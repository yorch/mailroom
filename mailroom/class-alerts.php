<?php
/**
 * Failure alerts: on `mailroom_send_failed`, notify a webhook (Slack / Discord / Teams /
 * generic JSON) and/or email addresses. One throttle window covers all channels.
 *
 * - Payloads are PII-reduced: no recipients or subject; email addresses in the error are masked.
 * - Each service needs its own body shape. Slack rejects a body without `text` (400 no_text), Discord
 *   one without `content`, and Teams Workflows expects an Adaptive Card. With a non-blocking POST a
 *   wrong shape fails silently, which is why the format is detected from the URL and a "Send test
 *   alert" button does a *blocking* POST and shows the HTTP status.
 * - Alert emails go through PHP mail() (the local MTA), NOT wp_mail(): when the primary mailer is the
 *   thing failing, an alert sent through it would fail too (and would re-enter these hooks).
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Alerts {

	const SECTION = 'alerts';
	const FORMATS = [ 'auto', 'slack', 'discord', 'teams', 'generic' ];

	/** @var Mailroom */
	private $core;

	public function __construct( Mailroom $core ) {
		$this->core = $core;
	}

	public function boot() : void {
		add_action( 'mailroom_send_failed', [ $this, 'on_failed' ], 10, 4 );
		add_action( 'mailroom_settings_alerts', [ $this, 'settings_rows' ] );
		add_action( 'mailroom_actions_alerts', [ $this, 'action_rows' ] );
		add_filter( 'mailroom_sanitize', [ $this, 'sanitize' ], 10, 3 );
		add_action( 'admin_post_mailroom_test_alert', [ $this, 'handle_test' ] );
	}

	public function on_failed( $mailcatcher = null, $error = '', $mailer_slug = '', $error_key = '' ) : void {
		try {
			if ( ! $this->has_channel() ) {
				return;
			}
			// Throttle: one alert per window across all channels, otherwise an outage produces an alert storm.
			$window = max( 0, (int) Mailroom::opt( 'alert_throttle' ) );
			if ( $window > 0 && get_transient( 'mailroom_alert_throttle' ) ) {
				return;
			}
			if ( $window > 0 ) {
				set_transient( 'mailroom_alert_throttle', 1, $window );
			}
			$event = [
				'event'           => 'email_send_failed',
				'mailer'          => (string) $mailer_slug,
				'error_key'       => (string) $error_key,
				'recipient_count' => ( is_object( $mailcatcher ) && is_array( $mailcatcher->getToAddresses() ) ) ? count( $mailcatcher->getToAddresses() ) : 0,
				'error'           => Mailroom::mask_emails( mb_substr( (string) $error, 0, 500 ) ),
			];
			$this->dispatch( $event, false );
		} catch ( \Throwable $e ) {
			// alerts are best-effort
		}
	}

	private function has_channel() : bool {
		return self::webhook_url() !== '' || self::recipients() !== [];
	}

	private static function webhook_url() : string {
		$url = trim( (string) Mailroom::opt( 'alert_webhook' ) );
		// https only: the payload can describe customer mail. (Shape check rather than
		// wp_http_validate_url(), which does a DNS lookup on every failed send.)
		return stripos( $url, 'https://' ) === 0 ? $url : '';
	}

	/** @return string[] */
	private static function recipients() : array {
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', (string) Mailroom::opt( 'alert_email' ) ) as $a ) {
			if ( $a !== '' && is_email( $a ) ) {
				$out[] = $a;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Send an event to every configured channel.
	 *
	 * @param bool $blocking true for the test button (report the real HTTP status), false on the send path.
	 * @return array{webhook:?string,email:?string} human-readable result per channel (null = not configured)
	 */
	public function dispatch( array $event, bool $blocking ) : array {
		$event  = array_merge( [
			'site' => home_url(),
			'when' => current_time( 'mysql' ),
			'log'  => admin_url( 'admin.php?page=mailroom-log' ),
		], $event );
		$result = [ 'webhook' => null, 'email' => null ];

		$url = self::webhook_url();
		if ( $url !== '' ) {
			try {
				$format = self::format_for( $url, (string) Mailroom::opt( 'alert_format' ) );
				$res    = wp_remote_post( $url, [
					'timeout'  => 8,
					'blocking' => $blocking,
					'headers'  => [ 'Content-Type' => 'application/json' ],
					'body'     => wp_json_encode( self::payload( $format, $event ) ),
				] );
				if ( is_wp_error( $res ) ) {
					$result['webhook'] = $format . ': error ' . $res->get_error_message();
				} else {
					$code              = (int) wp_remote_retrieve_response_code( $res );
					$result['webhook'] = $format . ': ' . ( $blocking ? 'HTTP ' . $code : 'queued (non-blocking)' );
				}
			} catch ( \Throwable $e ) {
				$result['webhook'] = 'exception: ' . $e->getMessage();
			}
		}

		$to = self::recipients();
		if ( $to ) {
			try {
				$ok              = $this->send_email( $to, self::subject( $event ), self::text( $event ) );
				$result['email'] = ( $ok ? 'handed to local MTA: ' : 'mail() returned false: ' ) . implode( ', ', $to );
			} catch ( \Throwable $e ) {
				$result['email'] = 'exception: ' . $e->getMessage();
			}
		}
		return $result;
	}

	/** Auto-detect the service from the URL unless a format was chosen explicitly. */
	public static function format_for( string $url, string $chosen ) : string {
		if ( $chosen !== '' && $chosen !== 'auto' && in_array( $chosen, self::FORMATS, true ) ) {
			return $chosen;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $host === 'hooks.slack.com' ) {
			return 'slack';
		}
		if ( in_array( $host, [ 'discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com' ], true ) && strpos( $path, '/api/webhooks/' ) === 0 ) {
			return 'discord';
		}
		foreach ( [ '.webhook.office.com', '.logic.azure.com', '.powerplatform.com', '.powerautomate.com' ] as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return 'teams';
			}
		}
		return 'generic';
	}

	public static function payload( string $format, array $event ) : array {
		$text = self::text( $event );
		switch ( $format ) {
			case 'slack':
				return [ 'text' => $text ];
			case 'discord':
				// Discord caps `content` at 2000 chars and would otherwise reject the message.
				return [ 'content' => mb_substr( $text, 0, 1900 ), 'allowed_mentions' => [ 'parse' => [] ] ];
			case 'teams':
				return [
					'type'        => 'message',
					'attachments' => [ [
						'contentType' => 'application/vnd.microsoft.card.adaptive',
						'content'     => [
							'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
							'type'    => 'AdaptiveCard',
							'version' => '1.4',
							'body'    => [ [ 'type' => 'TextBlock', 'text' => $text, 'wrap' => true ] ],
						],
					] ],
				];
			default:
				return $event;
		}
	}

	private static function subject( array $event ) : string {
		$host = (string) wp_parse_url( (string) $event['site'], PHP_URL_HOST );
		return $event['event'] === 'test'
			? sprintf( '[%s] Test alert from Mailroom', $host )
			: sprintf( '[%s] Email send failed (%s)', $host, $event['mailer'] ?: 'unknown mailer' );
	}

	/** One plain-text rendering shared by every channel. Contains no recipient or subject. */
	public static function text( array $event ) : string {
		if ( ( $event['event'] ?? '' ) === 'test' ) {
			return sprintf( "Test alert from Mailroom on %s (%s). If you can read this, failure alerts reach you.\nEmail log: %s", $event['site'], $event['when'], $event['log'] );
		}
		return sprintf(
			"Email send FAILED on %s at %s\nMailer: %s · Error key: %s · Recipients: %d\nError: %s\nEmail log: %s",
			$event['site'],
			$event['when'],
			$event['mailer'] ?: '-',
			$event['error_key'] ?: '-',
			(int) ( $event['recipient_count'] ?? 0 ),
			$event['error'] ?: '-',
			$event['log']
		);
	}

	/**
	 * Local-MTA email. `mailroom_pre_alert_email` short-circuits it (tests; returning non-null
	 * skips mail()).
	 */
	private function send_email( array $to, string $subject, string $body ) : bool {
		$pre = apply_filters( 'mailroom_pre_alert_email', null, $to, $subject, $body );
		if ( $pre !== null ) {
			return (bool) $pre;
		}
		$from = (string) Mailroom::opt( 'alert_from' );
		if ( $from === '' || ! is_email( $from ) ) {
			$from = 'wordpress@' . preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
		$headers = implode( "\r\n", [
			'From: Mailroom <' . $from . '>',
			'MIME-Version: 1.0',
			'Content-Type: text/plain; charset=UTF-8',
			'Auto-Submitted: auto-generated',
		] );
		$subject = '=?UTF-8?B?' . base64_encode( $subject ) . '?=';
		return (bool) mail( implode( ', ', $to ), $subject, $body, $headers, '-f' . $from );
	}

	/* ---------------------------------------------------------------------
	 * Settings + test button
	 * ------------------------------------------------------------------ */

	public function settings_rows( array $o ) : void {
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		?>
		<h2><?php esc_html_e( 'Failure alerts', 'mailroom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'When a send fails, notify a webhook and/or email addresses. At most one alert per throttle window across all channels. Alerts never contain recipients or the subject.', 'mailroom' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="mailroom-alert-webhook"><?php esc_html_e( 'Webhook URL', 'mailroom' ); ?></label></th><td><input type="url" class="regular-text" id="mailroom-alert-webhook" name="alert_webhook" value="<?php echo esc_attr( $o['alert_webhook'] ); ?>" placeholder="https://hooks.slack.com/..."><p class="description"><?php esc_html_e( 'https only. Slack, Discord, Teams (Workflows) or any JSON endpoint.', 'mailroom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="mailroom-alert-format"><?php esc_html_e( 'Webhook format', 'mailroom' ); ?></label></th><td><select id="mailroom-alert-format" name="alert_format">
				<?php foreach ( self::FORMATS as $f ) : ?>
					<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $o['alert_format'], $f ); ?>><?php echo esc_html( $f === 'auto' ? 'auto-detect from URL' : $f ); ?></option>
				<?php endforeach; ?>
			</select></td></tr>
			<tr><th scope="row"><label for="mailroom-alert-email"><?php esc_html_e( 'Alert email(s)', 'mailroom' ); ?></label></th><td><input type="text" class="regular-text" id="mailroom-alert-email" name="alert_email" value="<?php echo esc_attr( $o['alert_email'] ); ?>" placeholder="ops@example.com, other@example.com"><p class="description"><?php esc_html_e( "Sent through the server's local mail system, not the site mailer (so it still works when that is down).", 'mailroom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="mailroom-alert-from"><?php esc_html_e( 'Alert email From', 'mailroom' ); ?></label></th><td><input type="email" class="regular-text" id="mailroom-alert-from" name="alert_from" value="<?php echo esc_attr( $o['alert_from'] ); ?>" placeholder="wordpress@<?php echo esc_attr( $host ); ?>"></td></tr>
			<tr><th scope="row"><label for="mailroom-alert-throttle"><?php esc_html_e( 'Throttle (seconds)', 'mailroom' ); ?></label></th><td><input type="number" min="0" class="small-text" id="mailroom-alert-throttle" name="alert_throttle" value="<?php echo esc_attr( $o['alert_throttle'] ); ?>"><p class="description"><?php esc_html_e( 'At most one alert per window, across all channels.', 'mailroom' ); ?></p></td></tr>
		</table>
		<?php
	}

	/** Rendered after (outside) the settings form: the test button is its own POST form. */
	public function action_rows( array $o ) : void {
		?>
		<div class="mailroom-actions-box">
			<h2><?php esc_html_e( 'Test', 'mailroom' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Sends a test alert using the saved settings and reports the real webhook HTTP status. Save your changes first.', 'mailroom' ); ?></p>
			<?php echo Mailroom_UI::action_form( 'mailroom_test_alert', 'mailroom_test_alert', __( 'Send test alert', 'mailroom' ), 'alerts' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in action_form() ?>
		</div>
		<?php
	}

	/** @return array{0:array,1:bool} sanitised options and whether every value was accepted */
	public function sanitize( array $acc, array $post, string $section = '' ) : array {
		if ( $section !== '' && $section !== self::SECTION ) {
			return $acc;
		}
		[ $o, $ok ] = $acc;
		$webhook = esc_url_raw( wp_unslash( $post['alert_webhook'] ?? '' ) );
		if ( $webhook !== '' && stripos( $webhook, 'https://' ) !== 0 ) {
			$webhook = $o['alert_webhook']; // reject non-https: the alert payload can describe customer mail
			$ok      = false;
		}
		$o['alert_webhook']  = $webhook;
		$fmt                 = sanitize_key( wp_unslash( $post['alert_format'] ?? 'auto' ) );
		$o['alert_format']   = in_array( $fmt, self::FORMATS, true ) ? $fmt : 'auto';
		$o['alert_throttle'] = max( 0, (int) ( $post['alert_throttle'] ?? 300 ) );

		$emails = [];
		foreach ( preg_split( '/[\s,;]+/', (string) wp_unslash( $post['alert_email'] ?? '' ) ) as $a ) {
			if ( $a === '' ) {
				continue;
			}
			if ( is_email( $a ) ) {
				$emails[] = sanitize_email( $a );
			} else {
				$ok = false; // invalid addresses are dropped and reported
			}
		}
		$o['alert_email'] = implode( ', ', array_unique( $emails ) );
		$from             = sanitize_email( wp_unslash( $post['alert_from'] ?? '' ) );
		$o['alert_from']  = is_email( $from ) ? $from : '';
		return [ $o, $ok ];
	}

	public function handle_test() : void {
		if ( ! current_user_can( Mailroom::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mailroom' ) );
		}
		Mailroom_UI::require_post();
		check_admin_referer( 'mailroom_test_alert' );
		$r   = $this->has_channel() ? $this->dispatch( [ 'event' => 'test' ], true ) : [];
		$msg = $r ? implode( ' | ', array_filter( [
			$r['webhook'] !== null ? 'Webhook → ' . $r['webhook'] : null,
			$r['email'] !== null ? 'Email → ' . $r['email'] : null,
		] ) ) : 'No alert channel configured (save a webhook or an alert email first).';
		Mailroom_UI::finish( $msg, 'alerts' );
	}
}
