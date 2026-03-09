=== PayzCore for WooCommerce ===
Contributors: payzcore
Tags: usdt, usdc, crypto, stablecoin, cryptocurrency
Requires at least: 5.8
Tested up to: 6.9.1
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 10.5.3
Requires Plugins: woocommerce
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDT and USDC stablecoin payments in your WooCommerce store via PayzCore blockchain transaction monitoring across multiple networks.

== Description ==

**PayzCore is a blockchain monitoring service, not a payment processor.** All payments are sent directly to your own wallet addresses. PayzCore never holds, transfers, or has access to your funds.

* **Your wallets, your funds** — You provide your own wallet (HD xPub or static addresses). Customers pay directly to your addresses.
* **Read-only monitoring** — PayzCore watches the blockchain for incoming transactions and sends webhook notifications. That's it.
* **Protection Key security** — Sensitive operations like wallet management, address changes, and API key regeneration require a Protection Key that only you set. PayzCore cannot perform these actions without your authorization.
* **Your responsibility** — You are responsible for securing your own wallets and private keys. PayzCore provides monitoring and notification only.

**How It Works**

PayzCore is a non-custodial blockchain transaction monitoring service. When a customer checks out, the plugin creates a monitoring request via the PayzCore API. The customer is shown a unique wallet address and QR code. PayzCore watches the blockchain for incoming transfers and sends a webhook notification when the payment is detected, automatically updating your WooCommerce order status.

**Key Features**

* Accept USDT and USDC stablecoins
* Multi-network support: TRC20 (Tron), BEP20 (BNB Smart Chain), ERC20 (Ethereum), Polygon, and Arbitrum
* Non-custodial: funds go directly to your wallet, PayzCore never touches them
* Static wallet mode: use a fixed address for all payments with customer tx hash confirmation
* Real-time payment status polling with automatic order updates
* Dark-themed payment instructions page with QR code and countdown timer
* HMAC-SHA256 webhook signature verification for security
* Automatic order completion for virtual/downloadable products
* Blockchain explorer links in order notes (Tronscan, BSCScan, Etherscan, Polygonscan, Arbiscan)
* WooCommerce HPOS (High-Performance Order Storage) compatible
* Translatable with full i18n support

**Static Wallet Mode**

By default, PayzCore derives a unique HD wallet address for each payment. If you prefer to use a single fixed address for all payments, you can enable Static Wallet Mode:

1. Go to WooCommerce > Settings > Payments > PayzCore (Stablecoin)
2. Enter your wallet address in the "Static Wallet Address" field
3. Save settings

When static wallet mode is active:

* All payments use the same address you configured
* The payment amount includes a micro-offset (e.g., $50.003 instead of $50.00) to distinguish transactions
* Customers see a notice with the exact amount to send
* After sending payment, customers are shown a form to paste their blockchain transaction hash (TxID)
* The transaction hash is submitted to PayzCore for verification
* Order status is updated automatically once PayzCore confirms the transaction

This mode is useful when you want to receive all payments to a single address without setting up HD wallet (xPub) derivation.

**Requirements**

