<?php
/**
 * Shared CLI bootstrap for the test scripts: loads a real WordPress install (real DB, real WP Mail SMTP).
 *
 *   MAILROOM_WP_LOAD    absolute path to the site's wp-load.php (required)
 *   MAILROOM_SITE_HOST  host name WordPress expects, e.g. www.example.com (default: localhost)
 *
 * To test new code before it goes live, point WPMU_PLUGIN_DIR at a staging copy of mu-plugins through an
 * auto_prepend_file (see README). Run the scripts as files: `php -r` ignores auto_prepend_file.
 */
$wp_load = getenv( 'MAILROOM_WP_LOAD' );
if ( ! $wp_load || ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "Set MAILROOM_WP_LOAD to the absolute path of the site's wp-load.php\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']      = getenv( 'MAILROOM_SITE_HOST' ) ?: 'localhost';
$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $wp_load;
