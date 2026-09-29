<?php
/*
Plugin Name: PKPay+ Secured Checkout
Plugin URI: https://pkpayplus.com/
Description: Secure hosted checkout solution for fast, reliable, and protected online payments with WooCommerce.
Version: 1.0.1
Author: PKPay+ Dev
Author URI: https://www.pkpayplus.com/contact
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: pkpay-secured-checkout
Requires at least: 6.0
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.0
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function pkplus_checkout_get_backend_url() {
    if ( defined( 'PKPLUS_BACKEND_URL' ) && PKPLUS_BACKEND_URL ) {
        return rtrim( trim( (string) PKPLUS_BACKEND_URL ), '/' );
    }
    return 'https://api.pkpayplus.com';
}

function pkplus_checkout_get_iframe_url() {
    if ( defined( 'PKPLUS_IFRAME_URL' ) && PKPLUS_IFRAME_URL ) {
        return rtrim( trim( (string) PKPLUS_IFRAME_URL ), '/' );
    }

    return 'https://pay-inline.pkpayplus.com';
}

function pkplus_checkout_get_redirect_url() {
    if ( defined( 'PKPLUS_REDIRECT_URL' ) && PKPLUS_REDIRECT_URL ) {
        return rtrim( trim( (string) PKPLUS_REDIRECT_URL ), '/' );
    }

    return 'https://pay.pkpayplus.com';
}

/**
 * Check the backend's signed payment-success marker before making another
 * synchronous verification request during the browser return.
 */
function pkplus_checkout_webhook_payment_confirmed( $order, $session_id = '' ) {
    if ( ! $order || ! is_object( $order ) ) {
        return false;
    }

    $webhook_status = sanitize_text_field( (string) $order->get_meta( '_pkplus_webhook_payment_status' ) );
    $webhook_session = sanitize_text_field( (string) $order->get_meta( '_pkplus_webhook_session_id' ) );

    if ( $webhook_status !== 'paid' || $webhook_session === '' || (string) $session_id !== $webhook_session ) {
        return false;
    }

    if ( ! $order->is_paid() ) {
        $order->payment_complete( $webhook_session );
    }

    return true;
}

function pkplus_checkout_send_server_diagnostic( $session_id, $event, $data = array(), $level = 'info', $message = '' ) {
    $session_id = sanitize_text_field( (string) $session_id );
    if ( $session_id === '' ) {
        return;
    }

    $api_url = rtrim( pkplus_checkout_get_backend_url(), '/' )
        . '/client-api/checkout-sessions/' . rawurlencode( $session_id ) . '/diagnostics';

    wp_remote_post( $api_url, array(
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body' => wp_json_encode( array(
            'event' => sanitize_text_field( (string) $event ),
            'level' => in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ? $level : 'info',
            'message' => sanitize_text_field( (string) $message ),
            'data' => is_array( $data ) ? $data : array(),
            'url' => $api_url,
        ) ),
        'timeout' => 5,
        'blocking' => false,
    ) );
}

function pkplus_checkout_default_gateway_settings() {
    return array(
        'enabled'       => 'no',
        'title'         => 'PKPay+ Secured Checkout',
        'description'   => 'Pay with your credit or debit card via our secure payment gateway.',
        'checkout_flow' => 'iframe',
        'merchant_id'   => '',
        'publishable_key' => '',
        'secret_key'    => '',
    );
}

function pkplus_checkout_normalize_gateway_settings( $settings ) {
    $defaults = pkplus_checkout_default_gateway_settings();
    $settings = is_array( $settings ) ? $settings : array();
    $settings = wp_parse_args( $settings, $defaults );

    $settings['enabled'] = ( $settings['enabled'] === 'yes' ) ? 'yes' : 'no';
    $settings['checkout_flow'] = ( $settings['checkout_flow'] === 'redirect' ) ? 'redirect' : 'iframe';
    $settings['title'] = sanitize_text_field( (string) $settings['title'] );
    $settings['description'] = sanitize_textarea_field( (string) $settings['description'] );
    $settings['merchant_id'] = sanitize_text_field( (string) $settings['merchant_id'] );
    $settings['publishable_key'] = sanitize_text_field( (string) $settings['publishable_key'] );
    $settings['secret_key'] = sanitize_text_field( (string) $settings['secret_key'] );

    return $settings;
}

function pkplus_checkout_sanitize_checkout_flow_setting( $value ) {
    return ( sanitize_text_field( (string) $value ) === 'redirect' ) ? 'redirect' : 'iframe';
}

function pkplus_checkout_sanitize_enabled_setting( $value ) {
    return ( sanitize_text_field( (string) $value ) === 'yes' ) ? 'yes' : 'no';
}

