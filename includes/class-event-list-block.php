<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Event_List_Block {
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_shortcode( 'eventflow_events', array( $this, 'render_shortcode' ) );
	}

	public function register(): void {
		register_block_type( EVENTFLOW_PATH . 'blocks/event-list', array( 'render_callback' => array( $this, 'render_block' ) ) );
	}

	public function render_block( array $attributes ): string {
		$count    = isset( $attributes['count'] ) ? min( 24, max( 1, absint( $attributes['count'] ) ) ) : 6;
		$category = isset( $attributes['category'] ) ? sanitize_title( $attributes['category'] ) : '';
		$args     = array(
			'post_type'           => 'ef_event',
			'post_status'         => 'publish',
			'posts_per_page'      => $count,
			'ignore_sticky_posts' => true,
			'meta_key'            => '_eventflow_starts_at',
			'orderby'             => 'meta_value',
			'order'               => 'ASC',
		);
		if ( '' !== $category ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'ef_event_category', 'field' => 'slug', 'terms' => $category ) );
		}
		$query = new \WP_Query( $args );
		ob_start();
		?>
		<div class="eventflow-list-block">
			<?php if ( $query->have_posts() ) : ?>
				<?php while ( $query->have_posts() ) : $query->the_post(); ?>
					<?php
					$starts_at = get_post_meta( get_the_ID(), '_eventflow_starts_at', true );
					$location  = get_post_meta( get_the_ID(), '_eventflow_location', true );
					$date      = Event_Date::parse( $starts_at );
					?>
					<article class="eventflow-event-row">
						<div class="eventflow-event-row__date" aria-label="<?php echo esc_attr( $date ? wp_date( 'j F Y', $date->getTimestamp(), wp_timezone() ) : __( 'Date to be announced', 'eventflow' ) ); ?>"><strong><?php echo esc_html( $date ? wp_date( 'd', $date->getTimestamp(), wp_timezone() ) : '—' ); ?></strong><span><?php echo esc_html( $date ? wp_date( 'M', $date->getTimestamp(), wp_timezone() ) : '' ); ?></span></div>
						<div class="eventflow-event-row__main"><p><?php echo esc_html( $date ? wp_date( 'l, j F · g:i a', $date->getTimestamp(), wp_timezone() ) : __( 'Date to be announced', 'eventflow' ) ); ?></p><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php if ( $location ) : ?><span><?php echo esc_html( $location ); ?></span><?php endif; ?></div>
						<a class="eventflow-event-row__link" href="<?php the_permalink(); ?>"><span class="screen-reader-text"><?php echo esc_html( sprintf( __( 'View %s', 'eventflow' ), get_the_title() ) ); ?></span><span aria-hidden="true">↗</span></a>
					</article>
				<?php endwhile; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No events are published yet.', 'eventflow' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		wp_reset_postdata();
		return (string) ob_get_clean();
	}

	public function render_shortcode( array $attributes ): string {
		return $this->render_block( $attributes );
	}
}
