<?php
/**
 * Products: the cache of Amazon data, ASIN and link helpers, and the index
 * of which posts use which ASINs.
 *
 * Two small tables:
 * - {prefix}apb_products: one row per ASIN and store with the last API data,
 *   its status (ok, unavailable, not_found, pending) and when it was fetched,
 *   checked and last seen on the site.
 * - {prefix}apb_searches: the ASINs a keyword-based bestseller list found.
 *
 * Amazon's licence lets sites keep API data for 24 hours, so anything older
 * is never shown: boxes fall back to no-API mode (no price) until the next
 * refresh succeeds.
 *
 * @package Amazon_Product_Boxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product cache and helpers.
 */
final class Amazon_Product_Boxes_Products {

	/**
	 * Schema version (bump to re-run dbDelta).
	 */
	const DB_VERSION = '1';

	/**
	 * Option that records the installed schema version.
	 */
	const DB_OPTION = 'amazon_product_boxes_db';

	/**
	 * Post meta key listing the ASINs a post uses (one row per ASIN).
	 */
	const USAGE_META = '_apb_asin';

	/**
	 * How long API data may be shown, in seconds (Amazon's 24-hour rule).
	 */
	const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Create or update the tables when the schema version changes.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			asin varchar(10) NOT NULL,
			marketplace varchar(40) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			data longtext NULL,
			fetched_at datetime NULL,
			checked_at datetime NULL,
			seen_at datetime NULL,
			PRIMARY KEY  (asin,marketplace),
			KEY status (status),
			KEY fetched_at (fetched_at)
			) $charset;"
		);

		dbDelta(
			'CREATE TABLE ' . self::searches_table() . " (
			id char(32) NOT NULL,
			query text NOT NULL,
			asins text NULL,
			fetched_at datetime NULL,
			seen_at datetime NULL,
			PRIMARY KEY  (id)
			) $charset;"
		);

		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * Products table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'apb_products';
	}

	/**
	 * Searches table name.
	 *
	 * @return string
	 */
	public static function searches_table() {
		global $wpdb;
		return $wpdb->prefix . 'apb_searches';
	}

	/*
	 * ASINs, links and images -------------------------------------------------
	 */

	/**
	 * A valid ASIN (10 letters and digits, upper case), or ''.
	 *
	 * @param string $value Possible ASIN.
	 * @return string
	 */
	public static function clean_asin( $value ) {
		$value = strtoupper( trim( (string) $value ) );

		return preg_match( '/^[A-Z0-9]{10}$/D', $value ) ? $value : '';
	}

	/**
	 * Get an ASIN from what an editor pasted: an ASIN, an Amazon product link,
	 * or an amzn.to / a.co short link.
	 *
	 * @param string $input Pasted text.
	 * @return string ASIN, or ''.
	 */
	public static function parse_input( $input ) {
		$input = trim( (string) $input );
		$asin  = self::clean_asin( $input );

		if ( '' !== $asin ) {
			return $asin;
		}

		// Only the ASIN is taken from a pasted link, so an old http:// link is fine.
		$input = preg_match( '#^https?://#i', $input ) ? preg_replace( '#^http://#i', 'https://', $input ) : 'https://' . $input;

		$host = strtolower( (string) wp_parse_url( $input, PHP_URL_HOST ) );

		if ( in_array( $host, array( 'amzn.to', 'a.co', 'amzn.eu', 'amzn.asia', 'amzn.com' ), true ) ) {
			$input = self::resolve_short_link( $input );
		}

		return self::asin_from_url( $input );
	}

	/**
	 * The ASIN in an Amazon product URL.
	 *
	 * @param string $url URL.
	 * @return string ASIN, or ''.
	 */
	public static function asin_from_url( $url ) {
		if ( ! self::is_amazon_url( $url ) ) {
			return '';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( preg_match( '#/(?:dp|gp/product|gp/aw/d|exec/obidos/asin|exec/obidos/ASIN|o/asin|product)/([A-Z0-9]{10})(?:[/?]|$)#i', $path, $m ) ) {
			return self::clean_asin( $m[1] );
		}

		// Search-style URLs carry it as a parameter.
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $args );
		foreach ( array( 'asin', 'ASIN', 'creativeASIN' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_string( $args[ $key ] ) ) {
				return self::clean_asin( $args[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Follow one redirect of an Amazon short link without loading the page.
	 *
	 * Only Amazon's own shorteners are asked, over HTTPS, and only their
	 * Location header is read, so this can't be pointed at anything else.
	 *
	 * @param string $url Short link.
	 * @return string The Amazon URL it points at, or ''.
	 */
	private static function resolve_short_link( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( ! preg_match( '#^/[A-Za-z0-9_\-/]{1,60}$#D', $path ) ) {
			return '';
		}

		$response = wp_safe_remote_head(
			'https://' . $host . $path,
			array(
				'timeout'     => 8,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$location = wp_remote_retrieve_header( $response, 'location' );
		$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;

		return self::is_amazon_url( $location ) ? $location : '';
	}

	/**
	 * Whether a host is one of Amazon's stores.
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	public static function is_amazon_host( $host ) {
		$host = strtolower( (string) $host );

		return (bool) preg_match( '/^(?:(?:www|smile|m)\.)?amazon\.(?:com|ca|com\.mx|com\.br|co\.uk|de|fr|it|es|nl|com\.be|ie|se|pl|com\.tr|ae|sa|eg|in|co\.jp|sg|com\.au)$/D', $host );
	}

	/**
	 * Whether a URL is an HTTPS link to an Amazon store.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_amazon_url( $url ) {
		$parts = wp_parse_url( (string) $url );

		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& empty( $parts['user'] )
			&& empty( $parts['port'] )
			&& self::is_amazon_host( $parts['host'] );
	}

	/**
	 * Whether a URL is an image on Amazon's own image servers. No-API boxes
	 * only hotlink from these, as Amazon requires (never downloaded or
	 * re-hosted).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_amazon_image( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'] ) || 'https' !== strtolower( $parts['scheme'] ) || ! empty( $parts['port'] ) || ! empty( $parts['user'] ) ) {
			return false;
		}

		return (bool) preg_match( '/^(?:m\.media-amazon\.com|images-(?:na|eu|fe)\.ssl-images-amazon\.com|images\.amazon\.com)$/D', strtolower( $parts['host'] ) )
			&& (bool) preg_match( '#^/images/[A-Za-z0-9._%+\-/,]+\.(?:jpe?g|png|gif|webp)$#Di', $parts['path'] );
	}

	/**
	 * Amazon's image for an ASIN, served from Amazon's image CDN.
	 *
	 * @param string $asin ASIN.
	 * @param int    $size Longest side in px.
	 * @return string
	 */
	public static function asin_image( $asin, $size = 500 ) {
		return 'https://m.media-amazon.com/images/P/' . rawurlencode( $asin ) . '.01._SCLZZZZZZZ_SX' . (int) $size . '_.jpg';
	}

	/**
	 * Affiliate link to a product.
	 *
	 * @param string     $asin    ASIN.
	 * @param array|null $product API data, whose link is used when present.
	 * @return string
	 */
	public static function link( $asin, $product = null ) {
		$plugin = amazon_product_boxes_plugin();
		$tag    = (string) $plugin->get_setting( 'tag' );

		if ( is_array( $product ) && ! empty( $product['url'] ) && self::is_amazon_url( $product['url'] ) ) {
			return $product['url'];
		}

		$url = 'https://' . $plugin->marketplace() . '/dp/' . rawurlencode( $asin ) . '/';

		return '' !== $tag ? add_query_arg( 'tag', rawurlencode( $tag ), $url ) : $url;
	}

	/**
	 * A valid Store ID (Amazon tracking ID, e.g. mysite-20), or ''.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public static function clean_tag( $value ) {
		$value = trim( (string) $value );

		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\-]{1,62}$/D', $value ) ? $value : '';
	}

	/*
	 * Cache -------------------------------------------------------------------
	 */

	/**
	 * Cached rows for some ASINs in the current store.
	 *
	 * @param string[] $asins ASINs.
	 * @return array<string,array{status:string,data:array|null,fetched:int,checked:int,seen:int}>
	 */
	public static function get_many( array $asins ) {
		global $wpdb;

		$asins = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'clean_asin' ), $asins ) ) ) );
		if ( ! $asins ) {
			return array();
		}

		$table = self::table();
		$place = implode( ',', array_fill( 0, count( $asins ), '%s' ) );
		$args  = array_merge( array( amazon_product_boxes_plugin()->marketplace() ), $asins );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table; placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE marketplace = %s AND asin IN ($place)", $args ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['asin'] ] = self::row( $row );
		}

		return $out;
	}

	/**
	 * Turn a database row into the shape everything else uses.
	 *
	 * @param array $row Row.
	 * @return array{asin:string,status:string,data:array|null,fetched:int,checked:int,seen:int}
	 */
	private static function row( array $row ) {
		$data = isset( $row['data'] ) ? json_decode( (string) $row['data'], true ) : null;

		return array(
			'asin'    => (string) $row['asin'],
			'status'  => (string) $row['status'],
			'data'    => is_array( $data ) ? $data : null,
			'fetched' => self::ts( isset( $row['fetched_at'] ) ? $row['fetched_at'] : null ),
			'checked' => self::ts( isset( $row['checked_at'] ) ? $row['checked_at'] : null ),
			'seen'    => self::ts( isset( $row['seen_at'] ) ? $row['seen_at'] : null ),
		);
	}

	/**
	 * Unix time from a UTC datetime column.
	 *
	 * @param string|null $value Column.
	 * @return int 0 when empty.
	 */
	private static function ts( $value ) {
		return $value && '0000-00-00 00:00:00' !== $value ? (int) strtotime( $value . ' UTC' ) : 0;
	}

	/**
	 * Whether a row's API data is recent enough to show (24 hours).
	 *
	 * @param array|null $row Row from {@see get_many()}.
	 * @return bool
	 */
	public static function is_fresh( $row ) {
		return is_array( $row ) && is_array( $row['data'] ) && $row['fetched'] > time() - self::MAX_AGE;
	}

	/**
	 * Make sure rows exist for these ASINs and mark them as in use, so the
	 * refresh keeps them current. Writes at most once a day per product.
	 *
	 * @param string[] $asins ASINs.
	 * @return string[] ASINs that had no row before (so nothing to show yet).
	 */
	public static function touch( array $asins ) {
		global $wpdb;

		$asins = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'clean_asin' ), $asins ) ) ) );
		if ( ! $asins ) {
			return array();
		}

		$rows        = self::get_many( $asins );
		$marketplace = amazon_product_boxes_plugin()->marketplace();
		$now         = gmdate( 'Y-m-d H:i:s' );
		$new         = array();

		foreach ( $asins as $asin ) {
			if ( ! isset( $rows[ $asin ] ) ) {
				$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (asin, marketplace, status, seen_at) VALUES (%s, %s, %s, %s)', $asin, $marketplace, 'pending', $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table; the name is the only thing not in placeholders.
				$new[] = $asin;
			} elseif ( $rows[ $asin ]['seen'] < time() - DAY_IN_SECONDS ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
				$wpdb->update(
					self::table(),
					array( 'seen_at' => $now ),
					array(
						'asin'        => $asin,
						'marketplace' => $marketplace,
					)
				);
			}
		}

		return $new;
	}

	/**
	 * Store fresh API data for a product.
	 *
	 * @param array $product Product from {@see Amazon_Product_Boxes_Api::normalize()}.
	 * @return void
	 */
	public static function save_api( array $product ) {
		$unavailable = in_array( $product['availability'], array( 'OUT_OF_STOCK', 'UNAVAILABLE', 'NO_OFFERS' ), true );

		self::write(
			$product['asin'],
			array(
				'status'     => $unavailable ? 'unavailable' : 'ok',
				'data'       => wp_json_encode( $product ),
				'fetched_at' => gmdate( 'Y-m-d H:i:s' ),
				'checked_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Record a check that didn't bring data (not found, or the no-API check).
	 *
	 * @param string $asin   ASIN.
	 * @param string $status ok, not_found or unavailable.
	 * @return void
	 */
	public static function mark( $asin, $status ) {
		$fields = array(
			'status'     => $status,
			'checked_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		// A product that's gone keeps no Amazon data.
		if ( 'not_found' === $status ) {
			$fields['data']       = null;
			$fields['fetched_at'] = null;
		}

		self::write( $asin, $fields );
	}

	/**
	 * Note a failed attempt so it isn't retried on every run.
	 *
	 * @param string[] $asins ASINs.
	 * @return void
	 */
	public static function mark_checked( array $asins ) {
		foreach ( $asins as $asin ) {
			self::write( $asin, array( 'checked_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
	}

	/**
	 * Insert or update one product row.
	 *
	 * @param string $asin   ASIN.
	 * @param array  $fields Columns.
	 * @return void
	 */
	private static function write( $asin, array $fields ) {
		global $wpdb;

		$where = array(
			'asin'        => $asin,
			'marketplace' => amazon_product_boxes_plugin()->marketplace(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$updated = $wpdb->update( self::table(), $fields, $where );

		if ( ! $updated ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . ' WHERE asin = %s AND marketplace = %s', $where['asin'], $where['marketplace'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table.
			if ( ! $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
				$wpdb->insert( self::table(), array_merge( $where, array( 'seen_at' => gmdate( 'Y-m-d H:i:s' ) ), $fields ) );
			}
		}
	}

	/**
	 * ASINs due for an API refresh: used in the last 30 days and never
	 * fetched or fetched more than $hours ago (not-found ones weekly).
	 *
	 * @param int  $hours Refresh interval.
	 * @param int  $limit Most to return.
	 * @param bool $force Every product in use, oldest first ("Refresh now").
	 * @return string[]
	 */
	public static function due_for_api( $hours, $limit, $force = false ) {
		global $wpdb;

		$table  = self::table();
		$market = amazon_product_boxes_plugin()->marketplace();
		$seen   = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		if ( $force ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
			return (array) $wpdb->get_col( $wpdb->prepare( "SELECT asin FROM {$table} WHERE marketplace = %s AND seen_at > %s ORDER BY fetched_at IS NULL DESC, fetched_at ASC LIMIT %d", $market, $seen, (int) $limit ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; every value is a placeholder.
		$asins = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT asin FROM {$table}
				WHERE marketplace = %s AND seen_at > %s
				AND ( checked_at IS NULL OR checked_at < %s )
				AND ( ( status <> 'not_found' AND ( fetched_at IS NULL OR fetched_at < %s ) ) OR ( status = 'not_found' AND checked_at < %s ) )
				ORDER BY fetched_at IS NULL DESC, fetched_at ASC
				LIMIT %d",
				$market,
				$seen,
				// Don't hammer ASINs that just failed: wait 15 minutes.
				gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', time() - (int) $hours * HOUR_IN_SECONDS + 5 * MINUTE_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ),
				(int) $limit
			)
		);
		// phpcs:enable

		return $asins;
	}

	/**
	 * ASINs due for the daily no-API check (is the product still on Amazon?).
	 *
	 * @param int  $limit Most to return.
	 * @param bool $force Every product in use, least recently checked first.
	 * @return string[]
	 */
	public static function due_for_check( $limit, $force = false ) {
		global $wpdb;

		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; every value is a placeholder.
		$asins = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT asin FROM {$table}
				WHERE marketplace = %s AND seen_at > %s AND ( checked_at IS NULL OR checked_at < %s )
				ORDER BY checked_at IS NULL DESC, checked_at ASC
				LIMIT %d",
				amazon_product_boxes_plugin()->marketplace(),
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', $force ? time() + 60 : time() - DAY_IN_SECONDS ),
				(int) $limit
			)
		);
		// phpcs:enable

		return $asins;
	}

	/**
	 * Products for the admin list.
	 *
	 * @param string $filter all or attention.
	 * @param int    $limit  Page size.
	 * @param int    $offset Offset.
	 * @return array{rows:array<int,array>,total:int}
	 */
	public static function admin_list( $filter, $limit, $offset ) {
		global $wpdb;

		$table = self::table();
		$where = $wpdb->prepare( 'marketplace = %s', amazon_product_boxes_plugin()->marketplace() );
		if ( 'attention' === $filter ) {
			$where .= " AND status IN ('unavailable','not_found')";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; $where is prepared above.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY FIELD(status,'not_found','unavailable','pending','ok'), seen_at DESC LIMIT %d OFFSET %d", (int) $limit, (int) $offset ),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'rows'  => array_map( array( __CLASS__, 'row' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Count products by status.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE marketplace = %s GROUP BY status", amazon_product_boxes_plugin()->marketplace() ), ARRAY_A );

		$out = array(
			'ok'          => 0,
			'unavailable' => 0,
			'not_found'   => 0,
			'pending'     => 0,
		);
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		$out['attention'] = $out['unavailable'] + $out['not_found'];
		$out['all']       = array_sum( array_intersect_key( $out, array_flip( array( 'ok', 'unavailable', 'not_found', 'pending' ) ) ) );

		return $out;
	}

	/**
	 * Products needing attention that are used in published content
	 * (cached for the admin menu badge).
	 *
	 * @return int
	 */
	public static function attention_count() {
		$count = get_transient( 'apb_attention' );

		if ( false === $count ) {
			$counts = self::counts();
			$count  = $counts['attention'];
			set_transient( 'apb_attention', $count, HOUR_IN_SECONDS );
		}

		return (int) $count;
	}

	/*
	 * Keyword lists -----------------------------------------------------------
	 */

	/**
	 * Cache key for a keyword list.
	 *
	 * @param array $query keywords, index, count, sort.
	 * @return string
	 */
	public static function search_id( array $query ) {
		return md5( amazon_product_boxes_plugin()->marketplace() . '|' . wp_json_encode( $query ) );
	}

	/**
	 * A keyword list's cached ASINs.
	 *
	 * @param array $query Query.
	 * @return array{asins:string[],fetched:int,seen:int}|null
	 */
	public static function get_search( array $query ) {
		global $wpdb;

		$table = self::searches_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT asins, fetched_at, seen_at FROM {$table} WHERE id = %s", self::search_id( $query ) ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		return array(
			'asins'   => array_values( array_filter( array_map( array( __CLASS__, 'clean_asin' ), explode( ',', (string) $row['asins'] ) ) ) ),
			'fetched' => self::ts( $row['fetched_at'] ),
			'seen'    => self::ts( $row['seen_at'] ),
		);
	}

	/**
	 * Make sure a keyword list is tracked (so the refresh fetches it), and
	 * mark it as in use.
	 *
	 * @param array $query Query.
	 * @return void
	 */
	public static function touch_search( array $query ) {
		global $wpdb;

		$cached = self::get_search( $query );
		$now    = gmdate( 'Y-m-d H:i:s' );

		if ( null === $cached ) {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::searches_table() . ' (id, query, seen_at) VALUES (%s, %s, %s)', self::search_id( $query ), wp_json_encode( $query ), $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table; the name is the only thing not in placeholders.
		} elseif ( $cached['seen'] < time() - DAY_IN_SECONDS ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$wpdb->update( self::searches_table(), array( 'seen_at' => $now ), array( 'id' => self::search_id( $query ) ) );
		}
	}

	/**
	 * Save a keyword list's results.
	 *
	 * @param array    $query Query.
	 * @param string[] $asins ASINs in order.
	 * @return void
	 */
	public static function save_search( array $query, array $asins ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->replace(
			self::searches_table(),
			array(
				'id'         => self::search_id( $query ),
				'query'      => wp_json_encode( $query ),
				'asins'      => implode( ',', $asins ),
				'fetched_at' => $now,
				'seen_at'    => $now,
			)
		);
	}

	/**
	 * Keyword lists due for a refresh.
	 *
	 * @param int $hours Refresh interval.
	 * @param int $limit Most to return.
	 * @return array<int,array> Queries.
	 */
	public static function due_searches( $hours, $limit ) {
		global $wpdb;

		$table = self::searches_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; every value is a placeholder.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT query FROM {$table} WHERE seen_at > %s AND ( fetched_at IS NULL OR fetched_at < %s ) ORDER BY fetched_at IS NULL DESC, fetched_at ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', time() - (int) $hours * HOUR_IN_SECONDS + 5 * MINUTE_IN_SECONDS ),
				(int) $limit
			)
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $json ) {
			$query = json_decode( (string) $json, true );
			if ( is_array( $query ) ) {
				$out[] = $query;
			}
		}

		return $out;
	}

	/*
	 * Which posts use which ASINs --------------------------------------------
	 */

	/**
	 * Re-index the ASINs a post uses (its blocks and shortcodes).
	 *
	 * @param int $post_id Post ID.
	 * @return string[] The ASINs found.
	 */
	public static function index_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'revision' === $post->post_type ) {
			return array();
		}

		$found   = Amazon_Product_Boxes_Render::asins_in_content( (string) $post->post_content );
		$current = get_post_meta( $post_id, self::USAGE_META, false );
		$current = is_array( $current ) ? $current : array();

		if ( array_values( $found['asins'] ) !== array_values( $current ) ) {
			delete_post_meta( $post_id, self::USAGE_META );
			foreach ( $found['asins'] as $asin ) {
				add_post_meta( $post_id, self::USAGE_META, $asin );
			}
		}

		if ( 'publish' === $post->post_status || 'future' === $post->post_status || 'private' === $post->post_status ) {
			self::touch( $found['asins'] );
			foreach ( $found['searches'] as $query ) {
				self::touch_search( $query );
			}
		}

		return $found['asins'];
	}

	/**
	 * Posts that use an ASIN.
	 *
	 * @param string $asin  ASIN.
	 * @param int    $limit Most to return.
	 * @return WP_Post[]
	 */
	public static function posts_using( $asin, $limit = 5 ) {
		return get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'future', 'private', 'draft', 'pending' ),
				'posts_per_page' => (int) $limit,
				'meta_key'       => self::USAGE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed key, admin only.
				'meta_value'     => $asin, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin only.
				'no_found_rows'  => true,
			)
		);
	}
}
