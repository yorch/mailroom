<?php
/**
 * Adversarial test harness for the mailroom mu-plugin.
 * Only touches its own table. Intercepts outgoing HTTP. No real email is sent.
 *
 * Shape notes (they matter — an earlier version of this harness tested a class shape that
 * does not exist in production):
 *  - MockCatcher::$failed_recipients is PRIVATE, matching MailCatcherTrait.php:91.
 *  - The partial-rejection case is driven by the real error STRING, not by that property.
 */
require __DIR__ . '/bootstrap.php';
global $wpdb;

if ( ! class_exists( 'Mailroom' ) ) {
	fwrite( STDERR, "mu-plugin not loaded\n" );
	exit( 1 );
}
// Isolation from production data: a separate log table (dropped at the end), settings held in
// memory via pre_option (the live option is never written), and the shared transients snapshotted
// and restored. The live log and settings are untouched by a test run.
$T = $wpdb->prefix . 'mailroom_log_test';
add_filter( 'mailroom_table', function () use ( $T ) { return $T; } );
$GLOBALS['mailroom_test_opts'] = [];
function test_opts( array $o ) { $GLOBALS['mailroom_test_opts'] = $o; }
add_filter( 'pre_option_' . Mailroom::OPT, function () { return $GLOBALS['mailroom_test_opts']; } );
$TRANSIENTS = [];
foreach ( [ 'mailroom_alert_throttle', 'mailroom_backup_down' ] as $k ) { $TRANSIENTS[ $k ] = get_transient( $k ); }
$LIVE_ROWS = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mailroom_log' );
if ( Mailroom::table() !== $T ) {
	fwrite( STDERR, "ABORT: test table filter not effective\n" );
	exit( 2 );
}

// Last-resort guards (the site is live): no test may send a real email or make a real HTTP call.
// Registered at PHP_INT_MAX, so they only see what every test-specific filter let through. The single
// allowed exception is the deliberate refused-port send (WP Mail SMTP resolved to smtp 127.0.0.1, T54).
$GLOBALS['blocked_mail'] = [];
$GLOBALS['blocked_http'] = [];
add_filter( 'pre_wp_mail', function ( $pre, $atts ) {
	if ( $pre !== null ) {
		return $pre;
	}
	$conn = wp_mail_smtp()->get_connections_manager()->get_mail_connection();
	if ( $conn->get_mailer_slug() === 'smtp' && $conn->get_options()->get( 'smtp', 'host' ) === '127.0.0.1' ) {
		return $pre; // refused local port: can't leave the box
	}
	$GLOBALS['blocked_mail'][] = (string) ( $atts['subject'] ?? '' );
	return false;
}, PHP_INT_MAX, 2 );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( $pre !== false ) {
		return $pre;
	}
	$GLOBALS['blocked_http'][] = $url;
	return new WP_Error( 'mailroom_test_blocked', 'blocked by harness guard' );
}, PHP_INT_MAX, 3 );

class MockMailer {
	public $sent;
	public function __construct( $sent ) { $this->sent = $sent; }
	public function is_email_sent() { return $this->sent; }
}
class MockCatcher {
	public $Subject = 'Test subject';
	public $Body = '<p>Hello <b>body</b></p>';
	public $AltBody = 'Hello body';
	public $ContentType = 'text/html';
	public $From = 'from@example.com';
	public $FromName = 'Sender';
	private $failed_recipients = []; // same visibility as the real class
	public function getToAddresses() { return [ [ 'to@example.com', 'To' ] ]; }
	public function getCcAddresses() { return []; }
	public function getBccAddresses() { return []; }
	public function getReplyToAddresses() { return []; }
	public function getCustomHeaders() { return []; }
	public function getAttachments() { return []; }
	public $MIME = "MIME-Version: 1.0\r\nSubject: Test subject\r\n\r\nraw body key=SECRET1";
	public function getSentMIMEMessage() { return $this->MIME; }
}

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; }
}

$inst = Mailroom::instance()->module( 'failover' );
$RP   = new ReflectionProperty( 'Mailroom_Failover', 'attempts' );
$RP->setAccessible( true );
$reset_failover = function () use ( $RP, $inst ) {
	$RP->setValue( $inst, 0 );
	delete_transient( 'mailroom_backup_down' );
};
$reset_failover();

Mailroom::create_table( $T );
$wpdb->query( "TRUNCATE TABLE {$T}" );

$PARTIAL = 'The mail transport accepted the message but rejected 1 recipient address: bad@example.com. Other recipients in the same email have been sent.';

// T1 SMTP success
do_action( 'wp_mail_smtp_mailcatcher_smtp_send_after', true, [ [ 'to@example.com', 'To' ] ], [], [], 'Subj SMTP', '<p>b</p>', [ 'from@example.com', 'S' ] );
$r = $wpdb->get_row( "SELECT * FROM {$T} ORDER BY id DESC LIMIT 1" );
check( 'T1 smtp success logged as sent', $r && $r->status === 'sent' && $r->source === 'smtp' && $r->to_email === 'to@example.com' );

// T2 API send_after fires on failure too — must NOT be logged as sent
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T}" );
do_action( 'wp_mail_smtp_mailcatcher_send_after', new MockMailer( false ), new MockCatcher() );
$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T}" );
check( 'T2 API send_after on FAILURE logs nothing', $before === $after );

do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'SMTP connect() failed', new MockCatcher(), 'gmail', 'E1', 0, null, 'k1' );
$r = $wpdb->get_row( "SELECT * FROM {$T} ORDER BY id DESC LIMIT 1" );
check( 'T2b failure logged as failed', $r && $r->status === 'failed' && $r->error === 'SMTP connect() failed' );

// T3 API success
do_action( 'wp_mail_smtp_mailcatcher_send_after', new MockMailer( true ), new MockCatcher() );
$r = $wpdb->get_row( "SELECT * FROM {$T} ORDER BY id DESC LIMIT 1" );
check( 'T3 API success logged as sent (api)', $r && $r->status === 'sent' && $r->source === 'api' );

// T4 null mailcatcher must not throw
$ok = true;
try {
	do_action( 'wp_mail_smtp_mailcatcher_send_after', new MockMailer( true ), null );
	do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'x', null, 'smtp', '', 0, null, '' );
} catch ( \Throwable $e ) { $ok = false; echo '  exception: ' . $e->getMessage() . "\n"; }
check( 'T4 null mailcatcher does not throw', $ok );

// T16 (adversarial) non-string error must not throw
$ok = true;
try {
	do_action( 'wp_mail_smtp_mailcatcher_send_failed', new stdClass(), new MockCatcher(), 'gmail', '', 0, null, 'k' );
} catch ( \Throwable $e ) { $ok = false; echo '  exception: ' . $e->getMessage() . "\n"; }
check( 'T16 non-string error does not throw', $ok );

// T16b (adversarial) fewer args than the callback declares must not throw (defaults)
$ok = true;
try { do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'only one arg' ); } catch ( \Throwable $e ) { $ok = false; }
check( 'T16b short arg list does not throw', $ok );

// T5 alert fires once, then throttled
test_opts( array_merge( Mailroom::defaults(), [ 'alert_webhook' => 'https://example.test/hook', 'alert_throttle' => 300 ] ) );
delete_transient( 'mailroom_alert_throttle' );
$GLOBALS['hook_hits'] = 0;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$GLOBALS['hook_hits']++;
	return new WP_Error( 'blocked', 'test' );
}, 10, 3 );
do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'e1', new MockCatcher(), 'gmail', '', 0, null, 'ka' );
do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'e2', new MockCatcher(), 'gmail', '', 0, null, 'kb' );
check( 'T5 alert sent once then throttled', $GLOBALS['hook_hits'] === 1 );

// T18 (adversarial) alert payload must not carry recipient/subject/raw addresses
delete_transient( 'mailroom_alert_throttle' );
test_opts( array_merge( Mailroom::defaults(), [ 'alert_webhook' => 'https://example.test/hook', 'alert_throttle' => 0 ] ) );
$GLOBALS['alert_body'] = null;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$GLOBALS['alert_body'] = $args['body'];
	return new WP_Error( 'blocked', 'test' );
}, 20, 3 );
do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'rejected customer@example.com', new MockCatcher(), 'gmail', '', 0, null, 'K' );
$p = json_decode( (string) $GLOBALS['alert_body'], true );
check(
	'T18 alert payload drops PII and masks emails',
	is_array( $p ) && ! isset( $p['to'] ) && ! isset( $p['subject'] ) && isset( $p['error_key'] )
	&& strpos( (string) $p['error'], 'customer@example.com' ) === false
	&& strpos( (string) $p['error'], '***@***' ) !== false
);

// ---- Alerts (2.0.0) -------------------------------------------------------
$AL = Mailroom::instance()->module( 'alerts' );
$GLOBALS['http_log'] = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$GLOBALS['http_log'][] = [ 'url' => $url, 'body' => (string) ( $args['body'] ?? '' ), 'blocking' => $args['blocking'] ?? true ];
	return [ 'headers' => [], 'body' => 'ok', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
}, 30, 3 );
$GLOBALS['alert_mails'] = [];
add_filter( 'mailroom_pre_alert_email', function ( $pre, $to, $subject, $body ) {
	$GLOBALS['alert_mails'][] = compact( 'to', 'subject', 'body' );
	return true;
}, 10, 4 );

// T22 format auto-detection + explicit override
check( 'T22 format detection (slack/discord/teams/generic/override)',
	Mailroom_Alerts::format_for( 'https://hooks.slack.com/services/A/B/C', 'auto' ) === 'slack'
	&& Mailroom_Alerts::format_for( 'https://discord.com/api/webhooks/1/x', 'auto' ) === 'discord'
	&& Mailroom_Alerts::format_for( 'https://prod-01.westus.logic.azure.com/workflows/x', 'auto' ) === 'teams'
	&& Mailroom_Alerts::format_for( 'https://example.com/hook', 'auto' ) === 'generic'
	&& Mailroom_Alerts::format_for( 'https://example.com/hook', 'slack' ) === 'slack'
	&& Mailroom_Alerts::format_for( 'https://discord.com/not-a-webhook', 'auto' ) === 'generic'
);

// T23 each format carries the field its service requires, and none carries recipient/subject
$shape_ok = true;
foreach ( [
	'https://hooks.slack.com/services/A/B/C'            => function ( $p ) { return isset( $p['text'] ) && $p['text'] !== ''; },
	'https://discord.com/api/webhooks/1/x'               => function ( $p ) { return isset( $p['content'] ) && mb_strlen( $p['content'] ) <= 2000; },
	'https://x.westus.logic.azure.com/workflows/y'       => function ( $p ) { return ( $p['attachments'][0]['content']['type'] ?? '' ) === 'AdaptiveCard'; },
] as $url => $has_field ) {
	test_opts( array_merge( Mailroom::defaults(), [ 'alert_webhook' => $url, 'alert_throttle' => 0 ] ) );
	$GLOBALS['http_log'] = [];
	do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'rejected customer@example.com', new MockCatcher(), 'gmail', '', 0, null, 'K23' );
	$body = (string) ( end( $GLOBALS['http_log'] )['body'] ?? '' );
	$p    = json_decode( $body, true );
	$ok   = is_array( $p ) && $has_field( $p ) && strpos( $body, 'to@example.com' ) === false
		&& strpos( $body, 'Test subject' ) === false && strpos( $body, 'customer@example.com' ) === false
		&& strpos( $body, 'K23' ) !== false;
	if ( ! $ok ) { echo "  shape failed for $url: $body\n"; }
	$shape_ok = $shape_ok && $ok;
}
check( 'T23 slack/discord/teams payload shapes valid and PII-free', $shape_ok );

// T24 email channel + ONE throttle window across channels
test_opts( array_merge( Mailroom::defaults(), [ 'alert_webhook' => 'https://hooks.slack.com/services/A/B/C', 'alert_email' => 'ops@example.com', 'alert_throttle' => 300 ] ) );
delete_transient( 'mailroom_alert_throttle' );
$GLOBALS['http_log'] = []; $GLOBALS['alert_mails'] = [];
do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'e1', new MockCatcher(), 'gmail', '', 0, null, 'k24' );
do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'e2', new MockCatcher(), 'gmail', '', 0, null, 'k24' );
$m = $GLOBALS['alert_mails'][0] ?? null;
check( 'T24 email + webhook fire once, then both throttled',
	count( $GLOBALS['http_log'] ) === 1 && count( $GLOBALS['alert_mails'] ) === 1
	&& $m && $m['to'] === [ 'ops@example.com' ] && strpos( $m['body'], 'Test subject' ) === false && strpos( $m['body'], 'to@example.com' ) === false
);
delete_transient( 'mailroom_alert_throttle' );

// T25 email-only config still alerts (no webhook required)
test_opts( array_merge( Mailroom::defaults(), [ 'alert_email' => 'ops@example.com', 'alert_throttle' => 0 ] ) );
$GLOBALS['http_log'] = []; $GLOBALS['alert_mails'] = [];
do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'e', new MockCatcher(), 'gmail', '', 0, null, 'k25' );
check( 'T25 email-only alert sends mail, no HTTP', count( $GLOBALS['alert_mails'] ) === 1 && count( $GLOBALS['http_log'] ) === 0 );

// T26 sanitize: http webhook rejected (old value kept), bad emails dropped, flag reported
[ $o, $ok ] = $AL->sanitize( [ array_merge( Mailroom::defaults(), [ 'alert_webhook' => 'https://keep.example/hook' ] ), true ], [
	'alert_webhook' => 'http://insecure.example/hook', 'alert_email' => 'good@example.com, not-an-email; also@example.com',
	'alert_format' => 'bogus', 'alert_from' => "evil@example.com\r\nBcc: x@y.z", 'alert_throttle' => '-5',
] );
check( 'T26 sanitize rejects http/bad emails/header injection',
	$ok === false && $o['alert_webhook'] === 'https://keep.example/hook' && $o['alert_email'] === 'good@example.com, also@example.com'
	&& $o['alert_format'] === 'auto' && strpos( (string) $o['alert_from'], "\n" ) === false && $o['alert_throttle'] === 0
);

// T27 test button path: BLOCKING dispatch reports the real HTTP status, not "queued"
test_opts( array_merge( Mailroom::defaults(), [ 'alert_webhook' => 'https://hooks.slack.com/services/A/B/C', 'alert_email' => 'ops@example.com' ] ) );
$GLOBALS['http_log'] = []; $GLOBALS['alert_mails'] = [];
$r = $AL->dispatch( [ 'event' => 'test' ], true );
check( 'T27 test dispatch is blocking and reports HTTP 200 + email',
	strpos( (string) $r['webhook'], 'slack: HTTP 200' ) === 0 && ( $GLOBALS['http_log'][0]['blocking'] ?? null ) === true
	&& strpos( (string) $r['email'], 'local MTA' ) !== false && strpos( $GLOBALS['alert_mails'][0]['subject'], 'Test alert' ) !== false
);
test_opts( [] );

// ---- Full content / resend / EML (2.0.0) -----------------------------------
$CT = Mailroom::instance()->module( 'content' );
$send_api = function ( array $atts, $cat = null ) {
	// What a real wp_mail() does around WP Mail SMTP's API path: the `wp_mail` filter, then the catcher hooks.
	$cat = $cat ?: new MockCatcher();
	apply_filters( 'wp_mail', $atts );
	do_action( 'wp_mail_smtp_mailcatcher_pre_send_before', $cat );
	do_action( 'wp_mail_smtp_mailcatcher_send_after', new MockMailer( true ), $cat );
	do_action( 'wp_mail_succeeded', $atts );
};
$ATTS = [ 'to' => 'to@example.com', 'subject' => 'Test subject', 'message' => "Reset: https://x.test/wp-login.php?action=rp&key=SECRET1&login=u <script>alert(1)</script>", 'headers' => [ 'Reply-To: r@example.com' ], 'attachments' => [] ];

// T28 off by default: nothing stored
$wpdb->query( "TRUNCATE TABLE {$T}" );
test_opts( [] );
$send_api( $ATTS );
$r = $wpdb->get_row( "SELECT content, mime FROM {$T} ORDER BY id DESC LIMIT 1" );
check( 'T28 full content NOT stored by default', $r && $r->content === null && $r->mime === null );

// T29 opted in: raw args + exact MIME stored
test_opts( array_merge( Mailroom::defaults(), [ 'store_content' => 1 ] ) );
$send_api( $ATTS );
$r = $wpdb->get_row( "SELECT id, content, mime FROM {$T} ORDER BY id DESC LIMIT 1" );
$c = json_decode( (string) $r->content, true );
check( 'T29 opted in: raw wp_mail args + exact MIME stored', is_array( $c ) && $c['message'] === $ATTS['message'] && $c['headers'] === $ATTS['headers'] && $r->mime === ( new MockCatcher() )->MIME );
$ID29 = (int) $r->id;

// T30 content does not leak into the NEXT email (cleared after the send)
test_opts( array_merge( Mailroom::defaults(), [ 'store_content' => 1 ] ) );
$cat = new MockCatcher();
do_action( 'wp_mail_smtp_mailcatcher_send_after', new MockMailer( true ), $cat ); // a send that bypassed the wp_mail filter
$r = $wpdb->get_row( "SELECT content FROM {$T} ORDER BY id DESC LIMIT 1" );
check( 'T30 previous email content not attached to a later row', $r && $r->content === null );

// T31 oversized MIME is skipped, row (and args) still stored
$big = new MockCatcher();
$big->MIME = str_repeat( 'x', Mailroom_Content::MAX_BYTES + 1 );
$send_api( $ATTS, $big );
$r = $wpdb->get_row( "SELECT content, mime FROM {$T} ORDER BY id DESC LIMIT 1" );
check( 'T31 oversized MIME skipped, row + args kept', $r && $r->mime === null && $r->content !== null );

// T32 purge wipes old content but keeps the metadata row
$wpdb->query( "UPDATE {$T} SET created_at = '2020-01-01 00:00:00' WHERE id = {$ID29}" );
test_opts( array_merge( Mailroom::defaults(), [ 'store_content' => 1, 'content_days' => 7, 'retention_days' => 0 ] ) );
$CT->purge();
$r = $wpdb->get_row( "SELECT content, mime, status FROM {$T} WHERE id = {$ID29}" );
check( 'T32 purge wipes content, keeps the row', $r && $r->content === null && $r->mime === null && $r->status === 'sent' );

// T33 detail view: redacted, escaped, offers resend + EML
$send_api( $ATTS );
$ID33 = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$T}" );
ob_start(); $CT->render_detail( $ID33 ); $html = ob_get_clean();
check( 'T33 detail view redacts secrets, escapes HTML, offers resend + EML',
	strpos( $html, 'SECRET1' ) === false && strpos( $html, 'key=***' ) !== false && strpos( $html, '<script>alert(1)' ) === false
	&& strpos( $html, 'mailroom_resend' ) !== false && strpos( $html, 'mailroom_eml' ) !== false
);

// T34 EML is the exact stored MIME (raw, unredacted)
check( 'T34 EML = exact stored MIME', $CT->eml_for( $ID33 ) === ( new MockCatcher() )->MIME && $CT->eml_for( 999999 ) === null );

// T35 resend replays the stored args (override recipient; invalid recipient refused; missing attachment skipped)
$GLOBALS['resent'] = [];
add_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'] = function ( $pre, $atts ) { $GLOBALS['resent'][] = $atts; return true; }, 10, 2 );
$wpdb->query( $wpdb->prepare( "UPDATE {$T} SET content = %s WHERE id = %d", wp_json_encode( array_merge( $ATTS, [ 'attachments' => [ '/nonexistent/file.pdf' ] ] ) ), $ID33 ) );
[ $ok1, $msg1 ] = $CT->resend( $ID33 );
[ $ok2 ]        = $CT->resend( $ID33, 'other@example.com' );
[ $ok3 ]        = $CT->resend( $ID33, 'not-an-email' );
[ $ok4 ]        = $CT->resend( 999999 );
$a1 = $GLOBALS['resent'][0] ?? [];
$a2 = $GLOBALS['resent'][1] ?? [];
check( 'T35 resend replays args, honours override, refuses bad input, skips missing attachment',
	$ok1 && $a1['message'] === $ATTS['message'] && $a1['headers'] === $ATTS['headers'] && $a1['attachments'] === [] && strpos( $msg1, '1 missing attachment' ) !== false
	&& $ok2 && $a2['to'] === [ 'other@example.com' ] && ! $ok3 && ! $ok4 && count( $GLOBALS['resent'] ) === 2
);
remove_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'], 10 );

// T36 a resend's new log row is stamped resent_of
$wpdb->query( "TRUNCATE TABLE {$T}" );
$send_api( $ATTS );
$ID36 = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$T}" );
$RS = new ReflectionProperty( 'Mailroom_Content', 'resending' ); $RS->setAccessible( true );
$RS->setValue( $CT, $ID36 );
$send_api( $ATTS );
$RS->setValue( $CT, 0 );
check( 'T36 resent row stamped resent_of', (int) $wpdb->get_var( "SELECT resent_of FROM {$T} ORDER BY id DESC LIMIT 1" ) === $ID36 );
test_opts( [] );

// ---- Email controls / weekly report / filtered export (2.0.0) -------------
// T37 controls: nothing disabled by default; a disabled one returns false; others untouched
test_opts( [] );
// (Auto-update email filters are NOT used for the default check: Easy Updates Manager already forces them
// false on this site. The controls pass the incoming value through, so they respect that.)
$def_ok = apply_filters( 'notify_post_author', true ) === true && apply_filters( 'wp_send_new_user_notification_to_admin', true ) === true;
$passthru = apply_filters( 'wp_send_new_user_notification_to_user', 'sentinel' ) === 'sentinel'; // ours is the only callback on this hook
test_opts( array_merge( Mailroom::defaults(), [ 'controls_off' => [ 'comment_post_author', 'new_user_admin', 'auto_update_plugins' ] ] ) );
check( 'T37 controls: off by default; disabled → false; others untouched',
	$def_ok && apply_filters( 'notify_post_author', true ) === false && apply_filters( 'wp_send_new_user_notification_to_admin', true ) === false
	&& $passthru && apply_filters( 'notify_moderator', true ) === true
	&& apply_filters( 'wp_send_new_user_notification_to_user', true ) === true
);

