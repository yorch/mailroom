<?php
/**
 * Bridge: the one place that decides whether our admin screens render INSIDE WP Mail SMTP's own admin
 * ("native integration") or on our standalone page (page=mailroom-log, the 2.0 behaviour).
 *
 * Native integration is ON only when every check passes: our admin module is loaded, WP Mail SMTP is active
 * and its version is in the tested range, every WP Mail SMTP class/method we hook exists, the kill-switch
 * constant MAILROOM_NATIVE_UI is not false, and the `mailroom_native_ui` filter does not say no.
 * On ANY failure everything below is inert and the standalone page, menu replacement and redirects behave
 * exactly as before. The filters this class registers only swap page/tab objects; storage, handlers and the mail
 * engine are not involved. Every callback is wrapped and returns its input untouched on any doubt.
 *
 * This file defines no class that extends a WP Mail SMTP class (mu-plugins load BEFORE regular plugins, so the
 * parent would not exist yet). Those live in class-native.php, which is required lazily, only after the checks
 * pass, and is deliberately NOT in the loader list.
 *
 * Phase P3 (WP Mail SMTP's own Dashboard page fed from our log) has its OWN independent decision on top of
 * this one: dashboard_active() = native integration ON + MAILROOM_NATIVE_DASHBOARD not false + the
 * `mailroom_native_dashboard` filter not saying no + every Dashboard class we extend exists and every method
 * we override still has the exact signature class-native-dashboard.php was written against (checked by reflection
 * BEFORE that file is required: a mismatch would otherwise be a compile-time fatal). Any failure turns off ONLY P3;
 * the tabs above keep working. Its subclasses live in class-native-dashboard.php (lazy, not in the loader list).
 *
 * Timing (verified in 4.10.0): the admin Area is built in Core::init() on the `init` action (Core.php:208,
 * get_admin() at 373), and its pages/tabs are read then or later, so registering from boot() (plugins_loaded 20)
 * is early enough. The `wp_mail_smtp_core_get_*` class-swap filters (which we do not use) are read at
 * plugins_loaded 10 and would have to be added at include time.
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Bridge {

	/** Tested WP Mail SMTP range: MIN_VERSION <= version < MAX_VERSION_EXCL. Widen only after re-verifying every seam. */
	const MIN_VERSION      = '4.10.0';
	const MAX_VERSION_EXCL = '4.11.0';

	/** Filter (default true) that can switch native integration off. It can never switch it on. */
	const FILTER = 'mailroom_native_ui';

	/** Logical tab key => [ WP Mail SMTP admin page, tab ]. An empty tab means the page has no tabs. */
	const MAP = [
		'overview'    => [ 'wp-mail-smtp-reports', 'reports' ],
		'reports'     => [ 'wp-mail-smtp-reports', 'weekly' ],
		'log'         => [ 'wp-mail-smtp-logs', '' ],
		'storage'     => [ 'wp-mail-smtp', 'logs' ],
		'alerts'      => [ 'wp-mail-smtp', 'alerts' ],
		'controls'    => [ 'wp-mail-smtp', 'control' ],
		'delivery'    => [ 'wp-mail-smtp', 'connections' ],
		'connections' => [ 'wp-mail-smtp', 'connections' ],
		'routing'     => [ 'wp-mail-smtp', 'routing' ],
		'ratelimit'   => [ 'wp-mail-smtp', 'routing' ],
		'export'      => [ 'wp-mail-smtp-tools', 'export' ],
	];

	/** Classes (and one interface) in class-native.php; all must exist or native stays off. */
	const NATIVE_CLASSES = [
		'Mailroom_Native_Logs',
		'Mailroom_Native_Storage_Tab',
		'Mailroom_Native_Alerts_Tab',
		'Mailroom_Native_Controls_Tab',
		'Mailroom_Native_Connections_Tab',
		'Mailroom_Native_Routing_Tab',
		'Mailroom_Native_Export_Tab',
		'Mailroom_Native_Overview_Tab',
		'Mailroom_Native_Weekly_Tab',
	];

	/** P3 kill switch: constant MAILROOM_NATIVE_DASHBOARD = false, or this filter (default true; it can never switch P3 on). */
	const DASHBOARD_FILTER = 'mailroom_native_dashboard';

	/** Classes in class-native-dashboard.php; all must exist or P3 stays off. */
	const DASHBOARD_CLASSES = [
		'Mailroom_Native_Dashboard',
		'Mailroom_Native_Stats',
		'Mailroom_Native_Stat_Cards',
		'Mailroom_Native_Emails_Overview',
		'Mailroom_Native_Email_Log',
		'Mailroom_Native_Email_Sources',
	];

	/** WP Mail SMTP widget class => our subclass (swapped in the widget pipeline, matched on the exact class). */
	const DASHBOARD_WIDGETS = [
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\StatCards'       => 'Mailroom_Native_Stat_Cards',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailsOverview'  => 'Mailroom_Native_Emails_Overview',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailLog'        => 'Mailroom_Native_Email_Log',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailSources'    => 'Mailroom_Native_Email_Sources',
	];

	/**
	 * Parent classes class-native-dashboard.php extends => the constructor signature we construct them with
	 * (null = no constructor). Every one must be concrete and not final.
	 */
	const DASHBOARD_PARENTS = [
		'WPMailSMTP\\Admin\\Dashboard\\Dashboard'                => null,
		'WPMailSMTP\\Admin\\Dashboard\\Stats'                    => null,
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\StatCards'       => 'public(WPMailSMTP\\Admin\\Dashboard\\AccessContext)',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailsOverview'  => 'public(WPMailSMTP\\Admin\\Dashboard\\AccessContext)',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailLog'        => 'public(WPMailSMTP\\Admin\\Dashboard\\AccessContext)',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailSources'    => 'public(WPMailSMTP\\Admin\\Dashboard\\AccessContext)',
	];

	/**
	 * Every WP Mail SMTP method class-native-dashboard.php overrides or calls on the parent, with the signature it
	 * was written against (see signature(): visibility, static/final/abstract, parameter types with `*` for untyped
	 * and `=` for optional, return type). Exact match required: the overrides repeat these signatures verbatim, and
	 * PHP turns any incompatibility into a compile-time fatal that try/catch cannot catch.
	 */
	const DASHBOARD_SIGNATURES = [
		'WPMailSMTP\\Admin\\Dashboard\\Dashboard::get_stats'                       => 'protected()',
		'WPMailSMTP\\Admin\\Dashboard\\Stats::get_sent_counters'                   => 'protected()',
		'WPMailSMTP\\Admin\\Dashboard\\Stats::get_failed_counters'                 => 'protected()',
		'WPMailSMTP\\Admin\\Dashboard\\Stats::get_counters'                        => 'protected(*)',
		'WPMailSMTP\\Admin\\Dashboard\\Stats::get_stat_metrics'                    => 'protected():array',
		'WPMailSMTP\\Admin\\Dashboard\\Stats::get_stat_totals'                     => 'public(?array=):array',
		'WPMailSMTP\\Admin\\Dashboard\\Stats::get_delta'                           => 'public(*)',
		'WPMailSMTP\\Admin\\Dashboard\\Stats::get_series'                          => 'public(?array=):array',
		'WPMailSMTP\\Admin\\Dashboard\\Stats::get_week_number'                     => 'protected(*=)',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\StatCards::get_cards'              => 'public(array)',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailsOverview::get_stats'         => 'protected()',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailsOverview::get_email_alerts_card' => 'protected(array):array',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailLog::get_state'               => 'public():WPMailSMTP\\Admin\\Dashboard\\WidgetState',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailLog::render_body'             => 'protected(string,array):string',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailSources::get_state'           => 'public():WPMailSMTP\\Admin\\Dashboard\\WidgetState',
		'WPMailSMTP\\Admin\\Dashboard\\Widgets\\EmailSources::render_body'         => 'protected(string,array):string',
	];

	/** @var array|null memoised environment (the filter is NOT memoised) */
	private static $env = null;

	/** @var string[]|null memoised P3 probe: the Dashboard symbols/signatures that do not match ([] = all good) */
	private static $dashboard_probe = null;

	/** @var array<string,object> */
	private static $instances = [];

	public static function boot() : void {
		add_filter( 'wp_mail_smtp_admin_get_pages', [ __CLASS__, 'filter_pages' ] );
		add_filter( 'wp_mail_smtp_admin_display_get_logs_fqcn', [ __CLASS__, 'filter_logs_fqcn' ] );
		add_filter( 'wp_mail_smtp_admin_page_tools_tabs', [ __CLASS__, 'filter_tools_tabs' ] );
		add_filter( 'wp_mail_smtp_admin_page_reports_tabs', [ __CLASS__, 'filter_reports_tabs' ] );
		// Runs in cron/Action Scheduler too, so it is not admin-gated.
		add_filter( 'wp_mail_smtp_reports_emails_summary_is_disabled', [ __CLASS__, 'filter_summary_disabled' ] );
		// P3. Each callback re-evaluates dashboard_active() lazily, so a switch-off takes effect without a reboot.
		add_filter( 'wp_mail_smtp_admin_area_get_dashboard', [ __CLASS__, 'filter_dashboard' ] );
		add_filter( 'wp_mail_smtp_admin_dashboard_widget_pipeline_get_widgets', [ __CLASS__, 'filter_dashboard_widgets' ], 10, 2 );
		add_filter( 'wp_mail_smtp_admin_dashboard_widget', [ __CLASS__, 'filter_wpms_dashboard_widget' ] );
	}

	/* ---------------------------------------------------------------------
	 * The decision
	 * ------------------------------------------------------------------ */

	public static function active() : bool {
		return self::reason() === '';
	}

	/** Why native integration is off ('' = on). Cheap: the environment is computed once per request. */
	public static function reason() : string {
		try {
			$reason = self::check( self::environment() );
			if ( $reason === '' && ! apply_filters( self::FILTER, true ) ) {
				$reason = 'kill-switch-filter';
			}
			return $reason;
		} catch ( \Throwable $e ) {
			return 'error';
		}
	}

	/**
	 * Pure decision over an environment description (testable without WP Mail SMTP).
	 *
	 * @param array{constant?:mixed,ours?:bool,version?:string,missing?:string[]} $env `constant` null = not defined
	 * @return string '' when native integration may be ON, otherwise the reason code
	 */
	public static function check( array $env ) : string {
		if ( array_key_exists( 'constant', $env ) && $env['constant'] !== null && ! $env['constant'] ) {
			return 'kill-switch-constant';
		}
		if ( empty( $env['ours'] ) ) {
			return 'module-missing';
		}
		$version = (string) ( $env['version'] ?? '' );
		if ( $version === '' ) {
			return 'wpms-missing';
		}
		if ( version_compare( $version, self::MIN_VERSION, '<' ) || version_compare( $version, self::MAX_VERSION_EXCL, '>=' ) ) {
			return 'version';
		}
		if ( ! empty( $env['missing'] ) ) {
			return 'missing:' . implode( ',', (array) $env['missing'] );
		}
		return '';
	}

	public static function reset() : void {
		self::$env             = null;
		self::$instances       = [];
		self::$dashboard_probe = null;
	}

	private static function environment() : array {
		if ( self::$env !== null ) {
			return self::$env;
		}
		$env = [
			'constant' => defined( 'MAILROOM_NATIVE_UI' ) ? MAILROOM_NATIVE_UI : null,
			'ours'     => class_exists( 'Mailroom_Admin' ) && class_exists( 'Mailroom_UI' ) && Mailroom::instance()->module( 'admin' ) !== null,
			'version'  => ( function_exists( 'wp_mail_smtp' ) && defined( 'WPMS_PLUGIN_VER' ) ) ? (string) WPMS_PLUGIN_VER : '',
			'missing'  => [],
		];
		// Only probe WP Mail SMTP (and load our subclasses) once the cheap checks pass.
		if ( self::check( $env ) === '' ) {
			$env['missing'] = self::missing_symbols();
			if ( ! $env['missing'] && ! self::load_native() ) {
				$env['missing'] = [ 'class-native.php' ];
			}
		}
		if ( did_action( 'plugins_loaded' ) ) {
			self::$env = $env; // never memoise a verdict reached before WP Mail SMTP could have loaded
		}
		return $env;
	}

	/** @return string[] the WP Mail SMTP symbols we rely on that do not exist */
	private static function missing_symbols() : array {
		$missing = [];
		foreach ( [
			'WPMailSMTP\Admin\PageInterface',
			'WPMailSMTP\Admin\PageAbstract',
			'WPMailSMTP\Admin\ParentPageAbstract',
			'WPMailSMTP\Admin\Area',
			'WPMailSMTP\Admin\Pages\Logs',
			'WPMailSMTP\Admin\Pages\LogsTab',
			'WPMailSMTP\Admin\Pages\AlertsTab',
			'WPMailSMTP\Admin\Pages\ControlTab',
			'WPMailSMTP\Admin\Pages\AdditionalConnectionsTab',
			'WPMailSMTP\Admin\Pages\SmartRoutingTab',
			'WPMailSMTP\Admin\Pages\ExportTab',
			'WPMailSMTP\Admin\Pages\EmailReportsTab',
			'WPMailSMTP\Admin\Pages\EmailReports',
			'WPMailSMTP\Admin\Pages\Tools',
			'WPMailSMTP\Reports\Emails\Summary',
		] as $name ) {
			if ( ! class_exists( $name ) && ! interface_exists( $name ) ) {
				$missing[] = $name;
			}
		}
		if ( $missing ) {
			return $missing;
		}
		foreach ( [
			[ 'WPMailSMTP\Admin\Area', 'get_pages' ],
			[ 'WPMailSMTP\Admin\Area', 'get_parent_pages' ],
			[ 'WPMailSMTP\Admin\Area', 'generate_display_logs_object' ],
			[ 'WPMailSMTP\Admin\PageAbstract', 'get_link' ],
			[ 'WPMailSMTP\Admin\PageAbstract', 'get_slug' ],
			[ 'WPMailSMTP\Admin\PageAbstract', 'get_priority' ],
			[ 'WPMailSMTP\Admin\ParentPageAbstract', 'get_tabs' ],
			[ 'WPMailSMTP\Reports\Emails\Summary', 'is_disabled' ],
		] as [ $class, $method ] ) {
			if ( ! method_exists( $class, $method ) ) {
				$missing[] = $class . '::' . $method;
			}
		}
		// class-native.php overrides these as untyped, parameterless, non-static public methods. If a WP Mail SMTP
		// update changed a parent signature (added a return/param type, a parameter, `final`, `static`), requiring
		// class-native.php would be a COMPILE-TIME fatal that try/catch cannot catch, on every request that probes
		// the bridge (admin + cron). Reflection is catchable, so verify compatibility here, before the require.
		foreach ( [ 'get_label', 'display', 'get_link' ] as $method ) {
			try {
				$rm = new \ReflectionMethod( 'WPMailSMTP\Admin\PageAbstract', $method );
				if ( $rm->hasReturnType() || $rm->getNumberOfParameters() !== 0 || $rm->isFinal() || $rm->isStatic() || ! $rm->isPublic() ) {
					$missing[] = 'PageAbstract::' . $method . ' (signature changed)';
				}
			} catch ( \Throwable $e ) {
				$missing[] = 'PageAbstract::' . $method;
			}
		}
		if ( ! defined( 'WPMailSMTP\Admin\Area::SLUG' ) || \WPMailSMTP\Admin\Area::SLUG !== 'wp-mail-smtp' ) {
			$missing[] = 'Area::SLUG';
		}
		if ( ! is_object( wp_mail_smtp() ) || ! method_exists( wp_mail_smtp(), 'get_admin' ) ) {
			$missing[] = 'wp_mail_smtp()->get_admin';
		}
		return $missing;
	}

	/** Require class-native.php (it extends WP Mail SMTP classes, so never before the checks pass). */
	private static function load_native() : bool {
		$file = __DIR__ . '/class-native.php';
		if ( ! is_readable( $file ) ) {
			return false;
		}
		try {
			require_once $file;
		} catch ( \Throwable $e ) {
			// fall through: a half-declared file counts as not loaded
		}
		foreach ( self::NATIVE_CLASSES as $class ) {
			if ( ! class_exists( $class, false ) ) {
				return false;
			}
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * URLs and page detection
	 * ------------------------------------------------------------------ */

	/** Native admin URL for one of our logical tabs ('' when the key is unknown). */
	public static function url( string $key, array $args = [] ) : string {
		if ( ! isset( self::MAP[ $key ] ) ) {
			return '';
		}
		[ $page, $tab ] = self::MAP[ $key ];
		$query          = [ 'page' => $page ];
		if ( $tab !== '' ) {
			$query['tab'] = $tab;
		}
		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/** True while the request is for a WP Mail SMTP screen that renders our content (assets are printed only then). */
	public static function is_native_page() : bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore
		switch ( $page ) {
			case 'wp-mail-smtp-logs':
			case 'wp-mail-smtp-reports':
				$ours = true;
				break;
			case 'wp-mail-smtp':
				$ours = in_array( $tab, [ 'logs', 'alerts', 'control', 'connections', 'routing' ], true );
				break;
			case 'wp-mail-smtp-tools':
				$ours = $tab === 'export';
				break;
			default:
				$ours = false;
		}
		return $ours && self::active(); // the cheap URL test first: this runs on every admin page
	}

	/* ---------------------------------------------------------------------
	 * Filter callbacks (each returns its input untouched on any doubt)
	 * ------------------------------------------------------------------ */

	/** Settings tabs: swap ours in by slug (order is preserved) and drop the upgrade tab. */
	public static function filter_pages( $pages ) {
		try {
			if ( ! is_array( $pages ) || ! self::active() ) {
				return $pages;
			}
			foreach ( [
				'logs'        => 'Mailroom_Native_Storage_Tab',
				'alerts'      => 'Mailroom_Native_Alerts_Tab',
				'control'     => 'Mailroom_Native_Controls_Tab',
				'connections' => 'Mailroom_Native_Connections_Tab',
				'routing'     => 'Mailroom_Native_Routing_Tab',
			] as $slug => $class ) {
				if ( isset( $pages[ $slug ] ) ) {
					$pages[ $slug ] = self::instance( $class );
				}
			}
			unset( $pages['get-pro'] );
		} catch ( \Throwable $e ) {
			return $pages;
		}
		return $pages;
	}

	/** The "Email Log" submenu: our class (not Pages\Logs), so the plugin's redirect to its upsell tab does not fire. */
	public static function filter_logs_fqcn( $fqcn ) {
		try {
			return self::active() ? 'Mailroom_Native_Logs' : $fqcn;
		} catch ( \Throwable $e ) {
			return $fqcn;
		}
	}

	/** Tools → Export. $tabs maps slug => class name (the plugin instantiates them). */
	public static function filter_tools_tabs( $tabs ) {
		try {
			if ( is_array( $tabs ) && isset( $tabs['export'] ) && self::active() ) {
				$tabs['export'] = 'Mailroom_Native_Export_Tab';
			}
		} catch ( \Throwable $e ) {
			return $tabs;
		}
		return $tabs;
	}

	/** Email Reports: the Overview in the plugin's own tab, plus a second tab for the weekly report settings. */
	public static function filter_reports_tabs( $tabs ) {
		try {
			if ( is_array( $tabs ) && isset( $tabs['reports'] ) && self::active() ) {
				$tabs['reports'] = 'Mailroom_Native_Overview_Tab';
				$tabs['weekly']  = 'Mailroom_Native_Weekly_Tab';
			}
		} catch ( \Throwable $e ) {
			return $tabs;
		}
		return $tabs;
	}

	/** Our weekly report replaces Lite's own summary email, but only while ours is switched on. */
	public static function filter_summary_disabled( $disabled ) {
		try {
			if ( self::active() && Mailroom::opt( 'report_enabled' ) ) {
				return true;
			}
		} catch ( \Throwable $e ) {
			return $disabled;
		}
		return $disabled;
	}

	/* ---------------------------------------------------------------------
	 * Phase P3: WP Mail SMTP's Dashboard page. A decision independent of (but layered on) the one above.
	 * ------------------------------------------------------------------ */

	public static function dashboard_active() : bool {
		return self::dashboard_reason() === '';
	}

	/** Why P3 is off ('' = on). Native integration off always means P3 off; P3 off never affects native integration. */
	public static function dashboard_reason() : string {
		try {
			$env    = [
				'constant' => defined( 'MAILROOM_NATIVE_DASHBOARD' ) ? MAILROOM_NATIVE_DASHBOARD : null,
				'native'   => self::active(),
				'ours'     => class_exists( 'Mailroom_Dashdata' ),
				'missing'  => [],
			];
			// Only probe WP Mail SMTP (and load our subclasses) once the cheap checks pass.
			if ( self::check_dashboard( $env ) === '' ) {
				$env['missing'] = self::dashboard_probe();
			}
			$reason = self::check_dashboard( $env );
			if ( $reason === '' && ! apply_filters( self::DASHBOARD_FILTER, true ) ) {
				$reason = 'kill-switch-filter';
			}
			return $reason;
		} catch ( \Throwable $e ) {
			return 'error';
		}
	}

	/**
	 * Pure P3 decision (testable without WP Mail SMTP).
	 *
	 * @param array{constant?:mixed,native?:bool,ours?:bool,missing?:string[]} $env `constant` null = not defined
	 * @return string '' when P3 may be ON, otherwise the reason code
	 */
	public static function check_dashboard( array $env ) : string {
		if ( array_key_exists( 'constant', $env ) && $env['constant'] !== null && ! $env['constant'] ) {
			return 'kill-switch-constant';
		}
		if ( empty( $env['native'] ) ) {
			return 'native-off';
		}
		if ( empty( $env['ours'] ) ) {
			return 'module-missing';
		}
		if ( ! empty( $env['missing'] ) ) {
			return 'missing:' . implode( ',', (array) $env['missing'] );
		}
		return '';
	}

	/** @return string[] what is wrong with the Dashboard seams ([] = good and class-native-dashboard.php is loaded); memoised once WP Mail SMTP can have loaded */
	private static function dashboard_probe() : array {
		if ( self::$dashboard_probe !== null ) {
			return self::$dashboard_probe;
		}
		$missing = self::dashboard_missing();
		if ( ! $missing && ! self::load_dashboard() ) {
			$missing = [ 'class-native-dashboard.php' ];
		}
		if ( did_action( 'plugins_loaded' ) ) {
			self::$dashboard_probe = $missing;
		}
		return $missing;
	}

	/**
	 * Parent classes, constants and the exact signature of every method we override or call. Reflection is
	 * catchable; the compile-time fatal an incompatible override would cause is not, so this runs BEFORE
	 * class-native-dashboard.php is required.
	 *
	 * @return string[]
	 */
	private static function dashboard_missing() : array {
		$missing = [];
		foreach ( array_merge( [ 'WPMailSMTP\Admin\Dashboard\WidgetState', 'WPMailSMTP\Admin\Dashboard\AccessContext' ], array_keys( self::DASHBOARD_PARENTS ) ) as $name ) {
			if ( ! class_exists( $name ) ) {
				$missing[] = $name;
			}
		}
		if ( ! function_exists( 'wp_mail_smtp_render' ) ) {
			$missing[] = 'wp_mail_smtp_render';
		}
		if ( $missing ) {
			return $missing;
		}
		$missing = array_merge( self::parent_problems( self::DASHBOARD_PARENTS ), self::signature_mismatches( self::DASHBOARD_SIGNATURES ) );
		if ( ! defined( 'WPMailSMTP\\Admin\\Dashboard\\Page::SLUG' ) ) {
			$missing[] = 'Page::SLUG';
		}
		// Our series window must equal the plugin's, or the weekly keys would not line up.
		if ( ! defined( 'WPMailSMTP\Admin\Dashboard\Stats::WEEKS' ) || \WPMailSMTP\Admin\Dashboard\Stats::WEEKS !== Mailroom_Dashdata::WEEKS ) {
			$missing[] = 'Stats::WEEKS';
		}
		return $missing;
	}

	/**
	 * Parent classes that are missing, final/abstract, or constructed differently from what we assume.
	 *
	 * @param array<string,string|null> $parents class => expected constructor signature (null = no constructor)
	 * @return string[]
	 */
	public static function parent_problems( array $parents ) : array {
		$bad = [];
		foreach ( $parents as $class => $ctor ) {
			try {
				$rc = new \ReflectionClass( $class );
				if ( $rc->isFinal() || $rc->isAbstract() || ! $rc->isInstantiable() ) {
					$bad[] = $class . ' (not extensible)';
					continue;
				}
				$rm = $rc->getConstructor();
				if ( ( $rm === null ? null : self::signature( $rm ) ) !== $ctor ) {
					$bad[] = $class . '::__construct (signature changed)';
				}
			} catch ( \Throwable $e ) {
				$bad[] = $class;
			}
		}
		return $bad;
	}

	/**
	 * Methods that are missing or whose signature differs from the expected one.
	 *
	 * @param array<string,string> $expected 'Class::method' => signature() string
	 * @return string[]
	 */
	public static function signature_mismatches( array $expected ) : array {
		$bad = [];
		foreach ( $expected as $ref => $sig ) {
			try {
				if ( self::signature( new \ReflectionMethod( $ref ) ) !== $sig ) {
					$bad[] = $ref . ' (signature changed)';
				}
			} catch ( \Throwable $e ) {
				$bad[] = $ref;
			}
		}
		return $bad;
	}

	/**
	 * A method's signature as a comparable string: `public(?array=):array`. Visibility, then ` static`/` final`/
	 * ` abstract`, then each parameter (`&`, `...`, its type or `*` when untyped, `=` when optional), then the
	 * return type. Parameter names and default values are deliberately left out: neither can make an override fatal.
	 */
	public static function signature( \ReflectionMethod $rm ) : string {
		$params = [];
		foreach ( $rm->getParameters() as $p ) {
			$params[] = ( $p->isPassedByReference() ? '&' : '' ) . ( $p->isVariadic() ? '...' : '' ) . ( $p->hasType() ? (string) $p->getType() : '*' ) . ( $p->isOptional() ? '=' : '' );
		}
		return ( $rm->isPublic() ? 'public' : ( $rm->isProtected() ? 'protected' : 'private' ) )
			. ( $rm->isStatic() ? ' static' : '' ) . ( $rm->isFinal() ? ' final' : '' ) . ( $rm->isAbstract() ? ' abstract' : '' )
			. '(' . implode( ',', $params ) . ')' . ( $rm->hasReturnType() ? ':' . $rm->getReturnType() : '' );
	}

	/** Require class-native-dashboard.php (it extends WP Mail SMTP classes, so never before dashboard_missing() is empty). */
	private static function load_dashboard() : bool {
		$file = __DIR__ . '/class-native-dashboard.php';
		if ( ! is_readable( $file ) ) {
			return false;
		}
		try {
			require_once $file;
		} catch ( \Throwable $e ) {
			// fall through: a half-declared file counts as not loaded
		}
		foreach ( self::DASHBOARD_CLASSES as $class ) {
			if ( ! class_exists( $class, false ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * True while the request is for the given wp-admin file (`$pagenow`) and, when $page is given, that `?page=`.
	 * P3 only matters on two screens, so its callbacks do nothing (not even the environment probe) anywhere else:
	 * the admin Area is also built for every admin-ajax.php / admin-post.php request, front-end visitors included.
	 */
	private static function on_screen( string $file, string $page = '' ) : bool {
		if ( ( $GLOBALS['pagenow'] ?? '' ) !== $file ) {
			return false;
		}
		return $page === '' || ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === $page ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * The Dashboard orchestrator (the filter runs when the admin Area is built, on `init`). Ours replaces the plugin's
	 * own class, and only on the Dashboard page itself (the page controller it builds is used nowhere else).
	 */
	public static function filter_dashboard( $dashboard ) {
		try {
			if ( is_object( $dashboard ) && get_class( $dashboard ) === 'WPMailSMTP\Admin\Dashboard\Dashboard'
				&& class_exists( 'WPMailSMTP\Admin\Dashboard\Page' ) && self::on_screen( 'admin.php', \WPMailSMTP\Admin\Dashboard\Page::SLUG ) && self::dashboard_active() ) {
				return new Mailroom_Native_Dashboard();
			}
		} catch ( \Throwable $e ) {
			return $dashboard;
		}
		return $dashboard;
	}

	/** The widget list (filter runs when the Dashboard page is rendered): swap the four widgets we feed, keep the rest. */
	public static function filter_dashboard_widgets( $widgets, $access = null ) {
		try {
			if ( ! is_array( $widgets ) || ! $access instanceof \WPMailSMTP\Admin\Dashboard\AccessContext || ! self::dashboard_active() ) {
				return $widgets;
			}
			$out = $widgets;
			foreach ( $out as $i => $widget ) {
				if ( is_object( $widget ) && isset( self::DASHBOARD_WIDGETS[ get_class( $widget ) ] ) ) {
					$class     = self::DASHBOARD_WIDGETS[ get_class( $widget ) ];
					$out[ $i ] = new $class( $access );
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			return $widgets;
		}
	}

	/**
	 * WP Mail SMTP's own wp-admin index widget has its documented off switch (read on `admin_init`, DashboardWidget.php).
	 * While P3 is on, our "Email delivery" widget is the only one, so the two never show different numbers. Decided
	 * only on the WordPress dashboard screen (`index.php`, also the network/user dashboards): the widget exists nowhere else.
	 */
	public static function filter_wpms_dashboard_widget( $allow ) {
		try {
			return ( self::on_screen( 'index.php' ) && self::dashboard_active() ) ? false : $allow;
		} catch ( \Throwable $e ) {
			return $allow;
		}
	}

	/** One instance per tab class (the plugin asks for its pages many times per request). */
	private static function instance( string $class ) {
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new $class();
		}
		return self::$instances[ $class ];
	}

	/** Render one of our tabs inside a WP Mail SMTP page. A failure prints a notice instead of breaking the admin page. */
	public static function render( string $key ) : void {
		try {
			$admin = Mailroom::instance()->module( 'admin' );
			if ( $admin ) {
				$admin->render_native( $key );
			}
		} catch ( \Throwable $e ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This section could not be displayed.', 'mailroom' ) . '</p></div>';
		}
	}
}
