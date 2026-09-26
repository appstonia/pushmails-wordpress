<?php
/**
 * Background sync through WP-Cron.
 *
 * Nothing is sent inside a visitor's request: a registration or a profile
 * save only queues the user. WordPress core has no job queue (WooCommerce
 * brings Action Scheduler; this plugin cannot assume it), so jobs are single
 * WP-Cron events. They run on the next page load, usually within a minute.
 *
 * @package PushMails
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queues and runs user syncs, the backfill of existing users, and the daily
 * hello.
 */
class PushMails_Sync {

	const HOOK_USER     = 'pushmails_wp_sync_user';
	const HOOK_BACKFILL = 'pushmails_wp_backfill';
	const HOOK_HELLO    = 'pushmails_wp_hello';

	/** Give up on a single user after this many retries (about a day). */
	const MAX_ATTEMPTS = 8;

	/** Page size of the backfill; the server accepts at most 100 per request. */
	const PAGE_SIZE = 100;

	/** How long the request that starts a backfill keeps sending pages itself. */
	const INLINE_SECONDS = 5;

	/**
	 * Registers triggers and workers.
	 */
	public static function init() {
		add_action( self::HOOK_USER, array( __CLASS__, 'run_user' ), 10, 2 );
		add_action( self::HOOK_BACKFILL, array( __CLASS__, 'run_backfill' ) );
		add_action( self::HOOK_HELLO, array( __CLASS__, 'run_hello' ) );

		add_action( 'user_register', array( __CLASS__, 'queue_user' ) );
		add_action( 'profile_update', array( __CLASS__, 'queue_user' ) );
		add_action( 'set_user_role', array( __CLASS__, 'queue_user' ) );
	}

