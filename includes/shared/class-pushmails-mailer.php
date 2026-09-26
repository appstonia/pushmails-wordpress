<?php
/**
 * Site and store emails through PushMails.
 *
 * Order confirmations, order status updates, password resets — the emails
 * WordPress (and WooCommerce, when it runs) send, in the kinds the owner
 * picked (customer, users, staff) — are handed to PushMails when the
 * "transactional" mode is chosen. The rule that shapes everything here: an
 * email is never lost. Whenever PushMails cannot take one (sender address not
 * registered, plan limit reached, an attachment, PushMails unreachable),
 * WordPress sends it exactly as it would without this plugin.
 *
 * Shared by PushMails for WordPress and PushMails for WooCommerce; edit it in
 * pushmails-wordpress (see class-pushmails-api.php).
 *
 * @package PushMails
 */

defined( 'ABSPATH' ) || exit;

/**
 * `pre_wp_mail` hand-off with fallback.
 */
class PushMails_Mailer {

	/**
	 * Which emails go through PushMails, one yes/no option per kind (the
	 * option is PushMails_Settings::option( 'mail_<kind>' )). Staff emails are
	 * off by default: they include password reset links for administrator
	 * accounts, and an owner should opt in to those leaving the site.
	 * "customer" only exists where WooCommerce runs.
	 */
	const KIND_DEFAULTS = array(
		'customer' => 'yes',
		'users'    => 'yes',
		'staff'    => 'no',
	);

	/**
	 * The WooCommerce email being sent right now, if any. Set just before
	 * WooCommerce calls wp_mail() and cleared right after.
	 *
	 * @var WC_Email|null
	 */
	private static $wc_email = null;

	/** While set, emails skip PushMails; avoids a timeout on every email during an outage. */
	const BREAKER = 'pushmails_mail_breaker';

	/**
	 * @return string Option holding the latest outcome, for the settings page.
	 */
	public static function status_option() {
		return PushMails_Settings::option( 'mail_status' );
	}

	/**
	 * @return string Option holding the sender address override.
	 */
	public static function from_option() {
		return PushMails_Settings::option( 'from_email' );
	}

	/**
	 * @param string $kind customer|users|staff.
	 * @return string Option holding the yes/no choice for that kind.
	 */
	public static function kind_option( $kind ) {
		return PushMails_Settings::option( 'mail_' . $kind );
	}

	/**
	 * "customer" belongs to PushMails for WooCommerce only. PushMails for
	 * WordPress keeps running on a site that added WooCommerce later, but it
	 * has no setting for store emails and tells the owner store emails need
	 * the WooCommerce plugin — so they stay with WordPress there.
	 *
	 * @return string[] Kinds that exist on this site.
	 */
	public static function kinds() {
		$kinds = array_keys( self::KIND_DEFAULTS );
		return 'woocommerce' === PUSHMAILS_PLATFORM && class_exists( 'WooCommerce' ) ? $kinds : array_values( array_diff( $kinds, array( 'customer' ) ) );
	}

