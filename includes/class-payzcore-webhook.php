<?php
/**
 * PayzCore webhook handler.
 *
 * Receives and processes webhook notifications from PayzCore
 * about blockchain transaction status changes.
 *
 * @package PayzCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PayzCore_Webhook
 *
 * Handles incoming webhook events from PayzCore and updates
 * WooCommerce order statuses accordingly.
 */
class PayzCore_Webhook {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Register the REST API webhook endpoint.
	 *
	 * @return void
	 */
	public function register_rest_route() {
		register_rest_route(
			'payzcore/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle an incoming webhook from PayzCore.
	 *
	 * Verifies the HMAC-SHA256 signature, locates the WooCommerce order,
	 * and updates the order status based on the event type.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$raw_body  = $request->get_body();
		$signature = $request->get_header( 'X-PayzCore-Signature' );
		$event     = $request->get_header( 'X-PayzCore-Event' );
		$timestamp = $request->get_header( 'X-PayzCore-Timestamp' );

		if ( empty( $signature ) || empty( $raw_body ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Missing signature or body.' ),
				400
			);
		}

		// Validate timestamp (±5 minutes tolerance, required)
		if ( empty( $timestamp ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Missing timestamp header.' ),
				401
			);
		}
		$ts = strtotime( sanitize_text_field( $timestamp ) );
		if ( false === $ts || abs( time() - $ts ) > 300 ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( 'Webhook rejected: timestamp too old or invalid (' . $timestamp . ')', array( 'source' => 'payzcore' ) );
			}
			return new WP_REST_Response(
				array( 'error' => 'Timestamp validation failed.' ),
				401
			);
		}

		$gateway_settings = get_option( 'woocommerce_payzcore_settings', array() );
		$webhook_secret   = isset( $gateway_settings['webhook_secret'] ) ? $gateway_settings['webhook_secret'] : '';

		if ( empty( $webhook_secret ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Webhook secret not configured.' ),
				500
			);
		}

		if ( ! $this->verify_signature( $raw_body, $signature, $webhook_secret, $timestamp ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Invalid signature.' ),
				401
			);
		}

		$payload = json_decode( $raw_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $payload ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Invalid JSON payload.' ),
				400
			);
		}

		$payment_event    = isset( $payload['event'] ) ? sanitize_text_field( $payload['event'] ) : '';
		$payment_id       = isset( $payload['payment_id'] ) ? sanitize_text_field( $payload['payment_id'] ) : '';
		$external_order_id = isset( $payload['external_order_id'] ) ? sanitize_text_field( $payload['external_order_id'] ) : '';
		$paid_amount      = isset( $payload['paid_amount'] ) ? sanitize_text_field( $payload['paid_amount'] ) : '0';
		$tx_hash          = isset( $payload['tx_hash'] ) ? sanitize_text_field( $payload['tx_hash'] ) : '';
		$network          = isset( $payload['network'] ) ? sanitize_text_field( $payload['network'] ) : '';
		$token            = isset( $payload['token'] ) ? sanitize_text_field( $payload['token'] ) : '';

		if ( empty( $payment_event ) || empty( $payment_id ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Missing required fields.' ),
				400
			);
		}

		$order = $this->find_order_by_payment_id( $payment_id, $external_order_id );

		if ( ! $order ) {
			return new WP_REST_Response(
				array( 'error' => 'Order not found.' ),
				404
			);
		}

