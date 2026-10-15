<?php
/**
 * Upsells: hide WP Mail SMTP Lite's upgrade advertising in wp-admin, through its own switches only.
 *
 * Every removal uses a seam WP Mail SMTP or WordPress provides, never an edit to the plugin and never
 * CSS: its documented notice-bar filter, its Dashboard widget pipeline and admin pages filters, core's
 * submenu and plugin-action-link APIs, and its own per-user "dismissed" flag for the Settings Pro banner
 * (read as set, which is what clicking its Dismiss button stores). Pro detection
 * (its is-Pro filter), the licence-key field, upgrade URLs and the "X is a Pro feature"
 * modals on locked features are deliberately left alone. The setup checklist has its own site-wide
 * Dismiss button and is not touched here.
 *
 * Independent of the native integration (class-bridge.php): works with it on or off.
 * Kill switch: define( 'MAILROOM_HIDE_UPSELLS', false ), or the `mailroom_hide_upsells`
 * filter returning false. Both are read when each callback fires, so a switch-off needs no reboot.
 *
 * Timing (verified in 4.10.2): WP Mail SMTP builds its admin Area in Core::init() on `init`, which
 * reads the notice-bar filter in Education::hooks(); boot() runs earlier (plugins_loaded 20).
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Upsells {

	const FILTER = 'mailroom_hide_upsells';

	/** Dashboard widgets that only advertise (Pro features, sister plugins). Matched with instanceof. */
	const UPSELL_WIDGETS = [
		'WPMailSMTP\Admin\Dashboard\Widgets\FeaturesUpsell',
		'WPMailSMTP\Admin\Dashboard\Widgets\GrowthTools',
	];

	/** Every Lite upgrade link WP Mail SMTP builds points at this page. */
	const UPGRADE_URL_NEEDLE = 'wpmailsmtp.com/lite-upgrade';

	/** User meta WP Mail SMTP's Settings Pro banner checks (SettingsTab::display_pro_banner()). */
	const PRO_BANNER_META = 'wp_mail_smtp_pro_banner_dismissed';

	/** @var Mailroom */
	private $core;

	public function __construct( Mailroom $core ) {
		$this->core = $core;
	}

	public function boot() : void {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'wp_mail_smtp_admin_education_notice_bar', [ __CLASS__, 'filter_notice_bar' ] );
		add_filter( 'wp_mail_smtp_admin_dashboard_widget_pipeline_get_widgets', [ __CLASS__, 'filter_widgets' ], 20 );
		add_filter( 'wp_mail_smtp_admin_get_pages', [ __CLASS__, 'filter_pages' ], 20 );
		add_action( 'admin_menu', [ __CLASS__, 'remove_upgrade_menu' ], 999 );
		if ( defined( 'WPMS_PLUGIN_FILE' ) ) {
			add_filter( 'plugin_action_links_' . plugin_basename( WPMS_PLUGIN_FILE ), [ __CLASS__, 'filter_action_links' ], 20 );
		}
		add_filter( 'get_user_metadata', [ __CLASS__, 'filter_pro_banner_meta' ], 10, 3 );
	}

	public static function enabled() : bool {
		if ( defined( 'MAILROOM_HIDE_UPSELLS' ) && ! MAILROOM_HIDE_UPSELLS ) {
			return false;
		}
		return (bool) apply_filters( self::FILTER, true );
	}

	/** The "You're using WP Mail SMTP Lite…" bar above every WP Mail SMTP page. */
	public static function filter_notice_bar( $show ) {
		return self::enabled() ? false : $show;
	}

	/** The "Take Your Email Deliverability to the Next Level" and "Growth Tools" boxes on its Dashboard. */
	public static function filter_widgets( $widgets ) {
		try {
			if ( ! is_array( $widgets ) || ! self::enabled() ) {
				return $widgets;
			}
			return array_values( array_filter( $widgets, function ( $widget ) {
				foreach ( self::UPSELL_WIDGETS as $class ) {
					if ( $widget instanceof $class ) {
						return false;
					}
				}
				return true;
			} ) );
		} catch ( \Throwable $e ) {
			return $widgets;
		}
	}

	/** The Settings "Get Pro" tab (the native integration also drops it while it is on). */
	public static function filter_pages( $pages ) {
		if ( is_array( $pages ) && self::enabled() ) {
			unset( $pages['get-pro'] );
		}
		return $pages;
	}

	/** The green "Upgrade to Pro" sidebar item. Its slug is the upgrade URL itself, so match on that. */
	public static function remove_upgrade_menu() : void {
		global $submenu;
		if ( ! self::enabled() || ! class_exists( '\WPMailSMTP\Admin\Area' ) ) {
			return;
		}
		$parent = \WPMailSMTP\Admin\Area::SLUG;
		foreach ( (array) ( $submenu[ $parent ] ?? [] ) as $item ) {
			if ( isset( $item[2] ) && strpos( urldecode( (string) $item[2] ), self::UPGRADE_URL_NEEDLE ) !== false ) {
				remove_submenu_page( $parent, $item[2] );
			}
		}
	}

	/** The green "Get WP Mail SMTP Pro" link in its row on the Plugins screen. */
	public static function filter_action_links( $links ) {
		if ( is_array( $links ) && self::enabled() ) {
			unset( $links['wp-mail-smtp-pro'] );
		}
		return $links;
	}

	/** Every admin reads as having clicked Dismiss on the Settings Pro banner. The stored meta is not changed. */
	public static function filter_pro_banner_meta( $value, $object_id, $meta_key ) {
		if ( $meta_key === self::PRO_BANNER_META && self::enabled() ) {
			return [ '1' ];
		}
		return $value;
	}
}
