<?php
/**
 * Amazon Creators API client (the successor to Product Advertising API 5.0).
 *
 * Auth is OAuth 2.0 client credentials: the Credential ID and Secret are
 * swapped for a bearer token (valid for an hour, cached encrypted) at the
 * token endpoint for the credential's version. Catalog calls are JSON POSTs
 * to https://creatorsapi.amazon/catalog/v1/{operation} with the store in an
 * x-marketplace header. Only this class talks to Amazon's API, only from the
 * server, and never while a visitor's page is being built.
 *
 * @package Amazon_Product_Boxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creators API client.
 */
final class Amazon_Product_Boxes_Api {

	/**
	 * Catalog API base URL (all regions).
	 */
	const BASE = 'https://creatorsapi.amazon/catalog/v1/';

	/**
	 * Most ASINs getItems takes in one request.
	 */
	const BATCH = 10;

	/**
	 * Transient holding the encrypted access token.
	 */
	const TOKEN_KEY = 'apb_token';

	/**
	 * Data each product box needs. Asking for less keeps responses small.
	 */
	const RESOURCES = array(
		'images.primary.large',
		'itemInfo.title',
		'itemInfo.features',
		'itemInfo.byLineInfo',
		'offersV2.listings.price',
		'offersV2.listings.availability',
		'offersV2.listings.merchantInfo',
		'offersV2.listings.dealDetails',
		'offersV2.listings.isBuyBoxWinner',
		'customerReviews.count',
		'customerReviews.starRating',
	);

	/**
	 * Token endpoint for each credential version. 3.x is Login with Amazon
	 * (JSON body), 2.x is the older Cognito setup (form body, and the
	 * version goes in the Authorization header).
	 *
	 * @return array<string,string>
	 */
	public static function token_endpoints() {
		return array(
			'3.1' => 'https://api.amazon.com/auth/o2/token',
			'3.2' => 'https://api.amazon.co.uk/auth/o2/token',
			'3.3' => 'https://api.amazon.co.jp/auth/o2/token',
			'2.1' => 'https://creatorsapi.auth.us-east-1.amazoncognito.com/oauth2/token',
			'2.2' => 'https://creatorsapi.auth.eu-south-2.amazoncognito.com/oauth2/token',
			'2.3' => 'https://creatorsapi.auth.us-west-2.amazoncognito.com/oauth2/token',
		);
	}

	/**
	 * Look up to 10 ASINs.
	 *
	 * @param string[] $asins ASINs (already valid).
	 * @return array{items:array<string,array>,missing:string[]}|WP_Error Items by ASIN, and ASINs Amazon said don't exist or can't be shown.
	 */
	public static function get_items( array $asins ) {
		$asins = array_slice( array_values( array_unique( $asins ) ), 0, self::BATCH );

		$response = self::call(
			'getItems',
			array(
				'itemIds'    => $asins,
				'itemIdType' => 'ASIN',
				'resources'  => self::RESOURCES,
			)
		);

		if ( is_wp_error( $response ) ) {
			// A request where no ASIN exists comes back as a 404.
			if ( 'apb_not_found' === $response->get_error_code() ) {
				return array(
					'items'   => array(),
					'missing' => $asins,
				);
			}

			return $response;
		}

		$items = array();
		$list  = isset( $response['itemsResult']['items'] ) && is_array( $response['itemsResult']['items'] ) ? $response['itemsResult']['items'] : array();

		foreach ( $list as $item ) {
			$product = self::normalize( $item );
			if ( $product ) {
				$items[ $product['asin'] ] = $product;
			}
		}

		// Partial errors ("ItemNotAccessible", "InvalidParameterValue") name
		// the ASIN in their message.
		$missing = array();
		$errors  = isset( $response['errors'] ) && is_array( $response['errors'] ) ? $response['errors'] : array();

		foreach ( $errors as $error ) {
			$message = is_array( $error ) && isset( $error['message'] ) ? (string) $error['message'] : '';
			foreach ( $asins as $asin ) {
				if ( ! isset( $items[ $asin ] ) && false !== strpos( strtoupper( $message ), $asin ) ) {
					$missing[] = $asin;
				}
			}
		}

		return array(
			'items'   => $items,
			'missing' => array_values( array_unique( $missing ) ),
		);
	}

