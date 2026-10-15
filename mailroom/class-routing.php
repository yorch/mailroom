<?php
/**
 * Smart Routing (lite): send emails that match a rule through the backup SMTP connection instead of
 * the primary mailer.
 *
 * Mechanism: the decision is made on `pre_wp_mail` (after WP Mail SMTP has recorded the wp_mail()
 * initiator); for that one email, WP Mail SMTP's option getters are filtered to mailer=smtp with the
 * backup host/credentials and a forced backup From. WP Mail SMTP then builds and sends the message
 * itself (full fidelity: attachments, headers) and its hooks log it as usual (`source` = route).
 * The flag is cleared on wp_mail_succeeded/failed, and if a later pre_wp_mail short-circuits the send.
 *
 * Rules, one per line, `field: text` (case-insensitive "contains"):
 *   subject: Order        to: @example.com        initiator: Gravity Forms        header: X-Mailer
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Routing {

	const SECTION        = 'delivery';
	const NATIVE_SECTION = 'routing'; // the same keys, saved from the Smart Routing tab inside WP Mail SMTP
	const FIELDS = [ 'subject', 'to', 'initiator', 'header' ];

	/** @var Mailroom */
	private $core;

	/** @var bool the email in progress is routed */
	private $active = false;

	public function __construct( Mailroom $core ) {
		$this->core = $core;
	}

	public function boot() : void {
		add_filter( 'pre_wp_mail', [ $this, 'decide' ], 1, 2 );
		add_filter( 'pre_wp_mail', [ $this, 'after_short_circuit' ], PHP_INT_MAX, 1 );
		add_action( 'wp_mail_succeeded', [ $this, 'reset' ] );
		add_action( 'wp_mail_failed', [ $this, 'reset' ] );
		add_filter( 'wp_mail_smtp_options_get', [ $this, 'override_option' ], PHP_INT_MAX, 3 );
		add_filter( 'wp_mail_smtp_options_get_group', [ $this, 'override_group' ], PHP_INT_MAX, 2 );
		add_filter( 'mailroom_log_row', [ $this, 'mark_row' ], 20, 1 );
		add_filter( 'mailroom_skip_failover', [ $this, 'is_active' ] );
		add_action( 'mailroom_settings_delivery', [ $this, 'settings_rows' ], 10 );
		add_action( 'mailroom_settings_routing', [ $this, 'settings_rows' ], 10 );
		add_filter( 'mailroom_sanitize', [ $this, 'sanitize' ], 10, 3 );
	}

	public function is_active() : bool {
		return $this->active;
	}

	public function reset() : void {
		$this->active = false;
	}

	/** Routing needs a usable backup connection with its own From (DMARC). */
	public static function backup_ready() : bool {
		return trim( (string) Mailroom::opt( 'backup_host' ) ) !== '' && is_email( (string) Mailroom::opt( 'backup_from' ) );
	}

	/** @return array<int,array{0:string,1:string}> parsed [field, needle] rules */
	public static function rules() : array {
		$out = [];
		foreach ( preg_split( '/\R/', (string) Mailroom::opt( 'route_rules' ) ) as $line ) {
			if ( preg_match( '/^\s*(subject|to|initiator|header)\s*:\s*(.+?)\s*$/i', $line, $m ) ) {
				$out[] = [ strtolower( $m[1] ), $m[2] ];
			}
		}
		return $out;
	}

	public static function initiator() : string {
		try {
			if ( function_exists( 'wp_mail_smtp' ) && method_exists( wp_mail_smtp(), 'get_wp_mail_initiator' ) ) {
				return (string) wp_mail_smtp()->get_wp_mail_initiator()->get_name();
			}
		} catch ( \Throwable $e ) {
			// unknown initiator
		}
		return '';
	}

	public static function matches( array $atts, string $initiator ) : bool {
		$hay = [
			'subject'   => (string) ( $atts['subject'] ?? '' ),
			'to'        => is_array( $atts['to'] ?? '' ) ? implode( ', ', $atts['to'] ) : (string) ( $atts['to'] ?? '' ),
			'initiator' => $initiator,
			'header'    => is_array( $atts['headers'] ?? '' ) ? implode( "\n", $atts['headers'] ) : (string) ( $atts['headers'] ?? '' ),
		];
		foreach ( self::rules() as [ $field, $needle ] ) {
			if ( stripos( $hay[ $field ], $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/** pre_wp_mail priority 1: decide, never short-circuit. */
	public function decide( $return, $atts ) {
		$this->active = false;
		try {
			if ( $return === null && Mailroom::opt( 'route_enabled' ) && self::backup_ready() && is_array( $atts ) ) {
				$this->active = self::matches( $atts, self::initiator() );
			}
		} catch ( \Throwable $e ) {
			$this->active = false; // on any doubt, use the primary
		}
		return $return;
	}

	/** If something (e.g. the rate-limit queue) short-circuited the send, don't leave the flag set. */
	public function after_short_circuit( $return ) {
		if ( $return !== null ) {
			$this->active = false;
		}
		return $return;
	}

	private function smtp_values() : array {
		return [
			'host'       => trim( (string) Mailroom::opt( 'backup_host' ) ),
			'port'       => (int) Mailroom::opt( 'backup_port' ),
			'encryption' => (string) Mailroom::opt( 'backup_secure' ) ?: 'none',
			'autotls'    => true,
			'auth'       => (bool) Mailroom::opt( 'backup_user' ),
			'user'       => (string) Mailroom::opt( 'backup_user' ),
			'pass'       => Mailroom::decrypt_secret( (string) Mailroom::opt( 'backup_pass' ) ),
		];
	}

	public function override_option( $value, $group = '', $key = '' ) {
		if ( ! $this->active ) {
			return $value;
		}
		if ( $group === 'mail' ) {
			switch ( $key ) {
				case 'mailer':
					return 'smtp';
				case 'from_email':
					return (string) Mailroom::opt( 'backup_from' );
				case 'from_email_force':
					return true;
			}
		}
		if ( $group === 'smtp' ) {
			$v = $this->smtp_values();
			if ( array_key_exists( $key, $v ) ) {
				return $v[ $key ];
			}
		}
		return $value;
	}

	public function override_group( $opts, $group = '' ) {
		return ( $this->active && $group === 'smtp' ) ? array_merge( (array) $opts, $this->smtp_values() ) : $opts;
	}

	public function mark_row( array $row ) : array {
		if ( $this->active ) {
			$row['source'] = 'route';
		}
		return $row;
	}

	public function settings_rows( array $o ) : void {
		?>
		<h2><?php esc_html_e( 'Smart Routing', 'mailroom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Send emails that match a rule through the backup SMTP connection instead of the primary mailer. Needs a backup host and a backup From address (the backup SMTP connection).', 'mailroom' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="mailroom-route-enabled"><?php esc_html_e( 'Smart Routing', 'mailroom' ); ?></label></th><td><label for="mailroom-route-enabled"><input type="checkbox" id="mailroom-route-enabled" name="route_enabled" value="1" data-mailroom-toggle="route" <?php checked( $o['route_enabled'] ); ?>> <?php echo wp_kses( __( 'Send matching emails through the <strong>backup SMTP</strong> connection', 'mailroom' ), [ 'strong' => [] ] ); ?></label>
				<?php if ( ! self::backup_ready() ) : ?><p class="description" style="color:#b32d2e"><?php esc_html_e( 'Needs a backup host and a backup From (the backup SMTP connection) before it can route anything.', 'mailroom' ); ?></p><?php endif; ?></td></tr>
			<tr data-mailroom-dep="route"><th scope="row"><label for="mailroom-route-rules"><?php esc_html_e( 'Routing rules', 'mailroom' ); ?></label></th><td><textarea id="mailroom-route-rules" name="route_rules" rows="4" class="large-text code" placeholder="subject: Order&#10;initiator: Gravity Forms&#10;to: @example.com"><?php echo esc_textarea( $o['route_rules'] ); ?></textarea><p class="description"><?php echo wp_kses( __( 'One per line: <code>subject|to|initiator|header: text</code>, case-insensitive "contains". Any match routes the email. <code>initiator</code> is the plugin/theme that called wp_mail (as WP Mail SMTP names it).', 'mailroom' ), [ 'code' => [] ] ); ?></p></td></tr>
		</table>
		<?php
	}

	public function sanitize( array $acc, array $post, string $section = '' ) : array {
		if ( $section !== '' && $section !== self::SECTION && $section !== self::NATIVE_SECTION ) {
			return $acc;
		}
		[ $o, $ok ]         = $acc;
		$o['route_enabled'] = empty( $post['route_enabled'] ) ? 0 : 1;
		$keep               = [];
		foreach ( preg_split( '/\R/', sanitize_textarea_field( wp_unslash( (string) ( $post['route_rules'] ?? '' ) ) ) ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			if ( preg_match( '/^(subject|to|initiator|header)\s*:\s*\S/i', $line ) ) {
				$keep[] = $line;
			} else {
				$ok = false; // malformed rule dropped and reported
			}
		}
		$o['route_rules'] = implode( "\n", array_slice( $keep, 0, 50 ) );
		return [ $o, $ok ];
	}
}
