=== PKPay+ Secured Checkout ===
Contributors: pkpayplus
Tags: woocommerce, payment gateway, checkout
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure hosted checkout solution for fast, reliable, and protected online payments with WooCommerce.

== Description ==

PKPay+ Secured Checkout provides embedded iframe and redirect payment flows for WooCommerce.

== External services ==

This plugin connects to PKPay+, a payment gateway service provided by PKPay+, to create hosted checkout sessions, display the hosted payment form, redirect customers to hosted checkout when that option is enabled, and verify payment/session status.

The plugin sends data to the PKPay+ API at `https://api.pkpayplus.com` when a customer starts checkout and when WooCommerce verifies a payment. Site owners may override this API host with the `PKPLUS_BACKEND_URL` constant for a different PKPay+ endpoint. The hosted iframe checkout loads from `https://pay-inline.pkpayplus.com`. Redirect checkout may send customers to `https://pay-session.pkpayplus.com`.

Data sent to PKPay+ can include the merchant ID, the merchant secret key in an Authorization header, checkout flow, order amount, currency, cart totals, product names, product IDs, variation IDs, SKUs, quantities, line totals, tax totals, website name and URL, WooCommerce order ID and order key for redirect checkout, callback/return URLs, customer user ID when logged in, username, email address, billing name, company, phone number, billing address, city, state, postcode, and country. This data is sent when a checkout session is created and when a payment/session is verified.

Service provider: PKPay+.
Terms of Service: https://www.pkpayplus.com/terms-of-service
Privacy Policy: https://www.pkpayplus.com/privacy

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Configure Merchant ID, Publishable Key, and Secret Key in WooCommerce payment settings.

== Changelog ==

= 1.0.0 =
* Initial release.
