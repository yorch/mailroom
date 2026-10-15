<?php
/**
 * Live end-to-end test of the mailroom FAILURE path, against the real WP Mail SMTP plugin.
 *
 * For THIS request only (filters, no settings written):
 *  - WP Mail SMTP's mailer is switched to `smtp` at 127.0.0.1:1 (connection refused), so the real
 *    MailCatcher fails and fires `wp_mail_smtp_mailcatcher_send_failed`. Nothing reaches the wire.
 *  - mailroom options are injected: an https alert webhook (throttle 0) and failover to a
 *    backup at 127.0.0.1:2 (also refused), so alert + failover run for real.
 *  - All outgoing HTTP is intercepted (`pre_http_request`); the alert payload is captured.
 *
 * WP Mail SMTP records a failure in three places. They are snapshotted and restored afterwards so the
 * site isn't left showing a fake "email failed" notice: the `wp_mail_smtp_email_sending_debug` and
 * `wp_mail_smtp_debug` options, and new rows in `wp_wpmailsmtp_debug_events`. Also removes the
 * failover circuit-breaker transient and this run's rows in `wp_mailroom_log`.
 *
 *   MAILROOM_WP_LOAD=/path/to/wp-load.php MAILROOM_SITE_HOST=www.example.com \
 *     php -d display_errors=0 tests/e2e-mail-failure.php
 *
 * The test recipient never receives anything (both hosts refuse the connection). Override it with
 * MAILROOM_E2E_TO if you want the failed row to carry a recognisable address.
 */
require __DIR__ . '/bootstrap.php';
$E2E_TO = getenv( 'MAILROOM_E2E_TO' ) ?: 'mailroom-e2e@example.com';
global $wpdb;

$pass = 0;
$fail = 0;
function check( $name, $cond, $detail = '' ) {
	global $pass, $fail;
	$cond ? $pass++ : $fail++;
	printf( "%s  %s%s\n", $cond ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ($detail)" : '' );
}

if ( ! class_exists( 'Mailroom' ) || ! function_exists( 'wp_mail_smtp' ) ) {
	fwrite( STDERR, "mu-plugin or WP Mail SMTP not loaded\n" );
	exit( 1 );
}

// --- snapshot WP Mail SMTP failure state ---------------------------------
$missing = new stdClass();
$snap    = [];
foreach ( [ 'wp_mail_smtp_email_sending_debug', 'wp_mail_smtp_debug' ] as $k ) {
	$snap[ $k ] = get_option( $k, $missing );
}
$events    = $wpdb->prefix . 'wpmailsmtp_debug_events';
$has_ev    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events ) ) === $events;
$ev_max    = $has_ev ? (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$events}" ) : 0;
$T         = Mailroom::table();
$log_max   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$T}" );
$real_mailer = wp_mail_smtp()->get_connections_manager()->get_mail_connection()->get_mailer_slug();

// --- per-request overrides -----------------------------------------------
$smtp = [ 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'auth' => false, 'autotls' => false, 'user' => '', 'pass' => '' ];
add_filter( 'wp_mail_smtp_options_get', function ( $v, $group, $key ) use ( $smtp ) {
	if ( $group === 'mail' && $key === 'mailer' ) { return 'smtp'; }
	if ( $group === 'smtp' && array_key_exists( $key, $smtp ) ) { return $smtp[ $key ]; }
	return $v;
}, PHP_INT_MAX, 3 );
add_filter( 'wp_mail_smtp_options_get_group', function ( $opts, $group ) use ( $smtp ) {
	return $group === 'smtp' ? array_merge( (array) $opts, $smtp ) : $opts;
}, PHP_INT_MAX, 2 );

add_filter( 'pre_option_' . Mailroom::OPT, function () {
	return [
		'retention_days' => 30,
		'log_body'       => 0,
		'alert_webhook'  => 'https://alert.e2e.invalid/hook',
		'alert_throttle' => 0,
		'backup_enabled' => 1,
		'backup_host'    => '127.0.0.1',
		'backup_port'    => 2,
		'backup_secure'  => '',
		'backup_user'    => '',
		'backup_pass'    => '',
		'backup_from'    => 'e2e@example.com',
	];
} );

$http = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$http ) {
	$http[] = [ 'url' => $url, 'body' => $args['body'] ?? '' ];
	return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
}, PHP_INT_MAX, 3 );

