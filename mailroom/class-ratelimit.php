<?php
/**
 * Rate limiting: at most `rate_max` emails per `rate_window` minutes. Over the cap, wp_mail() calls are
 * queued (and wp_mail returns true); a 5-minute cron drains the queue within the cap.
 *
 * Never lose mail: if anything about queuing fails (DB error, attachments too large), the email is
 * sent immediately instead. Subjects matching `rate_exempt` (default: password resets) bypass the cap.
 * Attachments are embedded in the queue row (base64), because the caller's temp files may be deleted
 * as soon as wp_mail() returns.
 * The send count is taken from the log (sent/failed/backup rows in the window), so it includes mail
 * from every source.
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_RateLimit {

	const SECTION      = 'delivery';
	const NATIVE_SECTION = 'ratelimit'; // the same keys, saved from the Smart Routing tab inside WP Mail SMTP
	const CRON         = 'mailroom_drain';
	const SCHEDULE     = 'mailroom_5min';
	const DB_VER_OPT   = 'mailroom_queue_db_version';
	const DB_VER       = '1';
	const MAX_ATTACH   = 10485760; // 10 MB total per queued email
	const MAX_ATTEMPTS = 3;

	/** @var Mailroom */
	private $core;

	/** true while the drain is sending, so its own wp_mail() calls aren't re-queued */
	private $draining = false;

	public function __construct( Mailroom $core ) {
		$this->core = $core;
	}

	public function boot() : void {
		$this->maybe_install();
		add_filter( 'pre_wp_mail', [ $this, 'maybe_queue' ], 10, 2 );
		add_filter( 'cron_schedules', [ $this, 'schedules' ] );
		add_action( self::CRON, [ $this, 'drain' ] );
		$this->sync_schedule();
		add_action( 'mailroom_settings_delivery', [ $this, 'settings_rows' ], 20 );
		add_action( 'mailroom_actions_delivery', [ $this, 'action_rows' ] );
		add_action( 'mailroom_settings_ratelimit', [ $this, 'settings_rows' ], 20 );
		add_action( 'mailroom_actions_ratelimit', [ $this, 'action_rows' ] );
		add_filter( 'mailroom_sanitize', [ $this, 'sanitize' ], 10, 3 );
		add_action( 'admin_post_mailroom_drain_now', [ $this, 'handle_drain_now' ] );
	}

	public static function table() : string {
		global $wpdb;
		return (string) apply_filters( 'mailroom_queue_table', $wpdb->prefix . 'mailroom_queue' );
	}

	public function maybe_install() : void {
		if ( get_option( self::DB_VER_OPT ) === self::DB_VER ) {
			return;
		}
		self::create_table( self::table() );
		global $wpdb;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) === self::table() ) {
			update_option( self::DB_VER_OPT, self::DB_VER, true );
		}
	}

	public static function create_table( string $table ) : void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			payload longtext NOT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			log_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$wpdb->get_charset_collate()};" );
	}

	public function schedules( $s ) {
		$s[ self::SCHEDULE ] = [ 'interval' => 300, 'display' => 'Every 5 minutes (Mailroom)' ];
		return $s;
	}

	/**
	 * Drain event present iff the limit is on or something is still queued. Runs on every request, so
	 * the queue COUNT is only taken in the rare "limit off but event still scheduled" state.
	 */
	public function sync_schedule() : void {
		$on   = (bool) Mailroom::opt( 'rate_enabled' );
		$next = wp_next_scheduled( self::CRON );
		if ( $on && ! $next ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON );
		} elseif ( ! $on && $next && self::queue_count() === 0 ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	public static function queue_count() : int {
		global $wpdb;
		$n = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore
		return (int) $n;
	}

	/** Emails sent or attempted (any path) in the current window, from the log. */
	public static function sent_in_window() : int {
		global $wpdb;
		$mins  = max( 1, (int) Mailroom::opt( 'rate_window' ) );
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $mins * MINUTE_IN_SECONDS );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Mailroom::table() . " WHERE created_at >= %s AND status IN ('sent','failed','sent_backup','failed_backup')", $since ) ); // phpcs:ignore
	}

	public static function is_exempt( string $subject ) : bool {
		foreach ( preg_split( '/\R/', (string) Mailroom::opt( 'rate_exempt' ) ) as $p ) {
			$p = trim( $p );
			if ( $p !== '' && stripos( $subject, $p ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/** pre_wp_mail: return true (queued) or pass $return through untouched. */
	public function maybe_queue( $return, $atts ) {
		try {
			if ( $return !== null || $this->draining || ! Mailroom::opt( 'rate_enabled' ) || ! is_array( $atts ) ) {
				return $return;
			}
			if ( self::is_exempt( (string) ( $atts['subject'] ?? '' ) ) ) {
				return $return;
			}
			if ( self::sent_in_window() < max( 1, (int) Mailroom::opt( 'rate_max' ) ) ) {
				return $return;
			}
			return $this->enqueue( $atts ) ? true : $return;
		} catch ( \Throwable $e ) {
			return $return; // never lose mail: send it now
		}
	}

	/** @return bool true if stored (then the caller must NOT send) */
	private function enqueue( array $atts ) : bool {
		$att = $atts['attachments'] ?? [];
		if ( ! is_array( $att ) ) {
			$att = $att === '' ? [] : explode( "\n", str_replace( "\r\n", "\n", (string) $att ) );
		}
		$files = [];
		$total = 0;
		foreach ( $att as $name => $path ) {
			$path = (string) $path;
			if ( ! is_readable( $path ) ) {
				continue; // wp_mail would skip it too
			}
			$total += (int) filesize( $path );
			if ( $total > self::MAX_ATTACH ) {
				return false; // too big to queue: send now
			}
			$files[] = [ 'name' => is_string( $name ) ? $name : basename( $path ), 'data' => base64_encode( (string) file_get_contents( $path ) ) ];
		}
		$payload = wp_json_encode( [
			'to'          => $atts['to'] ?? '',
			'subject'     => (string) ( $atts['subject'] ?? '' ),
			'message'     => (string) ( $atts['message'] ?? '' ),
			'headers'     => $atts['headers'] ?? '',
			'attachments' => $files,
		], JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! $payload ) {
			return false;
		}
		$to     = is_array( $atts['to'] ?? '' ) ? implode( ', ', $atts['to'] ) : (string) ( $atts['to'] ?? '' );
		$log_id = $this->core->insert( [
			'status'   => 'queued',
			'source'   => 'queue',
			'mailer'   => Mailroom::current_mailer(),
			'to_email' => $to,
			'subject'  => (string) ( $atts['subject'] ?? '' ),
		] );
		global $wpdb;
		$ok = $wpdb->insert( self::table(), [ 'created_at' => current_time( 'mysql' ), 'payload' => $payload, 'log_id' => $log_id ] ); // phpcs:ignore
		if ( ! $ok ) {
			if ( $log_id ) {
				$wpdb->delete( Mailroom::table(), [ 'id' => $log_id ] ); // phpcs:ignore
			}
			return false;
		}
		$this->sync_schedule();
		return true;
	}

	/** Cron: send queued emails within the remaining capacity, oldest first. @return int sent count */
	public function drain() : int {
		if ( get_transient( 'mailroom_drain_lock' ) ) {
			return 0;
		}
		set_transient( 'mailroom_drain_lock', 1, 240 );
		$sent = 0;
		try {
			global $wpdb;
			$cap  = Mailroom::opt( 'rate_enabled' ) ? max( 0, max( 1, (int) Mailroom::opt( 'rate_max' ) ) - self::sent_in_window() ) : 100;
			$rows = $cap > 0 ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id ASC LIMIT %d', min( $cap, 100 ) ) ) : []; // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				$p = json_decode( (string) $r->payload, true );
				if ( ! is_array( $p ) ) {
					$wpdb->delete( self::table(), [ 'id' => $r->id ] ); // phpcs:ignore
					continue;
				}
				$tmp   = [];
				$files = [];
				foreach ( (array) $p['attachments'] as $a ) {
					$dir = trailingslashit( get_temp_dir() ) . 'mailroom-mail-' . wp_generate_password( 12, false );
					if ( wp_mkdir_p( $dir ) ) {
						$f = $dir . '/' . sanitize_file_name( (string) $a['name'] );
						file_put_contents( $f, base64_decode( (string) $a['data'] ) );
						$tmp[]   = $dir;
						$files[] = $f;
					}
				}
				$err = '';
				$cb  = function ( $e ) use ( &$err ) { $err = is_wp_error( $e ) ? $e->get_error_message() : 'failed'; };
				add_action( 'wp_mail_failed', $cb );
				$this->draining = true;
				try {
					$ok = wp_mail( $p['to'], (string) $p['subject'], (string) $p['message'], $p['headers'], $files );
				} finally {
					$this->draining = false;
					remove_action( 'wp_mail_failed', $cb );
					foreach ( $tmp as $d ) {
						foreach ( (array) glob( $d . '/*' ) as $f ) {
							@unlink( $f ); // phpcs:ignore
						}
						@rmdir( $d ); // phpcs:ignore
					}
				}
				if ( $ok || (int) $r->attempts + 1 >= self::MAX_ATTEMPTS ) {
					// Sent, or given up (each attempt is in the log as a failed row).
					$wpdb->delete( self::table(), [ 'id' => $r->id ] ); // phpcs:ignore
					$sent += $ok ? 1 : 0;
				} else {
					$wpdb->update( self::table(), [ 'attempts' => (int) $r->attempts + 1, 'last_error' => mb_substr( $err, 0, 1000 ) ], [ 'id' => $r->id ] ); // phpcs:ignore
				}
			}
		} catch ( \Throwable $e ) {
			// leave remaining rows for the next run
		} finally {
			delete_transient( 'mailroom_drain_lock' );
			$this->sync_schedule();
		}
		return $sent;
	}

	public function settings_rows( array $o ) : void {
		?>
		<h2><?php esc_html_e( 'Rate limiting', 'mailroom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Over the cap, emails are queued and sent by a 5-minute cron within the cap. If queuing fails, the email is sent immediately (never lost).', 'mailroom' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="mailroom-rate-enabled"><?php esc_html_e( 'Rate limit', 'mailroom' ); ?></label></th><td><label for="mailroom-rate-enabled"><input type="checkbox" id="mailroom-rate-enabled" name="rate_enabled" value="1" data-mailroom-toggle="rate" <?php checked( $o['rate_enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'mailroom' ); ?></label></td></tr>
			<tr data-mailroom-dep="rate"><th scope="row"><label for="mailroom-rate-max"><?php esc_html_e( 'Maximum emails', 'mailroom' ); ?></label></th><td><input type="number" min="1" class="small-text" id="mailroom-rate-max" name="rate_max" value="<?php echo esc_attr( $o['rate_max'] ); ?>"></td></tr>
			<tr data-mailroom-dep="rate"><th scope="row"><label for="mailroom-rate-window"><?php esc_html_e( 'Per window (minutes)', 'mailroom' ); ?></label></th><td><input type="number" min="1" max="1440" class="small-text" id="mailroom-rate-window" name="rate_window" value="<?php echo esc_attr( $o['rate_window'] ); ?>"></td></tr>
			<tr data-mailroom-dep="rate"><th scope="row"><label for="mailroom-rate-exempt"><?php esc_html_e( 'Never queue subjects containing', 'mailroom' ); ?></label></th><td><textarea id="mailroom-rate-exempt" name="rate_exempt" rows="3" class="large-text code"><?php echo esc_textarea( $o['rate_exempt'] ); ?></textarea><p class="description"><?php esc_html_e( 'One per line, case-insensitive. Keep password resets here so logins are never delayed.', 'mailroom' ); ?></p></td></tr>
		</table>
		<?php
	}

	/** Rendered after (outside) the settings form: the queue and its own POST form. */
	public function action_rows( array $o ) : void {
		$q = self::queue_count();
		?>
		<div class="mailroom-actions-box">
			<h2><?php esc_html_e( 'Queue', 'mailroom' ); ?></h2>
			<p><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d email waiting.', '%d emails waiting.', $q, 'mailroom' ), $q ) ); ?></p>
			<?php if ( $q ) : ?>
				<?php echo Mailroom_UI::action_form( 'mailroom_drain_now', 'mailroom_drain_now', __( 'Process queue now', 'mailroom' ), 'ratelimit' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in action_form() ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function sanitize( array $acc, array $post, string $section = '' ) : array {
		if ( $section !== '' && $section !== self::SECTION && $section !== self::NATIVE_SECTION ) {
			return $acc;
		}
		[ $o, $ok ]        = $acc;
		$o['rate_enabled'] = empty( $post['rate_enabled'] ) ? 0 : 1;
		$o['rate_max']     = max( 1, (int) ( $post['rate_max'] ?? 60 ) );
		$o['rate_window']  = min( 1440, max( 1, (int) ( $post['rate_window'] ?? 60 ) ) );
		$lines             = array_filter( array_map( 'trim', preg_split( '/\R/', sanitize_textarea_field( wp_unslash( (string) ( $post['rate_exempt'] ?? '' ) ) ) ) ), 'strlen' );
		$o['rate_exempt']  = implode( "\n", array_slice( array_unique( $lines ), 0, 50 ) );
		return [ $o, $ok ];
	}

	public function handle_drain_now() : void {
		if ( ! current_user_can( Mailroom::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mailroom' ) );
		}
		Mailroom_UI::require_post();
		check_admin_referer( 'mailroom_drain_now' );
		$n = $this->drain();
		Mailroom_UI::finish( sprintf( 'Queue processed: %d sent, %d still waiting.', $n, self::queue_count() ), 'ratelimit' );
	}
}
