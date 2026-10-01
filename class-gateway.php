<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PkplusSecuredCheckoutClient extends WC_Payment_Gateway {

    // Constructor method
    public function __construct() {
        $this->id                 = 'pkplus_checkout_client';
        $this->method_title       = __('PKPay+ Secured Checkout', 'pkpay-secured-checkout');
        $this->method_description = __('Pay with pkplus account.', 'pkpay-secured-checkout');
        $this->icon               = plugin_dir_url(__FILE__) . 'payment.png';

        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');

        $this->supports = array(
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'multiple_subscriptions',
            'subscription_manual_renewals'
        );

        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function is_available() {
        // Check if the gateway is enabled
        if ($this->enabled === "yes") {
            return parent::is_available();
        }

        return false; // Not available
    }

    public function init_form_fields() {
        $backend_default_url = defined( 'PKPLUS_BACKEND_URL' ) && PKPLUS_BACKEND_URL
            ? rtrim( trim( (string) PKPLUS_BACKEND_URL ), '/' )
            : 'https://api.pkpayplus.com';

        $this->form_fields = array(
            'merchant_id' => array(
                'title'       => __( 'Merchant ID', 'pkpay-secured-checkout' ),
                'type'        => 'text',
                'description' => __( 'The unique ID of your Pkplus account.', 'pkpay-secured-checkout' ),
                'default'     => get_option( 'pkplus_client_merchant_id', '' ),
                'desc_tip'    => true,
            ),
            'publishable_key' => array(
                'title'       => __( 'Publishable Key', 'pkpay-secured-checkout' ),
                'type'        => 'text',
                'description' => __( 'Used by the checkout iframe on the client side.', 'pkpay-secured-checkout' ),
                'default'     => get_option( 'pkplus_client_publishable_key', '' ),
                'desc_tip'    => true,
            ),
            'secret_key' => array(
                'title'       => __( 'Secret Key', 'pkpay-secured-checkout' ),
                'type'        => 'password',
                'description' => __( 'Used by your backend to securely create checkout sessions.', 'pkpay-secured-checkout' ),
                'default'     => get_option( 'pkplus_client_secret_key', '' ),
                'desc_tip'    => true,
            ),
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'pkpay-secured-checkout' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable PKPay+ Secured Checkout', 'pkpay-secured-checkout' ),
                'default' => 'no'
            ),
            'title' => array(
                'title'       => __( 'Title', 'pkpay-secured-checkout' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'pkpay-secured-checkout' ),
                'default'     => __( 'PKPay+ Secured Checkout', 'pkpay-secured-checkout' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'pkpay-secured-checkout' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'pkpay-secured-checkout' ),
                'default'     => __( 'Pay with your credit or debit card via our secure payment gateway.', 'pkpay-secured-checkout' ),
                'desc_tip'    => true,
            ),
            'checkout_flow' => array(
                'title'       => __( 'Checkout Flow', 'pkpay-secured-checkout' ),
                'type'        => 'select',
                'description' => __( 'Choose how customers complete payment.', 'pkpay-secured-checkout' ),
                'default'     => 'iframe',
                'options'     => array(
                    'iframe'   => __( 'Embedded iframe checkout', 'pkpay-secured-checkout' ),
                    'redirect' => __( 'Redirect checkout', 'pkpay-secured-checkout' ),
                ),
                'desc_tip'    => true,
            ),
        );
    }

    public function get_icon() {
        $asset_url = plugin_dir_url( __FILE__ ) . 'assets/';
        $brands = array(
            'visa.svg'    => 'Visa',
            'master.svg'  => 'Mastercard',
            'gpay.svg'    => 'Google Pay',
            'discover.svg'=> 'Discover',
            'amex.svg'    => 'American Express',
        );
        $icons = '<span class="pkplus-card-brands" aria-label="Accepted payment methods">';
        foreach ( $brands as $filename => $label ) {
            $icons .= '<img class="pkplus-card-brand" style="width:auto;height:24px;max-width:48px;object-fit:contain;margin:0 3px;vertical-align:middle;" src="' . esc_url( WC_HTTPS::force_https_url( $asset_url . $filename ) ) . '" alt="' . esc_attr( $label ) . '" />';
        }
        $icons .= '</span>';

        $icon = $icons;

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter hook.
        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
    }

    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        echo '<div id="pkplus-classic-checkout-container" class="pkplus-classic-checkout-container">';
        echo '<div id="pkplus-classic-checkout-frame-wrap" class="pkplus-classic-checkout-frame-wrap"></div>';
        echo '<input type="hidden" name="pkplus_session_id" id="pkplus_classic_session_id" value="" />';
        echo '<input type="hidden" name="pkplus_checkout_flow" value="iframe" />';
        echo '<p id="pkplus-classic-checkout-status" class="pkplus-classic-checkout-status" aria-live="polite"></p>';
        echo '</div>';
    }

    // Process the payment
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if ( ! $order ) {
            wc_add_notice(__('Payment failed: order not found.', 'pkpay-secured-checkout'), 'error');
            return ['result' => 'failure'];
        }

        $checkout_flow = $order->get_meta( '_pkplus_checkout_flow' );
        if ( empty( $checkout_flow ) ) {
            $checkout_flow = $this->get_option( 'checkout_flow', 'iframe' );
        }
        $checkout_flow = ( $checkout_flow === 'redirect' ) ? 'redirect' : 'iframe';

        if ( $checkout_flow === 'redirect' ) {
            $session = $this->create_redirect_session_for_order( $order );
            if ( empty( $session['success'] ) ) {
                $message = ! empty( $session['message'] ) ? $session['message'] : __( 'Unable to start redirect payment session.', 'pkpay-secured-checkout' );
                $order->add_order_note( 'Pkplus redirect session failed: ' . $message );
                wc_add_notice( $message, 'error' );
                return ['result' => 'failure'];
            }

            $order->update_status( 'pending', 'Waiting for Pkplus redirect payment.' );
            $order->update_meta_data( '_pkplus_session_id', $session['session_id'] );
            $order->save();

            return [
                'result'   => 'success',
                'redirect' => $session['redirect_url'],
            ];
        }

        $session_id = $order->get_meta('_pkplus_session_id');
        if (empty($session_id)) {
            $order->add_order_note('Missing Pkplus session ID.');
            wc_add_notice(__('Payment verification failed: Missing session ID.', 'pkpay-secured-checkout'), 'error');
            return ['result' => 'failure'];
        }

        $publishable_key = get_option('pkplus_client_publishable_key');
        $secret_key      = get_option('pkplus_client_secret_key');
        $merchant_id     = get_option('pkplus_client_merchant_id');

        $verify_url = pkplus_checkout_get_backend_url() . "/merchant-api/{$merchant_id}/{$session_id}";

        pkplus_checkout_send_server_diagnostic( $session_id, 'woocommerce.verify.request', array(
            'order_id' => $order->get_id(),
            'merchant_id' => $merchant_id,
            'verify_url' => $verify_url,
        ), 'info', 'WooCommerce is verifying the checkout session.' );

        $response = wp_remote_get($verify_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            pkplus_checkout_send_server_diagnostic( $session_id, 'woocommerce.verify.transport_error', array(
                'order_id' => $order->get_id(),
                'merchant_id' => $merchant_id,
                'error' => $response->get_error_message(),
            ), 'error', 'WooCommerce could not reach the PKPay+ verification endpoint.' );
            $order->add_order_note('Could not reach Pkplus backend: ' . $response->get_error_message());
            return ['result' => 'failure'];
        }

        $http_status = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw_body, true );

        pkplus_checkout_send_server_diagnostic( $session_id, 'woocommerce.verify.response', array(
            'order_id' => $order->get_id(),
            'merchant_id' => $merchant_id,
            'http_status' => $http_status,
            'response' => is_array( $body ) ? $body : array( 'raw_body' => substr( (string) $raw_body, 0, 4000 ) ),
        ), ( $http_status >= 400 ? 'error' : 'info' ), 'WooCommerce received the PKPay+ verification response.' );

        if (isset($body['status']) && $body['status'] === 'paid') {
            $order->payment_complete($session_id);
            $order->add_order_note('Pkplus payment confirmed via backend.');
            WC()->cart->empty_cart();

            pkplus_checkout_send_server_diagnostic( $session_id, 'woocommerce.order.payment_complete', array(
                'order_id' => $order->get_id(),
                'order_status' => $order->get_status(),
                'transaction_id' => $session_id,
            ), 'info', 'WooCommerce marked the order payment complete.' );

            $redirect_url = $order->get_checkout_order_received_url();

            return [
                'result'   => 'success',
                'redirect' => $redirect_url
            ];
        } else {
            pkplus_checkout_send_server_diagnostic( $session_id, 'woocommerce.verify.not_paid', array(
                'order_id' => $order->get_id(),
                'http_status' => $http_status,
                'response_status' => is_array( $body ) ? ( $body['status'] ?? null ) : null,
                'response' => is_array( $body ) ? $body : array(),
            ), 'error', 'WooCommerce received a verification response that was not paid.' );
            $order->update_status('failed', 'Pkplus payment verification failed.');
            wc_add_notice(__('Payment not verified. Please try again.', 'pkpay-secured-checkout'), 'error');
            return ['result' => 'failure'];
        }
    }

    /**
     * Thumbnail for a line item, for display on the hosted checkout.
     */
    private function line_item_image_url( $item ) {
        $override = (string) $item->get_meta( '_pkplus_image_url' );
        if ( '' !== $override ) {
            return esc_url_raw( $override );
        }
        $product = $item->get_product();
        if ( ! $product ) {
            return '';
        }

        $image_id = $product->get_image_id();
        if ( ! $image_id ) {
            return '';
        }
        $url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );

        return $url ? esc_url_raw( $url ) : '';
    }

    private function create_redirect_session_for_order( WC_Order $order ) {
        $secret_key  = $this->get_option( 'secret_key' );
        $merchant_id = $this->get_option( 'merchant_id' );
        if ( empty( $secret_key ) ) {
            $secret_key = get_option('pkplus_client_secret_key');
        }
        if ( empty( $merchant_id ) ) {
            $merchant_id = get_option('pkplus_client_merchant_id');
        }

        if ( empty( $secret_key ) || empty( $merchant_id ) ) {
            return [
                'success' => false,
                'message' => 'Missing merchant credentials.',
            ];
        }

        $order_id = $order->get_id();
        $order_key = $order->get_order_key();
        $api_url = pkplus_checkout_get_backend_url() . '/merchant-api/' . $merchant_id . '/checkout-sessions';
        $success_url = add_query_arg(
            array(
                'pkplus_return' => 'success',
                'order_id'       => $order_id,
                'order_key'      => $order_key,
            ),
            $order->get_checkout_order_received_url()
        );
        $cancel_url = add_query_arg(
            array(
                'pkplus_return' => 'cancel',
                'order_id'       => $order_id,
                'order_key'      => $order_key,
            ),
            wc_get_checkout_url()
        );
        $callback_url = add_query_arg(
            array(
                'wc-api'    => 'pkplus_callback',
                'order_id'  => $order_id,
                'order_key' => $order_key,
            ),
            home_url( '/' )
        );

        $billing = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'company'    => $order->get_billing_company(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
            'address_1'  => $order->get_billing_address_1(),
            'address_2'  => $order->get_billing_address_2(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
        );

        $line_items = array();
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $line_items[] = array(
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name'         => $item->get_name(),
                'sku'          => $product ? $product->get_sku() : '',
                'quantity'     => (int) $item->get_quantity(),
                'line_total'   => (string) $item->get_total(),
                'line_tax'     => (string) $item->get_total_tax(),
                'image_url'    => $this->line_item_image_url( $item ),
            );
        }

        $body = array(
            'amount'        => (string) $order->get_total(),
            'currency'      => $order->get_currency(),
            'description'   => 'WooCommerce Order #' . $order_id,
            'source'        => 'woocommerce',
            'checkout_flow' => 'redirect',
            'success_url'   => $success_url,
            'cancel_url'    => $cancel_url,
            'callback_url'  => $callback_url,
            'website'       => array(
                'name' => get_bloginfo( 'name' ),
                'url'  => home_url( '/' ),
            ),
            'customer'      => array(
                'user_id'      => $order->get_user_id(),
                'username'     => '',
                'email'        => $order->get_billing_email(),
                'is_logged_in' => (bool) $order->get_user_id(),
                'billing'      => $billing,
            ),
            'order'         => array(
                'id'        => $order_id,
                'order_key' => $order_key,
            ),
            'cart'          => array(
                'subtotal'       => (string) $order->get_subtotal(),
                'discount_total' => (string) $order->get_discount_total(),
                'shipping_total' => (string) $order->get_shipping_total(),
                'tax_total'      => (string) $order->get_total_tax(),
                'total'          => (string) $order->get_total(),
                'items'          => $line_items,
            ),
        );

        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $secret_key,
            ),
            'body'    => wp_json_encode($body),
            'timeout' => 20,
        ));

        $this->log_pkplus_redirect_debug( 'request', array(
            'order_id' => $order_id,
            'api_url'  => $api_url,
            'payload'  => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log_pkplus_redirect_debug( 'wp_error', array(
                'order_id' => $order_id,
                'message'  => $response->get_error_message(),
            ) );
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $http_status = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw_body, true );
        if ( ! is_array( $data ) ) {
            $data = array();
        }
        $this->log_pkplus_redirect_debug( 'response', array(
            'order_id'    => $order_id,
            'http_status' => $http_status,
            'raw_body'    => $raw_body,
            'decoded'     => $data,
        ) );

        $payload = $data;
        if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
            $payload = $data['data'];
        }

        $session_id = ! empty( $payload['session_id'] ) ? sanitize_text_field( $payload['session_id'] ) : '';
        if ( empty( $session_id ) && ! empty( $payload['id'] ) ) {
            $session_id = sanitize_text_field( $payload['id'] );
        }
        $redirect_url = '';
        if ( ! empty( $session_id ) ) {
            $redirect_base_url = function_exists( 'pkplus_checkout_get_redirect_url' )
                ? pkplus_checkout_get_redirect_url()
                : 'https://pay.pkpayplus.com';
            $redirect_url = $redirect_base_url . '/s/' . rawurlencode( $session_id );
        }

        if ( empty( $session_id ) || empty( $redirect_url ) ) {
            $api_error = '';
            if ( ! empty( $payload['error'] ) ) {
                $api_error = (string) $payload['error'];
            } elseif ( ! empty( $payload['message'] ) ) {
                $api_error = (string) $payload['message'];
            }

            return array(
                'success' => false,
                'message' => 'Invalid response from Pkplus API.'
                    . ( $api_error ? ' ' . $api_error : '' )
                    . ' (HTTP ' . (int) $http_status . ')',
            );
        }

        return array(
            'success'      => true,
            'session_id'   => $session_id,
            'redirect_url' => $redirect_url,
        );
    }

    private function log_pkplus_redirect_debug( $stage, $context = array() ) {
        if ( ! function_exists( 'wc_get_logger' ) ) {
            return;
        }

        $logger = wc_get_logger();
        $logger->info(
            '[redirect_session][' . $stage . '] ' . wp_json_encode( $context ),
            array( 'source' => 'pkpay-secured-checkout' )
        );
    }
}
?>
