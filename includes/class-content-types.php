<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Content_Types {
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_event_details_box' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_event_relations_box' ) );
		add_action( 'save_post_ef_event', array( $this, 'save_event_details' ) );
		add_action( 'admin_notices', array( $this, 'show_event_validation_notices' ) );
	}

	public static function register(): void {
		register_post_type(
			'ef_event',
			array(
				'labels' => array(
					'name'          => __( 'Events', 'eventflow' ),
					'singular_name' => __( 'Event', 'eventflow' ),
					'add_new_item'  => __( 'Add event', 'eventflow' ),
					'edit_item'     => __( 'Edit event', 'eventflow' ),
				),
				'public'       => true,
				'menu_icon'    => 'dashicons-tickets-alt',
				'has_archive'  => 'events',
				'rewrite'      => array( 'slug' => 'events' ),
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author' ),
				'capability_type' => array( 'ef_event', 'ef_events' ),
				'map_meta_cap' => true,
			)
		);

		foreach ( array( 'ef_venue' => array( __( 'Venues', 'eventflow' ), 'ef_venue', 'ef_venues' ), 'ef_organizer' => array( __( 'Organizers', 'eventflow' ), 'ef_organizer', 'ef_organizers' ) ) as $type => $definition ) {
			register_post_type( $type, array( 'label' => $definition[0], 'public' => false, 'show_ui' => true, 'show_in_rest' => true, 'supports' => array( 'title', 'editor', 'thumbnail', 'author' ), 'capability_type' => array( $definition[1], $definition[2] ), 'map_meta_cap' => true ) );
		}

		register_taxonomy( 'ef_event_category', 'ef_event', array( 'label' => __( 'Event categories', 'eventflow' ), 'public' => true, 'show_in_rest' => true, 'hierarchical' => true, 'rewrite' => array( 'slug' => 'event-category' ), 'capabilities' => array( 'manage_terms' => 'manage_categories', 'edit_terms' => 'manage_categories', 'delete_terms' => 'manage_categories', 'assign_terms' => 'edit_ef_events' ) ) );
	}

	public function add_event_details_box(): void {
		add_meta_box( 'eventflow-event-details', __( 'When and where', 'eventflow' ), array( $this, 'render_event_details_box' ), 'ef_event', 'normal', 'high' );
	}

	public function add_event_relations_box(): void {
		add_meta_box( 'eventflow-event-relations', __( 'People and place', 'eventflow' ), array( $this, 'render_event_relations_box' ), 'ef_event', 'side', 'default' );
	}

	public function render_event_relations_box( \WP_Post $post ): void {
		$venue_id     = (int) get_post_meta( $post->ID, '_eventflow_venue_id', true );
		$organizer_id = (int) get_post_meta( $post->ID, '_eventflow_organizer_id', true );
		$venue_args = array( 'post_type' => 'ef_venue', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC' );
		$organizer_args = array( 'post_type' => 'ef_organizer', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC' );
		if ( ! current_user_can( 'manage_options' ) ) {
			$venue_args['author'] = get_current_user_id();
			$organizer_args['author'] = get_current_user_id();
		}
		$venues       = get_posts( $venue_args );
		$organizers   = get_posts( $organizer_args );
		?>
		<p><label for="eventflow_venue_id"><strong><?php esc_html_e( 'Venue', 'eventflow' ); ?></strong></label><br><select class="widefat" id="eventflow_venue_id" name="eventflow_venue_id"><option value="0"><?php esc_html_e( 'No venue selected', 'eventflow' ); ?></option><?php foreach ( $venues as $venue ) : ?><option value="<?php echo esc_attr( $venue->ID ); ?>" <?php selected( $venue_id, $venue->ID ); ?>><?php echo esc_html( $venue->post_title ); ?></option><?php endforeach; ?></select></p>
		<p><label for="eventflow_organizer_id"><strong><?php esc_html_e( 'Organizer', 'eventflow' ); ?></strong></label><br><select class="widefat" id="eventflow_organizer_id" name="eventflow_organizer_id"><option value="0"><?php esc_html_e( 'No organizer selected', 'eventflow' ); ?></option><?php foreach ( $organizers as $organizer ) : ?><option value="<?php echo esc_attr( $organizer->ID ); ?>" <?php selected( $organizer_id, $organizer->ID ); ?>><?php echo esc_html( $organizer->post_title ); ?></option><?php endforeach; ?></select></p>
		<?php
	}

	public function render_event_details_box( \WP_Post $post ): void {
		wp_nonce_field( 'eventflow_save_event', 'eventflow_event_nonce' );
		$starts_at = get_post_meta( $post->ID, '_eventflow_starts_at', true );
		$ends_at   = get_post_meta( $post->ID, '_eventflow_ends_at', true );
		$location  = get_post_meta( $post->ID, '_eventflow_location', true );
		$capacity  = get_post_meta( $post->ID, '_eventflow_ticket_capacity', true );
		$price_minor = (int) get_post_meta( $post->ID, '_eventflow_ticket_price_minor', true );
		$currency = (string) get_post_meta( $post->ID, '_eventflow_ticket_currency', true );
		$site_settings = Stripe_Gateway::settings();
		if ( '' === $currency ) {
			$currency = isset( $site_settings['currency'] ) ? $site_settings['currency'] : 'usd';
		}
		$price_display = number_format( $price_minor / 100, 2, '.', '' );
		?>
		<p><label for="eventflow_starts_at"><strong><?php esc_html_e( 'Starts', 'eventflow' ); ?></strong></label><br><input class="regular-text" id="eventflow_starts_at" name="eventflow_starts_at" type="datetime-local" value="<?php echo esc_attr( $starts_at ); ?>"></p>
		<p><label for="eventflow_ends_at"><strong><?php esc_html_e( 'Ends', 'eventflow' ); ?></strong></label><br><input class="regular-text" id="eventflow_ends_at" name="eventflow_ends_at" type="datetime-local" value="<?php echo esc_attr( $ends_at ); ?>"></p>
		<p><label for="eventflow_location"><strong><?php esc_html_e( 'Location', 'eventflow' ); ?></strong></label><br><input class="regular-text" id="eventflow_location" name="eventflow_location" type="text" value="<?php echo esc_attr( $location ); ?>"></p>
		<p><label for="eventflow_ticket_capacity"><strong><?php esc_html_e( 'Ticket capacity', 'eventflow' ); ?></strong></label><br><input class="small-text" id="eventflow_ticket_capacity" min="0" name="eventflow_ticket_capacity" type="number" value="<?php echo esc_attr( $capacity ); ?>"><br><span class="description"><?php esc_html_e( 'Set a number greater than zero to open reservations.', 'eventflow' ); ?></span></p>
		<p><label for="eventflow_ticket_price"><strong><?php echo esc_html( sprintf( __( 'Ticket price (%s)', 'eventflow' ), strtoupper( $currency ) ) ); ?></strong></label><br><input class="regular-text" id="eventflow_ticket_price" min="0" max="1000000" name="eventflow_ticket_price" step="0.01" type="number" value="<?php echo esc_attr( $price_display ); ?>"><input name="eventflow_ticket_currency" type="hidden" value="<?php echo esc_attr( $currency ); ?>"><br><span class="description"><?php esc_html_e( 'Use 0 for a free ticket. Paid reservations use Stripe Checkout.', 'eventflow' ); ?></span></p>
		<?php
	}

	public function save_event_details( int $post_id ): void {
		if ( ! isset( $_POST['eventflow_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventflow_event_nonce'] ) ), 'eventflow_save_event' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$submitted_start = isset( $_POST['eventflow_starts_at'] ) ? sanitize_text_field( wp_unslash( $_POST['eventflow_starts_at'] ) ) : (string) get_post_meta( $post_id, '_eventflow_starts_at', true );
		$submitted_end   = isset( $_POST['eventflow_ends_at'] ) ? sanitize_text_field( wp_unslash( $_POST['eventflow_ends_at'] ) ) : (string) get_post_meta( $post_id, '_eventflow_ends_at', true );
		$start_date      = Event_Date::parse( $submitted_start );
		$end_date        = Event_Date::parse( $submitted_end );
		$invalid_schedule = ( '' !== $submitted_start && ! $start_date ) || ( '' !== $submitted_end && ! $end_date ) || ( $start_date && $end_date && $end_date <= $start_date );
		foreach ( array( 'starts_at', 'ends_at', 'location' ) as $field ) {
			if ( isset( $_POST[ "eventflow_{$field}" ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ "eventflow_{$field}" ] ) );
				if ( in_array( $field, array( 'starts_at', 'ends_at' ), true ) ) {
					if ( $invalid_schedule ) {
						continue;
					}
					if ( '' === $value ) {
						delete_post_meta( $post_id, "_eventflow_{$field}" );
					} elseif ( Event_Date::parse( $value ) ) {
						update_post_meta( $post_id, "_eventflow_{$field}", $value );
					}
				} else {
					update_post_meta( $post_id, "_eventflow_{$field}", $value );
				}
			}
		}
		if ( isset( $_POST['eventflow_ticket_capacity'] ) ) {
			$capacity = absint( $_POST['eventflow_ticket_capacity'] );
			if ( ! Bookings::update_inventory( $post_id, $capacity ) ) {
				$this->add_validation_redirect( 'eventflow_capacity_error' );
			}
		}
		if ( isset( $_POST['eventflow_ticket_price'], $_POST['eventflow_ticket_currency'] ) ) {
			$raw_price = sanitize_text_field( wp_unslash( $_POST['eventflow_ticket_price'] ) );
			$currency = strtolower( sanitize_text_field( wp_unslash( $_POST['eventflow_ticket_currency'] ) ) );
			if ( in_array( $currency, Settings::currencies(), true ) && preg_match( '/^\d{1,7}(?:\.\d{1,2})?$/', $raw_price ) ) {
				update_post_meta( $post_id, '_eventflow_ticket_price_minor', (int) round( (float) $raw_price * 100 ) );
				update_post_meta( $post_id, '_eventflow_ticket_currency', $currency );
			}
		}
		if ( $invalid_schedule ) {
			$this->add_validation_redirect( 'eventflow_schedule_error' );
		}
		foreach ( array( 'venue_id' => 'ef_venue', 'organizer_id' => 'ef_organizer' ) as $field => $post_type ) {
			if ( ! isset( $_POST[ "eventflow_{$field}" ] ) ) {
				continue;
			}
			$related_id = absint( $_POST[ "eventflow_{$field}" ] );
			if ( ! $related_id ) {
				delete_post_meta( $post_id, "_eventflow_{$field}" );
			} elseif ( $post_type === get_post_type( $related_id ) && current_user_can( 'edit_post', $related_id ) ) {
				update_post_meta( $post_id, "_eventflow_{$field}", $related_id );
			}
		}
	}

	public function show_event_validation_notices(): void {
		if ( ! current_user_can( 'edit_ef_events' ) ) {
			return;
		}
		if ( isset( $_GET['eventflow_capacity_error'] ) ) {
			?><div class="notice notice-error"><p><?php esc_html_e( 'Ticket capacity was not changed because it is lower than the number of active bookings.', 'eventflow' ); ?></p></div><?php
		}
		if ( isset( $_GET['eventflow_schedule_error'] ) ) {
			?><div class="notice notice-error"><p><?php esc_html_e( 'Event dates were not changed. Enter valid local dates and make sure the end is after the start.', 'eventflow' ); ?></p></div><?php
		}
	}

	private function add_validation_redirect( string $query_arg ): void {
		add_filter( 'redirect_post_location', static function ( string $location ) use ( $query_arg ): string {
			return add_query_arg( $query_arg, '1', $location );
		} );
	}
}