	/**
	 * Queues one user. Several triggers in one request (registration, then a
	 * role) collapse into one job.
	 *
	 * @param int $user_id User.
	 */
	public static function queue_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! PushMails_Settings::is_connected() ) {
			return;
		}
		$args = array( $user_id, 0 );
		if ( ! wp_next_scheduled( self::HOOK_USER, $args ) ) {
			wp_schedule_single_event( time(), self::HOOK_USER, $args );
		}
	}

	/**
	 * Sends one user.
	 *
	 * @param int $user_id User.
	 * @param int $attempt Retry counter.
	 */
	public static function run_user( $user_id, $attempt = 0 ) {
		$api = PushMails_Settings::api();
		if ( ! $api ) {
			return;
		}
		$contact = PushMails_Contacts::build( (int) $user_id );
		if ( null === $contact ) {
			return;
		}
		try {
			$result = $api->sync_contacts( array( $contact ) );
			self::log_rejections( $result );
			PushMails_Settings::record_sync_ok();
		} catch ( PushMails_API_Error $e ) {
			if ( self::handle_error( $e ) && $attempt < self::MAX_ATTEMPTS ) {
				wp_schedule_single_event(
					time() + self::backoff( $attempt, $e->retry_after ),
					self::HOOK_USER,
					array( (int) $user_id, $attempt + 1 )
				);
			}
		}
	}

	/**
	 * Starts sending every existing user. The first pages run inside the
	 * request that started it — see PushMails for WooCommerce for why — and
	 * the rest follows through WP-Cron.
	 */
	public static function start_backfill() {
		wp_clear_scheduled_hook( self::HOOK_BACKFILL );
		update_option(
			self::option(),
			array(
				'status'     => 'running',
				'page'       => 1,
				'sent'       => 0,
				'skipped'    => 0,
				'started_at' => gmdate( 'c' ),
			),
			false
		);
		$deadline = microtime( true ) + self::INLINE_SECONDS;
		do {
			$more = self::run_page();
		} while ( $more && microtime( true ) < $deadline );
		if ( $more ) {
			wp_schedule_single_event( time(), self::HOOK_BACKFILL );
		}
	}

	/**
	 * @return string Option holding the backfill progress.
	 */
	public static function option() {
		return PushMails_Settings::option( 'backfill' );
	}

	/**
	 * @return array|null Backfill progress.
	 */
	public static function backfill_state() {
		$state = get_option( self::option() );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * One backfill page from WP-Cron. Schedules itself until users run out.
	 */
	public static function run_backfill() {
		if ( self::run_page() ) {
			wp_schedule_single_event( time(), self::HOOK_BACKFILL );
		}
	}

	/**
	 * Sends one page and advances the progress.
	 *
	 * @return bool Whether the next page should run now.
	 */
	private static function run_page() {
		$state = self::backfill_state();
		$api   = PushMails_Settings::api();
		if ( ! $state || 'running' !== $state['status'] || ! $api ) {
			return false;
		}

		$ids = get_users(
			array(
				'number'             => self::PAGE_SIZE,
				'paged'              => (int) $state['page'],
				'orderby'            => 'ID',
				'order'              => 'ASC',
				'fields'             => 'ID',
				'capability__not_in' => array( 'edit_posts' ),
			)
		);
		$full = count( $ids ) >= self::PAGE_SIZE;

		$contacts = array();
		foreach ( $ids as $id ) {
			$contact = PushMails_Contacts::build( (int) $id );
			if ( null === $contact ) {
				++$state['skipped'];
				continue;
			}
			$contacts[] = $contact;
		}

		try {
			foreach ( array_chunk( $contacts, PushMails_Settings::max_batch_size() ) as $chunk ) {
				$result = $api->sync_contacts( $chunk );
				self::log_rejections( $result );
				$state['sent'] += (int) ( $result['created'] ?? 0 ) + (int) ( $result['updated'] ?? 0 );
			}
			PushMails_Settings::record_sync_ok();
		} catch ( PushMails_API_Error $e ) {
			if ( self::handle_error( $e ) ) {
				update_option( self::option(), $state, false );
				wp_schedule_single_event( time() + self::backoff( 2, $e->retry_after ), self::HOOK_BACKFILL );
				return false;
			}
			$state['status'] = 'failed';
			$state['error']  = $e->getMessage();
			update_option( self::option(), $state, false );
			return false;
		}

		if ( $full ) {
			++$state['page'];
			update_option( self::option(), $state, false );
			return true;
		}
		$state['status']      = 'done';
		$state['finished_at'] = gmdate( 'c' );
		update_option( self::option(), $state, false );
		return false;
	}

	/**
	 * Daily hello: keeps the site "connected" in the PushMails panel and
	 * picks up a changed target list.
	 */
	public static function run_hello() {
		PushMails_Settings::hello();
	}

	/**
	 * Schedules the daily hello once.
	 */
	public static function schedule_hello() {
		if ( ! wp_next_scheduled( self::HOOK_HELLO ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_HELLO );
		}
	}

	/**
	 * Removes every queued job (deactivation, uninstall, disconnect).
	 */
	public static function unschedule_all() {
		foreach ( array( self::HOOK_USER, self::HOOK_BACKFILL, self::HOOK_HELLO, PushMails_Form::RETRY_HOOK ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Logs and records an API error. Returns whether retrying makes sense.
	 *
	 * @param PushMails_API_Error $e Error.
	 * @return bool
	 */
	private static function handle_error( PushMails_API_Error $e ) {
		PushMails_Log::warning( sprintf( 'Sync failed (%s, HTTP %d): %s', $e->error_code, $e->status, $e->getMessage() ) );
		if ( $e->is_unauthorized() ) {
			// Disconnected in the panel or a wrong token: stop until the owner
			// fixes it in the settings, which clears the error.
			PushMails_Settings::record_error( $e );
			self::unschedule_all();
			return false;
		}
		return $e->is_retryable();
	}

	/**
	 * Rejected contacts (invalid address, plan limit) are logged, not retried.
	 *
	 * @param array $result API response.
	 */
	private static function log_rejections( array $result ) {
		foreach ( (array) ( $result['results'] ?? array() ) as $item ) {
			if ( 'rejected' === ( $item['result'] ?? '' ) ) {
				PushMails_Log::info( sprintf( 'Rejected %s: %s', $item['email'] ?? '?', $item['message'] ?? $item['error'] ?? '' ) );
			}
		}
	}

	/**
	 * 1 min, 2, 4 … capped at 6 hours; a server-sent Retry-After wins when longer.
	 *
	 * @param int $attempt     Retry counter.
	 * @param int $retry_after Seconds the server asked for.
	 * @return int
	 */
	private static function backoff( $attempt, $retry_after ) {
		$delay = min( 6 * HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** $attempt ) );
		return max( $delay, (int) $retry_after );
	}
}