// T38 the admin "user reset their password" notice (an action, not a filter) is removed when disabled
$GLOBALS['ctl_mail'] = [];
add_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'] = function ( $pre, $atts ) { $GLOBALS['ctl_mail'][] = $atts; return true; }, 10, 2 );
test_opts( array_merge( Mailroom::defaults(), [ 'controls_off' => [ 'password_reset_admin' ] ] ) );
$had = (bool) has_action( 'after_password_reset', 'wp_password_change_notification' );
$u   = new WP_User( 0 ); $u->user_email = 'someone@example.com'; $u->user_login = 'someone'; $u->display_name = 'someone';
do_action( 'after_password_reset', $u, 'new-pass' );
check( 'T38 password-reset admin notice suppressed (core action removed, no mail)', $had && ! has_action( 'after_password_reset', 'wp_password_change_notification' ) && $GLOBALS['ctl_mail'] === [] );
add_action( 'after_password_reset', 'wp_password_change_notification' ); // restore for the rest of this process
remove_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'], 10 );

// T39 sanitize keeps only known controls
[ $o ] = Mailroom::instance()->module( 'controls' )->sanitize( [ Mailroom::defaults(), true ], [ 'controls_off' => [ 'comment_moderation', 'evil<script>', 'auto_update_core' ] ] );
check( 'T39 controls sanitize drops unknown slugs', $o['controls_off'] === [ 'comment_moderation', 'auto_update_core' ] );

// T40 report stats + text: counts, failure rate, masked errors, top subjects; default recipient admin_email
$wpdb->query( "TRUNCATE TABLE {$T}" );
$now = current_time( 'mysql' );
foreach ( [ [ 'sent', 'Order 1', null ], [ 'sent', 'Order 1', null ], [ 'sent', 'Welcome', null ], [ 'failed', 'Order 2', 'rejected bob@example.com' ], [ 'sent_backup', 'Order 2', null ] ] as $x ) {
	$wpdb->insert( $T, [ 'created_at' => $now, 'status' => $x[0], 'source' => 'api', 'mailer' => 'gmail', 'subject' => $x[1], 'error' => $x[2] ] );
}
$wpdb->insert( $T, [ 'created_at' => '2020-01-01 00:00:00', 'status' => 'failed', 'source' => 'api', 'mailer' => 'gmail', 'subject' => 'old' ] );
test_opts( [] );
$s   = Mailroom_Reports::stats( 7 );
$txt = Mailroom_Reports::render_text( $s );
check( 'T40 report: counts, rate, masked errors, top subjects, old rows excluded',
	$s['sent'] === 4 && $s['failed'] === 1 && abs( $s['fail_rate'] - 20.0 ) < 0.01
	&& strpos( $txt, 'bob@example.com' ) === false && strpos( $txt, '***@***' ) !== false
	&& strpos( $txt, 'Order 1' ) !== false && strpos( $txt, 'Delivered via backup: 1' ) !== false
	&& Mailroom_Reports::recipients() === [ (string) get_option( 'admin_email' ) ]
);

// T41 send_report goes through wp_mail to the configured recipients
$GLOBALS['rep_mail'] = [];
add_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'] = function ( $pre, $atts ) { $GLOBALS['rep_mail'][] = $atts; return true; }, 10, 2 );
test_opts( array_merge( Mailroom::defaults(), [ 'report_email' => 'ops@example.com, boss@example.com' ] ) );
$ok = Mailroom::instance()->module( 'reports' )->send_report();
$m  = $GLOBALS['rep_mail'][0] ?? [];
check( 'T41 report sent via wp_mail to configured recipients', $ok && ( $m['to'] ?? null ) === [ 'ops@example.com', 'boss@example.com' ] && strpos( (string) ( $m['subject'] ?? '' ), 'Weekly email delivery report' ) !== false );
remove_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'], 10 );

// T42 the weekly cron exists iff the report is enabled
// The live `cron` option is shared with the real wp-cron.php (every 5 min), which reconciles this event against the
// LIVE setting, so reading/writing the real schedule here raced with production (flaky) and briefly altered it.
// Simulate the schedule with WP's pre_* cron filters instead and record what sync_schedule() asks for.
$RE = Mailroom::instance()->module( 'reports' );
$GLOBALS['t42'] = [ 'exists' => false, 'calls' => [] ];
$t42_get   = function ( $pre, $hook ) {
	if ( $hook !== Mailroom_Reports::CRON ) { return $pre; }
	return $GLOBALS['t42']['exists'] ? (object) [ 'hook' => $hook, 'timestamp' => time() + 3600, 'schedule' => 'weekly', 'args' => [], 'interval' => WEEK_IN_SECONDS ] : false;
};
$t42_sched = function ( $pre, $event ) {
	if ( $event->hook !== Mailroom_Reports::CRON ) { return $pre; }
	$GLOBALS['t42']['calls'][] = 'schedule:' . $event->schedule; $GLOBALS['t42']['exists'] = true; return true;
};
$t42_clear = function ( $pre, $hook ) {
	if ( $hook !== Mailroom_Reports::CRON ) { return $pre; }
	$GLOBALS['t42']['calls'][] = 'clear'; $GLOBALS['t42']['exists'] = false; return 1;
};
add_filter( 'pre_get_scheduled_event', $t42_get, 10, 2 );
add_filter( 'pre_schedule_event', $t42_sched, 10, 2 );
add_filter( 'pre_clear_scheduled_hook', $t42_clear, 10, 2 );
test_opts( array_merge( Mailroom::defaults(), [ 'report_enabled' => 1 ] ) );
$RE->sync_schedule();            // not scheduled + enabled → schedule
$RE->sync_schedule();            // already scheduled + enabled → no-op
test_opts( [] );
$RE->sync_schedule();            // scheduled + disabled → clear
$RE->sync_schedule();            // not scheduled + disabled → no-op
remove_filter( 'pre_get_scheduled_event', $t42_get, 10 );
remove_filter( 'pre_schedule_event', $t42_sched, 10 );
remove_filter( 'pre_clear_scheduled_hook', $t42_clear, 10 );
check( 'T42 weekly event scheduled when enabled, cleared when disabled (simulated schedule; never touches the live cron option)', $GLOBALS['t42']['calls'] === [ 'schedule:weekly', 'clear' ] );

// T43 filters: invalid values dropped; LIKE wildcards escaped; export honours filters
$f = Mailroom_Admin::filters( [ 'status' => 'bogus', 'from' => '2026-13-99x', 'to' => '2026-09-30', 'q' => '100%_off' ] );
[ $w, $a ] = Mailroom_Admin::where( $f );
$wpdb->insert( $T, [ 'created_at' => $now, 'status' => 'sent', 'source' => 'api', 'mailer' => 'gmail', 'subject' => 'Save 100% today', 'to_email' => 'x@example.com' ] );
$rows_failed = Mailroom_Admin::export_rows( Mailroom_Admin::filters( [ 'status' => 'failed' ] ) );
$rows_q      = Mailroom_Admin::export_rows( Mailroom_Admin::filters( [ 'q' => 'Order 1' ] ) );
$rows_pct    = Mailroom_Admin::export_rows( Mailroom_Admin::filters( [ 'q' => '100%_off' ] ) ); // literal: must NOT match "100% today"
$rows_old    = Mailroom_Admin::export_rows( Mailroom_Admin::filters( [ 'to' => '2020-12-31' ] ) );
check( 'T43 filters validated; LIKE escaped; export honours status/search/date',
	$f['status'] === '' && $f['from'] === '' && $f['to'] === '2026-09-30' && strpos( implode( '|', $a ), '100\\%\\_off' ) !== false
	&& count( $rows_failed ) === 2 && count( $rows_q ) === 2 && count( $rows_pct ) === 0 && count( $rows_old ) === 1
);
test_opts( [] );

// ---- Rate limiting (2.0.0) -----------------------------------------------
$QT = $wpdb->prefix . 'mailroom_queue_test';
add_filter( 'mailroom_queue_table', function () use ( $QT ) { return $QT; } );
Mailroom_RateLimit::create_table( $QT );
$RL  = Mailroom::instance()->module( 'ratelimit' );
$now = current_time( 'mysql' );
$seed_sent = function ( int $n ) use ( $wpdb, $T, $now ) {
	for ( $i = 0; $i < $n; $i++ ) {
		$wpdb->insert( $T, [ 'created_at' => $now, 'status' => 'sent', 'source' => 'api', 'mailer' => 'gmail', 'subject' => 'seed' ] );
	}
};
$RATE = array_merge( Mailroom::defaults(), [ 'rate_enabled' => 1, 'rate_max' => 3, 'rate_window' => 60 ] );
$MAIL = [ 'to' => 'q@example.com', 'subject' => 'Newsletter', 'message' => 'hello', 'headers' => [ 'X-Test: 1' ], 'attachments' => [] ];

// T44 under the cap: passes through
$wpdb->query( "TRUNCATE TABLE {$T}" ); $wpdb->query( "TRUNCATE TABLE {$QT}" );
test_opts( $RATE );
$seed_sent( 2 );
check( 'T44 under the cap: not queued', $RL->maybe_queue( null, $MAIL ) === null && Mailroom_RateLimit::queue_count() === 0 );

// T45 at the cap: queued (wp_mail would return true), log row `queued`
$seed_sent( 1 );
$q = $RL->maybe_queue( null, $MAIL );
check( 'T45 at the cap: queued + `queued` log row', $q === true && Mailroom_RateLimit::queue_count() === 1
	&& (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T} WHERE status='queued' AND subject='Newsletter'" ) === 1 );

// T46 exempt subject bypasses the cap; an earlier short-circuit is respected
check( 'T46 exempt subject (password reset) not queued; earlier short-circuit respected',
	$RL->maybe_queue( null, array_merge( $MAIL, [ 'subject' => '[Site] Password Reset' ] ) ) === null
	&& $RL->maybe_queue( false, $MAIL ) === false && Mailroom_RateLimit::queue_count() === 1 );

// T47 attachments are embedded at queue time (caller's temp file deleted), restored for the drain;
// drain sends nothing while the window is still full, then sends once capacity frees up
$wpdb->query( "TRUNCATE TABLE {$QT}" );
$tmpf = wp_tempnam( 'mailroom-att' ); file_put_contents( $tmpf, 'ATTACHMENT-BYTES' );
$RL->maybe_queue( null, array_merge( $MAIL, [ 'attachments' => [ $tmpf ] ] ) );
@unlink( $tmpf );
$GLOBALS['drained'] = [];
add_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'] = function ( $pre, $atts ) {
	$GLOBALS['drained'][] = [ 'atts' => $atts, 'att_data' => array_map( function ( $f ) { return is_readable( $f ) ? file_get_contents( $f ) : null; }, (array) $atts['attachments'] ) ];
	return $GLOBALS['drain_result'] ?? true;
}, 100, 2 );
$n_full = $RL->drain();
$wpdb->query( "DELETE FROM {$T} WHERE subject = 'seed'" ); // window frees up
$n_free = $RL->drain();
$d      = $GLOBALS['drained'][0] ?? null;
$left_tmp = $d ? array_filter( (array) $d['atts']['attachments'], 'file_exists' ) : [ 'x' ];
check( 'T47 drain respects capacity, replays args + embedded attachment, cleans temp files',
	$n_full === 0 && $n_free === 1 && $d && $d['atts']['subject'] === 'Newsletter' && $d['atts']['headers'] === [ 'X-Test: 1' ]
	&& $d['att_data'] === [ 'ATTACHMENT-BYTES' ] && $left_tmp === [] && Mailroom_RateLimit::queue_count() === 0 );

// T48 a failing drained email is retried, then dropped after MAX_ATTEMPTS (each attempt is logged by the mailer)
$wpdb->query( "TRUNCATE TABLE {$T}" );
test_opts( array_merge( $RATE, [ 'rate_max' => 1 ] ) );
$seed_sent( 1 );
$RL->maybe_queue( null, $MAIL );
$wpdb->query( "DELETE FROM {$T} WHERE subject = 'seed'" );
$GLOBALS['drain_result'] = false;
$att = [];
for ( $i = 0; $i < Mailroom_RateLimit::MAX_ATTEMPTS; $i++ ) {
	$RL->drain();
	$att[] = Mailroom_RateLimit::queue_count();
}
unset( $GLOBALS['drain_result'] );
check( 'T48 failed drain retried, dropped after MAX_ATTEMPTS', $att === [ 1, 1, 0 ] );

// T49 never lose mail: a queue-insert failure sends now (null) and leaves no orphan `queued` row
$wpdb->query( "TRUNCATE TABLE {$T}" );
test_opts( $RATE );
$seed_sent( 3 );
add_filter( 'mailroom_queue_table', function () { return 'wp_mailroom_no_such_table'; }, 20 );
$wpdb->suppress_errors( true );
$r49 = $RL->maybe_queue( null, $MAIL );
$wpdb->suppress_errors( false );
remove_all_filters( 'mailroom_queue_table' );
add_filter( 'mailroom_queue_table', function () use ( $QT ) { return $QT; } );
check( 'T49 queue failure → sent now, no orphan queued row', $r49 === null && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T} WHERE status='queued'" ) === 0 );

// T50 attachments over the queue size cap → sent now (not queued)
$big = wp_tempnam( 'mailroom-big' ); $fh = fopen( $big, 'w' ); ftruncate( $fh, Mailroom_RateLimit::MAX_ATTACH + 1 ); fclose( $fh );
check( 'T50 oversized attachments are sent now, not queued', $RL->maybe_queue( null, array_merge( $MAIL, [ 'attachments' => [ $big ] ] ) ) === null && Mailroom_RateLimit::queue_count() === 0 );
@unlink( $big );
remove_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'], 100 );
test_opts( [] );

// ---- Smart Routing (2.0.0) -----------------------------------------------
$RT    = Mailroom::instance()->module( 'routing' );
$ROUTE = array_merge( Mailroom::defaults(), [
	'route_enabled' => 1, 'route_rules' => "subject: Invoice\ninitiator: Gravity Forms\nto: @partner.test\nnot a rule\nheader: X-Route-Me",
	'backup_enabled' => 1, 'backup_host' => '127.0.0.1', 'backup_port' => 1, 'backup_secure' => '', 'backup_from' => 'routed@example.com',
] );

// T51 rule parsing + matching on each field; malformed lines ignored (and reported by sanitize)
test_opts( $ROUTE );
[ , $ok51 ] = $RT->sanitize( [ Mailroom::defaults(), true ], [ 'route_rules' => "subject: A\nbogus line" ] );
check( 'T51 rules parse; subject/to/initiator/header match; malformed ignored + reported',
	count( Mailroom_Routing::rules() ) === 4
	&& Mailroom_Routing::matches( [ 'subject' => 'Your invoice #9' ], '' )
	&& Mailroom_Routing::matches( [ 'to' => [ 'a@partner.test' ] ], '' )
	&& Mailroom_Routing::matches( [ 'subject' => 'x' ], 'Gravity Forms' )
	&& Mailroom_Routing::matches( [ 'headers' => [ 'X-Route-Me: yes' ] ], '' )
	&& ! Mailroom_Routing::matches( [ 'subject' => 'Welcome', 'to' => 'b@example.com' ], 'WooCommerce' )
	&& $ok51 === false
);

// T52 decide: routes only when enabled + backup ready + match; flag reset by a short-circuit
$RT->decide( null, [ 'subject' => 'Invoice 1' ] ); $a1 = $RT->is_active();
$RT->decide( null, [ 'subject' => 'Welcome' ] );   $a2 = $RT->is_active();
test_opts( array_merge( $ROUTE, [ 'backup_from' => '' ] ) );
$RT->decide( null, [ 'subject' => 'Invoice 1' ] ); $a3 = $RT->is_active();
test_opts( $ROUTE );
$RT->decide( null, [ 'subject' => 'Invoice 1' ] ); $RT->after_short_circuit( true ); $a4 = $RT->is_active();
check( 'T52 routes only on match with a ready backup; short-circuit clears the flag', $a1 && ! $a2 && ! $a3 && ! $a4 );

// T53 while active, WP Mail SMTP itself resolves to the backup connection + backup From; inactive = untouched
$conn = wp_mail_smtp()->get_connections_manager()->get_mail_connection();
$real = [ $conn->get_mailer_slug(), (string) $conn->get_options()->get( 'mail', 'from_email' ) ];
$RT->decide( null, [ 'subject' => 'Invoice 2' ] );
$eff  = [ $conn->get_mailer_slug(), $conn->get_options()->get( 'smtp', 'host' ), (int) $conn->get_options()->get( 'smtp', 'port' ), $conn->get_options()->get( 'mail', 'from_email' ), (bool) $conn->get_options()->get( 'mail', 'from_email_force' ) ];
$RT->reset();
$back = [ $conn->get_mailer_slug(), (string) $conn->get_options()->get( 'mail', 'from_email' ) ];
check( 'T53 active → WPMS resolves smtp/backup host/backup From; reset → original', $eff === [ 'smtp', '127.0.0.1', 1, 'routed@example.com', true ] && $back === $real && $real[1] !== 'routed@example.com' ); // mailer-agnostic: works whatever mailer the site uses

// T54 LIVE routed send through the real WP Mail SMTP: backup host refused → one `failed` row with
// source=route, mailer=smtp; failover skipped (no failed_backup); flag cleared. Guarded like the e2e
// script (abort unless the override is in effect, so it can never be a real Gmail send), and WP Mail
// SMTP's own failure records are restored afterwards.
$wpdb->query( "TRUNCATE TABLE {$T}" );
$miss = new stdClass(); $snap54 = [];
foreach ( [ 'wp_mail_smtp_email_sending_debug', 'wp_mail_smtp_debug' ] as $k ) { $snap54[ $k ] = get_option( $k, $miss ); }
$ev = $wpdb->prefix . 'wpmailsmtp_debug_events';
$ev_has = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ev ) ) === $ev;
$ev_max = $ev_has ? (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$ev}" ) : 0;
$guard = function ( $pre, $atts ) use ( $conn ) {
	if ( $conn->get_mailer_slug() !== 'smtp' || $conn->get_options()->get( 'smtp', 'host' ) !== '127.0.0.1' ) {
		fwrite( STDERR, "ABORT T54: routing override not effective\n" );
		return false; // short-circuit: never let it reach the real mailer
	}
	return $pre;
};
add_filter( 'pre_wp_mail', $guard, 50, 2 );
delete_transient( 'mailroom_backup_down' );
$RP->setValue( $inst, 0 );
$ok54 = wp_mail( 'routing-e2e@example.invalid', 'Invoice routing e2e', 'test' );
remove_filter( 'pre_wp_mail', $guard, 50 );
$rows54 = $wpdb->get_results( "SELECT status, source, mailer FROM {$T} ORDER BY id" );
check( 'T54 live routed send: failed via backup (route/smtp), failover skipped, flag cleared',
	$ok54 === false && count( $rows54 ) === 1 && $rows54[0]->status === 'failed' && $rows54[0]->source === 'route'
	&& $rows54[0]->mailer === 'smtp' && ! $RT->is_active() && ! get_transient( 'mailroom_backup_down' )
);
foreach ( $snap54 as $k => $v ) { $v === $miss ? delete_option( $k ) : update_option( $k, $v ); }
if ( $ev_has ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$ev} WHERE id > %d", $ev_max ) ); }
test_opts( [] );

// T6 CSV formula injection
$m = new ReflectionMethod( 'Mailroom_Admin', 'csv_safe' );
$m->setAccessible( true );
check( 'T6 CSV formula escape', $m->invoke( null, '=cmd' ) === "'=cmd" && $m->invoke( null, 'normal' ) === 'normal' );

// T7 (non-circular) retention — a hard-coded old row is pruned, a fresh one is kept
delete_transient( 'mailroom_backup_down' );
$wpdb->query( "TRUNCATE TABLE {$T}" );
$wpdb->query( "INSERT INTO {$T} (created_at,status,source,mailer) VALUES ('2020-01-01 00:00:00','sent','smtp','x')" );
do_action( 'wp_mail_smtp_mailcatcher_smtp_send_after', true, [ [ 'a@b.c' ] ], [], [], 'fresh', '', [] );
test_opts( array_merge( Mailroom::defaults(), [ 'retention_days' => 30 ] ) );
do_action( 'mailroom_prune' );
$left   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T}" );
$hasOld = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T} WHERE created_at='2020-01-01 00:00:00'" );
check( 'T7 prune removes a hard-coded old row, keeps fresh', $left === 1 && $hasOld === 0 );

// T8 failover attempts on a non-partial failure and records failed_backup
$wpdb->query( "TRUNCATE TABLE {$T}" );
$reset_failover();
test_opts( array_merge( Mailroom::defaults(), [ 'backup_enabled' => 1, 'backup_host' => '127.0.0.1', 'backup_port' => 1, 'backup_secure' => '', 'backup_from' => 'backup@example.com', 'alert_webhook' => '', 'alert_throttle' => 0 ] ) );
do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'SMTP connect() failed', new MockCatcher(), 'gmail', '', 0, null, 'k' );
$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T} WHERE status='failed_backup'" );
$e = (string) $wpdb->get_var( "SELECT error FROM {$T} WHERE status='failed_backup' ORDER BY id DESC LIMIT 1" );
check( 'T8 failover attempted (failed_backup), not a config skip', $n === 1 && stripos( $e, 'not configured' ) === false );

