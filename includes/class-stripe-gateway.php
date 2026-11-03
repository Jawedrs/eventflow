<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Stripe_Gateway {
	private const API_URL = 'https://api.stripe.com/v1/checkout/sessions';
	private const REFUNDS_API_URL = 'https://api.stripe.com/v1/refunds';
	private const SESSION_MINUTES = 35;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_webhook_route' ) );
	}

	public static function settings(): array {
		$settings = get_option( 'eventflow_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	public static function is_configured(): bool {
		$settings = self::settings();
		return '' !== self::secret_key( $settings ) && '' !== self::webhook_secret( $settings );
	}

	public function create_checkout( int $booking_id, string $booking_code, int $event_id, int $amount_minor, string $currency, string $attendee_email ): array|\WP_Error {
		$settings  = self::settings();
		$secret    = self::secret_key( $settings );
		$event_url = get_permalink( $event_id );
		$expires   = time() + ( self::SESSION_MINUTES * MINUTE_IN_SECONDS );
		$success   = add_query_arg( array( 'eventflow_booking' => 'pending', 'reference' => rawurlencode( $booking_code ) ), $event_url ) . '&session_id={CHECKOUT_SESSION_ID}';
		$cancel    = add_query_arg( 'eventflow_booking', 'payment_cancelled', $event_url );
		$body      = array(
			'mode'                                  => 'payment',
			'client_reference_id'                  => (string) $booking_id,
			'customer_email'                       => $attendee_email,
			'success_url'                          => $success,
			'cancel_url'                           => $cancel,
			'expires_at'                           => $expires,
			'line_items[0][quantity]'              => 1,
			'line_items[0][price_data][currency]'  => strtolower( $currency ),
			'line_items[0][price_data][unit_amount]' => $amount_minor,
			'line_items[0][price_data][product_data][name]' => get_the_title( $event_id ),
			'metadata[booking_id]'                 => (string) $booking_id,
			'metadata[event_id]'                   => (string) $event_id,
			'metadata[booking_code]'               => $booking_code,
		);
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $secret, 'Idempotency-Key' => 'eventflow-booking-' . $booking_id . '-' . strtolower( $booking_code ), 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'eventflow_stripe_request', __( 'The payment service could not be reached.', 'eventflow' ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || empty( $data['id'] ) || empty( $data['url'] ) || 'https' !== wp_parse_url( $data['url'], PHP_URL_SCHEME ) || ! wp_http_validate_url( $data['url'] ) ) {
			return new \WP_Error( 'eventflow_stripe_checkout', __( 'The payment service could not start checkout. Please try again later.', 'eventflow' ) );
		}
		global $wpdb;
		$updated = $wpdb->update( Bookings::table_name(), array( 'checkout_session_id' => sanitize_text_field( $data['id'] ) ), array( 'id' => $booking_id, 'status' => 'pending_payment' ), array( '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $updated ) {
			return new \WP_Error( 'eventflow_stripe_booking', __( 'The booking could not be attached to its payment session.', 'eventflow' ) );
		}
		return array( 'id' => sanitize_text_field( $data['id'] ), 'url' => esc_url_raw( $data['url'] ) );
	}

	public function refund( string $payment_intent_id, int $booking_id ): array|\WP_Error {
		$secret = self::secret_key( self::settings() );
		if ( '' === $secret || '' === $payment_intent_id ) {
			return new \WP_Error( 'eventflow_stripe_refund_config', __( 'Stripe is not configured for refunds.', 'eventflow' ) );
		}
		$response = wp_remote_post(
			self::REFUNDS_API_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Idempotency-Key' => 'eventflow-refund-' . $booking_id,
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body' => array(
					'payment_intent' => $payment_intent_id,
					'reason' => 'requested_by_customer',
					'metadata[eventflow_booking_id]' => (string) $booking_id,
					'metadata[eventflow_plugin]' => 'eventflow',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'eventflow_stripe_refund_request', __( 'Stripe could not be reached. You can retry safely; refund requests use an idempotency key.', 'eventflow' ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || empty( $data['id'] ) ) {
			return new \WP_Error( 'eventflow_stripe_refund_failed', __( 'Stripe did not accept the refund. The booking remains active.', 'eventflow' ) );
		}
		return array( 'id' => sanitize_text_field( $data['id'] ), 'status' => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'pending' );
	}

	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $request->get_body();
		$header  = $request->get_header( 'stripe-signature' );
		$secret  = self::webhook_secret( self::settings() );
		if ( '' === $secret || ! $this->verify_signature( $payload, $header, $secret ) ) {
			return new \WP_Error( 'eventflow_stripe_signature', __( 'Invalid payment notification signature.', 'eventflow' ), array( 'status' => 400 ) );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['data']['object'] ) ) {
			return new \WP_Error( 'eventflow_stripe_payload', __( 'Invalid payment notification payload.', 'eventflow' ), array( 'status' => 400 ) );
		}
		$session = $event['data']['object'];
		$kind    = sanitize_text_field( $event['type'] );
		$session_id = isset( $session['id'] ) ? sanitize_text_field( $session['id'] ) : '';
		if ( 'refund.updated' === $kind && isset( $session['metadata']['eventflow_plugin'], $session['metadata']['eventflow_booking_id'] ) && 'eventflow' === $session['metadata']['eventflow_plugin'] ) {
			$booking_id = absint( $session['metadata']['eventflow_booking_id'] );
			$refund_id = isset( $session['id'] ) ? sanitize_text_field( $session['id'] ) : '';
			$refund_status = isset( $session['status'] ) ? sanitize_key( $session['status'] ) : '';
			if ( $booking_id && $refund_id && in_array( $refund_status, array( 'succeeded', 'failed', 'canceled' ), true ) ) {
				global $wpdb;
				$status = 'succeeded' === $refund_status ? 'refunded' : 'refund_failed';
				$updated = $wpdb->update( Bookings::table_name(), array( 'status' => $status ), array( 'id' => $booking_id, 'status' => 'refund_pending', 'refund_id' => $refund_id ), array( '%s' ), array( '%d', '%s', '%s' ) );
				if ( false === $updated ) {
					return new \WP_Error( 'eventflow_stripe_refund_update', __( 'The refund update could not be saved yet.', 'eventflow' ), array( 'status' => 500 ) );
				}
				if ( 0 === $updated ) {
					$stored_refund = $wpdb->get_var( $wpdb->prepare( 'SELECT refund_id FROM ' . Bookings::table_name() . ' WHERE id = %d', $booking_id ) );
					if ( $refund_id !== $stored_refund ) {
						return new \WP_Error( 'eventflow_stripe_refund_retry', __( 'The refund request is not attached to its booking yet.', 'eventflow' ), array( 'status' => 500 ) );
					}
				}
			}
		} elseif ( 'checkout.session.completed' === $kind || 'checkout.session.async_payment_succeeded' === $kind ) {
			if ( isset( $session['payment_status'] ) && 'paid' === $session['payment_status'] ) {
				if ( ! $this->confirm_session( $session_id, $session ) ) {
					return new \WP_Error( 'eventflow_stripe_confirmation', __( 'The payment could not be applied to the booking yet.', 'eventflow' ), array( 'status' => 500 ) );
				}
			}
		} elseif ( in_array( $kind, array( 'checkout.session.expired', 'checkout.session.async_payment_failed' ), true ) ) {
			if ( ! $this->expire_session( $session_id ) ) {
				return new \WP_Error( 'eventflow_stripe_expiration', __( 'The expired booking could not be released yet.', 'eventflow' ), array( 'status' => 500 ) );
			}
		}
		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	public function register_webhook_route(): void {
		register_rest_route( 'eventflow/v1', '/stripe-webhook', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	private static function secret_key( array $settings ): string {
		if ( defined( 'EVENTFLOW_STRIPE_SECRET_KEY' ) ) {
			return trim( (string) EVENTFLOW_STRIPE_SECRET_KEY );
		}
		return isset( $settings['stripe_secret_key'] ) ? trim( (string) $settings['stripe_secret_key'] ) : '';
	}

	private static function webhook_secret( array $settings ): string {
		if ( defined( 'EVENTFLOW_STRIPE_WEBHOOK_SECRET' ) ) {
			return trim( (string) EVENTFLOW_STRIPE_WEBHOOK_SECRET );
		}
		return isset( $settings['stripe_webhook_secret'] ) ? trim( (string) $settings['stripe_webhook_secret'] ) : '';
	}

	private function verify_signature( string $payload, string $header, string $secret ): bool {
		$timestamp = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = absint( $pair[1] );
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = sanitize_text_field( $pair[1] );
			}
		}
		if ( ! $timestamp || abs( time() - $timestamp ) > 300 || empty( $signatures ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	private function confirm_session( string $session_id, array $session ): bool {
		global $wpdb;
		$table   = Bookings::table_name();
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE checkout_session_id = %s AND status = %s", $session_id, 'pending_payment' ) );
		if ( ! $booking ) {
			return true;
		}
		if ( ! isset( $session['amount_total'], $session['currency'] ) || (int) $session['amount_total'] !== (int) $booking->ticket_amount || strtolower( (string) $session['currency'] ) !== strtolower( (string) $booking->currency ) ) {
			return false;
		}
		$payment_intent = isset( $session['payment_intent'] ) && is_string( $session['payment_intent'] ) ? sanitize_text_field( $session['payment_intent'] ) : '';
		$changed = $wpdb->update( $table, array( 'status' => 'confirmed', 'payment_intent_id' => $payment_intent ), array( 'id' => (int) $booking->id, 'status' => 'pending_payment', 'checkout_session_id' => $session_id ), array( '%s', '%s' ), array( '%d', '%s', '%s' ) );
		if ( 1 === $changed ) {
			$name  = (string) $booking->attendee_name;
			$email = (string) $booking->attendee_email;
			$subject = sprintf( __( 'Your booking for %s', 'eventflow' ), get_the_title( $booking->event_id ) );
			$message = sprintf( __( "Hi %1\$s,\n\nYour paid booking for %2\$s is confirmed.\n\nBooking reference: %3\$s\n\nKeep this reference for check-in.", 'eventflow' ), $name, get_the_title( $booking->event_id ), $booking->booking_code );
			wp_mail( $email, $subject, $message );
			return true;
		}
		return false !== $changed;
	}

	private function expire_session( string $session_id ): bool {
		global $wpdb;
		$table   = Bookings::table_name();
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT id, event_id FROM {$table} WHERE checkout_session_id = %s AND status = %s", $session_id, 'pending_payment' ) );
		if ( ! $booking ) {
			return true;
		}
		$name = 'eventflow_' . md5( $wpdb->prefix . '|' . (int) $booking->event_id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) ) ) {
			return false;
		}
		$changed = $wpdb->update( $table, array( 'status' => 'cancelled' ), array( 'id' => (int) $booking->id, 'status' => 'pending_payment' ), array( '%s' ), array( '%d', '%s' ) );
		if ( 1 === $changed ) {
			$inventory_updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s", (int) $booking->event_id, '_eventflow_remaining_capacity' ) );
			if ( false === $inventory_updated ) {
				$wpdb->update( $table, array( 'status' => 'pending_payment' ), array( 'id' => (int) $booking->id, 'status' => 'cancelled' ), array( '%s' ), array( '%d', '%s' ) );
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
				return false;
			}
			wp_cache_delete( (int) $booking->event_id, 'post_meta' );
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		return false !== $changed;
	}
}
