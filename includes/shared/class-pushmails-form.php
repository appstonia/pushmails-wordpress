<?php
/**
 * Subscribe form with double opt-in: `[pushmails_form]` and the
 * "PushMails subscribe form" block.
 *
 * Nobody is sent to PushMails from the form itself. The form only sends a
 * confirmation email; the address joins after its owner clicks the link. A
 * public form without that step is the easiest way to put a stranger's address
 * on a list — and every complaint it causes lands on the site owner's domain.
 *
 * Shared by PushMails for WordPress and PushMails for WooCommerce; edit it in
 * pushmails-wordpress (see class-pushmails-api.php). The shortcode, block name
 * and pending confirmations are the same in both, so a site moving to the
 * WooCommerce plugin keeps its forms and its unconfirmed sign-ups.
 *
 * @package PushMails
 */

defined( 'ABSPATH' ) || exit;

/**
 * Form rendering, submission, confirmation.
 */
class PushMails_Form {

	const ACTION         = 'pushmails_subscribe';
	const CONFIRM_ACTION = 'pushmails_confirm';
	const CONFIRM_ARG = 'pushmails_confirm';
	const RESULT_ARG  = 'pushmails';
	const FORM_ARG    = 'pushmails_form';
	const RETRY_HOOK  = 'pushmails_form_retry';

	/** Pending sign-ups are transients named after the token's hash. */
	const PENDING_PREFIX = 'pushmails_doi_';

	/** How long a confirmation link works. */
	const PENDING_TTL = 3 * DAY_IN_SECONDS;

	/** Submissions per visitor address per window, and per email address. */
	const LIMIT_PER_IP    = 5;
	const LIMIT_PER_EMAIL = 1;
	const LIMIT_WINDOW    = 10 * MINUTE_IN_SECONDS;

	/** A human needs at least this long to fill in the form. */
	const MIN_SECONDS = 2;

