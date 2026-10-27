<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * Boxes already in posts stay in the content as block comments and shortcodes
 * (they just stop showing anything).
 *
 * @package Amazon_Product_Boxes
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'amazon_product_boxes_settings' );
delete_option( 'amazon_product_boxes_status' );
delete_option( 'amazon_product_boxes_db' );

wp_clear_scheduled_hook( 'amazon_product_boxes_refresh' );
wp_clear_scheduled_hook( 'amazon_product_boxes_fetch' );

// The product cache and keyword lists.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}apb_products" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own table on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}apb_searches" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own table on uninstall.

// The index of which posts use which ASINs.
delete_post_meta_by_key( '_apb_asin' );

// Access token, rate-limit counters and notices.
$amazon_product_boxes_like = array(
	$wpdb->esc_like( '_transient_apb_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_apb_' ) . '%',
);
foreach ( $amazon_product_boxes_like as $amazon_product_boxes_pattern ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $amazon_product_boxes_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup on uninstall.
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-amazon-product-boxes' );
