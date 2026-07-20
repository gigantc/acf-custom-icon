<?php
/**
 * Plugin Name: ACF Custom Icon
 * Description: Adds a Custom Icon field type to Advanced Custom Fields with an icon library manager.
 * Version: 1.5.2
 * Author: Dan Freeman
 *
 * @package ACF_Custom_Icon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACF_CUSTOM_ICON_VERSION', '1.5.2' );
define( 'ACF_CUSTOM_ICON_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACF_CUSTOM_ICON_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check if ACF is active and show admin notice if not.
 *
 * @return bool True if ACF is active, false otherwise.
 */
function acf_custom_icon_check_acf() {
	if ( ! class_exists( 'ACF' ) ) {
		add_action( 'admin_notices', 'acf_custom_icon_missing_acf_notice' );
		return false;
	}
	return true;
}

/**
 * Admin notice when ACF is not active.
 */
function acf_custom_icon_missing_acf_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'ACF Custom Icon requires Advanced Custom Fields (ACF) to be installed and activated.',
				'acf-custom-icon'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Load plugin includes and initialize components.
 */
function acf_custom_icon_load() {
	if ( ! acf_custom_icon_check_acf() ) {
		return;
	}

	require_once ACF_CUSTOM_ICON_DIR . 'includes/class-svg-sanitizer.php';
	require_once ACF_CUSTOM_ICON_DIR . 'includes/class-icon-storage.php';
	require_once ACF_CUSTOM_ICON_DIR . 'includes/class-acf-field-custom-icon.php';
	require_once ACF_CUSTOM_ICON_DIR . 'includes/class-admin-page.php';

	// Initialize the admin page.
	$admin = new ACF_Icon_Admin_Page();
	$admin->init();
}

add_action( 'plugins_loaded', 'acf_custom_icon_load' );

/**
 * Plugin activation: create uploads directory and security files.
 */
function acf_custom_icon_activate() {
	$upload_dir = wp_upload_dir();
	$icons_dir  = $upload_dir['basedir'] . '/acf-custom-icons';

	if ( ! file_exists( $icons_dir ) ) {
		wp_mkdir_p( $icons_dir );
	}

	// Add .htaccess to prevent directory listing.
	$htaccess_file = $icons_dir . '/.htaccess';
	if ( ! file_exists( $htaccess_file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$htaccess_file,
			"Options -Indexes\n"
		);
	}

	// Add index.php to prevent directory listing on servers without .htaccess.
	$index_file = $icons_dir . '/index.php';
	if ( ! file_exists( $index_file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $index_file, '<?php // Silence is golden.' );
	}
}

register_activation_hook( __FILE__, 'acf_custom_icon_activate' );
