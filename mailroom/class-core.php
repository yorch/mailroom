<?php
/**
 * Core: options, schema, the log writer, retention, and module wiring.
 *
 * Modules receive the core in their constructor and register their own hooks in boot().
 * Cross-module events go through actions (e.g. `mailroom_send_failed`) so a module
 * can be removed without editing the others.
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom {

	const OPT        = 'mailroom';
	const DB_VER_OPT = 'mailroom_db_version';
	const DB_VER     = '2';
	const CRON       = 'mailroom_prune';
	const CAP        = 'manage_options';

	/** Module slug => class. Only modules whose class is loaded are wired. */
	const MODULES = [
		'logger'   => 'Mailroom_Logger',
		'alerts'   => 'Mailroom_Alerts',
		'content'  => 'Mailroom_Content',
		'controls' => 'Mailroom_Controls',
		'reports'  => 'Mailroom_Reports',
		'ratelimit' => 'Mailroom_RateLimit',
		'routing'  => 'Mailroom_Routing',
		'failover' => 'Mailroom_Failover',
		'upsells'  => 'Mailroom_Upsells',
		'admin'    => 'Mailroom_Admin',
	];

	/** @var Mailroom|null */
	private static $instance = null;

	/** @var array<string,object> */
	private $modules = [];

	public static function instance() : Mailroom {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'boot' ], 20 );
	}

	public function boot() : void {
		// WP Mail SMTP must be present; if not, do nothing at all.
		if ( ! function_exists( 'wp_mail_smtp' ) ) {
			return;
		}

		$this->maybe_install();

		foreach ( self::MODULES as $slug => $class ) {
			if ( class_exists( $class ) ) {
				$this->modules[ $slug ] = new $class( $this );
				$this->modules[ $slug ]->boot();
			}
		}

		// --- retention ----------------------------------------------------
		add_action( self::CRON, [ $this, 'prune' ] );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** @return object|null */
	public function module( string $slug ) {
		return $this->modules[ $slug ] ?? null;
	}

	/* ---------------------------------------------------------------------
	 * Options
	 * ------------------------------------------------------------------ */

	public static function defaults() : array {
		return [
			'retention_days' => 30,
			'log_body'       => 0,
			'alert_webhook'  => '',
			'alert_format'   => 'auto',
			'alert_email'    => '',
			'alert_from'     => '',
			'alert_throttle' => 300,
			'backup_enabled' => 0,
			'backup_host'    => '',
			'backup_port'    => 587,
			'backup_secure'  => 'tls',
			'backup_user'    => '',
			'backup_pass'    => '',
			'backup_from'    => '',
			'store_content'  => 0,
			'content_days'   => 7,
			'controls_off'   => [],
			'report_enabled' => 0,
			'report_email'   => '',
			'rate_enabled'   => 0,
			'rate_max'       => 60,
			'rate_window'    => 60,
			'rate_exempt'    => 'Password Reset',
			'route_enabled'  => 0,
			'route_rules'    => '',
		];
	}

	public static function opt( string $key ) {
		$o = wp_parse_args( (array) get_option( self::OPT, [] ), self::defaults() );
		return $o[ $key ] ?? null;
	}

	/** Reuse WP Mail SMTP's own crypto so the backup SMTP password is not stored plaintext. */
	public static function encrypt_secret( string $v ) : string {
		if ( $v === '' || ! class_exists( '\WPMailSMTP\Helpers\Crypto' ) ) {
			return $v;
		}
		try {
			return (string) \WPMailSMTP\Helpers\Crypto::encrypt( $v );
		} catch ( \Throwable $e ) {
			return $v;
		}
	}

	public static function decrypt_secret( string $v ) : string {
		if ( $v === '' || ! class_exists( '\WPMailSMTP\Helpers\Crypto' ) ) {
			return $v;
		}
		try {
			return (string) \WPMailSMTP\Helpers\Crypto::decrypt( $v );
		} catch ( \Throwable $e ) {
			return $v;
		}
	}

	/* ---------------------------------------------------------------------
	 * Schema
	 * ------------------------------------------------------------------ */

	/** Filterable so the test harness can work on its own table instead of the live log. */
	public static function table() : string {
		global $wpdb;
		return (string) apply_filters( 'mailroom_table', $wpdb->prefix . 'mailroom_log' );
	}

	public function maybe_install() : void {
		if ( get_option( self::DB_VER_OPT ) === self::DB_VER ) {
			return;
		}
		self::create_table( self::table() );
		global $wpdb;
		// Only stamp the version if the table actually exists, otherwise logging would stay dead
		// and never be retried.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) === self::table() ) {
			update_option( self::DB_VER_OPT, self::DB_VER, true );
		}
	}

	public static function create_table( string $table ) : void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			status varchar(16) NOT NULL,
			source varchar(8) NOT NULL,
			mailer varchar(32) NOT NULL DEFAULT '',
			to_email text NULL,
			cc_email text NULL,
			subject text NULL,
			body_preview text NULL,
			error text NULL,
			error_key varchar(191) NULL,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resent_of bigint(20) unsigned NOT NULL DEFAULT 0,
			content longtext NULL,
			mime longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status),
			KEY error_key (error_key)
		) {$collate};";
		dbDelta( $sql );
	}

	/* ---------------------------------------------------------------------
	 * Log writer (shared by every module)
	 * ------------------------------------------------------------------ */

	/** @return int Inserted row id, or 0. Never throws: logging must never break sending. */
	public function insert( array $row ) : int {
		try {
			global $wpdb;
			$row = wp_parse_args( $row, [
				'created_at'   => current_time( 'mysql' ),
				'status'       => 'sent',
				'source'       => '',
				'mailer'       => '',
				'to_email'     => '',
				'cc_email'     => '',
				'subject'      => '',
				'body_preview' => '',
				'error'        => null,
				'error_key'    => null,
				'duration_ms'  => 0,
				'user_id'      => get_current_user_id(),
			] );
			// wpdb silences "data too long" and drops the WHOLE row, so clamp anything unbounded.
			foreach ( [ 'to_email' => 6000, 'cc_email' => 2000, 'subject' => 2000, 'body_preview' => 1000, 'error' => 6000, 'error_key' => 191, 'status' => 16, 'source' => 8, 'mailer' => 32 ] as $k => $max ) {
				if ( isset( $row[ $k ] ) && is_string( $row[ $k ] ) && strlen( $row[ $k ] ) > $max ) {
					$row[ $k ] = mb_strcut( $row[ $k ], 0, $max );
				}
			}
			if ( $wpdb->insert( self::table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return (int) $wpdb->insert_id;
			}
			// Optional full content must never cost the metadata row: retry without it.
			if ( isset( $row['content'] ) || isset( $row['mime'] ) ) {
				unset( $row['content'], $row['mime'] );
				return $wpdb->insert( self::table(), $row ) ? (int) $wpdb->insert_id : 0; // phpcs:ignore
			}
			return 0;
		} catch ( \Throwable $e ) {
			return 0; // deliberately silent
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** Pull [email,name] pairs out of a PHPMailer-style address array. */
	public static function fmt_addresses( $addresses ) : string {
		if ( ! is_array( $addresses ) ) {
			return '';
		}
		$out = [];
		foreach ( $addresses as $a ) {
			if ( is_array( $a ) && ! empty( $a[0] ) ) {
				$out[] = (string) $a[0];
			} elseif ( is_string( $a ) ) {
				$out[] = $a;
			}
		}
		return implode( ', ', array_unique( $out ) );
	}

	/** Redact email addresses so alerts never carry customer PII. */
	public static function mask_emails( string $s ) : string {
		return (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '***@***', $s );
	}

	public static function current_mailer() : string {
		if ( function_exists( 'wp_mail_smtp' ) && method_exists( wp_mail_smtp(), 'get_connections_manager' ) ) {
			$conn = wp_mail_smtp()->get_connections_manager()->get_mail_connection();
			if ( $conn && method_exists( $conn, 'get_mailer_slug' ) ) {
				return (string) $conn->get_mailer_slug();
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Retention
	 * ------------------------------------------------------------------ */

	public function prune() : void {
		$days = (int) self::opt( 'retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		global $wpdb;
		// created_at is stored with current_time('mysql') (site local), so the cutoff must be local wall-clock too.
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff ) ); // phpcs:ignore
	}
}
