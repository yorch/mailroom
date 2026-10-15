<?php
/**
 * Our screens as WP Mail SMTP page/tab objects. Required lazily by Mailroom_Bridge, and only after it has
 * verified that WP Mail SMTP is active, in the tested version range and has every class we extend. NOT in the
 * loader list: the parent classes do not exist yet when mu-plugins are included.
 *
 * The objects are thin: label, slug, priority and a display() that hands over to the Admin module. They have no
 * form handling of their own (no process_post): our forms post to admin-post.php, never with the plugin's
 * `wp-mail-smtp-post` field, so the plugin's own tab-POST path never sees them. The plugin does not wrap a tab's
 * display() in a <form> (Area::display_tabs and ParentPageAbstract::display call it bare), so our per-section
 * forms do not nest.
 */

defined( 'ABSPATH' ) || exit;

/** A tab with a logical key; display() renders that key through the Admin module. */
abstract class Mailroom_Native_Tab extends \WPMailSMTP\Admin\PageAbstract {

	/** @var string logical tab key (see Mailroom_Bridge::MAP) */
	protected $mailroom_key = '';

	public function display() {
		Mailroom_Bridge::render( $this->mailroom_key );
	}
}

/** The "Email Log" submenu page: the log list and the single-email view. */
final class Mailroom_Native_Logs extends \WPMailSMTP\Admin\PageAbstract {

	protected $slug = 'logs';

	public function get_label() {
		return __( 'Email Log', 'mailroom' );
	}

	public function get_link() {
		return Mailroom_Bridge::url( 'log' );
	}

	public function display() {
		?>
		<div class="wp-mail-smtp-page-title"><span class="page-title"><?php echo esc_html( $this->get_title() ); ?></span></div>
		<div class="wp-mail-smtp-page-content">
			<h1 class="screen-reader-text"><?php echo esc_html( $this->get_title() ); ?></h1>
			<?php Mailroom_Bridge::render( 'log' ); ?>
		</div>
		<?php
	}
}

/** Settings → Email Log: storage and retention. */
final class Mailroom_Native_Storage_Tab extends Mailroom_Native_Tab {
	protected $slug     = 'logs';
	protected $mailroom_key = 'storage';

	public function get_label() {
		return __( 'Email Log', 'mailroom' );
	}
}

/** Settings → Alerts. */
final class Mailroom_Native_Alerts_Tab extends Mailroom_Native_Tab {
	protected $slug     = 'alerts';
	protected $priority = 20;
	protected $mailroom_key = 'alerts';

	public function get_label() {
		return __( 'Alerts', 'mailroom' );
	}
}

/** Settings → Email Controls. */
final class Mailroom_Native_Controls_Tab extends Mailroom_Native_Tab {
	protected $slug     = 'control';
	protected $mailroom_key = 'controls';

	public function get_label() {
		return __( 'Email Controls', 'mailroom' );
	}
}

/** Settings → Additional Connections: the backup SMTP connection and failover. */
final class Mailroom_Native_Connections_Tab extends Mailroom_Native_Tab {
	protected $slug     = 'connections';
	protected $mailroom_key = 'connections';

	public function get_label() {
		return __( 'Additional Connections', 'mailroom' );
	}
}

/** Settings → Smart Routing: routing rules, and rate limiting (the plugin's UI has no home for it). */
final class Mailroom_Native_Routing_Tab extends Mailroom_Native_Tab {
	protected $slug     = 'routing';
	protected $mailroom_key = 'routing';

	public function get_label() {
		return __( 'Smart Routing', 'mailroom' );
	}
}

/** Tools → Export. */
final class Mailroom_Native_Export_Tab extends Mailroom_Native_Tab {
	protected $slug     = 'export';
	protected $priority = 20;
	protected $mailroom_key = 'export';

	public function get_label() {
		return __( 'Export', 'mailroom' );
	}
}

/** Email Reports (default tab): the Overview. */
final class Mailroom_Native_Overview_Tab extends Mailroom_Native_Tab {
	protected $slug     = 'reports';
	protected $priority = 10;
	protected $mailroom_key = 'overview';

	public function get_label() {
		return __( 'Email Reports', 'mailroom' );
	}
}

/** Email Reports → Weekly Report: settings and Send now. */
final class Mailroom_Native_Weekly_Tab extends Mailroom_Native_Tab {
	protected $slug     = 'weekly';
	protected $priority = 15;
	protected $mailroom_key = 'reports';

	public function get_label() {
		return __( 'Weekly Report', 'mailroom' );
	}
}
