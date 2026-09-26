<?php
/**
 * Removes the plugin's settings. Contacts already sent stay in PushMails, and
 * users' consent stays on their profiles: it is their record, not the plugin's.
 *
 * @package PushMails
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array(
	'pushmails_wp_token',
	'pushmails_wp_consent_mode',
	'pushmails_wp_checkbox_label',
	'pushmails_wp_connection',
	'pushmails_wp_error',
	'pushmails_wp_last_sync',
	'pushmails_wp_backfill',
	'pushmails_wp_from_email',
	'pushmails_wp_mail_status',
	'pushmails_wp_mail_users',
	'pushmails_wp_mail_staff',
) as $option ) {
	delete_option( $option );
}

foreach ( array( 'pushmails_wp_sync_user', 'pushmails_wp_backfill', 'pushmails_wp_hello' ) as $hook ) {
	wp_unschedule_hook( $hook );
}

// The subscribe form, its retries and the email breaker are shared with
// PushMails for WooCommerce; when that plugin took over, they are its own now.
if ( ! defined( 'PUSHMAILS_WC_VERSION' ) ) {
	wp_unschedule_hook( 'pushmails_form_retry' );
	delete_transient( 'pushmails_mail_breaker' );
}
