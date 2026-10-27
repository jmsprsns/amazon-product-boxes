<?php
/**
 * Admin side: the Content Powered > Amazon Product Boxes page (Amazon
 * account and mode, box design, the products in use, help), the menu badge
 * for products that need attention, the one-time "switched to API mode"
 * notice and the Plugins-screen link.
 *
 * Every change goes through admin-post.php with its own nonce and the
 * manage_options check, then redirects back with a notice kept in a per-user
 * transient (never in the URL, where its text could be spoofed).
 *
 * @package Amazon_Product_Boxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Amazon_Product_Boxes_Admin {

	/**
	 * Settings page slug.
	 */
	const PAGE = 'amazon-product-boxes';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'package-search';

	/**
	 * Tabs: id => icon.
	 */
	const TABS = array(
		'amazon'   => 'store',
		'design'   => 'palette',
		'products' => 'package',
		'help'     => 'life-buoy',
	);

	/**
	 * Capability for everything here.
	 */
	const CAP = 'manage_options';

	/**
	 * Products per page on the Products tab.
	 */
	const PER_PAGE = 25;

	/**
	 * Plugin instance.
	 *
	 * @var Amazon_Product_Boxes_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Amazon_Product_Boxes_Plugin $plugin Plugin instance.
	 */
	public function __construct( Amazon_Product_Boxes_Plugin $plugin ) {
		$this->plugin = $plugin;

		Amazon_Product_Boxes_Menu::init();
		Amazon_Product_Boxes_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'admin_notices', array( $this, 'switched_notice' ) );

		foreach ( array( 'save_account', 'save_credentials', 'remove_credentials', 'check_now', 'save_design', 'refresh_products', 'dismiss_switch' ) as $action ) {
			add_action( 'admin_post_apb_' . $action, array( $this, 'handle_' . $action ) );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( AMAZON_PRODUCT_BOXES_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url() ),
			esc_html__( 'Settings', 'amazon-product-boxes' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu, with a count of
	 * products that need attention.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Amazon_Product_Boxes_Icons::get( self::ICON, 17, 'amazon-product-boxes-menu-icon' );
		$attention  = current_user_can( self::CAP ) ? Amazon_Product_Boxes_Products::attention_count() : 0;
		$badge      = $attention ? ' <span class="awaiting-mod apb-badge-count count-' . (int) $attention . '"><span class="pending-count">' . esc_html( number_format_i18n( $attention ) ) . '</span></span>' : '';
		$menu_title = $icon . '<span class="amazon-product-boxes-menu-label">' . esc_html__( 'Amazon Product Boxes', 'amazon-product-boxes' ) . $badge . '</span>';

		$this->hook_suffix = add_submenu_page(
			Amazon_Product_Boxes_Menu::SLUG,
			__( 'Amazon Product Boxes', 'amazon-product-boxes' ),
			$menu_title,
			self::CAP,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' apb-settings-page' : $classes;
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="amazon-product-boxes-menu-icon-style">'
			. '#adminmenu a:has(>.amazon-product-boxes-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .amazon-product-boxes-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .amazon-product-boxes-menu-icon,#adminmenu a:hover .amazon-product-boxes-menu-icon,#adminmenu a:focus .amazon-product-boxes-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts (and the box styles, for the preview).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'apb-admin', AMAZON_PRODUCT_BOXES_URL . 'assets/admin.css', array(), AMAZON_PRODUCT_BOXES_VERSION );
		wp_enqueue_style( 'apb-boxes' );
		wp_enqueue_script( 'apb-admin', AMAZON_PRODUCT_BOXES_URL . 'assets/admin.js', array(), AMAZON_PRODUCT_BOXES_VERSION, true );

		wp_localize_script(
			'apb-admin',
			'apb_admin',
			array(
				'i18n' => array(
					'copied'        => __( 'Copied', 'amazon-product-boxes' ),
					'copyFailed'    => __( 'Press Ctrl+C to copy', 'amazon-product-boxes' ),
					'confirmRemove' => __( 'Remove the API credentials? Boxes switch to no-API mode (no prices) until you add them again.', 'amazon-product-boxes' ),
					'unsaved'       => __( 'You have unsaved changes.', 'amazon-product-boxes' ),
				),
			)
		);
	}

	/**
	 * Link to the page (or one of its tabs).
	 *
	 * @param array $args Extra query args, e.g. tab.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Amazon_Product_Boxes_Icons::get( $name, $size );
	}

	/**
	 * The tab being shown.
	 *
	 * @return string
	 */
	private function tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return array_key_exists( $tab, self::TABS ) ? $tab : 'amazon';
	}

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'amazon'   => __( 'Amazon Account', 'amazon-product-boxes' ),
			'design'   => __( 'Box Design', 'amazon-product-boxes' ),
			'products' => __( 'Products', 'amazon-product-boxes' ),
			'help'     => __( 'Help', 'amazon-product-boxes' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * Queue a notice for the next page load (this user only).
	 *
	 * @param string $type    success, error, warning or info.
	 * @param string $message Text.
	 * @return void
	 */
	private static function notice( $type, $message ) {
		$key     = 'apb_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[] = array( $type, $message );
		set_transient( $key, array_slice( $notices, -5 ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Print and forget the queued notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		$key     = 'apb_notices_' . get_current_user_id();
		$notices = get_transient( $key );

		if ( ! is_array( $notices ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $notices as $notice ) {
			if ( ! is_array( $notice ) || 2 !== count( $notice ) ) {
				continue;
			}
			$type = in_array( $notice[0], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice[0] : 'info';
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( (string) $notice[1] ) );
		}
	}

	/**
	 * Stop unless the user may manage the plugin and the nonce is right.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'amazon-product-boxes' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Go back to the page.
	 *
	 * @param array $args Query args.
	 * @return void
	 */
	private static function back( array $args = array() ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * One posted field, unslashed (the caller has checked the nonce).
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in guard(); each caller sanitizes.
		return isset( $_POST['apb'][ $key ] ) && is_string( $_POST['apb'][ $key ] ) ? (string) wp_unslash( $_POST['apb'][ $key ] ) : '';
	}

	/*
	 * Handlers ------------------------------------------------------------------
	 */

	/**
	 * Save the Store ID, store, mode and refresh interval.
	 *
	 * @return void
	 */
	public function handle_save_account() {
		self::guard( 'apb_save_account' );

		$raw_tag     = trim( sanitize_text_field( self::posted( 'tag' ) ) );
		$tag         = Amazon_Product_Boxes_Products::clean_tag( $raw_tag );
		$marketplace = sanitize_text_field( self::posted( 'marketplace' ) );
		$mode        = self::posted( 'mode' );
		$hours       = (int) self::posted( 'refresh_hours' );

		if ( '' !== $raw_tag && '' === $tag ) {
			self::notice( 'error', __( 'That doesn\'t look like an Amazon Store ID. It\'s your tracking ID, such as mysite-20, from the top right of Associates Central.', 'amazon-product-boxes' ) );
			self::back( array( 'tab' => 'amazon' ) );
		}

		$old_tag    = (string) $this->plugin->get_setting( 'tag' );
		$old_market = $this->plugin->marketplace();

		$this->plugin->update_settings(
			array(
				'tag'           => $tag,
				'marketplace'   => isset( Amazon_Product_Boxes_Plugin::marketplaces()[ $marketplace ] ) ? $marketplace : 'www.amazon.com',
				'mode'          => 'manual' === $mode ? 'manual' : 'auto',
				'refresh_hours' => in_array( $hours, array( 1, 3, 6, 12 ), true ) ? $hours : 12,
			)
		);

		// A different Store ID or store needs a fresh eligibility check.
		if ( $this->plugin->has_credentials() && 'auto' === $this->plugin->get_setting( 'mode' ) && ( $old_tag !== $tag || $old_market !== $this->plugin->marketplace() ) ) {
			$this->check_and_report();
		} else {
			self::notice( 'success', __( 'Settings saved.', 'amazon-product-boxes' ) );
		}

		delete_transient( 'apb_attention' );
		self::back( array( 'tab' => 'amazon' ) );
	}

	/**
	 * Save Creators API credentials and test them.
	 *
	 * @return void
	 */
	public function handle_save_credentials() {
		self::guard( 'apb_save_credentials' );

		$id      = trim( sanitize_text_field( self::posted( 'credential_id' ) ) );
		$secret  = trim( self::posted( 'credential_secret' ) );
		$version = self::posted( 'credential_ver' );

		if ( ! preg_match( '/^[A-Za-z0-9._\-|]{8,200}$/D', $id ) ) {
			self::notice( 'error', __( 'That doesn\'t look like a Credential ID. Copy it from Associates Central > Tools > Creators API.', 'amazon-product-boxes' ) );
			self::back( array( 'tab' => 'amazon' ) );
		}

		// Keep the saved secret when the field is left blank on an update.
		if ( '' === $secret && '' !== $this->plugin->credential_secret() ) {
			$stored = (string) $this->plugin->get_setting( 'credential_secret' );
		} elseif ( preg_match( '/^[\x21-\x7e]{8,500}$/D', $secret ) ) {
			$stored = Amazon_Product_Boxes_Crypto::encrypt( $secret );
			if ( '' === $stored ) {
				self::notice( 'error', __( 'The secret couldn\'t be stored securely on this server (libsodium is missing), so it wasn\'t saved.', 'amazon-product-boxes' ) );
				self::back( array( 'tab' => 'amazon' ) );
			}
		} else {
			self::notice( 'error', __( 'That doesn\'t look like a Credential Secret. Amazon shows it once, when you create the credential; if you no longer have it, create a new credential.', 'amazon-product-boxes' ) );
			self::back( array( 'tab' => 'amazon' ) );
		}

		$this->plugin->update_settings(
			array(
				'credential_id'     => $id,
				'credential_secret' => $stored,
				'credential_ver'    => isset( Amazon_Product_Boxes_Api::token_endpoints()[ $version ] ) ? $version : '3.1',
			)
		);
		Amazon_Product_Boxes_Api::forget_token();
		Amazon_Product_Boxes_Plugin::set_status( 'none' );

		if ( '' === (string) $this->plugin->get_setting( 'tag' ) ) {
			self::notice( 'warning', __( 'Credentials saved. Add your Store ID above too: the API needs it to check your account.', 'amazon-product-boxes' ) );
		} else {
			$this->check_and_report();
		}

		self::back( array( 'tab' => 'amazon' ) );
	}

	/**
	 * Delete the credentials.
	 *
	 * @return void
	 */
	public function handle_remove_credentials() {
		self::guard( 'apb_remove_credentials' );

		$this->plugin->update_settings(
			array(
				'credential_id'     => '',
				'credential_secret' => '',
			)
		);
		Amazon_Product_Boxes_Api::forget_token();
		Amazon_Product_Boxes_Plugin::set_status( 'none' );

		self::notice( 'success', __( 'The API credentials are deleted from this site. Boxes are in no-API mode.', 'amazon-product-boxes' ) );
		self::back( array( 'tab' => 'amazon' ) );
	}

	/**
	 * Ask Amazon right now whether the account can use the API.
	 *
	 * @return void
	 */
	public function handle_check_now() {
		self::guard( 'apb_check_now' );

		$this->check_and_report();
		self::back( array( 'tab' => 'amazon' ) );
	}

	/**
	 * Run the connection check and queue a notice saying what it means.
	 *
	 * @return void
	 */
	private function check_and_report() {
		$result = Amazon_Product_Boxes_Api::check_connection();

		if ( true === $result ) {
			self::notice( 'success', __( 'Connected. API mode is on: boxes now show live prices, Prime and ratings. Products already in your posts are updated in the background within a few minutes.', 'amazon-product-boxes' ) );
			Amazon_Product_Boxes_Refresh::soon();
			return;
		}

		if ( 'apb_not_eligible' === $result->get_error_code() ) {
			self::notice( 'warning', __( 'The credentials work, but Amazon hasn\'t opened the Creators API to this account yet (it needs 10 qualifying sales in the last 30 days). Boxes stay in no-API mode, and the plugin checks again every day and switches over by itself.', 'amazon-product-boxes' ) );
			return;
		}

		/* translators: %s: error from Amazon. */
		self::notice( 'error', sprintf( __( 'Couldn\'t connect: %s', 'amazon-product-boxes' ), $result->get_error_message() ) );
	}

	/**
	 * Save the box design.
	 *
	 * @return void
	 */
	public function handle_save_design() {
		self::guard( 'apb_save_design' );

		$defaults = Amazon_Product_Boxes_Plugin::defaults();
		$text     = function ( $key, $max ) use ( $defaults ) {
			$value = trim( mb_substr( sanitize_text_field( self::posted( $key ) ), 0, $max ) );
			return '' !== $value ? $value : $defaults[ $key ];
		};
		$color    = function ( $key ) use ( $defaults ) {
			$value = sanitize_hex_color( self::posted( $key ) );
			return $value ? $value : $defaults[ $key ];
		};
		$pick     = function ( $key, array $allowed ) use ( $defaults ) {
			$value = self::posted( $key );
			return in_array( $value, $allowed, true ) ? $value : $defaults[ $key ];
		};

		$this->plugin->update_settings(
			array(
				'layout'            => $pick( 'layout', array( 'horizontal', 'vertical', 'compact' ) ),
				'button_text'       => $text( 'button_text', 40 ),
				'button_text_noapi' => $text( 'button_text_noapi', 40 ),
				'button_color'      => $color( 'button_color' ),
				'accent_color'      => $color( 'accent_color' ),
				'features'          => max( 0, min( 10, (int) self::posted( 'features' ) ) ),
				'show_prime'        => '1' === self::posted( 'show_prime' ) ? 1 : 0,
				'show_rating'       => '1' === self::posted( 'show_rating' ) ? 1 : 0,
				'show_savings'      => '1' === self::posted( 'show_savings' ) ? 1 : 0,
				'new_tab'           => '1' === self::posted( 'new_tab' ) ? 1 : 0,
				'unavailable'       => $pick( 'unavailable', array( 'show', 'hide' ) ),
				'disclosure'        => $pick( 'disclosure', array( 'first', 'every', 'off' ) ),
				'disclosure_text'   => trim( mb_substr( sanitize_text_field( self::posted( 'disclosure_text' ) ), 0, 300 ) ),
			)
		);

		self::notice( 'success', __( 'Box design saved.', 'amazon-product-boxes' ) );
		self::back( array( 'tab' => 'design' ) );
	}

	/**
	 * Refresh (API mode) or re-check (no-API mode) products now.
	 *
	 * @return void
	 */
	public function handle_refresh_products() {
		self::guard( 'apb_refresh_products' );

		if ( $this->plugin->api_mode() ) {
			$asins = Amazon_Product_Boxes_Products::due_for_api( 0, 50, true );
			$done  = 0;
			$error = null;

			foreach ( array_chunk( $asins, Amazon_Product_Boxes_Api::BATCH ) as $chunk ) {
				$result = Amazon_Product_Boxes_Refresh::fetch( $chunk );
				if ( is_wp_error( $result ) ) {
					$error = $result;
					break;
				}
				$done += $result;
			}
			Amazon_Product_Boxes_Refresh::refresh_searches( 3 );

			if ( $error ) {
				/* translators: %s: error from Amazon. */
				self::notice( 'error', sprintf( __( 'Amazon stopped the refresh: %s', 'amazon-product-boxes' ), $error->get_error_message() ) );
			} else {
				/* translators: %d: number of products. */
				self::notice( 'success', sprintf( _n( '%d product updated from Amazon.', '%d products updated from Amazon.', $done, 'amazon-product-boxes' ), $done ) );
			}
		} else {
			$asins = Amazon_Product_Boxes_Products::due_for_check( 25, true );
			foreach ( $asins as $asin ) {
				$exists = Amazon_Product_Boxes_Refresh::image_exists( $asin );
				if ( null !== $exists ) {
					Amazon_Product_Boxes_Products::mark( $asin, $exists ? 'ok' : 'not_found' );
				}
			}
			/* translators: %d: number of products. */
			self::notice( 'success', sprintf( _n( '%d product checked on Amazon.', '%d products checked on Amazon.', count( $asins ), 'amazon-product-boxes' ), count( $asins ) ) );
		}

		delete_transient( 'apb_attention' );
		self::back( array( 'tab' => 'products' ) );
	}

	/**
	 * Hide the "switched to API mode" notice.
	 *
	 * @return void
	 */
	public function handle_dismiss_switch() {
		self::guard( 'apb_dismiss_switch' );

		update_user_meta( get_current_user_id(), 'apb_switch_seen', time() );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : self::url() );
		exit;
	}

	/**
	 * One-time notice, on every admin page, when the site moved into API
	 * mode by itself.
	 *
	 * @return void
	 */
	public function switched_notice() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$status = Amazon_Product_Boxes_Plugin::status();
		$seen   = (int) get_user_meta( get_current_user_id(), 'apb_switch_seen', true );

		if ( ! $status['switched'] || $seen >= $status['switched'] || ! $this->plugin->api_mode() || $status['switched'] < time() - 14 * DAY_IN_SECONDS ) {
			return;
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=apb_dismiss_switch' ), 'apb_dismiss_switch' );
		?>
		<div class="notice notice-success">
			<p>
				<strong><?php esc_html_e( 'Amazon Product Boxes switched to API mode.', 'amazon-product-boxes' ); ?></strong>
				<?php esc_html_e( 'Amazon opened the Creators API to your account, so your product boxes now show live prices, Prime and ratings.', 'amazon-product-boxes' ); ?>
				<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'amazon-product-boxes' ); ?></a>
			</p>
		</div>
		<?php
	}

	/*
	 * Page ------------------------------------------------------------------------
	 */

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$tab = $this->tab();
		?>
		<div class="apb-header">
			<div class="apb-header-title">
				<span class="apb-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="apb-version"><?php echo esc_html( 'v' . AMAZON_PRODUCT_BOXES_VERSION ); ?></span>
				<?php echo $this->mode_pill(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in mode_pill(). ?>
			</div>
			<nav class="apb-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'amazon-product-boxes' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'tab' => $id ) ) ); ?>" class="apb-tab<?php echo $id === $tab ? ' is-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
						<?php if ( 'products' === $id && Amazon_Product_Boxes_Products::attention_count() ) : ?>
							<span class="apb-tab-count"><?php echo esc_html( number_format_i18n( Amazon_Product_Boxes_Products::attention_count() ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap apb-wrap">
			<hr class="wp-header-end">
			<?php $this->render_notices(); ?>
			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php
						switch ( $tab ) {
							case 'design':
								$this->render_design();
								break;
							case 'products':
								$this->render_products();
								break;
							case 'help':
								$this->render_help();
								break;
							default:
								$this->render_mode_card();
								$this->render_account();
								$this->render_credentials();
						}
						?>
					</div>
					<div id="postbox-container-1" class="postbox-container">
						<?php
						if ( 'design' === $tab ) {
							$this->render_preview_box();
						}
						$this->render_status_box();
						$this->render_updates_box();
						?>
					</div>
				</div>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	/**
	 * The current mode as a pill for the header.
	 *
	 * @return string
	 */
	private function mode_pill() {
		$api = $this->plugin->api_mode();

		return '<span class="apb-mode-pill ' . ( $api ? 'is-api' : 'is-noapi' ) . '">'
			. self::icon( $api ? 'zap' : 'image', 13 )
			. esc_html( $api ? __( 'API mode', 'amazon-product-boxes' ) : __( 'No-API mode', 'amazon-product-boxes' ) )
			. '</span>';
	}

	/**
	 * Open a box.
	 *
	 * @param string $icon        Icon.
	 * @param string $title       Title.
	 * @param string $extra_class Extra class.
	 * @param string $extra       HTML for the right side of the header (already escaped).
	 * @return void
	 */
	private static function box_start( $icon, $title, $extra_class = '', $extra = '' ) {
		?>
		<div class="postbox apb-panel <?php echo esc_attr( $extra_class ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span><?php echo esc_html( $title ); ?></span>
				</h2>
				<?php
				if ( '' !== $extra ) {
					echo '<div class="apb-box-actions">' . $extra . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
				}
				?>
			</div>
			<div class="inside">
		<?php
	}

	/**
	 * Close a box.
	 *
	 * @return void
	 */
	private static function box_end() {
		echo '</div></div>';
	}

	/**
	 * A callout line.
	 *
	 * @param string $icon     Icon.
	 * @param string $html     Text (already escaped).
	 * @param string $modifier Extra class: is-warning, is-success, is-error.
	 * @return void
	 */
	private static function callout( $icon, $html, $modifier = '' ) {
		echo '<div class="apb-callout ' . esc_attr( $modifier ) . '">' . self::icon( $icon, 18 ) . '<div>' . $html . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG and caller-escaped HTML.
	}

	/**
	 * A code snippet with a copy button.
	 *
	 * @param string $value Text to copy.
	 * @return string
	 */
	private static function copy_field( $value ) {
		return '<span class="apb-copy-field"><code>' . esc_html( $value ) . '</code><button type="button" class="button button-small apb-copy" data-copy="' . esc_attr( $value ) . '">' . self::icon( 'copy', 14 ) . '<span>' . esc_html__( 'Copy', 'amazon-product-boxes' ) . '</span></button></span>';
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @return void
	 */
	private static function row( $label, $content, $label_for = '' ) {
		echo '<tr><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * Checkbox with a label.
	 *
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="apb-check"><input type="checkbox" id="apb-%1$s" name="apb[%1$s]" value="1" %2$s> <span>%3$s</span></label>',
			esc_attr( $key ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Radio cards (a short title and a line under it).
	 *
	 * @param string $key     Setting.
	 * @param array  $options value => [title, text, icon].
	 * @return void
	 */
	private function cards( $key, array $options ) {
		$current = (string) $this->plugin->get_setting( $key );

		echo '<fieldset class="apb-cards">';
		foreach ( $options as $value => $option ) {
			printf(
				'<label class="apb-card"><input type="radio" name="apb[%1$s]" value="%2$s" %3$s><span class="apb-card-icon">%4$s</span><span class="apb-card-text"><strong>%5$s</strong><span>%6$s</span></span></label>',
				esc_attr( $key ),
				esc_attr( $value ),
				checked( (string) $value, $current, false ),
				self::icon( $option[2], 18 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				esc_html( $option[0] ),
				esc_html( $option[1] )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * How long ago.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function ago( $time ) {
		/* translators: %s: time span, e.g. 5 mins. */
		return $time ? sprintf( __( '%s ago', 'amazon-product-boxes' ), human_time_diff( $time ) ) : __( 'never', 'amazon-product-boxes' );
	}

	/*
	 * Amazon Account tab ----------------------------------------------------------
	 */

	/**
	 * The big "which mode are we in, and why" card.
	 *
	 * @return void
	 */
	private function render_mode_card() {
		$status = Amazon_Product_Boxes_Plugin::status();
		$hours  = (int) $this->plugin->get_setting( 'refresh_hours' );

		if ( '' === (string) $this->plugin->get_setting( 'tag' ) ) {
			$card = array( 'is-warning', 'store', __( 'Add your Store ID to start', 'amazon-product-boxes' ), __( 'Your Amazon tracking ID (like mysite-20) goes on every link, so the sales are credited to you. Boxes already work without it, but you won\'t earn from them.', 'amazon-product-boxes' ) );
		} elseif ( 'manual' === $this->plugin->get_setting( 'mode' ) ) {
			$card = array( 'is-neutral', 'image', __( 'No-API mode (always)', 'amazon-product-boxes' ), __( 'You chose to keep boxes in no-API mode: your own titles and bullet points, Amazon\'s product images, and no prices. Switch "Mode" to Automatic to use the API.', 'amazon-product-boxes' ) );
		} elseif ( $this->plugin->api_mode() ) {
			$card = array(
				'is-success',
				'zap',
				__( 'API mode is on', 'amazon-product-boxes' ),
				1 === $hours
					? __( 'Boxes show Amazon\'s live title, image, price, Prime and rating, refreshed every hour in the background.', 'amazon-product-boxes' )
					/* translators: %d: hours. */
					: sprintf( __( 'Boxes show Amazon\'s live title, image, price, Prime and rating, refreshed every %d hours in the background.', 'amazon-product-boxes' ), $hours ),
			);
		} elseif ( ! $this->plugin->has_credentials() ) {
			$card = array( 'is-info', 'image', __( 'No-API mode', 'amazon-product-boxes' ), __( 'Boxes use the titles and bullet points you write, with Amazon\'s product images and no prices (Amazon only allows prices that come from its API). Once your account has 10 qualifying sales in 30 days, add Creators API credentials below and the boxes switch to live data by themselves.', 'amazon-product-boxes' ) );
		} elseif ( 'not_eligible' === $status['state'] ) {
			/* translators: %s: time ago. */
			$card = array( 'is-warning', 'hourglass', __( 'No-API mode: waiting for Amazon', 'amazon-product-boxes' ), sprintf( __( 'Your credentials work, but Amazon only opens the Creators API to accounts with 10 qualifying sales in the last 30 days. The plugin asks Amazon again every day and switches to API mode by itself as soon as your account qualifies. Last checked %s.', 'amazon-product-boxes' ), self::ago( $status['checked'] ) ) );
		} elseif ( 'auth' === $status['state'] ) {
			$card = array( 'is-error', 'key-round', __( 'No-API mode: Amazon rejected the credentials', 'amazon-product-boxes' ), $status['message'] );
		} elseif ( 'error' === $status['state'] ) {
			/* translators: %s: error message. */
			$card = array( 'is-warning', 'circle-alert', __( 'No-API mode: couldn\'t reach Amazon', 'amazon-product-boxes' ), sprintf( __( 'The plugin will try again within the hour. Amazon said: %s', 'amazon-product-boxes' ), $status['message'] ) );
		} else {
			$card = array( 'is-info', 'refresh-cw', __( 'No-API mode: not checked yet', 'amazon-product-boxes' ), __( 'Click "Check now" to see whether Amazon lets this account use the API.', 'amazon-product-boxes' ) );
		}
		?>
		<div class="apb-mode-card <?php echo esc_attr( $card[0] ); ?>">
			<span class="apb-mode-icon"><?php echo self::icon( $card[1], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<div class="apb-mode-text">
				<strong><?php echo esc_html( $card[2] ); ?></strong>
				<p><?php echo esc_html( $card[3] ); ?></p>
			</div>
			<?php if ( $this->plugin->has_credentials() && 'auto' === $this->plugin->get_setting( 'mode' ) ) : ?>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="apb-inline-form">
					<input type="hidden" name="action" value="apb_check_now">
					<?php wp_nonce_field( 'apb_check_now' ); ?>
					<button type="submit" class="button"><?php echo self::icon( 'refresh-cw', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Check now', 'amazon-product-boxes' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Store ID, store, mode and refresh interval.
	 *
	 * @return void
	 */
	private function render_account() {
		self::box_start( 'store', __( 'Your Amazon store', 'amazon-product-boxes' ) );
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="apb-track">
			<input type="hidden" name="action" value="apb_save_account">
			<?php wp_nonce_field( 'apb_save_account' ); ?>
			<table class="form-table" role="presentation">
				<?php
				self::row(
					__( 'Store ID', 'amazon-product-boxes' ),
					function () {
						printf( '<input type="text" class="regular-text code" id="apb-tag" name="apb[tag]" value="%s" maxlength="64" placeholder="mysite-20" autocomplete="off" spellcheck="false">', esc_attr( (string) $this->plugin->get_setting( 'tag' ) ) );
						echo '<p class="description">' . esc_html__( 'Your Amazon Associates tracking ID, shown at the top right of Associates Central. It\'s added to every link so sales are credited to you.', 'amazon-product-boxes' ) . '</p>';
					},
					'apb-tag'
				);

				self::row(
					__( 'Amazon store', 'amazon-product-boxes' ),
					function () {
						echo '<select id="apb-marketplace" name="apb[marketplace]">';
						foreach ( Amazon_Product_Boxes_Plugin::marketplaces() as $domain => $info ) {
							printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $domain ), selected( $domain, $this->plugin->marketplace(), false ), esc_html( $info[0] ) );
						}
						echo '</select>';
						echo '<p class="description">' . esc_html__( 'The store your Associates account belongs to. Links, images and prices come from this store.', 'amazon-product-boxes' ) . '</p>';
					},
					'apb-marketplace'
				);

				self::row(
					__( 'Mode', 'amazon-product-boxes' ),
					function () {
						$this->cards(
							'mode',
							array(
								'auto'   => array( __( 'Automatic (recommended)', 'amazon-product-boxes' ), __( 'No-API mode until Amazon opens the API to your account, then API mode with live prices, by itself.', 'amazon-product-boxes' ), 'sparkles' ),
								'manual' => array( __( 'Always no-API', 'amazon-product-boxes' ), __( 'Never use the API, even with credentials saved. Boxes show your own titles and no prices.', 'amazon-product-boxes' ), 'image' ),
							)
						);
					}
				);

				self::row(
					__( 'Refresh prices', 'amazon-product-boxes' ),
					function () {
						$hours = (int) $this->plugin->get_setting( 'refresh_hours' );
						echo '<select id="apb-refresh" name="apb[refresh_hours]">';
						foreach ( array( 1, 3, 6, 12 ) as $option ) {
							/* translators: %d: hours. */
							$label = 1 === $option ? __( 'Every hour', 'amazon-product-boxes' ) : sprintf( __( 'Every %d hours', 'amazon-product-boxes' ), $option );
							printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $option, selected( $option, $hours, false ), esc_html( $label ) );
						}
						echo '</select>';
						echo '<p class="description">' . esc_html__( 'API mode only. Amazon allows its data to be shown for up to 24 hours, so a product whose data is older (say, Amazon was unreachable all day) shows without a price until the next refresh works.', 'amazon-product-boxes' ) . '</p>';
					},
					'apb-refresh'
				);
				?>
			</table>
			<p class="apb-form-foot"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'amazon-product-boxes' ); ?></button></p>
		</form>
		<?php
		self::box_end();
	}

	/**
	 * Creators API credentials.
	 *
	 * @return void
	 */
	private function render_credentials() {
		$has = $this->plugin->has_credentials() || '' !== (string) $this->plugin->get_setting( 'credential_id' );
		$id  = (string) $this->plugin->get_setting( 'credential_id' );

		self::box_start( 'key-round', __( 'Creators API credentials', 'amazon-product-boxes' ), 'apb-credentials' );

		if ( $has ) :
			$masked = strlen( $id ) > 10 ? substr( $id, 0, 4 ) . str_repeat( '•', 6 ) . substr( $id, -4 ) : str_repeat( '•', 8 );
			?>
			<div class="apb-connected">
				<span class="apb-connected-icon"><?php echo self::icon( 'key-round', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<div>
					<strong><?php esc_html_e( 'Credentials saved', 'amazon-product-boxes' ); ?></strong>
					<span class="apb-muted">
						<?php
						/* translators: 1: masked credential ID, 2: version. */
						echo esc_html( sprintf( __( 'Credential ID %1$s · version %2$s · secret stored encrypted', 'amazon-product-boxes' ), $masked, (string) $this->plugin->get_setting( 'credential_ver' ) ) );
						?>
					</span>
				</div>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="apb-inline-form">
					<input type="hidden" name="action" value="apb_remove_credentials">
					<?php wp_nonce_field( 'apb_remove_credentials' ); ?>
					<button type="submit" class="button apb-remove" data-confirm="remove"><?php esc_html_e( 'Remove', 'amazon-product-boxes' ); ?></button>
				</form>
			</div>
			<details class="apb-details">
				<summary><?php esc_html_e( 'Use different credentials', 'amazon-product-boxes' ); ?></summary>
				<?php $this->render_credentials_form( true ); ?>
			</details>
			<?php
		else :
			?>
			<p class="apb-intro"><?php esc_html_e( 'Optional. Amazon only gives Creators API access to Associates accounts with 10 qualifying sales in the last 30 days. If yours doesn\'t qualify yet, you can still save credentials: the plugin checks every day and switches to API mode when it does.', 'amazon-product-boxes' ); ?></p>
			<?php $this->render_credentials_form( false ); ?>
			<ol class="apb-steps">
				<li>
					<?php
					printf(
						/* translators: %s: link to Associates Central. */
						esc_html__( 'Sign in to %s and open Tools > Creators API.', 'amazon-product-boxes' ),
						'<a href="' . esc_url( 'https://' . $this->plugin->marketplace() . '/associates' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Associates Central', 'amazon-product-boxes' ) . '</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Click "Create Application", name it after this website, then click "Create Credential".', 'amazon-product-boxes' ); ?></li>
				<li><?php esc_html_e( 'Copy the Credential ID, the Credential Secret (Amazon shows it only once) and the Version, and paste them here.', 'amazon-product-boxes' ); ?></li>
			</ol>
			<?php
		endif;

		self::box_end();
	}

	/**
	 * The credentials form.
	 *
	 * @param bool $update Whether credentials are already saved.
	 * @return void
	 */
	private function render_credentials_form( $update ) {
		$current = (string) $this->plugin->get_setting( 'credential_ver' );
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="apb-key-form">
			<input type="hidden" name="action" value="apb_save_credentials">
			<?php wp_nonce_field( 'apb_save_credentials' ); ?>
			<div class="apb-key-grid">
				<label>
					<span><?php esc_html_e( 'Credential ID', 'amazon-product-boxes' ); ?></span>
					<input type="text" class="code" name="apb[credential_id]" value="<?php echo esc_attr( $update ? (string) $this->plugin->get_setting( 'credential_id' ) : '' ); ?>" maxlength="200" autocomplete="off" spellcheck="false" required>
				</label>
				<label>
					<span><?php esc_html_e( 'Credential Secret', 'amazon-product-boxes' ); ?></span>
					<input type="password" class="code" name="apb[credential_secret]" value="" maxlength="500" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr( $update ? __( 'Leave blank to keep the saved secret', 'amazon-product-boxes' ) : '' ); ?>"<?php echo $update ? '' : ' required'; ?>>
				</label>
				<label>
					<span><?php esc_html_e( 'Version', 'amazon-product-boxes' ); ?></span>
					<select name="apb[credential_ver]">
						<?php
						$labels  = array(
							'3.1' => __( '3.1 (North America)', 'amazon-product-boxes' ),
							'3.2' => __( '3.2 (Europe, Middle East, India)', 'amazon-product-boxes' ),
							'3.3' => __( '3.3 (Far East)', 'amazon-product-boxes' ),
							'2.1' => __( '2.1 (older, North America)', 'amazon-product-boxes' ),
							'2.2' => __( '2.2 (older, Europe)', 'amazon-product-boxes' ),
							'2.3' => __( '2.3 (older, Far East)', 'amazon-product-boxes' ),
						);
						$suggest = Amazon_Product_Boxes_Plugin::marketplaces()[ $this->plugin->marketplace() ][1];
						$current = $update ? $current : $suggest;
						foreach ( $labels as $value => $label ) {
							printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $value, $current, false ), esc_html( $label ) );
						}
						?>
					</select>
				</label>
			</div>
			<p class="apb-form-foot">
				<button type="submit" class="button button-primary"><?php echo self::icon( 'plug-zap', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Save and test', 'amazon-product-boxes' ); ?></button>
				<span class="apb-muted apb-small"><?php echo self::icon( 'lock', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?> <?php esc_html_e( 'The secret is stored encrypted, only ever sent to Amazon, and never shown again.', 'amazon-product-boxes' ); ?></span>
			</p>
		</form>
		<?php
	}

	/*
	 * Box Design tab ----------------------------------------------------------------
	 */

	/**
	 * Design settings.
	 *
	 * @return void
	 */
	private function render_design() {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="apb-track apb-design-form">
			<input type="hidden" name="action" value="apb_save_design">
			<?php wp_nonce_field( 'apb_save_design' ); ?>
			<?php
			self::box_start( 'layout-template', __( 'Layout and colors', 'amazon-product-boxes' ) );
			echo '<table class="form-table" role="presentation">';

			self::row(
				__( 'Box layout', 'amazon-product-boxes' ),
				function () {
					$this->cards(
						'layout',
						array(
							'horizontal' => array( __( 'Side by side', 'amazon-product-boxes' ), __( 'Image on the left, details on the right.', 'amazon-product-boxes' ), 'layout-list' ),
							'vertical'   => array( __( 'Card', 'amazon-product-boxes' ), __( 'Image on top, centered. Good in narrow columns.', 'amazon-product-boxes' ), 'layout-grid' ),
							'compact'    => array( __( 'Compact', 'amazon-product-boxes' ), __( 'A slim row: small image, title, price, button.', 'amazon-product-boxes' ), 'rows-3' ),
						)
					);
					echo '<p class="description">' . esc_html__( 'Each box can pick its own layout in the block settings.', 'amazon-product-boxes' ) . '</p>';
				}
			);

			self::row(
				__( 'Button color', 'amazon-product-boxes' ),
				function () {
					printf( '<input type="color" id="apb-button_color" name="apb[button_color]" value="%s" class="apb-color">', esc_attr( (string) $this->plugin->get_setting( 'button_color' ) ) );
					echo ' <span class="description">' . esc_html__( 'The text color is picked automatically so it stays readable.', 'amazon-product-boxes' ) . '</span>';
				},
				'apb-button_color'
			);

			self::row(
				__( 'Accent color', 'amazon-product-boxes' ),
				function () {
					printf( '<input type="color" id="apb-accent_color" name="apb[accent_color]" value="%s" class="apb-color">', esc_attr( (string) $this->plugin->get_setting( 'accent_color' ) ) );
					echo ' <span class="description">' . esc_html__( 'Badges ("Best overall"), list numbers and the table\'s featured column.', 'amazon-product-boxes' ) . '</span>';
				},
				'apb-accent_color'
			);

			self::row(
				__( 'Button text', 'amazon-product-boxes' ),
				function () {
					printf( '<label class="apb-stack"><span>%1$s</span><input type="text" class="regular-text" id="apb-button_text" name="apb[button_text]" value="%2$s" maxlength="40"></label>', esc_html__( 'With a price (API mode)', 'amazon-product-boxes' ), esc_attr( (string) $this->plugin->get_setting( 'button_text' ) ) );
					printf( '<label class="apb-stack"><span>%1$s</span><input type="text" class="regular-text" id="apb-button_text_noapi" name="apb[button_text_noapi]" value="%2$s" maxlength="40"></label>', esc_html__( 'Without a price (no-API mode)', 'amazon-product-boxes' ), esc_attr( (string) $this->plugin->get_setting( 'button_text_noapi' ) ) );
				},
				'apb-button_text'
			);

			echo '</table>';
			self::box_end();

			self::box_start( 'list-checks', __( 'What boxes show', 'amazon-product-boxes' ) );
			echo '<table class="form-table" role="presentation">';

			self::row(
				__( 'Bullet points', 'amazon-product-boxes' ),
				function () {
					printf( '<input type="number" class="small-text" id="apb-features" name="apb[features]" value="%d" min="0" max="10" step="1"> ', (int) $this->plugin->get_setting( 'features' ) );
					echo '<span class="description">' . esc_html__( 'Up to this many of Amazon\'s feature bullets (API mode) when you haven\'t written your own. 0 hides them.', 'amazon-product-boxes' ) . '</span>';
				},
				'apb-features'
			);

			self::row(
				__( 'From the API', 'amazon-product-boxes' ),
				function () {
					echo '<fieldset class="apb-checks">';
					$this->checkbox( 'show_prime', __( 'Prime badge on products Amazon sells itself', 'amazon-product-boxes' ) );
					$this->checkbox( 'show_rating', __( 'Star rating and number of reviews', 'amazon-product-boxes' ) );
					$this->checkbox( 'show_savings', __( 'Was-price and percentage saved, during sales', 'amazon-product-boxes' ) );
					echo '</fieldset>';
					echo '<p class="description">' . esc_html__( 'API mode only. Amazon\'s new API no longer says whether a seller\'s item ships with Prime, so the badge appears on items sold by Amazon itself; you can switch it on or off for any box.', 'amazon-product-boxes' ) . '</p>';
				}
			);

			self::row(
				__( 'Links', 'amazon-product-boxes' ),
				function () {
					$this->checkbox( 'new_tab', __( 'Open Amazon in a new tab', 'amazon-product-boxes' ), __( 'Links always carry rel="sponsored nofollow", as Google asks for affiliate links.', 'amazon-product-boxes' ) );
				}
			);

			self::row(
				__( 'Unavailable products', 'amazon-product-boxes' ),
				function () {
					$this->cards(
						'unavailable',
						array(
							'show' => array( __( 'Keep showing them', 'amazon-product-boxes' ), __( 'Marked "Currently unavailable". Products Amazon removed link to an Amazon search instead.', 'amazon-product-boxes' ), 'eye' ),
							'hide' => array( __( 'Hide them from visitors', 'amazon-product-boxes' ), __( 'Logged-in editors still see them, with a note.', 'amazon-product-boxes' ), 'eye-off' ),
						)
					);
				}
			);

			echo '</table>';
			self::box_end();

			self::box_start( 'badge-info', __( 'Affiliate disclosure', 'amazon-product-boxes' ) );
			echo '<p class="apb-intro">' . esc_html__( 'Amazon\'s Operating Agreement requires this statement wherever you link to Amazon, and the FTC expects it near the links.', 'amazon-product-boxes' ) . '</p>';
			echo '<table class="form-table" role="presentation">';

			self::row(
				__( 'Show it', 'amazon-product-boxes' ),
				function () {
					$where = (string) $this->plugin->get_setting( 'disclosure' );
					echo '<select id="apb-disclosure" name="apb[disclosure]">';
					foreach (
						array(
							'first' => __( 'Under the first box on each page', 'amazon-product-boxes' ),
							'every' => __( 'Under every box, table and list', 'amazon-product-boxes' ),
							'off'   => __( 'Don\'t show it (my theme already does)', 'amazon-product-boxes' ),
						) as $value => $label
					) {
						printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $value, $where, false ), esc_html( $label ) );
					}
					echo '</select>';
				},
				'apb-disclosure'
			);

			self::row(
				__( 'Text', 'amazon-product-boxes' ),
				function () {
					printf( '<input type="text" class="large-text" id="apb-disclosure_text" name="apb[disclosure_text]" value="%s" maxlength="300">', esc_attr( (string) $this->plugin->get_setting( 'disclosure_text' ) ) );
				},
				'apb-disclosure_text'
			);

			echo '</table>';
			echo '<p class="apb-form-foot"><button type="submit" class="button button-primary">' . esc_html__( 'Save design', 'amazon-product-boxes' ) . '</button></p>';
			self::box_end();
			?>
		</form>
		<?php
	}

	/**
	 * Live preview of a sample box (sidebar on the Box Design tab).
	 *
	 * @return void
	 */
	private function render_preview_box() {
		self::box_start( 'eye', __( 'Preview', 'amazon-product-boxes' ), 'apb-preview-box' );
		echo '<div class="apb-preview" data-api="' . ( $this->plugin->api_mode() ? '1' : '0' ) . '">';
		echo Amazon_Product_Boxes_Render::sample( $this->plugin->api_mode() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in the renderer.
		echo '</div>';
		echo '<p class="apb-muted apb-small">' . esc_html__( 'A made-up product, to show your colors and layout. Updates as you change the settings.', 'amazon-product-boxes' ) . '</p>';
		self::box_end();
	}

	/*
	 * Products tab ----------------------------------------------------------------
	 */

	/**
	 * Products in use, with their status.
	 *
	 * @return void
	 */
	private function render_products() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$filter = isset( $_GET['filter'] ) && 'attention' === $_GET['filter'] ? 'attention' : 'all';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable

		$counts = Amazon_Product_Boxes_Products::counts();
		$list   = Amazon_Product_Boxes_Products::admin_list( $filter, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$api    = $this->plugin->api_mode();

		$refresh = '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="apb-inline-form"><input type="hidden" name="action" value="apb_refresh_products">'
			. wp_nonce_field( 'apb_refresh_products', '_wpnonce', true, false )
			. '<button type="submit" class="button button-small">' . self::icon( 'refresh-cw', 14 ) . esc_html( $api ? __( 'Refresh from Amazon', 'amazon-product-boxes' ) : __( 'Check on Amazon', 'amazon-product-boxes' ) ) . '</button></form>';

		self::box_start( 'package', __( 'Products in your content', 'amazon-product-boxes' ), 'apb-products-box', $refresh );

		if ( $counts['attention'] ) {
			/* translators: %d: number of products. */
			self::callout( 'triangle-alert', esc_html( sprintf( _n( '%d product is unavailable or gone from Amazon. Open the posts that use it and swap in a current product.', '%d products are unavailable or gone from Amazon. Open the posts that use them and swap in current products.', $counts['attention'], 'amazon-product-boxes' ), $counts['attention'] ) ), 'is-warning' );
		}
		$all_url       = self::products_url( 'all' );
		$attention_url = self::products_url( 'attention' );
		?>
		<ul class="subsubsub apb-filters">
			<li><a href="<?php echo esc_url( $all_url ); ?>" class="<?php echo 'all' === $filter ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'amazon-product-boxes' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts['all'] ) ); ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( $attention_url ); ?>" class="<?php echo 'attention' === $filter ? 'current' : ''; ?>"><?php esc_html_e( 'Needs attention', 'amazon-product-boxes' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts['attention'] ) ); ?>)</span></a></li>
		</ul>
		<?php
		if ( ! $list['rows'] ) {
			echo '<div class="apb-empty">' . self::icon( 'package-search', 32 ) . '<p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			echo 'attention' === $filter
				? esc_html__( 'Nothing needs attention. Every product in your boxes is available on Amazon.', 'amazon-product-boxes' )
				: esc_html__( 'No products yet. Add an "Amazon Product Box", "Amazon Comparison Table" or "Amazon Bestseller List" block to a post, and the products show up here once it\'s published.', 'amazon-product-boxes' );
			echo '</p></div>';
			self::box_end();
			return;
		}
		?>
		<table class="widefat striped apb-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'amazon-product-boxes' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'amazon-product-boxes' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Used in', 'amazon-product-boxes' ); ?></th>
					<th scope="col" class="apb-when"><?php esc_html_e( 'Checked', 'amazon-product-boxes' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $list['rows'] as $row ) : ?>
					<?php
					$fresh = Amazon_Product_Boxes_Products::is_fresh( $row );
					$title = $fresh && ! empty( $row['data']['title'] ) ? $row['data']['title'] : '';
					$price = $fresh && ! empty( $row['data']['price'] ) && 'ok' === $row['status'] ? $row['data']['price'] : '';
					$posts = Amazon_Product_Boxes_Products::posts_using( $row['asin'], 4 );
					?>
					<tr>
						<td class="apb-product-cell">
							<?php if ( 'not_found' === $row['status'] ) : ?>
								<span class="apb-product-gone"><?php echo self::icon( 'package-x', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
							<?php else : ?>
								<img src="<?php echo esc_url( Amazon_Product_Boxes_Products::asin_image( $row['asin'], 100 ) ); ?>" alt="" width="44" height="44" loading="lazy" referrerpolicy="no-referrer-when-downgrade">
							<?php endif; ?>
							<div>
								<?php if ( '' !== $title ) : ?>
									<strong class="apb-product-title"><?php echo esc_html( wp_trim_words( $title, 14 ) ); ?></strong>
								<?php endif; ?>
								<span class="apb-muted">
									<a href="<?php echo esc_url( Amazon_Product_Boxes_Products::link( $row['asin'] ) ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $row['asin'] ); ?></code></a>
									<?php echo '' !== $price ? ' · ' . esc_html( $price ) : ''; ?>
									<?php echo '' !== $price && ! empty( $row['data']['prime'] ) ? ' · Prime' : ''; ?>
								</span>
							</div>
						</td>
						<td><?php echo $this->status_tag( $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_tag(). ?></td>
						<td class="apb-used-in">
							<?php
							if ( ! $posts ) {
								echo '<span class="apb-muted">' . esc_html__( 'A widget, template or deleted post', 'amazon-product-boxes' ) . '</span>';
							}
							foreach ( $posts as $post ) {
								$link  = get_edit_post_link( $post->ID );
								$label = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'amazon-product-boxes' );
								echo $link
									? '<a href="' . esc_url( $link ) . '">' . esc_html( wp_trim_words( $label, 8 ) ) . '</a>'
									: esc_html( wp_trim_words( $label, 8 ) );
								echo 'publish' !== $post->post_status ? ' <span class="apb-muted">(' . esc_html( get_post_status_object( $post->post_status ) ? get_post_status_object( $post->post_status )->label : $post->post_status ) . ')</span>' : '';
								echo '<br>';
							}
							?>
						</td>
						<td class="apb-when"><?php echo esc_html( self::ago( $row['checked'] ? $row['checked'] : $row['fetched'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $list['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core markup with escaped URLs.
				array(
					'base'    => add_query_arg( 'paged', '%#%', self::products_url( $filter ) ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			);
			echo '</div></div>';
		}

		echo '<p class="apb-muted apb-small apb-table-note">';
		echo $api
			? esc_html__( 'In API mode, out-of-stock products are flagged when Amazon reports them, and products Amazon removed are flagged too.', 'amazon-product-boxes' )
			: esc_html__( 'In no-API mode the plugin checks once a day that each product still exists on Amazon (out-of-stock products can only be spotted in API mode).', 'amazon-product-boxes' );
		echo '</p>';

		self::box_end();
	}

	/**
	 * Link to the Products tab.
	 *
	 * @param string $filter all or attention.
	 * @return string
	 */
	private static function products_url( $filter ) {
		$args = array( 'tab' => 'products' );
		if ( 'attention' === $filter ) {
			$args['filter'] = 'attention';
		}

		return self::url( $args );
	}

	/**
	 * A status tag.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_tag( $status ) {
		$tags = array(
			'ok'          => array( 'is-good', 'circle-check', __( 'Available', 'amazon-product-boxes' ) ),
			'unavailable' => array( 'is-warn', 'package-x', __( 'Unavailable', 'amazon-product-boxes' ) ),
			'not_found'   => array( 'is-bad', 'circle-x', __( 'Gone from Amazon', 'amazon-product-boxes' ) ),
			'pending'     => array( 'is-none', 'clock', __( 'Not checked yet', 'amazon-product-boxes' ) ),
		);
		$tag  = isset( $tags[ $status ] ) ? $tags[ $status ] : $tags['pending'];

		return '<span class="apb-tag ' . esc_attr( $tag[0] ) . '">' . self::icon( $tag[1], 13 ) . esc_html( $tag[2] ) . '</span>';
	}

	/*
	 * Help tab ----------------------------------------------------------------------
	 */

	/**
	 * Help.
	 *
	 * @return void
	 */
	private function render_help() {
		self::box_start( 'blocks', __( 'Adding products', 'amazon-product-boxes' ) );
		?>
		<div class="apb-ways">
			<div class="apb-way">
				<span class="apb-way-icon"><?php echo self::icon( 'package', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<strong><?php esc_html_e( 'Amazon Product Box', 'amazon-product-boxes' ); ?></strong>
				<p><?php esc_html_e( 'One product with its image, bullet points, price and a button.', 'amazon-product-boxes' ); ?></p>
			</div>
			<div class="apb-way">
				<span class="apb-way-icon"><?php echo self::icon( 'columns-3', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<strong><?php esc_html_e( 'Amazon Comparison Table', 'amazon-product-boxes' ); ?></strong>
				<p><?php esc_html_e( 'Two to ten products side by side, with your own spec rows and a "Best overall" badge.', 'amazon-product-boxes' ); ?></p>
			</div>
			<div class="apb-way">
				<span class="apb-way-icon"><?php echo self::icon( 'list-ordered', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<strong><?php esc_html_e( 'Amazon Bestseller List', 'amazon-product-boxes' ); ?></strong>
				<p><?php esc_html_e( 'A numbered top list, from products you pick or (API mode) Amazon\'s top results for a search.', 'amazon-product-boxes' ); ?></p>
			</div>
		</div>
		<p><?php esc_html_e( 'In the block editor, add one of these blocks, then paste an ASIN or any Amazon product link (amzn.to short links work too). In API mode you can also search Amazon right from the block. In no-API mode, type your own product title and a few bullet points in the block settings.', 'amazon-product-boxes' ); ?></p>
		<?php
		self::box_end();

		self::box_start( 'code', __( 'Shortcodes and PHP', 'amazon-product-boxes' ) );
		?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Product box', 'amazon-product-boxes' ); ?></th><td><?php echo self::copy_field( '[amazon_product_box asin="B0CX23V2ZK" title="My pick" description="Point one|Point two" badge="Best overall"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in copy_field(). ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Comparison table', 'amazon-product-boxes' ); ?></th><td><?php echo self::copy_field( '[amazon_product_table asins="B0CX23V2ZK,B0BSHF7WHW" titles="First|Second" badges="Best overall|" rows="Battery|Weight" specs="10 h;1 kg|8 h;2 kg"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in copy_field(). ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Bestseller list', 'amazon-product-boxes' ); ?></th><td><?php echo self::copy_field( '[amazon_product_list asins="B0CX23V2ZK,B0BSHF7WHW" titles="First|Second"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in copy_field(). ?><p class="description"><?php esc_html_e( 'API mode: [amazon_product_list keywords="air fryer" count="5" category="HomeAndKitchen"]', 'amazon-product-boxes' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Theme templates', 'amazon-product-boxes' ); ?></th><td><?php echo self::copy_field( "<?php if ( function_exists( 'amazon_product_box' ) ) { amazon_product_box( 'B0CX23V2ZK' ); } ?>" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in copy_field(). ?></td></tr>
		</table>
		<?php
		self::box_end();

		self::box_start( 'circle-question-mark', __( 'Good to know', 'amazon-product-boxes' ) );
		?>
		<dl class="apb-faq">
			<dt><?php esc_html_e( 'Why no prices in no-API mode?', 'amazon-product-boxes' ); ?></dt>
			<dd><?php esc_html_e( 'Amazon\'s Operating Agreement only allows prices that come from its API and are under 24 hours old, so a hand-typed price could cost you your Associates account. No-API boxes say "Check price on Amazon" instead.', 'amazon-product-boxes' ); ?></dd>
			<dt><?php esc_html_e( 'Where do no-API images come from?', 'amazon-product-boxes' ); ?></dt>
			<dd><?php esc_html_e( 'From Amazon\'s own image servers, linked by ASIN, never copied to your site (Amazon doesn\'t allow downloading its product images). You can also paste an image link from Amazon or use your own photo from the Media Library.', 'amazon-product-boxes' ); ?></dd>
			<dt><?php esc_html_e( 'Page caching', 'amazon-product-boxes' ); ?></dt>
			<dd><?php esc_html_e( 'Each box shows when its price was fetched, as Amazon requires. If your caching plugin keeps pages for longer than a day, set its cache lifespan to 24 hours or less so visitors see current prices.', 'amazon-product-boxes' ); ?></dd>
			<dt><?php esc_html_e( 'Does it slow pages down?', 'amazon-product-boxes' ); ?></dt>
			<dd><?php esc_html_e( 'No. Pages never wait for Amazon: product data is cached and refreshed in the background, and the stylesheet (about 3 KB gzipped) only loads on pages with a box. There\'s no front-end JavaScript.', 'amazon-product-boxes' ); ?></dd>
		</dl>
		<?php
		self::box_end();
	}

	/*
	 * Sidebar -----------------------------------------------------------------------
	 */

	/**
	 * At-a-glance status.
	 *
	 * @return void
	 */
	private function render_status_box() {
		$plugin = $this->plugin;
		$counts = Amazon_Product_Boxes_Products::counts();
		$next   = wp_next_scheduled( Amazon_Product_Boxes_Refresh::HOOK );
		$tag    = (string) $plugin->get_setting( 'tag' );
		$status = array(
			/* translators: %s: Store ID. */
			array( '' !== $tag, '' !== $tag ? sprintf( __( 'Store ID %s', 'amazon-product-boxes' ), $tag ) : __( 'No Store ID yet', 'amazon-product-boxes' ) ),
			array( true, Amazon_Product_Boxes_Plugin::marketplaces()[ $plugin->marketplace() ][0] ),
			array( $plugin->api_mode(), $plugin->api_mode() ? __( 'API mode: live prices', 'amazon-product-boxes' ) : __( 'No-API mode: no prices', 'amazon-product-boxes' ) ),
			/* translators: %s: number of products. */
			array( $counts['all'] > 0, sprintf( _n( '%s product in your content', '%s products in your content', $counts['all'], 'amazon-product-boxes' ), number_format_i18n( $counts['all'] ) ) ),
			/* translators: %s: number of products. */
			array( 0 === $counts['attention'], $counts['attention'] ? sprintf( _n( '%s needs attention', '%s need attention', $counts['attention'], 'amazon-product-boxes' ), number_format_i18n( $counts['attention'] ) ) : __( 'None unavailable', 'amazon-product-boxes' ) ),
			/* translators: %s: time span. */
			array( (bool) $next, $next ? sprintf( __( 'Next background check in %s', 'amazon-product-boxes' ), human_time_diff( $next ) ) : __( 'Background checks not scheduled', 'amazon-product-boxes' ) ),
		);

		self::box_start( 'activity', __( 'Status', 'amazon-product-boxes' ) );
		?>
		<ul class="apb-checklist">
			<?php foreach ( $status as $item ) : ?>
				<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php
		self::box_end();
	}

	/**
	 * Updates card: installed version, the repo, and a manual check.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( AMAZON_PRODUCT_BOXES_REPO ) );
		$check_url  = Amazon_Product_Boxes_Updater::check_now_url();

		self::box_start( 'refresh-cw', __( 'Updates', 'amazon-product-boxes' ), 'apb-updates-box' );
		?>
		<p>
			<?php
			/* translators: %s: installed plugin version. */
			printf( esc_html__( 'Installed version: %s', 'amazon-product-boxes' ), '<strong>' . esc_html( AMAZON_PRODUCT_BOXES_VERSION ) . '</strong>' );
			?>
		</p>

		<?php if ( ! Amazon_Product_Boxes_Updater::has_repo() ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: header name, 2: main plugin file. */
					esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'amazon-product-boxes' ),
					'<code>Update URI</code>',
					'<code>' . esc_html( plugin_basename( AMAZON_PRODUCT_BOXES_FILE ) ) . '</code>'
				);
				?>
			</p>
		<?php elseif ( Amazon_Product_Boxes_Updater::is_dev_copy() ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: repo, 2: constant name. */
					esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'amazon-product-boxes' ),
					'<a href="' . esc_url( AMAZON_PRODUCT_BOXES_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
					'<code>AMAZON_PRODUCT_BOXES_DEV_MODE</code>'
				);
				?>
			</p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %s: repo. */
					esc_html__( 'Updates come from the latest release of %s.', 'amazon-product-boxes' ),
					'<a href="' . esc_url( AMAZON_PRODUCT_BOXES_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
				);
				?>
			</p>
			<?php if ( $check_url ) : ?>
				<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'amazon-product-boxes' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
		self::box_end();
	}
}
