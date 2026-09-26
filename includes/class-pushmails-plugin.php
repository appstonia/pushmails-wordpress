<?php
/**
 * Bootstrap.
 *
 * @package PushMails
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the parts together.
 */
class PushMails_Plugin {

	/**
	 * Runs on plugins_loaded.
	 */
	public static function init() {
		load_plugin_textdomain( 'pushmails-for-wordpress', false, dirname( plugin_basename( PUSHMAILS_WP_FILE ) ) . '/languages' );

		PushMails_Settings::init();
		PushMails_Consent::init();
		PushMails_Sync::init();
		PushMails_Mailer::init();
		PushMails_Form::init();
		PushMails_Onboarding::init();

		add_action( 'admin_notices', array( __CLASS__, 'woocommerce_notice' ) );
	}

	/**
	 * WooCommerce was activated after this plugin. Everything keeps working;
	 * the notice points to the plugin that also knows about customers and
	 * says what happens on the switch, so it is not a leap in the dark.
	 */
	public static function woocommerce_notice() {
		if ( ! class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins', 'settings_page_' . PushMails_Settings::PAGE ), true ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		printf(
			wp_kses(
				/* translators: %s: link to PushMails for WooCommerce */
				__( 'This site now runs WooCommerce. Switch to %s to also send your customers and deliver your store emails through PushMails. Activating it switches this plugin off and takes over its connection, settings and subscribe forms — nothing to set up again.', 'pushmails-for-wordpress' ),
				array( 'a' => array( 'href' => array() ) )
			),
			'<a href="https://github.com/appstonia/pushmails-woocommerce/releases/latest">PushMails for WooCommerce</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Resumes the daily hello when the plugin is re-activated with a token.
	 */
	public static function activate() {
		if ( PushMails_Settings::is_connected() ) {
			PushMails_Sync::schedule_hello();
		}
	}

	/**
	 * Stops background work; settings stay so re-activating resumes as before.
	 */
	public static function deactivate() {
		// PUSHMAILS_TAKEOVER: PushMails for WooCommerce is switching this
		// plugin off to carry on in its place.
		PushMails_Sync::unschedule_all( defined( 'PUSHMAILS_TAKEOVER' ) );
	}
}
