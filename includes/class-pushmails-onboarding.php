<?php
/**
 * First steps: what the plugin does and how to connect it.
 *
 * Shown where a site owner looks right after installing — under the plugin in
 * the plugins list and at the top of the settings page — only until the site
 * is connected. After that the same steps stay on the settings page, folded
 * away under "How it works".
 *
 * @package PushMails
 */

defined( 'ABSPATH' ) || exit;

/**
 * Intro, connection steps and plugins-list links.
 */
class PushMails_Onboarding {

	const PANEL_URL = 'https://app.pushmails.net';

	/**
	 * Hooks into the plugins list and the settings screen.
	 */
	public static function init() {
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'after_plugin_row_' . plugin_basename( PUSHMAILS_WP_FILE ), array( __CLASS__, 'plugin_row_intro' ), 10, 0 );
	}

	/**
	 * PushMails panel address. Overridable for staging with the
	 * `pushmails_panel_url` filter.
	 *
	 * @param string $path Path inside the panel.
	 * @return string
	 */
	public static function panel_url( $path = '' ) {
		return untrailingslashit( apply_filters( 'pushmails_panel_url', self::PANEL_URL ) ) . $path;
	}

	/**
	 * "How to connect" next to "View details" in the plugins list.
	 *
	 * @param string[] $links Row meta links.
	 * @param string   $file  Plugin file.
	 * @return string[]
	 */
	public static function row_meta( $links, $file ) {
		if ( plugin_basename( PUSHMAILS_WP_FILE ) !== $file ) {
			return $links;
		}
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( PushMails_Settings::url() . '#pushmails-howto' ),
			esc_html__( 'How to connect', 'pushmails-for-wordpress' )
		);
		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://github.com/appstonia/pushmails-wordpress#readme' ),
			esc_html__( 'Documentation', 'pushmails-for-wordpress' )
		);
		return $links;
	}

	/**
	 * A short intro right under the plugin in the plugins list, until the
	 * site is connected.
	 */
	public static function plugin_row_intro() {
		if ( PushMails_Settings::is_connected() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$columns = 4;
		if ( isset( $GLOBALS['wp_list_table'] ) && method_exists( $GLOBALS['wp_list_table'], 'get_column_count' ) ) {
			$columns = (int) $GLOBALS['wp_list_table']->get_column_count();
		}
		echo '<tr class="plugin-update-tr active"><td colspan="' . esc_attr( $columns ) . '" class="plugin-update colspanchange">';
		echo '<div class="notice inline notice-info notice-alt" style="padding:8px 12px">';
		echo '<p><strong>' . esc_html__( 'Almost there — connect your site to PushMails.', 'pushmails-for-wordpress' ) . '</strong> ';
		esc_html_e( 'Add a subscribe form with double opt-in, send your site users who agree, and deliver your site emails — password resets, account notices — through PushMails.', 'pushmails-for-wordpress' );
		echo '</p>';
		self::steps();
		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( PushMails_Settings::url() ),
			esc_html__( 'Open PushMails settings', 'pushmails-for-wordpress' )
		);
		echo '</div></td></tr>';
	}

	/**
	 * Introduction at the top of the settings page while not connected.
	 */
	public static function settings_intro() {
		echo '<div id="pushmails-howto" style="max-width:760px">';
		echo '<p><strong>' . esc_html__( 'What PushMails does for your site', 'pushmails-for-wordpress' ) . '</strong></p>';
		echo '<ul style="list-style:disc;margin-left:20px">';
		echo '<li>' . esc_html__( 'A subscribe form (block or shortcode). Visitors join your PushMails audience after confirming their address by email.', 'pushmails-for-wordpress' ) . '</li>';
		echo '<li>' . esc_html__( 'A consent box on the registration form and the user profile. Only users who tick it receive campaigns; the box is never ticked in advance.', 'pushmails-for-wordpress' ) . '</li>';
		echo '<li>' . esc_html__( 'Password resets and other site emails can be delivered by PushMails. If PushMails cannot take an email, WordPress sends it as before.', 'pushmails-for-wordpress' ) . '</li>';
		echo '</ul>';
		echo '<p><strong>' . esc_html__( 'Connect in three steps', 'pushmails-for-wordpress' ) . '</strong></p>';
		self::steps();
		echo '</div>';
	}

	/**
	 * The connection steps, shared by every place that explains them.
	 */
	public static function steps() {
		echo '<ol style="margin-left:20px">';
		echo '<li>';
		printf(
			/* translators: %s: link to the PushMails panel */
			esc_html__( 'In %s, open Integrations → WordPress and click Connect. Pick the list new subscribers should join.', 'pushmails-for-wordpress' ),
			'<a href="' . esc_url( self::panel_url( '/integrations/wordpress' ) ) . '" target="_blank" rel="noopener noreferrer">PushMails</a>'
		);
		echo '</li><li>';
		esc_html_e( 'Copy the connection token — it is shown only once — and paste it into the “Connection token” field on this settings page.', 'pushmails-for-wordpress' );
		echo '</li><li>';
		esc_html_e( 'Choose who to send and which site emails go through PushMails, then save. Existing users are sent right away. Then add the subscribe form to a page.', 'pushmails-for-wordpress' );
		echo '</li></ol>';
		echo '<p class="description">';
		esc_html_e( 'To deliver site emails, verify your domain and add a sender address in PushMails (Sending Infrastructure → Domains), then enter that address on this page.', 'pushmails-for-wordpress' );
		echo '</p>';
	}

	/**
	 * The steps and the site email rules, folded away on the settings page once
	 * connected. Kept in the page rather than a Help tab to match the WooCommerce
	 * plugin, whose settings header hides the Help button.
	 */
	public static function settings_help() {
		echo '<details id="pushmails-howto" style="margin-top:16px;max-width:760px"><summary style="cursor:pointer"><strong>';
		esc_html_e( 'How it works', 'pushmails-for-wordpress' );
		echo '</strong></summary>';
		self::steps();
		echo '<p class="description">';
		esc_html_e( 'Site emails are sorted by recipient first: anything addressed to an administrator counts as an administrator email. An email stays with WordPress when its kind is not selected, when it has attachments or several recipients, or when PushMails does not accept it (sender address not registered, plan limit reached, PushMails unreachable).', 'pushmails-for-wordpress' );
		echo '</p></details>';
	}
}
