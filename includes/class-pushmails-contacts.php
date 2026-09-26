<?php
/**
 * Turns a site user into a PushMails contact.
 *
 * @package PushMails
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contact payload builder.
 */
class PushMails_Contacts {

	/**
	 * People who run the site are not its audience: authors, editors and
	 * administrators are never sent. Their emails also get the stricter
	 * "staff" handling in the mailer.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function is_audience( $user ) {
		return $user instanceof WP_User && ! user_can( $user, 'edit_posts' );
	}

	/**
	 * In "consented" mode only users who ticked the box are sent. In the other
	 * modes every user is sent; PushMails keeps those without consent
	 * unsubscribed, so they get account emails but never campaigns.
	 *
	 * @param int $user_id User.
	 * @return array|null Contact payload, or null when the user is not sent.
	 */
	public static function build( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! self::is_audience( $user ) || ! is_email( $user->user_email ) ) {
			return null;
		}
		$consent = PushMails_Consent::for_user( $user->ID );
		if ( 'consented' === PushMails_Settings::consent_mode() && PushMails_Consent::UNKNOWN === $consent['state'] ) {
			return null;
		}

		$contact = array(
			'email'   => strtolower( $user->user_email ),
			'consent' => $consent['state'],
			'tags'    => array( 'wordpress', 'registered' ),
			'fields'  => array(
				'registered_at' => gmdate( 'c', strtotime( $user->user_registered . ' UTC' ) ),
			),
		);
		if ( $consent['at'] ) {
			$contact['consented_at'] = $consent['at'];
		}
		if ( '' !== $user->first_name ) {
			$contact['first_name'] = $user->first_name;
		}
		if ( '' !== $user->last_name ) {
			$contact['last_name'] = $user->last_name;
		}

		/**
		 * Filters a user before it is sent to PushMails. Return null to skip.
		 *
		 * @param array   $contact Contact payload.
		 * @param WP_User $user    User.
		 */
		return apply_filters( 'pushmails_wp_contact', $contact, $user );
	}
}