	/**
	 * Search Amazon.
	 *
	 * @param string $keywords Search words.
	 * @param string $index    Search index (category), e.g. All or Electronics.
	 * @param int    $count    Results (1-10).
	 * @param string $sort     Sort order, or '' for Amazon's default.
	 * @param int    $page     Results page (1-10).
	 * @return array<int,array>|WP_Error Products in Amazon's order.
	 */
	public static function search_items( $keywords, $index = 'All', $count = 10, $sort = '', $page = 1 ) {
		$body = array(
			'keywords'    => substr( (string) $keywords, 0, 200 ),
			'searchIndex' => '' !== $index ? $index : 'All',
			'itemCount'   => max( 1, min( 10, (int) $count ) ),
			'itemPage'    => max( 1, min( 10, (int) $page ) ),
			'resources'   => self::RESOURCES,
		);

		if ( in_array( $sort, self::sorts(), true ) ) {
			$body['sortBy'] = $sort;
		}

		$response = self::call( 'searchItems', $body );

		if ( is_wp_error( $response ) ) {
			return 'apb_not_found' === $response->get_error_code() ? array() : $response;
		}

		$out  = array();
		$list = isset( $response['searchResult']['items'] ) && is_array( $response['searchResult']['items'] ) ? $response['searchResult']['items'] : array();

		foreach ( $list as $item ) {
			$product = self::normalize( $item );
			if ( $product ) {
				$out[] = $product;
			}
		}

		return $out;
	}

	/**
	 * Sort orders searchItems accepts.
	 *
	 * @return string[]
	 */
	public static function sorts() {
		return array( 'Featured', 'AvgCustomerReviews', 'NewestArrivals', 'Price:LowToHigh', 'Price:HighToLow', 'Relevance' );
	}

	/**
	 * Check that the saved credentials work and the account may use the API.
	 *
	 * Records the result as the connection state, which is what switches
	 * boxes between API mode and no-API mode.
	 *
	 * @return true|WP_Error
	 */
	public static function check_connection() {
		$plugin = amazon_product_boxes_plugin();

		if ( ! $plugin->has_credentials() ) {
			Amazon_Product_Boxes_Plugin::set_status( 'none' );
			return new WP_Error( 'apb_no_credentials', __( 'Add your Store ID, Credential ID and Credential Secret first.', 'amazon-product-boxes' ) );
		}

		$result = self::search_items( 'kindle', 'All', 1 );

		if ( is_wp_error( $result ) ) {
			self::record_error( $result );
			return $result;
		}

		Amazon_Product_Boxes_Plugin::set_status( 'ok' );
		return true;
	}

	/**
	 * Turn an API error into the connection state.
	 *
	 * @param WP_Error $error Error from {@see call()}.
	 * @return void
	 */
	public static function record_error( WP_Error $error ) {
		$map  = array(
			'apb_not_eligible' => 'not_eligible',
			'apb_auth'         => 'auth',
		);
		$code = $error->get_error_code();

		// Throttling and outages are passing problems: keep the current mode.
		if ( isset( $map[ $code ] ) ) {
			Amazon_Product_Boxes_Plugin::set_status( $map[ $code ], $error->get_error_message() );
		} elseif ( 'ok' !== Amazon_Product_Boxes_Plugin::status()['state'] ) {
			Amazon_Product_Boxes_Plugin::set_status( 'error', $error->get_error_message() );
		}
	}

	/**
	 * Forget the cached access token (credentials changed or were removed).
	 *
	 * @return void
	 */
	public static function forget_token() {
		delete_transient( self::TOKEN_KEY );
	}

