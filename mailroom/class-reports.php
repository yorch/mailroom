<?php
/**
 * Weekly email report: delivery summary for the last 7 days, sent through the site mailer (wp_mail).
 *
 * Off by default. The weekly cron event exists only while the report is enabled (reconciled on boot,
 * so toggling the setting schedules/unschedules it). "Send report now" sends immediately.
 * Error text is PII-masked; subjects are included (the report goes to site operators).
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Reports {

	const SECTION = 'reports';
	const CRON = 'mailroom_weekly_report';

	/** @var Mailroom */
	private $core;

	public function __construct( Mailroom $core ) {
		$this->core = $core;
	}

	public function boot() : void {
		add_action( self::CRON, [ $this, 'send_report' ] );
		$this->sync_schedule();
		add_action( 'mailroom_settings_reports', [ $this, 'settings_rows' ] );
		add_action( 'mailroom_actions_reports', [ $this, 'action_rows' ] );
		add_filter( 'mailroom_sanitize', [ $this, 'sanitize' ], 10, 3 );
		add_action( 'admin_post_mailroom_report_now', [ $this, 'handle_now' ] );
	}

	/** Weekly event present iff the report is enabled. */
	public function sync_schedule() : void {
		$next = wp_next_scheduled( self::CRON );
		if ( Mailroom::opt( 'report_enabled' ) ) {
			if ( ! $next ) {
				// Next Monday 08:00 site time.
				$ts = ( new DateTimeImmutable( 'next monday 08:00', wp_timezone() ) )->getTimestamp();
				wp_schedule_event( $ts, 'weekly', self::CRON );
			}
		} elseif ( $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/** @return string[] */
	public static function recipients() : array {
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', (string) Mailroom::opt( 'report_email' ) ) as $a ) {
			if ( $a !== '' && is_email( $a ) ) {
				$out[] = $a;
			}
		}
		return $out ? array_values( array_unique( $out ) ) : [ (string) get_option( 'admin_email' ) ];
	}

	/** Summary of the last $days days. */
	public static function stats( int $days = 7 ) : array {
		global $wpdb;
		$t     = Mailroom::table();
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$by    = [];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) c FROM {$t} WHERE created_at >= %s GROUP BY status", $since ), ARRAY_A ) as $r ) { // phpcs:ignore
			$by[ $r['status'] ] = (int) $r['c'];
		}
		$errors = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(NULLIF(error_key,''), LEFT(error, 120)) k, COUNT(*) c FROM {$t} WHERE created_at >= %s AND status IN ('failed','failed_backup') GROUP BY k ORDER BY c DESC LIMIT 5", $since ), ARRAY_A ); // phpcs:ignore
		$subjects = $wpdb->get_results( $wpdb->prepare( "SELECT LEFT(subject, 100) s, COUNT(*) c FROM {$t} WHERE created_at >= %s AND status IN ('sent','sent_backup') GROUP BY s ORDER BY c DESC LIMIT 5", $since ), ARRAY_A ); // phpcs:ignore
		$sent  = ( $by['sent'] ?? 0 ) + ( $by['sent_backup'] ?? 0 );
		$fail  = $by['failed'] ?? 0; // primary failures (failed_backup rows are the backup's attempts at the same emails)
		return [
			'days'      => $days,
			'since'     => $since,
			'sent'      => $sent,
			'failed'    => $fail,
			'by_status' => $by,
			'fail_rate' => ( $sent + $fail ) > 0 ? round( 100 * $fail / ( $sent + $fail ), 1 ) : 0.0,
			'errors'    => (array) $errors,
			'subjects'  => (array) $subjects,
			'routed'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_at >= %s AND source = 'route'", $since ) ), // phpcs:ignore
		];
	}

	public static function render_text( array $s ) : string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$l    = [];
		$l[]  = "Email delivery report for {$host}: last {$s['days']} days (since {$s['since']})";
		$l[]  = '';
		$l[]  = sprintf( 'Sent: %d   Failed: %d   Failure rate: %s%%', $s['sent'], $s['failed'], $s['fail_rate'] );
		foreach ( [ 'sent_backup' => 'Delivered via backup', 'failed_backup' => 'Backup attempts failed', 'queued' => 'Queued by the rate limit' ] as $k => $label ) {
			if ( ! empty( $s['by_status'][ $k ] ) ) {
				$l[] = sprintf( '%s: %d', $label, $s['by_status'][ $k ] );
			}
		}
		if ( ! empty( $s['routed'] ) ) {
			$l[] = sprintf( 'Sent via Smart Routing (backup host): %d', $s['routed'] );
		}
		if ( $s['errors'] ) {
			$l[] = '';
			$l[] = 'Top errors:';
			foreach ( $s['errors'] as $e ) {
				$l[] = sprintf( '  %4d  %s', $e['c'], Mailroom::mask_emails( (string) $e['k'] ) );
			}
		}
		if ( $s['subjects'] ) {
			$l[] = '';
			$l[] = 'Most-sent subjects:';
			foreach ( $s['subjects'] as $e ) {
				$l[] = sprintf( '  %4d  %s', $e['c'], (string) $e['s'] );
			}
		}
		$l[] = '';
		$l[] = 'Email log: ' . admin_url( 'admin.php?page=mailroom-log' );
		return implode( "\n", $l );
	}

	public function send_report() : bool {
		try {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			return (bool) wp_mail( self::recipients(), "[{$host}] Weekly email delivery report", self::render_text( self::stats( 7 ) ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function settings_rows( array $o ) : void {
		$next = wp_next_scheduled( self::CRON );
		?>
		<h2><?php esc_html_e( 'Weekly report', 'mailroom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A delivery summary for the last 7 days (totals, failure rate, top errors, most-sent subjects), sent through the site mailer.', 'mailroom' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="mailroom-report-enabled"><?php esc_html_e( 'Weekly email report', 'mailroom' ); ?></label></th><td><label for="mailroom-report-enabled"><input type="checkbox" id="mailroom-report-enabled" name="report_enabled" value="1" data-mailroom-toggle="report" <?php checked( $o['report_enabled'] ); ?>> <?php esc_html_e( 'Send every Monday 08:00 (site time)', 'mailroom' ); ?></label>
				<?php if ( $next ) : ?><p class="description"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Next: %s', 'mailroom' ), wp_date( 'Y-m-d H:i', $next ) ) ); ?></p><?php endif; ?></td></tr>
			<tr data-mailroom-dep="report"><th scope="row"><label for="mailroom-report-email"><?php esc_html_e( 'Recipients', 'mailroom' ); ?></label></th><td><input type="text" class="regular-text" id="mailroom-report-email" name="report_email" value="<?php echo esc_attr( $o['report_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"><p class="description"><?php esc_html_e( 'Empty = the site admin email.', 'mailroom' ); ?></p></td></tr>
		</table>
		<?php
	}

	/** Rendered after (outside) the settings form: its own POST form. */
	public function action_rows( array $o ) : void {
		?>
		<div class="mailroom-actions-box">
			<h2><?php esc_html_e( 'Send now', 'mailroom' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Sends the report immediately to the saved recipients.', 'mailroom' ); ?></p>
			<?php echo Mailroom_UI::action_form( 'mailroom_report_now', 'mailroom_report_now', __( 'Send report now', 'mailroom' ), 'reports' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in action_form() ?>
		</div>
		<?php
	}

	public function sanitize( array $acc, array $post, string $section = '' ) : array {
		if ( $section !== '' && $section !== self::SECTION ) {
			return $acc;
		}
		[ $o, $ok ]          = $acc;
		$o['report_enabled'] = empty( $post['report_enabled'] ) ? 0 : 1;
		$emails              = [];
		foreach ( preg_split( '/[\s,;]+/', (string) wp_unslash( $post['report_email'] ?? '' ) ) as $a ) {
			if ( $a === '' ) {
				continue;
			}
			if ( is_email( $a ) ) {
				$emails[] = sanitize_email( $a );
			} else {
				$ok = false;
			}
		}
		$o['report_email'] = implode( ', ', array_unique( $emails ) );
		return [ $o, $ok ];
	}

	public function handle_now() : void {
		if ( ! current_user_can( Mailroom::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mailroom' ) );
		}
		Mailroom_UI::require_post();
		check_admin_referer( 'mailroom_report_now' );
		$ok = $this->send_report();
		Mailroom_UI::finish( $ok ? 'Report sent to ' . implode( ', ', self::recipients() ) . '.' : 'Report send failed (see the log).', 'reports' );
	}
}
