<?php
/**
 * Plugin Name: Woo Side Cart
 * Description: Responsive floating side cart for WooCommerce with a configurable cart progress bar (minimum checkout value, tiered % discounts, free shipping).
 * Version: 1.1.3
 * Author: Shimanta Das
 * Text Domain: woo-side-cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Side_Cart {

	const VERSION      = '1.1.3';
	const OPTION_KEY   = 'wsc_settings';

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) );
			return;
		}

		/* Front */
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_drawer' ) );

		/* Cart page progress bar */
		add_action( 'woocommerce_before_cart', array( $this, 'render_progress_cart_page' ) );

		/* Redirects / fragments */
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'redirect_back_to_product' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_fragments' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'set_open_cookie' ), 10, 6 );

		/* AJAX */
		add_action( 'wp_ajax_woo_side_cart_update_item', array( $this, 'ajax_update_item' ) );
		add_action( 'wp_ajax_nopriv_woo_side_cart_update_item', array( $this, 'ajax_update_item' ) );
		add_action( 'wp_ajax_woo_side_cart_refresh', array( $this, 'ajax_refresh_cart' ) );
		add_action( 'wp_ajax_nopriv_woo_side_cart_refresh', array( $this, 'ajax_refresh_cart' ) );

		/* Discounts + free shipping */
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_tiered_discount' ) );
		add_filter( 'woocommerce_shipping_free_shipping_is_available', array( $this, 'filter_free_shipping' ), 20, 3 );

		/* Checkout gating */
		add_action( 'template_redirect', array( $this, 'gate_checkout' ) );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_min_cart_at_checkout' ) );

		/* Admin */
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	/* ==================================================================
	 * Settings helpers
	 * ================================================================== */
	public static function defaults() {
		return array(
			'progress_enabled'        => 'no',
			'progress_placement'      => 'both',
			'min_checkout_enabled'    => 'no',
			'min_checkout_amount'     => 40,
			'min_checkout_message'    => 'Minimum %s required to checkout.',
			'free_shipping_enabled'   => 'no',
			'free_shipping_threshold' => 250,
			'free_shipping_label'     => 'Free shipping',
			'tiers'                   => array(
				array( 'threshold' => 100, 'percent' => 5,  'label' => '5% OFF' ),
				array( 'threshold' => 200, 'percent' => 10, 'label' => '10% OFF' ),
			),
			'progress_label'          => 'Cart Progress',
			'progress_hint'           => 'Spend %s more to unlock %s',
			'unlocked_label'          => 'You unlocked all rewards!',
			'use_base_price'          => 'yes',
			'bar_color'               => '#e91e63',
			'bar_bg'                  => '#eeeeee',
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$out = wp_parse_args( $saved, self::defaults() );
		if ( ! is_array( $out['tiers'] ) ) {
			$out['tiers'] = array();
		}
		return $out;
	}

	public function admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Cart Progress', 'woo-side-cart' ),
			__( 'Cart Progress', 'woo-side-cart' ),
			'manage_woocommerce',
			'wsc-cart-progress',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'wsc_settings_group', self::OPTION_KEY, array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {

		$d = self::defaults();
		$out = array();

		$out['progress_enabled']        = ( isset( $input['progress_enabled'] ) && 'yes' === $input['progress_enabled'] ) ? 'yes' : 'no';
		$out['progress_placement']      = in_array( ( $input['progress_placement'] ?? 'both' ), array( 'both', 'cart', 'side' ), true ) ? $input['progress_placement'] : 'both';
		$out['min_checkout_enabled']    = ( isset( $input['min_checkout_enabled'] ) && 'yes' === $input['min_checkout_enabled'] ) ? 'yes' : 'no';
		$out['min_checkout_amount']     = isset( $input['min_checkout_amount'] ) ? (float) $input['min_checkout_amount'] : $d['min_checkout_amount'];
		$out['min_checkout_message']    = sanitize_text_field( $input['min_checkout_message'] ?? $d['min_checkout_message'] );
		$out['free_shipping_enabled']   = ( isset( $input['free_shipping_enabled'] ) && 'yes' === $input['free_shipping_enabled'] ) ? 'yes' : 'no';
		$out['free_shipping_threshold'] = isset( $input['free_shipping_threshold'] ) ? (float) $input['free_shipping_threshold'] : $d['free_shipping_threshold'];
		$out['free_shipping_label']     = sanitize_text_field( $input['free_shipping_label'] ?? $d['free_shipping_label'] );
		$out['progress_label']          = sanitize_text_field( $input['progress_label'] ?? $d['progress_label'] );
		$out['progress_hint']           = sanitize_text_field( $input['progress_hint'] ?? $d['progress_hint'] );
		$out['unlocked_label']          = sanitize_text_field( $input['unlocked_label'] ?? $d['unlocked_label'] );
		$out['use_base_price']          = ( isset( $input['use_base_price'] ) && 'yes' === $input['use_base_price'] ) ? 'yes' : 'no';
		$out['bar_color']               = sanitize_hex_color( $input['bar_color'] ?? $d['bar_color'] );
		$out['bar_bg']                  = sanitize_hex_color( $input['bar_bg'] ?? $d['bar_bg'] );

		$out['tiers'] = array();
		if ( ! empty( $input['tiers'] ) && is_array( $input['tiers'] ) ) {
			foreach ( $input['tiers'] as $row ) {
				$threshold = isset( $row['threshold'] ) ? (float) $row['threshold'] : 0;
				$percent   = isset( $row['percent'] ) ? (float) $row['percent'] : 0;
				if ( $threshold <= 0 || $percent <= 0 ) {
					continue;
				}
				$out['tiers'][] = array(
					'threshold' => $threshold,
					'percent'   => $percent,
					'label'     => sanitize_text_field( $row['label'] ?? '' ),
				);
			}
		}

		usort( $out['tiers'], function ( $a, $b ) {
			return $a['threshold'] <=> $b['threshold'];
		} );

		return $out;
	}

	public function admin_assets( $hook ) {
		if ( $hook !== 'woocommerce_page_wsc-cart-progress' ) {
			return;
		}
		wp_enqueue_script(
			'wsc-admin',
			plugin_dir_url( __FILE__ ) . 'assets/woo-side-cart-admin.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);
	}

	public function render_settings_page() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s   = self::settings();
		$cur = get_woocommerce_currency_symbol();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cart Progress', 'woo-side-cart' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'wsc_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'General', 'woo-side-cart' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable progress bar', 'woo-side-cart' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[progress_enabled]" value="yes" <?php checked( $s['progress_enabled'], 'yes' ); ?> />
								<?php esc_html_e( 'Show cart progress bar', 'woo-side-cart' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Placement', 'woo-side-cart' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[progress_placement]">
								<option value="both" <?php selected( $s['progress_placement'], 'both' ); ?>><?php esc_html_e( 'Cart page + Side cart', 'woo-side-cart' ); ?></option>
								<option value="cart" <?php selected( $s['progress_placement'], 'cart' ); ?>><?php esc_html_e( 'Cart page only', 'woo-side-cart' ); ?></option>
								<option value="side" <?php selected( $s['progress_placement'], 'side' ); ?>><?php esc_html_e( 'Side cart only', 'woo-side-cart' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Title', 'woo-side-cart' ); ?></th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[progress_label]" value="<?php echo esc_attr( $s['progress_label'] ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Hint (progress)', 'woo-side-cart' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[progress_hint]" value="<?php echo esc_attr( $s['progress_hint'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Use %s for the missing amount and %s for the reward label. Example: "Spend %s more to unlock %s".', 'woo-side-cart' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Unlocked message', 'woo-side-cart' ); ?></th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[unlocked_label]" value="<?php echo esc_attr( $s['unlocked_label'] ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Bar colors', 'woo-side-cart' ); ?></th>
						<td>
							<input type="color" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bar_color]" value="<?php echo esc_attr( $s['bar_color'] ); ?>" />
							<input type="color" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bar_bg]" value="<?php echo esc_attr( $s['bar_bg'] ); ?>" />
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Minimum value for checkout', 'woo-side-cart' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable', 'woo-side-cart' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_checkout_enabled]" value="yes" <?php checked( $s['min_checkout_enabled'], 'yes' ); ?> />
								<?php esc_html_e( 'Block checkout below the minimum amount', 'woo-side-cart' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Minimum amount', 'woo-side-cart' ); ?></th>
						<td>
							<?php echo esc_html( $cur ); ?>
							<input type="number" step="0.01" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_checkout_amount]" value="<?php echo esc_attr( $s['min_checkout_amount'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Message', 'woo-side-cart' ); ?></th>
						<td>
							<input type="text" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_checkout_message]" value="<?php echo esc_attr( $s['min_checkout_message'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Use %s for the missing amount.', 'woo-side-cart' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Free shipping', 'woo-side-cart' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable', 'woo-side-cart' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[free_shipping_enabled]" value="yes" <?php checked( $s['free_shipping_enabled'], 'yes' ); ?> />
								<?php esc_html_e( 'Unlock free shipping above threshold', 'woo-side-cart' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Note: your shipping zone must contain a "Free shipping" method.', 'woo-side-cart' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Threshold', 'woo-side-cart' ); ?></th>
						<td>
							<?php echo esc_html( $cur ); ?>
							<input type="number" step="0.01" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[free_shipping_threshold]" value="<?php echo esc_attr( $s['free_shipping_threshold'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Label', 'woo-side-cart' ); ?></th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[free_shipping_label]" value="<?php echo esc_attr( $s['free_shipping_label'] ); ?>" /></td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Tiered % discounts', 'woo-side-cart' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Unlimited tiers. Each tier unlocks when the cart subtotal reaches the threshold. Discounts are applied automatically as a negative fee at checkout.', 'woo-side-cart' ); ?></p>
				<table class="widefat striped" id="wsc-tiers-table">
					<thead>
						<tr>
							<th style="width:25%"><?php esc_html_e( 'Cart subtotal ≥', 'woo-side-cart' ); ?></th>
							<th style="width:15%"><?php esc_html_e( '% off', 'woo-side-cart' ); ?></th>
							<th><?php esc_html_e( 'Label', 'woo-side-cart' ); ?></th>
							<th style="width:60px"></th>
						</tr>
					</thead>
					<tbody id="wsc-tiers-body">
						<?php foreach ( $s['tiers'] as $i => $tier ) : ?>
						<tr>
							<td>
								<?php echo esc_html( $cur ); ?>
								<input type="number" step="0.01" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tiers][<?php echo (int) $i; ?>][threshold]" value="<?php echo esc_attr( $tier['threshold'] ); ?>" />
							</td>
							<td>
								<input type="number" step="0.01" min="0" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tiers][<?php echo (int) $i; ?>][percent]" value="<?php echo esc_attr( $tier['percent'] ); ?>" /> %
							</td>
							<td>
								<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tiers][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $tier['label'] ); ?>" />
							</td>
							<td><button type="button" class="button wsc-remove-tier">×</button></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button button-secondary" id="wsc-add-tier">+ <?php esc_html_e( 'Add tier', 'woo-side-cart' ); ?></button></p>

				<h2 class="title"><?php esc_html_e( 'Discount calculation', 'woo-side-cart' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Base', 'woo-side-cart' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[use_base_price]" value="yes" <?php checked( $s['use_base_price'], 'yes' ); ?> />
								<?php esc_html_e( 'Apply discount to cart subtotal (before tax & other fees). Otherwise applied to the discountable total.', 'woo-side-cart' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ==================================================================
	 * Front assets
	 * ================================================================== */
	public function woocommerce_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p><strong>Woo Side Cart</strong> requires WooCommerce to be installed and activated.</p></div>';
		}
	}

	public function enqueue_assets() {

		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'woo-side-cart',
			plugin_dir_url( __FILE__ ) . 'assets/woo-side-cart.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'woo-side-cart',
			plugin_dir_url( __FILE__ ) . 'assets/woo-side-cart.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);

		$s = self::settings();

		wp_localize_script(
			'woo-side-cart',
			'WooSideCart',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'cartUrl'     => wc_get_cart_url(),
				'checkoutUrl' => wc_get_checkout_url(),
				'nonce'       => wp_create_nonce( 'woo_side_cart_nonce' ),
				'isCartPage'  => is_cart(),
				'isCheckout'  => is_checkout(),
				'reloadCart'  => (
					isset( $_GET['wsc_reload_cart'] ) &&
					'1' === sanitize_text_field( wp_unslash( $_GET['wsc_reload_cart'] ) )
				),
				'openOnLoad'  => (
					isset( $_GET['woo_side_cart_open'] ) &&
					'1' === sanitize_text_field( wp_unslash( $_GET['woo_side_cart_open'] ) )
				),

				'progress' => array(
					'enabled'     => 'yes' === $s['progress_enabled'],
					'minEnabled'  => 'yes' === $s['min_checkout_enabled'],
					'minAmount'   => (float) $s['min_checkout_amount'],
					'minMessage'  => $s['min_checkout_message'],
				),

				'strings' => array(
					'loading' => __( 'Updating cart…', 'woo-side-cart' ),
					'error'   => __( 'Could not update the cart. Please try again.', 'woo-side-cart' ),
					'minBlock' => __( 'Please add more items to reach the minimum checkout amount.', 'woo-side-cart' ),
				),
			)
		);
	}

	
	/** cart progress */
		public function render_progress_html() {

		$s = self::settings();
		if ( 'yes' !== $s['progress_enabled'] || ! WC()->cart ) {
			return '';
		}

		$subtotal = (float) WC()->cart->get_subtotal();
		
		// Prevent zero-value issues if cart is completely empty
		if ( $subtotal <= 0 ) {
			$subtotal = 0;
		}

		$currency = get_woocommerce_currency_symbol();

		$nodes = array();

		if ( 'yes' === $s['min_checkout_enabled'] && $s['min_checkout_amount'] > 0 ) {
			$nodes[] = array(
				'type'      => 'min',
				'threshold' => (float) $s['min_checkout_amount'],
				'label'     => sprintf(
					__( 'Min. %s to Checkout', 'woo-side-cart' ),
					wc_price( $s['min_checkout_amount'] )
				),
				'reward'    => __( 'Checkout enabled', 'woo-side-cart' ),
			);
		}

		foreach ( $s['tiers'] as $tier ) {
			$label = $tier['label'] ? $tier['label'] : sprintf( '%s%% OFF', rtrim( rtrim( number_format( $tier['percent'], 2, '.', '' ), '0' ), '.' ) );
			$nodes[] = array(
				'type'      => 'tier',
				'threshold' => (float) $tier['threshold'],
				'percent'   => (float) $tier['percent'],
				'label'     => wc_price( $tier['threshold'] ),
				'reward'    => $label,
			);
		}

		if ( 'yes' === $s['free_shipping_enabled'] && $s['free_shipping_threshold'] > 0 ) {
			$nodes[] = array(
				'type'      => 'shipping',
				'threshold' => (float) $s['free_shipping_threshold'],
				'label'     => wc_price( $s['free_shipping_threshold'] ),
				'reward'    => $s['free_shipping_label'],
			);
		}

		if ( empty( $nodes ) ) {
			return '';
		}

		usort( $nodes, function ( $a, $b ) {
			return $a['threshold'] <=> $b['threshold'];
		} );

		$total_nodes = count( $nodes );
		$fill_percent = 0;

		// Safe calculation for progress
		if ( $subtotal > 0 ) {
			if ( $total_nodes === 1 ) {
				$fill_percent = ( $subtotal >= $nodes[0]['threshold'] ) ? 100 : 0;
			} else {
				$step = 100 / ( $total_nodes - 1 );
				$last_index = 0;

				foreach ( $nodes as $index => $n ) {
					if ( $subtotal >= $n['threshold'] ) {
						$last_index = $index;
					}
				}

				if ( $last_index === ( $total_nodes - 1 ) ) {
					$fill_percent = 100;
				} else {
					$current_threshold = $nodes[ $last_index ]['threshold'];
					$next_threshold    = $nodes[ $last_index + 1 ]['threshold'];

					$interval = $next_threshold - $current_threshold;
					$progress_in_interval = 0;
					if ( $interval > 0 ) {
						$progress_in_interval = ( $subtotal - $current_threshold ) / $interval;
					}

					$base_position = $last_index * $step;
					$gap_position  = $progress_in_interval * $step;

					$fill_percent = $base_position + $gap_position;
				}
			}
		}
		
		// Ensure it never exceeds 100 or drops below 0
		$fill_percent = max( 0, min( 100, $fill_percent ) );

		$next = null;
		foreach ( $nodes as $n ) {
			if ( $subtotal < $n['threshold'] ) {
				$next = $n;
				break;
			}
		}

		ob_start();
		?>
		<div class="wsc-progress"
			data-subtotal="<?php echo esc_attr( $subtotal ); ?>"
			data-max="<?php echo esc_attr( end( $nodes )['threshold'] ); ?>"
			data-bar-color="<?php echo esc_attr( $s['bar_color'] ); ?>"
			data-bar-bg="<?php echo esc_attr( $s['bar_bg'] ); ?>"
			data-hint="<?php echo esc_attr( $s['progress_hint'] ); ?>"
			data-unlocked="<?php echo esc_attr( $s['unlocked_label'] ); ?>"
		>
			<div class="wsc-progress-head">
				<span class="wsc-progress-title">🎁 <?php echo esc_html( $s['progress_label'] ); ?></span>
				<span class="wsc-progress-total"><?php esc_html_e( 'Total', 'woo-side-cart' ); ?> <strong><?php echo wp_kses_post( wc_price( $subtotal ) ); ?></strong></span>
			</div>

			<div class="wsc-progress-grid" style="--wsc-total-items: <?php echo count( $nodes ); ?>;">
				<!-- Background Track Line -->
				<div class="wsc-progress-bg-track" style="background:<?php echo esc_attr( $s['bar_bg'] ); ?>;">
					<div class="wsc-progress-fill" style="width:<?php echo esc_attr( $fill_percent ); ?>%; background:<?php echo esc_attr( $s['bar_color'] ); ?>;"></div>
				</div>

				<!-- Columns containing both Dots and Text -->
				<div class="wsc-progress-marks">
					<?php foreach ( $nodes as $n ) :
						$done = $subtotal >= $n['threshold'];
						?>
						<div class="wsc-progress-mark <?php echo $done ? 'is-done' : ''; ?>">
							<span class="wsc-progress-tick"></span>
							<span class="wsc-progress-mark-amount"><?php echo wp_kses_post( $n['label'] ); ?></span>
							<span class="wsc-progress-mark-label"><?php echo $done ? '✓ ' : ''; ?><?php echo esc_html( $n['reward'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="wsc-progress-hint">
				<?php if ( $next ) :
					$missing = max( 0, $next['threshold'] - $subtotal );
					echo wp_kses_post(
						sprintf(
							$s['progress_hint'],
							'<strong>' . wc_price( $missing ) . '</strong>',
							'<strong>' . esc_html( $next['reward'] ) . '</strong>'
						)
					);
				else :
					echo '<strong>' . esc_html( $s['unlocked_label'] ) . '</strong>';
				endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}


	public function render_progress_cart_page() {
		$s = self::settings();
		if ( 'cart' !== $s['progress_placement'] && 'both' !== $s['progress_placement'] ) {
			return;
		}
		echo $this->render_progress_html(); // phpcs:ignore
	}

	/* ==================================================================
	 * Drawer
	 * ================================================================== */
	public function render_drawer() {

		if ( is_admin() ) {
			return;
		}

		$s          = self::settings();
		$show_side  = ( 'side' === $s['progress_placement'] || 'both' === $s['progress_placement'] );
		$subtotal   = WC()->cart ? (float) WC()->cart->get_subtotal() : 0;
		$min_amount = ( 'yes' === $s['min_checkout_enabled'] ) ? (float) $s['min_checkout_amount'] : 0;
		$min_reached = ( $subtotal >= $min_amount ) ? true : false;
		?>
		<div class="wsc-overlay" data-wsc-close tabindex="-1" aria-hidden="true"></div>

		<aside class="wsc-drawer" id="woo-side-cart" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Shopping cart', 'woo-side-cart' ); ?>" aria-hidden="true">

			<div class="wsc-header">
				<div>
					<h2><?php esc_html_e( 'Shopping Cart', 'woo-side-cart' ); ?></h2>
					<div class="wsc-item-count"><?php echo esc_html( $this->item_count_text() ); ?></div>
				</div>
				<button class="wsc-close" type="button" aria-label="<?php esc_attr_e( 'Close cart', 'woo-side-cart' ); ?>" data-wsc-close>&times;</button>
			</div>

			<div class="wsc-body">
				<div class="wsc-notice" role="status" aria-live="polite"></div>

				<?php if ( $show_side ) : ?>
					<div class="wsc-progress-slot">
						<?php echo $this->render_progress_html(); // phpcs:ignore ?>
					</div>
				<?php endif; ?>

				<div class="wsc-items"><?php $this->render_cart_items(); ?></div>
			</div>

			<div class="wsc-footer">
				<div class="wsc-subtotal">
					<strong>
						<?php esc_html_e( 'Subtotal', 'woo-side-cart' ); ?>
						( <span class="wsc-subtotal-count"><?php echo esc_html( WC()->cart->get_cart_contents_count() ); ?></span>
						<?php esc_html_e( 'item(s)', 'woo-side-cart' ); ?> )
					</strong>
					<strong class="wsc-subtotal-price"><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></strong>
				</div>

				<p class="wsc-footnote"><?php esc_html_e( 'Shipping, taxes, coupons, and discounts are calculated at checkout.', 'woo-side-cart' ); ?></p>

				<div class="wsc-actions">
					<a class="wsc-view-cart" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'View Cart', 'woo-side-cart' ); ?></a>
					<a class="wsc-checkout <?php echo ! $min_reached ? 'wsc-min-not-met' : ''; ?>" href="<?php echo esc_url( wc_get_checkout_url() ); ?>" data-wsc-checkout>
						<span class="wsc-checkout-text">
							<?php 
							if ( ! $min_reached ) {
								printf( esc_html__( 'Minimum %s to Checkout', 'woo-side-cart' ), wp_kses_post( wc_price( $min_amount ) ) );
							} else {
								esc_html_e( 'Checkout', 'woo-side-cart' );
							}
							?>
						</span>
					</a>
				</div>
			</div>
		</aside>

		<button class="wsc-floating-button" type="button" data-wsc-open aria-label="Open shopping cart">
			<span class="wsc-floating-icon">🛒</span>
			<span class="wsc-floating-label">Cart</span>
			<span class="wsc-floating-count"><?php echo esc_html( WC()->cart->get_cart_contents_count() ); ?></span>
		</button>
		<?php
	}

	private function item_count_text() {
		$count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
		return sprintf( _n( '%d item', '%d items', $count, 'woo-side-cart' ), $count );
	}

	private function render_cart_items() {

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			echo '<div class="wsc-empty"><p>' . esc_html__( 'Your cart is currently empty.', 'woo-side-cart' ) . '</p>';
			echo '<a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Continue Shopping', 'woo-side-cart' ) . '</a></div>';
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

			$product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

			if ( ! $product || ! $product->exists() || $cart_item['quantity'] <= 0 ) {
				continue;
			}

			$product_name      = $product->get_name();
			$product_permalink = $product->is_visible() ? $product->get_permalink( $cart_item ) : '';
			$thumbnail         = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'wsc-product-image' ) );
			$qty               = (int) $cart_item['quantity'];
			?>
			<div class="wsc-item" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>">
				<button class="wsc-remove" type="button" data-wsc-remove="<?php echo esc_attr( $cart_item_key ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s from cart', 'woo-side-cart' ), $product_name ) ); ?>">&times;</button>
				<div class="wsc-product-thumb">
					<?php if ( $product_permalink ) : ?><a href="<?php echo esc_url( $product_permalink ); ?>"><?php endif; ?>
					<?php echo wp_kses_post( $thumbnail ); ?>
					<?php if ( $product_permalink ) : ?></a><?php endif; ?>
				</div>
				<div class="wsc-product-info">
					<div class="wsc-product-name">
						<?php if ( $product_permalink ) : ?>
							<a href="<?php echo esc_url( $product_permalink ); ?>"><?php echo esc_html( $product_name ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $product_name ); ?>
						<?php endif; ?>
					</div>
					<div class="wsc-variation"><?php echo wp_kses_post( wc_get_formatted_cart_item_data( $cart_item ) ); ?></div>
					<div class="wsc-item-bottom">
						<div class="wsc-quantity">
							<button type="button" data-wsc-qty="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'woo-side-cart' ); ?>">−</button>
							<span><?php echo esc_html( $qty ); ?></span>
							<button type="button" data-wsc-qty="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'woo-side-cart' ); ?>">+</button>
						</div>
						<div class="wsc-line-price"><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $qty ) ); ?></div>
					</div>
				</div>
			</div>
			<?php
		}
	}

	/* ==================================================================
	 * AJAX fragments
	 * ================================================================== */
	private function build_fragments_response() {

		$s = self::settings();
		$show_side = ( 'side' === $s['progress_placement'] || 'both' === $s['progress_placement'] );
		$subtotal  = (float) WC()->cart->get_subtotal();
		$min_amount = ( 'yes' === $s['min_checkout_enabled'] ) ? (float) $s['min_checkout_amount'] : 0;
		$min_reached = ( $subtotal >= $min_amount ) ? true : false;

		ob_start();
		echo '<div class="wsc-items">';
		$this->render_cart_items();
		echo '</div>';
		$items_html = ob_get_clean();

		ob_start();
		echo '<div class="wsc-item-count">' . esc_html( $this->item_count_text() ) . '</div>';
		$count_html = ob_get_clean();

		ob_start();
		echo '<span class="wsc-subtotal-count">' . esc_html( WC()->cart->get_cart_contents_count() ) . '</span>';
		$subtotal_count_html = ob_get_clean();

		ob_start();
		echo '<strong class="wsc-subtotal-price">' . wp_kses_post( WC()->cart->get_cart_subtotal() ) . '</strong>';
		$subtotal_html = ob_get_clean();

		$floating_count_html = '<span class="wsc-floating-count">' . esc_html( WC()->cart->get_cart_contents_count() ) . '</span>';

		ob_start();
		?>
		<a class="wsc-checkout <?php echo ! $min_reached ? 'wsc-min-not-met' : ''; ?>" href="<?php echo esc_url( wc_get_checkout_url() ); ?>" data-wsc-checkout>
			<span class="wsc-checkout-text">
				<?php 
				if ( ! $min_reached ) {
					printf( esc_html__( 'Minimum %s to Checkout', 'woo-side-cart' ), wp_kses_post( wc_price( $min_amount ) ) );
				} else {
					esc_html_e( 'Checkout', 'woo-side-cart' );
				}
				?>
			</span>
		</a>
		<?php
		$checkout_btn_html = ob_get_clean();

		$fragments = array(
			'.wsc-items'                  => $items_html,
			'.wsc-header .wsc-item-count' => $count_html,
			'.wsc-subtotal-count'         => $subtotal_count_html,
			'.wsc-subtotal-price'         => $subtotal_html,
			'.wsc-floating-count'         => $floating_count_html,
			'.wsc-checkout'               => $checkout_btn_html,
		);

		if ( $show_side ) {
			$fragments['.wsc-progress-slot'] = '<div class="wsc-progress-slot">' . $this->render_progress_html() . '</div>';
		}

		return array(
			'fragments' => $fragments,
			'empty'     => WC()->cart->is_empty(),
		);
	}

	public function ajax_refresh_cart() {

		check_ajax_referer( 'woo_side_cart_nonce', 'nonce' );

		if ( ! WC()->cart ) {
			wp_send_json_error( array( 'message' => 'WooCommerce cart is unavailable.' ) );
		}

		WC()->cart->calculate_totals();
		wp_send_json_success( $this->build_fragments_response() );
	}

	public function cart_fragments( $fragments ) {
		$resp = $this->build_fragments_response();
		return array_merge( $fragments, $resp['fragments'] );
	}

	public function ajax_update_item() {

		check_ajax_referer( 'woo_side_cart_nonce', 'nonce' );

		if ( ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart unavailable.', 'woo-side-cart' ) ) );
		}

		$cart_key = isset( $_POST['cart_key'] ) ? wc_clean( wp_unslash( $_POST['cart_key'] ) ) : '';
		$quantity = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : -1;

		if ( $quantity < 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid quantity.', 'woo-side-cart' ) ) );
		}

		$cart = WC()->cart->get_cart();

		if ( empty( $cart_key ) || ! isset( $cart[ $cart_key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'This cart item could not be found.', 'woo-side-cart' ) ) );
		}

		if ( 0 === $quantity ) {
			WC()->cart->remove_cart_item( $cart_key );
		} else {
			WC()->cart->set_quantity( $cart_key, $quantity, true );
		}

		WC()->cart->calculate_totals();
		wp_send_json_success( $this->build_fragments_response() );
	}

	/* ==================================================================
	 * Redirects / cookie
	 * ================================================================== */
	public function redirect_back_to_product( $url, $adding_to_cart ) {

		if ( isset( $_REQUEST['wsc_no_redirect'] ) ) {
			return $url;
		}

		$referrer = wp_get_referer();
		if ( $referrer && false === strpos( $referrer, 'wp-admin' ) && false === strpos( $referrer, 'order-received' ) ) {
			return add_query_arg( 'woo_side_cart_open', '1', remove_query_arg( 'added-to-cart', $referrer ) );
		}

		return $url;
	}

	public function set_open_cookie( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( ! wp_doing_ajax() && ! headers_sent() ) {
			setcookie(
				'woo_side_cart_open',
				'1',
				time() + 90,
				COOKIEPATH ? COOKIEPATH : '/',
				COOKIE_DOMAIN,
				is_ssl(),
				true
			);
		}
	}

	/* ==================================================================
	 * Tiered discount + free shipping
	 * ================================================================== */
	public function apply_tiered_discount( $cart ) {

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$s = self::settings();
		if ( 'yes' !== $s['progress_enabled'] || empty( $s['tiers'] ) ) {
			return;
		}

		$subtotal = (float) $cart->get_subtotal();

		$best = null;
		foreach ( $s['tiers'] as $tier ) {
			if ( $subtotal >= (float) $tier['threshold'] ) {
				$best = $tier;
			}
		}

		if ( ! $best ) {
			return;
		}

		$base = 'yes' === $s['use_base_price']
			? $subtotal
			: (float) $cart->get_discount_total() + $subtotal;

		$discount = round( $base * ( (float) $best['percent'] / 100 ), wc_get_price_decimals() );

		if ( $discount > 0 ) {
			$label = $best['label'] ? $best['label'] : sprintf( '%s%% OFF', rtrim( rtrim( number_format( (float) $best['percent'], 2, '.', '' ), '0' ), '.' ) );
			$cart->add_fee( sprintf( __( 'Cart discount (%s)', 'woo-side-cart' ), $label ), -$discount, false );
		}
	}

	public function filter_free_shipping( $is_available, $package, $method = null ) {

		$s = self::settings();
		if ( 'yes' !== $s['free_shipping_enabled'] || 'yes' !== $s['progress_enabled'] ) {
			return $is_available;
		}

		$threshold = (float) $s['free_shipping_threshold'];
		$subtotal  = WC()->cart ? (float) WC()->cart->get_subtotal() : 0;

		if ( $subtotal < $threshold ) {
			return false;
		}

		return $is_available;
	}

	/* ==================================================================
	 * Checkout gating
	 * ================================================================== */
	public function gate_checkout() {

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$s = self::settings();
		if ( 'yes' !== $s['min_checkout_enabled'] || ! WC()->cart ) {
			return;
		}

		$subtotal = (float) WC()->cart->get_subtotal();
		if ( $subtotal < (float) $s['min_checkout_amount'] ) {
			wc_add_notice( sprintf( $s['min_checkout_message'], wc_price( $s['min_checkout_amount'] - $subtotal ) ), 'error' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	}

	public function validate_min_cart_at_checkout() {

		$s = self::settings();
		if ( 'yes' !== $s['min_checkout_enabled'] || ! WC()->cart ) {
			return;
		}

		$subtotal = (float) WC()->cart->get_subtotal();
		if ( $subtotal < (float) $s['min_checkout_amount'] ) {
			wc_add_notice(
				sprintf( $s['min_checkout_message'], wc_price( $s['min_checkout_amount'] - $subtotal ) ),
				'error'
			);
		}
	}
}

new Woo_Side_Cart();

?>