<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Public_Pages {
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'template_include', array( $this, 'event_template' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_event_archive' ) );
	}

	public function enqueue_assets(): void {
		$has_event_block = is_singular() && has_block( 'eventflow/event-list', get_queried_object_id() );
		$has_event_shortcode = is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'eventflow_events' );
		$has_booking_shortcode = is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'eventflow_booking_lookup' );
		$has_dashboard_shortcode = is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'eventflow_organizer_dashboard' );
		if ( is_post_type_archive( 'ef_event' ) || is_singular( 'ef_event' ) || $has_event_block || $has_event_shortcode || $has_booking_shortcode || $has_dashboard_shortcode ) {
			wp_enqueue_style( 'eventflow-public', EVENTFLOW_URL . 'assets/css/public.css', array(), EVENTFLOW_VERSION );
		}
	}

	public function event_template( string $template ): string {
		if ( is_singular( 'ef_event' ) ) {
			$candidate = EVENTFLOW_PATH . 'templates/single-ef_event.php';
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}
		if ( is_post_type_archive( 'ef_event' ) ) {
			$candidate = EVENTFLOW_PATH . 'templates/archive-ef_event.php';
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}
		return $template;
	}

	public function filter_event_archive( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'ef_event' ) ) {
			return;
		}
		$query->set( 'posts_per_page', 12 );
		$query->set( 'meta_key', '_eventflow_starts_at' );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', 'ASC' );

		if ( isset( $_GET['category'] ) ) {
			$category = sanitize_title( wp_unslash( $_GET['category'] ) );
			if ( '' !== $category ) {
				$query->set( 'tax_query', array( array( 'taxonomy' => 'ef_event_category', 'field' => 'slug', 'terms' => $category ) ) );
			}
		}
		if ( isset( $_GET['q'] ) ) {
			$query->set( 's', sanitize_text_field( wp_unslash( $_GET['q'] ) ) );
		}
	}
}