		$already_processed = $order->get_meta( '_payzcore_webhook_processed', true );
		if ( 'yes' === $already_processed && in_array( $payment_event, array( 'payment.completed', 'payment.overpaid' ), true ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'message' => 'Already processed.',
				),
				200
			);
		}

		$this->process_event( $order, $payment_event, $paid_amount, $tx_hash, $network, $token, $payload );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Process a webhook event and update the WooCommerce order.
	 *
	 * @param WC_Order $order         The WooCommerce order.
	 * @param string   $event         Event name.
	 * @param string   $paid_amount   Amount received.
	 * @param string   $tx_hash       Blockchain transaction hash.
	 * @param string   $network       Blockchain network.
	 * @param string   $token         Stablecoin token (USDT, USDC).
	 * @param array    $payload       Full webhook payload.
	 * @return void
	 */
	private function process_event( $order, $event, $paid_amount, $tx_hash, $network, $token, $payload ) {
		$explorer_url = $this->get_explorer_url( $tx_hash, $network );

		// Resolve token name: prefer webhook payload, fallback to order meta, then default.
		if ( empty( $token ) ) {
			$token = $order->get_meta( '_payzcore_token', true );
		}
		if ( empty( $token ) ) {
			$token = 'USDT';
		}

		switch ( $event ) {
			case 'payment.completed':
				$order->update_meta_data( '_payzcore_paid_amount', $paid_amount );
				$order->update_meta_data( '_payzcore_tx_hash', $tx_hash );
				$order->update_meta_data( '_payzcore_webhook_processed', 'yes' );

				$note = sprintf(
					/* translators: 1: paid amount 2: token name 3: network name 4: explorer link */
					__( 'Payment confirmed. %1$s %2$s received on %3$s. Transaction: %4$s', 'payzcore-for-woocommerce' ),
					$paid_amount,
					$token,
					$network,
					$explorer_url ? '<a href="' . esc_url( $explorer_url ) . '" target="_blank">' . esc_html( substr( $tx_hash, 0, 16 ) ) . '...</a>' : esc_html( $tx_hash )
				);
				$order->add_order_note( $note );

				// Let payment_complete() handle status transition and emails
				$order->payment_complete( $tx_hash );
				if ( $this->order_is_virtual( $order ) ) {
					$order->set_status( 'completed', __( 'Virtual order auto-completed.', 'payzcore-for-woocommerce' ) );
				}
				break;

			case 'payment.overpaid':
				$order->update_meta_data( '_payzcore_paid_amount', $paid_amount );
				$order->update_meta_data( '_payzcore_tx_hash', $tx_hash );
				$order->update_meta_data( '_payzcore_webhook_processed', 'yes' );

				$expected = $order->get_meta( '_payzcore_expected_amount', true );
				$note     = sprintf(
					/* translators: 1: paid amount 2: token name 3: expected amount 4: network name 5: explorer link */
					__( 'Overpayment detected. %1$s %2$s received (expected %3$s) on %4$s. Transaction: %5$s', 'payzcore-for-woocommerce' ),
					$paid_amount,
					$token,
					$expected,
					$network,
					$explorer_url ? '<a href="' . esc_url( $explorer_url ) . '" target="_blank">' . esc_html( substr( $tx_hash, 0, 16 ) ) . '...</a>' : esc_html( $tx_hash )
				);
				$order->add_order_note( $note );

				// Let payment_complete() handle status transition and emails
				$order->payment_complete( $tx_hash );
				if ( $this->order_is_virtual( $order ) ) {
					$order->set_status( 'completed', __( 'Virtual order auto-completed (overpaid).', 'payzcore-for-woocommerce' ) );
				}
				break;

			case 'payment.partial':
				$expected = $order->get_meta( '_payzcore_expected_amount', true );
				$note     = sprintf(
					/* translators: 1: paid amount 2: expected amount 3: token name 4: network name */
					__( 'Partial transfer detected. %1$s of %2$s %3$s received on %4$s. Awaiting remaining amount.', 'payzcore-for-woocommerce' ),
					$paid_amount,
					$expected,
					$token,
					$network
				);
				$order->add_order_note( $note );
				$order->update_meta_data( '_payzcore_paid_amount', $paid_amount );
				break;

			case 'payment.expired':
				$note = __( 'Payment monitoring window expired. No sufficient transfer detected.', 'payzcore-for-woocommerce' );
				$order->add_order_note( $note );
				$order->set_status( 'cancelled', $note );

				if ( function_exists( 'wc_increase_stock_levels' ) ) {
					wc_increase_stock_levels( $order );
				}
				break;

			case 'payment.cancelled':
				$note = __( 'Payment was cancelled by the merchant.', 'payzcore-for-woocommerce' );
				$order->add_order_note( $note );
				$order->set_status( 'cancelled', $note );

				if ( function_exists( 'wc_increase_stock_levels' ) ) {
					wc_increase_stock_levels( $order );
				}
				break;

			default:
				$order->add_order_note(
					sprintf(
						/* translators: %s: event name */
						__( 'Received unhandled event: %s', 'payzcore-for-woocommerce' ),
						$event
					)
				);
				break;
		}

		$order->save();
	}

	/**
	 * Find a WooCommerce order by PayzCore payment ID.
	 *
	 * Searches order meta for the stored PayzCore payment ID. Falls back
	 * to the external_order_id if available.
	 *
	 * @param string $payment_id       PayzCore payment UUID.
	 * @param string $external_order_id WooCommerce order ID sent as external_order_id.
	 * @return WC_Order|false
	 */
	private function find_order_by_payment_id( $payment_id, $external_order_id = '' ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_query' => array(
					array(
						'key'   => '_payzcore_payment_id',
						'value' => $payment_id,
					),
				),
			)
		);

		if ( ! empty( $orders ) ) {
			return $orders[0];
		}

		if ( ! empty( $external_order_id ) && is_numeric( $external_order_id ) ) {
			$order = wc_get_order( absint( $external_order_id ) );
			if ( $order && $order->get_meta( '_payzcore_payment_id', true ) === $payment_id ) {
				return $order;
			}
		}

		return false;
	}

	/**
	 * Verify the HMAC-SHA256 webhook signature.
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature Signature from X-PayzCore-Signature header.
	 * @param string $secret    Webhook secret.
	 * @return bool
	 */
	private function verify_signature( $body, $signature, $secret, $timestamp = '' ) {
		if ( empty( $timestamp ) ) {
			return false;
		}
		// Signature covers timestamp + body
		$message  = $timestamp . '.' . $body;
		$expected = hash_hmac( 'sha256', $message, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Check if all items in an order are virtual (no shipping needed).
	 *
	 * @param WC_Order $order The order to check.
	 * @return bool
	 */
	private function order_is_virtual( $order ) {
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && ! $product->is_virtual() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build a blockchain explorer URL for the transaction.
	 *
	 * @param string $tx_hash Transaction hash.
	 * @param string $network Blockchain network (TRC20, BEP20, ERC20, POLYGON, ARBITRUM).
	 * @return string|false Explorer URL or false if not available.
	 */
	private function get_explorer_url( $tx_hash, $network ) {
		if ( empty( $tx_hash ) ) {
			return false;
		}

		$explorers = array(
			'TRC20'    => 'https://tronscan.org/#/transaction/',
			'BEP20'    => 'https://bscscan.com/tx/',
			'ERC20'    => 'https://etherscan.io/tx/',
			'POLYGON'  => 'https://polygonscan.com/tx/',
			'ARBITRUM' => 'https://arbiscan.io/tx/',
		);

		if ( isset( $explorers[ $network ] ) ) {
			return $explorers[ $network ] . $tx_hash;
		}

		return false;
	}
}
