<?php
defined( 'ABSPATH' ) || exit;
get_header();

$query_text = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$selected_category = isset( $_GET['category'] ) ? sanitize_title( wp_unslash( $_GET['category'] ) ) : '';
$categories = get_terms( array( 'taxonomy' => 'ef_event_category', 'hide_empty' => true ) );
if ( is_wp_error( $categories ) ) {
	$categories = array();
}
?>
<main class="eventflow-archive">
	<div class="eventflow-archive__inner">
		<header class="eventflow-archive__header">
			<p class="eventflow-archive__intro"><?php esc_html_e( 'Make plans that matter.', 'eventflow' ); ?></p>
			<h1><?php esc_html_e( 'Find your next roomful of people.', 'eventflow' ); ?></h1>
	</header>

		<form class="eventflow-filters" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'ef_event' ) ); ?>">
			<label class="screen-reader-text" for="eventflow-search"><?php esc_html_e( 'Search events', 'eventflow' ); ?></label>
			<input id="eventflow-search" name="q" type="search" value="<?php echo esc_attr( $query_text ); ?>" placeholder="<?php esc_attr_e( 'Search by name', 'eventflow' ); ?>">
			<label class="screen-reader-text" for="eventflow-category"><?php esc_html_e( 'Event category', 'eventflow' ); ?></label>
			<select id="eventflow-category" name="category">
				<option value=""><?php esc_html_e( 'Every kind of event', 'eventflow' ); ?></option>
				<?php foreach ( $categories as $category ) : ?>
					<option value="<?php echo esc_attr( $category->slug ); ?>" <?php selected( $selected_category, $category->slug ); ?>><?php echo esc_html( $category->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit"><?php esc_html_e( 'Show events', 'eventflow' ); ?></button>
		</form>

		<?php if ( have_posts() ) : ?>
			<section class="eventflow-event-list" aria-label="<?php esc_attr_e( 'Events', 'eventflow' ); ?>">
				<?php while ( have_posts() ) : the_post(); ?>
					<?php
					$starts_at = get_post_meta( get_the_ID(), '_eventflow_starts_at', true );
					$location  = get_post_meta( get_the_ID(), '_eventflow_location', true );
					$date      = EventFlow\Event_Date::parse( $starts_at );
					?>
					<article class="eventflow-event-row">
						<div class="eventflow-event-row__date" aria-label="<?php echo esc_attr( $date ? wp_date( 'j F Y', $date->getTimestamp(), wp_timezone() ) : __( 'Date to be announced', 'eventflow' ) ); ?>">
							<strong><?php echo esc_html( $date ? wp_date( 'd', $date->getTimestamp(), wp_timezone() ) : '—' ); ?></strong><span><?php echo esc_html( $date ? wp_date( 'M', $date->getTimestamp(), wp_timezone() ) : '' ); ?></span>
						</div>
						<div class="eventflow-event-row__main">
							<p><?php echo esc_html( $date ? wp_date( 'l, j F · g:i a', $date->getTimestamp(), wp_timezone() ) : __( 'Date to be announced', 'eventflow' ) ); ?></p>
							<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
							<?php if ( $location ) : ?><span><?php echo esc_html( $location ); ?></span><?php endif; ?>
						</div>
						<a class="eventflow-event-row__link" href="<?php the_permalink(); ?>"><span class="screen-reader-text"><?php echo esc_html( sprintf( __( 'View %s', 'eventflow' ), get_the_title() ) ); ?></span><span aria-hidden="true">↗</span></a>
					</article>
				<?php endwhile; ?>
			</section>
			<?php the_posts_pagination( array( 'mid_size' => 1, 'prev_text' => __( 'Newer events', 'eventflow' ), 'next_text' => __( 'More events', 'eventflow' ) ) ); ?>
		<?php else : ?>
			<section class="eventflow-empty"><h2><?php esc_html_e( 'Nothing matches that search.', 'eventflow' ); ?></h2><p><?php esc_html_e( 'Try a different name or browse every event.', 'eventflow' ); ?></p><a href="<?php echo esc_url( get_post_type_archive_link( 'ef_event' ) ); ?>"><?php esc_html_e( 'Browse all events', 'eventflow' ); ?></a></section>
		<?php endif; ?>
	</div>
</main>
<?php get_footer();
