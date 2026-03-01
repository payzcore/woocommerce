<?php
/**
 * WooCommerce PayzCore payment gateway.
 *
 * Integrates PayzCore blockchain transaction monitoring with WooCommerce
 * checkout. Creates monitoring requests via the PayzCore API and displays
 * payment instructions (address, QR code, countdown) to customers.
 *
 * Supports multiple networks (TRC20, BEP20, ERC20, Polygon, Arbitrum) and
 * tokens (USDT, USDC).
 *
 * @package PayzCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Gateway_PayzCore
 *
 * WooCommerce payment gateway for stablecoin payments monitored by PayzCore.
 */
class WC_Gateway_PayzCore extends WC_Payment_Gateway {

	/**
	 * PayzCore API client.
	 *
	 * @var PayzCore_API|null
	 */
	private $api = null;

	/**
	 * API key for PayzCore.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Webhook secret for signature verification.
	 *
	 * @var string
	 */
	private $webhook_secret;

	/**
	 * PayzCore API base URL.
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * Payment expiry in seconds.
	 *
	 * @var int
	 */
	private $expiry_time;

	/**
	 * Static wallet address for static wallet mode.
	 *
	 * @var string
	 */
	private $static_address;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'payzcore';
		$this->has_fields         = true;
		$this->method_title       = __( 'PayzCore (Stablecoin)', 'payzcore-for-woocommerce' );
		$this->method_description = __(
			'Accept USDT/USDC stablecoin payments via PayzCore blockchain transaction monitoring across multiple networks (TRC20, BEP20, ERC20, Polygon, Arbitrum). Non-custodial: funds are sent directly to your wallet.',
			'payzcore-for-woocommerce'
		);

		$this->supports = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->api_key        = $this->get_option( 'api_key' );
		$this->webhook_secret = $this->get_option( 'webhook_secret' );
		$this->api_url        = $this->get_option( 'api_url', PayzCore_API::DEFAULT_BASE_URL );
		$this->expiry_time    = absint( $this->get_option( 'expiry_time', 3600 ) );
		$this->static_address = $this->get_option( 'static_address', '' );

