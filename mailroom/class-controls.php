<?php
/**
 * Email Controls: switch off WordPress core notification emails the site doesn't need.
 *
 * Nothing is disabled by default. Each control is a core filter (or, for the admin "password reset"
 * notice, removal of the core action) that consults the saved setting when it fires, so a change
 * takes effect on the next request. Security-relevant mail (password reset links, admin-email change
 * confirmations, personal-data requests) is deliberately not offered.
 */

defined( 'ABSPATH' ) || exit;

final class Mailroom_Controls {

	const SECTION = 'controls';

	/** slug => [ group, label, core filter that returns a boolean "send?" (null = special) ] */
	const CONTROLS = [
		'comment_moderation'   => [ 'Comments', 'Comment awaiting moderation (to admin / moderators)', 'notify_moderator' ],
		'comment_post_author'  => [ 'Comments', 'New comment published (to the post author)', 'notify_post_author' ],
		'new_user_admin'       => [ 'Users', 'New user registered (to admin)', 'wp_send_new_user_notification_to_admin' ],
		'new_user_user'        => [ 'Users', 'New user welcome / set-password email (to the user)', 'wp_send_new_user_notification_to_user' ],
		'password_changed'     => [ 'Users', 'Your password was changed (to the user)', 'send_password_change_email' ],
		'email_changed'        => [ 'Users', 'Your email address was changed (to the user)', 'send_email_change_email' ],
		'password_reset_admin' => [ 'Users', 'A user reset their password (to admin)', null ],
		'auto_update_core'     => [ 'Automatic updates', 'WordPress core auto-update results', 'auto_core_update_send_email' ],
		'auto_update_plugins'  => [ 'Automatic updates', 'Plugin auto-update results', 'auto_plugin_update_send_email' ],
		'auto_update_themes'   => [ 'Automatic updates', 'Theme auto-update results', 'auto_theme_update_send_email' ],
	];

	/** @var Mailroom */
	private $core;

	public function __construct( Mailroom $core ) {
		$this->core = $core;
	}

	public function boot() : void {
		foreach ( self::CONTROLS as $slug => $def ) {
			if ( $def[2] === null ) {
				continue;
			}
			add_filter( $def[2], function ( $send = true ) use ( $slug ) {
				return self::disabled( $slug ) ? false : $send;
			}, PHP_INT_MAX );
		}
		// Core: add_action( 'after_password_reset', 'wp_password_change_notification' ). No filter
		// exists to cancel it, so remove it just before it runs (WP_Hook supports removal mid-dispatch).
		add_action( 'after_password_reset', [ $this, 'maybe_remove_reset_notice' ], 0 );

		add_action( 'mailroom_settings_controls', [ $this, 'settings_rows' ] );
		add_filter( 'mailroom_sanitize', [ $this, 'sanitize' ], 10, 3 );
	}

	public static function disabled( string $slug ) : bool {
		return in_array( $slug, (array) Mailroom::opt( 'controls_off' ), true );
	}

	public function maybe_remove_reset_notice() : void {
		if ( self::disabled( 'password_reset_admin' ) ) {
			remove_action( 'after_password_reset', 'wp_password_change_notification' );
		}
	}

	public function settings_rows( array $o ) : void {
		$off    = (array) $o['controls_off'];
		$groups = [];
		foreach ( self::CONTROLS as $slug => $def ) {
			$groups[ $def[0] ][ $slug ] = $def[1];
		}
		?>
		<h2><?php esc_html_e( 'Email controls', 'mailroom' ); ?></h2>
		<p class="description"><?php echo wp_kses( __( 'Tick an email to <strong>stop</strong> WordPress sending it. Nothing is disabled by default. WooCommerce and LearnDash emails have their own settings.', 'mailroom' ), [ 'strong' => [] ] ); ?></p>
		<table class="form-table" role="presentation">
		<?php foreach ( $groups as $group => $items ) : ?>
			<tr><th scope="row"><?php echo esc_html( $group ); ?></th><td><fieldset><legend class="screen-reader-text"><span><?php echo esc_html( $group ); ?></span></legend>
			<?php foreach ( $items as $slug => $label ) : ?>
				<label for="mailroom-ctl-<?php echo esc_attr( $slug ); ?>" style="display:block;margin-bottom:4px"><input type="checkbox" id="mailroom-ctl-<?php echo esc_attr( $slug ); ?>" name="controls_off[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $off, true ) ); ?>> <?php echo esc_html( sprintf( /* translators: %s: email description */ __( "Don't send: %s", 'mailroom' ), $label ) ); ?></label>
			<?php endforeach; ?>
			</fieldset></td></tr>
		<?php endforeach; ?>
		</table>
		<?php
	}

	public function sanitize( array $acc, array $post, string $section = '' ) : array {
		if ( $section !== '' && $section !== self::SECTION ) {
			return $acc;
		}
		[ $o, $ok ]        = $acc;
		$in                = array_map( 'sanitize_key', (array) wp_unslash( $post['controls_off'] ?? [] ) );
		$o['controls_off'] = array_values( array_intersect( array_keys( self::CONTROLS ), $in ) );
		return [ $o, $ok ];
	}
}