	/**
	 * POST a catalog operation.
	 *
	 * @param string $operation getItems or searchItems.
	 * @param array  $body      Request body (partnerTag and marketplace are added).
	 * @param bool   $retry     Whether a rejected token may be refreshed once.
	 * @return array|WP_Error Decoded response.
	 */
	private static function call( $operation, array $body, $retry = true ) {
		$plugin = amazon_product_boxes_plugin();

		if ( ! $plugin->has_credentials() ) {
			return new WP_Error( 'apb_no_credentials', __( 'No Creators API credentials are saved.', 'amazon-product-boxes' ) );
		}

		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$marketplace         = $plugin->marketplace();
		$body['partnerTag']  = (string) $plugin->get_setting( 'tag' );
		$body['marketplace'] = $marketplace;
		$version             = self::version();

		self::throttle();

		$response = wp_remote_post(
			self::BASE . $operation,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 0 === strpos( $version, '2.' ) ? 'Bearer ' . $token . ', Version ' . $version : 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'x-marketplace' => $marketplace,
				),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: connection error. */
			return new WP_Error( 'apb_http', sprintf( __( 'Couldn\'t reach Amazon: %s', 'amazon-product-boxes' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( 200 === $code ) {
			return $data;
		}

		$reason  = self::field( $data, 'reason' );
		$message = self::field( $data, 'message' );
		$message = '' !== $message ? $message : sprintf( /* translators: %d: HTTP status. */ __( 'Amazon answered with HTTP %d.', 'amazon-product-boxes' ), $code );

		if ( 401 === $code && $retry && in_array( $reason, array( 'TokenExpired', 'InvalidToken' ), true ) ) {
			self::forget_token();
			return self::call( $operation, $body, false );
		}

		switch ( $code ) {
			case 403:
				if ( 'AssociateNotEligible' === $reason ) {
					return new WP_Error( 'apb_not_eligible', __( 'Amazon hasn\'t opened the Creators API to this Associates account yet. It needs 10 qualifying sales in the last 30 days.', 'amazon-product-boxes' ), array( 'status' => $code ) );
				}
				return new WP_Error( 'apb_auth', $message, array( 'status' => $code ) );
			case 401:
				return new WP_Error( 'apb_auth', __( 'Amazon rejected the credentials. Check the Credential ID, Secret and Version.', 'amazon-product-boxes' ) . ' (' . ( '' !== $reason ? $reason : $message ) . ')', array( 'status' => $code ) );
			case 400:
				if ( in_array( $reason, array( 'InvalidPartnerTag', 'InvalidAssociate' ), true ) ) {
					return new WP_Error( 'apb_auth', __( 'Amazon doesn\'t recognise that Store ID for these credentials in this store.', 'amazon-product-boxes' ) . ' (' . $reason . ')', array( 'status' => $code ) );
				}
				return new WP_Error( 'apb_bad_request', $message, array( 'status' => $code ) );
			case 404:
				return new WP_Error( 'apb_not_found', $message, array( 'status' => $code ) );
			case 429:
				return new WP_Error( 'apb_throttled', __( 'Amazon asked us to slow down. The plugin will try again later.', 'amazon-product-boxes' ), array( 'status' => $code ) );
		}

		return new WP_Error( 'apb_error', $message, array( 'status' => $code ) );
	}

	/**
	 * A cached access token, or a fresh one.
	 *
	 * @return string|WP_Error
	 */
	private static function token() {
		$plugin = amazon_product_boxes_plugin();
		$id     = (string) $plugin->get_setting( 'credential_id' );
		$ver    = self::version();
		$owner  = md5( $id . '|' . $ver );

		$cached = get_transient( self::TOKEN_KEY );
		if ( is_array( $cached ) && isset( $cached['owner'], $cached['token'] ) && $owner === $cached['owner'] ) {
			$token = Amazon_Product_Boxes_Crypto::decrypt( (string) $cached['token'] );
			if ( '' !== $token ) {
				return $token;
			}
		}

		$endpoints = self::token_endpoints();
		$cognito   = 0 === strpos( $ver, '2.' );
		$fields    = array(
			'grant_type'    => 'client_credentials',
			'client_id'     => $id,
			'client_secret' => $plugin->credential_secret(),
			'scope'         => $cognito ? 'creatorsapi/default' : 'creatorsapi::default',
		);

		$response = wp_remote_post(
			$endpoints[ $ver ],
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => $cognito ? 'application/x-www-form-urlencoded' : 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => $cognito ? $fields : wp_json_encode( $fields ),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: connection error. */
			return new WP_Error( 'apb_http', sprintf( __( 'Couldn\'t reach Amazon\'s login service: %s', 'amazon-product-boxes' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			if ( 429 === $code ) {
				return new WP_Error( 'apb_throttled', __( 'Amazon\'s login service asked us to slow down. The plugin will try again later.', 'amazon-product-boxes' ) );
			}

			$detail = is_array( $data ) ? self::field( $data, 'error_description' ) : '';
			$detail = '' !== $detail ? $detail : ( is_array( $data ) ? self::field( $data, 'error' ) : '' );

			return new WP_Error(
				'apb_auth',
				__( 'Amazon didn\'t accept the Credential ID and Secret. Check them, and that the Version matches the one shown beside the credential in Associates Central.', 'amazon-product-boxes' ) . ( '' !== $detail ? ' (' . $detail . ')' : '' )
			);
		}

		$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		$ttl = max( 60, min( 3600, $ttl ) - 60 );

		set_transient(
			self::TOKEN_KEY,
			array(
				'owner' => $owner,
				'token' => Amazon_Product_Boxes_Crypto::encrypt( $data['access_token'] ),
			),
			$ttl
		);

		return $data['access_token'];
	}

	/**
	 * The saved credential version, if it's one we know.
	 *
	 * @return string
	 */
	private static function version() {
		$ver = (string) amazon_product_boxes_plugin()->get_setting( 'credential_ver' );

		return isset( self::token_endpoints()[ $ver ] ) ? $ver : '3.1';
	}

	/**
	 * Keep at least a second between calls from this site, across requests:
	 * new Associates accounts start at about one request per second.
	 *
	 * @return void
	 */
	private static function throttle() {
		$last = (float) get_transient( 'apb_last_call' );
		$wait = $last + 1.05 - microtime( true );

		if ( $wait > 0 && $wait <= 1.05 ) {
			usleep( (int) ( $wait * 1000000 ) );
		}

		set_transient( 'apb_last_call', (string) microtime( true ), MINUTE_IN_SECONDS );
	}

	/**
	 * Read a string field whatever its case (the API uses "message", older
	 * gateways "Message").
	 *
	 * @param array  $data Decoded body.
	 * @param string $key  Field.
	 * @return string
	 */
	private static function field( array $data, $key ) {
		foreach ( $data as $name => $value ) {
			if ( is_string( $name ) && strtolower( $name ) === strtolower( $key ) && is_scalar( $value ) ) {
				return substr( wp_strip_all_tags( (string) $value ), 0, 300 );
			}
		}

		return '';
	}

	/**
	 * Boil an API item down to what a box shows, cleaned for storage.
	 *
	 * @param mixed $item Item from the response.
	 * @return array|null Null when it has no valid ASIN.
	 */
	public static function normalize( $item ) {
		if ( ! is_array( $item ) || empty( $item['asin'] ) ) {
			return null;
		}

		$asin = Amazon_Product_Boxes_Products::clean_asin( (string) $item['asin'] );
		if ( '' === $asin ) {
			return null;
		}

		$get = function ( array $path ) use ( $item ) {
			$node = $item;
			foreach ( $path as $key ) {
				if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
					return null;
				}
				$node = $node[ $key ];
			}
			return $node;
		};

		$text = function ( $value, $max = 300 ) {
			return is_scalar( $value ) ? trim( substr( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ), 0, $max ) ) : '';
		};

		$features = array();
		foreach ( (array) $get( array( 'itemInfo', 'features', 'displayValues' ) ) as $feature ) {
			$feature = $text( $feature, 400 );
			if ( '' !== $feature ) {
				$features[] = $feature;
			}
			if ( count( $features ) >= 10 ) {
				break;
			}
		}

		$image = $get( array( 'images', 'primary', 'large' ) );
		$image = is_array( $image ) && isset( $image['url'] ) && Amazon_Product_Boxes_Products::is_amazon_image( (string) $image['url'] )
			? array(
				'url' => esc_url_raw( (string) $image['url'] ),
				'w'   => isset( $image['width'] ) ? (int) $image['width'] : 0,
				'h'   => isset( $image['height'] ) ? (int) $image['height'] : 0,
			)
			: null;

		// The buy-box offer is the one Amazon shows; fall back to the first.
		$listings = (array) $get( array( 'offersV2', 'listings' ) );
		$listing  = null;
		foreach ( $listings as $candidate ) {
			if ( is_array( $candidate ) && ! empty( $candidate['isBuyBoxWinner'] ) ) {
				$listing = $candidate;
				break;
			}
		}
		if ( null === $listing && isset( $listings[0] ) && is_array( $listings[0] ) ) {
			$listing = $listings[0];
		}

		$offer = function ( array $path ) use ( $listing ) {
			$node = $listing;
			foreach ( $path as $key ) {
				if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
					return null;
				}
				$node = $node[ $key ];
			}
			return $node;
		};

		$availability = strtoupper( $text( $offer( array( 'availability', 'type' ) ), 40 ) );
		$merchant     = $text( $offer( array( 'merchantInfo', 'name' ) ), 100 );
		$basis_type   = $text( $offer( array( 'price', 'savingBasis', 'savingBasisType' ) ), 40 );
		$access       = $text( $offer( array( 'dealDetails', 'accessType' ) ), 40 );
		$url          = (string) $get( array( 'detailPageURL' ) );

		return array(
			'asin'         => $asin,
			'title'        => $text( $get( array( 'itemInfo', 'title', 'displayValue' ) ), 500 ),
			'brand'        => $text( $get( array( 'itemInfo', 'byLineInfo', 'brand', 'displayValue' ) ), 100 ),
			'url'          => Amazon_Product_Boxes_Products::is_amazon_url( $url ) ? esc_url_raw( $url ) : '',
			'image'        => $image,
			'features'     => $features,
			'price'        => $text( $offer( array( 'price', 'money', 'displayAmount' ) ), 40 ),
			'amount'       => (float) $offer( array( 'price', 'money', 'amount' ) ),
			'basis'        => in_array( $basis_type, array( 'LIST_PRICE', 'WAS_PRICE' ), true ) ? $text( $offer( array( 'price', 'savingBasis', 'money', 'displayAmount' ) ), 40 ) : '',
			'basis_label'  => $text( $offer( array( 'price', 'savingBasis', 'savingBasisTypeLabel' ) ), 40 ),
			'savings'      => max( 0, min( 99, (int) $offer( array( 'price', 'savings', 'percentage' ) ) ) ),
			'deal'         => $text( $offer( array( 'dealDetails', 'badge' ) ), 60 ),
			'availability' => null === $listing ? 'NO_OFFERS' : $availability,
			'merchant'     => $merchant,
			// OffersV2 has no Prime-eligibility field (Amazon dropped it from
			// PA-API's OffersV1). Items sold by Amazon itself always ship with
			// Prime, and Prime-only deals need Prime, so those two get the
			// badge. Editors can force it on or off per box.
			'prime'        => (bool) preg_match( '/^Amazon(\.|\s|$)/i', $merchant ) || in_array( $access, array( 'PRIME_EXCLUSIVE', 'PRIME_EARLY_ACCESS' ), true ),
			'rating'       => round( max( 0, min( 5, (float) $get( array( 'customerReviews', 'starRating', 'value' ) ) ) ), 1 ),
			'reviews'      => max( 0, (int) $get( array( 'customerReviews', 'count' ) ) ),
		);
	}
}
