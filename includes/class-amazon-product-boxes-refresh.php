<?php
/**
 * Background work, all on WP-Cron so visitors never wait for Amazon:
 *
 * - Hourly: refresh API data older than the chosen interval (in batches of
 *   10 ASINs, a second apart), refresh keyword lists, and check eligibility
 *   once a day while the site is in no-API mode, which is how it switches to
 *   API mode by itself once Amazon opens the API to the account.
 * - In no-API mode, check once a day that each product still exists on
 *   Amazon (its image on Amazon's CDN turns into a 1x1 placeholder when the
 *   ASIN is gone).
 * - When a post is saved, index the ASINs it uses and fetch new ones soon.
 *
 * @package Amazon_Product_Boxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron jobs and the post index.
 */
final class Amazon_Product_Boxes_Refresh {

	/**
	 * Hourly event.
	 */
	const HOOK = 'amazon_product_boxes_refresh';

	/**
	 * One-off event: fetch products that were just added.
	 */
	const FETCH_HOOK = 'amazon_product_boxes_fetch';

	/**
	 * API requests (of 10 ASINs each) per hourly run.
	 */
	const MAX_BATCHES = 10;

	/**
	 * Products checked per hourly run in no-API mode.
	 */
	const MAX_CHECKS = 25;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::FETCH_HOOK, array( __CLASS__, 'fetch_new' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Make sure the hourly event is scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove the events (on deactivation).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::FETCH_HOOK );
	}

	/**
	 * Fetch new products soon, if the site is in API mode.
	 *
	 * @return void
	 */
	public static function soon() {
		if ( amazon_product_boxes_plugin()->api_mode() && ! wp_next_scheduled( self::FETCH_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::FETCH_HOOK );
		}
	}

	/**
	 * Index a saved post's ASINs.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof WP_Post ) {
			return;
		}

		// Skip posts that can't contain boxes.
		if ( false === strpos( $post->post_content, 'amazon-product-boxes/' ) && false === strpos( $post->post_content, '[amazon_product_' ) && ! get_post_meta( $post_id, Amazon_Product_Boxes_Products::USAGE_META, true ) ) {
			return;
		}

		Amazon_Product_Boxes_Products::index_post( $post_id );
		delete_transient( 'apb_attention' );
		self::soon();
	}

	/**
	 * The hourly run.
	 *
	 * @return void
	 */
	public static function run() {
		$plugin = amazon_product_boxes_plugin();
		$status = Amazon_Product_Boxes_Plugin::status();

		if ( 'auto' === $plugin->get_setting( 'mode' ) && $plugin->has_credentials() && 'ok' !== $status['state'] ) {
			// Not eligible yet (or a past error): ask Amazon again once a day,
			// or hourly after a passing error.
			$wait = 'error' === $status['state'] ? HOUR_IN_SECONDS - 5 * MINUTE_IN_SECONDS : DAY_IN_SECONDS - 5 * MINUTE_IN_SECONDS;
			if ( $status['checked'] < time() - $wait ) {
				Amazon_Product_Boxes_Api::check_connection();
			}
		}

		if ( $plugin->api_mode() ) {
			self::refresh_api( self::MAX_BATCHES );
			self::refresh_searches( 3 );
		} else {
			self::check_without_api( self::MAX_CHECKS );
		}

		delete_transient( 'apb_attention' );
	}

	/**
	 * The one-off run for just-added products.
	 *
	 * @return void
	 */
	public static function fetch_new() {
		if ( amazon_product_boxes_plugin()->api_mode() ) {
			self::refresh_api( 3 );
			self::refresh_searches( 2 );
			delete_transient( 'apb_attention' );
		}
	}

	/**
	 * Refresh products due for new API data.
	 *
	 * @param int $batches Most requests to make.
	 * @return int Products updated.
	 */
	public static function refresh_api( $batches ) {
		$hours = (int) amazon_product_boxes_plugin()->get_setting( 'refresh_hours' );
		$asins = Amazon_Product_Boxes_Products::due_for_api( max( 1, $hours ), $batches * Amazon_Product_Boxes_Api::BATCH );
		$done  = 0;

		foreach ( array_chunk( $asins, Amazon_Product_Boxes_Api::BATCH ) as $chunk ) {
			$result = self::fetch( $chunk );

			if ( is_wp_error( $result ) ) {
				break;
			}

			$done += $result;
		}

		return $done;
	}

	/**
	 * Look up some ASINs and store what comes back.
	 *
	 * @param string[] $asins Up to 10 ASINs.
	 * @return int|WP_Error Products updated.
	 */
	public static function fetch( array $asins ) {
		$result = Amazon_Product_Boxes_Api::get_items( $asins );

		if ( is_wp_error( $result ) ) {
			Amazon_Product_Boxes_Api::record_error( $result );
			Amazon_Product_Boxes_Products::mark_checked( $asins );
			return $result;
		}

		foreach ( $result['items'] as $product ) {
			Amazon_Product_Boxes_Products::save_api( $product );
		}

		foreach ( $result['missing'] as $asin ) {
			Amazon_Product_Boxes_Products::mark( $asin, 'not_found' );
		}

		$rest = array_diff( $asins, array_keys( $result['items'] ), $result['missing'] );
		if ( $rest ) {
			Amazon_Product_Boxes_Products::mark_checked( $rest );
		}

		return count( $result['items'] );
	}

	/**
	 * Refresh keyword lists that are due.
	 *
	 * @param int $limit Most lists.
	 * @return void
	 */
	public static function refresh_searches( $limit ) {
		$hours = (int) amazon_product_boxes_plugin()->get_setting( 'refresh_hours' );

		foreach ( Amazon_Product_Boxes_Products::due_searches( max( 1, $hours ), $limit ) as $query ) {
			if ( is_wp_error( self::run_search( $query ) ) ) {
				break;
			}
		}
	}

	/**
	 * Run a keyword list's search and store the results.
	 *
	 * @param array $query keywords, index, count, sort.
	 * @return string[]|WP_Error ASINs in order.
	 */
	public static function run_search( array $query ) {
		$query = Amazon_Product_Boxes_Render::clean_search( $query );

		if ( '' === $query['keywords'] ) {
			return array();
		}

		$products = Amazon_Product_Boxes_Api::search_items( $query['keywords'], $query['index'], $query['count'], $query['sort'] );

		if ( is_wp_error( $products ) ) {
			Amazon_Product_Boxes_Api::record_error( $products );
			return $products;
		}

		$asins = array();
		foreach ( $products as $product ) {
			Amazon_Product_Boxes_Products::save_api( $product );
			$asins[] = $product['asin'];
		}

		Amazon_Product_Boxes_Products::save_search( $query, $asins );

		return $asins;
	}

	/**
	 * No-API mode: check that products still exist on Amazon.
	 *
	 * @param int $limit Most products.
	 * @return void
	 */
	public static function check_without_api( $limit ) {
		foreach ( Amazon_Product_Boxes_Products::due_for_check( $limit ) as $asin ) {
			$exists = self::image_exists( $asin );

			if ( null === $exists ) {
				break; // Amazon's CDN didn't answer: try the rest next hour.
			}

			Amazon_Product_Boxes_Products::mark( $asin, $exists ? 'ok' : 'not_found' );
		}
	}

	/**
	 * Whether Amazon's CDN has an image for an ASIN. For an ASIN Amazon
	 * doesn't know it serves a 43-byte transparent GIF instead of a JPEG.
	 *
	 * @param string $asin ASIN.
	 * @return bool|null Null when the CDN couldn't be reached.
	 */
	public static function image_exists( $asin ) {
		$response = wp_safe_remote_get(
			Amazon_Product_Boxes_Products::asin_image( $asin, 75 ),
			array(
				'timeout'             => 6,
				'redirection'         => 1,
				'limit_response_size' => 4096,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$body = (string) wp_remote_retrieve_body( $response );

		return ! ( 0 === strpos( $type, 'image/gif' ) && strlen( $body ) < 100 );
	}
}
