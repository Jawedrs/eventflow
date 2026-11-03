<?php
/**
 * Plugin Name: EventFlow
 * Description: A fast, accessible event calendar and ticketing foundation.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: EventFlow
 * License: GPL-2.0-or-later
 * Text Domain: eventflow
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'EVENTFLOW_VERSION', '0.1.0' );
define( 'EVENTFLOW_FILE', __FILE__ );
define( 'EVENTFLOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'EVENTFLOW_URL', plugin_dir_url( __FILE__ ) );

require_once EVENTFLOW_PATH . 'includes/class-plugin.php';

register_activation_hook( EVENTFLOW_FILE, array( 'EventFlow\\Plugin', 'activate' ) );
EventFlow\Plugin::instance();