// Guard: if the override didn't take, this would be a REAL send through the live mailer. Abort.
$conn = wp_mail_smtp()->get_connections_manager()->get_mail_connection();
$eff  = [ $conn->get_mailer_slug(), $conn->get_options()->get( 'smtp', 'host' ), (int) $conn->get_options()->get( 'smtp', 'port' ) ];
if ( $eff !== [ 'smtp', '127.0.0.1', 1 ] ) {
	fwrite( STDERR, 'ABORT: override not effective: ' . wp_json_encode( $eff ) . "\n" );
	exit( 2 );
}

$wp_err = null;
add_action( 'wp_mail_failed', function ( $e ) use ( &$wp_err ) { $wp_err = $e->get_error_message(); } );

// --- the send -------------------------------------------------------------
$subject = 'mailroom e2e-failure ' . gmdate( 'Ymd\THis\Z' );
$t0      = microtime( true );
$ok      = wp_mail( $E2E_TO, $subject, 'Forced-failure e2e test. Never delivered.' );
$ms      = (int) round( ( microtime( true ) - $t0 ) * 1000 );
echo "real mailer: {$real_mailer} (forced to smtp 127.0.0.1:1 for this request)\n";
echo "wp_mail: " . ( $ok ? 'TRUE' : 'FALSE' ) . " in {$ms} ms; wp_mail_failed: " . ( $wp_err ? preg_replace( '/\s+/', ' ', $wp_err ) : '(none)' ) . "\n\n";

// --- assertions -----------------------------------------------------------
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$T} WHERE id > %d ORDER BY id", $log_max ) );
foreach ( $rows as $r ) {
	printf( "  row %d: %s / %s / %s  to=%s  err=%s\n", $r->id, $r->status, $r->source, $r->mailer, $r->to_email, mb_substr( (string) $r->error, 0, 140 ) );
}
$by = [];
foreach ( $rows as $r ) { $by[ $r->status ][] = $r; }

check( 'wp_mail returned false', $ok === false );
check( 'exactly one `failed` row', count( $by['failed'] ?? [] ) === 1 );
check( 'no `sent` row for a failed send', empty( $by['sent'] ) );
$f = $by['failed'][0] ?? null;
check( 'failed row: source smtp, mailer smtp', $f && $f->source === 'smtp' && $f->mailer === 'smtp' );
check( 'failed row: recipient + subject + error recorded', $f && $f->to_email === $E2E_TO && $f->subject === $subject && $f->error !== '' );

$alerts = array_values( array_filter( $http, function ( $h ) { return strpos( $h['url'], 'alert.e2e.invalid' ) !== false; } ) );
check( 'exactly one alert POST', count( $alerts ) === 1, count( $http ) . ' HTTP call(s) intercepted in total' );
$p = $alerts ? json_decode( $alerts[0]['body'], true ) : null;
check( 'alert payload: event/mailer/count', is_array( $p ) && $p['event'] === 'email_send_failed' && $p['mailer'] === 'smtp' && (int) $p['recipient_count'] === 1 );
check( 'alert payload has no recipient address or subject', $alerts && strpos( $alerts[0]['body'], strtok( $E2E_TO, '@' ) ) === false && strpos( $alerts[0]['body'], 'e2e-failure' ) === false );

$bk = $by['failed_backup'] ?? [];
check( 'exactly one `failed_backup` row (backup refused)', count( $bk ) === 1 );
check( 'failed_backup error names both primary and backup', $bk && strpos( $bk[0]->error, 'primary failed' ) !== false && strpos( $bk[0]->error, 'backup' ) !== false );
check( 'circuit breaker set after backup failure', (bool) get_transient( 'mailroom_backup_down' ) );
check( 'request stayed bounded (< 20 s)', $ms < 20000, "{$ms} ms" );

// --- restore --------------------------------------------------------------
delete_transient( 'mailroom_backup_down' );
foreach ( $snap as $k => $v ) {
	$v === $missing ? delete_option( $k ) : update_option( $k, $v );
}
$ev_added = 0;
if ( $has_ev ) {
	$ev_added = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE id > %d", $ev_max ) );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$T} WHERE id > %d", $log_max ) );
echo "\ncleanup: restored " . implode( ', ', array_keys( $snap ) ) . "; removed {$ev_added} debug event(s), " . count( $rows ) . " log row(s), circuit breaker\n";
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
