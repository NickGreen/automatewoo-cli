<?php
/**
 * Plugin Name: AutomateWoo CLI
 * Description: WP-CLI commands for running AutomateWoo manual workflows
 * Version: 1.0.0
 * Author: Nick Green
 *
 * @package AutomateWoo_CLI
 */

namespace AutomateWoo_CLI;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

define( 'AUTOMATEWOO_CLI_VERSION', '1.0.0' );
define( 'AUTOMATEWOO_CLI_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );

/**
 * Initialize the CLI command after plugins are loaded.
 */
add_action( 'plugins_loaded', function() {
	if ( ! class_exists( 'AutomateWoo' ) ) {
		return;
	}

	require_once AUTOMATEWOO_CLI_PATH . '/includes/class-aw-cli-command.php';

	\WP_CLI::add_command( 'aw-cli', __NAMESPACE__ . '\AW_CLI_Command' );
}, 20 );
