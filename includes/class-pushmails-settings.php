<?php
/**
 * Settings → PushMails.
 *
 * @package PushMails
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page, connection state and the owner-facing status box.
 *
 * The shared code (includes/shared) reads every option through option(): the
 * WooCommerce plugin has the same class with its own prefix, and moves these
 * values over when it takes this site over.
 */
class PushMails_Settings {

	const PAGE   = 'pushmails';
	const PREFIX = 'pushmails_wp_';

	const OPTION_TOKEN      = 'pushmails_wp_token';
	const OPTION_MODE       = 'pushmails_wp_consent_mode';
	const OPTION_LABEL      = 'pushmails_wp_checkbox_label';
	const OPTION_CONNECTION = 'pushmails_wp_connection';
	const OPTION_ERROR      = 'pushmails_wp_error';
	const OPTION_LAST_SYNC  = 'pushmails_wp_last_sync';

	const MODES = array( 'transactional', 'consented', 'all' );

	/**
	 * @param string $name Option name without the plugin prefix.
	 * @return string
	 */
	public static function option( $name ) {
		return self::PREFIX . $name;
	}

	/**
	 * Hooks the page and its handlers.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_pushmails_wp_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_pushmails_wp_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_pushmails_wp_backfill', array( __CLASS__, 'handle_backfill' ) );
		add_action( 'wp_ajax_pushmails_wp_backfill_status', array( __CLASS__, 'ajax_backfill_status' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PUSHMAILS_WP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings → PushMails.
	 */
	public static function add_page() {
		add_options_page(
			__( 'PushMails', 'pushmails-for-wordpress' ),
			__( 'PushMails', 'pushmails-for-wordpress' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * @param array $links Plugin row links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'pushmails-for-wordpress' ) ) );
		return $links;
	}

	/**
	 * @return string
	 */
	public static function url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * The page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$token = (string) get_option( self::OPTION_TOKEN, '' );
		$mode  = self::consent_mode();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a message to show.
		$notice = isset( $_GET['pushmails_notice'] ) ? sanitize_key( wp_unslash( $_GET['pushmails_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PushMails', 'pushmails-for-wordpress' ); ?></h1>
			<?php self::render_notice( $notice ); ?>
			<p><?php esc_html_e( 'Collect subscribers with a double opt-in form, send your site users to PushMails as contacts, and deliver your site emails through PushMails.', 'pushmails-for-wordpress' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pushmails_wp_save" />
				<?php wp_nonce_field( 'pushmails_wp_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'pushmails-for-wordpress' ); ?></th>
						<td><?php self::render_status(); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="pushmails-token"><?php esc_html_e( 'Connection token', 'pushmails-for-wordpress' ); ?></label></th>
						<td><?php self::render_token( $token ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Who to send', 'pushmails-for-wordpress' ); ?></th>
						<td>
							<fieldset>
								<?php
								$labels = array(
									'transactional' => __( 'All users, and site emails through PushMails (recommended) — password resets and account emails are delivered by PushMails; only users who consent receive campaigns', 'pushmails-for-wordpress' ),
									'consented'     => __( 'Only users who consent — they tick a box when they register or on their profile', 'pushmails-for-wordpress' ),
									'all'           => __( 'All users — those without consent arrive unsubscribed and never receive marketing email; site emails stay with WordPress', 'pushmails-for-wordpress' ),
								);
								foreach ( $labels as $value => $label ) {
									printf(
										'<label style="display:block;margin-bottom:8px"><input type="radio" name="consent_mode" value="%s" %s /> %s</label>',
										esc_attr( $value ),
										checked( $mode, $value, false ),
										esc_html( $label )
									);
								}
								?>
								<p class="description"><?php esc_html_e( 'Subscribers from the form are always sent, once they confirm their address. Authors, editors and administrators are never sent.', 'pushmails-for-wordpress' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pushmails-from"><?php esc_html_e( 'Sender address for site emails', 'pushmails-for-wordpress' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="pushmails-from" name="from_email" value="<?php echo esc_attr( (string) get_option( PushMails_Mailer::from_option(), '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used when site emails go through PushMails. It must be a sender address in PushMails on a verified domain. Leave empty to use the address WordPress sends from. If PushMails cannot send an email, WordPress sends it as before.', 'pushmails-for-wordpress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site emails to send through PushMails', 'pushmails-for-wordpress' ); ?></th>
						<td>
							<fieldset>
								<?php
								$kinds = array(
									'users' => __( 'Emails to users and subscribers — password resets, email address changes, subscription confirmations, notifications from other plugins', 'pushmails-for-wordpress' ),
									'staff' => __( 'Emails to administrators — their own password resets, new user alerts, site notices', 'pushmails-for-wordpress' ),
								);
								foreach ( $kinds as $kind => $label ) {
									printf(
										'<label style="display:block;margin-bottom:8px"><input type="checkbox" name="mail_%1$s" value="yes" %2$s /> %3$s</label>',
										esc_attr( $kind ),
										checked( PushMails_Mailer::kind_enabled( $kind ), true, false ),
										esc_html( $label )
									);
								}
								?>
								<p class="description"><?php esc_html_e( 'Emails to administrators are off by default: they include password reset links for accounts that can manage your site. Emails not selected here are sent by WordPress as before.', 'pushmails-for-wordpress' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pushmails-label"><?php esc_html_e( 'Consent box label', 'pushmails-for-wordpress' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="pushmails-label" name="checkbox_label" value="<?php echo esc_attr( (string) get_option( self::OPTION_LABEL, '' ) ); ?>" placeholder="<?php esc_attr_e( 'Keep me updated with news and offers by email', 'pushmails-for-wordpress' ); ?>" />
							<p class="description"><?php esc_html_e( 'Shown on the subscribe form, the registration form and the user profile. The box is never ticked in advance.', 'pushmails-for-wordpress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Subscribe form', 'pushmails-for-wordpress' ); ?></th>
						<td>
							<p><?php esc_html_e( 'Add the “PushMails subscribe form” block to any page or post, or use the shortcode:', 'pushmails-for-wordpress' ); ?></p>
							<p><code>[pushmails_form]</code> &nbsp; <code>[pushmails_form name="yes" button="Join"]</code></p>
							<p class="description"><?php esc_html_e( 'Visitors get an email with a confirmation link and join PushMails only after clicking it.', 'pushmails-for-wordpress' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param string $notice Notice code from the redirect.
	 */
	private static function render_notice( $notice ) {
		$error = get_option( self::OPTION_ERROR );
		switch ( $notice ) {
			case 'saved':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'pushmails-for-wordpress' ) . '</p></div>';
				break;
			case 'connected':
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Connected to PushMails. Settings saved.', 'pushmails-for-wordpress' ) . '</p></div>';
				break;
			case 'failed':
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %s: error message from PushMails */
							__( 'Could not connect to PushMails: %s', 'pushmails-for-wordpress' ),
							is_array( $error ) ? $error['message'] : __( 'unknown error', 'pushmails-for-wordpress' )
						)
					)
				);
				break;
		}
	}

	/**
	 * Token field. The saved token is never printed, only its last four
	 * characters: it can send email from the owner's domain and anyone who can
	 * open this page could otherwise copy it from the page source.
	 *
	 * @param string $saved Saved token.
	 */
	private static function render_token( $saved ) {
		?>
		<input type="password" class="regular-text" id="pushmails-token" name="token" value="" autocomplete="new-password" spellcheck="false"
			placeholder="<?php echo '' !== $saved ? esc_attr( '••••••••' . substr( $saved, -4 ) ) : 'pm_int_…'; ?>" />
		<?php if ( '' !== $saved ) : ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pushmails_wp_disconnect' ), 'pushmails_wp_disconnect' ) ); ?>"
				onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Disconnect this site from PushMails? Users and subscribers are no longer sent and site emails go back to WordPress.', 'pushmails-for-wordpress' ) ) ); ?>);">
				<?php esc_html_e( 'Disconnect', 'pushmails-for-wordpress' ); ?>
			</a>
			<p class="description">
				<?php
				printf(
					/* translators: %s: last four characters of the token */
					esc_html__( 'A token ending in %s is saved. Paste a new one only to replace it.', 'pushmails-for-wordpress' ),
					'<code>' . esc_html( substr( $saved, -4 ) ) . '</code>'
				);
				?>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Create it in PushMails under Integrations → WordPress → Connect. It starts with pm_int_.', 'pushmails-for-wordpress' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Saves and immediately checks the connection, so the owner learns about a
	 * wrong token now rather than from a silent queue.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pushmails-for-wordpress' ), 403 );
		}
		check_admin_referer( 'pushmails_wp_save' );

		$before      = (string) get_option( self::OPTION_TOKEN, '' );
		$before_mode = self::consent_mode();

		$mode = isset( $_POST['consent_mode'] ) ? sanitize_key( wp_unslash( $_POST['consent_mode'] ) ) : 'transactional';
		update_option( self::OPTION_MODE, in_array( $mode, self::MODES, true ) ? $mode : 'transactional', false );
		$from = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '';
		update_option( PushMails_Mailer::from_option(), $from, false );
		foreach ( array( 'users', 'staff' ) as $kind ) {
			update_option( PushMails_Mailer::kind_option( $kind ), empty( $_POST[ 'mail_' . $kind ] ) ? 'no' : 'yes', false );
		}
		$label = isset( $_POST['checkbox_label'] ) ? sanitize_text_field( wp_unslash( $_POST['checkbox_label'] ) ) : '';
		update_option( self::OPTION_LABEL, $label, false );

		// An empty field keeps the saved token (the field is never filled in);
		// disconnecting is its own button.
		$posted = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		$token  = '' !== $posted ? $posted : $before;
		if ( '' === $token ) {
			self::redirect( 'saved' );
		}
		update_option( self::OPTION_TOKEN, $token, false );

		if ( $token !== $before ) {
			// Another account (or a reconnect): the previous run's progress
			// belongs to the old connection and would read as done.
			delete_option( self::OPTION_CONNECTION );
			delete_option( PushMails_Sync::option() );
			delete_option( PushMails_Mailer::status_option() );
		}
		delete_transient( PushMails_Mailer::BREAKER );

		if ( ! self::hello() ) {
			self::redirect( 'failed' );
		}
		PushMails_Sync::schedule_hello();
		// Existing users follow on their own when there is someone new to
		// send: a fresh connection, or a mode that includes more people.
		$widened = 'consented' === $before_mode && 'consented' !== self::consent_mode();
		if ( $token !== $before || $widened || ! PushMails_Sync::backfill_state() ) {
			PushMails_Sync::start_backfill();
		}
		self::redirect( 'connected' );
	}

	/**
	 * @param string $notice Notice code.
	 */
	private static function redirect( $notice ) {
		wp_safe_redirect( add_query_arg( 'pushmails_notice', $notice, self::url() ) );
		exit;
	}

	/**
	 * Introduces the site to PushMails and stores the answer.
	 *
	 * @return bool Whether the connection works.
	 */
	public static function hello() {
		$token = (string) get_option( self::OPTION_TOKEN, '' );
		if ( '' === $token ) {
			return false;
		}
		global $wp_version;
		try {
			$response = ( new PushMails_API( $token ) )->hello(
				array(
					'site_url'         => home_url(),
					'store_name'       => get_bloginfo( 'name' ),
					'platform_version' => sprintf( 'WordPress %s', $wp_version ),
					'plugin_version'   => PUSHMAILS_WP_VERSION,
					'consent_mode'     => self::consent_mode(),
					'platform'         => PUSHMAILS_PLATFORM,
				)
			);
		} catch ( PushMails_API_Error $e ) {
			self::record_error( $e );
			return false;
		}
		update_option(
			self::OPTION_CONNECTION,
			array(
				'integration_id' => (string) ( $response['integration_id'] ?? '' ),
				'account_name'   => (string) ( $response['account_name'] ?? '' ),
				'list'           => isset( $response['list']['name'] ) ? array( 'name' => (string) $response['list']['name'] ) : null,
				'max_batch_size' => (int) ( $response['max_batch_size'] ?? 100 ),
				'store_emails'   => ! empty( $response['store_emails'] ),
				'checked_at'     => gmdate( 'c' ),
			),
			false
		);
		delete_option( self::OPTION_ERROR );
		return true;
	}

	/**
	 * @param PushMails_API_Error $e Error to show on the settings page.
	 */
	public static function record_error( PushMails_API_Error $e ) {
		update_option(
			self::OPTION_ERROR,
			array(
				'code'    => $e->error_code,
				'message' => $e->getMessage(),
				'at'      => gmdate( 'c' ),
			),
			false
		);
		if ( $e->is_unauthorized() ) {
			delete_option( self::OPTION_CONNECTION );
		}
	}

	/**
	 * Marks a successful sync; clears a transient error shown before.
	 */
	public static function record_sync_ok() {
		update_option( self::OPTION_LAST_SYNC, gmdate( 'c' ), false );
		$error = get_option( self::OPTION_ERROR );
		if ( is_array( $error ) && 'invalid_integration_token' !== $error['code'] ) {
			delete_option( self::OPTION_ERROR );
		}
	}

	/**
	 * @return bool Token saved and accepted by PushMails.
	 */
	public static function is_connected() {
		return '' !== (string) get_option( self::OPTION_TOKEN, '' ) && is_array( get_option( self::OPTION_CONNECTION ) );
	}

	/**
	 * @return PushMails_API|null
	 */
	public static function api() {
		return self::is_connected() ? new PushMails_API( (string) get_option( self::OPTION_TOKEN ) ) : null;
	}

	/**
	 * @return string transactional|consented|all
	 */
	public static function consent_mode() {
		$mode = get_option( self::OPTION_MODE, 'transactional' );
		return in_array( $mode, self::MODES, true ) ? $mode : 'transactional';
	}

	/**
	 * @return int Contacts per request, as announced by the server.
	 */
	public static function max_batch_size() {
		$connection = get_option( self::OPTION_CONNECTION );
		$size       = is_array( $connection ) ? (int) $connection['max_batch_size'] : 100;
		return max( 1, min( 100, $size ) );
	}

	/**
	 * Status box at the top of the page.
	 */
	private static function render_status() {
		$connection = get_option( self::OPTION_CONNECTION );
		$error      = get_option( self::OPTION_ERROR );
		$last_sync  = get_option( self::OPTION_LAST_SYNC );
		$backfill   = PushMails_Sync::backfill_state();

		if ( is_array( $connection ) ) {
			echo '<p><span class="dashicons dashicons-yes-alt" style="color:#008a20"></span> ';
			printf(
				/* translators: %s: PushMails account name */
				esc_html__( 'Connected to %s', 'pushmails-for-wordpress' ),
				'<strong>' . esc_html( $connection['account_name'] ) . '</strong>'
			);
			echo '</p><p class="description">';
			if ( ! empty( $connection['list']['name'] ) ) {
				printf(
					/* translators: %s: list name */
					esc_html__( 'New contacts join the list “%s”.', 'pushmails-for-wordpress' ),
					esc_html( $connection['list']['name'] )
				);
			} else {
				esc_html_e( 'New contacts are not added to a list. You can choose one in PushMails.', 'pushmails-for-wordpress' );
			}
			echo ' ';
			if ( $last_sync ) {
				printf(
					/* translators: %s: human-readable time difference, e.g. "5 minutes" */
					esc_html__( 'Last sync %s ago.', 'pushmails-for-wordpress' ),
					esc_html( human_time_diff( strtotime( $last_sync ) ) )
				);
			} else {
				esc_html_e( 'Nobody has been sent yet.', 'pushmails-for-wordpress' );
			}
			echo '</p>';
		} elseif ( '' === (string) get_option( self::OPTION_TOKEN, '' ) ) {
			echo '<p><span class="dashicons dashicons-info" style="color:#646970"></span> ';
			esc_html_e( 'Not connected. Paste the connection token from PushMails below and save.', 'pushmails-for-wordpress' );
			echo '</p><hr style="margin:16px 0" />';
			PushMails_Onboarding::settings_intro();
		}

		if ( is_array( $error ) ) {
			echo '<div class="notice notice-error inline"><p>';
			echo esc_html( $error['message'] );
			if ( 'invalid_integration_token' === $error['code'] ) {
				echo ' ';
				esc_html_e( 'Create a new token in PushMails and paste it here.', 'pushmails-for-wordpress' );
			}
			echo '</p></div>';
		}

		if ( ! is_array( $connection ) ) {
			return;
		}

		if ( 'transactional' === self::consent_mode() ) {
			$mail = get_option( PushMails_Mailer::status_option() );
			echo '<hr style="margin:16px 0" /><p><strong>' . esc_html__( 'Site emails', 'pushmails-for-wordpress' ) . '</strong></p><p class="description">';
			if ( PushMails_Mailer::enabled() ) {
				esc_html_e( 'The kinds of email selected below are delivered by PushMails. Other kinds, and emails with attachments or several recipients, stay with WordPress.', 'pushmails-for-wordpress' );
			} else {
				esc_html_e( 'Save the settings once more to enable site emails for this connection.', 'pushmails-for-wordpress' );
			}
			if ( is_array( $mail ) && ! empty( $mail['ok_at'] ) ) {
				echo ' ';
				printf(
					/* translators: %s: human-readable time difference */
					esc_html__( 'Last one sent %s ago.', 'pushmails-for-wordpress' ),
					esc_html( human_time_diff( strtotime( $mail['ok_at'] ) ) )
				);
			}
			echo '</p>';
			if ( is_array( $mail ) && ! empty( $mail['error'] ) ) {
				echo '<div class="notice notice-warning inline"><p>';
				printf(
					/* translators: 1: time difference, 2: error message */
					esc_html__( '%1$s ago an email could not go through PushMails and WordPress sent it instead: %2$s', 'pushmails-for-wordpress' ),
					esc_html( human_time_diff( strtotime( $mail['error']['at'] ) ) ),
					esc_html( $mail['error']['message'] )
				);
				echo '</p></div>';
			}
		}

		echo '<hr style="margin:16px 0" /><p><strong>' . esc_html__( 'Existing users', 'pushmails-for-wordpress' ) . '</strong></p>';
		echo '<p class="description">';
		echo 'consented' === self::consent_mode()
			? esc_html__( 'New registrations and profile changes are sent as they happen. Existing users were sent when you connected — only those who have given consent. Run it again at any time.', 'pushmails-for-wordpress' )
			: esc_html__( 'New registrations and profile changes are sent as they happen. Existing users were sent when you connected — all of them; those without consent arrive unsubscribed. Run it again at any time.', 'pushmails-for-wordpress' );
		echo '</p>';

		if ( $backfill && 'running' === $backfill['status'] ) {
			echo '<p id="pushmails-backfill"><span class="spinner is-active" style="float:none;margin:0 6px 0 0"></span><span>';
			printf(
				/* translators: %s: number of contacts */
				esc_html__( 'Sending in the background… %s contacts so far. This page updates on its own.', 'pushmails-for-wordpress' ),
				esc_html( number_format_i18n( (int) $backfill['sent'] ) )
			);
			echo '</span></p>';
			self::print_backfill_poll();
		} else {
			if ( $backfill && 'done' === $backfill['status'] ) {
				echo '<p>';
				printf(
					/* translators: 1: number of contacts sent, 2: date */
					esc_html__( 'Last run sent %1$s contacts (%2$s).', 'pushmails-for-wordpress' ),
					esc_html( number_format_i18n( (int) $backfill['sent'] ) ),
					esc_html( wp_date( get_option( 'date_format' ), strtotime( $backfill['finished_at'] ) ) )
				);
				echo '</p>';
			} elseif ( $backfill && 'failed' === $backfill['status'] ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $backfill['error'] ?? '' ) . '</p></div>';
			}
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pushmails_wp_backfill' ), 'pushmails_wp_backfill' ) ),
				esc_html__( 'Send existing users', 'pushmails-for-wordpress' )
			);
		}

		PushMails_Onboarding::settings_help();
	}

	/**
	 * Polls the backfill while it runs in the background, and reloads when it
	 * ends so the summary matches a fresh visit. No reload once a field was
	 * edited: that would throw away unsaved settings.
	 */
	private static function print_backfill_poll() {
		$url = wp_nonce_url( admin_url( 'admin-ajax.php?action=pushmails_wp_backfill_status' ), 'pushmails_wp_backfill_status' );
		/* translators: %s: number of contacts */
		$running = __( 'Sending in the background… %s contacts so far. This page updates on its own.', 'pushmails-for-wordpress' );
		?>
		<script>
		( function () {
			var url = <?php echo wp_json_encode( $url ); ?>;
			var running = <?php echo wp_json_encode( $running ); ?>;
			var touched = false;
			document.addEventListener( 'input', function () { touched = true; } );
			function poll() {
				fetch( url, { credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
					var state = res && res.data;
					if ( ! state || 'running' !== state.status ) {
						if ( ! touched ) { window.location.reload(); }
						return;
					}
					var el = document.querySelector( '#pushmails-backfill span:last-child' );
					if ( el ) { el.textContent = running.replace( '%s', state.sent_formatted ); }
					setTimeout( poll, 5000 );
				} ).catch( function () { setTimeout( poll, 15000 ); } );
			}
			setTimeout( poll, 5000 );
		} )();
		</script>
		<?php
	}

	/**
	 * Backfill progress for the settings page poll.
	 */
	public static function ajax_backfill_status() {
		check_ajax_referer( 'pushmails_wp_backfill_status' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$state = PushMails_Sync::backfill_state();
		wp_send_json_success(
			array(
				'status'         => $state ? $state['status'] : null,
				'sent_formatted' => number_format_i18n( $state ? (int) $state['sent'] : 0 ),
			)
		);
	}

	/**
	 * Forgets the token and everything tied to the connection. The site stays
	 * listed in PushMails until it is removed there; its token keeps working
	 * for anyone who copied it, so the panel is where access is revoked.
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pushmails-for-wordpress' ), 403 );
		}
		check_admin_referer( 'pushmails_wp_disconnect' );
		delete_option( self::OPTION_TOKEN );
		delete_option( self::OPTION_CONNECTION );
		delete_option( self::OPTION_ERROR );
		delete_option( PushMails_Sync::option() );
		delete_option( PushMails_Mailer::status_option() );
		PushMails_Sync::unschedule_all();
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Starts the backfill from the button.
	 */
	public static function handle_backfill() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pushmails-for-wordpress' ), 403 );
		}
		check_admin_referer( 'pushmails_wp_backfill' );
		if ( self::is_connected() ) {
			PushMails_Sync::start_backfill();
		}
		wp_safe_redirect( self::url() );
		exit;
	}
}
