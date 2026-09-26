<?php
/**
 * Plugin Name:       PushMails for WordPress
 * Plugin URI:        https://github.com/appstonia/pushmails-wordpress
 * Description:       Grow your PushMails audience with a double opt-in subscribe form and opted-in site users, and deliver your site's emails through PushMails.
 * Version:           1.0.0
 * Author:            Appstonia
 * Author URI:        https://pushmails.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pushmails-for-wordpress
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 *
 * @package PushMails
 */

defined( 'ABSPATH' ) || exit;

/*
 * WooCommerce sites use PushMails for WooCommerce instead: it does everything
 * this plugin does, plus customers and store emails, and takes over this
 * plugin's connection when it is activated. Registered before the guard below
 * so that trying to activate this plugin next to WooCommerce gets an answer
 * instead of a plugin that silently switches itself off again.
 */
register_activation_hook(
	__FILE__,
	static function () {
		if ( class_exists( 'WooCommerce' ) || defined( 'PUSHMAILS_WC_VERSION' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				wp_kses_post(
					sprintf(
						/* translators: %s: link to PushMails for WooCommerce */
						__( 'This site runs WooCommerce, so PushMails for WordPress was not activated. Use %s instead: it includes the subscribe form and also sends your customers and store emails.', 'pushmails-for-wordpress' ),
						'<a href="https://github.com/appstonia/pushmails-woocommerce/releases/latest">PushMails for WooCommerce</a>'
					)
				),
				esc_html__( 'Plugin not activated', 'pushmails-for-wordpress' ),
				array( 'back_link' => true )
			);
		}
		if ( class_exists( 'PushMails_Plugin' ) ) {
			PushMails_Plugin::activate();
		}
	}
);

// Loaded next to PushMails for WooCommerce (both ended up active): that plugin
// runs the site. Loading this one too would declare the shared classes twice.
if ( defined( 'PUSHMAILS_WC_VERSION' ) ) {
	add_action(
		'admin_init',
		static function () {
			deactivate_plugins( plugin_basename( __FILE__ ), true );
		}
	);
	return;
}

// Keep in step with the header above; `make check` compares them.
define( 'PUSHMAILS_WP_VERSION', '1.0.0' );
define( 'PUSHMAILS_WP_FILE', __FILE__ );
define( 'PUSHMAILS_WP_DIR', plugin_dir_path( __FILE__ ) );

// What the shared code (includes/shared) needs to know about the plugin running it.
define( 'PUSHMAILS_PLUGIN_NAME', 'PushMails-WordPress' );
define( 'PUSHMAILS_PLUGIN_VERSION', PUSHMAILS_WP_VERSION );
define( 'PUSHMAILS_PLATFORM', 'wordpress' );

require_once PUSHMAILS_WP_DIR . 'includes/shared/class-pushmails-api.php';
require_once PUSHMAILS_WP_DIR . 'includes/shared/class-pushmails-mailer.php';
require_once PUSHMAILS_WP_DIR . 'includes/shared/class-pushmails-form.php';
require_once PUSHMAILS_WP_DIR . 'includes/class-pushmails-settings.php';
require_once PUSHMAILS_WP_DIR . 'includes/class-pushmails-consent.php';
require_once PUSHMAILS_WP_DIR . 'includes/class-pushmails-contacts.php';
require_once PUSHMAILS_WP_DIR . 'includes/class-pushmails-sync.php';
require_once PUSHMAILS_WP_DIR . 'includes/class-pushmails-onboarding.php';
require_once PUSHMAILS_WP_DIR . 'includes/class-pushmails-plugin.php';

register_deactivation_hook( __FILE__, array( 'PushMails_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'PushMails_Plugin', 'init' ) );