// T20 (adversarial) a missing backup From skips failover and says why
$wpdb->query( "TRUNCATE TABLE {$T}" );
$reset_failover();
test_opts( array_merge( Mailroom::defaults(), [ 'backup_enabled' => 1, 'backup_host' => '127.0.0.1', 'backup_port' => 1, 'backup_from' => '', 'alert_webhook' => '', 'alert_throttle' => 0 ] ) );
do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'SMTP connect() failed', new MockCatcher(), 'gmail', '', 0, null, 'kf' );
$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T} WHERE status='failed_backup'" );
$e = (string) $wpdb->get_var( "SELECT error FROM {$T} WHERE status='failed_backup' ORDER BY id DESC LIMIT 1" );
check( 'T20 missing backup From is skipped with a reason', $n === 1 && stripos( $e, 'not configured' ) !== false );
$reset_failover();

// T10 (adversarial, was the Critical) partial rejection must NOT fail over — even with attempts free
$wpdb->query( "TRUNCATE TABLE {$T}" );
$reset_failover();
do_action( 'wp_mail_smtp_mailcatcher_send_failed', $PARTIAL, new MockCatcher(), 'smtp', '', 0, null, 'kp' );
$nb = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T} WHERE status='failed_backup'" );
$nf = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T} WHERE status='failed'" );
check( 'T10 partial-recipient rejection does not fail over', $nb === 0 && $nf === 1 );

// T15 (adversarial) circuit breaker: after a backup failure a later failure skips failover
$wpdb->query( "TRUNCATE TABLE {$T}" );
$reset_failover();
set_transient( 'mailroom_backup_down', 1, 600 ); // simulate a recent backup failure
$RP->setValue( $inst, 0 );
do_action( 'wp_mail_smtp_mailcatcher_send_failed', 'SMTP connect() failed', new MockCatcher(), 'gmail', '', 0, null, 'kc' );
$nb = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T} WHERE status='failed_backup'" );
check( 'T15 circuit breaker skips failover', $nb === 0 );
$reset_failover();

// T19 (adversarial) oversized fields are clamped, row still inserted
// (isolate: disable failover, which T8/T15 left enabled, or a second row appears)
$wpdb->query( "TRUNCATE TABLE {$T}" );
test_opts( [] );
$reset_failover();
do_action( 'wp_mail_smtp_mailcatcher_send_failed', str_repeat( 'x', 20000 ), new MockCatcher(), 'gmail', '', 0, null, str_repeat( 'k', 500 ) );
$n   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T}" );
$len = (int) $wpdb->get_var( "SELECT CHAR_LENGTH(error_key) FROM {$T} WHERE status='failed' ORDER BY id DESC LIMIT 1" );
check( 'T19 oversized fields clamped, row still inserted', $n === 1 && $len <= 191 );

// T17 (adversarial) SMTP preview uses the captured raw body, not the MIME body
$wpdb->query( "TRUNCATE TABLE {$T}" );
test_opts( array_merge( Mailroom::defaults(), [ 'log_body' => 1 ] ) );
$cat = new MockCatcher();
$cat->Body = '<p>RAW BODY</p>';
do_action( 'wp_mail_smtp_mailcatcher_smtp_pre_send_before', $cat );
do_action( 'wp_mail_smtp_mailcatcher_smtp_send_after', true, [ [ 'a@b.c' ] ], [], [], 'Subj', 'MIME-JUNK-QUOTED-PRINTABLE', [] );
$r = $wpdb->get_row( "SELECT body_preview, subject FROM {$T} ORDER BY id DESC LIMIT 1" );
check( 'T17 SMTP preview uses raw body, not MIME', $r && strpos( $r->body_preview, 'RAW BODY' ) !== false && $r->subject === 'Test subject' );
test_opts( array_merge( Mailroom::defaults(), [ 'log_body' => 0 ] ) );

// T21 (adversarial) body preview redacts secrets in bare URLs (password-reset keys, tokens)
$wpdb->query( "TRUNCATE TABLE {$T}" );
test_opts( array_merge( Mailroom::defaults(), [ 'log_body' => 1 ] ) );
$cat = new MockCatcher();
$cat->Body = 'Reset your password: https://www.example.com/wp-login.php?action=rp&key=SUPERSECRETKEY&login=jane end';
do_action( 'wp_mail_smtp_mailcatcher_smtp_pre_send_before', $cat );
do_action( 'wp_mail_smtp_mailcatcher_smtp_send_after', true, [ [ 'a@b.c' ] ], [], [], 'Reset', 'mime', [] );
$prev = (string) $wpdb->get_var( "SELECT body_preview FROM {$T} ORDER BY id DESC LIMIT 1" );
check( 'T21 preview redacts reset keys', strpos( $prev, 'SUPERSECRETKEY' ) === false && strpos( $prev, 'key=***' ) !== false );
test_opts( [] );

// Everything from T9 to T63 tests the STANDALONE page (page=mailroom-log), which is what the bridge falls back to.
// Pin native integration OFF with a filter of our own (removed again before T64, which exercises it ON).
$FORCE_OFF = function () { return false; };
add_filter( 'mailroom_native_ui', $FORCE_OFF, 9999 );

// T9 admin page renders
$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
$uid    = ! empty( $admins ) ? (int) $admins[0]->ID : 0;
wp_set_current_user( $uid );
check( 'T9a administrator can manage options', $uid > 0 && current_user_can( 'manage_options' ) );
ob_start();
$ok = true;
try { Mailroom::instance()->module( 'admin' )->render_page(); $html = ob_get_clean(); } catch ( \Throwable $e ) { ob_end_clean(); $html = ''; $ok = false; }
check( 'T9b admin page renders', $ok && strpos( (string) $html, 'Email Log' ) !== false );

// T11 gated page removed, ours registered
global $submenu;
$submenu['wp-mail-smtp'] = [];
add_menu_page( 'WP Mail SMTP', 'WP Mail SMTP', 'manage_options', 'wp-mail-smtp', '__return_false', '' );
add_submenu_page( 'wp-mail-smtp', 'Email Log', 'Email Log', 'manage_options', 'wp-mail-smtp-logs', '__return_false' );
Mailroom::instance()->module( 'admin' )->menu();
$slugs = array_column( (array) $submenu['wp-mail-smtp'], 2 );
check( 'T11 removes gated page, registers ours', ! in_array( 'wp-mail-smtp-logs', $slugs, true ) && in_array( 'mailroom-log', $slugs, true ) );

// T12/T14 legacy URLs redirect to ours
$GLOBALS['redirect_to'] = null;
add_filter( 'wp_redirect', function ( $loc ) {
	$GLOBALS['redirect_to'] = $loc;
	throw new Exception( 'redirect' );
}, 10, 1 );
foreach ( [ [ 'page' => 'wp-mail-smtp-logs' ], [ 'page' => 'wp-mail-smtp', 'tab' => 'logs' ] ] as $q ) {
	$_GET = $q;
	$threw = false;
	try { Mailroom::instance()->module( 'admin' )->maybe_redirect_legacy_logs_page(); } catch ( Exception $e ) { $threw = true; }
	check( 'T12/' . implode( ',', $q ) . ' redirects to ours', $threw && strpos( (string) $GLOBALS['redirect_to'], 'page=mailroom-log' ) !== false );
}
$_GET = [];

// ---- Admin UI revamp: tabs, per-section save, overview, list table, POST actions -------------------
$AD  = Mailroom::instance()->module( 'admin' );
$ADM = 'Mailroom_Admin';
wp_set_current_user( $uid );
$render_tab = function ( string $tab, array $extra = [] ) use ( $AD ) {
	$_GET = array_merge( [ 'page' => 'mailroom-log', 'tab' => $tab ], $extra );
	ob_start();
	try { $AD->render_page(); $html = ob_get_clean(); } catch ( \Throwable $e ) { ob_end_clean(); $html = 'EXCEPTION: ' . $e->getMessage(); }
	$_GET = [];
	return $html;
};
$wpdb->query( "TRUNCATE TABLE {$T}" );
$fnow = current_time( 'mysql' );
$seed = function ( array $row ) use ( $wpdb, $T, $fnow ) {
	$wpdb->insert( $T, array_merge( [ 'created_at' => $fnow, 'status' => 'sent', 'source' => 'api', 'mailer' => 'gmail', 'subject' => 'Seed', 'to_email' => 's@example.com', 'duration_ms' => 120 ], $row ) );
	return (int) $wpdb->insert_id;
};

// T55 every tab renders for an administrator with its expected heading/fields, an accessible label for
// every input, and no disabled inputs (a disabled field is not submitted, which would reset it to a default on save)
test_opts( [] );
$markers = [
	'overview' => [ 'Last 7 days', 'data-feature="routing"', 'data-feature="alerts"' ],
	'log'      => [ 'mailroom-f-status', 'wp-list-table', 'Export CSV' ],
	'alerts'   => [ 'name="alert_webhook"', 'name="section" value="alerts"', 'mailroom_test_alert' ],
	'delivery' => [ 'name="backup_host"', 'name="route_rules"', 'name="rate_max"', 'name="section" value="delivery"' ],
	'storage'  => [ 'name="retention_days"', 'name="store_content"', 'name="section" value="storage"' ],
	'controls' => [ 'name="controls_off[]"', 'name="section" value="controls"' ],
	'reports'  => [ 'name="report_email"', 'mailroom_report_now', 'name="section" value="reports"' ],
];
$tabs_ok = true;
foreach ( Mailroom_UI::TABS as $slug => $label ) {
	$html = $render_tab( $slug );
	$ok   = strpos( $html, 'EXCEPTION' ) !== 0 && strpos( $html, 'nav-tab-wrapper' ) !== false && strpos( $html, 'Email Log' ) !== false && strpos( $html, 'nav-tab-active' ) !== false;
	foreach ( $markers[ $slug ] as $m ) { $ok = $ok && strpos( $html, $m ) !== false; }
	preg_match_all( '/<(input|select|textarea)\b([^>]*)>/i', $html, $mm, PREG_SET_ORDER );
	foreach ( $mm as $x ) {
		if ( preg_match( '/type="(hidden|submit)"/', $x[2] ) ) { continue; }
		if ( preg_match( '/\sdisabled\b/', $x[2] ) ) { $ok = false; echo "  disabled input on $slug: {$x[0]}\n"; }
		if ( preg_match( '/\bid="([^"]+)"/', $x[2], $id ) ) {
			if ( strpos( $html, 'for="' . $id[1] . '"' ) === false ) { $ok = false; echo "  no <label for> for #{$id[1]} on $slug\n"; }
		} elseif ( ! preg_match( '/type="(button|submit|reset)"/', $x[2] ) ) {
			$ok = false; echo "  input without id on $slug: {$x[0]}\n";
		}
	}
	if ( ! $ok ) { echo "  tab $slug failed. Output starts: " . substr( wp_strip_all_tags( $html ), 0, 300 ) . "\n"; }
	$tabs_ok = $tabs_ok && $ok;
}
check( 'T55 all 7 tabs render with expected fields, labelled inputs, nothing disabled', $tabs_ok );
check( 'T55b unknown tab falls back to the overview', strpos( $render_tab( 'bogus' ), 'Last 7 days' ) !== false );

// T56 per-section save never clobbers another section (the unticked-checkbox data-loss trap)
$ON = array_merge( Mailroom::defaults(), [
	'alert_webhook' => 'https://hooks.slack.com/services/A/B/C', 'alert_format' => 'slack', 'alert_email' => 'ops@example.com', 'alert_from' => 'a@example.com', 'alert_throttle' => 120,
	'backup_enabled' => 1, 'backup_host' => 'smtp.backup.test', 'backup_port' => 2525, 'backup_secure' => 'ssl', 'backup_user' => 'bu', 'backup_pass' => 'ENCRYPTED-BLOB', 'backup_from' => 'b@example.com',
	'route_enabled' => 1, 'route_rules' => 'subject: Invoice', 'rate_enabled' => 1, 'rate_max' => 10, 'rate_window' => 30, 'rate_exempt' => "Password Reset\nInvoice",
	'retention_days' => 45, 'log_body' => 1, 'store_content' => 1, 'content_days' => 14,
	'controls_off' => [ 'comment_moderation', 'new_user_user' ], 'report_enabled' => 1, 'report_email' => 'boss@example.com',
] );
$OWN = [
	'alerts'   => [ 'alert_webhook', 'alert_format', 'alert_email', 'alert_from', 'alert_throttle' ],
	'delivery' => [ 'backup_enabled', 'backup_host', 'backup_port', 'backup_secure', 'backup_user', 'backup_pass', 'backup_from', 'route_enabled', 'route_rules', 'rate_enabled', 'rate_max', 'rate_window', 'rate_exempt' ],
	'storage'  => [ 'retention_days', 'log_body', 'store_content', 'content_days' ],
	'controls' => [ 'controls_off' ],
	'reports'  => [ 'report_enabled', 'report_email' ],
];
check( 'T56a test fixture: every option key is owned by exactly one section', array_values( array_diff( array_keys( $ON ), array_merge( ...array_values( $OWN ) ) ) ) === [] && count( array_merge( ...array_values( $OWN ) ) ) === count( $ON ) );
$clobber = [];
foreach ( $OWN as $sec => $keys ) {
	// The worst case: the browser submits a form with every checkbox unticked and no other fields.
	[ $n ] = $ADM::sanitize_section( $sec, [], $ON );
	foreach ( $ON as $k => $v ) {
		if ( ! in_array( $k, $keys, true ) && $n[ $k ] !== $v ) { $clobber[] = "$sec clobbered $k"; }
	}
}
if ( $clobber ) { echo '  ' . implode( "\n  ", $clobber ) . "\n"; }
check( 'T56 saving any one section (empty POST) leaves every other section\'s keys exactly as they were', $clobber === [] );

[ $na, $oka ] = $ADM::sanitize_section( 'alerts', [ 'alert_webhook' => 'https://new.example/hook', 'alert_email' => 'x@example.com', 'alert_throttle' => '60' ], $ON );
[ $nd ]       = $ADM::sanitize_section( 'delivery', [ 'backup_enabled' => '1', 'backup_host' => 'other.test', 'backup_port' => '465', 'backup_secure' => 'ssl', 'backup_from' => 'z@example.com', 'backup_pass' => '', 'rate_max' => '5', 'rate_window' => '10', 'rate_exempt' => 'Reset', 'route_rules' => 'to: @x.test' ], $ON );
[ $ns ]       = $ADM::sanitize_section( 'storage', [ 'retention_days' => '10', 'store_content' => '1', 'content_days' => '3' ], $ON );
[ $nc ]       = $ADM::sanitize_section( 'controls', [ 'controls_off' => [ 'auto_update_core', 'evil' ] ], $ON );
[ $nr ]       = $ADM::sanitize_section( 'reports', [ 'report_email' => 'new@example.com' ], $ON );
check( 'T56b each section applies its own fields (blank backup password keeps the stored one; unticked own checkboxes go to 0)',
	$oka && $na['alert_webhook'] === 'https://new.example/hook' && $na['alert_email'] === 'x@example.com' && $na['alert_throttle'] === 60
	&& $nd['backup_host'] === 'other.test' && $nd['backup_port'] === 465 && $nd['backup_pass'] === 'ENCRYPTED-BLOB' && $nd['route_enabled'] === 0 && $nd['rate_enabled'] === 0 && $nd['rate_max'] === 5 && $nd['route_rules'] === 'to: @x.test'
	&& $ns['retention_days'] === 10 && $ns['log_body'] === 0 && $ns['store_content'] === 1 && $ns['content_days'] === 3
	&& $nc['controls_off'] === [ 'auto_update_core' ] && $nr['report_enabled'] === 0 && $nr['report_email'] === 'new@example.com'
);

// T56c unknown / non-settings sections change nothing, and a section's module ignores a section that isn't its own
$unknown_ok = true;
foreach ( [ 'bogus', '', 'overview', 'log', 'ALERTS ', '../alerts' ] as $bad ) {
	[ $nb, $okb ] = $ADM::sanitize_section( $bad, [ 'alert_webhook' => 'https://evil.example', 'backup_host' => 'evil', 'rate_enabled' => '1', 'controls_off' => [ 'comment_moderation' ] ], $ON );
	$unknown_ok = $unknown_ok && $nb === $ON && $okb === false;
}
[ $nf ] = $AL->sanitize( [ $ON, true ], [ 'alert_webhook' => 'https://elsewhere.example' ], 'storage' );
[ $ng ] = Mailroom::instance()->module( 'routing' )->sanitize( [ $ON, true ], [ 'route_rules' => 'to: z' ], 'alerts' );
check( 'T56c unknown section changes nothing; modules skip sections that are not theirs', $unknown_ok && $nf === $ON && $ng === $ON );

// T57 Overview: warning when routing is on without a backup From, none when properly configured
$OK_CFG = array_merge( Mailroom::defaults(), [ 'alert_email' => 'ops@example.com', 'backup_enabled' => 1, 'backup_host' => 'smtp.backup.test', 'backup_from' => 'b@example.com', 'route_enabled' => 1, 'route_rules' => 'subject: Invoice', 'rate_enabled' => 1 ] );
$count_warn = function ( string $h ) { return substr_count( $h, 'class="mailroom-card__warning"' ); };
$card       = function ( string $h, string $key ) { return preg_match( '/data-feature="' . $key . '".*?<\/div>/s', $h, $m ) ? $m[0] : ''; };
test_opts( array_merge( $OK_CFG, [ 'backup_from' => '' ] ) );
$h_bad = $render_tab( 'overview' );
test_opts( $OK_CFG );
$h_ok = $render_tab( 'overview' );
check( 'T57 overview warns on routing/failover without a backup From, and is clean when configured',
	strpos( $card( $h_bad, 'routing' ), 'mailroom-card__warning' ) !== false && strpos( $card( $h_bad, 'failover' ), 'mailroom-card__warning' ) !== false && $count_warn( $h_bad ) === 2
	&& $count_warn( $h_ok ) === 0 && strpos( $card( $h_ok, 'routing' ), 'mailroom-pill--on' ) !== false && strpos( $h_ok, 'tab=delivery' ) !== false
);
test_opts( array_merge( $OK_CFG, [ 'rate_exempt' => '' ] ) );
$h_rate = $render_tab( 'overview' );
test_opts( [] );
$h_def = $render_tab( 'overview' );
check( 'T57b overview warns for empty rate-limit exemptions and for having no alert channel; features off by default',
	strpos( $card( $h_rate, 'ratelimit' ), 'mailroom-card__warning' ) !== false && strpos( $card( $h_def, 'alerts' ), 'mailroom-card__warning' ) !== false
	&& $count_warn( $h_def ) === 1 && substr_count( $h_def, 'mailroom-pill--on' ) === 0
);

// T58 overview health cards: seven-day counts, rate, masked last failure
$wpdb->query( "TRUNCATE TABLE {$T}" );
foreach ( [ 'sent', 'sent', 'sent' ] as $st ) { $seed( [ 'status' => $st ] ); }
$fail_id = $seed( [ 'status' => 'failed', 'error' => 'rejected bob@example.com' ] );
$seed( [ 'status' => 'failed', 'created_at' => '2020-01-01 00:00:00', 'error' => 'ancient' ] );
$h = $render_tab( 'overview' );
check( 'T58 overview: sent 3, failed 1, rate 25%, last failure masked and linked',
	preg_match( '/<h3>Sent<\/h3><p class="mailroom-metric">3</', $h ) && preg_match( '/<h3>Failed<\/h3><p class="mailroom-metric">1</', $h ) && strpos( $h, '>25%<' ) !== false
	&& strpos( $h, 'bob@example.com' ) === false && strpos( $h, '***@***' ) !== false && strpos( $h, 'view=' . $fail_id ) !== false
);