function pkplus_checkout_get_effective_setting( $key, $legacy_option, $default = '' ) {
    $wc_settings = get_option( 'woocommerce_pkplus_checkout_client_settings', array() );
    if ( is_array( $wc_settings ) && array_key_exists( $key, $wc_settings ) && $wc_settings[ $key ] !== '' ) {
        return $wc_settings[ $key ];
    }

    return get_option( $legacy_option, $default );
}

function pkplus_checkout_sync_legacy_to_wc_settings() {
    static $in_progress = false;
    if ( $in_progress ) {
        return;
    }
    $in_progress = true;

    $existing = get_option( 'woocommerce_pkplus_checkout_client_settings', array() );
    $existing = is_array( $existing ) ? $existing : array();

    $merged = array_merge(
        $existing,
        array(
            'merchant_id'     => get_option( 'pkplus_client_merchant_id', '' ),
            'publishable_key' => get_option( 'pkplus_client_publishable_key', '' ),
            'secret_key'      => get_option( 'pkplus_client_secret_key', '' ),
            'enabled'         => get_option( 'pkplus_client_enabled', $existing['enabled'] ?? 'no' ),
            'title'           => get_option( 'pkplus_client_title', $existing['title'] ?? 'PKPay+ Secured Checkout' ),
            'description'     => get_option( 'pkplus_client_description', $existing['description'] ?? 'Pay with your credit or debit card via our secure payment gateway.' ),
            'checkout_flow'   => get_option( 'pkplus_client_checkout_flow', $existing['checkout_flow'] ?? 'iframe' ),
        )
    );

    update_option( 'woocommerce_pkplus_checkout_client_settings', pkplus_checkout_normalize_gateway_settings( $merged ) );
    $in_progress = false;
}

function pkplus_checkout_sync_wc_settings_to_legacy( $new_value ) {
    static $in_progress = false;
    if ( $in_progress ) {
        return;
    }
    $in_progress = true;

    $settings = pkplus_checkout_normalize_gateway_settings( $new_value );
    update_option( 'pkplus_client_merchant_id', $settings['merchant_id'] );
    update_option( 'pkplus_client_publishable_key', $settings['publishable_key'] );
    update_option( 'pkplus_client_secret_key', $settings['secret_key'] );
    update_option( 'pkplus_client_enabled', $settings['enabled'] );
    update_option( 'pkplus_client_title', $settings['title'] );
    update_option( 'pkplus_client_description', $settings['description'] );
    update_option( 'pkplus_client_checkout_flow', $settings['checkout_flow'] );

    $in_progress = false;
}

// Hook to check the required settings when the plugin is loaded
add_action('admin_init', 'pkplus_client_checkout_check_settings');

function pkplus_client_checkout_check_settings() {
    // Check if the required option is set
    $pkplus_client_publishable_key = get_option('pkplus_client_publishable_key');

    // If not set, show an admin notice and deactivate the plugin
    if (empty($pkplus_client_publishable_key)) {
        // Show admin notice
        add_action('admin_notices', 'pkplus_client_checkout_admin_notice');
    }
}

// Display an admin notice if the pkplus_client_publishable_key is not set
function pkplus_client_checkout_admin_notice() {
    ?>
    <div class="notice notice-warning">
        <p>
            <?php esc_html_e(
                    'PKPay+ Secured Checkout plugin requires the public key and secret key to be set before activation. Please go to the plugin settings page to configure it.',
                    'pkpay-secured-checkout'
            ); ?>
        </p>
    </div>
    <?php
}

// Add settings page to the admin menu
add_action('admin_menu', 'pkplus_client_checkout_add_menu');

function pkplus_client_checkout_add_menu() {
    add_options_page(
        'PKPay+ Secured Checkout Settings', // Page title
        'PKPay+ Secured Checkout',          // Menu title
        'manage_options',                     // Capability
        'pkplus-checkout',          // Menu slug
        'pkplus_client_checkout_settings_page' // Callback function
    );
}

