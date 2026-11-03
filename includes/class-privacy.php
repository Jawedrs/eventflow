<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	private const PAGE_SIZE = 50;

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_filter( 'wp_privacy_policy_content', array( $this, 'add_privacy_policy_content' ) );
	}

	public function register_exporter( array $exporters ): array {
		$exporters['eventflow-bookings'] = array(
			'exporter_friendly_name' => __( 'EventFlow bookings', 'eventflow' ),
			'callback'               => array( $this, 'export_bookings' ),
		);
		return $exporters;
	}

	public function register_eraser( array $erasers ): array {
		$erasers['eventflow-bookings'] = array(
			'eraser_friendly_name' => __( 'EventFlow bookings', 'eventflow' ),
			'callback'             => array( $this, 'erase_bookings' ),
		);
		return $erasers;
	}

	public function export_bookings( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$table   = Bookings::table_name();
		$offset  = ( max( 1, $page ) - 1 ) * self::PAGE_SIZE;
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT id, event_id, attendee_name, attendee_email, booking_code, ticket_amount, currency, checkout_session_id, payment_intent_id, refund_id, status, created_at FROM {$table} WHERE attendee_email = %s ORDER BY id ASC LIMIT %d OFFSET %d", sanitize_email( $email_address ), self::PAGE_SIZE, $offset ) );
		$data    = array();
		foreach ( $results as $booking ) {
			$data[] = array(
				'group_id'    => 'eventflow-bookings',
				'group_label' => __( 'Event bookings', 'eventflow' ),
				'item_id'     => 'eventflow-booking-' . $booking->id,
				'data'        => array(
					array( 'name' => __( 'Event', 'eventflow' ), 'value' => get_the_title( $booking->event_id ) ),
					array( 'name' => __( 'Attendee name', 'eventflow' ), 'value' => $booking->attendee_name ),
					array( 'name' => __( 'Email address', 'eventflow' ), 'value' => $booking->attendee_email ),
					array( 'name' => __( 'Booking reference', 'eventflow' ), 'value' => $booking->booking_code ),
					array( 'name' => __( 'Ticket amount', 'eventflow' ), 'value' => (int) $booking->ticket_amount > 0 ? strtoupper( $booking->currency ) . ' ' . number_format( (int) $booking->ticket_amount / 100, 2 ) : __( 'Free', 'eventflow' ) ),
					array( 'name' => __( 'Payment session reference', 'eventflow' ), 'value' => $booking->checkout_session_id ),
					array( 'name' => __( 'Payment intent reference', 'eventflow' ), 'value' => $booking->payment_intent_id ),
					array( 'name' => __( 'Refund reference', 'eventflow' ), 'value' => $booking->refund_id ),
					array( 'name' => __( 'Status', 'eventflow' ), 'value' => $booking->status ),
					array( 'name' => __( 'Created', 'eventflow' ), 'value' => $booking->created_at ),
				),
			);
		}
		return array( 'data' => $data, 'done' => count( $results ) < self::PAGE_SIZE );
	}

	public function erase_bookings( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$table   = Bookings::table_name();
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT id, event_id, status FROM {$table} WHERE attendee_email = %s AND status NOT IN (%s, %s) ORDER BY id ASC LIMIT %d", sanitize_email( $email_address ), 'pending_payment', 'refund_pending', self::PAGE_SIZE ) );
		$removed = 0;
		$lock_failed = false;
		$pending_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE attendee_email = %s AND status IN (%s, %s)", sanitize_email( $email_address ), 'pending_payment', 'refund_pending' ) );
		foreach ( $results as $booking ) {
			$lock_name = 'eventflow_' . md5( $wpdb->prefix . '|' . (int) $booking->event_id );
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ) ) {
				$lock_failed = true;
				continue;
			}
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d AND attendee_email = %s", (int) $booking->id, sanitize_email( $email_address ) ) );
			if ( $current && false !== $wpdb->delete( $table, array( 'id' => (int) $booking->id ), array( '%d' ) ) ) {
				++$removed;
				if ( in_array( $current->status, array( 'confirmed', 'pending_payment' ), true ) ) {
					$this->release_ticket( (int) $booking->event_id );
				}
			}
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $pending_count > 0,
			'messages'       => $pending_count > 0 ? array( __( 'A booking with an open payment or refund is retained until Stripe confirms its final status. Run the eraser again after the transaction closes.', 'eventflow' ) ) : array(),
			'done'           => count( $results ) < self::PAGE_SIZE && ! $lock_failed,
		);
	}

	public function add_privacy_policy_content( string $content ): string {
		$section = '<h2>' . esc_html__( 'Event bookings', 'eventflow' ) . '</h2>';
		$section .= '<p>' . esc_html__( 'When visitors reserve a ticket, EventFlow stores their name, email address, booking reference, booking status, event, and booking time to manage attendance and send confirmation email. Site administrators and organizers who manage that event can view attendee details. Booking records are stored on this site and are not sent to EventFlow.', 'eventflow' ) . '</p>';
		$section .= '<p>' . esc_html__( 'Site administrators can export or erase EventFlow booking data using the WordPress privacy tools. Erasing a booking also releases its reserved ticket when it has not been checked in. A booking with an open Stripe checkout or refund is retained until the transaction reaches a final status.', 'eventflow' ) . '</p>';
		$section .= '<p>' . esc_html__( 'If the site administrator configures Stripe for paid tickets, the attendee email address and transaction details (event name, amount, currency, and booking reference) are sent to Stripe to create and verify checkout. Stripe processes this information under its own privacy policy.', 'eventflow' ) . '</p>';
		return $content . $section;
	}

	private function release_ticket( int $event_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s", $event_id, '_eventflow_remaining_capacity' ) );
		wp_cache_delete( $event_id, 'post_meta' );
	}
}
