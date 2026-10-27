<?php
/**
 * Front end: the three blocks (product box, comparison table, bestseller
 * list), their shortcodes, and the HTML they print.
 *
 * Everything is built from the product cache. A visitor's page never waits
 * for Amazon: a product that isn't cached yet is drawn in no-API style and
 * fetched in the background. Only the editor's preview may call Amazon
 * directly, so a freshly inserted box shows its real data straight away.
 *
 * What a box shows depends on the mode:
 * - API mode, data under 24 hours old: Amazon's title, image, features,
 *   price (with its "as of" time and Amazon's disclaimer), Prime and rating.
 * - Otherwise (no-API mode): the editor's own title and bullet points, the
 *   product image hotlinked from Amazon's image servers, and no price.
 *
 * Every value is escaped on output, and links and images must point at
 * Amazon (or the site's own media library), whatever the block attributes
 * say, so a contributor can't use a box to link or load anything else.
 *
 * @package Amazon_Product_Boxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Blocks, shortcodes and markup.
 */
final class Amazon_Product_Boxes_Render {

	/**
	 * Block names.
	 */
	const BOX    = 'amazon-product-boxes/box';
	const TABLE  = 'amazon-product-boxes/table';
	const RANKED = 'amazon-product-boxes/list';

	/**
	 * Shortcode names.
	 */
	const SC_BOX   = 'amazon_product_box';
	const SC_TABLE = 'amazon_product_table';
	const SC_LIST  = 'amazon_product_list';

	/**
	 * Most products in a table or list.
	 */
	const MAX_ITEMS = 10;

	/**
	 * Whether the disclosure has been printed on this page.
	 *
	 * @var bool
	 */
	private static $disclosed = false;

