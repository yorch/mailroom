<?php
/**
 * Overview tab: seven-day health cards and one status card per feature (on/off, a one-line summary, and a
 * warning when a feature is switched on but cannot work). Read-only; reuses the modules' own helpers and
 * degrades quietly when a module is not loaded.
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Overview {

	public static function render() : void {
		self::render_health();
		self::render_features();
	}

	private static function render_health() : void {
		global $wpdb;
		echo '<h2>' . esc_html__( 'Last 7 days', 'mailroom' ) . '</h2>';
		if ( ! class_exists( 'Mailroom_Reports' ) ) {
			echo '<p class="description">' . esc_html__( 'Statistics are unavailable (the reports module is not loaded).', 'mailroom' ) . '</p>';
			return;
		}
		$s      = Mailroom_Reports::stats( 7 );
		$queued = class_exists( 'Mailroom_RateLimit' ) ? Mailroom_RateLimit::queue_count() : 0;
		$last   = $wpdb->get_row( 'SELECT id, created_at, error FROM ' . Mailroom::table() . " WHERE status = 'failed' ORDER BY created_at DESC, id DESC LIMIT 1" ); // phpcs:ignore

		$cards = [
			[ 'good', __( 'Sent', 'mailroom' ), (string) $s['sent'], '' ],
			[ $s['failed'] ? 'bad' : 'good', __( 'Failed', 'mailroom' ), (string) $s['failed'], '' ],
			[ $s['fail_rate'] > 0 ? 'bad' : 'good', __( 'Failure rate', 'mailroom' ), $s['fail_rate'] . '%', '' ],
			[ 'info', __( 'Delivered via backup', 'mailroom' ), (string) ( $s['by_status']['sent_backup'] ?? 0 ), '' ],
			[
				$queued ? 'warn' : 'info',
				__( 'Waiting in queue', 'mailroom' ),
				(string) $queued,
				sprintf( /* translators: %d: count */ __( '%d queued by the rate limit in 7 days', 'mailroom' ), (int) ( $s['by_status']['queued'] ?? 0 ) ),
			],
		];
		echo '<div class="mailroom-cards">';
		foreach ( $cards as [ $tone, $label, $value, $note ] ) {
			echo '<div class="mailroom-card mailroom-card--' . esc_attr( $tone ) . '"><h3>' . esc_html( $label ) . '</h3><p class="mailroom-metric">' . esc_html( $value ) . '</p>';
			echo $note !== '' ? '<p class="mailroom-card__note">' . esc_html( $note ) . '</p>' : '';
			echo '</div>';
		}

		echo '<div class="mailroom-card mailroom-card--' . ( $last ? 'bad' : 'good' ) . '" data-card="last-failure"><h3>' . esc_html__( 'Last failure', 'mailroom' ) . '</h3>';
		if ( $last ) {
			echo '<p class="mailroom-metric" style="font-size:18px">' . Mailroom_UI::when( (string) $last->created_at ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in UI::when()
			echo '<p class="mailroom-card__note"><code>' . esc_html( Mailroom::mask_emails( mb_substr( (string) $last->error, 0, 160 ) ) ) . '</code></p>';
			$url = class_exists( 'Mailroom_Content' )
				? Mailroom_UI::view_url( (int) $last->id )
				: Mailroom_UI::tab_url( 'log', [ 'status' => 'failed' ] );
			echo '<p class="mailroom-card__note"><a href="' . esc_url( $url ) . '">' . esc_html__( 'View this email', 'mailroom' ) . '</a></p>';
		} else {
			echo '<p class="mailroom-card__note">' . esc_html__( 'No failures recorded.', 'mailroom' ) . '</p>';
		}
		echo '</div></div>';
	}

	/** @return array<int,array{key:string,title:string,on:bool,summary:string,warning:string,tab:string}> */
	private static function features() : array {
		$o = wp_parse_args( (array) get_option( Mailroom::OPT, [] ), Mailroom::defaults() );

		$webhook  = stripos( trim( (string) $o['alert_webhook'] ), 'https://' ) === 0 ? trim( (string) $o['alert_webhook'] ) : '';
		$emails   = array_filter( preg_split( '/[\s,;]+/', (string) $o['alert_email'] ), 'is_email' );
		$channels = [];
		if ( $webhook !== '' ) {
			$channels[] = class_exists( 'Mailroom_Alerts' ) ? Mailroom_Alerts::format_for( $webhook, (string) $o['alert_format'] ) . ' webhook' : 'webhook';
		}
		if ( $emails ) {
			$channels[] = sprintf( /* translators: %d: count */ _n( '%d email address', '%d email addresses', count( $emails ), 'mailroom' ), count( $emails ) );
		}

		$backup_host  = trim( (string) $o['backup_host'] ) !== '';
		$backup_from  = is_email( (string) $o['backup_from'] );
		$backup_ready = $backup_host && $backup_from;
		$backup_gap   = ! $backup_host ? __( 'no backup host is set', 'mailroom' ) : __( 'the backup From address is missing or invalid', 'mailroom' );

		$rules = class_exists( 'Mailroom_Routing' ) ? count( Mailroom_Routing::rules() ) : 0;
		$off   = count( (array) $o['controls_off'] );
		$next  = class_exists( 'Mailroom_Reports' ) ? wp_next_scheduled( Mailroom_Reports::CRON ) : false;

		return [
			[
				'key'     => 'alerts',
				'title'   => __( 'Failure alerts', 'mailroom' ),
				'on'      => (bool) $channels,
				'summary' => $channels ? implode( ' + ', $channels ) . sprintf( /* translators: %d: seconds */ __( ', at most one alert per %d s', 'mailroom' ), (int) $o['alert_throttle'] ) : __( 'No channel configured.', 'mailroom' ),
				'warning' => $channels ? '' : __( 'No alert channel is set, so failed sends are only recorded in the log.', 'mailroom' ),
				'tab'     => 'alerts',
			],
			[
				'key'     => 'failover',
				'title'   => __( 'Backup failover', 'mailroom' ),
				'on'      => (bool) $o['backup_enabled'],
				'summary' => $backup_host ? sprintf( '%s:%d, From %s', $o['backup_host'], (int) $o['backup_port'], $backup_from ? $o['backup_from'] : '—' ) : __( 'No backup SMTP host set.', 'mailroom' ),
				'warning' => ( $o['backup_enabled'] && ! $backup_ready ) ? sprintf( /* translators: %s: reason */ __( 'Failover is on but cannot send: %s.', 'mailroom' ), $backup_gap ) : '',
				'tab'     => 'connections',
			],
			[
				'key'     => 'storage',
				'title'   => __( 'Full email storage', 'mailroom' ),
				'on'      => (bool) $o['store_content'],
				'summary' => $o['store_content'] ? sprintf( /* translators: %d: days */ __( 'Kept for %d days (resend and .eml).', 'mailroom' ), (int) $o['content_days'] ) : __( 'Only metadata is logged.', 'mailroom' ),
				'warning' => '',
				'tab'     => 'storage',
			],
			[
				'key'     => 'controls',
				'title'   => __( 'Email controls', 'mailroom' ),
				'on'      => $off > 0,
				'summary' => $off ? sprintf( /* translators: %d: count */ _n( '%d WordPress notification is switched off.', '%d WordPress notifications are switched off.', $off, 'mailroom' ), $off ) : __( 'All WordPress notifications are sent.', 'mailroom' ),
				'warning' => '',
				'tab'     => 'controls',
			],
			[
				'key'     => 'reports',
				'title'   => __( 'Weekly report', 'mailroom' ),
				'on'      => (bool) $o['report_enabled'],
				'summary' => $o['report_enabled'] ? sprintf( /* translators: %s: date */ __( 'Next: %s', 'mailroom' ), $next ? wp_date( 'Y-m-d H:i', $next ) : '—' ) : __( 'Not scheduled.', 'mailroom' ),
				'warning' => '',
				'tab'     => 'reports',
			],
			[
				'key'     => 'ratelimit',
				'title'   => __( 'Rate limiting', 'mailroom' ),
				'on'      => (bool) $o['rate_enabled'],
				'summary' => $o['rate_enabled'] ? sprintf( /* translators: 1: emails 2: minutes */ __( 'At most %1$d emails per %2$d minutes.', 'mailroom' ), (int) $o['rate_max'], (int) $o['rate_window'] ) : __( 'No cap on sending.', 'mailroom' ),
				'warning' => ( $o['rate_enabled'] && trim( (string) $o['rate_exempt'] ) === '' ) ? __( 'Nothing is exempt, so password-reset emails can be queued and delayed.', 'mailroom' ) : '',
				'tab'     => 'ratelimit',
			],
			[
				'key'     => 'routing',
				'title'   => __( 'Smart Routing', 'mailroom' ),
				'on'      => (bool) $o['route_enabled'],
				'summary' => $o['route_enabled'] ? sprintf( /* translators: %d: count */ _n( '%d rule routes email via the backup SMTP.', '%d rules route email via the backup SMTP.', $rules, 'mailroom' ), $rules ) : __( 'Everything uses the primary mailer.', 'mailroom' ),
				'warning' => ( $o['route_enabled'] && ! $backup_ready )
					? sprintf( /* translators: %s: reason */ __( 'Routing is on but cannot route anything: %s.', 'mailroom' ), $backup_gap )
					: ( ( $o['route_enabled'] && ! $rules ) ? __( 'Routing is on but there are no valid rules.', 'mailroom' ) : '' ),
				'tab'     => 'routing',
			],
		];
	}

	private static function render_features() : void {
		echo '<h2>' . esc_html__( 'Features', 'mailroom' ) . '</h2><div class="mailroom-cards">';
		foreach ( self::features() as $f ) {
			$tone = $f['warning'] !== '' ? 'warn' : ( $f['on'] ? 'good' : '' );
			echo '<div class="mailroom-card' . ( $tone !== '' ? ' mailroom-card--' . esc_attr( $tone ) : '' ) . '" data-feature="' . esc_attr( $f['key'] ) . '">';
			echo '<h3>' . esc_html( $f['title'] ) . ' <span class="mailroom-pill' . ( $f['on'] ? ' mailroom-pill--on' : '' ) . '">' . ( $f['on'] ? esc_html__( 'On', 'mailroom' ) : esc_html__( 'Off', 'mailroom' ) ) . '</span></h3>';
			echo '<p class="mailroom-card__summary">' . esc_html( $f['summary'] ) . '</p>';
			if ( $f['warning'] !== '' ) {
				echo '<p class="mailroom-card__warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <strong>' . esc_html__( 'Warning:', 'mailroom' ) . '</strong> ' . esc_html( $f['warning'] ) . '</p>';
			}
			echo '<a href="' . esc_url( Mailroom_UI::tab_url( $f['tab'] ) ) . '">' . esc_html__( 'Configure', 'mailroom' ) . '</a>';
			echo '</div>';
		}
		echo '</div>';
	}
}
