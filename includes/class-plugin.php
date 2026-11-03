<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

require_once EVENTFLOW_PATH . 'includes/class-content-types.php';
require_once EVENTFLOW_PATH . 'includes/class-public-pages.php';
require_once EVENTFLOW_PATH . 'includes/class-rest-api.php';
require_once EVENTFLOW_PATH . 'includes/class-bookings.php';
require_once EVENTFLOW_PATH . 'includes/class-event-list-block.php';
require_once EVENTFLOW_PATH . 'includes/class-admin-ui.php';
require_once EVENTFLOW_PATH . 'includes/class-privacy.php';
require_once EVENTFLOW_PATH . 'includes/class-event-date.php';
require_once EVENTFLOW_PATH . 'includes/class-settings.php';
require_once EVENTFLOW_PATH . 'includes/class-stripe-gateway.php';
require_once EVENTFLOW_PATH . 'includes/class-organizer-dashboard.php';

final class Plugin {
	private const ROLE_VERSION = '2';
	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate(): void {
		Content_Types::register();
		Bookings::install();
		self::add_roles_and_capabilities();
		flush_rewrite_rules();
	}

	public static function add_roles_and_capabilities(): void {
		if ( self::ROLE_VERSION === get_option( 'eventflow_roles_version' ) ) {
			return;
		}
		$role = get_role( 'eventflow_organizer' );
		if ( ! $role ) {
			$role = add_role( 'eventflow_organizer', __( 'Event Organizer', 'eventflow' ), array( 'read' => true, 'upload_files' => true ) );
		}
		$organizer_caps = array(
			'edit_ef_events', 'edit_published_ef_events', 'publish_ef_events', 'read_private_ef_events', 'delete_ef_events', 'delete_published_ef_events',
			'edit_ef_venues', 'edit_published_ef_venues', 'publish_ef_venues', 'delete_ef_venues', 'delete_published_ef_venues',
			'edit_ef_organizers', 'edit_published_ef_organizers', 'publish_ef_organizers', 'delete_ef_organizers', 'delete_published_ef_organizers',
		);
		if ( $role ) {
			foreach ( $organizer_caps as $capability ) {
				$role->add_cap( $capability );
			}
		}
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator_caps = array( 'edit_others_ef_events', 'delete_others_ef_events', 'edit_private_ef_events', 'delete_private_ef_events', 'edit_others_ef_venues', 'delete_others_ef_venues', 'edit_private_ef_venues', 'delete_private_ef_venues', 'edit_others_ef_organizers', 'delete_others_ef_organizers', 'edit_private_ef_organizers', 'delete_private_ef_organizers' );
			foreach ( array_merge( $organizer_caps, $administrator_caps ) as $capability ) {
				$administrator->add_cap( $capability );
			}
		}
		update_option( 'eventflow_roles_version', self::ROLE_VERSION, false );
	}

	private function __construct() {
		add_action( 'init', array( self::class, 'add_roles_and_capabilities' ), 0 );
		new Content_Types();
		new Public_Pages();
		new Rest_API();
		new Bookings();
		new Event_List_Block();
		new Admin_UI();
		new Privacy();
		new Settings();
		new Stripe_Gateway();
		new Organizer_Dashboard();
	}
}
