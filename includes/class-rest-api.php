<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Rest_API {
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route( 'eventflow/v1', '/events', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_events' ),
			'permission_callback' => '__return_true',
			'args'                => array( 'page' => array( 'sanitize_callback' => 'absint', 'default' => 1 ) ),
		) );
	}

	public function get_events( \WP_REST_Request $request ): \WP_REST_Response {
		$query = new \WP_Query( array( 'post_type' => 'ef_event', 'post_status' => 'publish', 'posts_per_page' => 12, 'paged' => max( 1, (int) $request['page'] ) ) );
		$events = array_map( static function ( \WP_Post $post ): array {
			return array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'url' => get_permalink( $post ), 'startsAt' => get_post_meta( $post->ID, '_eventflow_starts_at', true ), 'location' => get_post_meta( $post->ID, '_eventflow_location', true ) );
		}, $query->posts );
		return new \WP_REST_Response( array( 'events' => $events, 'totalPages' => (int) $query->max_num_pages ) );
	}
}