// T59 list table: counts, pagination, filters, badges, safe subject output, no mime/body columns read
$wpdb->query( "TRUNCATE TABLE {$T}" );
foreach ( [ 'sent', 'sent', 'sent', 'sent_backup', 'queued', 'failed_backup' ] as $st ) { $seed( [ 'status' => $st ] ); }
$seed( [ 'status' => 'failed', 'error' => 'SMTP connect() failed', 'subject' => '<b>bold</b> Order' ] );
$seed( [ 'status' => 'failed', 'subject' => 'Needle' ] );
$rid = $seed( [ 'status' => 'sent', 'subject' => 'Has content', 'content' => wp_json_encode( [ 'to' => 's@example.com', 'subject' => 'Has content', 'message' => 'x', 'headers' => '', 'attachments' => [] ] ), 'mime' => str_repeat( 'M', 2000 ) ] );
$seed( [ 'status' => 'sent', 'subject' => 'Resent', 'resent_of' => $rid ] );
foreach ( [ 'class-wp-screen', 'screen', 'template', 'class-wp-list-table' ] as $inc ) { require_once ABSPATH . 'wp-admin/includes/' . $inc . '.php'; }
require_once dirname( ( new ReflectionClass( $ADM ) )->getFileName() ) . '/class-list-table.php';
$_GET = $_REQUEST = [];
$lt_all = new Mailroom_List_Table( $ADM::filters( [] ), 5 );
$lt_all->prepare_items();
$_GET = $_REQUEST = [ 'paged' => '2' ];
$lt_p2 = new Mailroom_List_Table( $ADM::filters( [] ), 5 );
$lt_p2->prepare_items();
$_GET = $_REQUEST = [];
$lt_f = new Mailroom_List_Table( $ADM::filters( [ 'status' => 'failed' ] ), 50 );
$lt_f->prepare_items();
$lt_q = new Mailroom_List_Table( $ADM::filters( [ 'q' => 'Needle' ] ), 50 );
$lt_q->prepare_items();
$lt_big = new Mailroom_List_Table( $ADM::filters( [] ), 50 );
$lt_big->prepare_items();
ob_start(); $lt_big->display_rows(); $rows_html = ob_get_clean();
$with_content = null; foreach ( $lt_big->items as $it ) { if ( (int) $it->id === $rid ) { $with_content = $it; } }
check( 'T59 list table: total/pagination, status + search filters narrow, page 2 offset',
	count( $lt_all->items ) === 5 && $lt_all->get_pagination_arg( 'total_items' ) === 10 && $lt_all->get_pagination_arg( 'total_pages' ) === 2
	&& count( $lt_p2->items ) === 5 && $lt_p2->items[0]->id < $lt_all->items[4]->id
	&& count( $lt_f->items ) === 2 && $lt_f->get_pagination_arg( 'total_items' ) === 2 && array_unique( array_column( $lt_f->items, 'status' ) ) === [ 'failed' ]
	&& count( $lt_q->items ) === 1 && $lt_q->items[0]->subject === 'Needle'
);
check( 'T59b list table renders status badges, escapes subjects, flags stored content, no mime/body columns read',
	strpos( $rows_html, 'mailroom-badge--failed' ) !== false && strpos( $rows_html, 'mailroom-badge--queued' ) !== false && strpos( $rows_html, 'mailroom-badge--sent_backup' ) !== false && strpos( $rows_html, 'mailroom-badge--failed_backup' ) !== false
	&& strpos( $rows_html, '<b>bold' ) === false && strpos( $rows_html, '&lt;b&gt;bold' ) !== false && strpos( $rows_html, 'resend of #' . $rid ) !== false
	&& preg_match( '/just now|ago/', $rows_html ) === 1 && strpos( $rows_html, 'title="' . $fnow . '"' ) !== false
	&& $with_content && (string) $with_content->content === '1' && ! isset( $with_content->mime ) && ! isset( $with_content->body_preview )
);
check( 'T59c Resend appears only for the row with stored content, as a POST form (never a GET link)',
	substr_count( $rows_html, 'value="mailroom_resend"' ) === 1 && strpos( $rows_html, 'mailroom_resend_' ) === false /* nonce action is not printed */
	&& strpos( $rows_html, 'admin-post.php?action=mailroom_resend' ) === false && strpos( $rows_html, 'method="post"' ) !== false && strpos( $rows_html, 'name="_wpnonce"' ) !== false
);
$h_log = $render_tab( 'log', [ 'status' => 'failed' ] );
check( 'T59d Log tab renders the filtered table and keeps the filters in the form + export link',
	strpos( $h_log, 'EXCEPTION' ) !== 0 && strpos( $h_log, 'Export CSV (filtered)' ) !== false && preg_match( '/value="failed"\s+selected/', $h_log ) === 1 /* selected() emits a leading space */ && strpos( $h_log, 'Needle' ) !== false && strpos( $h_log, 'Has content' ) === false
);

// T60 detail view: header badge, summary, tabs; no-content branch intact
ob_start(); $CT->render_detail( $rid ); $dh = ob_get_clean();
$plain_id = $seed( [ 'subject' => 'No content row' ] );
ob_start(); $CT->render_detail( $plain_id ); $dh2 = ob_get_clean();
ob_start(); $CT->render_detail( 987654 ); $dh3 = ob_get_clean();
check( 'T60 detail view: badge + tabs (body/headers/raw MIME note), resend form, EML link; no-content and not-found branches',
	strpos( $dh, 'mailroom-badge--sent' ) !== false && strpos( $dh, 'data-mailroom-tab="body"' ) !== false && strpos( $dh, 'data-mailroom-tab="headers"' ) !== false && strpos( $dh, 'data-mailroom-tab="mime"' ) !== false
	&& strpos( $dh, 'for="mailroom-resend-to"' ) !== false && strpos( $dh, 'mailroom_eml' ) !== false && strpos( $dh, 'tab=log' ) !== false
	&& strpos( $dh2, 'No stored content' ) !== false && strpos( $dh2, 'mailroom_resend' ) === false && strpos( $dh3, 'Not found' ) !== false
);

// T61 assets are printed only on our page
$_GET = [ 'page' => 'mailroom-log' ];
ob_start(); Mailroom_UI::print_css(); Mailroom_UI::print_js(); $on_page = ob_get_clean();
$_GET = [ 'page' => 'something-else' ];
ob_start(); Mailroom_UI::print_css(); Mailroom_UI::print_js(); $off_page = ob_get_clean();
$_GET = [];
check( 'T61 CSS + JS printed only on the Email Log page', strpos( $on_page, '.mailroom-badge' ) !== false && strpos( $on_page, 'data-mailroom-toggle' ) !== false && $off_page === '' );

// T62 POST actions: capability, POST-only, nonce; success returns to the originating tab. Handlers run for real
// (wp_die and the final redirect are turned into exceptions), with outgoing mail/HTTP captured.
$run = function ( callable $fn, string $method, array $post ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_POST = $_REQUEST = $post;
	$GLOBALS['redirect_to'] = null;
	$die = function () { return function ( $m ) { throw new RuntimeException( 'die:' . trim( wp_strip_all_tags( is_wp_error( $m ) ? $m->get_error_message() : (string) $m ) ) ); }; };
	$red = function ( $loc ) { $GLOBALS['redirect_to'] = $loc; throw new Exception( 'redirect' ); };
	add_filter( 'wp_die_handler', $die, 99 );
	add_filter( 'wp_redirect', $red, 1 );
	try { $fn(); $res = [ 'returned', '' ]; }
	catch ( RuntimeException $e ) { $res = [ 'die', $e->getMessage() ]; }
	catch ( Exception $e ) { $res = [ $e->getMessage() === 'redirect' ? 'redirect' : 'exception:' . $e->getMessage(), (string) $GLOBALS['redirect_to'] ]; }
	remove_filter( 'wp_die_handler', $die, 99 );
	remove_filter( 'wp_redirect', $red, 1 );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_POST = $_REQUEST = [];
	return $res;
};
$GLOBALS['act_mail'] = []; $GLOBALS['act_http'] = [];
add_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'] = function ( $pre, $atts ) { $GLOBALS['act_mail'][] = $atts; return true; }, 100, 2 );
add_filter( 'pre_http_request', $GLOBALS['tmp_http'] = function ( $pre, $args, $url ) { $GLOBALS['act_http'][] = $url; return [ 'headers' => [], 'body' => 'ok', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ]; }, 5, 3 );
$resend_id = $seed( [ 'subject' => 'To resend', 'content' => wp_json_encode( [ 'to' => 'orig@example.com', 'subject' => 'To resend', 'message' => 'hi', 'headers' => '', 'attachments' => [] ] ) ] );
test_opts( array_merge( Mailroom::defaults(), [ 'alert_webhook' => 'https://hooks.slack.com/services/A/B/C' ] ) );
$AM = Mailroom::instance();
$handlers = [
	// label => [ callable, nonce action, extra post fields, expected tab in the success redirect ]
	'test alert'  => [ [ $AM->module( 'alerts' ), 'handle_test' ], 'mailroom_test_alert', [ 'tab' => 'alerts' ], 'tab=alerts' ],
	'report now'  => [ [ $AM->module( 'reports' ), 'handle_now' ], 'mailroom_report_now', [ 'tab' => 'reports' ], 'tab=reports' ],
	'drain now'   => [ [ $AM->module( 'ratelimit' ), 'handle_drain_now' ], 'mailroom_drain_now', [ 'tab' => 'delivery' ], 'tab=delivery' ],
	'resend'      => [ [ $AM->module( 'content' ), 'handle_resend' ], 'mailroom_resend_' . $resend_id, [ 'tab' => 'log', 'id' => $resend_id, 'to' => 'other@example.com' ], 'tab=log' ],
];
$acts_ok = true;
foreach ( $handlers as $label => [ $fn, $nonce_action, $extra, $expect_tab ] ) {
	$good = array_merge( $extra, [ '_wpnonce' => wp_create_nonce( $nonce_action ) ] );
	$GLOBALS['act_mail'] = []; $GLOBALS['act_http'] = [];
	$r_get  = $run( $fn, 'GET', $good );                                                       // right nonce, wrong method
	$r_bad  = $run( $fn, 'POST', array_merge( $extra, [ '_wpnonce' => 'badnonce' ] ) );         // POST, wrong nonce
	$r_none = $run( $fn, 'POST', $extra );                                                      // POST, no nonce
	wp_set_current_user( 0 );
	$r_cap  = $run( $fn, 'POST', $good );                                                       // right nonce, no capability
	wp_set_current_user( $uid );
	$side   = count( $GLOBALS['act_mail'] ) + count( $GLOBALS['act_http'] );
	$r_ok   = $run( $fn, 'POST', array_merge( $good, [ 'tab' => 'bogus-tab' ] ) );              // valid, unknown tab → default tab
	$r_ok2  = $run( $fn, 'POST', $good );                                                       // valid → originating tab
	$ok = $r_get[0] === 'die' && stripos( $r_get[1], 'method' ) !== false
		&& $r_bad[0] === 'die' && stripos( $r_bad[1], 'method' ) === false && $r_none[0] === 'die'
		&& $r_cap[0] === 'die' && stripos( $r_cap[1], 'permission' ) !== false
		&& $side === 0
		&& $r_ok[0] === 'redirect' && strpos( $r_ok[1], 'page=mailroom-log' ) !== false
		&& $r_ok2[0] === 'redirect' && strpos( $r_ok2[1], $expect_tab ) !== false;
	if ( ! $ok ) { echo "  $label: " . json_encode( compact( 'r_get', 'r_bad', 'r_none', 'r_cap', 'side', 'r_ok', 'r_ok2' ) ) . "\n"; }
	$acts_ok = $acts_ok && $ok;
	delete_transient( 'mailroom_notice_' . $uid );
}
check( 'T62 POST actions: capability + POST-only + nonce enforced with no side effect; success redirects to the originating tab', $acts_ok );

// T62b the settings save handler enforces the same (a valid save would write the live option, so only the refusals are run)
$save = [ $AD, 'handle_save' ];
$r1 = $run( $save, 'GET', [ '_wpnonce' => wp_create_nonce( 'mailroom_save' ), 'section' => 'alerts' ] );
$r2 = $run( $save, 'POST', [ '_wpnonce' => 'badnonce', 'section' => 'alerts' ] );
wp_set_current_user( 0 );
$r3 = $run( $save, 'POST', [ '_wpnonce' => wp_create_nonce( 'mailroom_save' ), 'section' => 'alerts' ] );
wp_set_current_user( $uid );
$r4 = $run( $save, 'POST', [ '_wpnonce' => wp_create_nonce( 'mailroom_save' ), 'section' => 'bogus' ] ); // unknown section: nothing saved, redirect to the overview
check( 'T62b save handler: refuses GET / bad nonce / no capability; unknown section is refused without saving',
	$r1[0] === 'die' && $r2[0] === 'die' && $r3[0] === 'die' && $r4[0] === 'redirect' && strpos( $r4[1], 'tab=overview' ) !== false && strpos( $r4[1], 'updated=' ) === false
);
delete_transient( 'mailroom_notice_' . $uid );

// T62c structure: every state-changing handler checks capability, POST and nonce; no action is offered as a GET link
$structure_ok = true;
foreach ( [ [ 'Mailroom_Alerts', 'handle_test' ], [ 'Mailroom_Reports', 'handle_now' ], [ 'Mailroom_RateLimit', 'handle_drain_now' ], [ 'Mailroom_Content', 'handle_resend' ], [ $ADM, 'handle_save' ] ] as [ $cls, $meth ] ) {
	$rm  = new ReflectionMethod( $cls, $meth );
	$src = implode( '', array_slice( file( $rm->getFileName() ), $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1 ) );
	$structure_ok = $structure_ok && strpos( $src, 'check_admin_referer' ) !== false && strpos( $src, 'require_post' ) !== false && ( strpos( $src, 'current_user_can' ) !== false || strpos( $src, '$this->can()' ) !== false );
}
$all_html = '';
foreach ( array_keys( Mailroom_UI::TABS ) as $t ) { $all_html .= $render_tab( $t ); }
check( 'T62c handlers check cap + POST + nonce; no admin-post action link in any tab (only forms)',
	$structure_ok && preg_match( '/href="[^"]*admin-post\.php\?action=mailroom_(test_alert|report_now|drain_now|resend|save)/', $all_html ) === 0
);
remove_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'], 100 );
remove_filter( 'pre_http_request', $GLOBALS['tmp_http'], 5 );
test_opts( [] );

// T63 the legacy log URLs land on the Log tab
$GLOBALS['redirect_to'] = null;
$_GET = [ 'page' => 'wp-mail-smtp-logs' ];
try { $AD->maybe_redirect_legacy_logs_page(); } catch ( Exception $e ) { /* thrown by the T12 redirect filter */ }
$_GET = [];
check( 'T63 legacy log URL redirects to the Log tab', strpos( (string) $GLOBALS['redirect_to'], 'page=mailroom-log' ) !== false && strpos( (string) $GLOBALS['redirect_to'], 'tab=log' ) !== false );

// ====================================================================================================
// Native integration: our screens inside WP Mail SMTP's own admin (T64+). From here on the bridge is ON
// (T64b asserts it is available against the installed plugin version) unless a test pins it OFF.
// ====================================================================================================
$BR = 'Mailroom_Bridge';

// T64 the ON/OFF decision (pure): version range, kill-switch constant, missing class, missing module, WP Mail SMTP absent
$envok = [ 'constant' => null, 'ours' => true, 'version' => '4.10.0', 'missing' => [] ];
$cases = [
	'4.10.0 in range'                => [ $envok, '' ],
	'4.10.7 in range'                => [ array_merge( $envok, [ 'version' => '4.10.7' ] ), '' ],
	'constant true'                  => [ array_merge( $envok, [ 'constant' => true ] ), '' ],
	'constant false = kill switch'   => [ array_merge( $envok, [ 'constant' => false ] ), 'kill-switch-constant' ],
	'constant 0 = kill switch'       => [ array_merge( $envok, [ 'constant' => 0 ] ), 'kill-switch-constant' ],
	'4.9.9 below range'              => [ array_merge( $envok, [ 'version' => '4.9.9' ] ), 'version' ],
	'4.10.0-beta1 below range'       => [ array_merge( $envok, [ 'version' => '4.10.0-beta1' ] ), 'version' ],
	'4.11.0 above range'             => [ array_merge( $envok, [ 'version' => '4.11.0' ] ), 'version' ],
	'5.0.0 above range'              => [ array_merge( $envok, [ 'version' => '5.0.0' ] ), 'version' ],
	'WP Mail SMTP absent'            => [ array_merge( $envok, [ 'version' => '' ] ), 'wpms-missing' ],
	'a required class is missing'    => [ array_merge( $envok, [ 'missing' => [ 'WPMailSMTP\Admin\PageAbstract' ] ] ), 'missing:WPMailSMTP\Admin\PageAbstract' ],
	'a required method is missing'   => [ array_merge( $envok, [ 'missing' => [ 'WPMailSMTP\Admin\Area::get_pages' ] ] ), 'missing:WPMailSMTP\Admin\Area::get_pages' ],
	'our admin module is missing'    => [ array_merge( $envok, [ 'ours' => false ] ), 'module-missing' ],
];
$decide_ok = true;
foreach ( $cases as $label => [ $env, $want ] ) {
	$got = $BR::check( $env );
	if ( $got !== $want ) { $decide_ok = false; echo "  $label: wanted '$want', got '$got'\n"; }
}
check( 'T64 bridge decision: in range ON; constant false / version out of range / WP Mail SMTP absent / missing class or method / missing module → OFF', $decide_ok );

// T64b against the REAL installed plugin: every class/method we rely on exists, our subclasses load, and the bridge is ON.
// This is the "did a WP Mail SMTP update move a seam" check: it fails loudly instead of silently falling back.
remove_filter( 'mailroom_native_ui', $FORCE_OFF, 9999 );
$BR::reset();
$reason = $BR::reason();
if ( $reason !== '' ) { echo "  bridge is OFF: $reason (WP Mail SMTP " . ( defined( 'WPMS_PLUGIN_VER' ) ? WPMS_PLUGIN_VER : '?' ) . ")\n"; }
$rm_missing = new ReflectionMethod( $BR, 'missing_symbols' );
$rm_missing->setAccessible( true );
$all_native = true;
foreach ( $BR::NATIVE_CLASSES as $c ) { $all_native = $all_native && class_exists( $c, false ) && is_subclass_of( $c, '\WPMailSMTP\Admin\PageAbstract' ); }
check( 'T64b bridge is ON against the installed WP Mail SMTP; no required symbol missing; every native class extends PageAbstract',
	$reason === '' && $BR::active() && $rm_missing->invoke( null ) === [] && $all_native && version_compare( WPMS_PLUGIN_VER, $BR::MIN_VERSION, '>=' ) && version_compare( WPMS_PLUGIN_VER, $BR::MAX_VERSION_EXCL, '<' ) );

// T64c the kill-switch FILTER turns it off (and nothing in the environment can turn it back on)
$kill = function () { return false; };
add_filter( 'mailroom_native_ui', $kill, 10 );
$killed = ! $BR::active() && $BR::reason() === 'kill-switch-filter';
remove_filter( 'mailroom_native_ui', $kill, 10 );
$yes = function () { return true; };
add_filter( 'mailroom_native_ui', $yes, 10 );
$still_on = $BR::active();
remove_filter( 'mailroom_native_ui', $yes, 10 );
check( 'T64c kill-switch filter: false → OFF, true → unchanged', $killed && $still_on && $BR::active() );

// T65 OFF = today's behaviour: the registry filters hand their input back untouched, URLs stay on our page,
// the Lite summary email is left alone even with our report on, and no asset is printed on the plugin's own screens
add_filter( 'mailroom_native_ui', $FORCE_OFF, 9999 );
$sample_pages = [ 'settings' => 'S', 'logs' => 'L', 'alerts' => 'A', 'connections' => 'C', 'routing' => 'R', 'control' => 'K', 'misc' => 'M', 'auth' => 'U', 'get-pro' => 'P' ];
$sample_tools = [ 'test' => 'T', 'export' => 'E', 'debug-events' => 'D' ];
$sample_rep   = [ 'reports' => 'X' ];
test_opts( array_merge( Mailroom::defaults(), [ 'report_enabled' => 1 ] ) );
$_GET = [ 'page' => 'wp-mail-smtp-logs' ];
ob_start(); Mailroom_UI::print_css(); Mailroom_UI::print_js(); $off_assets = ob_get_clean();
$off_native_page = $BR::is_native_page();
$_GET = [];
check( 'T65 bridge OFF: registry filters inert (also through the real hooks), standalone URLs, Lite summary untouched, no assets on the plugin screens',
	$BR::filter_pages( $sample_pages ) === $sample_pages && $BR::filter_logs_fqcn( 'Lite\Logs' ) === 'Lite\Logs' && $BR::filter_tools_tabs( $sample_tools ) === $sample_tools && $BR::filter_reports_tabs( $sample_rep ) === $sample_rep
	&& apply_filters( 'wp_mail_smtp_admin_get_pages', $sample_pages ) === $sample_pages && apply_filters( 'wp_mail_smtp_admin_display_get_logs_fqcn', 'Lite\Logs' ) === 'Lite\Logs'
	&& apply_filters( 'wp_mail_smtp_admin_page_tools_tabs', $sample_tools ) === $sample_tools && apply_filters( 'wp_mail_smtp_admin_page_reports_tabs', $sample_rep ) === $sample_rep
	&& apply_filters( 'wp_mail_smtp_reports_emails_summary_is_disabled', false ) === false
	&& strpos( Mailroom_UI::tab_url( 'log' ), 'page=mailroom-log&tab=log' ) !== false && strpos( Mailroom_UI::tab_url( 'routing' ), 'page=mailroom-log&tab=delivery' ) !== false
	&& strpos( Mailroom_UI::tab_url( 'ratelimit' ), 'tab=delivery' ) !== false && strpos( Mailroom_UI::view_url( 5 ), 'page=mailroom-log&view=5' ) !== false
	&& Mailroom_UI::log_form_fields() === [ 'page' => 'mailroom-log', 'tab' => 'log' ] && ! $off_native_page && $off_assets === ''
);
test_opts( [] );
remove_filter( 'mailroom_native_ui', $FORCE_OFF, 9999 );
$BR::reset();

// T66 ON: the registry filters swap in our classes (real hooks), keep order and WP Mail SMTP's own tabs, drop get-pro
$P = 'WPMailSMTP\Admin\PageAbstract';
$pg = apply_filters( 'wp_mail_smtp_admin_get_pages', $sample_pages );
$want_slugs = [ 'logs' => 'logs', 'alerts' => 'alerts', 'connections' => 'connections', 'routing' => 'routing', 'control' => 'control' ];
$pages_ok = array_keys( $pg ) === [ 'settings', 'logs', 'alerts', 'connections', 'routing', 'control', 'misc', 'auth' ] && $pg['settings'] === 'S' && $pg['misc'] === 'M' && $pg['auth'] === 'U' && ! isset( $pg['get-pro'] );
foreach ( $want_slugs as $key => $slug ) { $pages_ok = $pages_ok && $pg[ $key ] instanceof $P && $pg[ $key ]->get_slug() === $slug; }
$pages_missing = apply_filters( 'wp_mail_smtp_admin_get_pages', [ 'settings' => 'S', 'get-pro' => 'P' ] ); // a tab the plugin lacks is not invented
$logs_class = apply_filters( 'wp_mail_smtp_admin_display_get_logs_fqcn', '\WPMailSMTP\Admin\Pages\Logs' );
$logs_obj   = new $logs_class();
$tools = apply_filters( 'wp_mail_smtp_admin_page_tools_tabs', $sample_tools );
$tools_none = apply_filters( 'wp_mail_smtp_admin_page_tools_tabs', [ 'test' => 'T' ] );
$rep = apply_filters( 'wp_mail_smtp_admin_page_reports_tabs', $sample_rep );
check( 'T66 ON: settings tabs logs/alerts/control/connections/routing → ours, others untouched, order kept, get-pro dropped, nothing invented',
	$pages_ok && $pages_missing === [ 'settings' => 'S' ] );