	/**
	 * Hooks into wp_mail().
	 */
	public static function init() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'maybe_send' ), 10, 2 );
		// Needed with the WordPress plugin too: it is how a WooCommerce
		// customer email is told apart from a WordPress one (and kept here).
		if ( class_exists( 'WooCommerce' ) ) {
			add_filter( 'woocommerce_mail_callback_params', array( __CLASS__, 'remember_wc_email' ), 10, 2 );
			add_action( 'woocommerce_email_sent', array( __CLASS__, 'forget_wc_email' ) );
		}
	}

	/**
	 * @param array    $params wp_mail() arguments WooCommerce is about to use.
	 * @param WC_Email $email  The email.
	 * @return array Unchanged.
	 */
	public static function remember_wc_email( $params, $email ) {
		self::$wc_email = is_a( $email, 'WC_Email' ) ? $email : null;
		return $params;
	}

	/**
	 * Clears the WooCommerce context after its email went out.
	 */
	public static function forget_wc_email() {
		self::$wc_email = null;
	}

	/**
	 * @param string $kind customer|users|staff.
	 * @return bool Whether the merchant chose to send this kind through PushMails.
	 */
	public static function kind_enabled( $kind ) {
		if ( ! in_array( $kind, self::kinds(), true ) ) {
			return false;
		}
		return 'yes' === get_option( self::kind_option( $kind ), self::KIND_DEFAULTS[ $kind ] );
	}

	/**
	 * Sorts an email into customer, users or staff.
	 *
	 * The recipient decides first: anything addressed to an administrator or
	 * shop manager is a staff email, whatever sent it. That keeps staff
	 * password resets on the site even when they come through WooCommerce's
	 * My Account form, which sends them as a "customer" email.
	 *
	 * @param string        $recipient Recipient address.
	 * @param WC_Email|null $wc_email  WooCommerce email being sent, if any.
	 * @return string customer|users|staff
	 */
	private static function kind_of( $recipient, $wc_email ) {
		if ( self::is_staff_address( $recipient ) ) {
			return 'staff';
		}
		if ( $wc_email ) {
			return $wc_email->is_customer_email() ? 'customer' : 'staff';
		}
		return 'users';
	}

	/**
	 * @param string $email Address.
	 * @return bool The site admin address, or a user who can manage the site or the store.
	 */
	private static function is_staff_address( $email ) {
		$email = strtolower( $email );
		if ( strtolower( (string) get_option( 'admin_email' ) ) === $email ) {
			return true;
		}
		$user = get_user_by( 'email', $email );
		return $user && ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) );
	}

	/**
	 * @return bool Whether store emails should go through PushMails.
	 */
	public static function enabled() {
		if ( 'transactional' !== PushMails_Settings::consent_mode() || ! PushMails_Settings::is_connected() ) {
			return false;
		}
		$connection = get_option( PushMails_Settings::OPTION_CONNECTION );
		return is_array( $connection ) && ! empty( $connection['store_emails'] );
	}

	/**
	 * @param null|bool $short_circuit Result from an earlier filter.
	 * @param array     $atts          wp_mail() arguments.
	 * @return null|bool True when PushMails accepted the email; null lets
	 *                   WordPress send it.
	 */
	public static function maybe_send( $short_circuit, $atts ) {
		// The context belongs to this one email, whatever happens to it next.
		$wc_email       = self::$wc_email;
		self::$wc_email = null;
		if ( null !== $short_circuit || ! self::enabled() || get_transient( self::BREAKER ) ) {
			return $short_circuit;
		}

		$message = self::to_message( $atts, $wc_email );
		if ( null === $message ) {
			return null; // Not something PushMails carries; WordPress sends it.
		}

		try {
			PushMails_Settings::api()->send_message( $message );
		} catch ( PushMails_API_Error $e ) {
			self::record( $e->getMessage(), $e->error_code );
			if ( $e->is_retryable() ) {
				set_transient( self::BREAKER, 1, 5 * MINUTE_IN_SECONDS );
			}
			PushMails_Log::warning(
				sprintf( 'Email "%s" went out through WordPress: %s (%s)', $message['subject'], $e->getMessage(), $e->error_code )
			);
			return null;
		}

		self::record();
		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_succeeded', $atts );
		return true;
	}

	/**
	 * Converts wp_mail() arguments to a PushMails message, or null when the
	 * email has to stay with WordPress: a kind the merchant did not choose,
	 * several recipients, CC/BCC or attachments (the API carries one
	 * recipient and no files).
	 *
	 * @param array         $atts     wp_mail() arguments.
	 * @param WC_Email|null $wc_email WooCommerce email being sent, if any.
	 * @return array|null
	 */
	private static function to_message( array $atts, $wc_email ) {
		if ( ! empty( $atts['attachments'] ) ) {
			return null;
		}
		$to = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
		$to = array_values( array_filter( array_map( 'trim', $to ) ) );
		if ( 1 !== count( $to ) ) {
			return null;
		}
		$recipient = self::parse_address( $to[0] );
		if ( ! $recipient ) {
			return null;
		}
		if ( ! self::kind_enabled( self::kind_of( $recipient['email'], $wc_email ) ) ) {
			return null;
		}

		$headers      = self::parse_headers( $atts['headers'] ?? array() );
		if ( isset( $headers['cc'] ) || isset( $headers['bcc'] ) ) {
			return null;
		}
		$content_type = isset( $headers['content-type'] )
			? strtolower( strtok( $headers['content-type'], ';' ) )
			: apply_filters( 'wp_mail_content_type', 'text/plain' );

		$from = isset( $headers['from'] ) ? self::parse_address( $headers['from'] ) : null;
		$override = trim( (string) get_option( self::from_option(), '' ) );
		$from_email = '' !== $override ? $override
			: ( $from ? $from['email'] : apply_filters( 'wp_mail_from', get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) ) );
		$from_name = $from && '' !== $from['name'] ? $from['name']
			: apply_filters( 'wp_mail_from_name', get_bloginfo( 'name' ) );

		$body = (string) $atts['message'];
		if ( 'text/html' !== trim( $content_type ) ) {
			// Plain-text core emails (password reset on a non-WooCommerce
			// screen) become minimal HTML; links stay clickable.
			$body = wpautop( make_clickable( esc_html( $body ) ) );
		}

		$message = array(
			'to'         => $recipient['email'],
			'to_name'    => $recipient['name'],
			'subject'    => (string) $atts['subject'],
			'from_email' => (string) $from_email,
			'from_name'  => (string) $from_name,
			'body_html'  => $body,
		);
		if ( isset( $headers['reply-to'] ) ) {
			$reply = self::parse_address( $headers['reply-to'] );
			if ( $reply ) {
				$message['reply_to'] = $reply['email'];
			}
		}

		/**
		 * Filters a store email before it goes to PushMails. Return null to let
		 * WordPress send it instead.
		 *
		 * @param array|null $message PushMails message.
		 * @param array      $atts    wp_mail() arguments.
		 */
		return apply_filters( 'pushmails_store_email', $message, $atts );
	}

	/**
	 * @param string|array $raw Headers as wp_mail() accepts them.
	 * @return array<string,string> Lower-case name → value (first occurrence).
	 */
	private static function parse_headers( $raw ) {
		$lines = is_array( $raw ) ? $raw : explode( "\n", str_replace( "\r\n", "\n", (string) $raw ) );
		$out   = array();
		foreach ( $lines as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', $line, 2 );
			$name                 = strtolower( trim( $name ) );
			if ( ! isset( $out[ $name ] ) ) {
				$out[ $name ] = trim( $value );
			}
		}
		return $out;
	}

	/**
	 * "Name <email>" or "email".
	 *
	 * @param string $raw Address.
	 * @return array{email: string, name: string}|null
	 */
	private static function parse_address( $raw ) {
		$raw = trim( $raw );
		if ( preg_match( '/^(.*)<([^>]+)>$/', $raw, $m ) ) {
			$email = trim( $m[2] );
			$name  = trim( trim( $m[1] ), '"' );
		} else {
			$email = $raw;
			$name  = '';
		}
		return is_email( $email ) ? array(
			'email' => $email,
			'name'  => $name,
		) : null;
	}

	/**
	 * Remembers the latest outcome for the settings page.
	 *
	 * @param string|null $error Error message, null on success.
	 * @param string|null $code  Error code.
	 */
	private static function record( $error = null, $code = null ) {
		$status = get_option( self::status_option() );
		$status = is_array( $status ) ? $status : array();
		if ( null === $error ) {
			// A busy store sends an email every few seconds; the page shows
			// "sent N minutes ago", so one write per few minutes is enough.
			if ( empty( $status['error'] ) && ! empty( $status['ok_at'] ) && time() - strtotime( $status['ok_at'] ) < 5 * MINUTE_IN_SECONDS ) {
				return;
			}
			$status['ok_at'] = gmdate( 'c' );
			unset( $status['error'] );
		} else {
			$status['error'] = array(
				'message' => $error,
				'code'    => $code,
				'at'      => gmdate( 'c' ),
			);
		}
		update_option( self::status_option(), $status, false );
	}
}
