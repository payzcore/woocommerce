<?php
/**
 * WooCommerce Cart/Checkout Blocks support for PayzCore.
 *
 * Follows the official integration pattern: a class extending
 * AbstractPaymentMethodType registered on
 * `woocommerce_blocks_payment_method_type_registration`, plus a client
 * script that calls `window.wc.wcBlocksRegistry.registerPaymentMethod()`.
 * See https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/
 *
 * The block sends the selected network/token as `paymentMethodData`; the
 * Checkout block converts it to $_POST before calling the gateway's
 * validate_fields() and process_payment(), so the classic code path is reused.
 *
 * @package PayzCore
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * PayzCore payment method for the Checkout block.
 */
final class PayzCore_Blocks_Payment_Method extends AbstractPaymentMethodType {

	/**
	 * Payment method name (must match the gateway id).
	 *
	 * @var string
	 */
	protected $name = 'payzcore';

	/**
	 * Gateway instance.
	 *
	 * @var WC_Gateway_PayzCore|null
	 */
	private $gateway = null;

	/**
	 * Load settings and the gateway instance.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_payzcore_settings', array() );
	}

	/**
	 * Lazily resolve the gateway (payment gateways may not be loaded during initialize()).
	 *
	 * @return WC_Gateway_PayzCore|null
	 */
	private function get_gateway() {
		if ( null === $this->gateway && function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways      = WC()->payment_gateways()->payment_gateways();
			$this->gateway = isset( $gateways['payzcore'] ) ? $gateways['payzcore'] : null;
		}
		return $this->gateway;
	}

	/**
	 * Whether the payment method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = $this->get_gateway();
		return $gateway && 'yes' === $gateway->enabled && $gateway->is_available();
	}

	/**
	 * Register and return the client script handle.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'payzcore-blocks',
			PAYZCORE_PLUGIN_URL . 'assets/js/payzcore-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			PAYZCORE_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'payzcore-blocks', 'payzcore-for-woocommerce' );
		}
		return array( 'payzcore-blocks' );
	}

	/**
	 * Data exposed to the client script as getSetting( 'payzcore_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->get_gateway();
		if ( ! $gateway ) {
			return array(
				'title'    => $this->get_setting( 'title' ),
				'networks' => array(),
				'supports' => array( 'products' ),
			);
		}
		return $gateway->get_blocks_checkout_data();
	}
}
