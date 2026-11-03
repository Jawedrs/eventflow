<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Bookings {
	private const DB_VERSION = '5';
	private const TABLE_SUFFIX = 'eventflow_bookings';
	private ?string $active_lock = null;

	public function __construct() {
		add_action( 'admin_post_eventflow_reserve', array( $this, 'reserve' ) );
		add_action( 'admin_post_nopriv_eventflow_reserve', array( $this, 'reserve' ) );
		add_action( 'admin_post_eventflow_cancel_booking', array( $this, 'cancel_booking' ) );
		add_action( 'admin_post_nopriv_eventflow_cancel_booking', array( $this, 'cancel_booking' ) );
		add_action( 'admin_post_eventflow_check_in', array( $this, 'check_in' ) );
		add_action( 'admin_post_eventflow_refund_booking', array( $this, 'refund_booking' ) );
		add_action( 'admin_post_eventflow_export_bookings', array( $this, 'export_bookings' ) );
		add_action( 'admin_menu', array( $this, 'register_booking_page' ) );
		add_shortcode( 'eventflow_booking_lookup', array( $this, 'render_booking_lookup' ) );
		add_action( 'admin_init', array( self::class, 'maybe_install' ) );
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( 'eventflow_db_version' ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			attendee_name varchar(190) NOT NULL,
			attendee_email varchar(190) NOT NULL,
			booking_code varchar(32) NOT NULL,
			ticket_amount bigint(20) unsigned NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT '',
			checkout_session_id varchar(255) DEFAULT NULL,
			payment_intent_id varchar(255) DEFAULT NULL,
			refund_id varchar(255) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY booking_code (booking_code),
			UNIQUE KEY event_attendee (event_id,attendee_email),
			UNIQUE KEY checkout_session (checkout_session_id),
			KEY event_status (event_id,status),
			KEY attendee_email (attendee_email)
		) {$charset_collate};" );
		update_option( 'eventflow_db_version', self::DB_VERSION, false );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function confirmed_count( int $event_id ): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status IN (%s, %s, %s)", $event_id, 'confirmed', 'checked_in', 'pending_payment' ) );
	}

	public static function update_inventory( int $event_id, int $capacity ): bool {
		if ( ! self::lock_inventory( $event_id ) ) {
			return false;
		}
		try {
		$confirmed = self::confirmed_count( $event_id );
		if ( $capacity < $confirmed ) {
			return false;
		}
		update_post_meta( $event_id, '_eventflow_ticket_capacity', $capacity );
		update_post_meta( $event_id, '_eventflow_remaining_capacity', $capacity - $confirmed );
		return true;
		} finally {
			self::unlock_inventory( $event_id );
		}
	}

	public function reserve(): void {
		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		if ( ! $event_id || ! isset( $_POST['eventflow_booking_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventflow_booking_nonce'] ) ), 'eventflow_reserve_' . $event_id ) ) {
			wp_die( esc_html__( 'This booking request could not be verified. Please return to the event and try again.', 'eventflow' ), esc_html__( 'Booking unavailable', 'eventflow' ), array( 'response' => 403 ) );
		}

		if ( 'ef_event' !== get_post_type( $event_id ) || 'publish' !== get_post_status( $event_id ) ) {
			$this->redirect_with_notice( get_post_type_archive_link( 'ef_event' ), 'unavailable' );
		}
		$redirect = get_permalink( $event_id ) ?: get_post_type_archive_link( 'ef_event' );
		if ( ! $this->acquire_event_lock( $event_id ) ) {
			$this->redirect_with_notice( $redirect, 'failed' );
		}

		$name  = isset( $_POST['attendee_name'] ) ? sanitize_text_field( wp_unslash( $_POST['attendee_name'] ) ) : '';
		$email = isset( $_POST['attendee_email'] ) ? sanitize_email( wp_unslash( $_POST['attendee_email'] ) ) : '';
		if ( '' === $name || ! is_email( $email ) ) {
			$this->redirect_with_notice( $redirect, 'details' );
		}
		if ( $this->has_active_booking( $event_id, $email ) ) {
			$this->redirect_with_notice( $redirect, 'duplicate' );
		}
		$amount_minor = (int) get_post_meta( $event_id, '_eventflow_ticket_price_minor', true );
		$currency = strtolower( (string) get_post_meta( $event_id, '_eventflow_ticket_currency', true ) );
		if ( '' === $currency ) {
			$settings = Stripe_Gateway::settings();
			$currency = isset( $settings['currency'] ) ? strtolower( $settings['currency'] ) : 'usd';
		}
		if ( $amount_minor > 0 && ! Stripe_Gateway::is_configured() ) {
			$this->redirect_with_notice( $redirect, 'payment_not_ready' );
		}
		if ( $amount_minor > 0 && ! in_array( $currency, Settings::currencies(), true ) ) {
			$this->redirect_with_notice( $redirect, 'failed' );
		}

		if ( ! $this->claim_ticket( $event_id ) ) {
			$this->redirect_with_notice( $redirect, 'soldout' );
		}

		$code   = $this->generate_code();
		$status = $amount_minor > 0 ? 'pending_payment' : 'confirmed';
		$saved  = $this->create_booking( $event_id, $name, $email, $code, $status, $amount_minor, $amount_minor > 0 ? $currency : '' );
		if ( ! $saved ) {
			$this->release_ticket( $event_id );
			$this->redirect_with_notice( $redirect, 'failed' );
		}
		$booking_id = $this->booking_id( $event_id, $email );
		$this->release_event_lock();
		if ( $amount_minor > 0 ) {
			$checkout = ( new Stripe_Gateway() )->create_checkout( $booking_id, $code, $event_id, $amount_minor, $currency, $email );
			if ( is_wp_error( $checkout ) ) {
				$this->cancel_pending_booking( $event_id, $email );
				$this->redirect_with_notice( $redirect, 'failed' );
			}
			wp_redirect( $checkout['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URL is returned by Stripe over a verified HTTPS API request.
			exit;
		}

		$this->send_confirmation( $event_id, $name, $email, $code );
		$this->redirect_with_notice( $redirect, 'confirmed', $code );
	}

	private function claim_ticket( int $event_id ): bool {
		global $wpdb;
		$remaining_key = '_eventflow_remaining_capacity';
		$capacity = (int) get_post_meta( $event_id, '_eventflow_ticket_capacity', true );
		if ( $capacity < 1 ) {
			return false;
		}
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) - 1 WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS UNSIGNED) > 0", $event_id, $remaining_key ) );
		if ( 1 === $updated ) {
			wp_cache_delete( $event_id, 'post_meta' );
		}
		return 1 === $updated;
	}

	private function release_ticket( int $event_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s", $event_id, '_eventflow_remaining_capacity' ) );
		wp_cache_delete( $event_id, 'post_meta' );
	}

	private function create_booking( int $event_id, string $name, string $email, string $code, string $status = 'confirmed', int $amount_minor = 0, string $currency = '' ): bool {
		global $wpdb;
		$table    = self::table_name();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE event_id = %d AND attendee_email = %s LIMIT 1", $event_id, $email ) );
		if ( $existing ) {
			if ( 'cancelled' !== $existing->status ) {
				return false;
			}
			return 1 === $wpdb->update( $table, array( 'attendee_name' => $name, 'booking_code' => $code, 'ticket_amount' => $amount_minor, 'currency' => $currency, 'checkout_session_id' => null, 'status' => $status, 'created_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $existing->id, 'status' => 'cancelled' ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ), array( '%d', '%s' ) );
		}
		return false !== $wpdb->insert( self::table_name(), array( 'event_id' => $event_id, 'attendee_name' => $name, 'attendee_email' => $email, 'booking_code' => $code, 'ticket_amount' => $amount_minor, 'currency' => $currency, 'status' => $status, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );
	}

	private function has_active_booking( int $event_id, string $email ): bool {
		global $wpdb;
		$table = self::table_name();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_id = %d AND attendee_email = %s AND status IN (%s, %s, %s) LIMIT 1", $event_id, $email, 'confirmed', 'checked_in', 'pending_payment' ) );
	}

	private function booking_id( int $event_id, string $email ): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_id = %d AND attendee_email = %s LIMIT 1", $event_id, $email ) );
	}

	private function cancel_pending_booking( int $event_id, string $email ): void {
		global $wpdb;
		if ( ! $this->acquire_event_lock( $event_id ) ) {
			return;
		}
		$changed = $wpdb->update( self::table_name(), array( 'status' => 'cancelled' ), array( 'event_id' => $event_id, 'attendee_email' => $email, 'status' => 'pending_payment' ), array( '%s' ), array( '%d', '%s', '%s' ) );
		if ( 1 === $changed ) {
			$this->release_ticket( $event_id );
		}
		$this->release_event_lock();
	}

	public function cancel_booking(): void {
		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		$redirect   = isset( $_POST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_POST['redirect_to'] ), home_url( '/' ) ) : home_url( '/' );
		if ( ! $booking_id || ! isset( $_POST['eventflow_cancel_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventflow_cancel_nonce'] ) ), 'eventflow_cancel_booking_' . $booking_id ) ) {
			wp_die( esc_html__( 'This cancellation request could not be verified.', 'eventflow' ), esc_html__( 'Cancellation unavailable', 'eventflow' ), array( 'response' => 403 ) );
		}
		$email   = isset( $_POST['attendee_email'] ) ? sanitize_email( wp_unslash( $_POST['attendee_email'] ) ) : '';
		$booking = $this->find_booking( $booking_id, $email );
		$token = isset( $_POST['eventflow_cancel_token'] ) ? sanitize_text_field( wp_unslash( $_POST['eventflow_cancel_token'] ) ) : '';
		if ( ! $booking || (int) $booking->ticket_amount > 0 || ! hash_equals( $this->cancellation_token( $booking_id, $email ), $token ) || ! $this->acquire_event_lock( (int) $booking->event_id ) ) {
			wp_safe_redirect( add_query_arg( 'eventflow_lookup', 'unavailable', $redirect ) );
			exit;
		}
		$booking = $this->find_booking( $booking_id, $email );
		if ( ! $booking || 'confirmed' !== $booking->status || ! $this->set_status( $booking_id, 'cancelled', 'confirmed' ) ) {
			$this->release_event_lock();
			wp_safe_redirect( add_query_arg( 'eventflow_lookup', 'unavailable', $redirect ) );
			exit;
		}
		$this->release_ticket( (int) $booking->event_id );
		$this->release_event_lock();
		wp_safe_redirect( add_query_arg( 'eventflow_lookup', 'cancelled', $redirect ) );
		exit;
	}

	public function check_in(): void {
		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		$booking = $this->get_booking_by_id( $booking_id );
		$can_manage = current_user_can( 'manage_options' ) || ( $booking && (int) get_post_field( 'post_author', $booking->event_id ) === get_current_user_id() && current_user_can( 'edit_ef_events' ) );
		if ( ! $can_manage || ! $booking_id || ! isset( $_POST['eventflow_check_in_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventflow_check_in_nonce'] ) ), 'eventflow_check_in_' . $booking_id ) ) {
			wp_die( esc_html__( 'You cannot check in this attendee.', 'eventflow' ), esc_html__( 'Check-in unavailable', 'eventflow' ), array( 'response' => 403 ) );
		}
		$this->set_status( $booking_id, 'checked_in', 'confirmed' );
		wp_safe_redirect( admin_url( 'edit.php?post_type=ef_event&page=eventflow-bookings&eventflow_checkin=done' ) );
		exit;
	}

	public function refund_booking(): void {
		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		$booking    = $this->get_booking_by_id( $booking_id );
		$can_manage = current_user_can( 'manage_options' ) || ( $booking && (int) get_post_field( 'post_author', $booking->event_id ) === get_current_user_id() && current_user_can( 'edit_ef_events' ) );
		if ( ! $can_manage || ! $booking_id || ! isset( $_POST['eventflow_refund_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventflow_refund_nonce'] ) ), 'eventflow_refund_' . $booking_id ) ) {
			wp_die( esc_html__( 'You cannot refund this booking.', 'eventflow' ), esc_html__( 'Refund unavailable', 'eventflow' ), array( 'response' => 403 ) );
		}

		$redirect = admin_url( 'edit.php?post_type=ef_event&page=eventflow-bookings' );
		if ( ! $booking || (int) $booking->ticket_amount < 1 || empty( $booking->payment_intent_id ) || ! in_array( $booking->status, array( 'confirmed', 'refund_pending' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'eventflow_refund', 'unavailable', $redirect ) );
			exit;
		}
		if ( 'confirmed' === $booking->status ) {
			if ( ! $this->acquire_event_lock( (int) $booking->event_id ) ) {
				wp_safe_redirect( add_query_arg( 'eventflow_refund', 'unavailable', $redirect ) );
				exit;
			}
			$claimed = $this->set_status( $booking_id, 'refund_pending', 'confirmed' );
			$this->release_event_lock();
			if ( ! $claimed ) {
				wp_safe_redirect( add_query_arg( 'eventflow_refund', 'unavailable', $redirect ) );
				exit;
			}
		}

		$refund = ( new Stripe_Gateway() )->refund( (string) $booking->payment_intent_id, $booking_id );
		if ( is_wp_error( $refund ) ) {
			global $wpdb;
			$wpdb->update( self::table_name(), array( 'status' => 'confirmed' ), array( 'id' => $booking_id, 'status' => 'refund_pending' ), array( '%s' ), array( '%d', '%s' ) );
			wp_safe_redirect( add_query_arg( 'eventflow_refund', 'failed', $redirect ) );
			exit;
		}

		if ( ! $this->acquire_event_lock( (int) $booking->event_id ) ) {
			wp_safe_redirect( add_query_arg( 'eventflow_refund', 'processing', $redirect ) );
			exit;
		}
		global $wpdb;
		$refund_status = 'succeeded' === $refund['status'] ? 'refunded' : 'refund_pending';
		$updated = $wpdb->update( self::table_name(), array( 'refund_id' => $refund['id'], 'status' => $refund_status ), array( 'id' => $booking_id, 'status' => 'refund_pending', 'refund_id' => null ), array( '%s', '%s' ), array( '%d', '%s', '%s' ) );
		if ( $updated ) {
			$this->release_ticket( (int) $booking->event_id );
			$subject = sprintf( __( 'Refund update for %s', 'eventflow' ), get_the_title( $booking->event_id ) );
			$message = sprintf( __( "Hi %1\$s,\n\nA full refund for your booking for %2\$s was requested through Stripe. Stripe may take additional time to process the refund.\n\nBooking reference: %3\$s", 'eventflow' ), $booking->attendee_name, get_the_title( $booking->event_id ), $booking->booking_code );
			wp_mail( $booking->attendee_email, $subject, $message );
		}
		$this->release_event_lock();
		wp_safe_redirect( add_query_arg( 'eventflow_refund', false !== $updated ? ( 'succeeded' === $refund['status'] ? 'success' : 'processing' ) : 'processing', $redirect ) );
		exit;
	}

	public function register_booking_page(): void {
		add_submenu_page( 'edit.php?post_type=ef_event', __( 'Bookings', 'eventflow' ), __( 'Bookings', 'eventflow' ), 'edit_ef_events', 'eventflow-bookings', array( $this, 'render_booking_page' ) );
	}

	public function export_bookings(): void {
		if ( ! current_user_can( 'edit_ef_events' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'eventflow_export_bookings' ) ) {
			wp_die( esc_html__( 'You cannot export these bookings.', 'eventflow' ), esc_html__( 'Export unavailable', 'eventflow' ), array( 'response' => 403 ) );
		}
		global $wpdb;
		$table = self::table_name();
		$handle = fopen( 'php://output', 'w' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'The booking export could not be started.', 'eventflow' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=eventflow-bookings-' . gmdate( 'Y-m-d' ) . '.csv' );
		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv( $handle, array( __( 'Event', 'eventflow' ), __( 'Attendee name', 'eventflow' ), __( 'Attendee email', 'eventflow' ), __( 'Booking reference', 'eventflow' ), __( 'Amount', 'eventflow' ), __( 'Status', 'eventflow' ), __( 'Created (UTC)', 'eventflow' ) ), ',', '"', '' );
		$last_id = 0;
		while ( true ) {
			if ( current_user_can( 'manage_options' ) ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.id, b.attendee_name, b.attendee_email, b.booking_code, b.ticket_amount, b.currency, b.status, b.created_at, p.post_title FROM {$table} b INNER JOIN {$wpdb->posts} p ON p.ID = b.event_id WHERE b.id > %d ORDER BY b.id ASC LIMIT %d", $last_id, 500 ) );
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.id, b.attendee_name, b.attendee_email, b.booking_code, b.ticket_amount, b.currency, b.status, b.created_at, p.post_title FROM {$table} b INNER JOIN {$wpdb->posts} p ON p.ID = b.event_id WHERE b.id > %d AND p.post_author = %d ORDER BY b.id ASC LIMIT %d", $last_id, get_current_user_id(), 500 ) );
			}
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$amount = (int) $row->ticket_amount > 0 ? strtoupper( $row->currency ) . ' ' . number_format( (int) $row->ticket_amount / 100, 2, '.', '' ) : __( 'Free', 'eventflow' );
				$values = array( $row->post_title, $row->attendee_name, $row->attendee_email, $row->booking_code, $amount, $row->status, $row->created_at );
				$values = array_map( array( $this, 'spreadsheet_safe_value' ), $values );
				fputcsv( $handle, $values, ',', '"', '' );
				$last_id = (int) $row->id;
			}
		}
		fclose( $handle );
		exit;
	}

	private function spreadsheet_safe_value( string $value ): string {
		return preg_match( '/^[\t\r ]*[=+@-]/', $value ) ? "'" . $value : $value;
	}

	public function render_booking_page(): void {
		if ( ! current_user_can( 'edit_ef_events' ) ) {
			return;
		}
		global $wpdb;
		$table    = self::table_name();
		if ( current_user_can( 'manage_options' ) ) {
			$bookings = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-controlled.
		} else {
			$bookings = $wpdb->get_results( $wpdb->prepare( "SELECT b.* FROM {$table} b INNER JOIN {$wpdb->posts} p ON p.ID = b.event_id WHERE p.post_author = %d ORDER BY b.created_at DESC LIMIT 100", get_current_user_id() ) );
		}
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Bookings', 'eventflow' ); ?></h1>
		<?php if ( isset( $_GET['eventflow_checkin'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Attendee checked in.', 'eventflow' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['eventflow_refund'] ) ) : $refund_notice = sanitize_key( wp_unslash( $_GET['eventflow_refund'] ) ); ?><div class="notice <?php echo 'success' === $refund_notice ? 'notice-success' : ( 'failed' === $refund_notice || 'unavailable' === $refund_notice ? 'notice-error' : 'notice-warning' ); ?> is-dismissible"><p><?php echo esc_html( match ( $refund_notice ) { 'success' => __( 'Stripe completed the full refund and the ticket was released.', 'eventflow' ), 'failed' => __( 'Stripe could not process the refund. The booking remains confirmed.', 'eventflow' ), 'processing' => __( 'Stripe accepted the refund request. The ticket was released; Stripe may still be processing the payment.', 'eventflow' ), default => __( 'This booking is no longer eligible for a refund.', 'eventflow' ) } ); ?></p></div><?php endif; ?>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eventflow_export_bookings' ), 'eventflow_export_bookings' ) ); ?>"><?php esc_html_e( 'Export all bookings (CSV)', 'eventflow' ); ?></a></p>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Event', 'eventflow' ); ?></th><th><?php esc_html_e( 'Attendee', 'eventflow' ); ?></th><th><?php esc_html_e( 'Reference', 'eventflow' ); ?></th><th><?php esc_html_e( 'Amount', 'eventflow' ); ?></th><th><?php esc_html_e( 'Status', 'eventflow' ); ?></th><th><?php esc_html_e( 'Action', 'eventflow' ); ?></th></tr></thead><tbody>
		<?php if ( $bookings ) : ?>
			<?php foreach ( $bookings as $booking ) : ?>
				<tr><td><?php echo esc_html( get_the_title( $booking->event_id ) ); ?></td><td><?php echo esc_html( $booking->attendee_name ); ?><br><a href="mailto:<?php echo esc_attr( $booking->attendee_email ); ?>"><?php echo esc_html( $booking->attendee_email ); ?></a></td><td><code><?php echo esc_html( $booking->booking_code ); ?></code></td><td><?php echo esc_html( (int) $booking->ticket_amount > 0 ? strtoupper( $booking->currency ) . ' ' . number_format( (int) $booking->ticket_amount / 100, 2 ) : __( 'Free', 'eventflow' ) ); ?></td><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $booking->status ) ) ); ?></td><td><?php if ( in_array( $booking->status, array( 'confirmed', 'refund_pending' ), true ) && (int) $booking->ticket_amount > 0 && ! empty( $booking->payment_intent_id ) && Stripe_Gateway::is_configured() ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm( '<?php echo esc_js( __( 'Issue a full refund through Stripe and cancel this ticket?', 'eventflow' ) ); ?>' );"><input type="hidden" name="action" value="eventflow_refund_booking"><input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking->id ); ?>"><?php wp_nonce_field( 'eventflow_refund_' . $booking->id, 'eventflow_refund_nonce' ); ?><button class="button"><?php esc_html_e( 'Refund & cancel', 'eventflow' ); ?></button></form><?php endif; ?><?php if ( 'confirmed' === $booking->status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="eventflow_check_in"><input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking->id ); ?>"><?php wp_nonce_field( 'eventflow_check_in_' . $booking->id, 'eventflow_check_in_nonce' ); ?><button class="button button-primary"><?php esc_html_e( 'Check in', 'eventflow' ); ?></button></form><?php endif; ?></td></tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No bookings yet.', 'eventflow' ); ?></td></tr>
		<?php endif; ?>
		</tbody></table></div>
		<?php
	}

	public function render_booking_lookup(): string {
		$code  = isset( $_GET['eventflow_reference'] ) ? sanitize_text_field( wp_unslash( $_GET['eventflow_reference'] ) ) : '';
		$email = isset( $_GET['eventflow_email'] ) ? sanitize_email( wp_unslash( $_GET['eventflow_email'] ) ) : '';
		ob_start();
		?>
		<section class="eventflow-lookup"><h2><?php esc_html_e( 'Find your booking', 'eventflow' ); ?></h2><form method="get"><label for="eventflow-reference"><?php esc_html_e( 'Booking reference', 'eventflow' ); ?></label><input id="eventflow-reference" name="eventflow_reference" required value="<?php echo esc_attr( $code ); ?>"><label for="eventflow-email"><?php esc_html_e( 'Email address', 'eventflow' ); ?></label><input id="eventflow-email" name="eventflow_email" required type="email" value="<?php echo esc_attr( $email ); ?>"><button type="submit"><?php esc_html_e( 'Find booking', 'eventflow' ); ?></button></form>
		<?php
		if ( $code && $email ) {
			$booking = $this->find_booking_by_code( $code, $email );
			if ( $booking ) {
				?><div class="eventflow-lookup__result"><h3><?php echo esc_html( get_the_title( $booking->event_id ) ); ?></h3><p><?php echo esc_html( sprintf( __( 'Status: %s', 'eventflow' ), ucfirst( str_replace( '_', ' ', $booking->status ) ) ) ); ?></p><?php if ( 'confirmed' === $booking->status && (int) $booking->ticket_amount > 0 ) : ?><p><?php esc_html_e( 'For a paid booking cancellation or refund, contact the event organizer.', 'eventflow' ); ?></p><?php elseif ( 'confirmed' === $booking->status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="eventflow_cancel_booking"><input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking->id ); ?>"><input type="hidden" name="attendee_email" value="<?php echo esc_attr( $email ); ?>"><input type="hidden" name="eventflow_cancel_token" value="<?php echo esc_attr( $this->cancellation_token( (int) $booking->id, $email ) ); ?>"><input type="hidden" name="redirect_to" value="<?php echo esc_url( get_permalink() ); ?>"><?php wp_nonce_field( 'eventflow_cancel_booking_' . $booking->id, 'eventflow_cancel_nonce' ); ?><button type="submit"><?php esc_html_e( 'Cancel booking', 'eventflow' ); ?></button></form><?php endif; ?></div><?php
			} else {
				?><p role="alert"><?php esc_html_e( 'We could not find a booking with those details.', 'eventflow' ); ?></p><?php
			}
		}
		if ( isset( $_GET['eventflow_lookup'] ) && 'cancelled' === sanitize_key( wp_unslash( $_GET['eventflow_lookup'] ) ) ) {
			?><p role="status"><?php esc_html_e( 'Your booking has been cancelled.', 'eventflow' ); ?></p><?php
		}
		?></section><?php
		return (string) ob_get_clean();
	}

	private function find_booking( int $booking_id, string $email ): ?object {
		global $wpdb;
		$table = self::table_name();
		$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND attendee_email = %s", $booking_id, $email ) );
		return $result ?: null;
	}

	private function get_booking_by_id( int $booking_id ): ?object {
		global $wpdb;
		$table  = self::table_name();
		$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $booking_id ) );
		return $result ?: null;
	}

	private function cancellation_token( int $booking_id, string $email ): string {
		return hash_hmac( 'sha256', $booking_id . '|' . strtolower( $email ), wp_salt( 'auth' ) );
	}

	private function acquire_event_lock( int $event_id ): bool {
		$this->active_lock = self::lock_name( $event_id );
		if ( ! self::lock_inventory( $event_id ) ) {
			$this->active_lock = null;
			return false;
		}
		return true;
	}

	private function release_event_lock(): void {
		if ( null !== $this->active_lock ) {
		self::unlock_inventory( 0, $this->active_lock );
			$this->active_lock = null;
		}
	}

	private static function lock_name( int $event_id ): string {
		global $wpdb;
		return 'eventflow_' . md5( $wpdb->prefix . '|' . $event_id );
	}

	private static function lock_inventory( int $event_id ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::lock_name( $event_id ) ) );
	}

	private static function unlock_inventory( int $event_id, ?string $lock_name = null ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ?: self::lock_name( $event_id ) ) );
	}

	private function find_booking_by_code( string $code, string $email ): ?object {
		global $wpdb;
		$table = self::table_name();
		$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_code = %s AND attendee_email = %s", strtoupper( $code ), $email ) );
		return $result ?: null;
	}

	private function set_status( int $booking_id, string $new_status, string $current_status ): bool {
		global $wpdb;
		return 1 === $wpdb->update( self::table_name(), array( 'status' => $new_status ), array( 'id' => $booking_id, 'status' => $current_status ), array( '%s' ), array( '%d', '%s' ) );
	}

	private function generate_code(): string {
		return 'EF-' . strtoupper( wp_generate_password( 10, false, false ) );
	}

	private function send_confirmation( int $event_id, string $name, string $email, string $code ): void {
		$subject = sprintf( __( 'Your booking for %s', 'eventflow' ), get_the_title( $event_id ) );
		$message = sprintf( __( "Hi %1\$s,\n\nYour booking for %2\$s is confirmed.\n\nBooking reference: %3\$s\n\nKeep this reference for check-in.", 'eventflow' ), $name, get_the_title( $event_id ), $code );
		wp_mail( $email, $subject, $message );
	}

	private function redirect_with_notice( string $redirect, string $notice, string $code = '' ): void {
		$this->release_event_lock();
		$args = array( 'eventflow_booking' => $notice );
		if ( '' !== $code ) {
			$args['reference'] = $code;
		}
		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}
}
