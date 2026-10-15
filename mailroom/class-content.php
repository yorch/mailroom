<?php
/**
 * Full-email storage (opt-in) → per-email detail view, Resend, and EML download.
 *
 * - Captures the final wp_mail() arguments on the `wp_mail` filter (what Resend replays), and the exact
 *   MIME that was sent (PHPMailer::getSentMIMEMessage(), valid after preSend() on both the SMTP and the
 *   Gmail API path) for EML.
 * - OFF by default. When on, content is kept for `content_days` (default 7) and then wiped by the daily
 *   prune; the metadata row stays for the normal retention.
 * - The view shows a redacted plain-text body (reset keys/tokens masked). Resend and EML use the raw
 *   stored content: that's the point of them, and they're admin + nonce only.
 * - Size-capped (`MAX_BYTES`); if an insert with content still fails, the core retries without it,
 *   so storing content can never lose the log row.
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Content {

	const SECTION   = 'storage';
	const MAX_BYTES = 5242880; // 5 MB per field

	/** @var Mailroom */
	private $core;

	/** @var array|null wp_mail() args of the send in progress */
	private $pending = null;

	/** @var int log id being resent (stamped on the new row) */
	private $resending = 0;

	public function __construct( Mailroom $core ) {
		$this->core = $core;
	}

	public function boot() : void {
		add_filter( 'wp_mail', [ $this, 'capture_args' ], PHP_INT_MAX - 1 );
		add_action( 'wp_mail_succeeded', [ $this, 'clear' ] );
		add_action( 'wp_mail_failed', [ $this, 'clear' ] );
		add_filter( 'mailroom_log_row', [ $this, 'add_content' ], 10, 2 );
		add_action( Mailroom::CRON, [ $this, 'purge' ] );
		add_action( 'mailroom_settings_storage', [ $this, 'settings_rows' ], 10 );
		add_filter( 'mailroom_sanitize', [ $this, 'sanitize' ], 10, 3 );
		add_action( 'admin_post_mailroom_resend', [ $this, 'handle_resend' ] );
		add_action( 'admin_post_mailroom_eml', [ $this, 'handle_eml' ] );
	}

	/* ---------------------------------------------------------------------
	 * Capture
	 * ------------------------------------------------------------------ */

	/** Filter: never changes the args, only remembers them. */
	public function capture_args( $atts ) {
		try {
			$this->pending = null;
			if ( Mailroom::opt( 'store_content' ) && is_array( $atts ) ) {
				$att = $atts['attachments'] ?? [];
				if ( ! is_array( $att ) ) {
					$att = $att === '' ? [] : explode( "\n", str_replace( "\r\n", "\n", (string) $att ) );
				}
				$this->pending = [
					'to'          => $atts['to'] ?? '',
					'subject'     => (string) ( $atts['subject'] ?? '' ),
					'message'     => (string) ( $atts['message'] ?? '' ),
					'headers'     => $atts['headers'] ?? '',
					'attachments' => array_values( array_map( 'strval', $att ) ),
				];
			}
		} catch ( \Throwable $e ) {
			$this->pending = null;
		}
		return $atts;
	}

	public function clear() : void {
		$this->pending = null;
	}

	/** Filter on the logger's primary rows: add content/mime/resent_of. */
	public function add_content( array $row, $mailcatcher = null ) : array {
		try {
			if ( $this->resending ) {
				$row['resent_of'] = $this->resending;
			}
			if ( ! Mailroom::opt( 'store_content' ) || $this->pending === null ) {
				return $row;
			}
			$json = (string) wp_json_encode( $this->pending, JSON_INVALID_UTF8_SUBSTITUTE );
			if ( $json !== '' && strlen( $json ) <= self::MAX_BYTES ) {
				$row['content'] = $json;
			}
			if ( is_object( $mailcatcher ) && method_exists( $mailcatcher, 'getSentMIMEMessage' ) ) {
				$mime = (string) $mailcatcher->getSentMIMEMessage();
				// Empty when the send failed before the message was built.
				if ( trim( $mime ) !== '' && strlen( $mime ) <= self::MAX_BYTES ) {
					$row['mime'] = $mime;
				}
			}
		} catch ( \Throwable $e ) {
			// content is optional; the metadata row must still be written
		}
		return $row;
	}

	/** Daily: wipe content older than content_days, keep the metadata row. */
	public function purge() : void {
		global $wpdb;
		$days   = max( 1, (int) Mailroom::opt( 'content_days' ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Mailroom::table() . ' SET content = NULL, mime = NULL WHERE created_at < %s AND ( content IS NOT NULL OR mime IS NOT NULL )', $cutoff ) ); // phpcs:ignore
	}

	/* ---------------------------------------------------------------------
	 * Detail view / resend / EML
	 * ------------------------------------------------------------------ */

	private static function row( int $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Mailroom::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	public function render_detail( int $id ) : void {
		$r    = self::row( $id );
		$ui   = class_exists( 'Mailroom_UI' );
		$back = $ui ? Mailroom_UI::tab_url( 'log' ) : admin_url( 'admin.php?page=mailroom-log' );
		if ( $ui && class_exists( 'Mailroom_Bridge' ) && Mailroom_Bridge::active() ) {
			// Inside WP Mail SMTP's own page: no second .wrap / h1 (the plugin prints the page chrome and title).
			echo '<div class="mailroom-detail"><h2 class="wp-heading-inline">Email #' . (int) $id . '</h2> <a class="page-title-action" href="' . esc_url( $back ) . '">&larr; Back to the log</a>';
		} else {
			echo '<div class="wrap mailroom-detail"><h1 class="wp-heading-inline">Email #' . (int) $id . '</h1> <a class="page-title-action" href="' . esc_url( $back ) . '">&larr; Back to the log</a><hr class="wp-header-end">';
		}
		if ( $ui ) {
			Mailroom_UI::notices();
		}
		if ( ! $r ) {
			echo '<p>Not found (it may have been pruned).</p></div>';
			return;
		}
		$badge   = $ui ? Mailroom_UI::badge( (string) $r->status ) : esc_html( (string) $r->status );
		$subject = (string) $r->subject !== '' ? (string) $r->subject : '(no subject)';
		echo '<div class="mailroom-detail__head"><p>' . $badge . ' <strong class="mailroom-detail__subject">' . esc_html( $subject ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- badge is escaped in UI::badge()
		echo '<p class="mailroom-muted">' . esc_html( (string) $r->created_at ) . ' &middot; ' . esc_html( (string) $r->to_email ) . '</p></div>';

		$fields = [
			'Source' => $r->source, 'Mailer' => $r->mailer, 'Cc / Bcc' => $r->cc_email, 'Error' => $r->error,
			'Error key' => $r->error_key, 'Duration (ms)' => $r->duration_ms,
			'Resent from' => ! empty( $r->resent_of ) ? '#' . $r->resent_of : '',
		];
		echo '<table class="widefat striped" style="max-width:960px"><tbody>';
		foreach ( $fields as $k => $v ) {
			if ( (string) $v === '' ) {
				continue;
			}
			echo '<tr><th scope="row">' . esc_html( $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$c = ! empty( $r->content ) ? json_decode( (string) $r->content, true ) : null;
		if ( ! is_array( $c ) ) {
			echo '<p><em>No stored content for this email' . ( Mailroom::opt( 'store_content' ) ? ' (it is older than the content-retention window, or was too large)' : ' (full-email storage is off; enable it under Storage &amp; retention)' ) . '. Resend and EML need it.</em></p></div>';
			return;
		}

		$headers    = is_array( $c['headers'] ) ? implode( "\n", array_map( 'strval', $c['headers'] ) ) : (string) $c['headers'];
		$to_default = is_array( $c['to'] ) ? implode( ', ', $c['to'] ) : (string) $c['to'];
		?>
		<div class="mailroom-actions">
			<div class="mailroom-actions-box">
				<h2><?php esc_html_e( 'Resend', 'mailroom' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mailroom-confirm="<?php esc_attr_e( 'Resend this email?', 'mailroom' ); ?>">
					<?php wp_nonce_field( 'mailroom_resend_' . (int) $id ); ?>
					<input type="hidden" name="action" value="mailroom_resend">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<input type="hidden" name="tab" value="log">
					<p><label for="mailroom-resend-to"><?php esc_html_e( 'Send to', 'mailroom' ); ?></label>
					<input type="text" class="regular-text" id="mailroom-resend-to" name="to" value="<?php echo esc_attr( $to_default ); ?>"></p>
					<?php submit_button( __( 'Resend', 'mailroom' ), 'secondary', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Sends through the current mailer with the original subject, body, headers and (still-existing) attachments. Cc/Bcc in the original headers are sent again.', 'mailroom' ); ?></p>
				</form>
			</div>
			<?php if ( ! empty( $r->mime ) ) : ?>
				<?php $eml = wp_nonce_url( admin_url( 'admin-post.php?action=mailroom_eml&id=' . (int) $id ), 'mailroom_eml_' . (int) $id ); ?>
				<div class="mailroom-actions-box">
					<h2><?php esc_html_e( 'Download', 'mailroom' ); ?></h2>
					<p><a class="button" href="<?php echo esc_url( $eml ); ?>"><?php esc_html_e( 'Download .eml', 'mailroom' ); ?></a></p>
					<p class="description"><?php esc_html_e( 'The exact message as sent (unredacted).', 'mailroom' ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<div class="mailroom-tabs">
			<div class="mailroom-tabbar" role="tablist">
				<button type="button" role="tab" data-mailroom-tab="body"><?php esc_html_e( 'Body', 'mailroom' ); ?></button>
				<button type="button" role="tab" data-mailroom-tab="headers"><?php esc_html_e( 'Headers', 'mailroom' ); ?></button>
				<?php if ( ! empty( $c['attachments'] ) ) : ?><button type="button" role="tab" data-mailroom-tab="attachments"><?php esc_html_e( 'Attachments', 'mailroom' ); ?></button><?php endif; ?>
				<?php if ( ! empty( $r->mime ) ) : ?><button type="button" role="tab" data-mailroom-tab="mime"><?php esc_html_e( 'Raw MIME', 'mailroom' ); ?></button><?php endif; ?>
			</div>
			<div class="mailroom-panel" data-mailroom-panel="body" role="tabpanel">
				<h2><?php esc_html_e( 'Body', 'mailroom' ); ?> <small>(<?php esc_html_e( 'plain text, secrets redacted', 'mailroom' ); ?>)</small></h2>
				<pre class="mailroom-pre"><?php echo esc_html( Mailroom_Logger::redact_secrets( trim( (string) preg_replace( "/\n{3,}/", "\n\n", wp_strip_all_tags( (string) $c['message'] ) ) ) ) ); ?></pre>
			</div>
			<div class="mailroom-panel" data-mailroom-panel="headers" role="tabpanel">
				<h2><?php esc_html_e( 'Headers', 'mailroom' ); ?> <small>(<?php esc_html_e( 'as passed to wp_mail', 'mailroom' ); ?>)</small></h2>
				<pre class="mailroom-pre"><?php echo esc_html( $headers !== '' ? $headers : '(none)' ); ?></pre>
			</div>
			<?php if ( ! empty( $c['attachments'] ) ) : ?>
				<div class="mailroom-panel" data-mailroom-panel="attachments" role="tabpanel">
					<h2><?php esc_html_e( 'Attachments', 'mailroom' ); ?></h2>
					<ul>
					<?php foreach ( $c['attachments'] as $a ) : ?>
						<li><?php echo esc_html( basename( (string) $a ) ); ?><?php echo is_readable( (string) $a ) ? '' : ' <em>(file no longer exists; a resend would skip it)</em>'; ?></li>
					<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $r->mime ) ) : ?>
				<div class="mailroom-panel" data-mailroom-panel="mime" role="tabpanel">
					<h2><?php esc_html_e( 'Raw MIME', 'mailroom' ); ?></h2>
					<p><?php echo esc_html( sprintf( /* translators: %s: size */ __( 'The exact message as sent is stored (%s). It is unredacted, so it is not shown here; use Download .eml.', 'mailroom' ), size_format( strlen( (string) $r->mime ) ) ) ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		</div>
		<?php
	}

	/**
	 * Re-send a stored email. Returns [ok, message].
	 *
	 * @param string $to_override comma-separated recipients, or '' to use the original
	 */
	public function resend( int $id, string $to_override = '' ) : array {
		$r = self::row( $id );
		$c = ( $r && ! empty( $r->content ) ) ? json_decode( (string) $r->content, true ) : null;
		if ( ! is_array( $c ) ) {
			return [ false, 'No stored content for #' . $id . '.' ];
		}
		$to = $c['to'];
		if ( trim( $to_override ) !== '' ) {
			$to = [];
			foreach ( preg_split( '/[\s,;]+/', $to_override ) as $a ) {
				if ( $a !== '' ) {
					if ( ! is_email( $a ) ) {
						return [ false, 'Invalid recipient: ' . $a ];
					}
					$to[] = $a;
				}
			}
		}
		$att     = array_values( array_filter( (array) $c['attachments'], function ( $a ) { return is_readable( (string) $a ); } ) );
		$skipped = count( (array) $c['attachments'] ) - count( $att );

		$this->resending = $id;
		try {
			$ok = wp_mail( $to, (string) $c['subject'], (string) $c['message'], $c['headers'], $att );
		} finally {
			$this->resending = 0;
		}
		return [ (bool) $ok, ( $ok ? 'Resent #' . $id . '.' : 'Resend of #' . $id . ' failed (see the new log row).' ) . ( $skipped ? " {$skipped} missing attachment(s) skipped." : '' ) ];
	}

	public function handle_resend() : void {
		if ( ! current_user_can( Mailroom::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mailroom' ) );
		}
		Mailroom_UI::require_post();
		$id = (int) ( $_POST['id'] ?? 0 ); // phpcs:ignore
		check_admin_referer( 'mailroom_resend_' . $id );
		[ , $msg ] = $this->resend( $id, (string) wp_unslash( $_POST['to'] ?? '' ) ); // phpcs:ignore
		Mailroom_UI::finish( $msg, 'log' );
	}

	/** The stored MIME, or null. */
	public function eml_for( int $id ) : ?string {
		$r = self::row( $id );
		return ( $r && ! empty( $r->mime ) ) ? (string) $r->mime : null;
	}

	public function handle_eml() : void {
		if ( ! current_user_can( Mailroom::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mailroom' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 ); // phpcs:ignore
		check_admin_referer( 'mailroom_eml_' . $id );
		$mime = $this->eml_for( $id );
		if ( $mime === null ) {
			wp_die( esc_html__( 'No stored message for this entry.', 'mailroom' ) );
		}
		nocache_headers();
		header( 'Content-Type: message/rfc822' );
		header( 'Content-Disposition: attachment; filename=mailroom-mail-' . $id . '.eml' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $mime; // phpcs:ignore WordPress.Security.EscapeOutput -- raw download, not rendered
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public function settings_rows( array $o ) : void {
		?>
		<h2><?php esc_html_e( 'Full email storage (resend / EML)', 'mailroom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Keeps the complete message so it can be viewed, resent and downloaded as .eml. It contains customer data and live links such as password-reset keys: shown redacted, stored raw. After the period below the content is wiped; the log entry stays for the normal retention.', 'mailroom' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="mailroom-store-content"><?php esc_html_e( 'Store full emails', 'mailroom' ); ?></label></th><td><label for="mailroom-store-content"><input type="checkbox" id="mailroom-store-content" name="store_content" value="1" data-mailroom-toggle="store" <?php checked( $o['store_content'] ); ?>> <?php esc_html_e( 'Keep the complete message (body, headers, attachment paths, raw MIME). Off by default.', 'mailroom' ); ?></label></td></tr>
			<tr data-mailroom-dep="store"><th scope="row"><label for="mailroom-content-days"><?php esc_html_e( 'Keep full emails for (days)', 'mailroom' ); ?></label></th><td><input type="number" min="1" max="90" class="small-text" id="mailroom-content-days" name="content_days" value="<?php echo esc_attr( $o['content_days'] ); ?>"></td></tr>
		</table>
		<?php
	}

	public function sanitize( array $acc, array $post, string $section = '' ) : array {
		if ( $section !== '' && $section !== self::SECTION ) {
			return $acc;
		}
		[ $o, $ok ]         = $acc;
		$o['store_content'] = empty( $post['store_content'] ) ? 0 : 1;
		$o['content_days']  = min( 90, max( 1, (int) ( $post['content_days'] ?? 7 ) ) );
		return [ $o, $ok ];
	}
}
