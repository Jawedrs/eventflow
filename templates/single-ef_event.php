<?php
defined( 'ABSPATH' ) || exit;
get_header();
the_post();
$starts_at = get_post_meta( get_the_ID(), '_eventflow_starts_at', true );
$ends_at   = get_post_meta( get_the_ID(), '_eventflow_ends_at', true );
$location  = get_post_meta( get_the_ID(), '_eventflow_location', true );
$venue_id  = (int) get_post_meta( get_the_ID(), '_eventflow_venue_id', true );
$organizer_id = (int) get_post_meta( get_the_ID(), '_eventflow_organizer_id', true );
$venue_name = $venue_id ? get_the_title( $venue_id ) : '';
$organizer_name = $organizer_id ? get_the_title( $organizer_id ) : '';
$start     = EventFlow\Event_Date::display( $starts_at, 'D, j M · g:i a', __( 'Date to be announced', 'eventflow' ) );
$capacity  = (int) get_post_meta( get_the_ID(), '_eventflow_ticket_capacity', true );
$remaining = (int) get_post_meta( get_the_ID(), '_eventflow_remaining_capacity', true );
$price_minor = (int) get_post_meta( get_the_ID(), '_eventflow_ticket_price_minor', true );
$ticket_currency = (string) get_post_meta( get_the_ID(), '_eventflow_ticket_currency', true );
$site_settings = EventFlow\Stripe_Gateway::settings();
if ( '' === $ticket_currency ) {
	$ticket_currency = isset( $site_settings['currency'] ) ? $site_settings['currency'] : 'usd';
}
$notice    = isset( $_GET['eventflow_booking'] ) ? sanitize_key( wp_unslash( $_GET['eventflow_booking'] ) ) : '';
$reference = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';
$start_date = EventFlow\Event_Date::parse( $starts_at );
$end_date   = EventFlow\Event_Date::parse( $ends_at );
$schema     = array(
	'@context'          => 'https://schema.org',
	'@type'             => 'Event',
	'name'              => get_the_title(),
	'description'       => wp_trim_words( wp_strip_all_tags( get_the_content() ), 45 ),
	'eventStatus'       => 'https://schema.org/EventScheduled',
	'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
	'url'               => get_permalink(),
);
if ( $start_date ) {
	$schema['startDate'] = $start_date->format( DATE_W3C );
}
if ( $end_date ) {
	$schema['endDate'] = $end_date->format( DATE_W3C );
}
if ( $location || $venue_name ) {
	$schema['location'] = array( '@type' => 'Place', 'name' => $venue_name ? $venue_name : $location );
}
if ( $organizer_name ) {
	$schema['organizer'] = array( '@type' => 'Organization', 'name' => $organizer_name );
}
if ( $price_minor > 0 ) {
	$schema['offers'] = array( '@type' => 'Offer', 'price' => number_format( $price_minor / 100, 2, '.', '' ), 'priceCurrency' => strtoupper( $ticket_currency ), 'availability' => $remaining > 0 ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut', 'url' => get_permalink() );
}
if ( has_post_thumbnail() ) {
	$schema['image'] = get_the_post_thumbnail_url( get_the_ID(), 'full' );
}
?>
<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoding escapes markup-sensitive characters. ?></script>
<main class="eventflow-event">
	<article class="eventflow-shell">
		<header class="eventflow-hero">
			<div class="eventflow-date" aria-label="<?php echo esc_attr( $start ); ?>"><?php echo esc_html( EventFlow\Event_Date::display( $starts_at, 'd', '—' ) ); ?><span><?php echo esc_html( EventFlow\Event_Date::display( $starts_at, 'M' ) ); ?></span></div>
			<div><p class="eventflow-kicker"><?php echo esc_html( $venue_name ? $venue_name : ( $location ? $location : __( 'EventFlow event', 'eventflow' ) ) ); ?></p><h1><?php the_title(); ?></h1><p class="eventflow-time"><?php echo esc_html( $start ); ?><?php echo $ends_at ? esc_html( ' – ' . EventFlow\Event_Date::display( $ends_at, 'g:i a' ) ) : ''; ?></p><?php if ( $organizer_name ) : ?><p class="eventflow-organizer"><?php echo esc_html( sprintf( __( 'Hosted by %s', 'eventflow' ), $organizer_name ) ); ?></p><?php endif; ?></div>
		</header>
		<?php if ( has_post_thumbnail() ) : ?><div class="eventflow-image"><?php the_post_thumbnail( 'large' ); ?></div><?php endif; ?>
		<div class="eventflow-content"><?php the_content(); ?></div>
		<aside class="eventflow-action">
			<?php if ( 'confirmed' === $notice ) : ?>
				<h2><?php esc_html_e( 'You’re booked.', 'eventflow' ); ?></h2><p><?php esc_html_e( 'Your confirmation is on its way. Keep this reference for check-in:', 'eventflow' ); ?></p><strong class="eventflow-reference"><?php echo esc_html( $reference ); ?></strong>
			<?php elseif ( 'pending' === $notice ) : ?>
				<h2><?php esc_html_e( 'Payment processing', 'eventflow' ); ?></h2><p><?php esc_html_e( 'Stripe is confirming your payment. Your booking confirmation will arrive by email.', 'eventflow' ); ?></p><strong class="eventflow-reference"><?php echo esc_html( $reference ); ?></strong>
			<?php elseif ( 'payment_cancelled' === $notice ) : ?>
				<p class="eventflow-notice" role="status"><?php esc_html_e( 'Checkout was closed. Your seat is held briefly while the payment session expires.', 'eventflow' ); ?></p>
			<?php elseif ( in_array( $notice, array( 'soldout', 'details', 'failed', 'unavailable', 'duplicate', 'payment_not_ready' ), true ) ) : ?>
				<?php
				$notice_message = match ( $notice ) {
					'soldout' => __( 'All tickets have been claimed.', 'eventflow' ),
					'duplicate' => __( 'A booking already exists for this email address. Use booking lookup if you need to manage it.', 'eventflow' ),
					'payment_not_ready' => __( 'Paid booking is not configured for this event. Please contact the organizer.', 'eventflow' ),
					default => __( 'We could not complete that booking. Check your details and try again.', 'eventflow' ),
				};
				?>
				<p class="eventflow-notice" role="alert"><?php echo esc_html( $notice_message ); ?></p>
			<?php endif; ?>
			<?php if ( $capacity > 0 && $remaining > 0 && ( 0 === $price_minor || EventFlow\Stripe_Gateway::is_configured() ) ) : ?>
				<h2><?php esc_html_e( 'Reserve your place', 'eventflow' ); ?></h2><p><?php echo esc_html( sprintf( _n( '%d ticket remains.', '%d tickets remain.', $remaining, 'eventflow' ), $remaining ) ); ?><?php if ( $price_minor > 0 ) : ?><br><strong><?php echo esc_html( strtoupper( $ticket_currency ) . ' ' . number_format( $price_minor / 100, 2 ) ); ?></strong><?php else : ?><br><strong><?php esc_html_e( 'Free', 'eventflow' ); ?></strong><?php endif; ?></p>
				<form class="eventflow-booking-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="eventflow_reserve"><input type="hidden" name="event_id" value="<?php echo esc_attr( get_the_ID() ); ?>"><?php wp_nonce_field( 'eventflow_reserve_' . get_the_ID(), 'eventflow_booking_nonce' ); ?>
					<label for="eventflow-attendee-name"><?php esc_html_e( 'Your name', 'eventflow' ); ?></label><input id="eventflow-attendee-name" name="attendee_name" required type="text" autocomplete="name">
					<label for="eventflow-attendee-email"><?php esc_html_e( 'Email address', 'eventflow' ); ?></label><input id="eventflow-attendee-email" name="attendee_email" required type="email" autocomplete="email">
					<button type="submit"><?php echo esc_html( $price_minor > 0 ? __( 'Continue to payment', 'eventflow' ) : __( 'Reserve free ticket', 'eventflow' ) ); ?></button>
				</form>
			<?php elseif ( $capacity > 0 && $remaining > 0 && $price_minor > 0 ) : ?>
				<h2><?php esc_html_e( 'Paid booking unavailable', 'eventflow' ); ?></h2><p><?php esc_html_e( 'The event organizer has not finished payment setup.', 'eventflow' ); ?></p>
			<?php elseif ( $capacity > 0 ) : ?>
				<h2><?php esc_html_e( 'Fully booked', 'eventflow' ); ?></h2><p><?php esc_html_e( 'All free tickets have been claimed.', 'eventflow' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Tickets are coming soon.', 'eventflow' ); ?></p>
			<?php endif; ?>
		</aside>
	</article>
</main>
<?php get_footer();