// Display the settings page
function pkplus_client_checkout_settings_page() {
    ?>
    <div class="wrap">
        <h1>PKPay+ Secured Checkout Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('pkplus_client_checkout_settings_group');
            do_settings_sections('pkplus-checkout');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register settings
add_action('admin_init', 'pkplus_client_checkout_register_settings');
function pkplus_client_checkout_register_settings() {
    // ✅ Register all three options
    register_setting('pkplus_client_checkout_settings_group', 'pkplus_client_publishable_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting('pkplus_client_checkout_settings_group', 'pkplus_client_secret_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting('pkplus_client_checkout_settings_group', 'pkplus_client_merchant_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting('pkplus_client_checkout_settings_group', 'pkplus_client_enabled', array( 'sanitize_callback' => 'pkplus_checkout_sanitize_enabled_setting' ) );
    register_setting('pkplus_client_checkout_settings_group', 'pkplus_client_title', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting('pkplus_client_checkout_settings_group', 'pkplus_client_description', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
    register_setting('pkplus_client_checkout_settings_group', 'pkplus_client_checkout_flow', array( 'sanitize_callback' => 'pkplus_checkout_sanitize_checkout_flow_setting' ) );

    add_settings_section(
        'pkplus_client_checkout_main_section',
        '', // no title
        null, // no callback
        'pkplus-checkout'
    );

    // ✅ Merchant ID field
    add_settings_field(
        'pkplus_client_merchant_id_field',
        'Merchant ID',
        'pkplus_client_checkout_merchant_id_callback',
        'pkplus-checkout',
        'pkplus_client_checkout_main_section'
    );

    // ✅ Publishable key field
    add_settings_field(
        'pkplus_client_publishable_key_field',
        'Publishable Key',
        'pkplus_client_checkout_publishable_key_callback',
        'pkplus-checkout',
        'pkplus_client_checkout_main_section'
    );

    // ✅ Secret key field
    add_settings_field(
        'pkplus_client_secret_key_field',
        'Secret Key',
        'pkplus_client_checkout_secret_key_callback',
        'pkplus-checkout',
        'pkplus_client_checkout_main_section'
    );

    add_settings_field(
        'pkplus_client_enabled_field',
        'Enable/Disable',
        'pkplus_client_checkout_enabled_callback',
        'pkplus-checkout',
        'pkplus_client_checkout_main_section'
    );

    add_settings_field(
        'pkplus_client_title_field',
        'Title',
        'pkplus_client_checkout_title_callback',
        'pkplus-checkout',
        'pkplus_client_checkout_main_section'
    );

    add_settings_field(
        'pkplus_client_description_field',
        'Description',
        'pkplus_client_checkout_description_callback',
        'pkplus-checkout',
        'pkplus_client_checkout_main_section'
    );

    add_settings_field(
        'pkplus_client_checkout_flow_field',
        'Checkout Flow',
        'pkplus_client_checkout_flow_callback',
        'pkplus-checkout',
        'pkplus_client_checkout_main_section'
    );
}
add_action('admin_init', 'pkplus_client_checkout_register_settings');

// ✅ Callback for publishable key
function pkplus_client_checkout_publishable_key_callback() {
    $value = pkplus_checkout_get_effective_setting( 'publishable_key', 'pkplus_client_publishable_key', '' );
    echo '<input type="text" name="pkplus_client_publishable_key" value="' . esc_attr($value) . '" class="regular-text" required />';
    echo '<p class="description">Your <strong>publishable key</strong> (used by the checkout iframe on the client side).</p>';
}

// ✅ Callback for secret key
function pkplus_client_checkout_secret_key_callback() {
    $value = pkplus_checkout_get_effective_setting( 'secret_key', 'pkplus_client_secret_key', '' );
    echo '<input type="password" name="pkplus_client_secret_key" value="' . esc_attr($value) . '" class="regular-text" required />';
    echo '<p class="description">Your <strong>secret key</strong> (used by your WordPress backend to securely create checkout sessions via the Pkplus API).</p>';
}

// ✅ Callback for merchant ID
function pkplus_client_checkout_merchant_id_callback() {
    $value = pkplus_checkout_get_effective_setting( 'merchant_id', 'pkplus_client_merchant_id', '' );
    echo '<input type="text" name="pkplus_client_merchant_id" value="' . esc_attr($value) . '" class="regular-text" required />';
    echo '<p class="description">Your <strong>merchant ID</strong> (the unique ID of your Pkplus account).</p>';
}

function pkplus_client_checkout_enabled_callback() {
    $value = pkplus_checkout_get_effective_setting( 'enabled', 'pkplus_client_enabled', 'no' );
    echo '<label><input type="checkbox" name="pkplus_client_enabled" value="yes" ' . checked( $value, 'yes', false ) . ' /> Enable PKPay+ Secured Checkout</label>';
}

function pkplus_client_checkout_title_callback() {
    $value = pkplus_checkout_get_effective_setting( 'title', 'pkplus_client_title', 'PKPay+ Secured Checkout' );
    echo '<input type="text" name="pkplus_client_title" value="' . esc_attr($value) . '" class="regular-text" />';
}

function pkplus_client_checkout_description_callback() {
    $value = pkplus_checkout_get_effective_setting( 'description', 'pkplus_client_description', 'Pay with your credit or debit card via our secure payment gateway.' );
    echo '<textarea name="pkplus_client_description" class="large-text" rows="3">' . esc_textarea( $value ) . '</textarea>';
}

function pkplus_client_checkout_flow_callback() {
    $value = pkplus_checkout_get_effective_setting( 'checkout_flow', 'pkplus_client_checkout_flow', 'iframe' );
    echo '<select name="pkplus_client_checkout_flow">';
    echo '<option value="iframe" ' . selected( $value, 'iframe', false ) . '>Embedded iframe checkout</option>';
    echo '<option value="redirect" ' . selected( $value, 'redirect', false ) . '>Redirect checkout</option>';
    echo '</select>';
}

add_action( 'update_option_pkplus_client_merchant_id', 'pkplus_checkout_sync_legacy_to_wc_settings', 10, 0 );
add_action( 'update_option_pkplus_client_publishable_key', 'pkplus_checkout_sync_legacy_to_wc_settings', 10, 0 );
add_action( 'update_option_pkplus_client_secret_key', 'pkplus_checkout_sync_legacy_to_wc_settings', 10, 0 );
add_action( 'update_option_pkplus_client_enabled', 'pkplus_checkout_sync_legacy_to_wc_settings', 10, 0 );
add_action( 'update_option_pkplus_client_title', 'pkplus_checkout_sync_legacy_to_wc_settings', 10, 0 );
add_action( 'update_option_pkplus_client_description', 'pkplus_checkout_sync_legacy_to_wc_settings', 10, 0 );
add_action( 'update_option_pkplus_client_checkout_flow', 'pkplus_checkout_sync_legacy_to_wc_settings', 10, 0 );

add_action( 'update_option_woocommerce_pkplus_checkout_client_settings', function( $old_value, $new_value, $option ) {
    unset( $old_value, $option );
    pkplus_checkout_sync_wc_settings_to_legacy( $new_value );
}, 10, 3 );

add_action( 'admin_init', function() {
    $legacy_merchant = get_option( 'pkplus_client_merchant_id', '' );
    $legacy_publishable = get_option( 'pkplus_client_publishable_key', '' );
    $legacy_secret = get_option( 'pkplus_client_secret_key', '' );
    if ( $legacy_merchant !== '' || $legacy_publishable !== '' || $legacy_secret !== '' ) {
        return;
    }

    $wc_settings = get_option( 'woocommerce_pkplus_checkout_client_settings', array() );
    if ( is_array( $wc_settings ) && ! empty( $wc_settings ) ) {
        pkplus_checkout_sync_wc_settings_to_legacy( $wc_settings );
    }
} );

// ✅ Add "Settings" link on the Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pkplus_client_checkout_add_action_links');
function pkplus_client_checkout_add_action_links($links) {
    $settings_link = '<a href="options-general.php?page=pkplus-checkout">Settings</a>';
    array_push($links, $settings_link);
    return $links;
}

add_action('plugins_loaded', 'pkplus_checkout_load_gateway_class', 0);
function pkplus_checkout_load_gateway_class(){
    if (!class_exists('WC_Payment_Gateway'))
        return; // if the WC payment gateway class

    include(plugin_dir_path(__FILE__) . 'class-gateway.php');
}

add_filter('woocommerce_payment_gateways', 'pkplus_checkout_add_payment_gateway');

function pkplus_checkout_add_payment_gateway($gateways) {
    $gateways[] = 'PkplusSecuredCheckoutClient';
    return $gateways;
}

/**
 * Custom function to declare compatibility with cart_checkout_blocks feature
 */
function pkplus_checkout_declare_cart_checkout_blocks_compatibility() {
    // Check if the required class exists
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Declare compatibility for 'cart_checkout_blocks'
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        // Declare compatibility for High-Performance Order Storage (HPOS)
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}
// Hook the custom function to the 'before_woocommerce_init' action
add_action('before_woocommerce_init', 'pkplus_checkout_declare_cart_checkout_blocks_compatibility');

// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action( 'woocommerce_blocks_loaded', 'pkplus_checkout_register_order_approval_payment_method_type' );

/**
 * Custom function to register a payment method type

 */
function pkplus_checkout_register_order_approval_payment_method_type() {
    // Check if the required class exists
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    // Include the custom Blocks Checkout class
    require_once plugin_dir_path(__FILE__) . 'class-block.php';

    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            // Register an instance of PkplusSecuredCheckoutClient_Blocks
            $payment_method_registry->register( new PkplusSecuredCheckoutClient_Blocks );
        }
    );
}

function pkplus_checkout_get_customer_id() {
    if (is_user_logged_in()) {
        return get_current_user_id();
    } else {
        if (!session_id()) {
            session_start();
        }
        if (!isset($_SESSION['customer_id']) || !is_string($_SESSION['customer_id'])) {
            $_SESSION['customer_id'] = sanitize_text_field( wp_generate_uuid4() );
        }
        return sanitize_text_field( wp_unslash( (string) $_SESSION['customer_id'] ) );
    }
}

// Hook into WooCommerce checkout process
add_action( 'woocommerce_checkout_process', 'pkplus_checkout_bypass_payment_method_validation' );

function pkplus_checkout_bypass_payment_method_validation() {
    if ( ! is_wc_endpoint_url() && ! is_user_logged_in() ) {
        WC()->cart->calculate_totals();
        WC()->session->set( 'chosen_payment_method', 'bacs' );
    }
}

add_action('template_redirect', 'pkplus_checkout_handle_checkout_order_received');
add_action('woocommerce_api_pkplus_callback', 'pkplus_checkout_handle_payment_webhook');

function pkplus_checkout_handle_payment_webhook() {
    $raw_body = file_get_contents( 'php://input' );
    $payload = json_decode( (string) $raw_body, true );
    $payload = is_array( $payload ) ? $payload : array();

    $order_id = absint( $_GET['order_id'] ?? ( $payload['order_id'] ?? 0 ) );
    $order_key = sanitize_text_field( wp_unslash( (string) ( $_GET['order_key'] ?? '' ) ) );
    $webhook_token = sanitize_text_field( wp_unslash( (string) ( $_GET['pkplus_webhook_token'] ?? '' ) ) );
    $session_id = sanitize_text_field( (string) ( $payload['session_id'] ?? '' ) );
    $order = $order_id ? wc_get_order( $order_id ) : false;

    if ( ! $order && $session_id && function_exists( 'wc_get_orders' ) ) {
        $orders = wc_get_orders( array(
            'limit' => 1,
            'return' => 'objects',
            'meta_key' => '_pkplus_session_id',
            'meta_value' => $session_id,
        ) );
        $order = ! empty( $orders ) ? $orders[0] : false;
    }

    if ( ! $order ) {
        status_header( 401 );
        wp_send_json_error( array( 'message' => 'WooCommerce order was not found for this checkout session.' ), 401 );
    }

    $signature = sanitize_text_field( (string) ( $_SERVER['HTTP_X_PKPLUS_SIGNATURE'] ?? '' ) );
    if ( $order_key !== '' && ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
        status_header( 401 );
        wp_send_json_error( array( 'message' => 'Invalid WooCommerce order credentials.' ), 401 );
    }

    $signature_key = $order_key !== '' ? $order_key : $webhook_token;
    if ( $signature_key === '' ) {
        status_header( 401 );
        wp_send_json_error( array( 'message' => 'Missing PKPay+ webhook credentials.' ), 401 );
    }

    $expected_signature = hash_hmac( 'sha256', (string) $raw_body, $signature_key );
    if ( $signature === '' || ! hash_equals( $expected_signature, $signature ) ) {
        status_header( 401 );
        wp_send_json_error( array( 'message' => 'Invalid PKPay+ webhook signature.' ), 401 );
    }

    $stored_session_id = sanitize_text_field( (string) $order->get_meta( '_pkplus_session_id' ) );
    if ( $session_id === '' || ( $stored_session_id !== '' && $stored_session_id !== $session_id ) ) {
        status_header( 409 );
        wp_send_json_error( array( 'message' => 'Checkout session does not match the WooCommerce order.' ), 409 );
    }

    if ( ( $payload['event'] ?? '' ) !== 'payment.succeeded' || ( $payload['status'] ?? '' ) !== 'paid' ) {
        status_header( 422 );
        wp_send_json_error( array( 'message' => 'Unsupported or unpaid PKPay+ webhook event.' ), 422 );
    }

    $order->update_meta_data( '_pkplus_webhook_payment_status', 'paid' );
    $order->update_meta_data( '_pkplus_webhook_session_id', $session_id );
    $order->update_meta_data( '_pkplus_webhook_transaction_id', sanitize_text_field( (string) ( $payload['transaction_id'] ?? '' ) ) );
    $order->update_meta_data( '_pkplus_webhook_received_at', current_time( 'mysql', true ) );

    if ( ! $order->is_paid() ) {
        $order->payment_complete( sanitize_text_field( (string) ( $payload['transaction_id'] ?? $session_id ) ) );
    }

    $order->save();

    wp_send_json_success( array(
        'received' => true,
        'order_id' => $order_id,
        'session_id' => $session_id,
        'status' => 'paid',
    ) );
}

function pkplus_checkout_handle_checkout_order_received() {
    global $woocommerce;
    if (!is_order_received_page()) {
        return;
    }

    if ( $woocommerce && $woocommerce->cart ) {
        $woocommerce->cart->empty_cart();
    }

    $return_state = sanitize_text_field( (string) ( $_GET['pkplus_return'] ?? '' ) );
    if ( $return_state !== 'success' ) {
        return;
    }

    $order_id = absint( $_GET['order_id'] ?? 0 );
    $order_key = sanitize_text_field( wp_unslash( (string) ( $_GET['order_key'] ?? '' ) ) );
    $order = $order_id ? wc_get_order( $order_id ) : false;
    if ( ! $order || ! $order_key || ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
        return;
    }

    $session_id = sanitize_text_field( (string) $order->get_meta( '_pkplus_session_id' ) );
    if ( pkplus_checkout_webhook_payment_confirmed( $order, $session_id ) ) {
        return;
    }

    $merchant_id = sanitize_text_field( (string) get_option( 'pkplus_client_merchant_id' ) );
    $secret_key = sanitize_text_field( (string) get_option( 'pkplus_client_secret_key' ) );
    if ( ! $session_id || ! $merchant_id || ! $secret_key ) {
        $order->add_order_note( 'PKPay+ return verification skipped: missing session or merchant credentials.' );
        return;
    }

    $verify_url = rtrim( pkplus_checkout_get_backend_url(), '/' )
        . '/merchant-api/' . rawurlencode( $merchant_id ) . '/' . rawurlencode( $session_id );

    pkplus_checkout_send_server_diagnostic( $session_id, 'woocommerce.verify.request', array(
        'order_id' => $order_id,
        'merchant_id' => $merchant_id,
        'verify_url' => $verify_url,
        'source' => 'redirect_return',
    ), 'info', 'WooCommerce is verifying the redirect checkout return.' );

    $response = wp_remote_get( $verify_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type' => 'application/json',
        ),
        'timeout' => 15,
    ) );

    if ( is_wp_error( $response ) ) {
        pkplus_checkout_send_server_diagnostic( $session_id, 'woocommerce.verify.transport_error', array(
            'order_id' => $order_id,
            'error' => $response->get_error_message(),
            'source' => 'redirect_return',
        ), 'error', 'WooCommerce could not verify the redirect checkout return.' );
        $order->add_order_note( 'PKPay+ redirect verification failed: ' . $response->get_error_message() );
        return;
    }

    $http_status = wp_remote_retrieve_response_code( $response );
    $raw_body = wp_remote_retrieve_body( $response );
    $body = json_decode( $raw_body, true );
    $body = is_array( $body ) ? $body : array();

    pkplus_checkout_send_server_diagnostic( $session_id, 'woocommerce.verify.response', array(
        'order_id' => $order_id,
        'http_status' => $http_status,
        'response' => $body ?: array( 'raw_body' => substr( (string) $raw_body, 0, 4000 ) ),
        'source' => 'redirect_return',
    ), $http_status >= 400 ? 'error' : 'info', 'WooCommerce received the redirect checkout verification response.' );

    if ( ( $body['status'] ?? '' ) === 'paid' ) {
        if ( ! $order->is_paid() ) {
            $order->payment_complete( $session_id );
            $order->add_order_note( 'PKPay+ redirect payment confirmed by backend.' );
        }
        return;
    }

    $order->add_order_note(
        'PKPay+ redirect verification returned status: ' . sanitize_text_field( (string) ( $body['status'] ?? 'unknown' ) )
    );
}

add_action('enqueue_block_assets', 'pkplus_checkout_enqueue_custom_checkout_script');

function pkplus_checkout_enqueue_custom_checkout_script() {
    // Only load on checkout (blocks or classic)
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    // Classic checkout has its own adapter. The Blocks integration continues
    // to load its existing script through the WooCommerce Blocks registry.
    if ( ! function_exists( 'has_block' ) || ! has_block( 'woocommerce/checkout' ) ) {
        $classic_checkout_flow = pkplus_checkout_get_effective_setting(
            'checkout_flow',
            'pkplus_client_checkout_flow',
            'iframe'
        );

        wp_enqueue_script(
            'pkplus-classic-checkout',
            plugins_url( 'classic-checkout.js', __FILE__ ),
            array( 'jquery' ),
            '1.0.3',
            true
        );
        wp_localize_script( 'pkplus-classic-checkout', 'pkplus_classic_settings', array(
            'ajax_url'          => WC_AJAX::get_endpoint( 'pkplusCreateSession' ),
            'diagnostic_ajax_url' => WC_AJAX::get_endpoint( 'pkplusDiagnostics' ),
            'nonce'             => wp_create_nonce( 'pkplus_create_session' ),
            'iframe_url'        => pkplus_checkout_get_iframe_url(),
            'diagnostic_url'    => rtrim( pkplus_checkout_get_backend_url(), '/' ) . '/client-api/checkout-sessions/{session_id}/diagnostics',
            'publishable_key'   => get_option( 'pkplus_client_publishable_key', '' ),
            'checkout_flow'     => $classic_checkout_flow === 'redirect' ? 'redirect' : 'iframe',
            'three_ds_return_url' => add_query_arg( 'pkplus_3ds_return', '1', wc_get_checkout_url() ),
        ) );
    }
}

add_action( 'woocommerce_checkout_create_order', function ( $order ) {
    if ( ! empty( $_POST['pkplus_session_id'] ) ) {
        $order->update_meta_data( '_pkplus_session_id', sanitize_text_field( wp_unslash( $_POST['pkplus_session_id'] ) ) );
        $order->update_meta_data( '_pkplus_checkout_flow', 'iframe' );
    }
}, 10, 1 );



register_activation_hook(__FILE__, 'pkplus_checkout_create_checkout_page');

/**
 * Create the /pkplus-checkout-client page if it does not exist
 */
function pkplus_checkout_create_checkout_page() {
    $page_title = 'PKPay+ Secured Checkout';
    $page_content = '';

    // Check if the page already exists
    $page = get_page_by_path('pkplus-checkout-client');

    // If the page does not exist, create it
    if (!$page) {
        $new_page = array(
            'post_title'   => $page_title,
            'post_content' => $page_content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_name'    => 'pkplus-checkout-client'
        );

        // Insert the page into the database
        wp_insert_post($new_page);
    }
}


add_action('wc_ajax_pkplusCreateSession', 'pkplus_ajax_create_session');
add_action('wc_ajax_nopriv_pkplusCreateSession', 'pkplus_ajax_create_session');

add_action('wc_ajax_pkplusDiagnostics', 'pkplus_ajax_send_diagnostic');
add_action('wc_ajax_nopriv_pkplusDiagnostics', 'pkplus_ajax_send_diagnostic');

function pkplus_ajax_send_diagnostic() {
    if ( false === check_ajax_referer( 'pkplus_create_session', '_wpnonce', false ) ) {
        wp_send_json_error([ 'message' => 'Invalid diagnostic security token.' ], 403);
    }

    $request_body = json_decode( file_get_contents( 'php://input' ), true );
    $request_body = is_array( $request_body ) ? $request_body : [];
    $session_id = sanitize_text_field( (string) ($request_body['session_id'] ?? '') );

    if ($session_id === '') {
        wp_send_json_error([ 'message' => 'Missing checkout session ID.' ], 422);
    }

    $request_body['event'] = sanitize_text_field( (string) ($request_body['event'] ?? 'unknown') );
    $api_url = rtrim( pkplus_checkout_get_backend_url(), '/' )
        . '/client-api/checkout-sessions/' . rawurlencode( $session_id ) . '/diagnostics';
    unset($request_body['session_id']);

    $response = wp_remote_post($api_url, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body' => wp_json_encode($request_body),
        'timeout' => 5,
        'blocking' => false,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error([ 'message' => $response->get_error_message() ], 502);
    }

    wp_send_json_success([ 'queued' => true ]);
}

function pkplus_ajax_create_session() {
    if ( false === check_ajax_referer( 'pkplus_create_session', '_wpnonce', false ) ) {
        wp_send_json_error(
            array(
                'message' => 'Invalid checkout security token. Please refresh the page and try again.',
            ),
            403
        );
    }

    $request_body = json_decode( file_get_contents( 'php://input' ), true );
    $request_body = is_array( $request_body ) ? $request_body : array();

    $settings = pkplus_checkout_normalize_gateway_settings( get_option( 'woocommerce_pkplus_checkout_client_settings', array() ) );
    if ( $settings['enabled'] !== 'yes' ) {
        wp_send_json_error(
            array(
                'message' => 'PKPay+ Secured Checkout is not enabled.',
            ),
            403
        );
    }

    if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
        wp_send_json_error(
            array(
                'message' => 'Your cart is empty. Please add a product before starting checkout.',
            ),
            400
        );
    }

    $requested_flow = isset( $request_body['checkout_flow'] ) ? sanitize_text_field( $request_body['checkout_flow'] ) : '';
    $checkout_flow = ( $requested_flow === 'redirect' ) ? 'redirect' : 'iframe';

    // Fetch stored keys
    $publishable_key = get_option('pkplus_client_publishable_key');
    $secret_key      = get_option('pkplus_client_secret_key');
    $merchant_id     = get_option('pkplus_client_merchant_id');

    if (empty($secret_key) || empty($merchant_id)) {
        wp_send_json_error([
            'message' => 'Missing merchant credentials. Please configure Pkplus plugin settings.',
            'publishable_key' => $publishable_key,
            'secret_key_set' => !empty($secret_key),
            'merchant_id' => $merchant_id,
        ]);
    }

    $cart = WC()->cart;
    $amount   = $cart ? $cart->get_total('edit') : 0;
    $currency = get_woocommerce_currency();
    $checkout_url = wc_get_checkout_url();
    $success_return_url = add_query_arg( 'pkplus_return', 'success', $checkout_url );
    $cancel_return_url  = add_query_arg( 'pkplus_return', 'cancel', $checkout_url );
    $three_ds_return_url = add_query_arg( 'pkplus_3ds_return', '1', $checkout_url );
    $webhook_token      = wp_generate_password( 64, false, false );
    $callback_url       = add_query_arg( array(
        'wc-api' => 'pkplus_callback',
        'pkplus_webhook_token' => $webhook_token,
    ), home_url( '/' ) );
    $current_user = wp_get_current_user();
    $customer = WC()->customer;

    $billing = array(
        'first_name' => $customer ? $customer->get_billing_first_name() : '',
        'last_name'  => $customer ? $customer->get_billing_last_name() : '',
        'company'    => $customer ? $customer->get_billing_company() : '',
        'email'      => $customer ? $customer->get_billing_email() : '',
        'phone'      => $customer ? $customer->get_billing_phone() : '',
        'address_1'  => $customer ? $customer->get_billing_address_1() : '',
        'address_2'  => $customer ? $customer->get_billing_address_2() : '',
        'city'       => $customer ? $customer->get_billing_city() : '',
        'state'      => $customer ? $customer->get_billing_state() : '',
        'postcode'   => $customer ? $customer->get_billing_postcode() : '',
        'country'    => $customer ? $customer->get_billing_country() : '',
    );

    $line_items = array();
    if ( $cart && method_exists( $cart, 'get_cart' ) ) {
        foreach ( $cart->get_cart() as $item ) {
            $product = isset( $item['data'] ) ? $item['data'] : null;
            $line_items[] = array(
                'product_id' => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
                'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
                'name' => $product ? $product->get_name() : '',
                'sku' => ( $product && method_exists( $product, 'get_sku' ) ) ? $product->get_sku() : '',
                'quantity' => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
                'line_total' => isset( $item['line_total'] ) ? (string) $item['line_total'] : '0',
                'line_tax' => isset( $item['line_tax'] ) ? (string) $item['line_tax'] : '0',
            );
        }
    }

    $api_url = pkplus_checkout_get_backend_url() . '/merchant-api/' . $merchant_id . '/checkout-sessions';

    $body = [
        'amount'      => $amount,
        'currency'    => $currency,
        'description' => 'WooCommerce Order - ' . uniqid(),
        'source'      => 'woocommerce',
        'checkout_flow' => $checkout_flow,
        'success_url' => $success_return_url,
        'cancel_url'  => $cancel_return_url,
        'three_ds_return_url' => $three_ds_return_url,
        'checkout_return_url' => $three_ds_return_url,
        'callback_url' => $callback_url,
        'website' => array(
            'name' => get_bloginfo( 'name' ),
            'url'  => home_url( '/' ),
        ),
        'customer' => array(
            'user_id' => get_current_user_id(),
            'username' => ( $current_user && $current_user->exists() ) ? $current_user->user_login : '',
            'email' => ( $current_user && $current_user->exists() ) ? $current_user->user_email : '',
            'is_logged_in' => is_user_logged_in(),
            'billing' => $billing,
        ),
        'cart' => array(
            'subtotal' => $cart ? (string) $cart->get_subtotal() : '0',
            'discount_total' => $cart ? (string) $cart->get_discount_total() : '0',
            'shipping_total' => $cart ? (string) $cart->get_shipping_total() : '0',
            'tax_total' => $cart ? (string) $cart->get_total_tax() : '0',
            'total' => $amount,
            'items' => $line_items,
        ),
    ];


    $response = wp_remote_post($api_url, [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $secret_key,
        ],
        'body'    => wp_json_encode($body),
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($data['session_id'])) {
        $session_id = sanitize_text_field( $data['session_id'] );
        $iframe_url = pkplus_checkout_get_iframe_url();
        if ( ! defined( 'PKPLUS_IFRAME_URL' ) && ! empty( $data['iframe_url'] ) ) {
            $iframe_url = esc_url_raw( $data['iframe_url'] );
        }
        $redirect_url = '';
        if ( ! empty( $session_id ) ) {
            $redirect_url = pkplus_checkout_get_redirect_url() . '/s/' . rawurlencode( $session_id );
        }

        wp_send_json_success([
            'session_id'      => $session_id,
            'iframe_url'      => $iframe_url,
            'publishable_key' => $publishable_key,
            'redirect_url'    => $redirect_url,
            'three_ds_return_url' => $three_ds_return_url,
        ]);
    } else {
        wp_send_json_error([
            'message' => 'Invalid response from Pkplus API',
            'raw' => $data,
            'status' => wp_remote_retrieve_response_code($response),
        ]);
    }
}

add_action('woocommerce_store_api_checkout_update_order_from_request', function ($order, $request) {
    $payment_method = $request['payment_method'] ?? '';

    if ($payment_method !== 'pkplus_checkout_client') {
        return;
    }

    $payment_data = $request['payment_data'] ?? [];
    $session_id = null;
    $checkout_flow = null;

    foreach ($payment_data as $field) {
        if (($field['key'] ?? '') === 'pkplus_session_id') {
            $session_id = sanitize_text_field($field['value']);
        }
        if (($field['key'] ?? '') === 'pkplus_checkout_flow') {
            $checkout_flow = sanitize_text_field($field['value']);
        }
    }

    if ($session_id) {
        $order->update_meta_data('_pkplus_session_id', $session_id);
        $order->add_order_note('💳 Stored Pkplus session ID: ' . $session_id);
    }

    if ($checkout_flow) {
        $normalized_flow = ($checkout_flow === 'redirect') ? 'redirect' : 'iframe';
        $order->update_meta_data('_pkplus_checkout_flow', $normalized_flow);
        $order->add_order_note('💳 Pkplus checkout flow: ' . $normalized_flow);
    }

}, 10, 2);
