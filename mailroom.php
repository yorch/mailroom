<?php
/**
 * Plugin Name: Mailroom
 * Description: Email log, failure alerts, backup-SMTP failover and email reports for WP Mail SMTP (Lite). Fills the observability/robustness gaps that WP Mail SMTP Pro gates behind a licence.
 * Plugin URI:  https://github.com/yorch/mailroom
 * Version:     2.0.0
 * Author:      Jorge Barnaby
 * Requires PHP: 8.0
 * License:     GPL-3.0-or-later
 *
 * Admin-only helpers: ui (badges, tab URLs, inline CSS/JS), overview, dashdata (read-only queries behind WP Mail
 * SMTP's Dashboard page) and bridge (decides whether our screens render inside WP Mail SMTP's own admin; off = our
 * standalone page). Three files are NOT in the loader list on purpose, because each extends a class that does not
 * exist yet at include time: class-list-table.php extends WP_List_Table (an admin-only include;
 * Mailroom_Admin requires it on demand when the log renders), and class-native.php and
 * class-native-dashboard.php extend WP Mail SMTP's classes (mu-plugins load before regular plugins;
 * Mailroom_Bridge requires each only after its own version/class/signature checks pass).
 *
 * Deployed as an mu-plugin: wp-content/mu-plugins/mailroom.php (this loader)
 * plus wp-content/mu-plugins/mailroom/ (one file per feature).
 *
 * Design rules (do not break these):
 *  - A logging failure must NEVER break email delivery. Every write is wrapped and suppressed.
 *  - Never store SMTP passwords or OAuth tokens. Bodies are stored only when opted in: a short redacted
 *    preview, and/or the full message (separate opt-in, short retention, for resend/EML).
 *  - Admin actions require a capability AND a nonce. CSV is escaped against formula injection.
 *  - Failover is best-effort, at most once per request and behind a circuit breaker: the original
 *    wp_mail() caller still sees false. This is documented, not hidden.
 *  - A missing module file must not take the site down (mu-plugin fatals are site-wide): modules
 *    are loaded only if readable, and the core only wires modules whose class exists.
 */

defined( 'ABSPATH' ) || exit;

foreach ( [ 'core', 'logger', 'alerts', 'failover', 'content', 'controls', 'reports', 'ratelimit', 'routing', 'ui', 'overview', 'dashdata', 'bridge', 'upsells', 'admin' ] as $mailroom_module ) {
	$mailroom_file = __DIR__ . '/mailroom/class-' . $mailroom_module . '.php';
	if ( is_readable( $mailroom_file ) ) {
		require_once $mailroom_file;
	}
}
unset( $mailroom_module, $mailroom_file );

if ( class_exists( 'Mailroom' ) ) {
	Mailroom::instance();
}
