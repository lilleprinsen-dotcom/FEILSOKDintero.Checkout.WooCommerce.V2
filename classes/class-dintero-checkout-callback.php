<?php
/**
 * Handle callbacks from Dintero.
 *
 * @package Dintero_Checkout/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for handling callbacks from Dintero.
 */
class Dintero_Checkout_Callback {

	/**
	 * Register callback action.
	 */
	public function __construct() {
		add_action( 'woocommerce_api_dintero_callback', array( $this, 'callback' ) );
		add_action( 'dintero_scheduled_callback', array( $this, 'handle_callback' ), 10, 3 );
	}

	/**
	 * Handle the callback from Dintero.
	 *
	 * @return void
	 */
	public function callback() {
		$get_params = wp_json_encode( filter_var_array( $_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		Dintero_Checkout_Logger::log( "CALLBACK: Callback triggered by Dintero. Data: $get_params" );
		$merchant_reference = filter_input( INPUT_GET, 'merchant_reference', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$transaction_id     = filter_input( INPUT_GET, 'transaction_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$error              = filter_input( INPUT_GET, 'error', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( empty( $merchant_reference ) ) {
			Dintero_Checkout_Logger::log( 'CALLBACK ERROR [merchant_reference]: The merchant reference is missing from the callback.' );
			http_response_code( 400 );
			die;
		}

		// Per request by Dintero, if the order doesn't exist, respond with 500.
		$maybe_order = $this->get_order_from_reference( $merchant_reference );
		if ( empty( $maybe_order ) ) {
			http_response_code( 500 );
			die;
		}

		if ( ! $this->maybe_schedule_callback( $transaction_id, $merchant_reference, $error ) ) {
			http_response_code( 500 );
			die;
		}

		http_response_code( 200 );
		die;
	}

	/**
	 * Maybe schedules a callback handler.
	 *
	 * Only schedules one if there are none already pending for the same transaction id and reference.
	 * This is done to prevent race conditions between simultaneous callbacks from Dintero that can happen
	 * on rare occasions. We can do this since we don't trust the data from Dintero in the callback, and will
	 * always fetch the order from their API when handling a callback.
	 *
	 * @param string $transaction_id The dintero transaction id.
	 * @param string $merchant_reference The Merchant Reference from Dintero.
	 * @param string $error Any error message from Dintero.
	 * @return boolean True if the action was successfully scheduled or already scheduled, false if it failed to schedule.
	 */
	public function maybe_schedule_callback( $transaction_id, $merchant_reference, $error ) {
		$callback_signature = sanitize_key( strtolower( $merchant_reference . '_' . $transaction_id ) );
		$group              = 'dintero_callback_' . $callback_signature;
		$action_args        = array(
			'transaction_id'     => $transaction_id,
			'merchant_reference' => $merchant_reference,
			'error'              => $error,
		);

		if ( false !== as_has_scheduled_action( 'dintero_scheduled_callback', $action_args, $group ) ) {
			Dintero_Checkout_Logger::log( "CALLBACK [action_scheduler]: The merchant reference $merchant_reference and transaction id $transaction_id has already been scheduled for processing." );
			return true;
		}

		$as_args           = array(
			'hook'   => 'dintero_scheduled_callback',
			'status' => ActionScheduler_Store::STATUS_PENDING,
			'group'  => $group,
		);
		$scheduled_actions = as_get_scheduled_actions( $as_args, OBJECT );

		/**
		 * Loop all actions to check if this one has been scheduled already.
		 *
		 * @var ActionScheduler_Action $action The action from the Action scheduler.
		 */
		foreach ( $scheduled_actions as $action ) {
			$action_args = $action->get_args();
			if ( $merchant_reference === ( $action_args['merchant_reference'] ?? '' ) && $transaction_id === ( $action_args['transaction_id'] ?? '' ) ) {
				Dintero_Checkout_Logger::log( "CALLBACK [action_scheduler]: The merchant reference $merchant_reference and transaction id $transaction_id has already been scheduled for processing." );
				return true;
			}
		}

		// If we get here, we should be good to create a new scheduled action, since none are currently scheduled for this order.
		$scheduled_action = as_schedule_single_action(
			time() + 60, // 1 Minute in the future.
			'dintero_scheduled_callback',
			$action_args,
			$group,
			true
		);

		if ( empty( $scheduled_action ) ) {
			Dintero_Checkout_Logger::log( "CALLBACK ERROR [action_scheduler]: Failed to schedule the callback for merchant reference $merchant_reference and transaction id $transaction_id." );
			return false;
		}

		return true;
	}

	/**
	 * Handle callback error events. Sent by Dintero.
	 *
	 * @param string   $error The error type.
	 * @param WC_Order $order The WooCommerce order.
	 * @return void
	 */
	public function handle_error_callback( $error, $order ) {
		$order_note = true;
		switch ( $error ) {
			case 'authorization':
				$note = 'The customer failed to authorize the payment.';
				break;
			case 'failed':
				$note = 'The transaction was rejected by Dintero, or an error occurred during transaction processing.';
				break;
			case 'cancelled':
				$note = 'The customer canceled the checkout payment.';
				break;
			case 'captured':
				$note = 'The transaction capture operation failed during auto-capture.';
				break;
			default:
				$note       = "Unknown event - [$error]";
				$order_note = false;
				break;
		}

		Dintero_Checkout_Logger::log( sprintf( 'CALLBACK: %s WC order id: %s.', $note, $order->get_id() ) );
		if ( $order_note ) {
			$order->add_order_note( $note );
		}
	}

	/**
	 * Handle normal callbacks from Dintero.
	 *
	 * @param string $transaction_id The Transaction id from Dintero.
	 * @param string $merchant_reference The Merchant Reference from Dintero.
	 * @param string $error Any error message from Dintero.
	 * @return void
	 */
	public function handle_callback( $transaction_id, $merchant_reference, $error ) {
		// Get the order relevant for the callback.
		$order = $this->get_order_from_reference( $merchant_reference );
		if ( empty( $order ) ) {
			Dintero_Checkout_Logger::log( "CALLBACK ERROR [wc_order]: Could not find a WooCommerce order with the merchant reference $merchant_reference." );
			return;
		}

		// Handle any error callbacks from Dintero.
		if ( ! empty( $error ) ) {
			$this->handle_error_callback( $error, $order );
			return;
		}

		// Get the order from Dintero.
		$dintero_order = Dintero()->api->get_order( $transaction_id );
		if ( is_wp_error( $dintero_order ) ) {
			Dintero_Checkout_Logger::log( sprintf( 'CALLBACK ERROR [dintero_order]: Failed to retrieve the order from Dintero. WC order id: %s (transaction ID: %s).', $order->get_id(), $transaction_id ) );
			return;
		}

		switch ( $dintero_order['status'] ) {
			case 'AUTHORIZED':
				Dintero_Checkout_Logger::log( sprintf( 'CALLBACK: Handling AUTHORIZED order status. Maybe triggering payment_complete. WC order id: %s (transaction ID: %s).', $order->get_id(), $transaction_id ) );
				dintero_confirm_order( $order, $transaction_id );
				break;
			case 'AUTHORIZATION_VOIDED':
				Dintero_Checkout_Logger::log( sprintf( 'CALLBACK: Handling AUTHORIZATION_VOIDED order status. Setting order status to CANCELLED. WC order id: %s (transaction ID: %s).', $order->get_id(), $transaction_id ) );
				$order->update_status( 'cancelled', __( 'The order was CANCELED in the Dintero.', 'dintero-checkout-for-woocommerce' ) );
				$order->save();
				break;
			case 'CAPTURED':
				Dintero_Checkout_Logger::log( sprintf( 'CALLBACK: Handling CAPTURED order status. Maybe triggering payment_complete. WC order id: %s (transaction ID: %s).', $order->get_id(), $transaction_id ) );
				/* Some payment methods (e.g., Swish) are immediately captured, and thus bypass the authorized status. We have to account for this: */
				dintero_confirm_order( $order, $transaction_id );
				break;
			case 'REFUNDED':
				Dintero_Checkout_Logger::log( sprintf( 'CALLBACK: Handling REFUNDED order status. WC order id: %s (transaction ID: %s).', $order->get_id(), $transaction_id ) );
				break;
			case 'DECLINED':
			case 'FAILED':
				Dintero_Checkout_Logger::log( sprintf( "CALLBACK: Handling {$dintero_order['status']} order status. Setting order status to FAILED. WC order id: %s (transaction ID: %s).", $order->get_id(), $transaction_id ) );
				$order->update_status( 'failed', __( 'The order was not approved by Dintero.', 'dintero-checkout-for-woocommerce' ) );
				$order->save();
				break;
			default:
				Dintero_Checkout_Logger::log( sprintf( "CALLBACK: Unknown order status on callback: {$dintero_order['status']}. WC order id: %s (transaction ID: %s)", $order->get_id(), $transaction_id ) );
				break;
		}
	}

	/**
	 * Get the order from the reference.
	 *
	 * @param string $merchant_reference The merchant reference from Dintero.
	 * @return WC_Order|null
	 */
	public function get_order_from_reference( $merchant_reference ) {
		$order_id = dintero_get_order_id_by_merchant_reference( $merchant_reference );

		// Check that we get a order id.
		if ( empty( $order_id ) ) {
			Dintero_Checkout_Logger::log( "CALLBACK ERROR [order_id]: Could not get an order_id from the merchant reference $merchant_reference." );
			return null;
		}

		$order = wc_get_order( $order_id );

		// Check if we get a valid order.
		if ( empty( $order ) ) {
			Dintero_Checkout_Logger::log( "CALLBACK ERROR [order]: Could not get an order from the merchant reference $merchant_reference." );
			return null;
		}

		return $order;
	}

	/**
	 * Handles any errors in the callback from Dintero.
	 *
	 * @param string   $error The error code.
	 * @param WC_Order $order The WooCommerce order.
	 * @return string
	 */
	public function get_error_message( $error, $order ) {
		$show_on_order_page = true;
		switch ( $error ) {
			case 'authorization':
				$note = 'The customer failed to authorize the payment.';
				break;
			case 'failed':
				$note = 'The transaction was rejected by Dintero, or an error occurred during transaction processing.';
				break;
			case 'cancelled':
				$note = 'The customer canceled the checkout payment.';
				break;
			case 'captured':
				$note = 'The transaction capture operation failed during auto-capture.';
				break;
			default:
				$note               = 'Unknown event.';
				$show_on_order_page = false;
				break;
		}

		if ( $show_on_order_page ) {
			$order->add_order_note( $note );
		}

		return $note;
	}

	/**
	 * Retrieve the callback URL.
	 *
	 * @static
	 * @return string The callback URL (relative to the home URL).
	 */
	public static function callback_url() {
		// Events to listen to when they happen in the back office.
		$events = '&report_event=CAPTURE&report_event=REFUND&report_event=VOID';

		return add_query_arg(
			array(
				'delay_callback'     => 120, /* seconds. */
				'report_error'       => 'true',
				'sid_parameter_name' => 'sid',
			),
			home_url( '/wc-api/dintero_callback/' )
		) . $events;
	}

	/**
	 * Determine whether Dintero is running on localhost.
	 *
	 * @return boolean TRUE if running on localhost, otherwise FALSE.
	 */
	public static function is_localhost() {
		return ( isset( $_SERVER['REMOTE_ADDR'] ) && in_array( $_SERVER['REMOTE_ADDR'], array( '127.0.0.1', '::1' ), true ) || isset( $_SERVER['HTTP_HOST'] ) && 'localhost' === substr( wp_unslash( $_SERVER['HTTP_HOST'] ), 0, 9 ) ); // phpcs:ignore
	}
}
new Dintero_Checkout_Callback();