check( 'T66b ON: the "Email Log" submenu class is ours: a PageAbstract that is NOT Pages\Logs (so the plugin\'s redirect to its upsell tab cannot fire)',
	$logs_class === 'Mailroom_Native_Logs' && $logs_obj instanceof $P && ! ( $logs_obj instanceof \WPMailSMTP\Admin\Pages\Logs ) && strpos( $logs_obj->get_link(), 'page=wp-mail-smtp-logs' ) !== false );
check( 'T66c ON: Tools → Export and Email Reports tabs are ours (class names the plugin instantiates), other tools tabs untouched',
	$tools['export'] === 'Mailroom_Native_Export_Tab' && $tools['test'] === 'T' && $tools['debug-events'] === 'D' && array_keys( $tools ) === array_keys( $sample_tools ) && $tools_none === [ 'test' => 'T' ]
	&& $rep['reports'] === 'Mailroom_Native_Overview_Tab' && $rep['weekly'] === 'Mailroom_Native_Weekly_Tab'
	&& is_subclass_of( $tools['export'], $P ) && is_subclass_of( $rep['reports'], $P ) && is_subclass_of( $rep['weekly'], $P ) );

// T66d WP Mail SMTP's own labels are kept where the feature matches (compared with the real tab classes that need no Area)
$lite_labels = [ 'Alerts' => ( new \WPMailSMTP\Admin\Pages\AlertsTab() )->get_label(), 'Email Controls' => ( new \WPMailSMTP\Admin\Pages\ControlTab() )->get_label(), 'Export' => ( new \WPMailSMTP\Admin\Pages\ExportTab() )->get_label(), 'Email Reports' => ( new \WPMailSMTP\Admin\Pages\EmailReportsTab() )->get_label() ];
check( 'T66d our tab labels match WP Mail SMTP\'s; priorities keep their order; no "Pro" in any label',
	$pg['alerts']->get_label() === $lite_labels['Alerts'] && $pg['control']->get_label() === $lite_labels['Email Controls'] && ( new Mailroom_Native_Export_Tab() )->get_label() === $lite_labels['Export']
	&& ( new Mailroom_Native_Overview_Tab() )->get_label() === $lite_labels['Email Reports'] && $pg['logs']->get_label() === 'Email Log' && $pg['connections']->get_label() === 'Additional Connections' && $pg['routing']->get_label() === 'Smart Routing'
	&& ( new Mailroom_Native_Overview_Tab() )->get_priority() < ( new Mailroom_Native_Weekly_Tab() )->get_priority()
	&& ! preg_grep( '/\bpro\b/i', [ $pg['logs']->get_label(), $pg['alerts']->get_label(), $pg['control']->get_label(), $pg['connections']->get_label(), $pg['routing']->get_label(), ( new Mailroom_Native_Weekly_Tab() )->get_label() ] ) );

// T67 every native tab renders for an administrator: expected fields, an accessible label for every input, nothing
// disabled, no nested <form> (WP Mail SMTP calls display() bare, so our own forms are the only ones), and the
// per-section forms carry exactly the sections that tab owns
wp_set_current_user( $uid );
test_opts( [] );
$label_problems = function ( string $h ) {
	$bad = [];
	preg_match_all( '/<(input|select|textarea)\b([^>]*)>/i', $h, $mm, PREG_SET_ORDER );
	foreach ( $mm as $x ) {
		// Attributes may be single- or double-quoted: WP core's list-table pagination uses id='current-page-selector'.
		if ( preg_match( '/type=["\'](hidden|submit)["\']/', $x[2] ) ) { continue; }
		if ( preg_match( '/\sdisabled\b/', $x[2] ) ) { $bad[] = "disabled: {$x[0]}"; }
		if ( preg_match( '/\bid=["\']([^"\']+)["\']/', $x[2], $id ) ) {
			if ( ! preg_match( '/\bfor=["\']' . preg_quote( $id[1], '/' ) . '["\']/', $h ) ) { $bad[] = "no <label for> for #{$id[1]}"; }
		} elseif ( ! preg_match( '/type=["\'](button|submit|reset)["\']/', $x[2] ) ) {
			$bad[] = "no id: {$x[0]}";
		}
	}
	return $bad;
};
$nested_forms = function ( string $h ) {
	$d = 0;
	preg_match_all( '/<(\/?)form\b/i', $h, $m );
	foreach ( $m[1] as $close ) { $d += $close === '/' ? -1 : 1; if ( $d > 1 || $d < 0 ) { return true; } }
	return $d !== 0;
};
$hrefs = function ( string $h ) { preg_match_all( '/href="([^"]+)"/', $h, $m ); return array_map( 'html_entity_decode', $m[1] ); };
$has_href = function ( string $h, string $needle ) use ( $hrefs ) { foreach ( $hrefs( $h ) as $u ) { if ( strpos( $u, $needle ) !== false ) { return true; } } return false; };
$render_obj = function ( $obj, array $get ) {
	$_GET = $get;
	ob_start();
	try { $obj->display(); $h = ob_get_clean(); } catch ( \Throwable $e ) { ob_end_clean(); $h = 'EXCEPTION: ' . $e->getMessage(); }
	$_GET = [];
	return $h;
};
$native = [
	// label => [ object, $_GET, markers that must appear, sections its forms post ]
	'logs (Email Log page)'  => [ new Mailroom_Native_Logs(), [ 'page' => 'wp-mail-smtp-logs' ], [ 'mailroom-f-status', 'wp-list-table', 'Export CSV', 'name="page" value="wp-mail-smtp-logs"', 'wp-mail-smtp-page-title' ], [] ],
	'storage (Settings → Email Log)' => [ new Mailroom_Native_Storage_Tab(), [ 'page' => 'wp-mail-smtp', 'tab' => 'logs' ], [ 'name="retention_days"', 'name="log_body"', 'name="store_content"', 'name="content_days"' ], [ 'storage' ] ],
	'alerts'      => [ new Mailroom_Native_Alerts_Tab(), [ 'page' => 'wp-mail-smtp', 'tab' => 'alerts' ], [ 'name="alert_webhook"', 'name="alert_email"', 'mailroom_test_alert' ], [ 'alerts' ] ],
	'controls'    => [ new Mailroom_Native_Controls_Tab(), [ 'page' => 'wp-mail-smtp', 'tab' => 'control' ], [ 'name="controls_off[]"' ], [ 'controls' ] ],
	'connections' => [ new Mailroom_Native_Connections_Tab(), [ 'page' => 'wp-mail-smtp', 'tab' => 'connections' ], [ 'name="backup_host"', 'name="backup_from"', 'name="backup_enabled"' ], [ 'connections' ] ],
	'routing (+ rate limiting)' => [ new Mailroom_Native_Routing_Tab(), [ 'page' => 'wp-mail-smtp', 'tab' => 'routing' ], [ 'name="route_rules"', 'name="route_enabled"', 'name="rate_max"', 'name="rate_exempt"', 'Queue' ], [ 'routing', 'ratelimit' ] ],
	'export'      => [ new Mailroom_Native_Export_Tab(), [ 'page' => 'wp-mail-smtp-tools', 'tab' => 'export' ], [ 'value="mailroom_export"', 'method="get"', 'for="mailroom-x-q"', '.eml' ], [] ],
	'overview (Email Reports)' => [ new Mailroom_Native_Overview_Tab(), [ 'page' => 'wp-mail-smtp-reports', 'tab' => 'reports' ], [ 'Last 7 days', 'data-feature="routing"', 'data-feature="alerts"' ], [] ],
	'weekly report' => [ new Mailroom_Native_Weekly_Tab(), [ 'page' => 'wp-mail-smtp-reports', 'tab' => 'weekly' ], [ 'name="report_email"', 'name="report_enabled"', 'mailroom_report_now' ], [ 'reports' ] ],
];
$not_in = [ 'connections' => [ 'route_rules', 'rate_max' ], 'routing (+ rate limiting)' => [ 'backup_host' ], 'storage (Settings → Email Log)' => [ 'backup_host', 'alert_webhook' ] ];
$render_ok = true;
foreach ( $native as $label => [ $obj, $get, $markers, $sections ] ) {
	$h  = $render_obj( $obj, $get );
	$ok = strpos( $h, 'EXCEPTION' ) !== 0 && strpos( $h, 'could not be displayed' ) === false && strpos( $h, 'nav-tab-wrapper' ) === false && strpos( $h, 'value="mailroom-log"' ) === false && ! $has_href( $h, 'mailroom-log' );
	foreach ( $markers as $m ) { $ok = $ok && strpos( $h, $m ) !== false; }
	foreach ( $not_in[ $label ] ?? [] as $m ) { $ok = $ok && strpos( $h, $m ) === false; }
	preg_match_all( '/name="section" value="([a-z]+)"/', $h, $sm );
	$ok = $ok && $sm[1] === $sections && ! $nested_forms( $h ) && $label_problems( $h ) === [] && preg_match( '/href="[^"]*admin-post\.php\?action=mailroom_(test_alert|report_now|drain_now|resend|save)/', $h ) === 0;
	if ( ! $ok ) { echo "  native tab '$label' failed. sections=" . json_encode( $sm[1] ) . ' problems=' . json_encode( $label_problems( $h ) ) . ' nested=' . json_encode( $nested_forms( $h ) ) . ' starts: ' . substr( wp_strip_all_tags( $h ), 0, 200 ) . "\n"; }
	$render_ok = $render_ok && $ok;
}
check( 'T67 every native tab renders: expected fields, own sections only, labelled inputs, nothing disabled, no nested <form>, no standalone chrome or legacy URLs', $render_ok );


// T67b the detail view inside the plugin's page: no second .wrap, back link to the native log
$did = $seed( [ 'subject' => 'Native detail', 'content' => wp_json_encode( [ 'to' => 'n@example.com', 'subject' => 'Native detail', 'message' => 'hi', 'headers' => '', 'attachments' => [] ] ) ] );
$hd  = $render_obj( new Mailroom_Native_Logs(), [ 'page' => 'wp-mail-smtp-logs', 'view' => (string) $did ] );
check( 'T67b native single-email view: renders the detail (resend form, tabs), links back to the native log, no nested .wrap, no nested form',
	strpos( $hd, 'Native detail' ) !== false && strpos( $hd, 'for="mailroom-resend-to"' ) !== false && strpos( $hd, 'data-mailroom-tab="body"' ) !== false && $has_href( $hd, 'page=wp-mail-smtp-logs' )
	&& strpos( $hd, 'class="wrap' ) === false && ! $nested_forms( $hd ) );

// T67c links point at the native screens: Overview cards, the last failure and the list rows open the single-email view in the plugin's page
$wpdb->query( "TRUNCATE TABLE {$T}" );
test_opts( array_merge( Mailroom::defaults(), [ 'backup_enabled' => 1, 'backup_host' => 'h.test', 'backup_from' => 'b@example.com', 'route_enabled' => 1, 'route_rules' => 'to: x', 'rate_enabled' => 1, 'report_enabled' => 1 ] ) );
$fid = $seed( [ 'status' => 'failed', 'error' => 'boom' ] );
$ho  = $render_obj( new Mailroom_Native_Overview_Tab(), [ 'page' => 'wp-mail-smtp-reports', 'tab' => 'reports' ] );
$hl  = $render_obj( new Mailroom_Native_Logs(), [ 'page' => 'wp-mail-smtp-logs' ] );
test_opts( [] );
check( 'T67c native links: Overview cards → Additional Connections / Smart Routing / Alerts / Weekly Report, last failure and list rows → single-email view in the plugin page, nothing on our legacy page',
	$has_href( $ho, 'page=wp-mail-smtp&tab=connections' ) && $has_href( $ho, 'page=wp-mail-smtp&tab=routing' ) && $has_href( $ho, 'page=wp-mail-smtp&tab=alerts' ) && $has_href( $ho, 'page=wp-mail-smtp&tab=logs' )
	&& $has_href( $ho, 'page=wp-mail-smtp&tab=control' ) && $has_href( $ho, 'page=wp-mail-smtp-reports&tab=weekly' ) && $has_href( $ho, 'page=wp-mail-smtp-logs&view=' . $fid )
	&& $has_href( $hl, 'page=wp-mail-smtp-logs&view=' . $fid ) && ! $has_href( $ho . $hl, 'mailroom-log' ) );

// T68 URL mapping for every logical tab (ON), and finish() only accepts known tabs
$base_ok = true;
foreach ( [
	'overview' => 'page=wp-mail-smtp-reports&tab=reports', 'reports' => 'page=wp-mail-smtp-reports&tab=weekly', 'log' => 'page=wp-mail-smtp-logs',
	'storage' => 'page=wp-mail-smtp&tab=logs', 'alerts' => 'page=wp-mail-smtp&tab=alerts', 'controls' => 'page=wp-mail-smtp&tab=control',
	'delivery' => 'page=wp-mail-smtp&tab=connections', 'connections' => 'page=wp-mail-smtp&tab=connections', 'routing' => 'page=wp-mail-smtp&tab=routing',
	'ratelimit' => 'page=wp-mail-smtp&tab=routing', 'export' => 'page=wp-mail-smtp-tools&tab=export',
] as $k => $frag ) {
	$u = Mailroom_UI::tab_url( $k, [ 'updated' => 1 ] );
	if ( strpos( $u, $frag ) === false || strpos( $u, 'updated=1' ) === false || strpos( $u, 'mailroom-log' ) !== false ) { $base_ok = false; echo "  $k → $u\n"; }
}
check( 'T68 ON: every logical tab maps to its WP Mail SMTP page/tab; view URL and log filter form target the plugin\'s log page; unknown keys fall back to our page',
	$base_ok && strpos( Mailroom_UI::view_url( 9 ), 'page=wp-mail-smtp-logs&view=9' ) !== false && Mailroom_UI::log_form_fields() === [ 'page' => 'wp-mail-smtp-logs' ]
	&& $BR::url( 'nope' ) === '' && strpos( Mailroom_UI::tab_url( 'nope' ), 'page=mailroom-log&tab=nope' ) !== false );

// T69 legacy URLs: our old page (bookmarks, alert and report emails) goes to the native equivalent; the plugin's own log URLs are NOT redirected any more
$redir = function ( array $get ) use ( $AD ) {
	$_GET = $get;
	$GLOBALS['redirect_to'] = null;
	$threw = false;
	try { $AD->maybe_redirect_legacy_logs_page(); } catch ( Exception $e ) { $threw = true; } // the T12 wp_redirect filter throws
	$_GET = [];
	return $threw ? html_entity_decode( (string) $GLOBALS['redirect_to'] ) : null;
};
$legacy = [
	'bare page'          => [ [ 'page' => 'mailroom-log' ], [ 'page=wp-mail-smtp-reports', 'tab=reports' ] ],
	'tab alerts'         => [ [ 'page' => 'mailroom-log', 'tab' => 'alerts' ], [ 'page=wp-mail-smtp&tab=alerts' ] ],
	'tab delivery'       => [ [ 'page' => 'mailroom-log', 'tab' => 'delivery' ], [ 'page=wp-mail-smtp&tab=connections' ] ],
	'tab storage'        => [ [ 'page' => 'mailroom-log', 'tab' => 'storage' ], [ 'page=wp-mail-smtp&tab=logs' ] ],
	'tab controls'       => [ [ 'page' => 'mailroom-log', 'tab' => 'controls' ], [ 'page=wp-mail-smtp&tab=control' ] ],
	'tab reports'        => [ [ 'page' => 'mailroom-log', 'tab' => 'reports' ], [ 'page=wp-mail-smtp-reports&tab=weekly' ] ],
	'unknown tab'        => [ [ 'page' => 'mailroom-log', 'tab' => 'bogus' ], [ 'page=wp-mail-smtp-reports&tab=reports' ] ],
	'log with filters'   => [ [ 'page' => 'mailroom-log', 'tab' => 'log', 'status' => 'failed', 'q' => 'boom', 'paged' => '3', 'evil' => 'x' ], [ 'page=wp-mail-smtp-logs', 'status=failed', 'q=boom', 'paged=3' ] ],
	'single-email view'  => [ [ 'page' => 'mailroom-log', 'view' => '7' ], [ 'page=wp-mail-smtp-logs', 'view=7' ] ],
];
$legacy_ok = true;
foreach ( $legacy as $label => [ $get, $frags ] ) {
	$to = $redir( $get );
	$ok = $to !== null && strpos( $to, 'mailroom-log' ) === false && strpos( $to, 'evil' ) === false;
	foreach ( $frags as $f ) { $ok = $ok && strpos( (string) $to, $f ) !== false; }
	if ( ! $ok ) { $legacy_ok = false; echo "  legacy '$label' → " . var_export( $to, true ) . "\n"; }
}
wp_set_current_user( 0 );
$no_cap = $redir( [ 'page' => 'mailroom-log' ] );
wp_set_current_user( $uid );
check( 'T69 ON: old page=mailroom-log[&tab|view|filters] URLs redirect to the native screen (admins only); the plugin\'s own log URLs are left alone',
	$legacy_ok && $no_cap === null && $redir( [ 'page' => 'wp-mail-smtp-logs' ] ) === null && $redir( [ 'page' => 'wp-mail-smtp', 'tab' => 'logs' ] ) === null && $redir( [ 'page' => 'something-else' ] ) === null );

// T69b (regression, found in the browser): with native ON, page=mailroom-log is unregistered and core's
// wp-admin/includes/menu.php denies it BEFORE admin_init runs, so the redirect must ALSO hang on
// `admin_page_access_denied` (fired just before that wp_die). Verify the core ordering this relies on, the hook
// registration, and that firing the REAL action (not calling the method) produces the native redirect.
$core_menu  = (string) file_get_contents( ABSPATH . 'wp-admin/includes/menu.php' );
$core_admin = (string) file_get_contents( ABSPATH . 'wp-admin/admin.php' );
$order_ok   = strpos( $core_menu, "do_action( 'admin_page_access_denied' )" ) !== false
	&& strpos( $core_admin, "wp-admin/menu.php'" ) !== false
	&& strpos( $core_admin, "wp-admin/menu.php'" ) < strpos( $core_admin, "do_action( 'admin_init' )" );
$hooked     = has_action( 'admin_page_access_denied', [ $AD, 'maybe_redirect_legacy_logs_page' ] ) === 0;
$GLOBALS['t69b_to'] = null;
$t69b_cap = function ( $loc ) { $GLOBALS['t69b_to'] = $loc; throw new Exception( 't69b' ); };
add_filter( 'wp_redirect', $t69b_cap, 1 ); // before T12's throwing filter at priority 10
$_GET = [ 'page' => 'mailroom-log', 'tab' => 'log', 'view' => '21' ];
try { do_action( 'admin_page_access_denied' ); } catch ( Exception $e ) { /* redirect captured */ }
$_GET = [];
remove_filter( 'wp_redirect', $t69b_cap, 1 );
check( 'T69b ON: legacy URL also redirects via the real admin_page_access_denied hook (core denies unregistered pages before admin_init)',
	$order_ok && $hooked && strpos( (string) $GLOBALS['t69b_to'], 'page=wp-mail-smtp-logs' ) !== false && strpos( (string) $GLOBALS['t69b_to'], 'view=21' ) !== false );

// T70 ON: the menu has exactly ONE "Email Log" item (the plugin's own, wp-mail-smtp-logs): nothing added, nothing removed
global $submenu;
$submenu['wp-mail-smtp'] = [];
add_menu_page( 'WP Mail SMTP', 'WP Mail SMTP', 'manage_options', 'wp-mail-smtp', '__return_false', '' );
add_submenu_page( 'wp-mail-smtp', 'Email Log', 'Email Log', 'manage_options', 'wp-mail-smtp-logs', '__return_false' );
$AD->menu();
$hookname = get_plugin_page_hookname( 'wp-mail-smtp-logs', 'wp-mail-smtp' );
$slugs    = array_column( (array) $submenu['wp-mail-smtp'], 2 );
$titles   = array_column( (array) $submenu['wp-mail-smtp'], 0 );
$screen_hooked = (bool) has_action( 'load-' . $hookname, [ $AD, 'screen_options' ] );
remove_action( 'load-' . $hookname, [ $AD, 'screen_options' ] );
check( 'T70 ON: menu() leaves the plugin\'s wp-mail-smtp-logs item, adds no mailroom-log item (one "Email Log" in total); Screen Options hooked to the plugin\'s log screen',
	in_array( 'wp-mail-smtp-logs', $slugs, true ) && ! in_array( 'mailroom-log', $slugs, true ) && count( array_keys( $titles, 'Email Log', true ) ) === 1 && $screen_hooked );

