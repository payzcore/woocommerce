# PayzCore for WooCommerce v1.0.3

## New
- **Cart/Checkout Blocks support.** PayzCore now appears in the block-based Checkout (the WooCommerce default since 8.3), including the network and stablecoin selectors. The plugin declares `cart_checkout_blocks` compatibility, so WooCommerce no longer flags it as incompatible.

## Fixed
- **Pay again after expiry:** a new payment attempt for the same order used the same `external_order_id`, so PayzCore returned the expired payment. A new attempt now uses `{order_id}-r{8 hex}`; while the previous payment is still open, the same id is reused (idempotent).
- **Late payments:** a `payment.completed`/`payment.overpaid` webhook for an earlier, replaced attempt of the order (PayzCore late payment, `late_payment: true`) is matched to the order through the signed `metadata.order_key` and applied. Expired/cancelled/partial events of a replaced attempt are ignored, so they cannot cancel an order that has a newer payment.
- Webhook lock is atomic (`add_option`). A concurrent delivery for the same payment gets HTTP `503` so PayzCore retries it, instead of HTTP `200` (which could lose the event if the first attempt failed).

## Compatibility
- Tested up to WordPress 7.1 and WooCommerce 11.2 (WordPress 7.1.3 and WooCommerce 11.2.1 were current on 2026-10-15 per api.wordpress.org).
- Requires PHP 7.4+, WordPress 5.8+, WooCommerce 7.0+.

Download: https://wordpress.org/plugins/payzcore-for-woocommerce/ · Docs: https://docs.payzcore.com/integrations/woocommerce
