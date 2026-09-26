<?php
/**
 * Talks to the PushMails integration API.
 *
 * Shared by PushMails for WordPress and PushMails for WooCommerce. The copy in
 * the WooCommerce plugin is generated: edit this file in pushmails-wordpress
 * and run scripts/sync-shared.sh there.
 *
 * @package PushMails
 */

defined( 'ABSPATH' ) || exit;

/**
 * A failed call, classified so the caller knows whether trying again can help.
 */
class PushMails_API_Error extends Exception {

	/**
	 * Machine-readable code from the API (`invalid_integration_token`, …) or a
	 * local one (`http_error`).
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * HTTP status; 0 when the request never got an answer.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Seconds the server asked us to wait (429), if any.
	 *
	 * @var int
	 */
	public $retry_after;

	/**
	 * @param string $message     Human-readable message.
	 * @param string $error_code  Machine-readable code.
	 * @param int    $status      HTTP status.
	 * @param int    $retry_after Seconds to wait.
	 */
	public function __construct( $message, $error_code, $status, $retry_after = 0 ) {
		parent::__construct( $message );
		$this->error_code  = $error_code;
		$this->status      = $status;
		$this->retry_after = $retry_after;
	}

	/**
	 * Network errors, rate limiting and server errors may clear up on their own.
	 * A rejected token or a malformed request will not: retrying those would
	 * only fill the queue.
	 *
	 * @return bool
	 */
	public function is_retryable() {
		return 0 === $this->status || 429 === $this->status || $this->status >= 500;
	}

	/**
	 * The store was disconnected in PushMails or the token was mistyped.
	 *
	 * @return bool
	 */
	public function is_unauthorized() {
		return 401 === $this->status;
	}
}

/**
 * HTTP client for `/v1/integration/*`.
 */
class PushMails_API {

	const DEFAULT_URL = 'https://send.pushmails.net';

	/**
	 * Connection token (`pm_int_…`).
	 *
	 * @var string
	 */
	private $token;

	/**
	 * @param string $token Connection token.
	 */
	public function __construct( $token ) {
		$this->token = $token;
	}

	/**
	 * API base URL. Overridable for staging: define PUSHMAILS_API_URL in
	 * wp-config.php or use the `pushmails_api_url` filter.
	 *
	 * @return string
	 */
	public static function base_url() {
		$url = defined( 'PUSHMAILS_API_URL' ) ? PUSHMAILS_API_URL : self::DEFAULT_URL;
		return untrailingslashit( apply_filters( 'pushmails_api_url', $url ) );
	}

	/**
	 * Introduces the store and fetches the connection's settings.
	 *
	 * @param array $store site_url, store_name, platform_version, plugin_version, consent_mode, platform.
	 * @return array Decoded response.
	 * @throws PushMails_API_Error When the call fails.
	 */
	public function hello( array $store ) {
		return $this->post( '/v1/integration/hello', $store );
	}

	/**
	 * Sends up to max_batch_size contacts.
	 *
	 * @param array $contacts List of contact payloads.
	 * @return array Decoded response with per-contact results.
	 * @throws PushMails_API_Error When the call fails as a whole.
	 */
	public function sync_contacts( array $contacts ) {
		return $this->post( '/v1/integration/contacts', array( 'contacts' => array_values( $contacts ) ) );
	}

	/**
	 * Delivers one store email (same body as /v1/messages).
	 *
	 * Short timeout: this runs inside the request that sends the email (a
	 * checkout, a password reset). If PushMails is slow the email goes out
	 * through WordPress instead of keeping the shopper waiting.
	 *
	 * @param array $message to, subject, from_email, from_name, reply_to, body_html.
	 * @return array Decoded response (message_id).
	 * @throws PushMails_API_Error When PushMails does not accept it.
	 */
	public function send_message( array $message ) {
		return $this->post( '/v1/integration/messages', $message, 8 );
	}

	/**
	 * @param string $path    Request path.
	 * @param array  $body    JSON body.
	 * @param int    $timeout Seconds.
	 * @return array
	 * @throws PushMails_API_Error When the call fails.
	 */
	private function post( $path, array $body, $timeout = 20 ) {
		$response = wp_remote_post(
			self::base_url() . $path,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'User-Agent'    => PUSHMAILS_PLUGIN_NAME . '/' . PUSHMAILS_PLUGIN_VERSION . '; ' . home_url(),
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new PushMails_API_Error( $response->get_error_message(), 'http_error', 0 );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 && is_array( $decoded ) ) {
			return $decoded;
		}

		$code    = is_array( $decoded ) && isset( $decoded['error'] ) ? (string) $decoded['error'] : 'http_' . $status;
		$message = is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : sprintf(
			/* translators: %d: HTTP status code */
			__( 'PushMails answered with HTTP %d.', 'pushmails-for-wordpress' ),
			$status
		);
		$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );

		throw new PushMails_API_Error( $message, $code, $status, $retry );
	}
}

/**
 * Plugin log: WooCommerce's logger when it is there (WooCommerce → Status →
 * Logs, source "pushmails"), PHP's error log with WP_DEBUG_LOG otherwise.
 */
class PushMails_Log {

	/**
	 * @param string $message Message.
	 */
	public static function warning( $message ) {
		self::write( 'warning', $message );
	}

	/**
	 * @param string $message Message.
	 */
	public static function info( $message ) {
		self::write( 'info', $message );
	}

	/**
	 * @param string $level   Level.
	 * @param string $message Message.
	 */
	private static function write( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'pushmails' ) );
			return;
		}
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in debug log.
			error_log( 'PushMails ' . $level . ': ' . $message );
		}
	}
}
