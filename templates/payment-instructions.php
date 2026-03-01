<?php
/**
 * Payment instructions template.
 *
 * Displays the crypto address, QR code, amount, and countdown timer
 * on the order-received (thank-you) page. Polls for payment status
 * via AJAX and auto-redirects when payment is confirmed.
 *
 * Available variables:
 *
 * @var int    $order_id         WooCommerce order ID.
 * @var string $payment_id       PayzCore payment UUID.
 * @var string $address          Blockchain address to receive funds.
 * @var string $expected_amount  Expected USDT amount (with micro-offset).
 * @var string $network          Blockchain network (TRC20, BEP20, ERC20, POLYGON, ARBITRUM).
 * @var string $token            Stablecoin token (USDT or USDC).
 * @var string $expires_at       ISO 8601 expiry timestamp.
 * @var string $qr_code          Base64-encoded QR code data URI.
 * @var string $notice           Optional notice from API (e.g. "Send exactly 50.003 USDT").
 * @var string $original_amount  Original amount before micro-offset (optional).
 * @var bool   $requires_txid    Whether customer must submit a tx hash.
 * @var string $confirm_endpoint PayzCore endpoint to POST tx_hash to.
 * @var string $nonce            Security nonce for AJAX requests.
 * @var string $txid_nonce       Security nonce for tx hash confirmation.
 * @var string $ajax_url         WordPress AJAX endpoint URL.
 * @var array  $texts            Customizable text strings from gateway settings.
 *
 * @package PayzCore
 */

defined( 'ABSPATH' ) || exit;

$payzcore_order     = wc_get_order( $order_id );
$payzcore_order_key = $payzcore_order ? $payzcore_order->get_order_key() : '';

// Backward compatibility: default to USDT for orders created before token support.
if ( empty( $token ) ) {
	$token = 'USDT';
}

$payzcore_network_labels = array(
	'TRC20'    => __( 'TRC20 (Tron)', 'payzcore-for-woocommerce' ),
	'BEP20'    => __( 'BEP20 (BNB Smart Chain)', 'payzcore-for-woocommerce' ),
	'ERC20'    => __( 'ERC20 (Ethereum)', 'payzcore-for-woocommerce' ),
	'POLYGON'  => __( 'Polygon', 'payzcore-for-woocommerce' ),
	'ARBITRUM' => __( 'Arbitrum', 'payzcore-for-woocommerce' ),
);
$payzcore_network_label = isset( $payzcore_network_labels[ $network ] ) ? $payzcore_network_labels[ $network ] : esc_html( $network );
?>

