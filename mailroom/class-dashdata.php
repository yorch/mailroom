<?php
/**
 * Dashdata: read-only queries behind the WP Mail SMTP Dashboard (phase P3). No WP Mail SMTP dependency, no hooks
 * and no writes, so it is in the loader list and testable on its own; class-native-dashboard.php (lazy) is the
 * only consumer that touches the plugin's classes.
 *
 * Every query is explicit-column (the content/mime columns can be megabytes), aggregates in SQL and is served by
 * the `created_at` index. Results are memoised for the request only: WP Mail SMTP asks for the same weekly
 * counters many times per page (series, cards, deltas, prompts), and a cross-request cache would only make the
 * numbers stale for a query that is already cheap. `created_at` is site-local wall-clock time (core insert()).
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Dashdata {

	/** Weeks in the dashboard series (WP Mail SMTP's Stats::WEEKS; the subclass file asserts they agree). */
	const WEEKS = 12;

	/** Rows in the Email Log widget (WP Mail SMTP's EmailLog::ROWS is 5). */
	const LOG_ROWS = 5;

	/**
	 * Status → counter. `sent` and `failed` follow the Overview exactly (Mailroom_Reports::stats): sent
	 * includes the emails delivered through the backup, failed counts primary failures only (a failed_backup row
	 * is the backup's attempt at an email whose primary failure is already a row). `backup` is the subset of
	 * sent that went through the backup; `queued` counts emails held by the rate limit.
	 */
	const COUNTERS = [
		'sent'        => [ 'sent' ],
		'sent_backup' => [ 'sent', 'backup' ],
		'failed'      => [ 'failed' ],
		'queued'      => [ 'queued' ],
	];

	/** @var array<string,mixed> request-scoped memo, keyed by table */
	private static $memo = [];

	/** Forget everything (tests, and anything that changes the log mid-request). */
	public static function flush() : void {
		self::$memo = [];
	}

	/** Monday 00:00 (site time) of the ISO week $back weeks before the current one. */
	public static function week_start( int $back ) : DateTimeImmutable {
		return ( new DateTimeImmutable( 'monday this week 00:00', wp_timezone() ) )->modify( '-' . max( 0, $back ) . ' weeks' );
	}

	/**
	 * Weekly counters for the last WEEKS weeks, keyed by ISO week number (WEEK(..., 3) is PHP's 'W').
	 * One aggregate query. Throws when the query fails (wpdb reports it in last_error), so the caller can fall back to WP Mail SMTP's own counters.
	 *
	 * @return array{sent:array<int,int>,failed:array<int,int>,backup:array<int,int>,queued:array<int,int>}
	 */
	public static function weekly() : array {
		global $wpdb;
		$t = Mailroom::table();
		if ( isset( self::$memo[ $t ]['weekly'] ) ) {
			return self::$memo[ $t ]['weekly'];
		}
		$start = self::week_start( self::WEEKS - 1 )->format( 'Y-m-d H:i:s' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT WEEK(created_at, 3) w, status, COUNT(*) c FROM {$t} WHERE created_at >= %s AND status IN ('sent','sent_backup','failed','queued') GROUP BY w, status", $start ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $rows ) || (string) $wpdb->last_error !== '' ) { // wpdb returns [] (not null) for a failed query
			throw new RuntimeException( 'weekly counters query failed' );
		}
		$out = [ 'sent' => [], 'failed' => [], 'backup' => [], 'queued' => [] ];
		foreach ( $rows as $r ) {
			foreach ( self::COUNTERS[ $r['status'] ] ?? [] as $key ) {
				$week                 = (int) $r['w'];
				$out[ $key ][ $week ] = ( $out[ $key ][ $week ] ?? 0 ) + (int) $r['c'];
			}
		}
		return self::$memo[ $t ]['weekly'] = $out;
	}

	/** Site-local datetime of the oldest log row ('' when the log is empty). */
	public static function first_logged_at() : string {
		global $wpdb;
		$t = Mailroom::table();
		if ( ! isset( self::$memo[ $t ]['first'] ) ) {
			$first = $wpdb->get_var( "SELECT MIN(created_at) FROM {$t}" ); // phpcs:ignore WordPress.DB
			if ( (string) $wpdb->last_error !== '' ) {
				throw new RuntimeException( 'first-row query failed' );
			}
			self::$memo[ $t ]['first'] = (string) $first;
		}
		return self::$memo[ $t ]['first'];
	}

	public static function has_rows() : bool {
		return self::first_logged_at() !== '';
	}

	/**
	 * Whether the log reaches back to the start of the week $back weeks ago. Retention prunes old rows, so an older
	 * week can be empty because it was deleted rather than because nothing was sent; callers must not present
	 * that as zero emails.
	 */
	public static function covers_week( int $back ) : bool {
		$first = self::first_logged_at();
		return $first !== '' && $first <= self::week_start( $back )->format( 'Y-m-d H:i:s' );
	}

	/** How many of the oldest series weeks end before the first log row (no data was kept for them). */
	public static function leading_weeks_without_log() : int {
		$first = self::first_logged_at();
		if ( $first === '' ) {
			return 0;
		}
		$drop = 0;
		for ( $back = self::WEEKS - 1; $back >= 0; $back-- ) {
			if ( $first >= self::week_start( $back )->modify( '+7 days' )->format( 'Y-m-d H:i:s' ) ) {
				$drop++;
			} else {
				break;
			}
		}
		return $drop;
	}

	/**
	 * The newest log entries in the row shape of WP Mail SMTP's dashboard Email Log widget
	 * (templates/dashboard/widgets/email-log.php): subject, subject_attr, recipient, mailer, date_sent, status
	 * ('sent'|'waiting'|'failed'), status_label, edit_url, preview_url. Strings are plain (the template escapes);
	 * `subject_attr` is the one pre-escaped value, as the template documents. Each row opens our single-email view.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function email_log_rows( int $limit = self::LOG_ROWS ) : array {
		global $wpdb;
		$t    = Mailroom::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, status, source, mailer, LEFT(to_email, 200) AS to_email, LEFT(subject, 200) AS subject FROM {$t} ORDER BY created_at DESC, id DESC LIMIT %d", max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $rows ) || (string) $wpdb->last_error !== '' ) {
			throw new RuntimeException( 'email log query failed' );
		}
		$labels = class_exists( 'Mailroom_UI' ) ? Mailroom_UI::STATUS_LABELS : [];
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$out    = [];
		foreach ( $rows as $r ) {
			$status  = (string) $r['status'];
			$subject = trim( (string) $r['subject'] ) !== '' ? (string) $r['subject'] : __( '(no subject)', 'mailroom' );
			try {
				$date = wp_date( $format, ( new DateTimeImmutable( (string) $r['created_at'], wp_timezone() ) )->getTimestamp() );
			} catch ( \Throwable $e ) {
				$date = (string) $r['created_at'];
			}
			$backup = in_array( $status, [ 'sent_backup', 'failed_backup' ], true ) || in_array( $r['source'], [ 'backup', 'route' ], true );
			$out[]  = [
				'subject'      => $subject,
				'subject_attr' => esc_attr( esc_html( $subject ) ),
				'recipient'    => (string) $r['to_email'],
				'mailer'       => $backup ? __( 'Backup SMTP', 'mailroom' ) : ( (string) $r['mailer'] !== '' ? (string) $r['mailer'] : '—' ),
				'date_sent'    => (string) $date,
				'status'       => in_array( $status, [ 'sent', 'sent_backup' ], true ) ? 'sent' : ( $status === 'queued' ? 'waiting' : 'failed' ),
				'status_label' => (string) ( $labels[ $status ] ?? $status ),
				'edit_url'     => Mailroom_Bridge::url( 'log', [ 'view' => (int) $r['id'] ] ),
				'preview_url'  => '', // the plugin's preview is its own Thickbox popup; the eye icon opens our entry view instead
			];
		}
		return $out;
	}

	/** Is at least one alert channel set (an https webhook or a valid address)? Same rule as the Overview's alerts card. */
	public static function alerts_configured() : bool {
		$webhook = stripos( trim( (string) Mailroom::opt( 'alert_webhook' ) ), 'https://' ) === 0;
		$emails  = array_filter( preg_split( '/[\s,;]+/', (string) Mailroom::opt( 'alert_email' ) ), 'is_email' );
		return $webhook || (bool) $emails;
	}
}
