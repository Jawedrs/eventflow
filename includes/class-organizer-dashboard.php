<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Organizer_Dashboard {
	public function __construct() {
		add_shortcode( 'eventflow_organizer_dashboard', array( $this, 'render' ) );
		add_action( 'admin_post_eventflow_create_event', array( $this, 'create_event' ) );
	}

	public function render(): string {
		if ( ! is_user_logged_in() ) {
			return '<section class="eventflow-organizer-dashboard"><p>' . esc_html__( 'Sign in to manage your events.', 'eventflow' ) . '</p><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in', 'eventflow' ) . '</a></section>';
		}
		if ( ! current_user_can( 'edit_ef_events' ) ) {
			return '<p>' . esc_html__( 'Your account cannot manage events.', 'eventflow' ) . '</p>';
		}
		$settings = Stripe_Gateway::settings();
		$currency = isset( $settings['currency'] ) ? strtoupper( $settings['currency'] ) : 'USD';
		$events = get_posts( array( 'post_type' => 'ef_event', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'author' => get_current_user_id(), 'posts_per_page' => 50, 'orderby' => 'modified', 'order' => 'DESC' ) );
		ob_start();
		?>
		<section class="eventflow-organizer-dashboard">
			<header><p class="eventflow-archive__intro"><?php esc_html_e( 'Organizer space', 'eventflow' ); ?></p><h2><?php esc_html_e( 'Your events', 'eventflow' ); ?></h2></header>
			<?php if ( isset( $_GET['eventflow_event_created'] ) ) : ?><p class="eventflow-dashboard-notice" role="status"><?php esc_html_e( 'Event created. You can edit it from your event list.', 'eventflow' ); ?></p><?php endif; ?>
			<?php if ( isset( $_GET['eventflow_event_error'] ) ) : ?><p class="eventflow-dashboard-notice" role="alert"><?php esc_html_e( 'The event could not be created. Check the event name and try again.', 'eventflow' ); ?></p><?php endif; ?>
			<?php if ( $events ) : ?><ul class="eventflow-dashboard-list"><?php foreach ( $events as $event ) : ?><li><div><strong><?php echo esc_html( get_the_title( $event ) ); ?></strong><span><?php echo esc_html( ucfirst( $event->post_status ) ); ?></span></div><a href="<?php echo esc_url( get_edit_post_link( $event->ID ) ); ?>"><?php esc_html_e( 'Edit event', 'eventflow' ); ?></a></li><?php endforeach; ?></ul><?php else : ?><p><?php esc_html_e( 'Your event list is empty. Create your first event below.', 'eventflow' ); ?></p><?php endif; ?>
			<form class="eventflow-dashboard-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<h3><?php esc_html_e( 'Create an event', 'eventflow' ); ?></h3>
				<input name="action" type="hidden" value="eventflow_create_event"><input name="redirect_to" type="hidden" value="<?php echo esc_url( get_permalink() ); ?>"><?php wp_nonce_field( 'eventflow_create_event', 'eventflow_create_event_nonce' ); ?>
				<label for="eventflow-dashboard-title"><?php esc_html_e( 'Event name', 'eventflow' ); ?></label><input id="eventflow-dashboard-title" maxlength="190" name="event_title" required>
				<label for="eventflow-dashboard-description"><?php esc_html_e( 'Description', 'eventflow' ); ?></label><textarea id="eventflow-dashboard-description" name="event_description" rows="5"></textarea>
				<div class="eventflow-dashboard-form__dates"><div><label for="eventflow-dashboard-start"><?php esc_html_e( 'Starts', 'eventflow' ); ?></label><input id="eventflow-dashboard-start" name="event_starts_at" type="datetime-local"></div><div><label for="eventflow-dashboard-end"><?php esc_html_e( 'Ends', 'eventflow' ); ?></label><input id="eventflow-dashboard-end" name="event_ends_at" type="datetime-local"></div></div>
				<label for="eventflow-dashboard-location"><?php esc_html_e( 'Location', 'eventflow' ); ?></label><input id="eventflow-dashboard-location" maxlength="190" name="event_location">
				<div class="eventflow-dashboard-form__dates"><div><label for="eventflow-dashboard-capacity"><?php esc_html_e( 'Ticket capacity', 'eventflow' ); ?></label><input id="eventflow-dashboard-capacity" min="0" name="event_capacity" type="number" value="0"></div><div><label for="eventflow-dashboard-price"><?php echo esc_html( sprintf( __( 'Price (%s)', 'eventflow' ), $currency ) ); ?></label><input id="eventflow-dashboard-price" max="1000000" min="0" name="event_price" step="0.01" type="number" value="0"></div></div>
				<button type="submit"><?php esc_html_e( 'Create event', 'eventflow' ); ?></button>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	public function create_event(): void {
		$redirect = isset( $_POST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_POST['redirect_to'] ), home_url( '/' ) ) : home_url( '/' );
		if ( ! is_user_logged_in() || ! current_user_can( 'publish_ef_events' ) || ! isset( $_POST['eventflow_create_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eventflow_create_event_nonce'] ) ), 'eventflow_create_event' ) ) {
			wp_die( esc_html__( 'You cannot create this event.', 'eventflow' ), esc_html__( 'Event unavailable', 'eventflow' ), array( 'response' => 403 ) );
		}
		$title = isset( $_POST['event_title'] ) ? sanitize_text_field( wp_unslash( $_POST['event_title'] ) ) : '';
		if ( '' === $title ) {
			wp_safe_redirect( add_query_arg( 'eventflow_event_error', 'title', $redirect ) );
			exit;
		}
		$event_id = wp_insert_post( array( 'post_type' => 'ef_event', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => isset( $_POST['event_description'] ) ? wp_kses_post( wp_unslash( $_POST['event_description'] ) ) : '', 'post_author' => get_current_user_id() ), true );
		if ( is_wp_error( $event_id ) ) {
			wp_safe_redirect( add_query_arg( 'eventflow_event_error', 'save', $redirect ) );
			exit;
		}
		$start_raw = isset( $_POST['event_starts_at'] ) ? sanitize_text_field( wp_unslash( $_POST['event_starts_at'] ) ) : '';
		$end_raw   = isset( $_POST['event_ends_at'] ) ? sanitize_text_field( wp_unslash( $_POST['event_ends_at'] ) ) : '';
		$start     = Event_Date::parse( $start_raw );
		$end       = Event_Date::parse( $end_raw );
		if ( $start && ( ! $end || $end > $start ) ) {
			update_post_meta( $event_id, '_eventflow_starts_at', $start_raw );
			if ( $end ) {
				update_post_meta( $event_id, '_eventflow_ends_at', $end_raw );
			}
		}
		if ( isset( $_POST['event_location'] ) ) {
			update_post_meta( $event_id, '_eventflow_location', sanitize_text_field( wp_unslash( $_POST['event_location'] ) ) );
		}
		$capacity = isset( $_POST['event_capacity'] ) ? absint( $_POST['event_capacity'] ) : 0;
		Bookings::update_inventory( (int) $event_id, $capacity );
		$price = isset( $_POST['event_price'] ) ? sanitize_text_field( wp_unslash( $_POST['event_price'] ) ) : '0';
		$settings = Stripe_Gateway::settings();
		$currency = isset( $settings['currency'] ) ? strtolower( $settings['currency'] ) : 'usd';
		if ( in_array( $currency, Settings::currencies(), true ) && preg_match( '/^\d{1,7}(?:\.\d{1,2})?$/', $price ) ) {
			update_post_meta( $event_id, '_eventflow_ticket_price_minor', (int) round( (float) $price * 100 ) );
			update_post_meta( $event_id, '_eventflow_ticket_currency', $currency );
		}
		wp_safe_redirect( add_query_arg( 'eventflow_event_created', '1', $redirect ) );
		exit;
	}
}
