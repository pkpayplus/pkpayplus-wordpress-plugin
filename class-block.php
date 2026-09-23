<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class PkplusSecuredCheckoutClient_Blocks extends AbstractPaymentMethodType {

    private $gateway;
    protected $name = 'pkplus_checkout_client';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_pkplus_checkout_client_settings', [] );
        $this->gateway = new PkplusSecuredCheckoutClient();
    }

    public function is_active() {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        $script_path = plugin_dir_path(__FILE__) . 'checkout.js';
        $script_version = file_exists( $script_path ) ? filemtime( $script_path ) : null;

        wp_register_script(
            'pkplus_checkout_client-blocks-integration',
            plugin_dir_url(__FILE__) . 'checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            $script_version,
            true
        );

        if( function_exists( 'wp_set_script_translations' ) ) {            
            wp_set_script_translations( 'pkplus_checkout_client-blocks-integration');
            
        }
        return [ 'pkplus_checkout_client-blocks-integration' ];
    }

    public function get_payment_method_data() {
        $checkout_flow = isset( $this->settings['checkout_flow'] ) ? $this->settings['checkout_flow'] : '';
        if ( empty( $checkout_flow ) ) {
            $checkout_flow = get_option( 'pkplus_client_checkout_flow', 'iframe' );
        }
        if ( $checkout_flow !== 'redirect' ) {
            $checkout_flow = 'iframe';
        }

        return [
            'title'           => $this->gateway->title,
            'description'     => __('Securely pay using Pkplus Checkout.', 'pkpay-secured-checkout'),
            'supports'        => $this->gateway->supports,
            'iframe_url'      => pkplus_checkout_get_iframe_url(),
            'publishable_key' => get_option('pkplus_client_publishable_key'),
            'checkout_flow'   => $checkout_flow,
            'three_ds_return_url' => add_query_arg(
                array( 'pkplus_3ds_return' => '1' ),
                wc_get_checkout_url()
            ),
            'create_session_nonce' => wp_create_nonce( 'pkplus_create_session' ),
        ];
    }
}
?>