* A PayzCore account at [payzcore.com](https://payzcore.com)
* A project with an API key and webhook secret configured
* WooCommerce 7.0 or higher
* PHP 7.4 or higher
* WordPress 5.8 or higher

**Before Going Live**

Always test your setup before accepting real payments:

1. **Verify your wallet** — In the PayzCore dashboard, verify that your wallet addresses are correct. For HD wallets, click "Verify Key" and compare address #0 with your wallet app.
2. **Run a test order** — Place a test order for a small amount ($1–5) and complete the payment. Verify the funds arrive in your wallet.
3. **Test sweeping** — Send the test funds back out to confirm you control the addresses with your private keys.

Warning: Wrong wallet configuration means payments go to addresses you don't control. Funds sent to incorrect addresses are permanently lost. PayzCore is watch-only and cannot recover funds. Please test before going live.

**Links**

* [Getting Started](https://docs.payzcore.com/getting-started) — Account setup and first payment
* [Webhooks Guide](https://docs.payzcore.com/guides/webhooks) — Events, headers, and signature verification
* [Supported Networks](https://docs.payzcore.com/guides/networks) — Available networks and tokens
* [Error Reference](https://docs.payzcore.com/guides/errors) — HTTP status codes and troubleshooting
* [API Reference](https://docs.payzcore.com) — Interactive API documentation
* [GitHub](https://github.com/payzcore/woocommerce) — Source code

== Third Party or External Service ==

This plugin connects to the [PayzCore API](https://api.payzcore.com) to create and monitor blockchain payment requests. The PayzCore API is required for this plugin to function.

**When data is sent:**

* When a customer places an order and selects PayzCore as the payment method, a monitoring request is created via the API.
* While the customer is on the payment instructions page, the plugin polls the API every 15 seconds to check payment status.
* If using static wallet mode, the customer's submitted transaction hash is sent to the API for verification.
* When the admin clicks "Test Connection" in plugin settings, the plugin fetches project configuration from the API.

**Data transmitted:**

* Order amount, selected blockchain network and token
* Customer identifier (order ID or email, used as external reference)
* Store webhook URL (for receiving payment status notifications)
* Transaction hash (static wallet mode only)

No data is sent unless the plugin is enabled and configured with an API key. PayzCore does not hold, transmit, or custody any funds — it is a read-only monitoring service.

* [PayzCore Website](https://payzcore.com)
* [PayzCore Terms of Service](https://payzcore.com/terms)
* [PayzCore Privacy Policy](https://payzcore.com/privacy)

This plugin also displays links to third-party blockchain explorers so users can verify their transactions on the blockchain. No data is sent to these services by the plugin — they are informational links only, opened in the user's browser.

* [Tronscan](https://tronscan.org) — TRC20 transaction explorer ([Terms](https://tronscan.org/#/about/terms), [Privacy](https://tronscan.org/#/about/privacyPolicy))
* [BscScan](https://bscscan.com) — BEP20 transaction explorer ([Terms](https://bscscan.com/terms), [Privacy](https://bscscan.com/privacyPolicy))
* [Etherscan](https://etherscan.io) — ERC20 transaction explorer ([Terms](https://etherscan.io/terms), [Privacy](https://etherscan.io/privacypolicy))
* [PolygonScan](https://polygonscan.com) — Polygon transaction explorer ([Terms](https://polygonscan.com/terms), [Privacy](https://polygonscan.com/privacyPolicy))
* [Arbiscan](https://arbiscan.io) — Arbitrum transaction explorer ([Terms](https://arbiscan.io/terms), [Privacy](https://arbiscan.io/privacyPolicy))

== Installation ==

1. Upload the `payzcore-woocommerce` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to WooCommerce > Settings > Payments > PayzCore (Stablecoin)
4. Enter your PayzCore API Key (found in your PayzCore dashboard under Projects)
5. Enter your Webhook Secret (shown when you create a project)
6. Set your Webhook URL in the PayzCore dashboard to: `https://yourstore.com/wp-json/payzcore/v1/webhook`
7. Select your preferred blockchain network (TRC20, BEP20, ERC20, Polygon, or Arbitrum)
8. Select your preferred stablecoin token (USDT or USDC)
9. Enable the payment method and save

== Frequently Asked Questions ==

= What is PayzCore? =

PayzCore is a non-custodial blockchain transaction monitoring API. It watches blockchain addresses for incoming stablecoin transfers (USDT, USDC) and sends webhook notifications. It does not hold, transmit, or custody any funds.

= How do I get API credentials? =

Sign up at [payzcore.com](https://payzcore.com), create a project, and you will receive an API Key and Webhook Secret. Enter these in the plugin settings.

= What currencies and networks are supported? =

USDT (Tether) and USDC (USD Coin) on TRC20 (Tron), BEP20 (BNB Smart Chain), ERC20 (Ethereum), Polygon, and Arbitrum networks. Ensure the token you select is supported on your chosen network in your PayzCore project configuration.

= Is this a payment processor? =

No. PayzCore is a monitoring and notification service. Funds are sent directly from the customer to your wallet address. PayzCore watches the blockchain and notifies your store when a transfer is detected. No funds pass through PayzCore at any point.

= What happens if a customer underpays? =

If a partial transfer is detected, an order note is added with the received amount. The order remains on hold until the full amount is received or the payment window expires.

= What happens if a customer overpays? =

The order is marked as complete with an order note indicating the overpayment amount. Any excess amount handling is between you and your customer.

= How long does confirmation take? =

Blockchain confirmation times vary by network. TRC20 transactions typically confirm within 1-3 minutes. BEP20 and Polygon transactions typically confirm within 15-30 seconds. ERC20 transactions typically confirm within 1-5 minutes. Arbitrum transactions typically confirm within a few seconds. PayzCore checks every 2 minutes.

= Is HPOS compatible? =

Yes. The plugin fully supports WooCommerce High-Performance Order Storage.

== Changelog ==

= 1.0.2 =
* Security: SSRF protection on confirm endpoint with host validation
* Security: Webhook idempotency with transient lock to prevent double-processing
* Security: Removed SVG from allowed QR code formats (XSS prevention)
* Security: Generic error messages to customers (no internal API details exposed)
* Security: API URL field restricted to HTTPS only
* Fixed: Webhook race condition — expired/cancelled events no longer cancel paid orders
* Fixed: Template variable mismatch for nonce fields
* Fixed: HPOS compatibility — replaced meta_query with meta_key/meta_value
* Fixed: Event listener leak on checkout page during AJAX updates
* Fixed: QR code re-validated at render time
* Fixed: order_is_virtual returns false for empty item sets
* Improved: All inline styles moved to enqueued CSS files (WordPress.org compliance)
* Improved: Network/token selector moved from inline JS to enqueued script file
* Improved: All text defaults wrapped in translation functions (i18n ready)
* Improved: Proper escaping on all webhook order note values
* Improved: Order key validation on thank-you page script loading
* Improved: Added return statements after all wp_send_json calls
* Updated: Tested up to WordPress 6.9.1
* Updated: WC tested up to 10.5.3

= 1.0.1 =
* Fixed QR code visibility on light backgrounds (standard black-on-white QR)

= 1.0.0 =
* Initial release
* Multi-network support: TRC20, BEP20, ERC20, Polygon, Arbitrum
* Multi-token support: USDT + USDC
* Network/token selector on checkout page
* QR code generation for payment addresses
* Real-time payment status polling
* Webhook signature verification (HMAC-SHA256)
* Static wallet support (dedicated + pool modes)
* Transaction hash confirmation for pool mode
* HPOS (High-Performance Order Storage) compatible

== Upgrade Notices ==

= 1.0.2 =
Security hardening and WordPress.org compliance improvements. No configuration changes needed.

= 1.0.1 =
QR code display fix. No configuration changes needed.

= 1.0.0 =
Initial release. Configure your API key and webhook secret in WooCommerce settings.
