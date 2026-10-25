<?php
/**
 * Plugin Name: Woo Side Cart
 * Description: A responsive floating side cart drawer for WooCommerce. Opens automatically when a product is added to the cart.
 * Version: 1.0.3
 * Author: Shimanta Das
 * Text Domain: woo-side-cart
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Woo_Side_Cart {

    const VERSION = '1.0.3';

    public function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function init() {

        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) );
            return;
        }

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'render_drawer' ) );

        add_filter(
            'woocommerce_add_to_cart_redirect',
            array( $this, 'redirect_back_to_product' ),
            20,
            2
        );

        add_filter(
            'woocommerce_add_to_cart_fragments',
            array( $this, 'cart_fragments' )
        );

        add_action(
            'woocommerce_add_to_cart',
            array( $this, 'set_open_cookie' ),
            10,
            6
        );

        add_action(
            'wp_ajax_woo_side_cart_update_item',
            array( $this, 'ajax_update_item' )
        );

        add_action(
            'wp_ajax_nopriv_woo_side_cart_update_item',
            array( $this, 'ajax_update_item' )
        );

        add_action(
            'wp_ajax_woo_side_cart_refresh',
            array( $this, 'ajax_refresh_cart' )
        );

        add_action(
            'wp_ajax_nopriv_woo_side_cart_refresh',
            array( $this, 'ajax_refresh_cart' )
        );
    }

    public function woocommerce_notice() {

        if ( current_user_can( 'activate_plugins' ) ) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>Woo Side Cart</strong> requires WooCommerce to be installed and activated.';
            echo '</p></div>';
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

        wp_localize_script(
            'woo-side-cart',
            'WooSideCart',
            array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'cartUrl'     => wc_get_cart_url(),
                'checkoutUrl' => wc_get_checkout_url(),
                'nonce'       => wp_create_nonce( 'woo_side_cart_nonce' ),

                // Cart-page detection.
                'isCartPage'  => is_cart(),

                'reloadCart'  => (
                    isset( $_GET['wsc_reload_cart'] )
                    && '1' === sanitize_text_field(
                        wp_unslash( $_GET['wsc_reload_cart'] )
                    )
                ),

                'openOnLoad' => (
                    isset( $_GET['woo_side_cart_open'] )
                    && '1' === sanitize_text_field(
                        wp_unslash( $_GET['woo_side_cart_open'] )
                    )
                ),

                'strings' => array(
                    'loading' => __( 'Updating cart…', 'woo-side-cart' ),
                    'error'   => __(
                        'Could not update the cart. Please try again.',
                        'woo-side-cart'
                    ),
                ),
            )
        );
    }

    public function ajax_refresh_cart() {

        check_ajax_referer( 'woo_side_cart_nonce', 'nonce' );

        if ( ! WC()->cart ) {
            wp_send_json_error(
                array(
                    'message' => 'WooCommerce cart is unavailable.',
                )
            );
        }

        WC()->cart->calculate_totals();

        wp_send_json_success( $this->build_fragments_response() );
    }

    public function render_drawer() {

        if ( is_admin() ) {
            return;
        }

        ?>

        <div
            class="wsc-overlay"
            data-wsc-close
            tabindex="-1"
            aria-hidden="true"
        ></div>

        <aside
            class="wsc-drawer"
            id="woo-side-cart"
            role="dialog"
            aria-modal="true"
            aria-label="<?php esc_attr_e( 'Shopping cart', 'woo-side-cart' ); ?>"
            aria-hidden="true"
        >

            <div class="wsc-header">
                <div>
                    <h2><?php esc_html_e( 'Shopping Cart', 'woo-side-cart' ); ?></h2>
                    <div class="wsc-item-count">
                        <?php echo esc_html( $this->item_count_text() ); ?>
                    </div>
                </div>

                <button
                    class="wsc-close"
                    type="button"
                    aria-label="<?php esc_attr_e( 'Close cart', 'woo-side-cart' ); ?>"
                    data-wsc-close
                >&times;</button>
            </div>

            <div class="wsc-body">
                <div class="wsc-notice" role="status" aria-live="polite"></div>
                <div class="wsc-items">
                    <?php $this->render_cart_items(); ?>
                </div>
            </div>

            <div class="wsc-footer">
                <div class="wsc-subtotal">
                    <strong>
                        <?php esc_html_e( 'Subtotal', 'woo-side-cart' ); ?>
                        (
                        <span class="wsc-subtotal-count">
                            <?php echo esc_html( WC()->cart->get_cart_contents_count() ); ?>
                        </span>
                        <?php esc_html_e( 'item(s)', 'woo-side-cart' ); ?>
                        )
                    </strong>

                    <strong class="wsc-subtotal-price">
                        <?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?>
                    </strong>
                </div>

                <p class="wsc-footnote">
                    <?php
                    esc_html_e(
                        'Shipping, taxes, coupons, and discounts are calculated at checkout.',
                        'woo-side-cart'
                    );
                    ?>
                </p>

                <div class="wsc-actions">
                    <a class="wsc-view-cart" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
                        <?php esc_html_e( 'View Cart', 'woo-side-cart' ); ?>
                    </a>
                    <a class="wsc-checkout" href="<?php echo esc_url( wc_get_checkout_url() ); ?>">
                        <?php esc_html_e( 'Checkout', 'woo-side-cart' ); ?>
                    </a>
                </div>
            </div>

        </aside>

        <button
            class="wsc-floating-button"
            type="button"
            data-wsc-open
            aria-label="Open shopping cart"
        >
            <span class="wsc-floating-icon">🛒</span>
            <span class="wsc-floating-label">Cart</span>
            <span class="wsc-floating-count">
                <?php echo esc_html( WC()->cart->get_cart_contents_count() ); ?>
            </span>
        </button>

        <?php
    }

    private function item_count_text() {

        $count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;

        return sprintf(
            _n( '%d item', '%d items', $count, 'woo-side-cart' ),
            $count
        );
    }

    private function render_cart_items() {

        if ( ! WC()->cart || WC()->cart->is_empty() ) {

            echo '<div class="wsc-empty">';
            echo '<p>';
            esc_html_e( 'Your cart is currently empty.', 'woo-side-cart' );
            echo '</p>';
            echo '<a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">';
            esc_html_e( 'Continue Shopping', 'woo-side-cart' );
            echo '</a>';
            echo '</div>';

            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

            $product = apply_filters(
                'woocommerce_cart_item_product',
                $cart_item['data'],
                $cart_item,
                $cart_item_key
            );

            if ( ! $product || ! $product->exists() || $cart_item['quantity'] <= 0 ) {
                continue;
            }

            $product_name = $product->get_name();

            $product_permalink = $product->is_visible()
                ? $product->get_permalink( $cart_item )
                : '';

            $thumbnail = $product->get_image(
                'woocommerce_thumbnail',
                array( 'class' => 'wsc-product-image' )
            );

            $qty = (int) $cart_item['quantity'];

            ?>

            <div class="wsc-item" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>">

                <button
                    class="wsc-remove"
                    type="button"
                    data-wsc-remove="<?php echo esc_attr( $cart_item_key ); ?>"
                    aria-label="<?php
                    echo esc_attr(
                        sprintf(
                            __( 'Remove %s from cart', 'woo-side-cart' ),
                            $product_name
                        )
                    );
                    ?>"
                >&times;</button>

                <div class="wsc-product-thumb">
                    <?php if ( $product_permalink ) : ?>
                        <a href="<?php echo esc_url( $product_permalink ); ?>">
                    <?php endif; ?>
                    <?php echo wp_kses_post( $thumbnail ); ?>
                    <?php if ( $product_permalink ) : ?>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="wsc-product-info">

                    <div class="wsc-product-name">
                        <?php if ( $product_permalink ) : ?>
                            <a href="<?php echo esc_url( $product_permalink ); ?>">
                                <?php echo esc_html( $product_name ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( $product_name ); ?>
                        <?php endif; ?>
                    </div>

                    <div class="wsc-variation">
                        <?php echo wp_kses_post( wc_get_formatted_cart_item_data( $cart_item ) ); ?>
                    </div>

                    <div class="wsc-item-bottom">

                        <div class="wsc-quantity">
                            <button
                                type="button"
                                data-wsc-qty="-1"
                                aria-label="<?php esc_attr_e( 'Decrease quantity', 'woo-side-cart' ); ?>"
                            >−</button>
                            <span><?php echo esc_html( $qty ); ?></span>
                            <button
                                type="button"
                                data-wsc-qty="1"
                                aria-label="<?php esc_attr_e( 'Increase quantity', 'woo-side-cart' ); ?>"
                            >+</button>
                        </div>

                        <div class="wsc-line-price">
                            <?php
                            echo wp_kses_post(
                                WC()->cart->get_product_subtotal( $product, $qty )
                            );
                            ?>
                        </div>

                    </div>

                </div>

            </div>

            <?php
        }
    }

    private function build_fragments_response() {

        ob_start();
        echo '<div class="wsc-items">';
        $this->render_cart_items();
        echo '</div>';
        $items_html = ob_get_clean();

        ob_start();
        echo '<div class="wsc-item-count">';
        echo esc_html( $this->item_count_text() );
        echo '</div>';
        $count_html = ob_get_clean();

        ob_start();
        echo '<span class="wsc-subtotal-count">';
        echo esc_html( WC()->cart->get_cart_contents_count() );
        echo '</span>';
        $subtotal_count_html = ob_get_clean();

        ob_start();
        echo '<strong class="wsc-subtotal-price">';
        echo wp_kses_post( WC()->cart->get_cart_subtotal() );
        echo '</strong>';
        $subtotal_html = ob_get_clean();

        $floating_count_html =
            '<span class="wsc-floating-count">' .
            esc_html( WC()->cart->get_cart_contents_count() ) .
            '</span>';

        return array(
            'fragments' => array(
                '.wsc-items'                  => $items_html,
                '.wsc-header .wsc-item-count' => $count_html,
                '.wsc-subtotal-count'         => $subtotal_count_html,
                '.wsc-subtotal-price'         => $subtotal_html,
                '.wsc-floating-count'         => $floating_count_html,
            ),
            'empty' => WC()->cart->is_empty(),
        );
    }

    public function cart_fragments( $fragments ) {

        ob_start();
        echo '<div class="wsc-item-count">';
        echo esc_html( $this->item_count_text() );
        echo '</div>';
        $fragments['.wsc-header .wsc-item-count'] = ob_get_clean();

        ob_start();
        echo '<div class="wsc-items">';
        $this->render_cart_items();
        echo '</div>';
        $fragments['.wsc-items'] = ob_get_clean();

        ob_start();
        echo '<span class="wsc-subtotal-count">';
        echo esc_html( WC()->cart->get_cart_contents_count() );
        echo '</span>';
        $fragments['.wsc-subtotal-count'] = ob_get_clean();

        ob_start();
        echo '<strong class="wsc-subtotal-price">';
        echo wp_kses_post( WC()->cart->get_cart_subtotal() );
        echo '</strong>';
        $fragments['.wsc-subtotal-price'] = ob_get_clean();

        ob_start();
        echo '<span class="wsc-floating-count">';
        echo esc_html( WC()->cart->get_cart_contents_count() );
        echo '</span>';
        $fragments['.wsc-floating-count'] = ob_get_clean();

        return $fragments;
    }

    public function redirect_back_to_product( $url, $adding_to_cart ) {

        if ( isset( $_REQUEST['wsc_no_redirect'] ) ) {
            return $url;
        }

        $referrer = wp_get_referer();

        if (
            $referrer
            && false === strpos( $referrer, 'wp-admin' )
            && false === strpos( $referrer, 'order-received' )
        ) {
            return add_query_arg(
                'woo_side_cart_open',
                '1',
                remove_query_arg( 'added-to-cart', $referrer )
            );
        }

        return $url;
    }

    public function ajax_update_item() {

        check_ajax_referer( 'woo_side_cart_nonce', 'nonce' );

        if ( ! WC()->cart ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Cart unavailable.', 'woo-side-cart' ),
                )
            );
        }

        $cart_key = isset( $_POST['cart_key'] )
            ? wc_clean( wp_unslash( $_POST['cart_key'] ) )
            : '';

        $quantity = isset( $_POST['quantity'] )
            ? absint( $_POST['quantity'] )
            : -1;

        if ( $quantity < 0 ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid quantity.', 'woo-side-cart' ),
                )
            );
        }

        $cart = WC()->cart->get_cart();

        if ( empty( $cart_key ) || ! isset( $cart[ $cart_key ] ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'This cart item could not be found.', 'woo-side-cart' ),
                )
            );
        }

        if ( 0 === $quantity ) {
            WC()->cart->remove_cart_item( $cart_key );
        } else {
            WC()->cart->set_quantity( $cart_key, $quantity, true );
        }

        WC()->cart->calculate_totals();

        wp_send_json_success( $this->build_fragments_response() );
    }

    public function set_open_cookie(
        $cart_item_key,
        $product_id,
        $quantity,
        $variation_id,
        $variation,
        $cart_item_data
    ) {

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
}

new Woo_Side_Cart();
?>