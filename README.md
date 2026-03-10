# PayzCore for WooCommerce

[![WordPress.org](https://img.shields.io/badge/WordPress.org-Plugin_Directory-blue)](https://wordpress.org/plugins/payzcore-for-woocommerce/) [![Docs](https://img.shields.io/badge/Docs-docs.payzcore.com-cyan)](https://docs.payzcore.com/integrations/woocommerce)

Accept USDT and USDC stablecoin payments in your WooCommerce store via PayzCore blockchain transaction monitoring across multiple networks (TRC20, BEP20, ERC20, Polygon, Arbitrum).

PayzCore is a **non-custodial** monitoring service. It watches blockchain addresses for incoming stablecoin transfers and sends webhook notifications when transactions are detected. PayzCore does not hold, transmit, or custody any funds.

## Important

**PayzCore is a blockchain monitoring service, not a payment processor.** All payments are sent directly to your own wallet addresses. PayzCore never holds, transfers, or has access to your funds.

- **Your wallets, your funds** — You provide your own wallet (HD xPub or static addresses). Customers pay directly to your addresses.
- **Read-only monitoring** — PayzCore watches the blockchain for incoming transactions and sends webhook notifications. That's it.
- **Protection Key security** — Sensitive operations like wallet management, address changes, and API key regeneration require a Protection Key that only you set. PayzCore cannot perform these actions without your authorization.
- **Your responsibility** — You are responsible for securing your own wallets and private keys. PayzCore provides monitoring and notification only.

## Requirements

- WordPress 5.8 or later
- WooCommerce 7.0 or later
- PHP 7.4 or later
- A PayzCore account with an active project ([app.payzcore.com](https://app.payzcore.com))

## Installation

### From WordPress.org (Recommended)

1. In WordPress admin: **Plugins > Add New**
2. Search for **"PayzCore"**
3. Click **Install Now** then **Activate**

### Manual Upload

1. Download the latest ZIP from [WordPress.org](https://wordpress.org/plugins/payzcore-for-woocommerce/)
2. In WordPress admin: **Plugins > Add New > Upload Plugin**
3. Upload the ZIP file and click **Activate**

## Configuration

1. Go to **WooCommerce > Settings > Payments**
2. Enable **PayzCore (USDT/USDC)** and click **Manage**
3. Enter your **API Key** and **Webhook Secret** from your PayzCore project
4. Click **Test Connection** to verify — networks and tokens are auto-detected from your wallet
5. Set your webhook URL in the PayzCore dashboard to: `https://yourstore.com/?wc-api=payzcore`

## Features

- 5 blockchain networks: TRC20, BEP20, ERC20, Polygon, Arbitrum
- 2 tokens: USDT and USDC
- QR code and wallet address display at checkout
- Countdown timer with automatic status polling
- Webhook-based order status updates (HMAC-SHA256 signed)
- WooCommerce HPOS compatible
- API-driven network/token configuration (no manual setup)

## Documentation

Full documentation at [docs.payzcore.com/integrations/woocommerce](https://docs.payzcore.com/integrations/woocommerce)

## License

GPLv2 or later
