<?php
/**
 * Plugin Name: Bricks Code Studio
 * Description: A docked SCSS, CSS, JavaScript, and structure editor for Bricks Builder 2.4+.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Mauricio Correa
 * Text Domain: bricks-code-studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BCS_VERSION', '0.2.0' );
define( 'BCS_FILE', __FILE__ );
define( 'BCS_PATH', plugin_dir_path( __FILE__ ) );
define( 'BCS_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( BCS_PATH . 'vendor/autoload.php' ) ) {
	require_once BCS_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'BricksCodeStudio\\';
			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$file     = BCS_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! BricksCodeStudio\Support::is_supported() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'Bricks Code Studio requires an active Bricks theme version 2.4+ and WordPress 6.9+.', 'bricks-code-studio' ),
				esc_html__( 'Plugin requirements not met', 'bricks-code-studio' ),
				[ 'back_link' => true ]
			);
		}
	}
);

add_action(
	'after_setup_theme',
	static function () {
		( new BricksCodeStudio\Plugin() )->boot();
	},
	20
);
