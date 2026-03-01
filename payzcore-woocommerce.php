<?php
/**
 * Plugin Name: PayzCore for WooCommerce
 * Plugin URI: https://github.com/payzcore/woocommerce
 * Description: Accept stablecoin payments via PayzCore blockchain transaction monitoring. Non-custodial USDT/USDC monitoring on TRC20, BEP20, ERC20, Polygon, and Arbitrum networks.
 * Version: 1.0.0
 * Author: PayzCore
 * Author URI: https://payzcore.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: payzcore-for-woocommerce
 * Requires at least: 5.8
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 *
 * @package PayzCore
 */

defined( 'ABSPATH' ) || exit;

define( 'PAYZCORE_VERSION', '1.0.0' );
define( 'PAYZCORE_PLUGIN_FILE', __FILE__ );
define( 'PAYZCORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAYZCORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check if WooCommerce is active before initializing.
 *
 * @return bool
 */
function payzcore_is_woocommerce_active() {
	if ( in_array(
		'woocommerce/woocommerce.php',
		apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ),
		true
	) ) {
		return true;
	}
	if ( is_multisite() ) {
		$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
		return isset( $network_plugins['woocommerce/woocommerce.php'] );
	}
	return false;
}

/**
 * Display an admin notice if WooCommerce is not active.
 *
 * @return void
 */
function payzcore_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: WooCommerce plugin name */
				esc_html__( '%s requires WooCommerce to be installed and active.', 'payzcore-for-woocommerce' ),
				'<strong>PayzCore for WooCommerce</strong>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Initialize the plugin.
 *
 * @return void
 */
function payzcore_init() {
	if ( ! payzcore_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'payzcore_woocommerce_missing_notice' );
		return;
	}

	require_once PAYZCORE_PLUGIN_DIR . 'includes/class-payzcore-api.php';
	require_once PAYZCORE_PLUGIN_DIR . 'includes/class-payzcore-webhook.php';
	require_once PAYZCORE_PLUGIN_DIR . 'includes/class-wc-gateway-payzcore.php';

	add_filter( 'woocommerce_payment_gateways', 'payzcore_add_gateway' );

	$webhook_handler = new PayzCore_Webhook();
	$webhook_handler->register_hooks();
}
add_action( 'plugins_loaded', 'payzcore_init' );

/**
 * Register the PayzCore payment gateway with WooCommerce.
 *
 * @param array $gateways Existing gateways.
 * @return array
 */
function payzcore_add_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_PayzCore';
	return $gateways;
}

/**
 * Declare HPOS compatibility.
 *
 * @return void
 */
function payzcore_declare_hpos_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
}
add_action( 'before_woocommerce_init', 'payzcore_declare_hpos_compatibility' );

/**
 * Add settings link on the plugins page.
 *
 * @param array $links Existing links.
 * @return array
 */
function payzcore_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payzcore' ) ),
		esc_html__( 'Settings', 'payzcore-for-woocommerce' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'payzcore_plugin_action_links' );
