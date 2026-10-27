<?php
/**
 * REST endpoints for the block editor: look up an ASIN or Amazon link, and
 * search Amazon (API mode).
 *
 * Only users who can edit posts may call them (the REST cookie nonce covers
 * CSRF), each user gets a small hourly allowance so nobody can burn through
 * the site's Amazon quota, and Amazon is only ever asked about what the
 * editor typed: links are reduced to an ASIN first, and the only outside
 * requests are to Amazon's API, Amazon's image CDN and Amazon's own short
 * link hosts.
 *
 * @package Amazon_Product_Boxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor REST API.
 */
final class Amazon_Product_Boxes_Rest {

	/**
	 * Route namespace.
	 */
	const NS = 'amazon-product-boxes/v1';

	/**
	 * Lookups per user per hour.
	 */
	const LOOKUP_LIMIT = 120;

	/**
	 * Searches per user per hour.
	 */
	const SEARCH_LIMIT = 60;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'lookup' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'q' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'keywords' => array(
						'type'     => 'string',
						'required' => true,
					),
					'index'    => array(
						'type'    => 'string',
						'default' => 'All',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public static function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Look up what the editor pasted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function lookup( WP_REST_Request $request ) {
		$limited = self::limit( 'lookup', self::LOOKUP_LIMIT );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$input = substr( trim( (string) $request->get_param( 'q' ) ), 0, 500 );
		$asin  = Amazon_Product_Boxes_Products::parse_input( $input );

		if ( '' === $asin ) {
			return new WP_Error( 'apb_no_asin', __( 'That isn\'t an ASIN or an Amazon product link. An ASIN is 10 letters and numbers, like B0CX23V2ZK; it\'s in the product\'s URL after /dp/.', 'amazon-product-boxes' ), array( 'status' => 400 ) );
		}

		$plugin  = amazon_product_boxes_plugin();
		$product = null;
		$status  = 'pending';
		$notice  = '';

		if ( $plugin->api_mode() ) {
			$rows = Amazon_Product_Boxes_Products::get_many( array( $asin ) );
			$row  = isset( $rows[ $asin ] ) ? $rows[ $asin ] : null;

			if ( ! Amazon_Product_Boxes_Products::is_fresh( $row ) ) {
				$fetched = Amazon_Product_Boxes_Refresh::fetch( array( $asin ) );
				if ( is_wp_error( $fetched ) ) {
					$notice = $fetched->get_error_message();
				}
				$rows = Amazon_Product_Boxes_Products::get_many( array( $asin ) );
				$row  = isset( $rows[ $asin ] ) ? $rows[ $asin ] : null;
			}

			if ( $row ) {
				$status  = $row['status'];
				$product = Amazon_Product_Boxes_Products::is_fresh( $row ) ? $row['data'] : null;
			}
		} else {
			// Without the API, Amazon's image CDN is the only thing that can
			// tell us the ASIN exists, which catches typos.
			$exists = Amazon_Product_Boxes_Refresh::image_exists( $asin );
			if ( false === $exists ) {
				$status = 'not_found';
			} elseif ( true === $exists ) {
				$status = 'ok';
			}
			Amazon_Product_Boxes_Products::touch( array( $asin ) );
			if ( null !== $exists ) {
				Amazon_Product_Boxes_Products::mark( $asin, $status );
			}
		}

		if ( 'not_found' === $status ) {
			$notice = __( 'Amazon doesn\'t seem to have a product with this ASIN in your store. Check it, or the box will send visitors to an Amazon search instead.', 'amazon-product-boxes' );
		} elseif ( 'unavailable' === $status ) {
			$notice = __( 'This product is currently unavailable on Amazon.', 'amazon-product-boxes' );
		}

		return rest_ensure_response(
			array(
				'asin'    => $asin,
				'api'     => $plugin->api_mode(),
				'status'  => $status,
				'notice'  => $notice,
				'image'   => Amazon_Product_Boxes_Products::asin_image( $asin, 300 ),
				'product' => $product ? self::summary( $product ) : null,
			)
		);
	}

	/**
	 * Search Amazon.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function search( WP_REST_Request $request ) {
		if ( ! amazon_product_boxes_plugin()->api_mode() ) {
			return new WP_Error( 'apb_no_api', __( 'Searching Amazon needs API mode. Paste an ASIN or Amazon link instead.', 'amazon-product-boxes' ), array( 'status' => 400 ) );
		}

		$keywords = trim( sanitize_text_field( (string) $request->get_param( 'keywords' ) ) );
		if ( '' === $keywords ) {
			return new WP_Error( 'apb_no_keywords', __( 'Type something to search for.', 'amazon-product-boxes' ), array( 'status' => 400 ) );
		}

		$limited = self::limit( 'search', self::SEARCH_LIMIT );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$query    = Amazon_Product_Boxes_Render::clean_search(
			array(
				'keywords' => $keywords,
				'index'    => (string) $request->get_param( 'index' ),
				'count'    => 10,
			)
		);
		$products = Amazon_Product_Boxes_Api::search_items( $query['keywords'], $query['index'], 10, '', max( 1, min( 10, (int) $request->get_param( 'page' ) ) ) );

		if ( is_wp_error( $products ) ) {
			Amazon_Product_Boxes_Api::record_error( $products );
			return new WP_Error( $products->get_error_code(), $products->get_error_message(), array( 'status' => 502 ) );
		}

		$out = array();
		foreach ( $products as $product ) {
			Amazon_Product_Boxes_Products::save_api( $product );
			$out[] = self::summary( $product );
		}

		return rest_ensure_response( array( 'results' => $out ) );
	}

	/**
	 * What the editor needs to know about a product.
	 *
	 * @param array $product Normalized product.
	 * @return array
	 */
	private static function summary( array $product ) {
		return array(
			'asin'         => $product['asin'],
			'title'        => $product['title'],
			'image'        => isset( $product['image']['url'] ) ? $product['image']['url'] : '',
			'price'        => $product['price'],
			'prime'        => ! empty( $product['prime'] ),
			'rating'       => $product['rating'],
			'reviews'      => $product['reviews'],
			'features'     => array_slice( (array) $product['features'], 0, 5 ),
			'availability' => $product['availability'],
			'unavailable'  => in_array( $product['availability'], array( 'OUT_OF_STOCK', 'UNAVAILABLE', 'NO_OFFERS' ), true ),
		);
	}

	/**
	 * Whether the editor's preview may ask Amazon for fresh data: it shares
	 * the lookup allowance, so previews can't be used to spend the site's
	 * Amazon quota either.
	 *
	 * @return bool
	 */
	public static function allow_preview_fetch() {
		return true === self::limit( 'lookup', self::LOOKUP_LIMIT );
	}

	/**
	 * Count a call against the user's hourly allowance.
	 *
	 * @param string $what  lookup or search.
	 * @param int    $limit Calls per hour.
	 * @return true|WP_Error
	 */
	private static function limit( $what, $limit ) {
		$key   = 'apb_rl_' . $what . '_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error( 'apb_rate_limited', __( 'That\'s a lot of lookups in an hour. Please wait a few minutes and try again.', 'amazon-product-boxes' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}
}
