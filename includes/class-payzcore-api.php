<?php
/**
 * PayzCore API client.
 *
 * Communicates with the PayzCore blockchain monitoring API using
 * the WordPress HTTP API (wp_remote_*).
 *
 * @package PayzCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayzCore_API
 *
 * Handles all HTTP communication with the PayzCore API.
 */
class PayzCore_API {

	/**
	 * Default API base URL.
	 *
	 * @var string
	 */
	const DEFAULT_BASE_URL = 'https://api.payzcore.com';

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * API key for authentication.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Base URL of the PayzCore API.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor.
	 *
	 * @param string $api_key  API key (pk_live_xxx).
	 * @param string $base_url Optional custom API base URL.
	 */
	public function __construct( $api_key, $base_url = '' ) {
		$this->api_key  = $api_key;
		$this->base_url = ! empty( $base_url ) ? untrailingslashit( $base_url ) : self::DEFAULT_BASE_URL;
	}

	/**
	 * Create a new payment monitoring request.
	 *
	 * @param array $params {
	 *     Payment parameters.
	 *
	 *     @type float  $amount           Required. Payment amount in stablecoin.
	 *     @type string $network          Required. Blockchain network (TRC20, BEP20, ERC20, POLYGON, ARBITRUM).
	 *     @type string $token            Optional. Stablecoin token (USDT or USDC). Default: USDT.
	 *     @type string $external_ref     Required. External reference (e.g. customer email).
	 *     @type string $external_order_id Optional. External order identifier.
	 *     @type int    $expires_in       Optional. Expiry in seconds (300-86400).
	 *     @type array  $metadata         Optional. Additional metadata key-value pairs.
	 * }
	 * @return array|WP_Error API response data on success, WP_Error on failure.
	 */
	public function create_payment( $params ) {
		return $this->post( '/v1/payments', $params );
	}

	/**
	 * Get a payment status by ID.
	 *
	 * Performs a real-time blockchain check for pending payments.
	 *
	 * @param string $payment_id PayzCore payment UUID.
	 * @return array|WP_Error API response data on success, WP_Error on failure.
	 */
	public function get_payment( $payment_id ) {
		return $this->get( '/v1/payments/' . sanitize_text_field( $payment_id ) );
	}

	/**
	 * Submit a transaction hash for confirmation (static wallet mode).
	 *
	 * @param string $endpoint The confirm endpoint URL from the payment response.
	 * @param string $tx_hash  The blockchain transaction hash.
	 * @return array|WP_Error API response data on success, WP_Error on failure.
	 */
	public function confirm_payment( $endpoint, $tx_hash ) {
		$url = ( strpos( $endpoint, 'http' ) === 0 ) ? $endpoint : $this->base_url . $endpoint;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->get_headers(),
				'body'    => wp_json_encode( array( 'tx_hash' => $tx_hash ) ),
			)
		);

		return $this->handle_response( $response );
	}

	/**
	 * Execute a GET request.
	 *
	 * @param string $path API endpoint path.
	 * @return array|WP_Error
	 */
	private function get( $path ) {
		$url = $this->base_url . $path;

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->get_headers(),
			)
		);

		return $this->handle_response( $response );
	}

	/**
	 * Execute a POST request.
	 *
	 * @param string $path API endpoint path.
	 * @param array  $body Request body data.
	 * @return array|WP_Error
	 */
	private function post( $path, $body ) {
		$url = $this->base_url . $path;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->get_headers(),
				'body'    => wp_json_encode( $body ),
			)
		);

		return $this->handle_response( $response );
	}

	/**
	 * Build request headers.
	 *
	 * @return array
	 */
	private function get_headers() {
		return array(
			'Content-Type' => 'application/json',
			'x-api-key'    => $this->api_key,
			'User-Agent'   => 'payzcore-woocommerce/' . PAYZCORE_VERSION,
		);
	}

	/**
	 * Process the API response.
	 *
	 * @param array|WP_Error $response WordPress HTTP API response.
	 * @return array|WP_Error Decoded response body or WP_Error.
	 */
	private function handle_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'payzcore_network_error',
				sprintf(
					/* translators: %s: error message */
					__( 'PayzCore API connection failed: %s', 'payzcore-for-woocommerce' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'payzcore_parse_error',
				__( 'Invalid response from PayzCore API.', 'payzcore-for-woocommerce' )
			);
		}

		if ( $status_code >= 200 && $status_code < 300 ) {
			return $decoded;
		}

		$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unknown API error.', 'payzcore-for-woocommerce' );

		$error_code = 'payzcore_api_error';
		if ( 401 === $status_code ) {
			$error_code = 'payzcore_auth_error';
		} elseif ( 429 === $status_code ) {
			$error_code = 'payzcore_rate_limit';
		} elseif ( 404 === $status_code ) {
			$error_code = 'payzcore_not_found';
		} elseif ( 400 === $status_code ) {
			$error_code = 'payzcore_validation_error';
		}

		return new WP_Error( $error_code, $error_message, array( 'status' => $status_code ) );
	}
}
