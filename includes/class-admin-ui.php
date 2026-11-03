<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Admin_UI {
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_page(): void {
		add_submenu_page( 'edit.php?post_type=ef_event', __( 'EventFlow overview', 'eventflow' ), __( 'Overview', 'eventflow' ), 'edit_ef_events', 'eventflow-overview', array( $this, 'render_page' ) );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'edit_ef_events' ) ) {
			return;
		}
		?>
		<div class="wrap eventflow-admin-wrap"><div id="eventflow-admin-root"></div></div>
		<?php
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'ef_event_page_eventflow-overview' !== $hook_suffix ) {
			return;
		}
		$asset_file = EVENTFLOW_PATH . 'assets/build/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array( 'dependencies' => array( 'wp-element' ), 'version' => EVENTFLOW_VERSION );
		wp_enqueue_script( 'eventflow-admin', EVENTFLOW_URL . 'assets/build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'eventflow-admin', EVENTFLOW_URL . 'assets/css/admin.css', array(), EVENTFLOW_VERSION );
	}
}