// T71 handlers redirect back to the NATIVE tab they came from (same cap + POST + nonce rules; the guards live in the handlers)
$GLOBALS['act_mail'] = []; $GLOBALS['act_http'] = [];
add_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'] = function ( $pre, $atts ) { $GLOBALS['act_mail'][] = $atts; return true; }, 100, 2 );
add_filter( 'pre_http_request', $GLOBALS['tmp_http'] = function ( $pre, $args, $url ) { $GLOBALS['act_http'][] = $url; return [ 'headers' => [], 'body' => 'ok', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ]; }, 5, 3 );
test_opts( array_merge( Mailroom::defaults(), [ 'alert_webhook' => 'https://hooks.slack.com/services/A/B/C' ] ) );
$resend2 = $seed( [ 'subject' => 'To resend native', 'content' => wp_json_encode( [ 'to' => 'orig@example.com', 'subject' => 'x', 'message' => 'hi', 'headers' => '', 'attachments' => [] ] ) ] );
$native_handlers = [
	// label => [ callable, nonce action, post fields, fragments of the success redirect ]
	'test alert'      => [ [ $AM->module( 'alerts' ), 'handle_test' ], 'mailroom_test_alert', [ 'tab' => 'alerts' ], [ 'page=wp-mail-smtp&tab=alerts' ] ],
	'report now'      => [ [ $AM->module( 'reports' ), 'handle_now' ], 'mailroom_report_now', [ 'tab' => 'reports' ], [ 'page=wp-mail-smtp-reports&tab=weekly' ] ],
	'drain now'       => [ [ $AM->module( 'ratelimit' ), 'handle_drain_now' ], 'mailroom_drain_now', [ 'tab' => 'ratelimit' ], [ 'page=wp-mail-smtp&tab=routing' ] ],
	'drain (default)' => [ [ $AM->module( 'ratelimit' ), 'handle_drain_now' ], 'mailroom_drain_now', [ 'tab' => 'bogus-tab' ], [ 'page=wp-mail-smtp&tab=routing' ] ],
	'resend'          => [ [ $AM->module( 'content' ), 'handle_resend' ], 'mailroom_resend_' . $resend2, [ 'tab' => 'log', 'id' => $resend2, 'to' => 'other@example.com' ], [ 'page=wp-mail-smtp-logs' ] ],
];
$nh_ok = true;
foreach ( $native_handlers as $label => [ $fn, $nonce_action, $extra, $frags ] ) {
	$good = array_merge( $extra, [ '_wpnonce' => wp_create_nonce( $nonce_action ) ] );
	$r_get = $run( $fn, 'GET', $good );
	$r_ok  = $run( $fn, 'POST', $good );
	$to    = html_entity_decode( $r_ok[1] );
	$ok    = $r_get[0] === 'die' && $r_ok[0] === 'redirect' && strpos( $to, 'mailroom-log' ) === false;
	foreach ( $frags as $f ) { $ok = $ok && strpos( $to, $f ) !== false; }
	if ( ! $ok ) { $nh_ok = false; echo "  $label: " . json_encode( [ $r_get, $r_ok ] ) . "\n"; }
	delete_transient( 'mailroom_notice_' . $uid );
}
remove_filter( 'pre_wp_mail', $GLOBALS['tmp_pre'], 100 );
remove_filter( 'pre_http_request', $GLOBALS['tmp_http'], 5 );
test_opts( [] );
check( 'T71 ON: test alert / report now / process queue / resend refuse GET and redirect to the native tab they came from', $nh_ok );

// T72 the save handler through the NATIVE forms: each of the three split sections changes only its own keys, redirects to its native tab.
// The write is neutralised (pre_update_option returns the old value, so WordPress skips the DB write) and the live row is compared before/after.
$opt_raw = function () use ( $wpdb ) { return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Mailroom::OPT ) ); };
$opt_before = $opt_raw();
$GLOBALS['saved'] = null;
$cap = function ( $value, $old ) { $GLOBALS['saved'] = $value; return $old; };
add_filter( 'pre_update_option_' . Mailroom::OPT, $cap, PHP_INT_MAX, 2 );
test_opts( $ON );
$save_cases = [
	'connections' => [ [ 'backup_enabled' => '1', 'backup_host' => 'native.test', 'backup_port' => '2526', 'backup_secure' => 'tls', 'backup_user' => 'nu', 'backup_pass' => '', 'backup_from' => 'n@example.com' ], [ 'backup_host' => 'native.test', 'backup_port' => 2526, 'backup_secure' => 'tls', 'backup_user' => 'nu', 'backup_from' => 'n@example.com' ], 'page=wp-mail-smtp&tab=connections' ],
	'routing'     => [ [ 'route_rules' => 'subject: Native' ], [ 'route_enabled' => 0, 'route_rules' => 'subject: Native' ], 'page=wp-mail-smtp&tab=routing' ],
	'ratelimit'   => [ [ 'rate_max' => '7', 'rate_window' => '15', 'rate_exempt' => 'Reset' ], [ 'rate_enabled' => 0, 'rate_max' => 7, 'rate_window' => 15, 'rate_exempt' => 'Reset' ], 'page=wp-mail-smtp&tab=routing' ],
];
$save_ok = true;
foreach ( $save_cases as $sec => [ $post, $changed, $frag ] ) {
	$GLOBALS['saved'] = null;
	$r = $run( [ $AD, 'handle_save' ], 'POST', array_merge( $post, [ '_wpnonce' => wp_create_nonce( 'mailroom_save' ), 'section' => $sec, 'action' => 'mailroom_save' ] ) );
	$saved = (array) $GLOBALS['saved'];
	$ok = $r[0] === 'redirect' && strpos( html_entity_decode( $r[1] ), $frag ) !== false && strpos( $r[1], 'updated=1' ) !== false && $saved !== [];
	foreach ( $ON as $k => $v ) {
		$want = array_key_exists( $k, $changed ) ? $changed[ $k ] : $v; // everything not listed (incl. the blank-kept backup password) is exactly as it was
		if ( ( $saved[ $k ] ?? null ) !== $want ) { $ok = false; echo "  $sec: $k expected " . json_encode( $want ) . ' got ' . json_encode( $saved[ $k ] ?? null ) . "\n"; }
	}
	$save_ok = $save_ok && $ok;
}
wp_set_current_user( 0 );
$r_cap = $run( [ $AD, 'handle_save' ], 'POST', [ '_wpnonce' => wp_create_nonce( 'mailroom_save' ), 'section' => 'routing' ] );
wp_set_current_user( $uid );
$r_get = $run( [ $AD, 'handle_save' ], 'GET', [ '_wpnonce' => wp_create_nonce( 'mailroom_save' ), 'section' => 'routing' ] );
remove_filter( 'pre_update_option_' . Mailroom::OPT, $cap, PHP_INT_MAX );
delete_transient( 'mailroom_notice_' . $uid );
test_opts( [] );
check( 'T72 native save: connections / routing / ratelimit forms each change only their own keys (unticked boxes → 0, blank password keeps the secret), redirect to the native tab; GET and no-capability refused; live option row untouched',
	$save_ok && $r_cap[0] === 'die' && $r_get[0] === 'die' && $opt_raw() === $opt_before );

// T73 the three split sections: pure sanitize isolation + equivalence with the combined `delivery` section
$NOWN = [
	'connections' => [ 'backup_enabled', 'backup_host', 'backup_port', 'backup_secure', 'backup_user', 'backup_pass', 'backup_from' ],
	'routing'     => [ 'route_enabled', 'route_rules' ],
	'ratelimit'   => [ 'rate_enabled', 'rate_max', 'rate_window', 'rate_exempt' ],
];
$clobber = [];
foreach ( $NOWN as $sec => $keys ) {
	[ $n ] = $ADM::sanitize_section( $sec, [], $ON ); // the worst case: every checkbox unticked, nothing else submitted
	foreach ( $ON as $k => $v ) { if ( ! in_array( $k, $keys, true ) && $n[ $k ] !== $v ) { $clobber[] = "$sec clobbered $k"; } }
}
$full = [ 'backup_enabled' => '1', 'backup_host' => 'h.test', 'backup_port' => '465', 'backup_secure' => 'ssl', 'backup_user' => 'u', 'backup_pass' => '', 'backup_from' => 'f@example.com', 'route_enabled' => '1', 'route_rules' => 'to: @x.test', 'rate_enabled' => '1', 'rate_max' => '9', 'rate_window' => '15', 'rate_exempt' => "A\nB" ];
[ $combined ] = $ADM::sanitize_section( 'delivery', $full, $ON );
$seq = $ON;
foreach ( array_keys( $NOWN ) as $sec ) { [ $seq ] = $ADM::sanitize_section( $sec, $full, $seq ); }
$bad_names_ok = true;
foreach ( [ 'connection', 'ratelimits', 'native', 'Routing', 'routing ' ] as $bad ) { [ $nb, $okb ] = $ADM::sanitize_section( $bad, $full, $ON ); $bad_names_ok = $bad_names_ok && $nb === $ON && $okb === false; }
$union = array_merge( ...array_values( $NOWN ) ); sort( $union ); $dkeys = $OWN['delivery']; sort( $dkeys );
[ $nr ] = Mailroom::instance()->module( 'routing' )->sanitize( [ $ON, true ], [ 'route_rules' => 'to: z' ], 'ratelimit' ); // a module ignores a native section that is not its own
[ $nl ] = Mailroom::instance()->module( 'ratelimit' )->sanitize( [ $ON, true ], [ 'rate_max' => '3' ], 'routing' );
if ( $clobber ) { echo '  ' . implode( "\n  ", $clobber ) . "\n"; }
check( 'T73 split sections: each owns a disjoint subset of `delivery`; saving one (empty POST) never touches another section; the three together equal `delivery`; near-miss names change nothing',
	$clobber === [] && $union === $dkeys && $seq === $combined && $bad_names_ok && $nr === $ON && $nl === $ON );

// T74 assets: printed only on the screens that render our content, and on no other WP Mail SMTP screen
$asset_cases = [
	[ [ 'page' => 'wp-mail-smtp-logs' ], true ], [ [ 'page' => 'wp-mail-smtp-logs', 'view' => '3' ], true ],
	[ [ 'page' => 'wp-mail-smtp', 'tab' => 'logs' ], true ], [ [ 'page' => 'wp-mail-smtp', 'tab' => 'alerts' ], true ], [ [ 'page' => 'wp-mail-smtp', 'tab' => 'control' ], true ],
	[ [ 'page' => 'wp-mail-smtp', 'tab' => 'connections' ], true ], [ [ 'page' => 'wp-mail-smtp', 'tab' => 'routing' ], true ],
	[ [ 'page' => 'wp-mail-smtp-reports' ], true ], [ [ 'page' => 'wp-mail-smtp-reports', 'tab' => 'weekly' ], true ], [ [ 'page' => 'wp-mail-smtp-tools', 'tab' => 'export' ], true ],
	[ [ 'page' => 'wp-mail-smtp' ], false ], [ [ 'page' => 'wp-mail-smtp', 'tab' => 'settings' ], false ], [ [ 'page' => 'wp-mail-smtp', 'tab' => 'misc' ], false ], [ [ 'page' => 'wp-mail-smtp', 'tab' => 'auth' ], false ],
	[ [ 'page' => 'wp-mail-smtp-tools' ], false ], [ [ 'page' => 'wp-mail-smtp-tools', 'tab' => 'test' ], false ], [ [ 'page' => 'wp-mail-smtp-about' ], false ], [ [ 'page' => 'wp-mail-smtp-dashboard' ], false ],
	[ [ 'page' => 'something-else' ], false ], [ [], false ],
];
$assets_ok = true;
foreach ( $asset_cases as [ $get, $want ] ) {
	$_GET = $get;
	ob_start(); Mailroom_UI::print_css(); Mailroom_UI::print_js(); $out = ob_get_clean();
	$_GET = [];
	$printed = strpos( $out, '.mailroom-badge' ) !== false && strpos( $out, 'data-mailroom-toggle' ) !== false;
	if ( $printed !== $want || ( ! $want && $out !== '' ) ) { $assets_ok = false; echo '  assets ' . json_encode( $get ) . ' printed=' . json_encode( $printed ) . "\n"; }
}
check( 'T74 ON: CSS + JS print on the log / our six settings tabs / reports / export screens only; none on settings, misc, tools test, about or any other page', $assets_ok );

// T75 Lite's own weekly summary email is disabled only while OUR weekly report is on (real hook; it also runs from cron)
test_opts( array_merge( Mailroom::defaults(), [ 'report_enabled' => 1 ] ) );
$on_true  = apply_filters( 'wp_mail_smtp_reports_emails_summary_is_disabled', false );
test_opts( array_merge( Mailroom::defaults(), [ 'report_enabled' => 0 ] ) );
$off_pass = apply_filters( 'wp_mail_smtp_reports_emails_summary_is_disabled', false ) === false && apply_filters( 'wp_mail_smtp_reports_emails_summary_is_disabled', true ) === true;
test_opts( [] );
check( 'T75 Lite summary email: disabled while our weekly report is enabled, its own setting is passed through otherwise', $on_true === true && $off_pass );

// T76 Export tab: a GET form to admin-post with a nonce the handler accepts, our filters preserved, labelled
$hx = $render_obj( new Mailroom_Native_Export_Tab(), [ 'page' => 'wp-mail-smtp-tools', 'tab' => 'export', 'status' => 'failed', 'q' => 'boom' ] );
preg_match( '/name="_wpnonce"[^>]*value="([^"]+)"|value="([^"]+)"[^>]*name="_wpnonce"/', $hx, $nm );
$nonce = ( $nm[1] ?? '' ) ?: ( $nm[2] ?? '' );
check( 'T76 Export tab: GET form → admin-post.php (action mailroom_export) with a valid export nonce; status/search filters carried; not nested',
	$nonce !== '' && wp_verify_nonce( $nonce, 'mailroom_export' ) && strpos( $hx, 'admin-post.php' ) !== false && preg_match( '/value="failed"\s+selected/', $hx ) === 1 && strpos( $hx, 'value="boom"' ) !== false && ! $nested_forms( $hx ) );

// T77 the kill switch end to end: pinned OFF again, the whole standalone behaviour is back (redirect of the plugin's URL, menu, URLs)
add_filter( 'mailroom_native_ui', $FORCE_OFF, 9999 );
$off_redirect = $redir( [ 'page' => 'wp-mail-smtp-logs' ] );
$off_view     = Mailroom_UI::view_url( 4 );
$submenu['wp-mail-smtp'] = [];
add_submenu_page( 'wp-mail-smtp', 'Email Log', 'Email Log', 'manage_options', 'wp-mail-smtp-logs', '__return_false' );
$AD->menu();
$off_menu_slugs = array_column( (array) $submenu['wp-mail-smtp'], 2 );
remove_filter( 'mailroom_native_ui', $FORCE_OFF, 9999 );
check( 'T77 kill switch (filter): standalone behaviour is back: plugin log URL redirects to our page, our menu item replaces the plugin\'s, view URL on our page',
	$off_redirect !== null && strpos( $off_redirect, 'page=mailroom-log' ) !== false && strpos( $off_view, 'page=mailroom-log&view=4' ) !== false
	&& ! in_array( 'wp-mail-smtp-logs', $off_menu_slugs, true ) && in_array( 'mailroom-log', $off_menu_slugs, true ) );
$_GET = [];


// ====================================================================================================
// P3: WP Mail SMTP's own Dashboard page and the wp-admin index widget, fed from OUR log (T78+).
// The bridge is ON here (T77 removed the pin). Everything uses the isolated test table and in-memory options.
// ====================================================================================================
$DD  = 'Mailroom_Dashdata';
$DNS = 'WPMailSMTP\\Admin\\Dashboard\\';
$P3_OFF = function () { return false; };
$P3_ON  = function () { return true; };
test_opts( [] );
wp_set_current_user( $uid );
$BR::reset();
$DD::flush();
$wpdb->query( "TRUNCATE TABLE {$T}" );

$ins = function ( string $status, string $at, array $extra = [] ) use ( $wpdb, $T ) {
	$wpdb->insert( $T, array_merge( [ 'created_at' => $at, 'status' => $status, 'source' => 'smtp', 'mailer' => 'smtp', 'to_email' => 'a@example.com', 'subject' => 'S', 'user_id' => 0 ], $extra ) );
	return (int) $wpdb->insert_id;
};
// A site-local datetime $day days after Monday 00:00 of the week $back weeks ago.
$at = function ( int $back, int $day = 2, string $time = '12:00:00' ) use ( $DD ) {
	return $DD::week_start( $back )->modify( "+{$day} days" )->format( 'Y-m-d' ) . ' ' . $time;
};
$access = new ( $DNS . 'AccessContext' )( [ 'can_manage' => true ] );

// T78 the P3 decision (pure) and the signature guard against fake classes with incompatible signatures
$denv = [ 'constant' => null, 'native' => true, 'ours' => true, 'missing' => [] ];
$dcases = [
	'all good'                          => [ $denv, '' ],
	'constant true'                     => [ array_merge( $denv, [ 'constant' => true ] ), '' ],
	'constant false = P3 kill switch'   => [ array_merge( $denv, [ 'constant' => false ] ), 'kill-switch-constant' ],
	'constant 0 = P3 kill switch'       => [ array_merge( $denv, [ 'constant' => 0 ] ), 'kill-switch-constant' ],
	'bridge (native integration) OFF'   => [ array_merge( $denv, [ 'native' => false ] ), 'native-off' ],
	'our data module missing'           => [ array_merge( $denv, [ 'ours' => false ] ), 'module-missing' ],
	'a Dashboard symbol is missing'     => [ array_merge( $denv, [ 'missing' => [ 'A::b (signature changed)' ] ] ), 'missing:A::b (signature changed)' ],
];
$d_ok = true;
foreach ( $dcases as $label => [ $env, $want ] ) {
	$got = $BR::check_dashboard( $env );
	if ( $got !== $want ) { $d_ok = false; echo "  $label: wanted '$want', got '$got'\n"; }
}
check( 'T78 P3 decision: bridge OFF / P3 constant false / missing module / missing or changed Dashboard symbol → OFF; otherwise ON', $d_ok );

abstract class T78_Abstract {}
final class T78_Final {}
class T78_NoCtor {}
class T78_Ctor { public function __construct( int $n ) {} }
class T78_Methods {
	protected function a( $x ) {}
	public function b( ?array $r = null ): array { return []; }
	public static function c() {}
	final public function d() {}
	public function e( string &$s, ...$rest ): ?string { return null; }
}
$fake_parents = [ 'T78_Final' => null, 'T78_Abstract' => null, 'T78_Ctor' => null, 'T78_NoCtor' => null, 'T78_Missing_Class' => null ];
$bad_parents  = $BR::parent_problems( $fake_parents );
$sigs = [
	'T78_Methods::a' => 'protected(*)',                       // matches
	'T78_Methods::b' => 'public(?array=):array',              // matches
	'T78_Methods::c' => 'public static()',                    // matches
	'T78_Methods::d' => 'public final()',                     // matches
	'T78_Methods::e' => 'public(&string,...*=):?string',       // matches
];
$match_ok = $BR::signature_mismatches( $sigs ) === [];
$mismatch = $BR::signature_mismatches( [
	'T78_Methods::a' => 'protected()',                        // parameter count changed
	'T78_Methods::b' => 'public(array=):array',               // nullable dropped
	'T78_Methods::c' => 'public()',                           // became static
	'T78_Methods::d' => 'public()',                           // became final
	'T78_Methods::a2' => 'protected()',                       // method gone
	'T78_Missing_Class::x' => 'public()',                     // class gone
] );
$p3_env_bad = [ 'constant' => null, 'native' => true, 'ours' => true, 'missing' => array_merge( $bad_parents, $mismatch ) ];
check( 'T78b signature guard: matching signatures pass; changed parameters / nullability / static / final / missing method or class, and a final, abstract, re-constructed or missing parent are all reported (no fatal), and a report means P3 OFF',
	$match_ok && count( $mismatch ) === 6 && count( $bad_parents ) === 4 && ! preg_grep( '/T78_NoCtor/', $bad_parents )
	&& strpos( $BR::check_dashboard( $p3_env_bad ), 'missing:' ) === 0 );

// T78c against the REAL installed plugin: every parent and every method we override has exactly the signature we wrote against
$real_problems = array_merge( $BR::parent_problems( $BR::DASHBOARD_PARENTS ), $BR::signature_mismatches( $BR::DASHBOARD_SIGNATURES ) );
if ( $real_problems ) { echo '  ' . implode( "\n  ", $real_problems ) . "\n"; }
$p3_rm = new ReflectionMethod( $BR, 'dashboard_missing' );
$p3_rm->setAccessible( true );
$p3_reason = $BR::dashboard_reason(); // loads class-native-dashboard.php once the guard passes
$p3_classes_ok = true;
foreach ( $BR::DASHBOARD_CLASSES as $c ) { $p3_classes_ok = $p3_classes_ok && class_exists( $c, false ); }
check( 'T78c real WP Mail SMTP ' . WPMS_PLUGIN_VER . ': no Dashboard seam moved (parents extensible, 16 overridden/called methods match), dashboard_missing() empty, all six P3 classes loaded',
	$real_problems === [] && $p3_rm->invoke( null ) === [] && count( $BR::DASHBOARD_SIGNATURES ) === 16 && $p3_reason === '' && $p3_classes_ok );