<div id="payzcore-payment-box"
	class="payzcore-payment-box"
	data-order-id="<?php echo esc_attr( $order_id ); ?>"
	data-order-key="<?php echo esc_attr( $payzcore_order_key ); ?>"
	data-payment-id="<?php echo esc_attr( $payment_id ); ?>"
	data-expires-at="<?php echo esc_attr( $expires_at ); ?>"
	data-requires-txid="<?php echo $requires_txid ? '1' : '0'; ?>">

	<div class="payzcore-payment-header">
		<div class="payzcore-payment-header-icon">
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="12" cy="12" r="10"></circle>
				<line x1="12" y1="8" x2="12" y2="12"></line>
				<line x1="12" y1="16" x2="12.01" y2="16"></line>
			</svg>
		</div>
		<div>
			<h3 class="payzcore-payment-title">
				<?php echo esc_html( $texts['payment_title'] ); ?>
			</h3>
			<p class="payzcore-payment-subtitle">
				<?php echo esc_html( $texts['payment_subtitle'] ); ?>
			</p>
		</div>
	</div>

	<div class="payzcore-payment-body">

		<div class="payzcore-amount-section">
			<span class="payzcore-label"><?php echo esc_html( $texts['amount_label'] ); ?></span>
			<div class="payzcore-amount-row">
				<span class="payzcore-amount" id="payzcore-amount">
					<?php echo esc_html( $expected_amount ); ?>
				</span>
				<span class="payzcore-amount-currency"><?php echo esc_html( $token ); ?></span>
				<button type="button"
					class="payzcore-copy-btn"
					data-copy="<?php echo esc_attr( $expected_amount ); ?>"
					title="<?php esc_attr_e( 'Copy amount', 'payzcore-for-woocommerce' ); ?>">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
						<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
					</svg>
				</button>
			</div>
			<span class="payzcore-amount-warning">
				<?php echo esc_html( $texts['amount_warning'] ); ?>
			</span>
		</div>

		<?php if ( ! empty( $notice ) ) : ?>
			<div class="payzcore-notice-section">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="12" cy="12" r="10"></circle>
					<line x1="12" y1="8" x2="12" y2="12"></line>
					<line x1="12" y1="16" x2="12.01" y2="16"></line>
				</svg>
				<span><?php echo esc_html( $notice ); ?></span>
			</div>
		<?php endif; ?>

		<div class="payzcore-network-badge">
			<span class="payzcore-network-dot"></span>
			<?php echo esc_html( $payzcore_network_label ); ?>
		</div>

		<?php if ( ! empty( $qr_code ) ) : ?>
			<div class="payzcore-qr-section">
				<img
					src="<?php echo esc_attr( $qr_code ); ?>"
					alt="<?php esc_attr_e( 'Payment QR Code', 'payzcore-for-woocommerce' ); ?>"
					class="payzcore-qr-image"
					width="200"
					height="200"
				/>
			</div>
		<?php endif; ?>

		<div class="payzcore-address-section">
			<span class="payzcore-label"><?php echo esc_html( $texts['address_label'] ); ?></span>
			<div class="payzcore-address-row">
				<code class="payzcore-address" id="payzcore-address">
					<?php echo esc_html( $address ); ?>
				</code>
				<button type="button"
					class="payzcore-copy-btn"
					data-copy="<?php echo esc_attr( $address ); ?>"
					title="<?php esc_attr_e( 'Copy address', 'payzcore-for-woocommerce' ); ?>">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
						<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
					</svg>
				</button>
			</div>
		</div>

		<div class="payzcore-timer-section">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="12" cy="12" r="10"></circle>
				<polyline points="12 6 12 12 16 14"></polyline>
			</svg>
			<span class="payzcore-timer-label"><?php echo esc_html( $texts['time_remaining'] ); ?></span>
			<span class="payzcore-timer" id="payzcore-timer">--:--</span>
		</div>

		<?php if ( $requires_txid ) : ?>
			<div class="payzcore-txid-section" id="payzcore-txid-section">
				<span class="payzcore-label"><?php echo esc_html( $texts['txid_label'] ); ?></span>
				<div class="payzcore-txid-row">
					<input type="text"
						id="payzcore-txid-input"
						class="payzcore-txid-input"
						placeholder="<?php echo esc_attr( $texts['txid_placeholder'] ); ?>"
						autocomplete="off"
						spellcheck="false"
					/>
				</div>
				<button type="button"
					id="payzcore-txid-submit"
					class="payzcore-txid-submit">
					<?php echo esc_html( $texts['txid_button'] ); ?>
				</button>
				<div class="payzcore-txid-message" id="payzcore-txid-message" style="display: none;"></div>
			</div>
		<?php endif; ?>

		<div class="payzcore-status-section" id="payzcore-status" style="display: none;">
			<div class="payzcore-status-icon" id="payzcore-status-icon"></div>
			<span class="payzcore-status-text" id="payzcore-status-text"></span>
		</div>

	</div>

	<div class="payzcore-payment-footer">
		<div class="payzcore-step">
			<span class="payzcore-step-number">1</span>
			<span class="payzcore-step-text"><?php echo esc_html( $texts['step1'] ); ?></span>
		</div>
		<div class="payzcore-step">
			<span class="payzcore-step-number">2</span>
			<span class="payzcore-step-text">
				<?php
				echo esc_html( str_replace(
					array( '{amount}', '{token}' ),
					array( $expected_amount, $token ),
					$texts['step2_template']
				) );
				?>
			</span>
		</div>
		<div class="payzcore-step">
			<span class="payzcore-step-number">3</span>
			<span class="payzcore-step-text"><?php echo esc_html( $texts['step3'] ); ?></span>
		</div>
	</div>

</div>
