<?php
/**
 * Plugin Name:       Amazon Product Boxes
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Free Amazon affiliate product boxes, comparison tables and bestseller lists. Works with Amazon's new Creators API, plus a no-API mode for sites that don't meet Amazon's sales requirement yet.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       amazon-product-boxes
 * Update URI:        https://github.com/jmsprsns/amazon-product-boxes
 *
 * @package Amazon_Product_Boxes
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$amazon_product_boxes_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'AMAZON_PRODUCT_BOXES_VERSION', $amazon_product_boxes_headers['version'] );
define( 'AMAZON_PRODUCT_BOXES_REPO', $amazon_product_boxes_headers['repo'] );
define( 'AMAZON_PRODUCT_BOXES_FILE', __FILE__ );
define( 'AMAZON_PRODUCT_BOXES_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMAZON_PRODUCT_BOXES_URL', plugin_dir_url( __FILE__ ) );

unset( $amazon_product_boxes_headers );

require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-icons.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-updater.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-menu.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-feedback.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-crypto.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-products.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-api.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-refresh.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-render.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-rest.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-admin.php';
require_once AMAZON_PRODUCT_BOXES_DIR . 'includes/class-amazon-product-boxes-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Amazon_Product_Boxes_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Amazon_Product_Boxes_Plugin
 */
function amazon_product_boxes_plugin() {
	return Amazon_Product_Boxes_Plugin::instance();
}
add_action( 'plugins_loaded', 'amazon_product_boxes_plugin' );

/**
 * Create the cache tables on activation (they're also checked on every load,
 * for sites that update by copying files).
 *
 * @return void
 */
function amazon_product_boxes_activate() {
	Amazon_Product_Boxes_Products::maybe_install();
}
register_activation_hook( AMAZON_PRODUCT_BOXES_FILE, 'amazon_product_boxes_activate' );

/**
 * Stop the background refresh when the plugin is deactivated.
 *
 * Settings and the product cache stay put (uninstall.php removes them).
 *
 * @return void
 */
function amazon_product_boxes_deactivate() {
	Amazon_Product_Boxes_Refresh::unschedule();
}
register_deactivation_hook( AMAZON_PRODUCT_BOXES_FILE, 'amazon_product_boxes_deactivate' );

if ( ! function_exists( 'amazon_product_box' ) ) {
	/**
	 * Print a product box from a theme template:
	 * <?php if ( function_exists( 'amazon_product_box' ) ) { amazon_product_box( 'B0CX23V2ZK' ); } ?>
	 *
	 * @param string $asin    ASIN.
	 * @param array  $args    Optional: title, description (one point per line), badge, buttonText, layout.
	 * @param bool   $display False to return the markup instead of printing it.
	 * @return string The markup.
	 */
	function amazon_product_box( $asin, array $args = array(), $display = true ) {
		$item = Amazon_Product_Boxes_Render::clean_item( array_merge( $args, array( 'asin' => $asin ) ) );
		$html = Amazon_Product_Boxes_Render::box( $item, array( 'layout' => isset( $args['layout'] ) ? (string) $args['layout'] : '' ) );

		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in the renderer.
		}

		return $html;
	}
}