// T79 ON/OFF end to end: layered on the bridge, with its own kill switch that leaves P0-P2 alone
$BR::reset();
$on = $BR::dashboard_active() && $BR::active();
add_filter( 'mailroom_native_ui', $FORCE_OFF, 9999 ); // bridge OFF
$BR::reset();
$bridge_off = ! $BR::dashboard_active() && $BR::dashboard_reason() === 'native-off';
add_filter( 'mailroom_native_dashboard', $P3_ON, 10 ); // P3 can never be switched ON by its own filter
$cannot_force_on = ! $BR::dashboard_active();
remove_filter( 'mailroom_native_dashboard', $P3_ON, 10 );
remove_filter( 'mailroom_native_ui', $FORCE_OFF, 9999 );
$BR::reset();
add_filter( 'mailroom_native_dashboard', $P3_OFF, 10 ); // P3 OFF only
$p3_only_off = ! $BR::dashboard_active() && $BR::dashboard_reason() === 'kill-switch-filter' && $BR::active();
$pg79 = apply_filters( 'wp_mail_smtp_admin_get_pages', [ 'settings' => 'S', 'logs' => 'L', 'alerts' => 'A', 'get-pro' => 'P' ] );
$p1_still = $pg79['logs'] instanceof \WPMailSMTP\Admin\PageAbstract && $pg79['alerts'] instanceof \WPMailSMTP\Admin\PageAbstract && ! isset( $pg79['get-pro'] )
	&& apply_filters( 'wp_mail_smtp_admin_display_get_logs_fqcn', 'Lite\Logs' ) === 'Mailroom_Native_Logs'
	&& apply_filters( 'wp_mail_smtp_admin_page_reports_tabs', [ 'reports' => 'X' ] )['reports'] === 'Mailroom_Native_Overview_Tab';
remove_filter( 'mailroom_native_dashboard', $P3_OFF, 10 );
$BR::reset();
check( 'T79 P3 ON with native ON; bridge OFF → P3 OFF (and its own filter cannot force it on); P3 filter false → only P3 OFF, native integration and the P1/P2 registry swaps keep working; back ON after',
	$on && $bridge_off && $cannot_force_on && $p3_only_off && $p1_still && $BR::dashboard_active() );

// T80 the Dashboard orchestrator through the REAL filter. P3 acts only on the Dashboard screen (admin.php?page=wp-mail-smtp-dashboard):
// the admin Area is built for every admin-ajax.php / admin-post.php request too, and those pay nothing for P3.
$saved_pagenow = $GLOBALS['pagenow'] ?? null;
$GLOBALS['pagenow'] = 'admin.php';
$_GET = [ 'page' => \WPMailSMTP\Admin\Dashboard\Page::SLUG ];
$lite_dash = new ( $DNS . 'Dashboard' )();
$swapped   = apply_filters( 'wp_mail_smtp_admin_area_get_dashboard', $lite_dash );
$foreign   = new class() extends \WPMailSMTP\Admin\Dashboard\Dashboard {};
$rm_stats  = new ReflectionMethod( 'Mailroom_Native_Dashboard', 'get_stats' ); // the override (a reflection on the parent would call the parent)
$rm_stats->setAccessible( true );
$stats_obj = $rm_stats->invoke( $swapped );
add_filter( 'mailroom_native_dashboard', $P3_OFF, 10 );
$BR::reset();
$when_off = apply_filters( 'wp_mail_smtp_admin_area_get_dashboard', $lite_dash );
remove_filter( 'mailroom_native_dashboard', $P3_OFF, 10 );
add_filter( 'mailroom_native_ui', $FORCE_OFF, 9999 );
$BR::reset();
$when_native_off = apply_filters( 'wp_mail_smtp_admin_area_get_dashboard', $lite_dash );
remove_filter( 'mailroom_native_ui', $FORCE_OFF, 9999 );
$BR::reset();
$_GET = [ 'page' => 'wp-mail-smtp-tools' ];
$other_page = apply_filters( 'wp_mail_smtp_admin_area_get_dashboard', $lite_dash );
$_GET = [ 'page' => \WPMailSMTP\Admin\Dashboard\Page::SLUG ];
$GLOBALS['pagenow'] = 'admin-ajax.php';
$ajax_req = apply_filters( 'wp_mail_smtp_admin_area_get_dashboard', $lite_dash );
$GLOBALS['pagenow'] = 'admin.php';
check( 'T80 the real area_get_dashboard filter returns our Dashboard (whose Stats is ours) only when P3 is ON; P3 off / native off / a foreign subclass / a non-object come back untouched',
	$swapped instanceof Mailroom_Native_Dashboard && $swapped instanceof \WPMailSMTP\Admin\Dashboard\Dashboard && $stats_obj instanceof Mailroom_Native_Stats
	&& $when_off === $lite_dash && $when_native_off === $lite_dash && apply_filters( 'wp_mail_smtp_admin_area_get_dashboard', $foreign ) === $foreign
	&& apply_filters( 'wp_mail_smtp_admin_area_get_dashboard', null ) === null && apply_filters( 'wp_mail_smtp_admin_area_get_dashboard', 'x' ) === 'x'
	&& $other_page === $lite_dash && $ajax_req === $lite_dash );
$_GET = [];

// T81 the statistics are exact for every one of the 12 weekly periods, and nothing outside the window leaks in
$wpdb->query( "TRUNCATE TABLE {$T}" );
$DD::flush();
$W_SENT = $W_FAIL = $W_BACKUP = $W_QUEUED = [];
for ( $n = 0; $n < 12; $n++ ) {
	$W_SENT[ $n ]   = $n + 1;
	$W_FAIL[ $n ]   = $n % 3;
	$W_BACKUP[ $n ] = $n === 1 ? 1 : ( $n === 5 ? 2 : 0 );
	$W_QUEUED[ $n ] = $n === 1 ? 3 : ( $n === 4 ? 1 : 0 );
	for ( $i = 0; $i < $W_SENT[ $n ] - $W_BACKUP[ $n ]; $i++ ) { $ins( 'sent', $at( $n, 1, sprintf( '10:%02d:00', $i ) ) ); }
	for ( $i = 0; $i < $W_BACKUP[ $n ]; $i++ ) { $ins( 'sent_backup', $at( $n, 2, sprintf( '10:%02d:00', $i ) ) ); }
	for ( $i = 0; $i < $W_FAIL[ $n ]; $i++ ) { $ins( 'failed', $at( $n, 3, sprintf( '10:%02d:00', $i ) ) ); }
	for ( $i = 0; $i < $W_QUEUED[ $n ]; $i++ ) { $ins( 'queued', $at( $n, 4, sprintf( '10:%02d:00', $i ) ) ); }
}
$ins( 'failed_backup', $at( 1, 5 ) );                       // the backup's attempt at a failed email: not a second failure
for ( $i = 0; $i < 9; $i++ ) { $ins( 'sent', $at( 12, 1 ) ); } // week 13 back: outside the 12-week window
$ins( 'failed', $at( 13, 1 ) );
$GLOBALS['t_q'] = [];
$qcap = function ( $q ) { $GLOBALS['t_q'][] = (string) $q; return $q; };
add_filter( 'query', $qcap, 1 );
$st = new Mailroom_Native_Stats();
$series = $st->get_weekly_series();
$totals = $st->get_stat_totals();
$deltas = $st->get_stat_deltas();
$chart  = $st->get_series();
$prompt = [ $st->get_recent_failed_count(), $st->has_high_failure_rate() ];
$wk_keys_ok = true;
for ( $i = 0; $i < 12; $i++ ) {
	$back = 11 - $i;
	$wk_keys_ok = $wk_keys_ok && $series[ $i ]['week'] === (int) $DD::week_start( $back )->format( 'W' ) && $series[ $i ]['sent'] === $W_SENT[ $back ] && $series[ $i ]['failed'] === $W_FAIL[ $back ];
}
$delta = function ( $last, $prev ) { return $prev === 0 ? null : round( ( $last - $prev ) / $prev * 100, 1 ); };
$exp_deltas = [ 'sent' => $delta( $W_SENT[1], $W_SENT[2] ), 'failed' => $delta( $W_FAIL[1], $W_FAIL[2] ), 'backup' => $delta( $W_BACKUP[1], $W_BACKUP[2] ), 'queued' => $delta( $W_QUEUED[1], $W_QUEUED[2] ) ];
$recent_failed = array_sum( array_slice( $W_FAIL, 0, 5 ) );
$recent_sent   = array_sum( array_slice( $W_SENT, 0, 5 ) );
$weekly_raw = $DD::weekly();
$sel_queries = array_values( array_filter( $GLOBALS['t_q'], function ( $q ) use ( $T ) { return stripos( ltrim( $q ), 'SELECT' ) === 0 && strpos( $q, $T ) !== false; } ) );
check( 'T81 weekly series: sent / failed exact for each of the 12 ISO weeks (backup deliveries count as sent, failed_backup does not count as failed), weeks 13+ back excluded',
	$wk_keys_ok && count( $series ) === 12 && count( $weekly_raw['sent'] ) === 12 && count( $weekly_raw['failed'] ) === 8 );
check( 'T81b last complete week totals (sent, failed, via backup, queued) and the percent changes against the week before are exact; zero previous week → no percent',
	$totals === [ 'sent' => $W_SENT[1], 'failed' => $W_FAIL[1], 'backup' => 1, 'queued' => 3 ] && $deltas === $exp_deltas && $deltas['backup'] === null && $deltas['queued'] === null
	&& $totals['sent'] === 2 && $totals['failed'] === 1 );
check( 'T81c the failed-email prompts use the same counters: recent (5-week) failed count and the high-failure-rate flag are exact',
	$prompt[0] === $recent_failed && $prompt[1] === ( $recent_failed / ( $recent_failed + $recent_sent ) * 100 >= 20 ) && $prompt[1] === true );
check( 'T81d the chart series has 12 points (the log reaches back over the whole window) carrying the same sent / failed numbers',
	count( $chart ) === 12 && $chart[11]['sent'] === $W_SENT[0] && $chart[0]['sent'] === $W_SENT[11] && $chart[10]['failed'] === $W_FAIL[1] );
check( 'T81e cheap: the whole stats build is ONE aggregate SELECT plus one MIN(created_at), never a row scan; repeated calls hit the request memo',
	count( $sel_queries ) === 2 && preg_match( '/WEEK\(created_at, 3\).*GROUP BY/s', $sel_queries[0] ) === 1 && stripos( $sel_queries[1], 'MIN(created_at)' ) !== false );
// Overview parity: the Overview's seven-day sent/failed use the same definitions as the weekly counters
$ov = Mailroom_Reports::stats( 7 );
$sent7 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$T} WHERE status IN ('sent','sent_backup') AND created_at >= '" . gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ) . "'" );
check( 'T81f definitions agree with the Overview (Reports::stats): sent = sent + sent_backup, failed = primary failures only', $ov['sent'] === $sent7 );

// T81g retention: a log that starts mid-history is not drawn as zeros, and no percent is claimed over a week it cannot see
$wpdb->query( "TRUNCATE TABLE {$T}" );
$DD::flush();
$ins( 'sent', $at( 1, 2 ) );
$ins( 'sent', $at( 1, 3 ) );
$ins( 'failed', $at( 0, 0, '00:01:00' ) );
$st2 = new Mailroom_Native_Stats();
$chart2 = $st2->get_series();
$series2 = $st2->get_weekly_series();
check( 'T81g log starting last week: chart shows only the 2 weeks that have data (10 leading pruned/empty weeks dropped), the 12-week counters still add up, and percent changes are null (earlier week not covered)',
	$DD::leading_weeks_without_log() === 10 && count( $chart2 ) === 2 && $chart2[0]['sent'] === 2 && $chart2[1]['failed'] === 1 && count( $series2 ) === 12
	&& $st2->get_delta( 'sent' ) === null && array_filter( $st2->get_stat_deltas() ) === [] && ! $DD::covers_week( 2 ) && ! $DD::covers_week( 1 ) && $DD::covers_week( 0 ) );
$wpdb->query( "TRUNCATE TABLE {$T}" );
$DD::flush();
$st3 = new Mailroom_Native_Stats();
check( 'T81h empty log: 12 zero weeks, zero totals, no percent, nothing dropped',
	count( $st3->get_series() ) === 12 && $st3->get_stat_totals() === [ 'sent' => 0, 'failed' => 0, 'backup' => 0, 'queued' => 0 ] && array_filter( $st3->get_stat_deltas() ) === [] && ! $DD::has_rows() );

// T82 the Email Log widget: documented row shape, newest first, links to the native single-email view, escaping
$ids = [];
$ids['old']    = $ins( 'sent', $at( 0, 0, '08:00:00' ), [ 'subject' => 'Old one', 'to_email' => 'old@example.com' ] );
$ids['bak']    = $ins( 'sent_backup', $at( 0, 0, '09:00:00' ), [ 'subject' => 'Via backup', 'source' => 'backup', 'mailer' => 'smtp' ] );
$ids['fail']   = $ins( 'failed', $at( 0, 0, '10:00:00' ), [ 'subject' => 'Failed one', 'mailer' => 'gmail' ] );
$ids['queued'] = $ins( 'queued', $at( 0, 0, '11:00:00' ), [ 'subject' => 'Queued one', 'source' => 'queue', 'mailer' => '' ] );
$ids['xss']    = $ins( 'sent', $at( 0, 0, '12:00:00' ), [ 'subject' => '<script>alert(1)</script> & "q"', 'to_email' => '"><img src=x onerror=alert(2)>@example.com', 'mailer' => '<i>bad</i>' ] );
$ids['nosub']  = $ins( 'sent', $at( 0, 0, '13:00:00' ), [ 'subject' => '' ] );
$DD::flush();
$rows = $DD::email_log_rows();
$shape = [ 'subject', 'subject_attr', 'recipient', 'mailer', 'date_sent', 'status', 'status_label', 'edit_url', 'preview_url' ];
$shape_ok = count( $rows ) === 5;
foreach ( $rows as $r ) { $shape_ok = $shape_ok && array_keys( $r ) === $shape && in_array( $r['status'], [ 'sent', 'waiting', 'failed' ], true ) && is_string( $r['date_sent'] ) && $r['date_sent'] !== ''; }
$map_ok = $rows[0]['status'] === 'sent' && $rows[0]['subject'] === '(no subject)' && $rows[1]['subject'] === '<script>alert(1)</script> & "q"'
	&& $rows[2]['status'] === 'waiting' && $rows[2]['status_label'] === 'Queued' && $rows[2]['mailer'] === '—'
	&& $rows[3]['status'] === 'failed' && $rows[3]['status_label'] === 'Failed' && $rows[3]['mailer'] === 'gmail'
	&& $rows[4]['status'] === 'sent' && $rows[4]['status_label'] === 'Sent via backup' && $rows[4]['mailer'] === 'Backup SMTP';
$links_ok = true;
foreach ( [ $ids['nosub'], $ids['xss'], $ids['queued'], $ids['fail'], $ids['bak'] ] as $i => $id ) {
	$links_ok = $links_ok && $rows[ $i ]['edit_url'] === $BR::url( 'log', [ 'view' => $id ] ) && strpos( $rows[ $i ]['edit_url'], 'page=wp-mail-smtp-logs' ) !== false && strpos( $rows[ $i ]['edit_url'], 'view=' . $id ) !== false && $rows[ $i ]['preview_url'] === '';
}
check( 'T82 Email Log rows: the 5 newest, newest first, exactly the documented keys, status mapped (sent_backup → sent, queued → waiting, failed), backup rows labelled, empty subject honest', $shape_ok && $map_ok );
check( 'T82b each row links to the native single-email view (Bridge::url(\'log\', [view => id])) and offers no Thickbox preview', $links_ok );

$wl = new Mailroom_Native_Email_Log( $access );
$wl_state = $wl->get_state();
$wl_html  = $wl->render( $wl_state->get_variant(), [] );
check( 'T82c rendered through the real widget class + the plugin\'s template: data variant, table rows with our links, no upgrade / Pro wording, HTML escaped (no raw <script>, <img>, <i>)',
	$wl_state->is_visible() && $wl_state->get_variant() === 'data' && strpos( $wl_html, 'data-widget="email_log"' ) !== false && substr_count( $wl_html, 'view=' ) >= 5
	&& strpos( $wl_html, 'view=' . $ids['xss'] ) !== false && strpos( $wl_html, '&lt;script&gt;alert(1)&lt;/script&gt;' ) !== false
	&& strpos( $wl_html, '<script>alert' ) === false && strpos( $wl_html, '<img src=x' ) === false && strpos( $wl_html, '<i>bad</i>' ) === false
	&& ! preg_match( '/upgrade|\bpro\b/i', $wl_html ) && strpos( $wl_html, 'Failed one' ) !== false );
wp_set_current_user( 0 );
$wl_anon = $wl->render( 'data', [] );
wp_set_current_user( $uid );
check( 'T82d the widget body prints nothing for a user without the capability (the page itself is capability-gated by WP Mail SMTP; this is the second lock)', strpos( $wl_anon, 'Failed one' ) === false && strpos( $wl_anon, '<table' ) === false );
$wpdb->query( "TRUNCATE TABLE {$T}" );
$DD::flush();
$wl_empty = new Mailroom_Native_Email_Log( $access );
$wl_empty_html = $wl_empty->render( $wl_empty->get_state()->get_variant(), [] );
check( 'T82e empty log: honest "No emails logged yet" state, no table, no upgrade prompt, no teaser figures',
	$wl_empty->get_state()->get_variant() === 'connect' && strpos( $wl_empty_html, 'No emails logged yet' ) !== false && strpos( $wl_empty_html, '<table' ) === false && ! preg_match( '/upgrade|\bpro\b/i', $wl_empty_html ) );

// T83 Email Sources: we do not record which plugin sent an email, so the widget says so
$es = new Mailroom_Native_Email_Sources( $access );
$es_html = $es->render( $es->get_state()->get_variant(), [] );
check( 'T83 Email Sources: honest "not tracked" state (no chart, no donut config, no invented breakdown, no upgrade prompt), links to the log',
	$es->get_state()->is_visible() && strpos( $es_html, 'data-widget="email_sources"' ) !== false && strpos( $es_html, 'Not tracked' ) !== false && strpos( $es_html, 'data-config' ) === false
	&& strpos( $es_html, '<canvas' ) === false && ! preg_match( '/upgrade|\bpro\b/i', $es_html ) && strpos( $es_html, 'page=wp-mail-smtp-logs' ) !== false );

// T84 stat cards: the two locked placeholder cards become real figures from our log; the Lite class is unchanged
$wpdb->query( "TRUNCATE TABLE {$T}" );
$DD::flush();
$ins( 'sent', $at( 1, 1 ) ); $ins( 'sent', $at( 1, 1, '13:00:00' ) ); $ins( 'sent_backup', $at( 1, 2 ) ); $ins( 'failed', $at( 1, 3 ) ); $ins( 'failed', $at( 1, 3, '13:00:00' ) ); $ins( 'failed', $at( 1, 3, '14:00:00' ) );
$ins( 'queued', $at( 1, 4 ) ); $ins( 'queued', $at( 1, 4, '13:00:00' ) );
$ins( 'sent', $at( 2, 1 ) ); $ins( 'sent', $at( 2, 1, '13:00:00' ) ); $ins( 'queued', $at( 2, 4 ) );
$ins( 'sent', $at( 3, 1 ) ); // an older row, so the log covers the week the percent changes compare against
$DD::flush();
$st4 = new Mailroom_Native_Stats();
$data4 = [ 'stat_totals' => $st4->get_stat_totals(), 'stat_deltas' => $st4->get_stat_deltas(), 'chart_series' => $st4->get_series(), 'has_high_failure_rate' => $st4->has_high_failure_rate(), 'recent_failed_count' => $st4->get_recent_failed_count(), 'date_range' => null ];
$cards  = ( new Mailroom_Native_Stat_Cards( $access ) )->get_cards( $data4 );
$lite_cards = ( new \WPMailSMTP\Admin\Dashboard\Widgets\StatCards( $access ) )->get_cards( $data4 );
$cards_html = ( new Mailroom_Native_Stat_Cards( $access ) )->render( 'data', $data4 );
check( 'T84 stat cards: [emails, failed, via backup, queued] = [3, 3, 1, 2] for the last complete week, none locked; WP Mail SMTP\'s own class still shows its locked placeholders (so the swap is what removes them)',
	array_column( $cards, 'id' ) === [ 'emails', 'failed', 'backup', 'queued' ] && array_column( $cards, 'value' ) === [ 3, 3, 1, 2 ] && array_filter( array_column( $cards, 'locked' ) ) === []
	&& $cards[0]['delta'] === 50.0 && $cards[2]['delta'] === null && $cards[3]['delta'] === 100.0 && array_column( $lite_cards, 'locked' ) === [ false, false, true, true ]
	&& strpos( $cards_html, 'Upgrade to Unlock' ) === false && strpos( $cards_html, 'data-card="backup"' ) !== false && strpos( $cards_html, 'data-card="queued"' ) !== false && ! preg_match( '/\bpro\b/i', $cards_html ) );

// T85 emails overview: its has-data check reads OUR counters (Lite's own Stats would hide the chart), and the alerts prompt points at our Alerts tab
$eo = new Mailroom_Native_Emails_Overview( $access );
$eo_data_state = $eo->get_state()->get_variant();
$wpdb->query( "TRUNCATE TABLE {$T}" );
$DD::flush();
$eo_empty_state = ( new Mailroom_Native_Emails_Overview( $access ) )->get_state()->get_variant();
$ins( 'sent', $at( 1, 1 ) ); $ins( 'failed', $at( 1, 2 ) ); $ins( 'failed', $at( 1, 3 ) ); $ins( 'failed', $at( 1, 4 ) );
$DD::flush();
$st5 = new Mailroom_Native_Stats();
$data5 = [ 'stat_totals' => $st5->get_stat_totals(), 'stat_deltas' => $st5->get_stat_deltas(), 'chart_series' => $st5->get_series(), 'has_high_failure_rate' => $st5->has_high_failure_rate(), 'recent_failed_count' => $st5->get_recent_failed_count(), 'date_range' => null ];
$eo5 = new Mailroom_Native_Emails_Overview( $access );
$eo_html = $eo5->render( $eo5->get_state()->get_variant(), $data5 );
test_opts( array_merge( Mailroom::defaults(), [ 'alert_email' => 'ops@example.com' ] ) );
$eo_html_alerts = $eo5->render( 'data', $data5 );
test_opts( [] );
check( 'T85 emails overview: "data" only when our log has data ("connect" when empty); with a high failure rate and no alert channel it prompts "Set Up Alerts" → our Alerts tab (3 failed in the last 5 weeks, no upgrade wording); the prompt disappears once a channel is configured',
	$eo_data_state === 'data' && $eo_empty_state === 'connect' && $data5['has_high_failure_rate'] === true && $data5['recent_failed_count'] === 3
	&& strpos( $eo_html, 'Set Up Alerts' ) !== false && strpos( $eo_html, 'Your Site Had 3 Failed Emails in the Last 5 Weeks' ) !== false && strpos( $eo_html, 'tab=alerts' ) !== false && ! preg_match( '/upgrade|\bpro\b/i', $eo_html )
	&& strpos( $eo_html_alerts, 'Set Up Alerts' ) === false );

