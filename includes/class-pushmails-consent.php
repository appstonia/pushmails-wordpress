<?php
/**
 * Consent box on the registration form and on the user's own profile.
 *
 * The user meta keys are the ones PushMails for WooCommerce uses for its My
 * Account box, so consent carries over when a site switches plugins.
 *
 * @package PushMails
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where consent is asked and how it is stored.
 */
class PushMails_Consent {

	const META_STATE = '_pushmails_consent';
	const META_TIME  = '_pushmails_consent_at';

	const GRANTED   = 'granted';
	const WITHDRAWN = 'withdrawn';
	const UNKNOWN   = 'unknown';

	/**
	 * Hooks the boxes.
	 */
	public static function init() {
		add_action( 'register_form', array( __CLASS__, 'render_register_box' ) );
		add_action( 'user_register', array( __CLASS__, 'save_register_box' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_box' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_box' ) );
	}

	/**
	 * Box on wp-login.php?action=register. Never ticked in advance.
	 */
	public static function render_register_box() {
		if ( ! PushMails_Settings::is_connected() ) {
			return;
		}
		echo '<p style="margin-bottom:16px"><label><input type="checkbox" name="pushmails_marketing_consent" value="1" /> ' . esc_html( PushMails_Form::consent_label() ) . '</label></p>';
	}

	/**
	 * @param int $user_id New user.
	 */
	public static function save_register_box( $user_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core's registration form has no nonce; this only records the new user's own choice.
		if ( empty( $_POST['pushmails_marketing_consent'] ) ) {
			return; // Unticked means "not asked to", not "withdrawn".
		}
		self::record( $user_id, self::GRANTED );
	}

	/**
	 * Box on the user's own profile page; editing someone else's profile
	 * does not show it — consent is the person's own decision.
	 *
	 * @param WP_User $user User.
	 */
	public static function render_profile_box( $user ) {
		if ( ! PushMails_Settings::is_connected() || ! PushMails_Contacts::is_audience( $user ) ) {
			return;
		}
		$state = self::for_user( $user->ID )['state'];
		?>
		<h2><?php esc_html_e( 'Email updates', 'pushmails-for-wordpress' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Newsletter', 'pushmails-for-wordpress' ); ?></th>
				<td>
					<input type="hidden" name="pushmails_marketing_consent_shown" value="1" />
					<label><input type="checkbox" name="pushmails_marketing_consent" value="1" <?php checked( self::GRANTED, $state ); ?> /> <?php echo esc_html( PushMails_Form::consent_label() ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param int $user_id User saving their own profile (core checked the nonce).
	 */
	public static function save_profile_box( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Core verified the profile form nonce.
		if ( empty( $_POST['pushmails_marketing_consent_shown'] ) ) {
			return;
		}
		$ticked = ! empty( $_POST['pushmails_marketing_consent'] );
		// phpcs:enable

		$current = self::for_user( $user_id )['state'];
		$next    = $ticked ? self::GRANTED : self::WITHDRAWN;
		if ( $next === $current || ( self::WITHDRAWN === $next && self::UNKNOWN === $current ) ) {
			return; // No change; an untouched empty box is not a withdrawal.
		}
		self::record( $user_id, $next );
		// profile_update already queued the user, but before this meta was written.
		PushMails_Sync::queue_user( $user_id );
	}

	/**
	 * @param int    $user_id User.
	 * @param string $state   granted|withdrawn.
	 */
	private static function record( $user_id, $state ) {
		update_user_meta( $user_id, self::META_STATE, $state );
		update_user_meta( $user_id, self::META_TIME, gmdate( 'c' ) );
	}

	/**
	 * @param int $user_id User.
	 * @return array{state: string, at: string|null}
	 */
	public static function for_user( $user_id ) {
		$state = (string) get_user_meta( $user_id, self::META_STATE, true );
		if ( self::GRANTED !== $state && self::WITHDRAWN !== $state ) {
			return array(
				'state' => self::UNKNOWN,
				'at'    => null,
			);
		}
		$at = (string) get_user_meta( $user_id, self::META_TIME, true );
		return array(
			'state' => $state,
			'at'    => '' !== $at ? $at : null,
		);
	}
}
