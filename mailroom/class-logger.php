<?php
/**
 * Logger: turns WP Mail SMTP's MailCatcher hooks into log rows, and announces failures.
 *
 * Hooks (verified against the plugin source):
 *   wp_mail_smtp_mailcatcher_smtp_send_after   success; SMTP/mail path (7 args)
 *   wp_mail_smtp_mailcatcher_send_after        API path; fires on EVERY attempt, success or not
 *   wp_mail_smtp_mailcatcher_send_failed       both paths, on failure (fired inside WPMS's own catch)
 *
 * On failure it fires `mailroom_send_failed( $mailcatcher, $error, $mailer_slug, $error_key )`
 * for the alerts and failover modules.
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Logger {

	/** @var Mailroom */
	private $core;

	/** @var float */
	private $send_started_at = 0.0;

	/** Captured in *_send_before: the *_send_after hooks pass the MIME-encoded body on the SMTP path. */
	private $last_subject = '';
	private $last_body    = '';

	/** The MailCatcher of the send in progress (for modules that read the built MIME afterwards). */
	private $last_catcher = null;

	public function __construct( Mailroom $core ) {
		$this->core = $core;
	}

	public function boot() : void {
		add_action( 'wp_mail_smtp_mailcatcher_smtp_pre_send_before', [ $this, 'mark_send_start' ] );
		add_action( 'wp_mail_smtp_mailcatcher_pre_send_before', [ $this, 'mark_send_start' ] );

		// SMTP / PHP-mail success (fires only on success, 7 args).
		add_action( 'wp_mail_smtp_mailcatcher_smtp_send_after', [ $this, 'log_smtp_sent' ], 10, 7 );
		// API mailer: fires on every attempt -> only trust it when the mailer says it was sent.
		add_action( 'wp_mail_smtp_mailcatcher_send_after', [ $this, 'log_api_after' ], 10, 2 );
		// Both paths, on failure.
		add_action( 'wp_mail_smtp_mailcatcher_send_failed', [ $this, 'log_failed' ], 10, 7 );
	}

	public function mark_send_start( $mailcatcher = null ) : void {
		$this->send_started_at = microtime( true );
		// The *_send_after hooks pass the MIME-encoded body on the SMTP path; capture the raw
		// Subject/Body here so previews are readable and identical across both paths.
		$this->last_catcher    = is_object( $mailcatcher ) ? $mailcatcher : null;
		if ( is_object( $mailcatcher ) ) {
			$this->last_subject = (string) ( $mailcatcher->Subject ?? '' );
			$this->last_body    = (string) ( $mailcatcher->Body ?? '' );
		}
	}

	private function duration_ms() : int {
		return $this->send_started_at > 0 ? (int) round( ( microtime( true ) - $this->send_started_at ) * 1000 ) : 0;
	}

	/** Primary rows pass through `mailroom_log_row` (e.g. the content module adds the full message). */
	private function row( array $row, $mailcatcher ) : array {
		try {
			$out = apply_filters( 'mailroom_log_row', $row, $mailcatcher );
			return is_array( $out ) ? $out : $row;
		} catch ( \Throwable $e ) {
			return $row;
		}
	}

	public function preview( $body ) : string {
		if ( ! Mailroom::opt( 'log_body' ) || ! is_string( $body ) || $body === '' ) {
			return '';
		}
		return self::redact_text( $body, 500 );
	}

	/** Plain-text, whitespace-collapsed, secrets masked, truncated. */
	public static function redact_text( string $body, int $max ) : string {
		$text = wp_strip_all_tags( $body );
		$text = preg_replace( '/\s+/', ' ', (string) $text );
		// Redact secrets that routinely appear in email links (password resets, tokens, nonces).
		$text = self::redact_secrets( (string) $text );
		return mb_substr( trim( (string) $text ), 0, $max );
	}

	public static function redact_secrets( string $text ) : string {
		return (string) preg_replace(
			'/([?&](?:key|token|password|pwd|secret|code|nonce|_wpnonce)=)[^&\s"\'<>]+/i',
			'$1***',
			$text
		);
	}

	/** SMTP/PHP-mail success: ( $is_sent, $to, $cc, $bcc, $subject, $body, $from ) */
	public function log_smtp_sent( $is_sent = false, $to = [], $cc = [], $bcc = [], $subject = '', $body = '', $from = [] ) : void {
		try {
			if ( $is_sent !== true ) {
				return; // never trust this hook for anything but success
			}
			$bcc_n = is_array( $bcc ) ? count( $bcc ) : 0;
			$this->core->insert( $this->row( [
				'status'       => 'sent',
				'source'       => 'smtp',
				'mailer'       => Mailroom::current_mailer(),
				'to_email'     => Mailroom::fmt_addresses( $to ),
				// Store a Bcc COUNT, not the addresses (Bcc recipients are PII and shouldn't be logged).
				'cc_email'     => trim( Mailroom::fmt_addresses( $cc ) . ( $bcc_n ? ' | bcc: ' . $bcc_n : '' ) ),
				'subject'      => $this->last_subject !== '' ? $this->last_subject : (string) $subject,
				'body_preview' => $this->preview( $this->last_body !== '' ? $this->last_body : (string) $body ),
				'duration_ms'  => $this->duration_ms(),
			], $this->last_catcher ) );
		} catch ( \Throwable $e ) {
			// never let logging break sending
		}
	}

	/** API mailer: fires ALWAYS, so only log when the mailer itself reports success. */
	public function log_api_after( $mailer = null, $mailcatcher = null ) : void {
		try {
			if ( ! is_object( $mailer ) || ! method_exists( $mailer, 'is_email_sent' ) || ! is_object( $mailcatcher ) ) {
				return;
			}
			if ( $mailer->is_email_sent() !== true ) {
				return; // defer to log_failed (the success hook never runs for a failed send)
			}
			$this->core->insert( $this->row( [
				'status'       => 'sent',
				'source'       => 'api',
				'mailer'       => Mailroom::current_mailer(),
				'to_email'     => Mailroom::fmt_addresses( $mailcatcher->getToAddresses() ),
				'cc_email'     => Mailroom::fmt_addresses( $mailcatcher->getCcAddresses() ),
				'subject'      => (string) $mailcatcher->Subject,
				'body_preview' => $this->preview( $mailcatcher->Body ),
				'duration_ms'  => $this->duration_ms(),
			], $mailcatcher ) );
		} catch ( \Throwable $e ) {
			// never let logging break sending
		}
	}

	/** ( $error, $mailcatcher, $mailer_slug, $error_code, $response_code, $connection, $error_key ) */
	public function log_failed( $error = '', $mailcatcher = null, $mailer_slug = '', $error_code = '', $response_code = 0, $connection = null, $error_key = '' ) : void {
		try {
			$err = is_string( $error ) ? $error : ( is_scalar( $error ) ? (string) $error : (string) wp_json_encode( $error ) );

			$this->core->insert( $this->row( [
				'status'       => 'failed',
				'source'       => in_array( $mailer_slug, [ 'mail', 'smtp', 'pepipost' ], true ) ? 'smtp' : 'api',
				'mailer'       => (string) $mailer_slug,
				'to_email'     => is_object( $mailcatcher ) ? Mailroom::fmt_addresses( $mailcatcher->getToAddresses() ) : '',
				'cc_email'     => is_object( $mailcatcher ) ? Mailroom::fmt_addresses( $mailcatcher->getCcAddresses() ) : '',
				'subject'      => is_object( $mailcatcher ) ? (string) $mailcatcher->Subject : '',
				'body_preview' => is_object( $mailcatcher ) ? $this->preview( (string) $mailcatcher->Body ) : '',
				'error'        => $err,
				'error_key'    => (string) $error_key,
				'duration_ms'  => $this->duration_ms(),
			], $mailcatcher ) );

			// Alerts (priority 10) then failover (priority 20). Each listener guards itself too.
			do_action( 'mailroom_send_failed', $mailcatcher, $err, (string) $mailer_slug, (string) $error_key );
		} catch ( \Throwable $e ) {
			// never let logging/alerting break the (already-failing) send path
		}
	}
}
