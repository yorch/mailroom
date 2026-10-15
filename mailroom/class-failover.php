<?php
/**
 * Backup-SMTP failover (best-effort): on `mailroom_send_failed`, re-send once through a
 * second SMTP host with a raw PHPMailer (which fires no WP Mail SMTP hooks, so it can't recurse).
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Failover {

	/** @var Mailroom */
	private $core;

	/** Failover runs at most once per request, plus a transient circuit breaker. */
	private $attempts = 0;

	public function __construct( Mailroom $core ) {
		$this->core = $core;
	}

	public function boot() : void {
		add_action( 'mailroom_send_failed', [ $this, 'on_failed' ], 20, 2 );
	}

	public function on_failed( $mailcatcher = null, $error = '' ) : void {
		$error = (string) $error;
		try {
			if ( ! Mailroom::opt( 'backup_enabled' ) ) {
				return;
			}
			// A routed email already went to the backup host; failing over would just retry the same host.
			if ( apply_filters( 'mailroom_skip_failover', false ) ) {
				return;
			}
			// Partial acceptance: the primary transport delivered to some recipients and rejected
			// others. Re-sending would duplicate to those who already received it. (The plugin's
			// $failed_recipients is private, so match its message instead of reading internals.)
			if (
				stripos( $error, 'accepted the message' ) !== false ||
				stripos( $error, 'Other recipients' ) !== false ||
				stripos( $error, 'recipients failed' ) !== false
			) {
				return;
			}
			if ( $this->attempts >= 1 ) {
				return; // at most one failover per request (bounds added latency)
			}
			if ( get_transient( 'mailroom_backup_down' ) ) {
				return; // circuit breaker: recent backup failure — don't retry for the window
			}
			$host = trim( (string) Mailroom::opt( 'backup_host' ) );
			if ( $host === '' || ! is_object( $mailcatcher ) || ! class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
				return;
			}
			$to = $mailcatcher->getToAddresses();
			if ( empty( $to ) ) {
				return;
			}
			// A backup From is required: reusing the primary's (e.g. Gmail) address on another relay
			// breaks DMARC and lands in spam, so don't attempt a doomed send.
			$bk_from = (string) Mailroom::opt( 'backup_from' );
			if ( $bk_from === '' || ! is_email( $bk_from ) ) {
				$this->core->insert( [
					'status'   => 'failed_backup',
					'source'   => 'backup',
					'mailer'   => 'backup-smtp',
					'to_email' => Mailroom::fmt_addresses( $to ),
					'subject'  => (string) $mailcatcher->Subject,
					'error'    => 'failover skipped: backup From not configured (use an address on a domain that authorises the backup host)',
				] );
				return;
			}

			$this->attempts++;
			$pm = new \PHPMailer\PHPMailer\PHPMailer( true );
			$pm->isSMTP();
			$pm->Host       = $host;
			$pm->Port       = (int) Mailroom::opt( 'backup_port' );
			$pm->SMTPAuth   = (bool) Mailroom::opt( 'backup_user' );
			$pm->Username   = (string) Mailroom::opt( 'backup_user' );
			$pm->Password   = Mailroom::decrypt_secret( (string) Mailroom::opt( 'backup_pass' ) );
			$pm->SMTPSecure = (string) Mailroom::opt( 'backup_secure' );
			$pm->Timeout    = 8;
			$pm->CharSet    = 'UTF-8';
			// Timeout is per-socket; SMTP::$Timelimit defaults to 300s and is the real ceiling.
			$pm->getSMTPInstance()->Timelimit = 15;

			$pm->setFrom( $bk_from, (string) $mailcatcher->FromName );
			// Preserve the message shape the primary was given.
			foreach ( (array) $mailcatcher->getReplyToAddresses() as $a ) {
				if ( ! empty( $a[0] ) ) { $pm->addReplyTo( $a[0], $a[1] ?? '' ); }
			}
			foreach ( (array) $mailcatcher->getCustomHeaders() as $h ) {
				if ( is_array( $h ) && isset( $h[0] ) ) { $pm->addCustomHeader( (string) $h[0], (string) ( $h[1] ?? '' ) ); }
			}
			foreach ( (array) $mailcatcher->getAttachments() as $a ) {
				// Only file-backed attachments can be re-added; string attachments expose raw data in [0].
				if ( is_array( $a ) && ! empty( $a[0] ) && is_readable( $a[0] ) ) {
					$pm->addAttachment( (string) $a[0], (string) ( $a[1] ?? '' ), (string) ( $a[2] ?? 'base64' ), (string) ( $a[3] ?? '' ) );
				}
			}
			foreach ( $to as $a ) {
				if ( ! empty( $a[0] ) ) { $pm->addAddress( $a[0], $a[1] ?? '' ); }
			}
			foreach ( (array) $mailcatcher->getCcAddresses() as $a ) {
				if ( ! empty( $a[0] ) ) { $pm->addCC( $a[0], $a[1] ?? '' ); }
			}
			foreach ( (array) $mailcatcher->getBccAddresses() as $a ) {
				if ( ! empty( $a[0] ) ) { $pm->addBCC( $a[0], $a[1] ?? '' ); }
			}
			$pm->Subject = (string) $mailcatcher->Subject;
			$pm->isHTML( stripos( (string) $mailcatcher->ContentType, 'html' ) !== false );
			$pm->Body    = (string) $mailcatcher->Body;
			$pm->AltBody = (string) $mailcatcher->AltBody;

			$ok = $pm->send();

			$this->core->insert( [
				'status'   => $ok ? 'sent_backup' : 'failed_backup',
				'source'   => 'backup',
				'mailer'   => 'backup-smtp',
				'to_email' => Mailroom::fmt_addresses( $to ),
				'subject'  => (string) $mailcatcher->Subject,
				'error'    => $ok ? null : ( 'primary failed: ' . $error . ' | backup: ' . $pm->ErrorInfo ),
			] );
			if ( ! $ok ) {
				set_transient( 'mailroom_backup_down', 1, 600 );
			}
		} catch ( \Throwable $e ) {
			$this->core->insert( [
				'status'   => 'failed_backup',
				'source'   => 'backup',
				'mailer'   => 'backup-smtp',
				'to_email' => is_object( $mailcatcher ) ? Mailroom::fmt_addresses( $mailcatcher->getToAddresses() ) : '',
				'subject'  => is_object( $mailcatcher ) ? (string) $mailcatcher->Subject : '',
				'error'    => 'primary failed: ' . $error . ' | backup exception: ' . $e->getMessage(),
			] );
			set_transient( 'mailroom_backup_down', 1, 600 );
		}
	}
}