	/**
	 * Hooks the shortcode, the block, submission and confirmation.
	 */
	public static function init() {
		add_shortcode( 'pushmails_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_submit' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_confirm' ) );
		add_action( 'admin_post_nopriv_' . self::CONFIRM_ACTION, array( __CLASS__, 'handle_confirm_post' ) );
		add_action( 'admin_post_' . self::CONFIRM_ACTION, array( __CLASS__, 'handle_confirm_post' ) );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'retry' ), 10, 2 );
	}

	/**
	 * Server-rendered block; the editor shows a live preview of the same markup.
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			__DIR__ . '/blocks/subscribe-form',
			array( 'render_callback' => array( __CLASS__, 'render_block' ) )
		);
	}

	/**
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		return self::render(
			array(
				'show_name' => ! empty( $attributes['showName'] ),
				'button'    => isset( $attributes['buttonText'] ) ? (string) $attributes['buttonText'] : '',
			)
		);
	}

	/**
	 * `[pushmails_form name="yes" button="Join"]`
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'name'   => 'no',
				'button' => '',
			),
			$atts,
			'pushmails_form'
		);
		return self::render(
			array(
				'show_name' => in_array( strtolower( (string) $atts['name'] ), array( 'yes', '1', 'true' ), true ),
				'button'    => (string) $atts['button'],
			)
		);
	}

	/**
	 * @return string Consent box label shared with checkout and registration.
	 */
	public static function consent_label() {
		$label = trim( (string) get_option( PushMails_Settings::option( 'checkbox_label' ), '' ) );
		return '' !== $label ? $label : __( 'Keep me updated with news and offers by email', 'pushmails-for-wordpress' );
	}

	/**
	 * The form. Nothing for visitors while the site is not connected; a hint
	 * for administrators, so an empty spot on the page has an explanation.
	 *
	 * @param array $args show_name, button.
	 * @return string
	 */
	public static function render( array $args ) {
		if ( ! PushMails_Settings::is_connected() ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="pushmails-form-note">' . esc_html__( 'The PushMails subscribe form appears here once the site is connected to PushMails.', 'pushmails-for-wordpress' ) . '</p>';
			}
			return '';
		}

		static $count = 0;
		++$count;
		$id = 'pushmails-form-' . $count;

		// Only the form that was sent shows the answer, when a page has several.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only picks which message to show.
		$target  = isset( $_GET[ self::FORM_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::FORM_ARG ] ) ) : '';
		$result  = isset( $_GET[ self::RESULT_ARG ] ) && ( '' === $target || $target === $id ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : '';
		// phpcs:enable
		$message = self::result_message( $result );
		$button  = '' !== trim( $args['button'] ) ? $args['button'] : __( 'Subscribe', 'pushmails-for-wordpress' );

		// Signed render time for the "too fast" check. No nonce: the form sits
		// on cached public pages, where a nonce goes stale and turns real
		// visitors away. What a nonce would stop (a forged cross-site post)
		// only ever produces a confirmation email the owner must click.
		$ts = time();

		ob_start();
		if ( 1 === $count ) {
			// Just enough layout to hold together in any theme; colours, fonts
			// and the button come from the theme (wp-element-button).
			echo '<style>.pushmails-form p{margin:0 0 12px;max-width:420px}.pushmails-form-field label{display:block;margin-bottom:4px}.pushmails-form-field input{width:100%;box-sizing:border-box;padding:8px 10px}.pushmails-form-consent label{display:flex;gap:8px;align-items:flex-start}.pushmails-form-consent input{margin-top:4px}.pushmails-form-message{padding:10px 12px;border-left:4px solid}.pushmails-form-message--success{border-color:#00a32a;background:rgba(0,163,42,.08)}.pushmails-form-message--error{border-color:#d63638;background:rgba(214,54,56,.08)}</style>';
		}
		?>
		<form class="pushmails-form" id="<?php echo esc_attr( $id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php if ( $message ) : ?>
				<p class="pushmails-form-message pushmails-form-message--<?php echo esc_attr( $message['type'] ); ?>" role="status"><?php echo esc_html( $message['text'] ); ?></p>
			<?php endif; ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="pm_ts" value="<?php echo esc_attr( $ts . '.' . self::sign( (string) $ts ) ); ?>" />
			<input type="hidden" name="pm_return" value="<?php echo esc_url( self::current_url() . '#' . $id ); ?>" />
			<p style="position:absolute;left:-9999px" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this field empty', 'pushmails-for-wordpress' ); ?> <input type="text" name="pm_website" value="" tabindex="-1" autocomplete="off" /></label>
			</p>
			<?php if ( $args['show_name'] ) : ?>
				<p class="pushmails-form-field">
					<label for="<?php echo esc_attr( $id ); ?>-name"><?php esc_html_e( 'First name', 'pushmails-for-wordpress' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-name" name="pm_first_name" autocomplete="given-name" maxlength="100" />
				</p>
			<?php endif; ?>
			<p class="pushmails-form-field">
				<label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Email address', 'pushmails-for-wordpress' ); ?></label>
				<input type="email" id="<?php echo esc_attr( $id ); ?>-email" name="pm_email" required autocomplete="email" maxlength="254" />
			</p>
			<p class="pushmails-form-consent">
				<label><input type="checkbox" name="pm_consent" value="1" required /> <?php echo esc_html( self::consent_label() ); ?></label>
			</p>
			<p class="pushmails-form-submit"><button type="submit" class="wp-element-button"><?php echo esc_html( $button ); ?></button></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param string $result Result code from the redirect.
	 * @return array{type: string, text: string}|null
	 */
	private static function result_message( $result ) {
		switch ( $result ) {
			case 'sent':
				return array(
					'type' => 'success',
					'text' => __( 'Almost done — check your inbox and click the link in the email we just sent to confirm your subscription.', 'pushmails-for-wordpress' ),
				);
			case 'confirmed':
				return array(
					'type' => 'success',
					'text' => __( 'Thank you — your subscription is confirmed.', 'pushmails-for-wordpress' ),
				);
			case 'expired':
				return array(
					'type' => 'error',
					'text' => __( 'This confirmation link has expired or was already used. Please subscribe again.', 'pushmails-for-wordpress' ),
				);
			case 'invalid':
				return array(
					'type' => 'error',
					'text' => __( 'Please enter a valid email address and tick the box.', 'pushmails-for-wordpress' ),
				);
			case 'busy':
				return array(
					'type' => 'error',
					'text' => __( 'Too many attempts. Please try again in a few minutes.', 'pushmails-for-wordpress' ),
				);
		}
		return null;
	}

	/**
	 * Form post: checks, then a confirmation email. Every path that reaches
	 * the email check ends with the same "check your inbox" answer, so the form
	 * does not tell anyone whether an address is already on the list.
	 */
	public static function handle_submit() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Public form on cached pages; see render().
		$return = isset( $_POST['pm_return'] ) ? esc_url_raw( wp_unslash( $_POST['pm_return'] ) ) : '';
		$return = wp_validate_redirect( $return, home_url( '/' ) );

		if ( ! PushMails_Settings::is_connected() ) {
			self::back( $return, 'invalid' );
		}

		// Honeypot and "too fast": answer as if it worked, so a bot learns nothing.
		$honeypot = isset( $_POST['pm_website'] ) ? (string) wp_unslash( $_POST['pm_website'] ) : '';
		$ts       = isset( $_POST['pm_ts'] ) ? sanitize_text_field( wp_unslash( $_POST['pm_ts'] ) ) : '';
		if ( '' !== $honeypot || ! self::human_timing( $ts ) ) {
			self::back( $return, 'sent' );
		}

		$email   = isset( $_POST['pm_email'] ) ? strtolower( trim( sanitize_email( wp_unslash( $_POST['pm_email'] ) ) ) ) : '';
		$first   = isset( $_POST['pm_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['pm_first_name'] ) ) : '';
		$consent = ! empty( $_POST['pm_consent'] );
		// phpcs:enable

		if ( ! $consent || ! is_email( $email ) ) {
			self::back( $return, 'invalid' );
		}
		if ( ! self::within_limit( 'ip', self::client_ip(), self::LIMIT_PER_IP ) ) {
			self::back( $return, 'busy' );
		}
		// Per address: a flood of confirmation emails to one inbox is the abuse
		// a double opt-in form still allows. Silent on purpose.
		if ( ! self::within_limit( 'email', $email, self::LIMIT_PER_EMAIL ) ) {
			self::back( $return, 'sent' );
		}

		$token = wp_generate_password( 32, false );
		set_transient(
			self::PENDING_PREFIX . hash( 'sha256', $token ),
			array(
				'email'      => $email,
				'first_name' => substr( $first, 0, 100 ),
				'return'     => $return,
			),
			self::PENDING_TTL
		);
		self::send_confirmation( $email, add_query_arg( self::CONFIRM_ARG, rawurlencode( $token ), home_url( '/' ) ) );
		self::back( $return, 'sent' );
	}

	/**
	 * The confirmation email, through wp_mail() — and so through PushMails
	 * when the owner chose that for emails to users.
	 *
	 * @param string $email Address.
	 * @param string $url   Confirmation link.
	 */
	private static function send_confirmation( $email, $url ) {
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Confirm your subscription to %s', 'pushmails-for-wordpress' ),
			$site
		);
		$intro = sprintf(
			/* translators: %s: site name */
			__( 'Someone — hopefully you — asked to receive emails from %s at this address. Please confirm by clicking the button below.', 'pushmails-for-wordpress' ),
			$site
		);
		$ignore = __( 'If you did not ask for this, ignore this email: you will not be subscribed.', 'pushmails-for-wordpress' );
		$button = __( 'Confirm subscription', 'pushmails-for-wordpress' );

		$body = '<p>' . esc_html( $intro ) . '</p>'
			. '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:10px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px">' . esc_html( $button ) . '</a></p>'
			. '<p style="color:#6b7280;font-size:13px">' . esc_html( $ignore ) . '</p>';

		/**
		 * Filters the confirmation email before it is sent.
		 *
		 * @param array{subject: string, body: string} $mail  Subject and HTML body.
		 * @param string                               $email Recipient.
		 * @param string                               $url   Confirmation link.
		 */
		$mail = apply_filters(
			'pushmails_confirmation_email',
			array(
				'subject' => $subject,
				'body'    => $body,
			),
			$email,
			$url
		);
		wp_mail( $email, $mail['subject'], $mail['body'], array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Confirmation link: a page with a "Confirm" button, not the confirmation
	 * itself. Mail security scanners (Outlook Safe Links, Mimecast, Barracuda
	 * …) open every link in an incoming email; confirming on that visit would
	 * subscribe an address nobody confirmed and leave the real owner, clicking
	 * a minute later, with "link expired". Scanners open links but do not
	 * submit forms, so the button is where the consent is.
	 */
	public static function handle_confirm() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token is the credential.
		if ( ! isset( $_GET[ self::CONFIRM_ARG ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET[ self::CONFIRM_ARG ] ) );
		if ( ! is_array( self::pending( $token ) ) ) {
			self::expired();
		}
		nocache_headers();
		$site  = get_bloginfo( 'name' );
		$html  = '<h1>' . esc_html( $site ) . '</h1>';
		$html .= '<p>' . esc_html__( 'Please confirm that you want to receive emails from us.', 'pushmails-for-wordpress' ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::CONFIRM_ACTION ) . '" />';
		$html .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
		$html .= '<p><button type="submit" class="button button-large">' . esc_html__( 'Confirm subscription', 'pushmails-for-wordpress' ) . '</button></p>';
		$html .= '</form>';
		wp_die( $html, esc_html( $site ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
	}

	/**
	 * The "Confirm" button: sends the contact to PushMails and goes back to
	 * the page with the form. The token works once.
	 */
	public static function handle_confirm_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The token is the credential; a nonce would expire on cached pages.
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$pending = self::pending( $token );
		if ( ! is_array( $pending ) ) {
			self::expired();
		}
		delete_transient( self::PENDING_PREFIX . hash( 'sha256', $token ) );

		$contact = array(
			'email'        => $pending['email'],
			'consent'      => 'granted',
			'consented_at' => gmdate( 'c' ),
			'tags'         => array( PUSHMAILS_PLATFORM, 'form' ),
		);
		if ( '' !== $pending['first_name'] ) {
			$contact['first_name'] = $pending['first_name'];
		}
		/**
		 * Filters a confirmed form subscriber before it is sent. Return null to
		 * drop it.
		 *
		 * @param array $contact Contact payload.
		 */
		$contact = apply_filters( 'pushmails_form_contact', $contact );
		if ( is_array( $contact ) ) {
			self::retry( $contact, 0 );
		}
		self::back( $pending['return'] ? $pending['return'] : home_url( '/' ), 'confirmed' );
	}

	/**
	 * @param string $token Token from the confirmation link.
	 * @return array|false The pending sign-up.
	 */
	private static function pending( $token ) {
		return '' !== $token ? get_transient( self::PENDING_PREFIX . hash( 'sha256', $token ) ) : false;
	}

	/**
	 * Expired or used link. Which page had the form is only known from the
	 * pending sign-up, so the answer is a page of its own rather than a
	 * redirect.
	 */
	private static function expired() {
		$message = self::result_message( 'expired' );
		wp_die(
			esc_html( $message['text'] ),
			esc_html( get_bloginfo( 'name' ) ),
			array(
				'response'  => 200,
				'link_url'  => esc_url( home_url( '/' ) ),
				'link_text' => esc_html__( 'Go to the website', 'pushmails-for-wordpress' ),
			)
		);
	}

	/**
	 * Sends a confirmed subscriber; on a passing failure tries again later.
	 * The visitor already saw "confirmed": their click is the consent, and
	 * PushMails being down for a moment should not undo it.
	 *
	 * @param array $contact Contact payload.
	 * @param int   $attempt Retry counter.
	 */
	public static function retry( $contact, $attempt = 0 ) {
		$api = PushMails_Settings::api();
		if ( ! $api || ! is_array( $contact ) ) {
			return;
		}
		try {
			$api->sync_contacts( array( $contact ) );
		} catch ( PushMails_API_Error $e ) {
			PushMails_Log::warning( sprintf( 'Form subscriber not sent yet (%s): %s', $e->error_code, $e->getMessage() ) );
			if ( $e->is_retryable() && $attempt < 6 ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS * ( 2 ** $attempt ), self::RETRY_HOOK, array( $contact, $attempt + 1 ) );
			}
		}
	}

	/**
	 * @param string $url    Where to go.
	 * @param string $result Result code shown by the form there.
	 */
	private static function back( $url, $result ) {
		$fragment = '';
		$args     = array( self::RESULT_ARG => $result );
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
			if ( 0 === strpos( $fragment, '#pushmails-form-' ) ) {
				$args[ self::FORM_ARG ] = sanitize_key( substr( $fragment, 1 ) );
			}
		}
		$url = remove_query_arg( array( self::RESULT_ARG, self::FORM_ARG, self::CONFIRM_ARG ), $url );
		wp_safe_redirect( add_query_arg( $args, $url ) . $fragment );
		exit;
	}

	/**
	 * @param string $bucket ip|email.
	 * @param string $value  Address.
	 * @param int    $limit  Allowed per window.
	 * @return bool Whether this attempt is within the limit (and counts it).
	 */
	private static function within_limit( $bucket, $value, $limit ) {
		if ( '' === $value ) {
			return true;
		}
		$key   = 'pushmails_rl_' . $bucket . '_' . md5( $value );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, self::LIMIT_WINDOW );
		return true;
	}

	/**
	 * The connecting address only. Forwarding headers are not read: anyone can
	 * send them, and trusting them would let one client reset its own limit.
	 *
	 * Behind a proxy or CDN that the server is not set up for (no
	 * mod_remoteip / real_ip), every visitor has the proxy's address and
	 * shares one limit, so a handful of posts would turn everyone away. Such a
	 * site passes the visitor's address through the `pushmails_client_ip`
	 * filter, reading the header its own proxy sets.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor address the subscribe form rate-limits by.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'pushmails_client_ip', $ip );
	}

	/**
	 * @param string $value "timestamp.signature" from the form.
	 * @return bool Signed, and not submitted faster than a person could.
	 */
	private static function human_timing( $value ) {
		$parts = explode( '.', $value, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			return false;
		}
		return time() - (int) $parts[0] >= self::MIN_SECONDS;
	}

	/**
	 * @param string $value Value.
	 * @return string
	 */
	private static function sign( $value ) {
		return substr( wp_hash( 'pushmails_form|' . $value, 'nonce' ), 0, 16 );
	}

	/**
	 * @return string URL of the page being rendered, without our result flags.
	 */
	private static function current_url() {
		// REQUEST_URI already carries the subdirectory of a site not at the
		// root, so it is joined to the host, not to home_url(). The host comes
		// from the request; handle_submit() only redirects to it after
		// wp_validate_redirect() has checked it is this site.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url  = '' !== $host ? set_url_scheme( 'http://' . $host . $uri ) : home_url( '/' );
		return remove_query_arg( array( self::RESULT_ARG, self::FORM_ARG, self::CONFIRM_ARG ), $url );
	}
}