		// Generic stablecoin icon (multiple tokens possible).
		$this->icon = PAYZCORE_PLUGIN_URL . 'assets/images/usdt-icon.svg';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_payzcore_check_status', array( $this, 'ajax_check_status' ) );
		add_action( 'wp_ajax_nopriv_payzcore_check_status', array( $this, 'ajax_check_status' ) );
		add_action( 'wp_ajax_payzcore_confirm_txid', array( $this, 'ajax_confirm_txid' ) );
		add_action( 'wp_ajax_nopriv_payzcore_confirm_txid', array( $this, 'ajax_confirm_txid' ) );
	}

	/**
	 * Check if the gateway is available for use.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( empty( $this->api_key ) || empty( $this->webhook_secret ) ) {
			return false;
		}
		// Stablecoin amounts are USD-pegged. Only available when store currency is USD.
		if ( 'USD' !== get_woocommerce_currency() ) {
			return false;
		}
		// Require at least one network from the synced API config.
		if ( empty( $this->get_enabled_networks() ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Process and save admin options with connection validation.
	 *
	 * After saving, tests the API key connectivity and shows appropriate
	 * admin notices. Also checks for common configuration issues.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		// Re-read saved values.
		$api_key = $this->get_option( 'api_key' );

		if ( empty( $api_key ) ) {
			return $saved;
		}

		// Fetch project config from PayzCore API (available networks/tokens).
		$this->fetch_and_cache_config();

		return $saved;
	}

	/**
	 * Fetch project config from PayzCore API and cache it.
	 *
	 * Called on admin save ("Test Connection"). Stores available networks,
	 * tokens, and default_token in WooCommerce options.
	 *
	 * @return bool True on success.
	 */
	private function fetch_and_cache_config() {
		$api_key = $this->get_option( 'api_key' );
		$api_url = $this->get_option( 'api_url', PayzCore_API::DEFAULT_BASE_URL );

		$response = wp_remote_get(
			untrailingslashit( $api_url ) . '/v1/config',
			array(
				'timeout' => 10,
				'headers' => array(
					'x-api-key'    => $api_key,
					'Content-Type' => 'application/json',
					'User-Agent'   => 'payzcore-woocommerce/' . PAYZCORE_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: error message */
					__( 'PayzCore connection failed: %s. Check the API URL and server connectivity.', 'payzcore-for-woocommerce' ),
					$response->get_error_message()
				)
			);
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $status_code || 403 === $status_code ) {
			WC_Admin_Settings::add_error(
				__( 'PayzCore API Key is invalid or the project is inactive. Check your credentials at app.payzcore.com.', 'payzcore-for-woocommerce' )
			);
			return false;
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: HTTP status code */
					__( 'PayzCore API returned HTTP %s. Please try again.', 'payzcore-for-woocommerce' ),
					$status_code
				)
			);
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			WC_Admin_Settings::add_error(
				__( 'PayzCore returned an invalid response.', 'payzcore-for-woocommerce' )
			);
			return false;
		}

		$networks      = isset( $body['networks'] ) ? $body['networks'] : array();
		$default_token = isset( $body['default_token'] ) ? $body['default_token'] : 'USDT';

		// Cache the config.
		$this->update_option( '_cached_networks', $networks );
		$this->update_option( '_cached_default_token', $default_token );
		$this->update_option( '_cached_at', current_time( 'mysql' ) );

		if ( empty( $networks ) ) {
			WC_Admin_Settings::add_error(
				__( 'Connected but no wallet configured in your PayzCore project. Add a wallet at app.payzcore.com to accept payments.', 'payzcore-for-woocommerce' )
			);
		} else {
			$network_names = array_map( function( $c ) {
				return $c['name'] . ' (' . implode( ', ', $c['tokens'] ) . ')';
			}, $networks );
			WC_Admin_Settings::add_message(
				sprintf(
					/* translators: 1: network list 2: sync time */
					__( 'PayzCore connected! Available: %1$s. Last sync: %2$s', 'payzcore-for-woocommerce' ),
					implode( ', ', $network_names ),
					current_time( 'mysql' )
				)
			);
		}

		return true;
	}

	/**
	 * Build the HTML for the Connection Status field in admin settings.
	 *
	 * Reads cached config and displays: connected chains + last sync time,
	 * a "not synced" prompt, or the last error message.
	 *
	 * @return string HTML string.
	 */
	private function get_config_status_html() {
		$cached_chains = $this->get_option( '_cached_networks', array() );
		$cached_at     = $this->get_option( '_cached_at', '' );
		$api_key       = $this->get_option( 'api_key', '' );

		if ( empty( $api_key ) ) {
			return '<div style="background:#1c1917;border:1px solid rgba(161,161,170,0.2);border-radius:6px;padding:12px 16px;font-size:13px;color:#a1a1aa;">'
				. esc_html__( 'Enter your API Key and click Save to connect with PayzCore.', 'payzcore-for-woocommerce' )
				. '</div>';
		}

		if ( ! empty( $cached_chains ) && is_array( $cached_chains ) ) {
			$chain_parts = array();
			foreach ( $cached_chains as $c ) {
				if ( is_array( $c ) && ! empty( $c['name'] ) ) {
					$tokens = ! empty( $c['tokens'] ) ? implode( ', ', $c['tokens'] ) : 'USDT';
					$chain_parts[] = esc_html( $c['name'] . ' (' . $tokens . ')' );
				} elseif ( is_string( $c ) ) {
					$chain_parts[] = esc_html( $c );
				}
			}

			$time_display = ! empty( $cached_at ) ? esc_html( $cached_at ) : '—';

			return '<div style="background:#022c22;border:1px solid rgba(6,182,212,0.3);border-radius:6px;padding:12px 16px;font-size:13px;color:#d1fae5;">'
				. '<strong style="color:#06b6d4;">' . esc_html__( 'Connected', 'payzcore-for-woocommerce' ) . '</strong><br>'
				. sprintf(
					/* translators: %s: list of available networks */
					esc_html__( 'Available networks: %s', 'payzcore-for-woocommerce' ),
					implode( ', ', $chain_parts )
				)
				. '<br>'
				. '<span style="color:#a1a1aa;font-size:12px;">'
				. sprintf(
					/* translators: %s: last sync date/time */
					esc_html__( 'Last synced: %s', 'payzcore-for-woocommerce' ),
					$time_display
				)
				. ' &mdash; ' . esc_html__( 'Save settings to re-sync.', 'payzcore-for-woocommerce' )
				. '</span>'
				. '</div>';
		}

		// Has API key but no cached config yet.
		return '<div style="background:#1c1917;border:1px solid rgba(245,158,11,0.3);border-radius:6px;padding:12px 16px;font-size:13px;color:#fbbf24;">'
			. esc_html__( 'Not synced yet. Click Save to connect and fetch your project configuration from PayzCore.', 'payzcore-for-woocommerce' )
			. '</div>';
	}

	/**
	 * Output the admin options with PayzCore branding header.
	 *
	 * @return void
	 */
	public function admin_options() {
		?>
		<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1500 1500" fill="#06b6d4" style="width:32px;height:32px;">
				<path d="M 475.7 1024.3 L 475.7 1258.2 L 709.6 1258.2 L 709.6 1024.3 L 1042.7 1024.3 C 1102.7 1024.3 1154.9 999.9 1194.4 960.8 C 1233.6 921.6 1257.9 869 1257.9 809.1 L 1257.9 457 C 1257.9 397 1233.6 344.8 1194.4 305.3 C 1155.2 266.1 1102.7 241.8 1042.7 241.8 L 690.9 241.8 C 631 241.8 578.7 266.1 539.2 305.3 C 499.7 344.5 475.7 397 475.7 457 L 475.7 790.1 L 7.9 790.1 L 7.9 1024 L 475.7 1024 Z M 241.8 790.4 L 241.8 457 C 241.8 333.5 292.2 221.3 372.7 138.8 C 455.2 58.3 567.4 7.9 690.9 7.9 L 1042.7 7.9 C 1166.2 7.9 1278.4 58.3 1360.9 138.8 C 1441.7 221.3 1492.1 333.5 1492.1 457 L 1492.1 808.8 C 1492.1 932.3 1441.7 1044.5 1361.2 1127 C 1279 1207.4 1166.5 1257.9 1043 1257.9 L 709.6 1257.9 L 709.6 1492.1 L 475.7 1492.1 L 475.7 1258.2 L 241.8 1258.2 Z M 938.2 475.7 L 796 475.7 C 773.4 475.7 751.2 485.2 736 501.8 C 719.1 516.7 709.9 539.2 709.9 561.8 L 709.9 790.1 L 938.2 790.1 C 960.8 790.1 983 780.6 998.2 764 C 1015.1 749.1 1024.3 726.5 1024.3 704 L 1024.3 561.8 C 1024.3 539.2 1014.8 517 998.2 501.8 C 983 485.2 960.5 475.7 938.2 475.7 Z"/>
			</svg>
			<div>
				<h2 style="margin:0;font-size:20px;color:#1e293b;">PayzCore</h2>
				<p style="margin:2px 0 0;font-size:13px;color:#64748b;">Stablecoin Transaction Monitoring</p>
			</div>
		</div>
		<?php
		if ( 'USD' !== get_woocommerce_currency() ) {
			?>
			<div class="notice notice-warning inline" style="margin:12px 0;">
				<p>
					<?php
					printf(
						/* translators: %s: current store currency code */
						esc_html__( 'Your store currency is %s. PayzCore stablecoin payments require USD as the store currency because USDT/USDC are pegged 1:1 to the US Dollar. The payment method will not appear at checkout until the store currency is set to USD.', 'payzcore-for-woocommerce' ),
						'<strong>' . esc_html( get_woocommerce_currency() ) . '</strong>'
					);
					?>
				</p>
			</div>
			<?php
		}
		parent::admin_options();
	}

	/**
	 * Get the API client instance.
	 *
	 * @return PayzCore_API
	 */
	private function get_api() {
		if ( null === $this->api ) {
			$this->api = new PayzCore_API( $this->api_key, $this->api_url );
		}
		return $this->api;
	}

	/**
	 * Define gateway settings fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$webhook_url = rest_url( 'payzcore/v1/webhook' );

		$this->form_fields = array(
			'setup_guide'    => array(
				'title'       => __( 'Setup Guide', 'payzcore-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					'<div style="background:#0c1222;border:1px solid rgba(6,182,212,0.3);border-radius:8px;padding:16px;margin:4px 0 8px;font-size:13px;line-height:1.6;color:#a1a1aa;">'
					. '<strong style="color:#06b6d4;font-size:14px;">%s</strong><br><br>'
					. '<strong>1.</strong> %s <a href="https://app.payzcore.com/register" target="_blank" style="color:#06b6d4;">app.payzcore.com</a><br>'
					. '<strong>2.</strong> %s<br>'
					. '<strong>3.</strong> %s<br>'
					. '<strong>4.</strong> %s <code style="background:#1a1a2e;padding:2px 6px;border-radius:3px;font-size:12px;color:#e4e4e7;">%s</code><br>'
					. '<strong>5.</strong> %s<br><br>'
					. '<span style="color:#f59e0b;">⚠</span> %s'
					. '</div>',
					__( 'Before you begin:', 'payzcore-for-woocommerce' ),
					__( 'Create a PayzCore account at', 'payzcore-for-woocommerce' ),
					__( 'Create a Project and add a Wallet (HD xPub or static addresses) for the blockchain network you want to use.', 'payzcore-for-woocommerce' ),
					__( 'Copy your API Key and Webhook Secret from the project settings page.', 'payzcore-for-woocommerce' ),
					__( 'Set your Webhook URL in the PayzCore project to:', 'payzcore-for-woocommerce' ),
					esc_html( $webhook_url ),
					__( 'Enable the payment method below and save.', 'payzcore-for-woocommerce' ),
					__( 'You must have a wallet configured for the selected blockchain network in your PayzCore project. If no wallet is set up, payments will fail at checkout.', 'payzcore-for-woocommerce' )
				),
			),
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'payzcore-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PayzCore stablecoin payments', 'payzcore-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'payzcore-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown during checkout.', 'payzcore-for-woocommerce' ),
				'default'     => __( 'Stablecoin (USDT/USDC)', 'payzcore-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'payzcore-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown during checkout.', 'payzcore-for-woocommerce' ),
				'default'     => __( 'Pay with USDT or USDC stablecoin. Funds go directly to the merchant wallet.', 'payzcore-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'api_key'        => array(
				'title'       => __( 'API Key', 'payzcore-for-woocommerce' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: dashboard URL */
					__( 'Found in your PayzCore dashboard: %s → Projects → your project → Settings.', 'payzcore-for-woocommerce' ),
					'<a href="https://app.payzcore.com" target="_blank">app.payzcore.com</a>'
				),
				'default'     => '',
				'placeholder' => 'pk_live_...',
			),
			'config_status'  => array(
				'title'       => __( 'Connection Status', 'payzcore-for-woocommerce' ),
				'type'        => 'title',
				'description' => $this->get_config_status_html(),
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook Secret', 'payzcore-for-woocommerce' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: 1: webhook URL 2: dashboard URL */
					__( 'Shown once when you create a project (or regenerate keys). Set your Webhook URL in %2$s to: %1$s', 'payzcore-for-woocommerce' ),
					'<code>' . esc_html( $webhook_url ) . '</code>',
					'<a href="https://app.payzcore.com" target="_blank">PayzCore dashboard</a>'
				),
				'default'     => '',
				'placeholder' => 'whsec_...',
			),
			'api_url'        => array(
				'title'       => __( 'API URL', 'payzcore-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'PayzCore API endpoint. Change only if using a self-hosted instance.', 'payzcore-for-woocommerce' ),
				'default'     => PayzCore_API::DEFAULT_BASE_URL,
				'desc_tip'    => true,
			),
			'expiry_time'    => array(
				'title'       => __( 'Payment Expiry (seconds)', 'payzcore-for-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'Time in seconds before the payment monitoring expires. Minimum 300 (5 min), maximum 86400 (24 hr).', 'payzcore-for-woocommerce' ),
				'default'     => 3600,
				'desc_tip'    => true,
				'custom_attributes' => array(
					'min'  => 300,
					'max'  => 86400,
					'step' => 60,
				),
			),
			'static_address' => array(
				'title'       => __( 'Static Wallet Address (Optional)', 'payzcore-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'If set, all payments will use this fixed address. Customers submit their transaction hash (TxID) after sending. Leave empty to use HD wallet addresses derived automatically by PayzCore.', 'payzcore-for-woocommerce' ),
				'default'     => '',
				'placeholder' => 'T... / 0x...',
			),

			// --- Payment Page Texts ---

			'text_settings_heading' => array(
				'title'       => __( 'Payment Page Texts', 'payzcore-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Customize all customer-facing texts. Change these to display your preferred language on the payment page.', 'payzcore-for-woocommerce' ),
			),
			'text_payment_title' => array(
				'title'   => __( 'Page Title', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Complete Your Payment',
				'desc_tip' => true,
				'description' => __( 'Main heading on the payment page.', 'payzcore-for-woocommerce' ),
			),
			'text_payment_subtitle' => array(
				'title'   => __( 'Page Subtitle', 'payzcore-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => 'Send the exact amount below to the provided address',
				'desc_tip' => true,
				'description' => __( 'Subtitle text below the heading.', 'payzcore-for-woocommerce' ),
			),
			'text_amount_label' => array(
				'title'   => __( 'Amount Label', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Amount to Send',
				'desc_tip' => true,
			),
			'text_amount_warning' => array(
				'title'   => __( 'Amount Warning', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Send the exact amount including cents',
				'desc_tip' => true,
			),
			'text_address_label' => array(
				'title'   => __( 'Address Label', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Wallet Address',
				'desc_tip' => true,
			),
			'text_time_remaining' => array(
				'title'   => __( 'Time Remaining', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Time remaining:',
				'desc_tip' => true,
			),
			'text_step1' => array(
				'title'   => __( 'Step 1 Text', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Copy the address or scan the QR code',
				'desc_tip' => true,
			),
			'text_step2_template' => array(
				'title'   => __( 'Step 2 Text', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Send exactly {amount} {token}',
				'desc_tip' => true,
				'description' => __( 'Use {amount} and {token} as placeholders.', 'payzcore-for-woocommerce' ),
			),
			'text_step3' => array(
				'title'   => __( 'Step 3 Text', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Wait for blockchain confirmation (automatic)',
				'desc_tip' => true,
			),
			'text_status_waiting' => array(
				'title'   => __( 'Status: Waiting', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Checking blockchain for your transaction',
				'desc_tip' => true,
			),
			'text_status_confirming' => array(
				'title'   => __( 'Status: Confirming', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Transfer detected, confirming...',
				'desc_tip' => true,
			),
			'text_status_confirmed' => array(
				'title'   => __( 'Status: Confirmed', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Payment confirmed!',
				'desc_tip' => true,
			),
			'text_status_expired' => array(
				'title'   => __( 'Status: Expired', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Payment window expired',
				'desc_tip' => true,
			),
			'text_status_partial' => array(
				'title'   => __( 'Status: Partial', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Partial transfer detected',
				'desc_tip' => true,
			),
			'text_copied' => array(
				'title'   => __( 'Copied Button', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Copied!',
				'desc_tip' => true,
			),
			'text_redirecting' => array(
				'title'   => __( 'Redirecting Text', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Redirecting...',
				'desc_tip' => true,
			),
			'text_txid_label' => array(
				'title'   => __( 'Transaction Hash Label', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Transaction Hash (TxID)',
				'desc_tip' => true,
				'description' => __( 'Label for the transaction hash input in static wallet mode.', 'payzcore-for-woocommerce' ),
			),
			'text_txid_placeholder' => array(
				'title'   => __( 'Transaction Hash Placeholder', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Paste your transaction hash here',
				'desc_tip' => true,
			),
			'text_txid_button' => array(
				'title'   => __( 'Confirm Button Text', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Confirm Payment',
				'desc_tip' => true,
			),
			'text_txid_success' => array(
				'title'   => __( 'Confirmation Success Text', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Transaction submitted. Awaiting blockchain confirmation.',
				'desc_tip' => true,
			),
			'text_txid_invalid' => array(
				'title'   => __( 'Invalid Hash Text', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Invalid transaction hash format. Please enter a valid hex hash.',
				'desc_tip' => true,
			),
			'text_partial_detail' => array(
				'title'   => __( 'Partial Payment Detail', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Partial transfer detected - Please send the remaining amount to the same address',
				'desc_tip' => true,
			),
			'text_connection_issue' => array(
				'title'   => __( 'Connection Issue Text', 'payzcore-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'Connection issue. Still trying to check payment status...',
				'desc_tip' => true,
			),
		);
	}

	/**
	 * Validate the API key field.
	 *
	 * @param string $key   Field key.
	 * @param string $value Field value.
	 * @return string Sanitized value.
	 */
	public function validate_api_key_field( $key, $value ) {
		$value = sanitize_text_field( $value );
		if ( ! empty( $value ) && strpos( $value, 'pk_' ) !== 0 ) {
			WC_Admin_Settings::add_error(
				__( 'PayzCore API Key should start with "pk_". Please check your credentials.', 'payzcore-for-woocommerce' )
			);
		}
		return $value;
	}

	/**
	 * Render checkout payment fields.
	 *
	 * Shows blockchain network and token selectors when multiple networks are
	 * enabled. When only one network is configured, hidden fields are used.
	 *
	 * @return void
	 */
	public function payment_fields() {
		// Show description.
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		$networks = $this->get_enabled_networks();

		if ( empty( $networks ) ) {
			echo '<p style="color:#ef4444;">' . esc_html__( 'Payment configuration is not available. Please contact the store.', 'payzcore-for-woocommerce' ) . '</p>';
			return;
		}

		// Build per-network token map from cached config for JS.
		$network_tokens_map = array();
		foreach ( $networks as $network ) {
			$network_tokens_map[ $network ] = $this->get_network_tokens( $network );
		}

		// If only 1 network with 1 token, hidden fields (no UI needed).
		if ( count( $networks ) === 1 ) {
			$network = reset( $networks );
			$tokens  = $network_tokens_map[ $network ];
			echo '<input type="hidden" name="payzcore_network" value="' . esc_attr( $network ) . '" />';
			if ( count( $tokens ) === 1 ) {
				echo '<input type="hidden" name="payzcore_token" value="' . esc_attr( $tokens[0] ) . '" />';
				return;
			}
			// Single network, multiple tokens: show token selector only.
			$default_token = $this->get_default_token();
			echo '<div class="payzcore-network-select" style="margin-bottom:12px;">';
			echo '<p class="form-row form-row-wide">';
			echo '<label for="payzcore_token">' . esc_html__( 'Stablecoin', 'payzcore-for-woocommerce' ) . '</label>';
			echo '<select name="payzcore_token" id="payzcore_token" class="select" style="width:100%;">';
			foreach ( $tokens as $t ) {
				$t_label = 'USDT' === $t ? 'USDT (Tether)' : ( 'USDC' === $t ? 'USDC (USD Coin)' : $t );
				echo '<option value="' . esc_attr( $t ) . '"' . selected( $default_token, $t, false ) . '>' . esc_html( $t_label ) . '</option>';
			}
			echo '</select>';
			echo '</p>';
			echo '</div>';
			return;
		}

		// Multiple networks: show network + token selectors.
		$network_labels = array(
			'TRC20'    => 'TRC20 (Tron) - Most popular',
			'BEP20'    => 'BEP20 (BNB Smart Chain) - Low fees',
			'ERC20'    => 'ERC20 (Ethereum)',
			'POLYGON'  => 'Polygon - Lowest fees',
			'ARBITRUM' => 'Arbitrum (L2) - Low fees',
		);

		$token_labels = array(
			'USDT' => 'USDT (Tether)',
			'USDC' => 'USDC (USD Coin)',
		);

		echo '<div class="payzcore-network-select" style="margin-bottom:12px;">';
		echo '<p class="form-row form-row-wide">';
		echo '<label for="payzcore_network">' . esc_html__( 'Blockchain Network', 'payzcore-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr></label>';
		echo '<select name="payzcore_network" id="payzcore_network" class="select" style="width:100%;">';
		foreach ( $networks as $network ) {
			$label = isset( $network_labels[ $network ] ) ? $network_labels[ $network ] : $network;
			echo '<option value="' . esc_attr( $network ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</p>';

		// Token selector (dynamically updated by JS based on network selection).
		$default_token = $this->get_default_token();
		echo '<p class="form-row form-row-wide" id="payzcore_token_field">';
		echo '<label for="payzcore_token">' . esc_html__( 'Stablecoin', 'payzcore-for-woocommerce' ) . '</label>';
		echo '<select name="payzcore_token" id="payzcore_token" class="select" style="width:100%;">';
		// Initial options populated by JS on load.
		echo '</select>';
		echo '</p>';
		echo '</div>';

		// Inline JS to update token options based on selected network.
		// Uses DOM createElement/removeChild (no innerHTML) for safe content injection.
		?>
		<script>
		(function(){
			var networkEl = document.getElementById("payzcore_network");
			var tokenField = document.getElementById("payzcore_token_field");
			var tokenEl = document.getElementById("payzcore_token");
			if (!networkEl || !tokenEl) return;

			var networkTokens = <?php echo wp_json_encode( $network_tokens_map ); ?>;
			var tokenLabels = <?php echo wp_json_encode( $token_labels ); ?>;
			var defaultToken = <?php echo wp_json_encode( $default_token ); ?>;

			function update() {
				var tokens = networkTokens[networkEl.value] || ["USDT"];
				while (tokenEl.firstChild) {
					tokenEl.removeChild(tokenEl.firstChild);
				}
				for (var i = 0; i < tokens.length; i++) {
					var opt = document.createElement("option");
					opt.value = tokens[i];
					opt.textContent = tokenLabels[tokens[i]] || tokens[i];
					if (tokens[i] === defaultToken) opt.selected = true;
					tokenEl.appendChild(opt);
				}
				if (tokenField) {
					tokenField.style.display = tokens.length <= 1 ? "none" : "";
				}
			}
			networkEl.addEventListener("change", update);
			update();
		})();
		</script>
		<?php
	}

	/**
	 * Validate checkout payment fields.
	 *
	 * Ensures the selected network and token are valid according to the
	 * cached API config.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		// Nonce is verified by WooCommerce core (woocommerce-process-checkout-nonce).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC handles nonce.
		$network        = isset( $_POST['payzcore_network'] ) ? sanitize_text_field( wp_unslash( $_POST['payzcore_network'] ) ) : '';
		$valid_networks = $this->get_enabled_networks();

		if ( empty( $network ) || ! in_array( $network, $valid_networks, true ) ) {
			wc_add_notice( __( 'Please select a valid blockchain network.', 'payzcore-for-woocommerce' ), 'error' );
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC handles nonce.
		$token        = isset( $_POST['payzcore_token'] ) ? sanitize_text_field( wp_unslash( $_POST['payzcore_token'] ) ) : 'USDT';
		$valid_tokens = $this->get_network_tokens( $network );
		if ( ! in_array( $token, $valid_tokens, true ) ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: token name 2: network name */
					__( '%1$s is not available on %2$s. Please select a different token or network.', 'payzcore-for-woocommerce' ),
					$token,
					$network
				),
				'error'
			);
			return false;
		}

		return true;
	}

	/**
	 * Get enabled blockchain networks from cached API config.
	 *
	 * Reads network identifiers from the cached /v1/config response.
	 * Returns an empty array when no config has been synced yet.
	 *
	 * @return array List of enabled network identifiers (e.g. ['TRC20', 'BEP20']).
	 */
	private function get_enabled_networks() {
		$cached = $this->get_option( '_cached_networks', array() );
		if ( ! empty( $cached ) && is_array( $cached ) ) {
			return array_map( function( $c ) {
				return is_array( $c ) ? $c['network'] : $c;
			}, $cached );
		}
		return array();
	}

	/**
	 * Get the full cached network config with per-network token info.
	 *
	 * Returns the raw array from the /v1/config response, where each
	 * element has 'network', 'name', and 'tokens' keys.
	 *
	 * @return array List of network config objects.
	 */
	private function get_cached_network_config() {
		$cached = $this->get_option( '_cached_networks', array() );
		if ( ! empty( $cached ) && is_array( $cached ) ) {
			return $cached;
		}
		return array();
	}

	/**
	 * Get the tokens available for a specific network from cached config.
	 *
	 * @param string $network Network identifier (e.g. 'TRC20', 'BEP20').
	 * @return array List of token identifiers (e.g. ['USDT', 'USDC']).
	 */
	private function get_network_tokens( $network ) {
		$config = $this->get_cached_network_config();
		foreach ( $config as $c ) {
			if ( is_array( $c ) && isset( $c['network'] ) && $c['network'] === $network ) {
				return ! empty( $c['tokens'] ) ? $c['tokens'] : array( 'USDT' );
			}
		}
		return array( 'USDT' );
	}

	/**
	 * Get the default token from cached API config.
	 *
	 * @return string Token identifier (USDT or USDC).
	 */
	private function get_default_token() {
		$cached = $this->get_option( '_cached_default_token', '' );
		if ( ! empty( $cached ) ) {
			return $cached;
		}
		return 'USDT';
	}

	/**
	 * Process the payment for a given order.
	 *
	 * Creates a PayzCore monitoring request and stores the payment details
	 * as order metadata. Redirects to the thank-you page where payment
	 * instructions are displayed.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array Result with 'result' and 'redirect' keys.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Order not found. Please try again.', 'payzcore-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount = $order->get_total();

		if ( floatval( $amount ) <= 0 ) {
			wc_add_notice( __( 'Invalid order amount.', 'payzcore-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Read network/token from checkout selection (with fallbacks).
		// Nonce is verified by WooCommerce core (woocommerce-process-checkout-nonce).
		$enabled = $this->get_enabled_networks();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC handles nonce.
		$network = isset( $_POST['payzcore_network'] ) ? sanitize_text_field( wp_unslash( $_POST['payzcore_network'] ) ) : ( ! empty( $enabled ) ? $enabled[0] : 'TRC20' );
		if ( ! in_array( $network, $enabled, true ) ) {
			$network = ! empty( $enabled ) ? $enabled[0] : 'TRC20';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC handles nonce.
		$token = isset( $_POST['payzcore_token'] ) ? sanitize_text_field( wp_unslash( $_POST['payzcore_token'] ) ) : $this->get_default_token();

		// Ensure token is valid for the selected network (API-driven).
		$valid_tokens = $this->get_network_tokens( $network );
		if ( ! in_array( $token, $valid_tokens, true ) ) {
			$token = $valid_tokens[0];
		}

		$params = array(
			'amount'           => $amount,
			'network'          => $network,
			'token'            => $token,
			'external_ref'     => $order->get_billing_email(),
			'external_order_id' => (string) $order_id,
			'expires_in'       => $this->expiry_time,
			'metadata'         => array(
				'source'       => 'woocommerce',
				'order_id'     => $order_id,
				'order_key'    => $order->get_order_key(),
				'customer'     => $order->get_formatted_billing_full_name(),
				'currency'     => $order->get_currency(),
				'store_url'    => home_url(),
			),
		);

		// Static wallet mode: include the configured address.
		if ( ! empty( $this->static_address ) ) {
			$params['address'] = $this->static_address;
		}

		$result = $this->get_api()->create_payment( $params );

		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
			$error_code    = $result->get_error_code();

			// Log the full technical error for the merchant.
			$order->add_order_note(
				sprintf(
					/* translators: 1: error code 2: error message */
					__( 'PayzCore API error [%1$s]: %2$s', 'payzcore-for-woocommerce' ),
					$error_code,
					$error_message
				)
			);

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( 'PayzCore payment creation failed for order #%d: [%s] %s', $order_id, $error_code, $error_message ),
					array( 'source' => 'payzcore' )
				);
			}

			// Show a user-friendly message based on error type.
			$is_wallet_error = (
				false !== strpos( $error_message, 'no wallet' ) ||
				false !== strpos( $error_message, 'Wallet not found' ) ||
				false !== strpos( $error_message, 'no xPub configured' ) ||
				false !== strpos( $error_message, 'No static address' )
			);

			if ( $is_wallet_error ) {
				$customer_message = __( 'This payment method is temporarily unavailable. The store has not yet configured the required wallet for the selected blockchain network. Please choose a different payment method or contact the store.', 'payzcore-for-woocommerce' );
			} elseif ( 'payzcore_auth_error' === $error_code ) {
				$customer_message = __( 'This payment method is temporarily unavailable due to a configuration issue. Please choose a different payment method or contact the store.', 'payzcore-for-woocommerce' );
			} elseif ( 'payzcore_rate_limit' === $error_code ) {
				$customer_message = __( 'Too many requests. Please wait a moment and try again.', 'payzcore-for-woocommerce' );
			} else {
				$customer_message = __( 'Unable to initiate payment. Please try again or choose a different payment method.', 'payzcore-for-woocommerce' );
			}

			wc_add_notice( $customer_message, 'error' );

			return array( 'result' => 'failure' );
		}

		if ( empty( $result['success'] ) || empty( $result['payment'] ) ) {
			wc_add_notice( __( 'Unexpected response from the payment monitoring service. Please try again.', 'payzcore-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$payment = $result['payment'];

		$token_name = ! empty( $payment['token'] ) ? $payment['token'] : $token;

		$order->update_meta_data( '_payzcore_payment_id', sanitize_text_field( $payment['id'] ) );
		$order->update_meta_data( '_payzcore_address', sanitize_text_field( $payment['address'] ) );
		$order->update_meta_data( '_payzcore_expected_amount', sanitize_text_field( $payment['amount'] ) );
		$order->update_meta_data( '_payzcore_network', sanitize_text_field( $payment['network'] ) );
		$order->update_meta_data( '_payzcore_token', sanitize_text_field( $token_name ) );
		$order->update_meta_data( '_payzcore_expires_at', sanitize_text_field( $payment['expires_at'] ) );

		if ( ! empty( $payment['qr_code'] ) && preg_match( '/^data:image\/(png|jpeg|gif|svg\+xml|webp);base64,/', $payment['qr_code'] ) ) {
			$order->update_meta_data( '_payzcore_qr_code', $payment['qr_code'] );
		}

		// Static wallet mode response fields.
		if ( ! empty( $payment['notice'] ) ) {
			$order->update_meta_data( '_payzcore_notice', sanitize_text_field( $payment['notice'] ) );
		}
		if ( ! empty( $payment['original_amount'] ) ) {
			$order->update_meta_data( '_payzcore_original_amount', sanitize_text_field( $payment['original_amount'] ) );
		}
		if ( ! empty( $payment['requires_txid'] ) ) {
			$order->update_meta_data( '_payzcore_requires_txid', 'yes' );
		}
		if ( ! empty( $payment['confirm_endpoint'] ) ) {
			$order->update_meta_data( '_payzcore_confirm_endpoint', sanitize_text_field( $payment['confirm_endpoint'] ) );
		}

		$order->set_status(
			'on-hold',
			sprintf(
				/* translators: 1: amount 2: token name 3: network name 4: address */
				__( 'Awaiting %1$s %2$s transfer on %3$s to %4$s', 'payzcore-for-woocommerce' ),
				$payment['amount'],
				$token_name,
				$payment['network'],
				$payment['address']
			)
		);

		$order->save();

		if ( function_exists( 'wc_reduce_stock_levels' ) ) {
			wc_reduce_stock_levels( $order_id );
		}

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Display payment instructions on the thank-you page.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		$payment_id       = $order->get_meta( '_payzcore_payment_id', true );
		$address          = $order->get_meta( '_payzcore_address', true );
		$expected_amount  = $order->get_meta( '_payzcore_expected_amount', true );
		$network          = $order->get_meta( '_payzcore_network', true );
		$token            = $order->get_meta( '_payzcore_token', true );
		$expires_at       = $order->get_meta( '_payzcore_expires_at', true );
		$qr_code          = $order->get_meta( '_payzcore_qr_code', true );
		$notice           = $order->get_meta( '_payzcore_notice', true );
		$original_amount  = $order->get_meta( '_payzcore_original_amount', true );
		$requires_txid    = $order->get_meta( '_payzcore_requires_txid', true );
		$confirm_endpoint = $order->get_meta( '_payzcore_confirm_endpoint', true );

		// Backward compatibility: default to USDT for orders created before token support.
		if ( empty( $token ) ) {
			$token = 'USDT';
		}

		if ( empty( $payment_id ) || empty( $address ) ) {
			return;
		}

		if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return;
		}

		$template_args = array(
			'order_id'         => $order_id,
			'payment_id'       => $payment_id,
			'address'          => $address,
			'expected_amount'  => $expected_amount,
			'network'          => $network,
			'token'            => $token,
			'expires_at'       => $expires_at,
			'qr_code'          => $qr_code,
			'notice'           => $notice,
			'original_amount'  => $original_amount,
			'requires_txid'    => ( 'yes' === $requires_txid ),
			'confirm_endpoint' => $confirm_endpoint,
			'nonce'            => wp_create_nonce( 'payzcore_check_status' ),
			'txid_nonce'       => wp_create_nonce( 'payzcore_confirm_txid' ),
			'ajax_url'         => admin_url( 'admin-ajax.php' ),
			'texts'            => array(
				'payment_title'    => $this->get_option( 'text_payment_title', 'Complete Your Payment' ),
				'payment_subtitle' => $this->get_option( 'text_payment_subtitle', 'Send the exact amount below to the provided address' ),
				'amount_label'     => $this->get_option( 'text_amount_label', 'Amount to Send' ),
				'amount_warning'   => $this->get_option( 'text_amount_warning', 'Send the exact amount including cents' ),
				'address_label'    => $this->get_option( 'text_address_label', 'Wallet Address' ),
				'time_remaining'   => $this->get_option( 'text_time_remaining', 'Time remaining:' ),
				'step1'            => $this->get_option( 'text_step1', 'Copy the address or scan the QR code' ),
				'step2_template'   => $this->get_option( 'text_step2_template', 'Send exactly {amount} {token}' ),
				'step3'            => $this->get_option( 'text_step3', 'Wait for blockchain confirmation (automatic)' ),
				'txid_label'       => $this->get_option( 'text_txid_label', 'Transaction Hash (TxID)' ),
				'txid_placeholder' => $this->get_option( 'text_txid_placeholder', 'Paste your transaction hash here' ),
				'txid_button'      => $this->get_option( 'text_txid_button', 'Confirm Payment' ),
				'txid_success'     => $this->get_option( 'text_txid_success', 'Transaction submitted. Awaiting blockchain confirmation.' ),
			),
		);

		$template_path = PAYZCORE_PLUGIN_DIR . 'templates/payment-instructions.php';
		if ( file_exists( $template_path ) ) {
			extract( $template_args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $template_path;
		}
	}

	/**
	 * Add payment instructions to order emails.
	 *
	 * @param WC_Order $order          The order object.
	 * @param bool     $sent_to_admin  Whether this is an admin email.
	 * @param bool     $plain_text     Whether this is a plain text email.
	 * @return void
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		if ( ! in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
			return;
		}

		$address         = $order->get_meta( '_payzcore_address', true );
		$expected_amount = $order->get_meta( '_payzcore_expected_amount', true );
		$network         = $order->get_meta( '_payzcore_network', true );
		$token           = $order->get_meta( '_payzcore_token', true );
		$expires_at      = $order->get_meta( '_payzcore_expires_at', true );

		// Backward compatibility: default to USDT for orders created before token support.
		if ( empty( $token ) ) {
			$token = 'USDT';
		}

		if ( empty( $address ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n";
			echo "==========\n";
			echo esc_html__( 'PAYMENT INSTRUCTIONS', 'payzcore-for-woocommerce' ) . "\n";
			echo "==========\n\n";
			printf(
				/* translators: 1: amount 2: token name */
				esc_html__( 'Amount: %1$s %2$s', 'payzcore-for-woocommerce' ) . "\n",
				esc_html( $expected_amount ),
				esc_html( $token )
			);
			printf(
				/* translators: %s: blockchain network */
				esc_html__( 'Network: %s', 'payzcore-for-woocommerce' ) . "\n",
				esc_html( $network )
			);
			printf(
				/* translators: %s: wallet address */
				esc_html__( 'Address: %s', 'payzcore-for-woocommerce' ) . "\n",
				esc_html( $address )
			);
			if ( ! empty( $expires_at ) ) {
				printf(
					/* translators: %s: expiry date/time */
					esc_html__( 'Expires: %s', 'payzcore-for-woocommerce' ) . "\n",
					esc_html( $expires_at )
				);
			}
			echo "\n" . esc_html__( 'Send the exact amount shown above to the address provided. Your order will be confirmed automatically once the transfer is detected on the blockchain.', 'payzcore-for-woocommerce' ) . "\n\n";
		} else {
			?>
			<div style="margin: 16px 0; padding: 16px; background: #1a1a2e; border: 1px solid rgba(6,182,212,0.3); border-radius: 8px; color: #e4e4e7;">
				<h3 style="margin: 0 0 12px; color: #06b6d4; font-size: 16px;">
					<?php esc_html_e( 'Payment Instructions', 'payzcore-for-woocommerce' ); ?>
				</h3>
				<table style="width: 100%; border-collapse: collapse;">
					<tr>
						<td style="padding: 4px 8px 4px 0; color: #a1a1aa; font-size: 13px;"><?php esc_html_e( 'Amount:', 'payzcore-for-woocommerce' ); ?></td>
						<td style="padding: 4px 0; font-weight: 600; font-size: 14px; color: #ffffff;">
							<?php echo esc_html( $expected_amount ); ?> <?php echo esc_html( $token ); ?>
						</td>
					</tr>
					<tr>
						<td style="padding: 4px 8px 4px 0; color: #a1a1aa; font-size: 13px;"><?php esc_html_e( 'Network:', 'payzcore-for-woocommerce' ); ?></td>
						<td style="padding: 4px 0; font-size: 14px; color: #ffffff;">
							<?php echo esc_html( $network ); ?>
						</td>
					</tr>
					<tr>
						<td style="padding: 4px 8px 4px 0; color: #a1a1aa; font-size: 13px;"><?php esc_html_e( 'Address:', 'payzcore-for-woocommerce' ); ?></td>
						<td style="padding: 4px 0; font-family: monospace; font-size: 13px; word-break: break-all; color: #ffffff;">
							<?php echo esc_html( $address ); ?>
						</td>
					</tr>
				</table>
				<p style="margin: 12px 0 0; font-size: 12px; color: #a1a1aa;">
					<?php esc_html_e( 'Send the exact amount shown above to the address provided. Your order will be confirmed automatically once the transfer is detected on the blockchain.', 'payzcore-for-woocommerce' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Enqueue frontend scripts and styles.
	 *
	 * Only loaded on the order-received (thank-you) page when PayzCore is the
	 * selected payment method.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-received' ) );
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || $order->get_payment_method() !== $this->id ) {
				return;
			}
		}

		wp_enqueue_style(
			'payzcore-checkout',
			PAYZCORE_PLUGIN_URL . 'assets/css/payzcore-checkout.css',
			array(),
			PAYZCORE_VERSION
		);

		wp_enqueue_script(
			'payzcore-checkout',
			PAYZCORE_PLUGIN_URL . 'assets/js/payzcore-checkout.js',
			array( 'jquery' ),
			PAYZCORE_VERSION,
			true
		);

		wp_localize_script(
			'payzcore-checkout',
			'payzcore_params',
			array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'payzcore_check_status' ),
				'txid_nonce'     => wp_create_nonce( 'payzcore_confirm_txid' ),
				'poll_interval'  => 15000,
				'i18n'           => array(
					'copied'           => wp_kses( $this->get_option( 'text_copied', 'Copied!' ), array() ),
					'copy_failed'      => __( 'Copy failed', 'payzcore-for-woocommerce' ),
					'expired'          => wp_kses( $this->get_option( 'text_status_expired', 'Payment window expired' ), array() ),
					'confirmed'        => wp_kses( $this->get_option( 'text_status_confirmed', 'Payment confirmed!' ), array() ),
					'confirming'       => wp_kses( $this->get_option( 'text_status_confirming', 'Transfer detected, confirming...' ), array() ),
					'partial'          => wp_kses( $this->get_option( 'text_status_partial', 'Partial transfer detected' ), array() ),
					'redirecting'      => wp_kses( $this->get_option( 'text_redirecting', 'Redirecting...' ), array() ),
					'status_error'     => __( 'Unable to check status', 'payzcore-for-woocommerce' ),
					'txid_success'     => wp_kses( $this->get_option( 'text_txid_success', 'Transaction submitted. Awaiting blockchain confirmation.' ), array() ),
					'txid_error'       => __( 'Failed to submit transaction hash. Please try again.', 'payzcore-for-woocommerce' ),
					'txid_empty'       => __( 'Please enter a transaction hash.', 'payzcore-for-woocommerce' ),
					'txid_invalid'     => wp_kses( $this->get_option( 'text_txid_invalid', 'Invalid transaction hash format. Please enter a valid hex hash.' ), array() ),
					'txid_submitting'  => __( 'Submitting...', 'payzcore-for-woocommerce' ),
					'partial_detail'   => wp_kses( $this->get_option( 'text_partial_detail', 'Partial transfer detected - Please send the remaining amount to the same address' ), array() ),
					'connection_issue' => wp_kses( $this->get_option( 'text_connection_issue', 'Connection issue. Still trying to check payment status...' ), array() ),
				),
			)
		);
	}

	/**
	 * AJAX handler for payment status polling.
	 *
	 * Called from the thank-you page to check the current payment status
	 * against the PayzCore API. Secured with nonce verification.
	 *
	 * @return void
	 */
	public function ajax_check_status() {
		check_ajax_referer( 'payzcore_check_status', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'payzcore-for-woocommerce' ) ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'payzcore-for-woocommerce' ) ) );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		if ( $order->get_order_key() !== $order_key ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order key.', 'payzcore-for-woocommerce' ) ) );
		}

		$status = $order->get_status();

		if ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$redirect = $order->get_checkout_order_received_url();
			wp_send_json_success(
				array(
					'status'      => 'paid',
					'redirect'    => $redirect,
					'paid_amount' => $order->get_meta( '_payzcore_paid_amount', true ),
					'tx_hash'     => $order->get_meta( '_payzcore_tx_hash', true ),
				)
			);
		}

		if ( 'cancelled' === $status ) {
			wp_send_json_success( array( 'status' => 'expired' ) );
		}

		$payment_id = $order->get_meta( '_payzcore_payment_id', true );

		if ( empty( $payment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Payment ID not found.', 'payzcore-for-woocommerce' ) ) );
		}

		$result = $this->get_api()->get_payment( $payment_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() )
			);
		}

		$payment_status = isset( $result['payment']['status'] ) ? $result['payment']['status'] : 'pending';

		wp_send_json_success(
			array(
				'status'      => $payment_status,
				'paid_amount' => isset( $result['payment']['paid_amount'] ) ? $result['payment']['paid_amount'] : '0',
			)
		);
	}

	/**
	 * AJAX handler for submitting a transaction hash (static wallet mode).
	 *
	 * Proxies the tx_hash to the PayzCore confirm endpoint stored in order meta.
	 * Secured with nonce verification and order key check.
	 *
	 * @return void
	 */
	public function ajax_confirm_txid() {
		check_ajax_referer( 'payzcore_confirm_txid', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$tx_hash  = isset( $_POST['tx_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['tx_hash'] ) ) : '';

		if ( ! $order_id || empty( $tx_hash ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'payzcore-for-woocommerce' ) ) );
		}

		// Validate tx_hash format (hex string, 10-128 chars)
		$clean_hash = preg_replace( '/^0x/i', '', $tx_hash );
		if ( ! preg_match( '/^[a-fA-F0-9]{10,128}$/', $clean_hash ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid transaction hash format.', 'payzcore-for-woocommerce' ) ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'payzcore-for-woocommerce' ) ) );
		}

		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		if ( $order->get_order_key() !== $order_key ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order key.', 'payzcore-for-woocommerce' ) ) );
		}

		$confirm_endpoint = $order->get_meta( '_payzcore_confirm_endpoint', true );

		if ( empty( $confirm_endpoint ) ) {
			wp_send_json_error( array( 'message' => __( 'Confirmation endpoint not available for this order.', 'payzcore-for-woocommerce' ) ) );
		}

		$result = $this->get_api()->confirm_payment( $confirm_endpoint, $tx_hash );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$order->update_meta_data( '_payzcore_submitted_txid', $tx_hash );
		$order->add_order_note(
			sprintf(
				/* translators: %s: transaction hash */
				__( 'Customer submitted transaction hash: %s', 'payzcore-for-woocommerce' ),
				$tx_hash
			)
		);
		$order->save();

		wp_send_json_success( array( 'message' => __( 'Transaction hash submitted successfully.', 'payzcore-for-woocommerce' ) ) );
	}
}