	/**
	 * Whether this request already called Amazon for a preview.
	 *
	 * @var bool
	 */
	private static $previewed = false;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_shortcode( self::SC_BOX, array( __CLASS__, 'shortcode_box' ) );
		add_shortcode( self::SC_TABLE, array( __CLASS__, 'shortcode_table' ) );
		add_shortcode( self::SC_LIST, array( __CLASS__, 'shortcode_list' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_early' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor_assets' ) );
	}

	/**
	 * Register the stylesheet and the blocks.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_style( 'apb-boxes', AMAZON_PRODUCT_BOXES_URL . 'assets/boxes.css', array(), AMAZON_PRODUCT_BOXES_VERSION );

		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$item = array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array( 'type' => 'object' ),
		);

		register_block_type(
			self::BOX,
			array(
				'api_version'     => 2,
				'editor_style'    => 'apb-boxes',
				'render_callback' => array( __CLASS__, 'render_box_block' ),
				'attributes'      => array(
					'asin'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'title'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'description' => array(
						'type'    => 'string',
						'default' => '',
					),
					'badge'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'buttonText'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'link'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'image'       => array(
						'type'    => 'string',
						'default' => 'auto',
					),
					'imageId'     => array(
						'type'    => 'number',
						'default' => 0,
					),
					'imageUrl'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'prime'       => array(
						'type'    => 'string',
						'default' => 'auto',
					),
					'layout'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'features'    => array(
						'type'    => 'number',
						'default' => -1,
					),
					'className'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_block_type(
			self::TABLE,
			array(
				'api_version'     => 2,
				'editor_style'    => 'apb-boxes',
				'render_callback' => array( __CLASS__, 'render_table_block' ),
				'attributes'      => array(
					'products'   => $item,
					'rows'       => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'string' ),
					),
					'showRating' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'className'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_block_type(
			self::RANKED,
			array(
				'api_version'     => 2,
				'editor_style'    => 'apb-boxes',
				'render_callback' => array( __CLASS__, 'render_list_block' ),
				'attributes'      => array(
					'source'    => array(
						'type'    => 'string',
						'default' => 'manual',
					),
					'products'  => $item,
					'keywords'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'index'     => array(
						'type'    => 'string',
						'default' => 'All',
					),
					'count'     => array(
						'type'    => 'number',
						'default' => 5,
					),
					'sort'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'features'  => array(
						'type'    => 'number',
						'default' => 2,
					),
					'className' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Editor script and the data it needs.
	 *
	 * @return void
	 */
	public static function block_editor_assets() {
		$plugin = amazon_product_boxes_plugin();
		$deps   = array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render', 'wp-data' );

		wp_enqueue_script( 'apb-block', AMAZON_PRODUCT_BOXES_URL . 'assets/block.js', $deps, AMAZON_PRODUCT_BOXES_VERSION, true );
		wp_enqueue_style( 'apb-block-editor', AMAZON_PRODUCT_BOXES_URL . 'assets/block-editor.css', array(), AMAZON_PRODUCT_BOXES_VERSION );

		wp_localize_script(
			'apb-block',
			'apbBlock',
			array(
				'api'         => $plugin->api_mode(),
				'tag'         => '' !== (string) $plugin->get_setting( 'tag' ),
				'canManage'   => current_user_can( 'manage_options' ),
				'settingsUrl' => admin_url( 'admin.php?page=' . Amazon_Product_Boxes_Admin::PAGE ),
				'layout'      => (string) $plugin->get_setting( 'layout' ),
				'features'    => (int) $plugin->get_setting( 'features' ),
				'indexes'     => self::search_indexes(),
				'sorts'       => array(
					''                   => __( 'Amazon\'s order (Featured)', 'amazon-product-boxes' ),
					'AvgCustomerReviews' => __( 'Best reviewed', 'amazon-product-boxes' ),
					'NewestArrivals'     => __( 'Newest', 'amazon-product-boxes' ),
					'Price:LowToHigh'    => __( 'Price: low to high', 'amazon-product-boxes' ),
					'Price:HighToLow'    => __( 'Price: high to low', 'amazon-product-boxes' ),
				),
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'apb-block', 'amazon-product-boxes' );
		}
	}

	/**
	 * Common search indexes (categories). Amazon's names; most exist in
	 * every store.
	 *
	 * @return array<string,string>
	 */
	public static function search_indexes() {
		return array(
			'All'                     => __( 'All departments', 'amazon-product-boxes' ),
			'Electronics'             => __( 'Electronics', 'amazon-product-boxes' ),
			'Computers'               => __( 'Computers', 'amazon-product-boxes' ),
			'HomeAndKitchen'          => __( 'Home & Kitchen', 'amazon-product-boxes' ),
			'ToolsAndHomeImprovement' => __( 'Tools & Home Improvement', 'amazon-product-boxes' ),
			'GardenAndOutdoor'        => __( 'Garden & Outdoor', 'amazon-product-boxes' ),
			'SportsAndOutdoors'       => __( 'Sports & Outdoors', 'amazon-product-boxes' ),
			'Beauty'                  => __( 'Beauty', 'amazon-product-boxes' ),
			'HealthPersonalCare'      => __( 'Health & Personal Care', 'amazon-product-boxes' ),
			'Fashion'                 => __( 'Fashion', 'amazon-product-boxes' ),
			'Baby'                    => __( 'Baby', 'amazon-product-boxes' ),
			'ToysAndGames'            => __( 'Toys & Games', 'amazon-product-boxes' ),
			'VideoGames'              => __( 'Video Games', 'amazon-product-boxes' ),
			'Books'                   => __( 'Books', 'amazon-product-boxes' ),
			'KindleStore'             => __( 'Kindle Store', 'amazon-product-boxes' ),
			'PetSupplies'             => __( 'Pet Supplies', 'amazon-product-boxes' ),
			'Automotive'              => __( 'Automotive', 'amazon-product-boxes' ),
			'OfficeProducts'          => __( 'Office Products', 'amazon-product-boxes' ),
			'GroceryAndGourmetFood'   => __( 'Grocery & Gourmet Food', 'amazon-product-boxes' ),
			'MusicalInstruments'      => __( 'Musical Instruments', 'amazon-product-boxes' ),
		);
	}

	/**
	 * Load the stylesheet in the <head> when the page's content has a box,
	 * so it doesn't flash unstyled (late loads still work, in the footer).
	 *
	 * @return void
	 */
	public static function enqueue_early() {
		$post = is_singular() ? get_post() : null;

		if ( $post && ( false !== strpos( $post->post_content, 'amazon-product-boxes/' ) || false !== strpos( $post->post_content, '[amazon_product_' ) ) ) {
			wp_enqueue_style( 'apb-boxes' );
		}
	}

	/*
	 * Entry points -------------------------------------------------------------
	 */

	/**
	 * Product box block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_box_block( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$item       = self::clean_item( $attributes );

		return self::box(
			$item,
			array(
				'layout'    => isset( $attributes['layout'] ) ? (string) $attributes['layout'] : '',
				'features'  => isset( $attributes['features'] ) ? (int) $attributes['features'] : -1,
				'className' => isset( $attributes['className'] ) ? (string) $attributes['className'] : '',
			)
		);
	}

	/**
	 * Comparison table block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_table_block( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$items      = array();

		foreach ( array_slice( isset( $attributes['products'] ) ? (array) $attributes['products'] : array(), 0, self::MAX_ITEMS ) as $product ) {
			if ( is_array( $product ) ) {
				$items[] = self::clean_item( $product );
			}
		}

		$rows = array();
		foreach ( array_slice( isset( $attributes['rows'] ) ? (array) $attributes['rows'] : array(), 0, 20 ) as $row ) {
			$rows[] = self::text( $row, 80 );
		}

		return self::table(
			$items,
			$rows,
			array(
				'rating'    => ! isset( $attributes['showRating'] ) || $attributes['showRating'],
				'className' => isset( $attributes['className'] ) ? (string) $attributes['className'] : '',
			)
		);
	}

	/**
	 * Bestseller list block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_list_block( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$args       = array(
			'features'  => isset( $attributes['features'] ) ? max( 0, min( 5, (int) $attributes['features'] ) ) : 2,
			'className' => isset( $attributes['className'] ) ? (string) $attributes['className'] : '',
		);

		if ( isset( $attributes['source'] ) && 'search' === $attributes['source'] ) {
			return self::search_list( self::clean_search( $attributes ), $args );
		}

		$items = array();
		foreach ( array_slice( isset( $attributes['products'] ) ? (array) $attributes['products'] : array(), 0, self::MAX_ITEMS ) as $product ) {
			if ( is_array( $product ) ) {
				$items[] = self::clean_item( $product );
			}
		}

		return self::ranked( $items, $args );
	}

	/**
	 * [amazon_product_box asin="B0..." title="" description="Line one|Line two" badge="" button="" layout=""]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_box( $atts ) {
		$atts = shortcode_atts(
			array(
				'asin'        => '',
				'title'       => '',
				'description' => '',
				'badge'       => '',
				'button'      => '',
				'link'        => '',
				'image'       => 'auto',
				'image_url'   => '',
				'prime'       => 'auto',
				'layout'      => '',
				'features'    => -1,
			),
			$atts,
			self::SC_BOX
		);

		$item = self::clean_item(
			array(
				'asin'        => $atts['asin'],
				'title'       => $atts['title'],
				'description' => str_replace( '|', "\n", (string) $atts['description'] ),
				'badge'       => $atts['badge'],
				'buttonText'  => $atts['button'],
				'link'        => $atts['link'],
				'image'       => '' !== $atts['image_url'] ? 'custom' : $atts['image'],
				'imageUrl'    => $atts['image_url'],
				'prime'       => $atts['prime'],
			)
		);

		return self::box(
			$item,
			array(
				'layout'   => (string) $atts['layout'],
				'features' => (int) $atts['features'],
			)
		);
	}

	/**
	 * [amazon_product_table asins="B0...,B0..." titles="A|B" badges="Best overall|" rows="Battery|Weight" specs="10 h;1 kg|8 h;2 kg"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_table( $atts ) {
		$atts = shortcode_atts(
			array(
				'asins'  => '',
				'titles' => '',
				'badges' => '',
				'rows'   => '',
				'specs'  => '',
				'rating' => 'yes',
			),
			$atts,
			self::SC_TABLE
		);

		$asins  = self::split( $atts['asins'], ',' );
		$titles = self::split( $atts['titles'], '|', false );
		$badges = self::split( $atts['badges'], '|', false );
		$specs  = self::split( $atts['specs'], '|', false );
		$items  = array();

		foreach ( array_slice( $asins, 0, self::MAX_ITEMS ) as $i => $asin ) {
			$items[] = self::clean_item(
				array(
					'asin'  => $asin,
					'title' => isset( $titles[ $i ] ) ? $titles[ $i ] : '',
					'badge' => isset( $badges[ $i ] ) ? $badges[ $i ] : '',
					'specs' => isset( $specs[ $i ] ) ? explode( ';', $specs[ $i ] ) : array(),
				)
			);
		}

		return self::table(
			$items,
			array_map(
				function ( $row ) {
					return self::text( $row, 80 );
				},
				self::split( $atts['rows'], '|' )
			),
			array( 'rating' => ! in_array( strtolower( (string) $atts['rating'] ), array( 'no', '0', 'false', 'off' ), true ) )
		);
	}

	/**
	 * [amazon_product_list asins="B0...,B0..." titles="A|B"] or
	 * [amazon_product_list keywords="air fryer" count="5" category="HomeAndKitchen" sort="AvgCustomerReviews"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_list( $atts ) {
		$atts = shortcode_atts(
			array(
				'asins'    => '',
				'titles'   => '',
				'keywords' => '',
				'category' => 'All',
				'count'    => 5,
				'sort'     => '',
				'features' => 2,
			),
			$atts,
			self::SC_LIST
		);

		$args = array( 'features' => max( 0, min( 5, (int) $atts['features'] ) ) );

		if ( '' !== trim( (string) $atts['keywords'] ) ) {
			return self::search_list(
				self::clean_search(
					array(
						'keywords' => $atts['keywords'],
						'index'    => $atts['category'],
						'count'    => $atts['count'],
						'sort'     => $atts['sort'],
					)
				),
				$args
			);
		}

		$titles = self::split( $atts['titles'], '|', false );
		$items  = array();
		foreach ( array_slice( self::split( $atts['asins'], ',' ), 0, self::MAX_ITEMS ) as $i => $asin ) {
			$items[] = self::clean_item(
				array(
					'asin'  => $asin,
					'title' => isset( $titles[ $i ] ) ? $titles[ $i ] : '',
				)
			);
		}

		return self::ranked( $items, $args );
	}

	/*
	 * Input cleaning ------------------------------------------------------------
	 */

	/**
	 * One product's editor settings, cleaned.
	 *
	 * @param array $raw Block attributes or a table/list entry.
	 * @return array
	 */
	public static function clean_item( array $raw ) {
		$link = isset( $raw['link'] ) ? trim( (string) $raw['link'] ) : '';
		$url  = isset( $raw['imageUrl'] ) ? trim( (string) $raw['imageUrl'] ) : '';
		$img  = isset( $raw['image'] ) && in_array( $raw['image'], array( 'auto', 'none', 'custom' ), true ) ? $raw['image'] : 'auto';

		$specs = array();
		foreach ( array_slice( isset( $raw['specs'] ) && is_array( $raw['specs'] ) ? $raw['specs'] : array(), 0, 20 ) as $spec ) {
			$specs[] = self::text( $spec, 200 );
		}

		return array(
			'asin'        => Amazon_Product_Boxes_Products::clean_asin( isset( $raw['asin'] ) ? $raw['asin'] : '' ),
			'title'       => self::text( isset( $raw['title'] ) ? $raw['title'] : '', 300 ),
			'description' => isset( $raw['description'] ) && is_string( $raw['description'] ) ? substr( sanitize_textarea_field( $raw['description'] ), 0, 2000 ) : '',
			'badge'       => self::text( isset( $raw['badge'] ) ? $raw['badge'] : '', 40 ),
			'buttonText'  => self::text( isset( $raw['buttonText'] ) ? $raw['buttonText'] : '', 60 ),
			'link'        => self::is_allowed_link( $link ) ? $link : '',
			'image'       => $img,
			'imageId'     => isset( $raw['imageId'] ) ? absint( $raw['imageId'] ) : 0,
			'imageUrl'    => Amazon_Product_Boxes_Products::is_amazon_image( $url ) ? $url : '',
			'prime'       => isset( $raw['prime'] ) && in_array( $raw['prime'], array( 'auto', 'show', 'hide' ), true ) ? $raw['prime'] : 'auto',
			'specs'       => $specs,
		);
	}

	/**
	 * A keyword list's query, cleaned.
	 *
	 * @param array $raw keywords, index, count, sort.
	 * @return array{keywords:string,index:string,count:int,sort:string}
	 */
	public static function clean_search( array $raw ) {
		$index = isset( $raw['index'] ) ? preg_replace( '/[^A-Za-z]/', '', (string) $raw['index'] ) : 'All';
		$sort  = isset( $raw['sort'] ) ? (string) $raw['sort'] : '';

		return array(
			'keywords' => self::text( isset( $raw['keywords'] ) ? $raw['keywords'] : '', 150 ),
			'index'    => '' !== $index ? substr( $index, 0, 40 ) : 'All',
			'count'    => max( 1, min( 10, isset( $raw['count'] ) ? (int) $raw['count'] : 5 ) ),
			'sort'     => in_array( $sort, Amazon_Product_Boxes_Api::sorts(), true ) ? $sort : '',
		);
	}

	/**
	 * One line of plain text.
	 *
	 * @param mixed $value Input.
	 * @param int   $max   Most characters.
	 * @return string
	 */
	private static function text( $value, $max ) {
		return is_scalar( $value ) ? trim( mb_substr( sanitize_text_field( (string) $value ), 0, $max ) ) : '';
	}

	/**
	 * Split a shortcode list.
	 *
	 * @param string $value      Input.
	 * @param string $sep        Separator.
	 * @param bool   $drop_empty Whether to drop empty entries.
	 * @return string[]
	 */
	private static function split( $value, $sep, $drop_empty = true ) {
		$parts = array_map( 'trim', explode( $sep, (string) $value ) );

		return $drop_empty ? array_values( array_filter( $parts, 'strlen' ) ) : ( '' === trim( (string) $value ) ? array() : $parts );
	}

	/**
	 * Whether a custom button link may be used: an Amazon store page or one
	 * of Amazon's own short links (which carry the Store ID).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_allowed_link( $url ) {
		if ( Amazon_Product_Boxes_Products::is_amazon_url( $url ) ) {
			return true;
		}

		$parts = wp_parse_url( (string) $url );

		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& empty( $parts['user'] )
			&& empty( $parts['port'] )
			&& in_array( strtolower( $parts['host'] ), array( 'amzn.to', 'a.co', 'amzn.eu', 'amzn.asia' ), true );
	}

	/**
	 * The ASINs (and keyword lists) a post's content uses.
	 *
	 * @param string $content Post content.
	 * @return array{asins:string[],searches:array<int,array>}
	 */
	public static function asins_in_content( $content ) {
		$asins    = array();
		$searches = array();

		if ( function_exists( 'parse_blocks' ) && false !== strpos( $content, 'amazon-product-boxes/' ) ) {
			$walk = function ( array $blocks ) use ( &$walk, &$asins, &$searches ) {
				foreach ( $blocks as $block ) {
					$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

					if ( self::BOX === $block['blockName'] && ! empty( $attrs['asin'] ) ) {
						$asins[] = (string) $attrs['asin'];
					} elseif ( in_array( $block['blockName'], array( self::TABLE, self::RANKED ), true ) ) {
						if ( self::RANKED === $block['blockName'] && isset( $attrs['source'] ) && 'search' === $attrs['source'] ) {
							$searches[] = self::clean_search( $attrs );
						} else {
							foreach ( isset( $attrs['products'] ) ? (array) $attrs['products'] : array() as $product ) {
								if ( is_array( $product ) && ! empty( $product['asin'] ) ) {
									$asins[] = (string) $product['asin'];
								}
							}
						}
					}

					if ( ! empty( $block['innerBlocks'] ) ) {
						$walk( $block['innerBlocks'] );
					}
				}
			};
			$walk( parse_blocks( $content ) );
		}

		if ( false !== strpos( $content, '[amazon_product_' ) && preg_match_all( '/' . get_shortcode_regex( array( self::SC_BOX, self::SC_TABLE, self::SC_LIST ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$atts = shortcode_parse_atts( $match[3] );
				$atts = is_array( $atts ) ? $atts : array();

				if ( ! empty( $atts['asin'] ) ) {
					$asins[] = $atts['asin'];
				}
				if ( ! empty( $atts['asins'] ) ) {
					$asins = array_merge( $asins, self::split( $atts['asins'], ',' ) );
				}
				if ( ! empty( $atts['keywords'] ) ) {
					$searches[] = self::clean_search(
						array(
							'keywords' => $atts['keywords'],
							'index'    => isset( $atts['category'] ) ? $atts['category'] : 'All',
							'count'    => isset( $atts['count'] ) ? $atts['count'] : 5,
							'sort'     => isset( $atts['sort'] ) ? $atts['sort'] : '',
						)
					);
				}
			}
		}

		return array(
			'asins'    => array_values( array_unique( array_filter( array_map( array( 'Amazon_Product_Boxes_Products', 'clean_asin' ), $asins ) ) ) ),
			'searches' => $searches,
		);
	}

	/*
	 * Data ------------------------------------------------------------------------
	 */

	/**
	 * Whether this is the block editor's preview (a logged-in editor's REST call).
	 *
	 * @return bool
	 */
	private static function is_preview() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' );
	}

	/**
	 * Cached rows for some ASINs, fetching missing or stale ones first when
	 * this is the editor's preview.
	 *
	 * @param string[] $asins ASINs.
	 * @return array<string,array>
	 */
	private static function rows( array $asins ) {
		$asins = array_values( array_unique( array_filter( $asins ) ) );
		if ( ! $asins ) {
			return array();
		}

		$plugin = amazon_product_boxes_plugin();
		$rows   = Amazon_Product_Boxes_Products::get_many( $asins );
		$new    = Amazon_Product_Boxes_Products::touch( $asins );

		if ( ! $plugin->api_mode() ) {
			return $rows;
		}

		if ( self::is_preview() && ! self::$previewed ) {
			$stale = array();
			foreach ( $asins as $asin ) {
				$row = isset( $rows[ $asin ] ) ? $rows[ $asin ] : null;
				if ( ! Amazon_Product_Boxes_Products::is_fresh( $row ) && ( ! $row || 'not_found' !== $row['status'] ) && ( ! $row || $row['checked'] < time() - MINUTE_IN_SECONDS ) ) {
					$stale[] = $asin;
				}
			}

			if ( $stale && Amazon_Product_Boxes_Rest::allow_preview_fetch() ) {
				self::$previewed = true;
				Amazon_Product_Boxes_Refresh::fetch( array_slice( $stale, 0, Amazon_Product_Boxes_Api::BATCH ) );
				return Amazon_Product_Boxes_Products::get_many( $asins );
			}
		} elseif ( $new ) {
			Amazon_Product_Boxes_Refresh::soon();
		}

		return $rows;
	}

	/**
	 * Everything needed to draw one product.
	 *
	 * @param array      $item     Clean editor settings.
	 * @param array|null $row      Cache row.
	 * @param int        $features Bullet points to show.
	 * @return array
	 */
	private static function view( array $item, $row, $features ) {
		$plugin = amazon_product_boxes_plugin();
		$api    = $plugin->api_mode() && Amazon_Product_Boxes_Products::is_fresh( $row );
		$data   = $api ? $row['data'] : null;
		$status = $row ? $row['status'] : 'pending';
		$asin   = $item['asin'];

		$title = '' !== $item['title'] ? $item['title'] : ( $data && '' !== $data['title'] ? $data['title'] : '' );

		// Bullet points: the editor's own, else Amazon's.
		$bullets = array();
		if ( '' !== trim( $item['description'] ) ) {
			$bullets = array_values( array_filter( array_map( 'trim', explode( "\n", $item['description'] ) ), 'strlen' ) );
		} elseif ( $data ) {
			$bullets = (array) $data['features'];
		}
		$bullets = array_slice( $bullets, 0, max( 0, (int) $features ) );

		// Image: the media library, a pasted Amazon image link, Amazon's API
		// image, or Amazon's image for the ASIN; never anything else.
		$image = null;
		if ( 'custom' === $item['image'] && $item['imageId'] && wp_attachment_is_image( $item['imageId'] ) ) {
			$src = wp_get_attachment_image_src( $item['imageId'], 'medium_large' );
			if ( $src ) {
				$image = array(
					'url' => $src[0],
					'w'   => (int) $src[1],
					'h'   => (int) $src[2],
				);
			}
		} elseif ( 'custom' === $item['image'] && '' !== $item['imageUrl'] ) {
			$image = array(
				'url' => $item['imageUrl'],
				'w'   => 0,
				'h'   => 0,
			);
		} elseif ( 'none' !== $item['image'] && 'not_found' !== $status ) {
			$image = $data && ! empty( $data['image']['url'] ) ? $data['image'] : array(
				'url' => Amazon_Product_Boxes_Products::asin_image( $asin ),
				'w'   => 0,
				'h'   => 0,
			);
		}

		$unavailable = in_array( $status, array( 'unavailable', 'not_found' ), true );
		$show_price  = $data && ! $unavailable && '' !== $data['price'];

		if ( 'show' === $item['prime'] ) {
			$prime = true;
		} elseif ( 'hide' === $item['prime'] ) {
			$prime = false;
		} else {
			$prime = $data && ! $unavailable && $plugin->get_setting( 'show_prime' ) && ! empty( $data['prime'] );
		}

		if ( 'not_found' === $status ) {
			// The ASIN is gone: send people to a search for it instead.
			$url = add_query_arg(
				array_filter(
					array(
						'k'   => '' !== $title ? $title : $asin,
						'tag' => (string) $plugin->get_setting( 'tag' ),
					)
				),
				'https://' . $plugin->marketplace() . '/s'
			);
		} else {
			$url = '' !== $item['link'] ? $item['link'] : Amazon_Product_Boxes_Products::link( $asin, $data );
		}

		if ( '' !== $item['buttonText'] ) {
			$button = $item['buttonText'];
		} elseif ( $unavailable ) {
			$button = 'not_found' === $status ? __( 'Find it on Amazon', 'amazon-product-boxes' ) : __( 'See it on Amazon', 'amazon-product-boxes' );
		} else {
			$button = (string) $plugin->get_setting( $show_price ? 'button_text' : 'button_text_noapi' );
		}

		return array(
			'asin'     => $asin,
			'title'    => '' !== $title ? $title : __( 'See this product on Amazon', 'amazon-product-boxes' ),
			'url'      => $url,
			'image'    => $image,
			'bullets'  => $bullets,
			'badge'    => $item['badge'],
			'button'   => $button,
			'status'   => $status,
			'api'      => (bool) $data,
			'price'    => $show_price ? $data['price'] : '',
			'basis'    => $show_price && $plugin->get_setting( 'show_savings' ) ? $data['basis'] : '',
			'savings'  => $show_price && $plugin->get_setting( 'show_savings' ) ? (int) $data['savings'] : 0,
			'prime'    => $prime,
			'rating'   => $data && $plugin->get_setting( 'show_rating' ) && $data['reviews'] > 0 ? (float) $data['rating'] : 0,
			'reviews'  => $data ? (int) $data['reviews'] : 0,
			'fetched'  => $data ? (int) $row['fetched'] : 0,
			'specs'    => $item['specs'],
			'no_title' => '' === $title,
		);
	}

	/**
	 * Whether a product should be hidden from visitors.
	 *
	 * @param array $view View.
	 * @return bool
	 */
	private static function hidden( array $view ) {
		return 'hide' === amazon_product_boxes_plugin()->get_setting( 'unavailable' ) && in_array( $view['status'], array( 'unavailable', 'not_found' ), true );
	}

	/*
	 * Markup ------------------------------------------------------------------------
	 */

	/**
	 * A single product box.
	 *
	 * @param array $item Clean editor settings.
	 * @param array $args layout, features, className.
	 * @return string
	 */
	public static function box( array $item, array $args = array() ) {
		if ( '' === $item['asin'] ) {
			return self::editor_note( __( 'Amazon product box: add an ASIN or Amazon link.', 'amazon-product-boxes' ) );
		}

		$plugin   = amazon_product_boxes_plugin();
		$rows     = self::rows( array( $item['asin'] ) );
		$row      = isset( $rows[ $item['asin'] ] ) ? $rows[ $item['asin'] ] : null;
		$features = isset( $args['features'] ) && (int) $args['features'] >= 0 ? (int) $args['features'] : (int) $plugin->get_setting( 'features' );
		$view     = self::view( $item, $row, min( 10, $features ) );

		if ( self::hidden( $view ) && ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		$layout = isset( $args['layout'] ) && in_array( $args['layout'], array( 'horizontal', 'vertical', 'compact' ), true ) ? $args['layout'] : (string) $plugin->get_setting( 'layout' );

		wp_enqueue_style( 'apb-boxes' );

		return self::flag( array( $view ) ) . self::box_html( $view, $layout, $args ) . self::disclosure();
	}

	/**
	 * A made-up product in a box, for the settings page preview.
	 *
	 * @param bool $api Show it as API mode (with a price) or no-API mode.
	 * @return string
	 */
	public static function sample( $api ) {
		$plugin = amazon_product_boxes_plugin();
		$view   = array(
			'asin'     => 'B000000000',
			'title'    => __( 'Barista Pro Espresso Machine with Built-In Grinder, Stainless Steel', 'amazon-product-boxes' ),
			'url'      => '#',
			'image'    => array(
				'url' => AMAZON_PRODUCT_BOXES_URL . 'assets/sample-product.svg',
				'w'   => 320,
				'h'   => 320,
			),
			'bullets'  => array_slice(
				array(
					__( 'Grinds beans right before brewing for a fuller flavor', 'amazon-product-boxes' ),
					__( 'Steam wand for hand-textured microfoam', 'amazon-product-boxes' ),
					__( 'Dose control and a 67 oz water tank', 'amazon-product-boxes' ),
					__( 'Includes a milk jug, tamper and filter baskets', 'amazon-product-boxes' ),
				),
				0,
				(int) $plugin->get_setting( 'features' )
			),
			'badge'    => __( 'Best overall', 'amazon-product-boxes' ),
			'button'   => (string) $plugin->get_setting( $api ? 'button_text' : 'button_text_noapi' ),
			'status'   => 'ok',
			'api'      => $api,
			'price'    => $api ? '$549.95' : '',
			'basis'    => $api && $plugin->get_setting( 'show_savings' ) ? '$699.95' : '',
			'savings'  => $api && $plugin->get_setting( 'show_savings' ) ? 21 : 0,
			'prime'    => $api && $plugin->get_setting( 'show_prime' ),
			'rating'   => $api && $plugin->get_setting( 'show_rating' ) ? 4.6 : 0,
			'reviews'  => 28104,
			'fetched'  => $api ? time() - 25 * MINUTE_IN_SECONDS : 0,
			'specs'    => array(),
			'no_title' => false,
		);

		return self::box_html( $view, (string) $plugin->get_setting( 'layout' ), array() );
	}

	/**
	 * The box markup for a view.
	 *
	 * @param array  $view   View from {@see view()}.
	 * @param string $layout horizontal, vertical or compact.
	 * @param array  $args   className.
	 * @return string
	 */
	private static function box_html( array $view, $layout, array $args ) {
		$class = 'apb apb-box apb-layout-' . $layout . ( 'ok' !== $view['status'] && 'pending' !== $view['status'] ? ' apb-is-' . $view['status'] : '' ) . self::extra_class( $args );
		$html  = '<div class="' . esc_attr( $class ) . '" style="' . esc_attr( self::style_vars() ) . '">';

		if ( '' !== $view['badge'] ) {
			$html .= '<span class="apb-badge">' . esc_html( $view['badge'] ) . '</span>';
		}

		if ( $view['image'] ) {
			$html .= '<a class="apb-media" ' . self::link_attrs( $view['url'] ) . ' tabindex="-1" aria-hidden="true">' . self::img( $view, 'compact' === $layout ? 160 : 320 ) . '</a>';
		}

		$html .= '<div class="apb-body">';
		$html .= '<a class="apb-title" ' . self::link_attrs( $view['url'] ) . '>' . esc_html( $view['title'] ) . '</a>';
		$html .= self::rating( $view );

		if ( $view['bullets'] && 'compact' !== $layout ) {
			$html .= '<ul class="apb-features">';
			foreach ( $view['bullets'] as $bullet ) {
				$html .= '<li>' . esc_html( $bullet ) . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '<div class="apb-buy">' . self::price( $view ) . self::button( $view ) . '</div>';
		$html .= self::as_of( array( $view ) );

		return $html . '</div></div>';
	}

	/**
	 * A comparison table (products are columns).
	 *
	 * @param array[]  $items Clean editor settings.
	 * @param string[] $rows  Spec row labels.
	 * @param array    $args  rating, className.
	 * @return string
	 */
	public static function table( array $items, array $rows, array $args = array() ) {
		$items = array_values(
			array_filter(
				$items,
				function ( $item ) {
					return '' !== $item['asin'];
				}
			)
		);

		if ( ! $items ) {
			return self::editor_note( __( 'Amazon comparison table: add products to compare.', 'amazon-product-boxes' ) );
		}

		$cache = self::rows( wp_list_pluck( $items, 'asin' ) );
		$views = array();
		foreach ( $items as $item ) {
			$view = self::view( $item, isset( $cache[ $item['asin'] ] ) ? $cache[ $item['asin'] ] : null, 0 );
			if ( ! self::hidden( $view ) || current_user_can( 'edit_posts' ) ) {
				$views[] = $view;
			}
		}

		if ( ! $views ) {
			return '';
		}

		wp_enqueue_style( 'apb-boxes' );

		$has      = function ( $key ) use ( $views ) {
			foreach ( $views as $view ) {
				if ( ! empty( $view[ $key ] ) ) {
					return true;
				}
			}
			return false;
		};
		$cells    = function ( $label, $row_class, $cell ) use ( $views ) {
			$out = '<tr class="' . esc_attr( $row_class ) . '"><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( $views as $i => $view ) {
				$out .= '<td' . ( '' !== $view['badge'] ? ' class="apb-col-featured"' : '' ) . '>' . $cell( $view, $i ) . '</td>';
			}
			return $out . '</tr>';
		};
		$featured = $has( 'badge' );

		$html  = self::flag( $views );
		$html .= '<div class="' . esc_attr( 'apb apb-table-wrap' . self::extra_class( $args ) ) . '" style="' . esc_attr( self::style_vars() ) . '">';
		$html .= '<div class="apb-table-scroll"><table class="apb-table"><tbody>';

		if ( $featured ) {
			$html .= $cells(
				'',
				'apb-row-badge',
				function ( $view ) {
					return '' !== $view['badge'] ? '<span class="apb-badge">' . esc_html( $view['badge'] ) . '</span>' : '';
				}
			);
		}

		if ( $has( 'image' ) ) {
			$html .= $cells(
				'',
				'apb-row-image',
				function ( $view ) {
					return $view['image'] ? '<a class="apb-media" ' . self::link_attrs( $view['url'] ) . ' tabindex="-1" aria-hidden="true">' . self::img( $view, 200 ) . '</a>' : '';
				}
			);
		}

		$html .= $cells(
			__( 'Product', 'amazon-product-boxes' ),
			'apb-row-title',
			function ( $view ) {
				return '<a class="apb-title" ' . self::link_attrs( $view['url'] ) . '>' . esc_html( $view['title'] ) . '</a>';
			}
		);

		if ( ! empty( $args['rating'] ) && $has( 'rating' ) ) {
			$html .= $cells(
				__( 'Rating', 'amazon-product-boxes' ),
				'apb-row-rating',
				function ( $view ) {
					return self::rating( $view );
				}
			);
		}

		foreach ( $rows as $r => $label ) {
			$html .= $cells(
				$label,
				'apb-row-spec',
				function ( $view ) use ( $r ) {
					return isset( $view['specs'][ $r ] ) ? esc_html( $view['specs'][ $r ] ) : '';
				}
			);
		}

		if ( $has( 'price' ) || $has( 'prime' ) ) {
			$html .= $cells(
				__( 'Price', 'amazon-product-boxes' ),
				'apb-row-price',
				function ( $view ) {
					return self::price( $view );
				}
			);
		}

		$html .= $cells(
			'',
			'apb-row-button',
			function ( $view ) {
				return self::button( $view );
			}
		);

		$html .= '</tbody></table></div>' . self::as_of( $views ) . '</div>';

		return $html . self::disclosure();
	}

	/**
	 * A keyword-based list.
	 *
	 * @param array $query Clean query.
	 * @param array $args  features, className.
	 * @return string
	 */
	private static function search_list( array $query, array $args ) {
		$plugin = amazon_product_boxes_plugin();

		if ( '' === $query['keywords'] ) {
			return self::editor_note( __( 'Amazon bestseller list: enter search words.', 'amazon-product-boxes' ) );
		}

		if ( ! $plugin->api_mode() ) {
			return self::editor_note( __( 'Amazon bestseller list: lists built from a search need API mode. Add the products by hand instead, or connect the Creators API.', 'amazon-product-boxes' ) );
		}

		Amazon_Product_Boxes_Products::touch_search( $query );
		$cached = Amazon_Product_Boxes_Products::get_search( $query );

		if ( ( ! $cached || ! $cached['fetched'] ) && self::is_preview() && ! self::$previewed && Amazon_Product_Boxes_Rest::allow_preview_fetch() ) {
			self::$previewed = true;
			$asins           = Amazon_Product_Boxes_Refresh::run_search( $query );
			if ( is_wp_error( $asins ) ) {
				return self::editor_note( $asins->get_error_message() );
			}
			$cached = array(
				'asins'   => $asins,
				'fetched' => time(),
			);
		}

		if ( ! $cached || ! $cached['fetched'] ) {
			Amazon_Product_Boxes_Refresh::soon();
			return self::editor_note( __( 'Amazon bestseller list: the products load in the background within a few minutes.', 'amazon-product-boxes' ) );
		}

		if ( ! $cached['asins'] ) {
			return self::editor_note( __( 'Amazon bestseller list: Amazon found nothing for that search.', 'amazon-product-boxes' ) );
		}

		$items = array();
		foreach ( $cached['asins'] as $asin ) {
			$items[] = self::clean_item( array( 'asin' => $asin ) );
		}

		return self::ranked( $items, $args );
	}

	/**
	 * A numbered list.
	 *
	 * @param array[] $items Clean editor settings.
	 * @param array   $args  features, className.
	 * @return string
	 */
	private static function ranked( array $items, array $args ) {
		$items = array_values(
			array_filter(
				$items,
				function ( $item ) {
					return '' !== $item['asin'];
				}
			)
		);

		if ( ! $items ) {
			return self::editor_note( __( 'Amazon bestseller list: add products.', 'amazon-product-boxes' ) );
		}

		$cache = self::rows( wp_list_pluck( $items, 'asin' ) );
		$views = array();
		foreach ( $items as $item ) {
			$view = self::view( $item, isset( $cache[ $item['asin'] ] ) ? $cache[ $item['asin'] ] : null, isset( $args['features'] ) ? (int) $args['features'] : 2 );
			if ( ! self::hidden( $view ) || current_user_can( 'edit_posts' ) ) {
				$views[] = $view;
			}
		}

		if ( ! $views ) {
			return '';
		}

		wp_enqueue_style( 'apb-boxes' );

		$html  = self::flag( $views );
		$html .= '<div class="' . esc_attr( 'apb apb-list-wrap' . self::extra_class( $args ) ) . '" style="' . esc_attr( self::style_vars() ) . '"><ol class="apb-list">';

		foreach ( $views as $i => $view ) {
			$html .= '<li class="apb-list-item' . ( 'ok' !== $view['status'] && 'pending' !== $view['status'] ? ' apb-is-' . esc_attr( $view['status'] ) : '' ) . '">';
			$html .= '<span class="apb-rank" aria-hidden="true">' . (int) ( $i + 1 ) . '</span>';
			$html .= $view['image'] ? '<a class="apb-media" ' . self::link_attrs( $view['url'] ) . ' tabindex="-1" aria-hidden="true">' . self::img( $view, 160 ) . '</a>' : '<span class="apb-media"></span>';
			$html .= '<div class="apb-body">';
			if ( '' !== $view['badge'] ) {
				$html .= '<span class="apb-badge">' . esc_html( $view['badge'] ) . '</span>';
			}
			$html .= '<a class="apb-title" ' . self::link_attrs( $view['url'] ) . '>' . esc_html( $view['title'] ) . '</a>';
			$html .= self::rating( $view );
			if ( $view['bullets'] ) {
				$html .= '<ul class="apb-features">';
				foreach ( $view['bullets'] as $bullet ) {
					$html .= '<li>' . esc_html( $bullet ) . '</li>';
				}
				$html .= '</ul>';
			}
			$html .= '</div>';
			$html .= '<div class="apb-buy">' . self::price( $view ) . self::button( $view ) . '</div>';
			$html .= '</li>';
		}

		$html .= '</ol>' . self::as_of( $views ) . '</div>';

		return $html . self::disclosure();
	}

	/**
	 * The product image.
	 *
	 * @param array $view View.
	 * @param int   $size Display size hint in px.
	 * @return string
	 */
	private static function img( array $view, $size ) {
		$w = $view['image']['w'] ? (int) $view['image']['w'] : (int) $size;
		$h = $view['image']['h'] ? (int) $view['image']['h'] : (int) $size;

		return sprintf(
			'<img src="%1$s" alt="%2$s" width="%3$d" height="%4$d" loading="lazy" decoding="async" referrerpolicy="no-referrer-when-downgrade">',
			esc_url( $view['image']['url'] ),
			esc_attr( $view['no_title'] ? '' : $view['title'] ),
			$w,
			$h
		);
	}

	/**
	 * Attributes for a link to Amazon.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function link_attrs( $url ) {
		$new_tab = (bool) amazon_product_boxes_plugin()->get_setting( 'new_tab' );

		return 'href="' . esc_url( $url ) . '" rel="nofollow sponsored noopener' . ( $new_tab ? ' noreferrer' : '' ) . '"' . ( $new_tab ? ' target="_blank"' : '' );
	}

	/**
	 * Star rating.
	 *
	 * @param array $view View.
	 * @return string
	 */
	private static function rating( array $view ) {
		if ( ! $view['rating'] ) {
			return '';
		}

		/* translators: 1: rating, 2: number of reviews. */
		$label = sprintf( _n( 'Rated %1$s out of 5 stars from %2$s review', 'Rated %1$s out of 5 stars from %2$s reviews', $view['reviews'], 'amazon-product-boxes' ), number_format_i18n( $view['rating'], 1 ), number_format_i18n( $view['reviews'] ) );

		return '<span class="apb-rating" role="img" aria-label="' . esc_attr( $label ) . '"><span class="apb-stars" style="--apb-rating:' . esc_attr( (string) $view['rating'] ) . '" aria-hidden="true">★★★★★</span><span class="apb-reviews" aria-hidden="true">' . esc_html( number_format_i18n( $view['reviews'] ) ) . '</span></span>';
	}

	/**
	 * Price line: price, list price, savings, Prime, or "Currently unavailable".
	 *
	 * @param array $view View.
	 * @return string
	 */
	private static function price( array $view ) {
		if ( 'unavailable' === $view['status'] ) {
			return '<span class="apb-price apb-price-out">' . esc_html__( 'Currently unavailable', 'amazon-product-boxes' ) . '</span>';
		}

		if ( 'not_found' === $view['status'] ) {
			return '<span class="apb-price apb-price-out">' . esc_html__( 'No longer available', 'amazon-product-boxes' ) . '</span>';
		}

		$html = '';
		if ( '' !== $view['price'] ) {
			$html .= '<span class="apb-amount">' . esc_html( $view['price'] ) . '</span>';
			if ( '' !== $view['basis'] && $view['basis'] !== $view['price'] ) {
				$html .= ' <del class="apb-basis">' . esc_html( $view['basis'] ) . '</del>';
			}
			if ( $view['savings'] > 0 ) {
				/* translators: %d: percent saved. */
				$html .= ' <span class="apb-save">' . esc_html( sprintf( __( '-%d%%', 'amazon-product-boxes' ), $view['savings'] ) ) . '</span>';
			}
		}

		if ( $view['prime'] ) {
			$html .= ' <span class="apb-prime">' . Amazon_Product_Boxes_Icons::get( 'check', 12 ) . esc_html__( 'Prime', 'amazon-product-boxes' ) . '</span>';
		}

		return '' !== $html ? '<span class="apb-price">' . $html . '</span>' : '';
	}

	/**
	 * The "Buy on Amazon" button.
	 *
	 * @param array $view View.
	 * @return string
	 */
	private static function button( array $view ) {
		return '<a class="apb-button" ' . self::link_attrs( $view['url'] ) . '>' . esc_html( $view['button'] ) . '</a>';
	}

	/**
	 * When the prices were fetched, with Amazon's required disclaimer.
	 *
	 * @param array[] $views Views.
	 * @return string
	 */
	private static function as_of( array $views ) {
		$oldest = 0;
		foreach ( $views as $view ) {
			if ( '' !== $view['price'] && $view['fetched'] && ( ! $oldest || $view['fetched'] < $oldest ) ) {
				$oldest = $view['fetched'];
			}
		}

		if ( ! $oldest ) {
			return '';
		}

		$when = function_exists( 'wp_date' )
			? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $oldest )
			: date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $oldest + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );

		return '<details class="apb-asof"><summary>'
			/* translators: %s: date and time. */
			. esc_html( sprintf( __( 'Prices as of %s', 'amazon-product-boxes' ), $when ) )
			. '</summary><p>'
			. esc_html__( 'Product prices and availability are accurate as of the date/time indicated and are subject to change. Any price and availability information displayed on Amazon at the time of purchase will apply to the purchase of this product.', 'amazon-product-boxes' )
			. '</p></details>';
	}

	/**
	 * The affiliate disclosure, once per page or after every box.
	 *
	 * @return string
	 */
	private static function disclosure() {
		$plugin = amazon_product_boxes_plugin();
		$where  = (string) $plugin->get_setting( 'disclosure' );
		$text   = trim( (string) $plugin->get_setting( 'disclosure_text' ) );

		if ( 'off' === $where || '' === $text || ( 'first' === $where && self::$disclosed && ! self::is_preview() ) ) {
			return '';
		}

		self::$disclosed = true;

		return '<p class="apb-disclosure">' . esc_html( $text ) . '</p>';
	}

	/**
	 * A note only editors see: on unavailable products.
	 *
	 * @param array[] $views Views.
	 * @return string
	 */
	private static function flag( array $views ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		$gone = array();
		$out  = array();
		foreach ( $views as $view ) {
			if ( 'not_found' === $view['status'] ) {
				$gone[] = $view['asin'];
			} elseif ( 'unavailable' === $view['status'] ) {
				$out[] = $view['asin'];
			}
		}

		$notes = array();
		if ( $gone ) {
			/* translators: %s: ASINs. */
			$notes[] = sprintf( _n( 'Amazon no longer has %s. Visitors are sent to an Amazon search instead; swap in a current product.', 'Amazon no longer has %s. Visitors are sent to an Amazon search instead; swap in current products.', count( $gone ), 'amazon-product-boxes' ), implode( ', ', $gone ) );
		}
		if ( $out ) {
			/* translators: %s: ASINs. */
			$notes[] = sprintf( _n( '%s is currently unavailable on Amazon.', '%s are currently unavailable on Amazon.', count( $out ), 'amazon-product-boxes' ), implode( ', ', $out ) );
		}

		if ( ! $notes ) {
			return '';
		}

		$hidden = 'hide' === amazon_product_boxes_plugin()->get_setting( 'unavailable' ) ? ' ' . __( 'Visitors don\'t see these products.', 'amazon-product-boxes' ) : '';

		return '<p class="apb-flag">' . Amazon_Product_Boxes_Icons::get( 'triangle-alert', 16 ) . '<span>' . esc_html( implode( ' ', $notes ) . $hidden ) . ' <em>' . esc_html__( '(Only editors see this note.)', 'amazon-product-boxes' ) . '</em></span></p>';
	}

	/**
	 * A note for editors when there's nothing to show; nothing for visitors.
	 *
	 * @param string $text Note.
	 * @return string
	 */
	private static function editor_note( $text ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		wp_enqueue_style( 'apb-boxes' );

		return '<p class="apb-flag apb-flag-info">' . Amazon_Product_Boxes_Icons::get( 'info', 16 ) . '<span>' . esc_html( $text ) . ' <em>' . esc_html__( '(Only editors see this note.)', 'amazon-product-boxes' ) . '</em></span></p>';
	}

	/**
	 * Extra classes from the block's Advanced panel.
	 *
	 * @param array $args Args with className.
	 * @return string
	 */
	private static function extra_class( array $args ) {
		if ( empty( $args['className'] ) ) {
			return '';
		}

		$classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $args['className'] ) ) );

		return $classes ? ' ' . implode( ' ', $classes ) : '';
	}

	/**
	 * CSS custom properties for the chosen colors.
	 *
	 * @return string
	 */
	public static function style_vars() {
		$plugin = amazon_product_boxes_plugin();
		$button = sanitize_hex_color( (string) $plugin->get_setting( 'button_color' ) );
		$accent = sanitize_hex_color( (string) $plugin->get_setting( 'accent_color' ) );
		$button = $button ? $button : '#ffa41c';
		$accent = $accent ? $accent : '#232f3e';

		return '--apb-button:' . $button . ';--apb-button-text:' . self::text_on( $button ) . ';--apb-accent:' . $accent . ';--apb-accent-text:' . self::text_on( $accent );
	}

	/**
	 * Black or white text, whichever reads better on a color.
	 *
	 * @param string $hex #rgb or #rrggbb.
	 * @return string
	 */
	public static function text_on( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$channel = function ( $part ) {
			$c = hexdec( $part ) / 255;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};

		$lum = 0.2126 * $channel( substr( $hex, 0, 2 ) ) + 0.7152 * $channel( substr( $hex, 2, 2 ) ) + 0.0722 * $channel( substr( $hex, 4, 2 ) );

		// Contrast against white vs against near-black #0f1111.
		return ( 1.05 / ( $lum + 0.05 ) ) >= ( ( $lum + 0.05 ) / 0.0566 ) ? '#ffffff' : '#0f1111';
	}
}
