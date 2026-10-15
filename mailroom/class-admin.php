<?php
/**
 * Admin: the WP Mail SMTP → Email Log page (tabs: Overview, Log, and one settings tab per area), CSV export,
 * dashboard widget, and the replacement of the plugin's Pro-gated "Email Log" menu item.
 *
 * Settings are saved per section (one form per tab). A section only touches the option keys it owns; every
 * other key is preserved exactly. This matters because unticked checkboxes are absent from a POST, so a
 * save that processed every key would switch off the features that live on other tabs.
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Admin {

	const PAGE = 'mailroom-log';

	const STATUSES = [ 'sent', 'failed', 'sent_backup', 'failed_backup', 'queued' ];

	/** Settings sections (= settings tabs). Unknown sections are refused. */
	const SECTIONS = [ 'alerts', 'delivery', 'storage', 'controls', 'reports' ];

	/**
	 * Sections saved from the native screens inside WP Mail SMTP, where `delivery` is split over three tabs.
	 * Each is a subset of `delivery`'s keys, so saving one never touches the others:
	 * connections = backup SMTP + failover, routing = Smart Routing, ratelimit = rate limiting.
	 */
	const NATIVE_SECTIONS = [ 'connections', 'routing', 'ratelimit' ];

	const PER_PAGE_OPT = 'mailroom_log_per_page';

	/** @var Mailroom */
	private $core;

	public function __construct( Mailroom $core ) {
		$this->core = $core;
	}

	public function boot() : void {
		if ( ! class_exists( 'Mailroom_UI' ) ) {
			return; // the admin pages and action handlers all depend on the UI helpers; a missing file must not fatal the site
		}
		if ( class_exists( 'Mailroom_Bridge' ) ) {
			Mailroom_Bridge::boot(); // native integration: every callback is inert unless the bridge says ON
		}
		add_action( 'admin_menu', [ $this, 'menu' ], 20 );
		add_action( 'admin_init', [ $this, 'maybe_redirect_legacy_logs_page' ] );
		// Native ON unregisters page=mailroom-log, and wp-admin/includes/menu.php denies an unregistered page
		// BEFORE admin_init fires ("Sorry, you are not allowed…"). It fires this action just before that wp_die,
		// so old links (bookmarks, alert/report emails) still reach the redirect.
		add_action( 'admin_page_access_denied', [ $this, 'maybe_redirect_legacy_logs_page' ], 0 );
		add_action( 'admin_post_mailroom_save', [ $this, 'handle_save' ] );
		add_action( 'admin_post_mailroom_export', [ $this, 'handle_export' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'dashboard_widget' ] );
		add_action( 'admin_head', [ 'Mailroom_UI', 'print_css' ] );
		add_action( 'admin_print_footer_scripts', [ 'Mailroom_UI', 'print_js' ] );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPT, [ $this, 'save_per_page' ], 10, 3 );
		// Backup SMTP / retention / body-preview rows belong to this class; modules add theirs after them.
		add_action( 'mailroom_settings_delivery', [ $this, 'backup_rows' ], 5 );
		add_action( 'mailroom_settings_connections', [ $this, 'backup_rows' ], 5 );
		add_action( 'mailroom_settings_storage', [ $this, 'retention_rows' ], 5 );
	}

	private function can() : bool {
		return current_user_can( Mailroom::CAP );
	}

	/** Native integration on? (A missing bridge file means off, never a fatal.) */
	private function native() : bool {
		return class_exists( 'Mailroom_Bridge' ) && Mailroom_Bridge::active();
	}

	public static function is_section( string $section ) : bool {
		return in_array( $section, self::SECTIONS, true ) || in_array( $section, self::NATIVE_SECTIONS, true );
	}

	public function menu() : void {
		if ( $this->native() ) {
			// Native integration: the plugin's own "Email Log" submenu (wp-mail-smtp-logs) renders our log, so
			// there is exactly one item and nothing to remove or add. Only the Screen Options hook is ours.
			add_action( 'load-' . get_plugin_page_hookname( 'wp-mail-smtp-logs', 'wp-mail-smtp' ), [ $this, 'screen_options' ] );
			return;
		}
		// WP Mail SMTP (Lite) registers its own "Email Log" submenu (slug wp-mail-smtp-logs)
		// that renders a "Upgrade to Pro" panel. Replace it with our working log so the menu
		// item the user expects actually works. Reversible: delete this mu-plugin.
		remove_submenu_page( 'wp-mail-smtp', 'wp-mail-smtp-logs' );

		$hook = add_submenu_page(
			'wp-mail-smtp',
			__( 'Email Log', 'mailroom' ),
			__( 'Email Log', 'mailroom' ),
			Mailroom::CAP,
			self::PAGE,
			[ $this, 'render_page' ]
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, [ $this, 'screen_options' ] );
		}
	}

	/**
	 * Old links land on the right screen. Native integration ON: our former page (page=mailroom-log, in
	 * bookmarks and in alert/report emails) goes to its native equivalent; the plugin's own log URLs now render ours.
	 * OFF: the plugin's gated log URLs go to our standalone page.
	 */
	public function maybe_redirect_legacy_logs_page() : void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore
		if ( $this->native() ) {
			if ( $page !== self::PAGE || ! $this->can() ) {
				return;
			}
			$view = (int) ( $_GET['view'] ?? 0 ); // phpcs:ignore
			$key  = ( $view > 0 || $tab === 'log' ) ? 'log' : ( isset( Mailroom_UI::TABS[ $tab ] ) ? $tab : 'overview' );
			$args = [];
			if ( $key === 'log' ) {
				$args = array_filter( self::filters( $_GET ), 'strlen' ); // phpcs:ignore
				if ( $view > 0 ) {
					$args = [ 'view' => $view ];
				} elseif ( ! empty( $_GET['paged'] ) ) { // phpcs:ignore
					$args['paged'] = max( 1, (int) $_GET['paged'] ); // phpcs:ignore
				}
			}
			wp_safe_redirect( Mailroom_UI::tab_url( $key, $args ) );
			exit;
		}
		// The plugin's own "Email Log" submenu (wp-mail-smtp-logs) redirects to its Settings → Email Logs
		// tab, and both render the Pro upsell. Catch both URLs and send them to our working log.
		$is_logs = ( $page === 'wp-mail-smtp-logs' ) || ( $page === 'wp-mail-smtp' && $tab === 'logs' );
		if ( ! $is_logs || ! $this->can() ) {
			return;
		}
		wp_safe_redirect( Mailroom_UI::tab_url( 'log' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Screen option: rows per page
	 * ------------------------------------------------------------------ */

	public function screen_options() : void {
		$native = ( $_GET['page'] ?? '' ) === 'wp-mail-smtp-logs'; // phpcs:ignore -- the log is the whole page; standalone it is the `log` tab
		if ( ( ! $native && ( $_GET['tab'] ?? '' ) !== 'log' ) || ! empty( $_GET['view'] ) ) { // phpcs:ignore
			return;
		}
		add_screen_option( 'per_page', [ 'label' => __( 'Emails per page', 'mailroom' ), 'default' => 50, 'option' => self::PER_PAGE_OPT ] );
	}

	public function save_per_page( $keep, $option = '', $value = 0 ) {
		return max( 1, min( 500, (int) $value ) );
	}

	private function per_page() : int {
		$n = (int) get_user_option( self::PER_PAGE_OPT );
		return $n >= 1 ? min( $n, 500 ) : 50;
	}

	/* ---------------------------------------------------------------------
	 * Page + tabs
	 * ------------------------------------------------------------------ */

	/** @return array<string,string> tab slug => label, only for tabs whose module is loaded */
	private function tabs() : array {
		$needs = [ 'alerts' => 'alerts', 'controls' => 'controls', 'reports' => 'reports' ];
		$tabs  = [];
		foreach ( Mailroom_UI::TABS as $slug => $label ) {
			if ( ! isset( $needs[ $slug ] ) || $this->core->module( $needs[ $slug ] ) ) {
				$tabs[ $slug ] = $label;
			}
		}
		return $tabs;
	}

	private function current_tab() : string {
		$t = sanitize_key( wp_unslash( (string) ( $_GET['tab'] ?? 'overview' ) ) ); // phpcs:ignore
		return isset( $this->tabs()[ $t ] ) ? $t : 'overview';
	}

	public function render_page() : void {
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mailroom' ) );
		}
		$view    = (int) ( $_GET['view'] ?? 0 ); // phpcs:ignore
		$content = $this->core->module( 'content' );
		if ( $view > 0 && $content ) {
			$content->render_detail( $view );
			return;
		}
		$tab = $this->current_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email Log', 'mailroom' ); ?></h1>
			<hr class="wp-header-end">
			<?php Mailroom_UI::notices(); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Email Log sections', 'mailroom' ); ?>">
				<?php foreach ( $this->tabs() as $slug => $label ) : ?>
					<a class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( Mailroom_UI::tab_url( $slug ) ); ?>"<?php echo $slug === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( $tab === 'overview' ) {
				if ( class_exists( 'Mailroom_Overview' ) ) {
					Mailroom_Overview::render();
				}
			} elseif ( $tab === 'log' ) {
				$this->render_log();
			} else {
				$this->render_settings( $tab );
			}
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Log tab
	 * ------------------------------------------------------------------ */

	private function render_log() : void {
		$f     = self::filters( $_GET ); // phpcs:ignore
		$table = $this->list_table( $f );
		if ( ! $table ) {
			echo '<p>' . esc_html__( 'The log table is unavailable.', 'mailroom' ) . '</p>';
			return;
		}
		$table->prepare_items();
		$active = array_filter( $f, 'strlen' );
		$export = wp_nonce_url( add_query_arg( array_map( 'rawurlencode', $active ), admin_url( 'admin-post.php?action=mailroom_export' ) ), 'mailroom_export' );
		?>
		<form method="get" class="mailroom-filters">
			<?php foreach ( Mailroom_UI::log_form_fields() as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<label for="mailroom-f-status" class="screen-reader-text"><?php esc_html_e( 'Status', 'mailroom' ); ?></label>
			<select name="status" id="mailroom-f-status">
				<option value=""><?php esc_html_e( 'All statuses', 'mailroom' ); ?></option>
				<?php foreach ( self::STATUSES as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $f['status'], $s ); ?>><?php echo esc_html( Mailroom_UI::STATUS_LABELS[ $s ] ?? $s ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="mailroom-f-from"><?php esc_html_e( 'From', 'mailroom' ); ?> <input type="date" name="from" id="mailroom-f-from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
			<label for="mailroom-f-to"><?php esc_html_e( 'To', 'mailroom' ); ?> <input type="date" name="to" id="mailroom-f-to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
			<label for="mailroom-f-q" class="screen-reader-text"><?php esc_html_e( 'Search recipient or subject', 'mailroom' ); ?></label>
			<input type="search" name="q" id="mailroom-f-q" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="<?php esc_attr_e( 'recipient or subject', 'mailroom' ); ?>">
			<?php submit_button( __( 'Filter', 'mailroom' ), 'secondary', '', false ); ?>
			<a class="button" href="<?php echo esc_url( Mailroom_UI::tab_url( 'log' ) ); ?>"><?php esc_html_e( 'Reset', 'mailroom' ); ?></a>
			<a class="button button-primary" href="<?php echo esc_url( $export ); ?>"><?php echo $active ? esc_html__( 'Export CSV (filtered)', 'mailroom' ) : esc_html__( 'Export CSV', 'mailroom' ); ?></a>
		</form>
		<?php
		$table->display();
	}

	/** @return Mailroom_List_Table|null */
	private function list_table( array $filters ) {
		// WP_List_Table and the screen API are admin-only includes: pull them in here, not in the loader.
		foreach ( [ 'class-wp-screen', 'screen', 'template', 'class-wp-list-table' ] as $inc ) {
			require_once ABSPATH . 'wp-admin/includes/' . $inc . '.php';
		}
		$file = __DIR__ . '/class-list-table.php';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		require_once $file;
		return new Mailroom_List_Table( $filters, $this->per_page(), (bool) $this->core->module( 'content' ) );
	}

	/* ---------------------------------------------------------------------
	 * Native integration: our screens inside WP Mail SMTP's own pages
	 * ------------------------------------------------------------------ */

	/**
	 * Render one logical tab inside a WP Mail SMTP page (the plugin prints the page chrome and its tab bar).
	 * Keys: overview, log, export, storage, alerts, controls, connections, routing (+ rate limiting), reports.
	 * The settings forms are the very same per-section forms as on the standalone page.
	 */
	public function render_native( string $key ) : void {
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mailroom' ) );
		}
		$view = (int) ( $_GET['view'] ?? 0 ); // phpcs:ignore
		echo '<div class="mailroom-native">';
		if ( $key === 'log' && $view > 0 && $this->core->module( 'content' ) ) {
			$this->core->module( 'content' )->render_detail( $view ); // prints the notices itself
			echo '</div>';
			return;
		}
		Mailroom_UI::notices();
		switch ( $key ) {
			case 'overview':
				if ( class_exists( 'Mailroom_Overview' ) ) {
					Mailroom_Overview::render();
				}
				break;
			case 'log':
				$this->render_log();
				break;
			case 'export':
				$this->render_export();
				break;
			case 'routing':
				$this->render_settings( 'routing' );
				$this->render_settings( 'ratelimit' );
				break;
			default:
				$this->render_settings( in_array( $key, [ 'storage', 'alerts', 'controls', 'connections', 'reports' ], true ) ? $key : '' );
		}
		echo '</div>';
	}

	/** Tools → Export: a GET form (the download is read-only; the nonce is in the form) with the log filters. */
	private function render_export() : void {
		$f = self::filters( $_GET ); // phpcs:ignore
		?>
		<h2><?php esc_html_e( 'Export the email log (CSV)', 'mailroom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Downloads the log entries that match the filters below (all entries when empty), newest first, at most 10,000 rows. A single email can be downloaded as .eml from its own page in the Email Log (needs full email storage).', 'mailroom' ); ?></p>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mailroom_export', '_wpnonce', false ); ?>
			<input type="hidden" name="action" value="mailroom_export">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="mailroom-x-status"><?php esc_html_e( 'Status', 'mailroom' ); ?></label></th><td><select name="status" id="mailroom-x-status">
					<option value=""><?php esc_html_e( 'All statuses', 'mailroom' ); ?></option>
					<?php foreach ( self::STATUSES as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $f['status'], $s ); ?>><?php echo esc_html( Mailroom_UI::STATUS_LABELS[ $s ] ?? $s ); ?></option>
					<?php endforeach; ?>
				</select></td></tr>
				<tr><th scope="row"><label for="mailroom-x-from"><?php esc_html_e( 'From date', 'mailroom' ); ?></label></th><td><input type="date" name="from" id="mailroom-x-from" value="<?php echo esc_attr( $f['from'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="mailroom-x-to"><?php esc_html_e( 'To date', 'mailroom' ); ?></label></th><td><input type="date" name="to" id="mailroom-x-to" value="<?php echo esc_attr( $f['to'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="mailroom-x-q"><?php esc_html_e( 'Recipient or subject contains', 'mailroom' ); ?></label></th><td><input type="search" class="regular-text" name="q" id="mailroom-x-q" value="<?php echo esc_attr( $f['q'] ); ?>"></td></tr>
			</table>
			<?php submit_button( __( 'Export CSV', 'mailroom' ), 'primary', 'submit', true ); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings tabs
	 * ------------------------------------------------------------------ */

	private function render_settings( string $section ) : void {
		if ( ! self::is_section( $section ) ) {
			return;
		}
		$o = wp_parse_args( (array) get_option( Mailroom::OPT, [] ), Mailroom::defaults() );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mailroom_save' ); ?>
			<input type="hidden" name="action" value="mailroom_save">
			<input type="hidden" name="section" value="<?php echo esc_attr( $section ); ?>">
			<?php
			do_action( 'mailroom_settings_' . $section, $o );
			submit_button();
			?>
		</form>
		<?php
		// Action buttons are separate POST forms; a <form> cannot nest inside the settings form.
		do_action( 'mailroom_actions_' . $section, $o );
	}

	public function backup_rows( array $o ) : void {
		?>
		<h2><?php esc_html_e( 'Backup SMTP and failover', 'mailroom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'When a send fails, try once more through this second SMTP host. Smart Routing also uses it. It needs its own From address (reusing the primary one on another relay breaks DMARC).', 'mailroom' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="mailroom-backup-enabled"><?php esc_html_e( 'Backup failover', 'mailroom' ); ?></label></th><td><label for="mailroom-backup-enabled"><input type="checkbox" id="mailroom-backup-enabled" name="backup_enabled" value="1" data-mailroom-toggle="backup" <?php checked( $o['backup_enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'mailroom' ); ?></label></td></tr>
			<tr data-mailroom-dep="backup"><th scope="row"><label for="mailroom-backup-host"><?php esc_html_e( 'Host', 'mailroom' ); ?></label></th><td><input type="text" class="regular-text" id="mailroom-backup-host" name="backup_host" value="<?php echo esc_attr( $o['backup_host'] ); ?>"></td></tr>
			<tr data-mailroom-dep="backup"><th scope="row"><label for="mailroom-backup-port"><?php esc_html_e( 'Port', 'mailroom' ); ?></label></th><td><input type="number" class="small-text" id="mailroom-backup-port" name="backup_port" value="<?php echo esc_attr( $o['backup_port'] ); ?>"></td></tr>
			<tr data-mailroom-dep="backup"><th scope="row"><label for="mailroom-backup-secure"><?php esc_html_e( 'Encryption', 'mailroom' ); ?></label></th><td><select id="mailroom-backup-secure" name="backup_secure">
				<?php foreach ( [ '', 'tls', 'ssl' ] as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $o['backup_secure'], $s ); ?>><?php echo esc_html( $s ?: 'none' ); ?></option>
				<?php endforeach; ?>
			</select></td></tr>
			<tr data-mailroom-dep="backup"><th scope="row"><label for="mailroom-backup-user"><?php esc_html_e( 'Username', 'mailroom' ); ?></label></th><td><input type="text" class="regular-text" id="mailroom-backup-user" name="backup_user" value="<?php echo esc_attr( $o['backup_user'] ); ?>" autocomplete="off"></td></tr>
			<tr data-mailroom-dep="backup"><th scope="row"><label for="mailroom-backup-pass"><?php esc_html_e( 'Password', 'mailroom' ); ?></label></th><td><input type="password" class="regular-text" id="mailroom-backup-pass" name="backup_pass" value="" autocomplete="new-password" placeholder="<?php echo $o['backup_pass'] ? '•••••••• (unchanged)' : ''; ?>"><p class="description"><?php esc_html_e( 'Leave blank to keep the stored secret.', 'mailroom' ); ?></p></td></tr>
			<tr data-mailroom-dep="backup"><th scope="row"><label for="mailroom-backup-from"><?php esc_html_e( 'From address', 'mailroom' ); ?></label></th><td><input type="email" class="regular-text" id="mailroom-backup-from" name="backup_from" value="<?php echo esc_attr( $o['backup_from'] ); ?>"></td></tr>
		</table>
		<?php
	}

	public function retention_rows( array $o ) : void {
		?>
		<h2><?php esc_html_e( 'Log retention', 'mailroom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A daily job deletes log entries older than the retention period.', 'mailroom' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="mailroom-retention"><?php esc_html_e( 'Retention (days)', 'mailroom' ); ?></label></th><td><input type="number" min="0" class="small-text" id="mailroom-retention" name="retention_days" value="<?php echo esc_attr( $o['retention_days'] ); ?>"><p class="description"><?php esc_html_e( '0 keeps entries forever.', 'mailroom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="mailroom-log-body"><?php esc_html_e( 'Body preview', 'mailroom' ); ?></label></th><td><label for="mailroom-log-body"><input type="checkbox" id="mailroom-log-body" name="log_body" value="1" <?php checked( $o['log_body'] ); ?>> <?php esc_html_e( 'Store a redacted 500-character preview of each body (may contain customer PII)', 'mailroom' ); ?></label></td></tr>
		</table>
		<?php
	}

	/**
	 * Apply one section's POST to the current options. Pure (no redirect, no write), so it can be tested.
	 * Only the keys the section owns are read from $post; everything else in $current is returned unchanged.
	 *
	 * @param array $post    raw POST (still slashed, as WordPress leaves it)
	 * @param array $current full current options (defaults merged)
	 * @return array{0:array,1:bool} new options, and whether every submitted value was accepted
	 */
	public static function sanitize_section( string $section, array $post, array $current ) : array {
		if ( ! self::is_section( $section ) ) {
			return [ $current, false ];
		}
		$o  = $current;
		$ok = true;
		if ( $section === 'storage' ) {
			$o['retention_days'] = max( 0, (int) ( $post['retention_days'] ?? 30 ) );
			$o['log_body']       = empty( $post['log_body'] ) ? 0 : 1;
		}
		if ( $section === 'delivery' || $section === 'connections' ) {
			$secure              = $post['backup_secure'] ?? '';
			$o['backup_enabled'] = empty( $post['backup_enabled'] ) ? 0 : 1;
			$o['backup_host']    = sanitize_text_field( wp_unslash( $post['backup_host'] ?? '' ) );
			$o['backup_port']    = (int) ( $post['backup_port'] ?? 587 );
			$o['backup_secure']  = in_array( $secure, [ '', 'tls', 'ssl' ], true ) ? $secure : '';
			$o['backup_user']    = sanitize_text_field( wp_unslash( $post['backup_user'] ?? '' ) );
			$o['backup_from']    = sanitize_email( wp_unslash( $post['backup_from'] ?? '' ) );
			$pass                = (string) wp_unslash( $post['backup_pass'] ?? '' ); // wp_magic_quotes slashes every $_POST value
			if ( $pass !== '' ) {
				$o['backup_pass'] = Mailroom::encrypt_secret( $pass ); // blank means "keep existing"
			}
		}
		// Modules sanitise their own keys, each ignoring sections that aren't theirs: [ $options, $all_values_accepted ].
		return apply_filters( 'mailroom_sanitize', [ $o, $ok ], $post, $section );
	}

	public function handle_save() : void {
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mailroom' ) );
		}
		Mailroom_UI::require_post();
		check_admin_referer( 'mailroom_save' );
		$section = sanitize_key( wp_unslash( (string) ( $_POST['section'] ?? '' ) ) ); // phpcs:ignore
		if ( ! self::is_section( $section ) ) {
			Mailroom_UI::finish( __( 'Unknown settings section; nothing was saved.', 'mailroom' ), 'overview' );
		}
		$current  = wp_parse_args( (array) get_option( Mailroom::OPT, [] ), Mailroom::defaults() );
		[ $o, $ok ] = self::sanitize_section( $section, (array) $_POST, $current ); // phpcs:ignore

		update_option( Mailroom::OPT, $o, false );
		wp_safe_redirect( Mailroom_UI::tab_url( $section, [ 'updated' => $ok ? 1 : 0 ] ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Export, filters
	 * ------------------------------------------------------------------ */

	/** CSV export — capability + nonce enforced, formula-injection escaped. */
	public function handle_export() : void {
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mailroom' ) );
		}
		check_admin_referer( 'mailroom_export' );

		$rows = self::export_rows( self::filters( $_GET ) ); // phpcs:ignore

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mailroom-log-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		$cols = [ 'created_at', 'status', 'source', 'mailer', 'to_email', 'subject', 'error' ];
		// Escape character disabled (''): Excel has no escape char, and the default "\" lets a value
		// close its field and start the next one with a formula.
		fputcsv( $out, $cols, ',', '"', '' );
		foreach ( (array) $rows as $r ) {
			$line = [];
			foreach ( $cols as $c ) {
				$line[] = self::csv_safe( (string) ( $r[ $c ] ?? '' ) );
			}
			fputcsv( $out, $line, ',', '"', '' );
		}
		fclose( $out );
		exit;
	}

	/** Normalised list/export filters from a request array. Invalid values become ''. */
	public static function filters( array $in ) : array {
		$status = sanitize_key( wp_unslash( (string) ( $in['status'] ?? '' ) ) );
		$date   = function ( $v ) {
			$v = (string) wp_unslash( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		return [
			'status' => in_array( $status, self::STATUSES, true ) ? $status : '',
			'from'   => $date( $in['from'] ?? '' ),
			'to'     => $date( $in['to'] ?? '' ),
			'q'      => mb_substr( sanitize_text_field( wp_unslash( (string) ( $in['q'] ?? '' ) ) ), 0, 100 ),
		];
	}

	/** @return array{0:string,1:array} WHERE clause with placeholders, and its args */
	public static function where( array $f ) : array {
		global $wpdb;
		$where = 'WHERE 1=1';
		$args  = [];
		if ( $f['status'] !== '' ) {
			$where .= ' AND status = %s';
			$args[] = $f['status'];
		}
		if ( $f['from'] !== '' ) {
			$where .= ' AND created_at >= %s';
			$args[] = $f['from'] . ' 00:00:00';
		}
		if ( $f['to'] !== '' ) {
			$where .= ' AND created_at <= %s';
			$args[] = $f['to'] . ' 23:59:59';
		}
		if ( $f['q'] !== '' ) {
			$like   = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$where .= ' AND ( to_email LIKE %s OR subject LIKE %s )';
			$args[] = $like;
			$args[] = $like;
		}
		return [ $where, $args ];
	}

	public static function export_rows( array $f ) : array {
		global $wpdb;
		$table            = Mailroom::table();
		[ $where, $args ] = self::where( $f );
		$sql              = "SELECT created_at,status,source,mailer,to_email,subject,error FROM {$table} {$where} ORDER BY id DESC LIMIT 10000";
		return (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, ...$args ) : $sql, ARRAY_A ); // phpcs:ignore
	}

	private static function csv_safe( string $v ) : string {
		// Neutralise spreadsheet formula injection.
		if ( $v !== '' && in_array( $v[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $v;
		}
		return $v;
	}

	/* ---------------------------------------------------------------------
	 * Dashboard widget
	 * ------------------------------------------------------------------ */

	public function dashboard_widget() : void {
		if ( ! $this->can() ) {
			return;
		}
		wp_add_dashboard_widget( 'mailroom_report', 'Email delivery (last 7 days)', [ $this, 'widget_body' ] );
	}

	public function widget_body() : void {
		global $wpdb;
		$table = Mailroom::table();
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) c FROM {$table} WHERE created_at >= %s GROUP BY status", $since ), ARRAY_A ); // phpcs:ignore
		$map = [];
		foreach ( (array) $rows as $r ) {
			$map[ $r['status'] ] = (int) $r['c'];
		}
		$sent = ( $map['sent'] ?? 0 ) + ( $map['sent_backup'] ?? 0 );
		$fail = $map['failed'] ?? 0; // same definition as the Overview (Reports::stats): a failed_backup row is the backup's attempt at an email whose primary failure is already counted
		echo '<ul>';
		printf( '<li>Sent: <strong>%d</strong></li>', $sent );
		printf( '<li>Failed: <strong style="color:%s">%d</strong></li>', $fail ? '#b32d2e' : 'inherit', $fail );
		if ( ! empty( $map['sent_backup'] ) ) {
			printf( '<li>Delivered via backup: <strong>%d</strong></li>', (int) $map['sent_backup'] );
		}
		echo '</ul>';
		printf( '<p><a href="%s">Email delivery overview</a> &middot; <a href="%s">Log</a></p>', esc_url( Mailroom_UI::tab_url( 'overview' ) ), esc_url( Mailroom_UI::tab_url( 'log' ) ) );
	}
}
