<?php
/**
 * Admin UI helpers shared by the Email Log page and the detail view: tab URLs, status badges, relative
 * times, notices, POST action forms and the page's inline CSS/JS. No state, no hooks.
 *
 * The CSS and JS are printed inline, and only on this plugin's page, so there is no static file to
 * deploy (or to 404 on a mis-permissioned directory) and nothing loads on other admin screens.
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_UI {

	const PAGE = 'mailroom-log';

	/** tab slug => label */
	const TABS = [
		'overview' => 'Overview',
		'log'      => 'Log',
		'alerts'   => 'Alerts',
		'delivery' => 'Delivery',
		'storage'  => 'Storage & retention',
		'controls' => 'Email Controls',
		'reports'  => 'Reports',
	];

	/** Tab keys that exist only as separate native screens; the standalone page shows them all on its Delivery tab. */
	const NATIVE_ONLY_TABS = [ 'connections', 'routing', 'ratelimit' ];

	const STATUS_LABELS = [
		'sent'          => 'Sent',
		'failed'        => 'Failed',
		'sent_backup'   => 'Sent via backup',
		'failed_backup' => 'Backup failed',
		'queued'        => 'Queued',
	];

	/** Where a tab lives: inside WP Mail SMTP when native integration is on, otherwise our standalone page. */
	public static function tab_url( string $tab, array $args = [] ) : string {
		if ( class_exists( 'Mailroom_Bridge' ) && Mailroom_Bridge::active() ) {
			$url = Mailroom_Bridge::url( $tab, $args );
			if ( $url !== '' ) {
				return $url;
			}
		}
		$tab = in_array( $tab, self::NATIVE_ONLY_TABS, true ) ? 'delivery' : $tab;
		return add_query_arg( array_merge( [ 'page' => self::PAGE, 'tab' => $tab ], $args ), admin_url( 'admin.php' ) );
	}

	/** A tab key we know (standalone tabs and the native-only ones). */
	public static function is_tab( string $tab ) : bool {
		return isset( self::TABS[ $tab ] ) || in_array( $tab, self::NATIVE_ONLY_TABS, true );
	}

	/** The single-email view. */
	public static function view_url( int $id ) : string {
		if ( class_exists( 'Mailroom_Bridge' ) && Mailroom_Bridge::active() ) {
			return Mailroom_Bridge::url( 'log', [ 'view' => $id ] );
		}
		return add_query_arg( [ 'page' => self::PAGE, 'view' => $id ], admin_url( 'admin.php' ) );
	}

	/** Hidden fields for a GET form that must come back to the log list. */
	public static function log_form_fields() : array {
		if ( class_exists( 'Mailroom_Bridge' ) && Mailroom_Bridge::active() ) {
			return [ 'page' => 'wp-mail-smtp-logs' ];
		}
		return [ 'page' => self::PAGE, 'tab' => 'log' ];
	}

	/** True while the request is for a screen that renders our content (assets are printed only then). */
	public static function is_our_page() : bool {
		if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === self::PAGE ) { // phpcs:ignore
			return true;
		}
		return class_exists( 'Mailroom_Bridge' ) && Mailroom_Bridge::is_native_page();
	}

	public static function badge( string $status ) : string {
		$label = self::STATUS_LABELS[ $status ] ?? $status;
		return '<span class="mailroom-badge mailroom-badge--' . esc_attr( sanitize_key( $status ) ) . '">' . esc_html( $label ) . '</span>';
	}

	/** "5 mins ago" with the exact (site-local) time in the title attribute. $mysql is a site-local datetime. */
	public static function when( string $mysql ) : string {
		try {
			$ts  = ( new DateTimeImmutable( $mysql, wp_timezone() ) )->getTimestamp();
			$ago = ( time() - $ts ) < MINUTE_IN_SECONDS ? __( 'just now', 'mailroom' ) : sprintf( /* translators: %s: time span */ __( '%s ago', 'mailroom' ), human_time_diff( $ts, time() ) );
		} catch ( \Throwable $e ) {
			$ago = $mysql;
		}
		return '<span title="' . esc_attr( $mysql ) . '">' . esc_html( $ago ) . '</span>';
	}

	/** Print the one-shot "updated" and per-user action notices. */
	public static function notices() : void {
		if ( isset( $_GET['updated'] ) ) : // phpcs:ignore
			$u = (int) $_GET['updated']; // phpcs:ignore
			?>
			<div class="notice <?php echo $u ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p>
				<?php echo $u ? esc_html__( 'Settings saved.', 'mailroom' ) : esc_html__( 'Saved, but some values were rejected and kept/dropped: the alert webhook must be https://, invalid email addresses are removed, and malformed routing rules are dropped.', 'mailroom' ); ?>
			</p></div>
			<?php
		endif;
		$key    = 'mailroom_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( (string) $notice ) . '</p></div>';
		}
	}

	/** Method guard for state-changing admin-post handlers: only POST is accepted (a GET link must never act). */
	public static function require_post() : void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			wp_die( esc_html__( 'Invalid request method.', 'mailroom' ), '', [ 'response' => 405 ] );
		}
	}

	/** Store the result notice for the current user and send them back to the tab the action came from. */
	public static function finish( string $msg, string $default_tab ) : void {
		if ( $msg !== '' ) {
			set_transient( 'mailroom_notice_' . get_current_user_id(), $msg, 60 );
		}
		$tab = sanitize_key( wp_unslash( (string) ( $_POST['tab'] ?? '' ) ) ); // phpcs:ignore
		wp_safe_redirect( self::tab_url( self::is_tab( $tab ) ? $tab : $default_tab ) );
		exit;
	}

	/**
	 * A one-button POST form for an admin-post action (never a GET link). Escaped; echo as-is.
	 *
	 * @param array<string,scalar> $fields extra hidden fields
	 */
	public static function action_form( string $action, string $nonce_action, string $label, string $tab, array $fields = [], string $class = 'button', string $confirm = '' ) : string {
		$html  = '<form method="post" class="mailroom-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $confirm !== '' ? ' data-mailroom-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		$html .= wp_nonce_field( $nonce_action, '_wpnonce', false, false );
		$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$html .= '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		foreach ( $fields as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		return $html . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function print_css() : void {
		if ( self::is_our_page() ) {
			echo '<style id="mailroom-css">' . self::css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static string
		}
	}

	public static function print_js() : void {
		if ( self::is_our_page() ) {
			echo '<script id="mailroom-js">' . self::js() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static string
		}
	}

	private static function css() : string {
		return <<<'CSS'
.mailroom-badge{display:inline-block;padding:1px 8px;border:1px solid #c3c4c7;border-radius:3px;background:#f0f0f1;color:#50575e;font-size:12px;font-weight:600;line-height:1.7;white-space:nowrap}
.mailroom-badge--sent{background:#edfaef;border-color:#00a32a;color:#007017}
.mailroom-badge--failed{background:#fcf0f1;border-color:#d63638;color:#b32d2e}
.mailroom-badge--sent_backup{background:#f0f6fc;border-color:#2271b1;color:#0a4b78}
.mailroom-badge--failed_backup{background:#fef1e1;border-color:#d97706;color:#8a4b00}
.mailroom-badge--queued{background:#f0f0f1;border-color:#a7aaad;color:#50575e}
.mailroom-muted{color:#646970}
.mailroom-err{display:block;margin-top:4px;color:#b32d2e;background:none;padding:0;word-break:break-word}
.mailroom-inline-form{display:inline;margin:0}
.mailroom-inline-form .button-link{color:#2271b1}
.mailroom-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0}
.mailroom-filters label{display:inline-flex;gap:4px;align-items:center}
.mailroom-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin:12px 0 24px}
.mailroom-card{min-width:0;box-sizing:border-box;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #c3c4c7;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.mailroom-card h3{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin:0 0 8px;font-size:14px}
.mailroom-card--good{border-left-color:#00a32a}
.mailroom-card--bad{border-left-color:#d63638}
.mailroom-card--warn{border-left-color:#dba617}
.mailroom-card--info{border-left-color:var(--wp-admin-theme-color,#2271b1)}
.mailroom-metric{margin:0;font-size:28px;font-weight:600;line-height:1.2}
.mailroom-card__note{margin:4px 0 0;color:#646970;word-break:break-word}
.mailroom-card__summary{margin:0 0 8px;color:#50575e;word-break:break-word}
.mailroom-card__warning{margin:0 0 8px;padding:6px 8px;background:#fcf9e8;border-left:3px solid #dba617;color:#1d2327}
.mailroom-card__warning .dashicons{color:#996800;font-size:18px;width:18px;height:18px;vertical-align:text-bottom}
.mailroom-pill{padding:0 8px;border-radius:10px;background:#f0f0f1;color:#50575e;font-size:12px;font-weight:600;line-height:1.8}
.mailroom-pill--on{background:#edfaef;color:#007017}
.mailroom-dim{opacity:.55}
.mailroom-actions-box{margin:16px 0;padding:12px 16px;background:#fff;border:1px solid #c3c4c7}
.mailroom-actions-box h2{margin-top:0}
.mailroom-detail__head{margin:12px 0}
.mailroom-detail__subject{font-size:16px;margin-left:6px}
.mailroom-detail .widefat th{width:11em}
.mailroom-actions{display:flex;flex-wrap:wrap;gap:16px;margin:16px 0}
.mailroom-actions .mailroom-actions-box{flex:1 1 320px;margin:0}
.mailroom-actions label{display:block;margin-bottom:4px;font-weight:600}
.mailroom-pre{max-width:960px;max-height:480px;margin:0;padding:8px 12px;overflow:auto;background:#fff;border:1px solid #c3c4c7;white-space:pre-wrap;word-break:break-word}
.mailroom-tabs .mailroom-tabbar{display:none}
.mailroom-tabs--js .mailroom-tabbar{display:flex;flex-wrap:wrap;gap:4px;margin:16px 0 0;border-bottom:1px solid #c3c4c7}
.mailroom-tabbar button{margin:0 0 -1px;padding:6px 14px;background:#f0f0f1;border:1px solid #c3c4c7;color:#50575e;cursor:pointer;font-size:13px}
.mailroom-tabbar button[aria-selected="true"]{background:#fff;border-bottom-color:#fff;color:#1d2327;font-weight:600}
.mailroom-tabs--js .mailroom-panel{padding-top:12px}
.mailroom-tabs--js .mailroom-panel>h2{display:none}
@media (min-width:783px){.wp-list-table .column-when,.wp-list-table .column-status{width:9em}.wp-list-table .column-duration{width:6em}}
@media (max-width:782px){.mailroom-cards{grid-template-columns:1fr 1fr}.mailroom-filters input,.mailroom-filters select{max-width:100%}}
@media (max-width:480px){.mailroom-cards{grid-template-columns:1fr}}
CSS;
	}

	private static function js() : string {
		return <<<'JS'
(function(){
function ready(f){if(document.readyState!=='loading'){f();}else{document.addEventListener('DOMContentLoaded',f);}}
ready(function(){
	// Dim (never disable: a disabled field is not submitted) the fields of an unticked feature.
	document.querySelectorAll('[data-mailroom-toggle]').forEach(function(box){
		var deps=document.querySelectorAll('[data-mailroom-dep="'+box.getAttribute('data-mailroom-toggle')+'"]');
		function sync(){deps.forEach(function(el){el.classList.toggle('mailroom-dim',!box.checked);});}
		box.addEventListener('change',sync);sync();
	});
	// Detail view tabs. Without JS every panel is simply shown one under the other.
	document.querySelectorAll('.mailroom-tabs').forEach(function(box){
		var btns=box.querySelectorAll('[data-mailroom-tab]'),panels=box.querySelectorAll('[data-mailroom-panel]');
		if(!btns.length){return;}
		box.classList.add('mailroom-tabs--js');
		function show(name){
			btns.forEach(function(b){b.setAttribute('aria-selected',b.getAttribute('data-mailroom-tab')===name?'true':'false');});
			panels.forEach(function(p){p.hidden=p.getAttribute('data-mailroom-panel')!==name;});
		}
		btns.forEach(function(b){b.addEventListener('click',function(){show(b.getAttribute('data-mailroom-tab'));});});
		show(btns[0].getAttribute('data-mailroom-tab'));
	});
	document.addEventListener('submit',function(e){
		var m=e.target&&e.target.getAttribute&&e.target.getAttribute('data-mailroom-confirm');
		if(m&&!window.confirm(m)){e.preventDefault();}
	});
});
})();
JS;
	}
}