// T86 the real widget pipeline filter: exactly the four widgets we feed are swapped (exact class match), the rest untouched, order kept
$lite_w = [
	new ( $DNS . 'Widgets\\Version' )( $access ), new ( $DNS . 'Widgets\\StatCards' )( $access ), new ( $DNS . 'Widgets\\EmailsOverview' )( $access ),
	new ( $DNS . 'Widgets\\EmailLog' )( $access ), new ( $DNS . 'Widgets\\EmailSources' )( $access ), new ( $DNS . 'Widgets\\FeaturesUpsell' )( $access ),
];
$sw = apply_filters( 'wp_mail_smtp_admin_dashboard_widget_pipeline_get_widgets', $lite_w, $access );
$sw_ok = count( $sw ) === 6 && $sw[0] === $lite_w[0] && $sw[5] === $lite_w[5]
	&& get_class( $sw[1] ) === 'Mailroom_Native_Stat_Cards' && get_class( $sw[2] ) === 'Mailroom_Native_Emails_Overview'
	&& get_class( $sw[3] ) === 'Mailroom_Native_Email_Log' && get_class( $sw[4] ) === 'Mailroom_Native_Email_Sources'
	&& $sw[3]->get_id() === 'email_log' && $sw[4]->get_id() === 'email_sources' && $sw[1]->get_id() === 'stat_cards' && $sw[2]->get_id() === 'emails_overview';
$custom = new class( $access ) extends \WPMailSMTP\Admin\Dashboard\Widgets\EmailLog {}; // someone else's subclass is left alone
$keep   = apply_filters( 'wp_mail_smtp_admin_dashboard_widget_pipeline_get_widgets', [ $custom ], $access );
add_filter( 'mailroom_native_dashboard', $P3_OFF, 10 );
$BR::reset();
$sw_off = apply_filters( 'wp_mail_smtp_admin_dashboard_widget_pipeline_get_widgets', $lite_w, $access );
remove_filter( 'mailroom_native_dashboard', $P3_OFF, 10 );
$BR::reset();
check( 'T86 widget pipeline filter (real hook): StatCards / EmailsOverview / EmailLog / EmailSources swapped for ours (same ids, same positions), every other widget and any foreign subclass untouched; P3 off → list returned as is',
	$sw_ok && $keep === [ $custom ] && $sw_off === $lite_w );

// T86b the whole WidgetPipeline, run for real (init evaluates every widget's state, render_column renders them): no exception, our widgets are the ones rendered
try {
	$pipe = new ( $DNS . 'WidgetPipeline' )();
	$pipe->init( $access );
	$cols = $pipe->render_column( 'main', $data5 ) . $pipe->render_column( 'top', $data5 ) . $pipe->render_column( 'sidebar', $data5 );
	$pipe_ok = strpos( $cols, 'data-widget="email_log"' ) !== false && strpos( $cols, 'data-widget="email_sources"' ) !== false && strpos( $cols, 'Not tracked' ) !== false
		&& strpos( $cols, 'data-card="backup"' ) !== false && strpos( $cols, 'Upgrade to Pro to unlock email logging' ) === false && strpos( $cols, 'See Which Plugins Send Emails' ) === false;
} catch ( \Throwable $e ) {
	$pipe_ok = false;
	echo '  pipeline exception: ' . $e->getMessage() . "\n";
}
check( 'T86b the full WP Mail SMTP WidgetPipeline renders with our widgets in place: no exception, our Email Log / Email Sources / stat cards present, the Email Log and Email Sources upgrade teasers gone', $pipe_ok );

// T87 failure handling: a failing query never reaches the page as an exception; stats fall back to WP Mail SMTP's own counters, widgets show a notice or hide
$bad_table = function () { return $GLOBALS['wpdb']->prefix . 'mailroom_log_does_not_exist'; };
add_filter( 'mailroom_table', $bad_table, 20 );
$DD::flush();
$wpdb->suppress_errors( true );
$st6 = new Mailroom_Native_Stats();
$lite_st = new \WPMailSMTP\Admin\Dashboard\Stats();
$fell_back = $st6->get_weekly_series() === $lite_st->get_weekly_series();
$threw = false;
try { $DD::weekly(); } catch ( \RuntimeException $e ) { $threw = true; }
$w_err = new Mailroom_Native_Email_Log( $access );
$err_state = $w_err->get_state();
$err_body  = $w_err->render( 'data', [] );
$wpdb->suppress_errors( false );
remove_filter( 'mailroom_table', $bad_table, 20 );
$DD::flush();
check( 'T87 broken log table: Dashdata throws internally, the Stats subclass falls back to the plugin\'s own counters, the Email Log widget hides itself / prints a notice (no exception, no fatal)',
	$threw && $fell_back && ! $err_state->is_visible() && strpos( $err_body, 'could not be displayed' ) !== false );

// T88 callbacks never throw on garbage input and hand it back untouched
$junk_ok = true;
foreach ( [ null, 0, 'x', [], [ 1 ], new stdClass() ] as $junk ) {
	$junk_ok = $junk_ok && apply_filters( 'wp_mail_smtp_admin_area_get_dashboard', $junk ) == $junk
		&& apply_filters( 'wp_mail_smtp_admin_dashboard_widget_pipeline_get_widgets', $junk, $access ) == $junk
		&& apply_filters( 'wp_mail_smtp_admin_dashboard_widget_pipeline_get_widgets', [ $lite_w[0] ], $junk ) == [ $lite_w[0] ];
}
check( 'T88 dashboard filter callbacks tolerate null / scalars / empty and odd arrays / a missing access context without throwing and return their input', $junk_ok );

// T89 the wp-admin index widget: ONE widget with ONE set of numbers. Ours stays (same data as the Overview); WP Mail SMTP's own is switched off through its documented switch while P3 is ON.
$src = (string) file_get_contents( WP_PLUGIN_DIR . '/wp-mail-smtp/src/Admin/DashboardWidget.php' );
$gated = preg_match( "/if \( ! apply_filters\( 'wp_mail_smtp_admin_dashboard_widget', '__return_true' \) \) \{\s*return;\s*\}\s*\\\$this->hooks\(\);/", $src ) === 1;
$GLOBALS['pagenow'] = 'index.php'; // the WordPress dashboard
$lite_off = apply_filters( 'wp_mail_smtp_admin_dashboard_widget', '__return_true' ) === false;
add_filter( 'mailroom_native_dashboard', $P3_OFF, 10 );
$BR::reset();
$lite_when_p3_off = apply_filters( 'wp_mail_smtp_admin_dashboard_widget', '__return_true' ) === '__return_true' && apply_filters( 'wp_mail_smtp_admin_dashboard_widget', true ) === true;
remove_filter( 'mailroom_native_dashboard', $P3_OFF, 10 );
$BR::reset();
$GLOBALS['pagenow'] = 'admin-ajax.php'; // anywhere else the decision is not even evaluated
$lite_elsewhere = apply_filters( 'wp_mail_smtp_admin_dashboard_widget', '__return_true' ) === '__return_true';
if ( $saved_pagenow === null ) { unset( $GLOBALS['pagenow'] ); } else { $GLOBALS['pagenow'] = $saved_pagenow; }
check( 'T89 WP Mail SMTP\'s own wp-admin widget is gated by exactly this filter (hooks() only after it passes) and is switched off while P3 is ON, left alone when P3 is OFF',
	$gated && $lite_off && $lite_when_p3_off && $lite_elsewhere );

$wpdb->query( "TRUNCATE TABLE {$T}" );
$DD::flush();
$ins( 'sent', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3600 ) );
$ins( 'sent', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7200 ) );
$ins( 'sent_backup', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7300 ) );
$ins( 'failed', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3700 ) );
$ins( 'failed_backup', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3600 ) );
$ins( 'sent', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 9 * DAY_IN_SECONDS ) ); // outside seven days
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/dashboard.php'; // wp_add_dashboard_widget(): core loads it only from wp-admin/index.php
global $wp_meta_boxes;
$saved_screen = $GLOBALS['current_screen'] ?? null;
$saved_boxes  = $wp_meta_boxes;
set_current_screen( 'dashboard' );
$wp_meta_boxes = [];
Mailroom::instance()->module( 'admin' )->dashboard_widget();
$ours_registered = array_keys( (array) ( $wp_meta_boxes['dashboard']['normal']['core'] ?? [] ) );
ob_start();
Mailroom::instance()->module( 'admin' )->widget_body();
$wbody = ob_get_clean();
$wp_meta_boxes = $saved_boxes;
if ( $saved_screen === null ) { unset( $GLOBALS['current_screen'] ); } else { $GLOBALS['current_screen'] = $saved_screen; }
$ov7 = Mailroom_Reports::stats( 7 );
check( 'T89b our "Email delivery (last 7 days)" widget registers exactly once and shows the Overview\'s numbers (sent 3 incl. backup, failed 1, via backup 1; the backup\'s failed attempt is not a second failure)',
	$ours_registered === [ 'mailroom_report' ] && $ov7['sent'] === 3 && $ov7['failed'] === 1
	&& strpos( $wbody, 'Sent: <strong>' . $ov7['sent'] . '</strong>' ) !== false && preg_match( '/Failed: <strong[^>]*>' . $ov7['failed'] . '<\/strong>/', $wbody ) === 1 && strpos( $wbody, 'Delivered via backup: <strong>1</strong>' ) !== false );

// T90 hygiene: no SELECT *, never the heavy columns, read-only, nothing licence / Pro related
$GLOBALS['t_q'] = [];
$DD::flush();
$DD::weekly(); $DD::first_logged_at(); $DD::email_log_rows(); $DD::leading_weeks_without_log();
remove_filter( 'query', $qcap, 1 );
$all_q = implode( "\n", $GLOBALS['t_q'] );
$src_files = (string) @file_get_contents( WPMU_PLUGIN_DIR . '/mailroom/class-dashdata.php' ) . (string) @file_get_contents( WPMU_PLUGIN_DIR . '/mailroom/class-native-dashboard.php' );
check( 'T90 queries: every dashboard query is a SELECT with explicit columns (no SELECT *, no content / mime / body_preview / error), and the P3 code contains no write statement, no is_pro filter, no licence / usage-tracking / lite-counter option',
	$all_q !== '' && ! preg_match( '/SELECT\s+\*/i', $all_q ) && ! preg_match( '/\b(content|mime|body_preview)\b/i', $all_q ) && ! preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)/im', $all_q )
	&& $src_files !== '' && ! preg_match( '/SELECT\s+\*/i', $src_files ) && ! preg_match( '/wp_mail_smtp_core_is_pro|update_option|add_option|wp_mail_smtp_lite_|license|usage_tracking|\\\$wpdb->(insert|update|delete)/i', $src_files ) );

// T91–T98 upsells module: hide WP Mail SMTP's upgrade advertising through its own seams, with a kill switch.
// The core-booted instance registered nothing (boot() is admin-only and CLI is not admin), so boot a fresh one under
// an admin screen (is_admin() reads $current_screen) and remove exactly its callbacks afterwards.
$UP           = 'Mailroom_Upsells';
$saved_screen = $GLOBALS['current_screen'] ?? null;
set_current_screen( 'dashboard' );
( new $UP( Mailroom::instance() ) )->boot();
if ( $saved_screen === null ) { unset( $GLOBALS['current_screen'] ); } else { $GLOBALS['current_screen'] = $saved_screen; }
$UP_OFF = '__return_false';

$edu = new \WPMailSMTP\Admin\Education();
$edu->hooks();
$bar_hidden = has_action( 'admin_init', [ $edu, 'notice_bar_init' ] ) === false;
add_filter( $UP::FILTER, $UP_OFF );
$edu2 = new \WPMailSMTP\Admin\Education();
$edu2->hooks();
$bar_back = has_action( 'admin_init', [ $edu2, 'notice_bar_init' ] ) !== false;
remove_filter( $UP::FILTER, $UP_OFF );
remove_action( 'admin_init', [ $edu2, 'notice_bar_init' ] );
check( 'T91 notice bar: WP Mail SMTP\'s own Education::hooks() no longer registers the "You\'re using Lite" bar; the kill switch brings it back',
	$bar_hidden && $bar_back );

$pipe_classes = function () use ( $DNS, $access ) {
	$p = new ( $DNS . 'WidgetPipeline' )();
	$p->init( $access );
	$prop = new ReflectionProperty( $p, 'widgets' );
	$prop->setAccessible( true );
	$out = [];
	foreach ( (array) $prop->getValue( $p ) as $col ) {
		foreach ( $col as $item ) { $out[] = get_class( $item['widget'] ); }
	}
	return $out;
};
$w_on = $pipe_classes();
add_filter( $UP::FILTER, $UP_OFF );
$w_off = $pipe_classes();
remove_filter( $UP::FILTER, $UP_OFF );
$is_upsell = function ( $c ) { return substr( $c, -14 ) === 'FeaturesUpsell' || substr( $c, -11 ) === 'GrowthTools'; };
check( 'T92 Dashboard (real widget pipeline): the "Take Your Email Deliverability…" and Growth Tools boxes are gone, every other widget stays; with the kill switch the upsell box is back',
	$w_on !== [] && ! array_filter( $w_on, $is_upsell ) && in_array( $DNS . 'Widgets\\FeaturesUpsell', $w_off, true )
	&& array_values( array_diff( $w_off, $w_on ) ) === array_values( array_filter( $w_off, $is_upsell ) ) );

$pg = $UP::filter_pages( [ 'misc' => 1, 'get-pro' => 2 ] );
add_filter( $UP::FILTER, $UP_OFF );
$pg_off = $UP::filter_pages( [ 'misc' => 1, 'get-pro' => 2 ] );
remove_filter( $UP::FILTER, $UP_OFF );
check( 'T93 Settings "Get Pro" tab removed (other tabs kept), kept with the kill switch, and the callback is on WP Mail SMTP\'s pages filter',
	$pg === [ 'misc' => 1 ] && isset( $pg_off['get-pro'] ) && has_filter( 'wp_mail_smtp_admin_get_pages', [ $UP, 'filter_pages' ] ) === 20 );

global $submenu;
$saved_submenu = $submenu;
$up_slug       = esc_url( wp_mail_smtp()->get_upgrade_link( [ 'medium' => 'admin-menu', 'content' => 'Upgrade to Pro' ] ) );
$SL            = \WPMailSMTP\Admin\Area::SLUG;
$fake_menu     = [ [ 'Settings', 'manage_options', $SL ], [ 'Email Log', 'manage_options', $SL . '-logs' ], [ 'Upgrade to Pro', 'manage_options', $up_slug ] ];
$submenu       = [ $SL => $fake_menu ];
$UP::remove_upgrade_menu();
$menu_on = array_column( array_values( $submenu[ $SL ] ), 2 );
$submenu = [ $SL => $fake_menu ];
add_filter( $UP::FILTER, $UP_OFF );
$UP::remove_upgrade_menu();
$menu_off = array_column( array_values( $submenu[ $SL ] ), 2 );
remove_filter( $UP::FILTER, $UP_OFF );
$submenu = $saved_submenu;
check( 'T94 sidebar: the "Upgrade to Pro" item (slug = WP Mail SMTP\'s real upgrade link) is removed, the others stay; kept with the kill switch',
	$menu_on === [ $SL, $SL . '-logs' ] && $menu_off === [ $SL, $SL . '-logs', $up_slug ] && has_action( 'admin_menu', [ $UP, 'remove_upgrade_menu' ] ) === 999 );

// WP Mail SMTP's real link builder (Area's constructor is empty; hooks() is not called), then the same hook chain.
$links_hook = 'plugin_action_links_' . plugin_basename( WPMS_PLUGIN_FILE );
$wpms_links = ( new \WPMailSMTP\Admin\Area() )->add_plugin_action_link( [ 'deactivate' => 'x' ] );
$links      = apply_filters( $links_hook, $wpms_links );
add_filter( $UP::FILTER, $UP_OFF );
$links_off = apply_filters( $links_hook, $wpms_links );
remove_filter( $UP::FILTER, $UP_OFF );
check( 'T95 Plugins screen (WP Mail SMTP\'s real action-link filter): "Get WP Mail SMTP Pro" gone, its Settings / Docs links and Deactivate stay; back with the kill switch',
	isset( $wpms_links['wp-mail-smtp-pro'] ) && has_filter( $links_hook, [ $UP, 'filter_action_links' ] ) === 20
	&& ! isset( $links['wp-mail-smtp-pro'] ) && isset( $links['wp-mail-smtp-settings'], $links['wp-mail-smtp-docs'], $links['deactivate'] ) && isset( $links_off['wp-mail-smtp-pro'] ) );

$banner = function () {
	$m = new ReflectionMethod( '\WPMailSMTP\Admin\Pages\SettingsTab', 'display_pro_banner' );
	$m->setAccessible( true );
	ob_start();
	$m->invoke( new \WPMailSMTP\Admin\Pages\SettingsTab() );
	return ob_get_clean();
};
$raw_meta  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", get_current_user_id(), $UP::PRO_BANNER_META ) );
$b_on      = $banner();
add_filter( $UP::FILTER, $UP_OFF );
$b_off = $banner();
remove_filter( $UP::FILTER, $UP_OFF );
$raw_after = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", get_current_user_id(), $UP::PRO_BANNER_META ) );
check( 'T96 Settings Pro banner (WP Mail SMTP\'s real display_pro_banner()): not rendered, because its dismissed flag reads as set; nothing is written to usermeta; it renders with the kill switch (unless this admin really dismissed it)',
	is_super_admin() && trim( $b_on ) === '' && $raw_meta === $raw_after
	&& ( (int) $raw_meta > 0 || strpos( $b_off, 'wp-mail-smtp-pro-banner' ) !== false )
	&& get_user_meta( get_current_user_id(), 'some_other_key_' . wp_generate_password( 6, false ), true ) === '' );

define( 'MAILROOM_HIDE_UPSELLS', false );
check( 'T97 constant MAILROOM_HIDE_UPSELLS = false turns every removal off (defined last: constants cannot be undefined)',
	! $UP::enabled() && $UP::filter_notice_bar( true ) === true && isset( $UP::filter_pages( [ 'get-pro' => 1 ] )['get-pro'] )
	&& $UP::filter_pro_banner_meta( null, get_current_user_id(), $UP::PRO_BANNER_META ) === null );

$up_src = (string) @file_get_contents( WPMU_PLUGIN_DIR . '/mailroom/class-upsells.php' );
check( 'T98 hygiene: the upsells module writes nothing and does not touch Pro detection, licence, upgrade URLs or usage tracking',
	$up_src !== '' && ! preg_match( '/wp_mail_smtp_core_is_pro|get_upgrade_link|update_(user_meta|option)|add_(user_meta|option)|delete_(user_meta|option)|license|licence_key|usage_tracking|<style|\$wpdb/i', $up_src ) );

foreach ( [
	[ 'wp_mail_smtp_admin_education_notice_bar', 'filter_notice_bar', 10 ],
	[ 'wp_mail_smtp_admin_dashboard_widget_pipeline_get_widgets', 'filter_widgets', 20 ],
	[ 'wp_mail_smtp_admin_get_pages', 'filter_pages', 20 ],
	[ 'admin_menu', 'remove_upgrade_menu', 999 ],
	[ $links_hook, 'filter_action_links', 20 ],
	[ 'get_user_metadata', 'filter_pro_banner_meta', 10 ],
] as [ $hook, $cb, $prio ] ) {
	remove_filter( $hook, [ $UP, $cb ], $prio );
}

$wpdb->query( "TRUNCATE TABLE {$T}" );
$_GET = [];

// reset: restore shared transients, drop the test table, prove the live data was untouched
foreach ( $TRANSIENTS as $k => $v ) {
	$v === false ? delete_transient( $k ) : set_transient( $k, $v, 600 );
}
$wpdb->query( "DROP TABLE IF EXISTS {$T}" );
$wpdb->query( "DROP TABLE IF EXISTS {$QT}" );
delete_transient( 'mailroom_drain_lock' );
// Re-sync crons with the LIVE settings and LIVE queue table (tests toggled them under in-memory settings).
remove_all_filters( 'pre_option_' . Mailroom::OPT );
remove_all_filters( 'mailroom_queue_table' );
Mailroom::instance()->module( 'reports' )->sync_schedule();
Mailroom::instance()->module( 'ratelimit' )->sync_schedule();
check( 'T0b no test reached a real mailer or the network (guards blocked nothing)', $GLOBALS['blocked_mail'] === [] && $GLOBALS['blocked_http'] === [] );
if ( $GLOBALS['blocked_mail'] || $GLOBALS['blocked_http'] ) {
	echo '  blocked mail: ' . implode( ' | ', $GLOBALS['blocked_mail'] ) . "\n  blocked http: " . implode( ' | ', $GLOBALS['blocked_http'] ) . "\n";
}
check( 'T0 live log row count unchanged by the run', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mailroom_log' ) === $LIVE_ROWS );

echo "\n== $pass passed, $fail failed ==\n";
exit( $fail ? 1 : 0 );
