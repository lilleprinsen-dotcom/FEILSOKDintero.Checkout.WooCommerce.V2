<?php
/**
 * Utility functions.
 *
 * @package Dintero_Checkout/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Equivalent to WP's get_the_ID() with HPOS support.
 *
 * @return int the order ID or false.
 */
//phpcs:ignore
function dintero_get_the_ID() {
	$hpos_enabled = dintero_is_hpos_enabled();
	$order_id     = $hpos_enabled ? filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT ) : get_the_ID();
	if ( empty( $order_id ) ) {
		return false;
	}

	return $order_id;
}

/**
 * Whether HPOS is enabled.
 *
 * @return bool
 */
function dintero_is_hpos_enabled() {
	// CustomOrdersTableController was introduced in WC 6.4.
	if ( class_exists( CustomOrdersTableController::class ) ) {
		return wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
	}

	return false;
}

/**
 * Add a button for changing gateway (intended to be used on the checkout page).
 *
 * @return void
 */
function dintero_checkout_wc_show_another_gateway_button() {
	$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
	if ( count( $available_gateways ) > 1 ) {
		$select_another_method_text = __( 'Select another payment method', 'dintero-checkout-for-woocommerce' );

		?>
		<p class="dintero-checkout-select-other-wrapper">
			<a class="checkout-button button" href="#" id="dintero-checkout-select-other">
				<?php echo esc_html( $select_another_method_text ); ?>
			</a>
		</p>
		<?php
	}
}

/**
 * Unsets all sessions set by Dintero.
 *
 * @return void
 */
function dintero_unset_sessions() {
	WC()->session->__unset( 'dintero_checkout_session_id' );
	WC()->session->__unset( 'dintero_merchant_reference' );
	WC()->session->__unset( 'dintero_checkout_subscription_session' );
}

/**
 * Prints error message as notices.
 *
 * Sometimes an error message cannot be printed (e.g., in a cronjob environment) where there is
 * no front end to display the error message, or otherwise irrelevant for a human. For that reason, we have to check if the print functions are undefined.
 *
 * @param WP_Error $wp_error A WordPress error object.
 * @return void
 */
function dintero_print_error_message( $wp_error ) {
	if ( is_ajax() ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			$print = 'wc_add_notice';
		}
	} elseif ( function_exists( 'wc_print_notice' ) ) {
		$print = 'wc_print_notice';
	}

	if ( ! isset( $print ) ) {
		return;
	}

	foreach ( $wp_error->get_error_messages() as $error ) {
		$message = $error;
		if ( is_array( $error ) ) {
			$error   = array_filter(
				$error,
				function ( $e ) {
					return ! empty( $e );
				}
			);
			$message = implode( ' ', $error );
		}

		$print( $message, 'error' );
	}
}

/**
 * Sets the shipping method in WooCommerce from Dintero.
 *
 * @param array|bool $data The shipping data from Dintero. False if not set.
 * @return void
 */
function dintero_update_wc_shipping( $data ) {
	$merchant_reference = WC()->session->get( 'dintero_merchant_reference' );
	if ( empty( $merchant_reference ) ) {
		return;
	}

	if ( empty( $data ) ) {
		return;
	}

	do_action( 'dintero_update_shipping_data', $data );
	set_transient( "dintero_shipping_data_{$merchant_reference}", $data, HOUR_IN_SECONDS );

	$chosen_shipping_methods   = array();
	$chosen_shipping_methods[] = wc_clean( $data['id'] );
	WC()->session->set( 'chosen_shipping_methods', apply_filters( 'dintero_chosen_shipping_method', $chosen_shipping_methods ) );
}

/**
 * Maybe set the merchant reference on a Dintero order if if was not done before.
 *
 * @param WC_Order $order The WooCommerce order.
 * @param string   $transaction_id The Dintero transaction id.
 *
 * @return void
 */
function dintero_maybe_set_merchant_reference_2( $order, $transaction_id ) {
	$order_number              = $order->get_order_number();
	$meta_merchant_reference_2 = $order->get_meta( '_dintero_merchant_reference_2' );

	// If the merchant reference has changed since it was set, log it since we cant update if after it was set.
	if ( ! empty( $meta_merchant_reference_2 ) && $meta_merchant_reference_2 !== $order_number ) {
		Dintero_Checkout_Logger::log( "The WC order {$order->get_id()} with transaction ID {$transaction_id} has a different order number ({$order_number}) than the previously set merchant_reference_2 ({$meta_merchant_reference_2})." );
		return;
	}

	if ( empty( $meta_merchant_reference_2 ) ) {
		Dintero()->api->update_transaction( $transaction_id, $order_number );
	}
}

/**
 * Set the confirmation order meta from the dintero order to the WooCommerce order.
 *
 * @param array    $dintero_order The Dintero order from the GET request.
 * @param WC_Order $order The WooCommerce order. Passed by reference.
 *
 * @return void
 */
function dintero_set_confirmation_order_meta( $dintero_order, &$order ) {
	if ( ! empty( $dintero_order['merchant_reference_2'] ?? '' ) ) {
		$order->update_meta_data( '_dintero_merchant_reference_2', wc_clean( $dintero_order['merchant_reference_2'] ) );
	}

	// Save shipping id to the order if it was not set before.
	$shipping = $order->get_shipping_methods();
	if ( ! empty( $shipping ) && empty( $order->get_meta( '_wc_dintero_shipping_id' ) ) ) {
		$shipping_option = $dintero_order['shipping_option']['id'] ?? reset( $shipping );

		// When processing a Woo subscription, the shipping option is an instance of WC_Order_Item_Shipping.
		if ( is_object( $shipping_option ) ) {
			$shipping_option = $shipping_option->get_method_id() . ':' . $shipping_option->get_instance_id();
		}

		$order->update_meta_data( '_wc_dintero_shipping_id', $shipping_option );
	}

	$payment_token = Dintero_Checkout_Subscription::get_payment_token_from_response( $dintero_order );
	// If we have a payment token, then save it to the subscription and order, also get the payment product type.
	if ( ! empty( $payment_token ) ) {
		$payment_product_type = Dintero_Checkout_Subscription::get_payment_product_type_from_response( $dintero_order );
		Dintero_Checkout_Subscription::save_subscription_metadata( $order, $payment_product_type, $payment_token, 'payment' );
	}

	/* Remove duplicate words from the payment method type (e.g., swish.swish → Swish). Otherwise, prints as is (e.g., collector.invoice → Collector Invoice). */
	$payment_method = dintero_get_payment_method_name( wc_get_var( $dintero_order['payment_product_type'], $order->get_meta( '_dintero_payment_method' ) ) );
	$order->update_meta_data( '_dintero_payment_method', $payment_method );

	dintero_maybe_save_org_nr( $dintero_order, $order );

	$order->save_meta_data();
}

/**
 * Process the order that requires further authentication and set it to the correct status.
 *
 * @param WC_Order $order The WooCommerce order. Passed by reference.
 * @param string   $transaction_id The Dintero transaction id.
 * @param string   $pending_auth_status The status to use for the pending authorization status.
 *
 * @return void
 */
function dintero_process_require_authentication( $order, $transaction_id, $pending_auth_status ) {
	$order->set_transaction_id( $transaction_id );
	$order->update_meta_data( Dintero()->order_management->status( 'on_hold' ), $transaction_id );

	// translators: %s the Dintero transaction ID.
	$order_note = sprintf( __( 'The order was placed successfully, but requires further authorization by Dintero. Transaction ID: %s', 'dintero-checkout-for-woocommerce' ), $transaction_id );
	$order->update_status( $pending_auth_status, $order_note ); // Update the status with the note. This will also save the order.
	Dintero_Checkout_Logger::log( "REDIRECT: The WC order {$order->get_id()} (transaction ID: $transaction_id) will require further authorization from Dintero." );
}

function dintero_process_authorized_order( $order, $settings, $transaction_id ) {
	$order_id              = $order->get_id();
	$initial_done_meta_key = '_dintero_initial_confirmation_done';
	$initial_lock_meta_key = '_dintero_initial_confirmation_lock';

	if ( ! empty( $order->get_meta( $initial_done_meta_key ) ) ) {
		Dintero_Checkout_Logger::log( sprintf( 'CONFIRMATION: completion already done. Duplicate prevented for WC order id: %s (transaction ID: %s).', $order_id, $transaction_id ) );
		return;
	}

	$lock_acquired = add_post_meta( $order_id, $initial_lock_meta_key, $transaction_id, true );
	if ( ! $lock_acquired ) {
		Dintero_Checkout_Logger::log( sprintf( 'CONFIRMATION: lock already exists. Duplicate prevented for WC order id: %s (transaction ID: %s).', $order_id, $transaction_id ) );
		return;
	}

	Dintero_Checkout_Logger::log( sprintf( 'CONFIRMATION: lock acquired for WC order id: %s (transaction ID: %s).', $order_id, $transaction_id ) );

	// Get the latest order state after lock acquisition to avoid stale in-memory values.
	$order = wc_get_order( $order_id );

	// If the order was already processed before introducing the marker, register completion and exit.
	if ( ! empty( $order->get_date_paid() ) ) {
		$order->update_meta_data( $initial_done_meta_key, time() );
		$order->update_meta_data( '_dintero_confirmed_txn_' . sanitize_key( $transaction_id ), time() );
		$order->save_meta_data();

		Dintero_Checkout_Logger::log( sprintf( 'CONFIRMATION: completion done for WC order id: %s (transaction ID: %s). Order was already paid.', $order_id, $transaction_id ) );
		return;
	}

	$was_pending_authorization = ! empty( $order->get_meta( Dintero()->order_management->status( 'on_hold' ) ) );
	$order_note                = $was_pending_authorization ? __( 'The order has been authorized.', 'dintero-checkout-for-woocommerce' )
		// translators: %s the Dintero transaction ID.
		: sprintf( __( 'The order was placed successfully via Dintero Checkout. Transaction ID: %s', 'dintero-checkout-for-woocommerce' ), $transaction_id );

	$order->add_order_note( $order_note );
	$order->delete_meta_data( Dintero()->order_management->status( 'on_hold' ) ); // Delete the meta data for the manual review status if it was set.

	// If the default status for authorized orders is not "processing", then set it to that status.
	$default_status_authorized = $settings['order_status_authorized'] ?? 'processing';
	if ( 'processing' !== $default_status_authorized ) {
		$order->set_transaction_id( $transaction_id );
		$order->set_status( $default_status_authorized );
		if ( ! $order->get_date_paid( 'edit' ) ) {
			$order->set_date_paid( time() );
		}
		$order->update_meta_data( $initial_done_meta_key, time() );
		$order->update_meta_data( '_dintero_confirmed_txn_' . sanitize_key( $transaction_id ), time() );
		// Save the order to store any changes made to the order.
		$order->save();
	} else {
		// If the status is processing, we can use the built-in payment_complete function to set the status and date paid. This will also save the order.
		$order->payment_complete( $transaction_id );
		$order->update_meta_data( $initial_done_meta_key, time() );
		$order->update_meta_data( '_dintero_confirmed_txn_' . sanitize_key( $transaction_id ), time() );
		$order->save_meta_data();
	}

	Dintero_Checkout_Logger::log( sprintf( 'CONFIRMATION: completion done for WC order id: %s (transaction ID: %s).', $order_id, $transaction_id ) );
}

/**
 * Confirms the Dintero Order.
 *
 * @param WC_Order $order The Woo order.
 * @param string   $transaction_id The Dintero transaction id.
 * @return void
 */
function dintero_confirm_order( $order, $transaction_id ) {
	$order_id = $order->get_id();

	$settings = get_option( 'woocommerce_dintero_checkout_settings' );

	// Save the environment mode for use in the meta box.
	$order->update_meta_data( '_wc_dintero_checkout_environment', 'yes' === $settings['test_mode'] ? 'Test' : 'Production' );
	$order->update_meta_data( '_dintero_transaction_id', $transaction_id );

	// Set the merchant_reference_2 for the Dintero order if it was not set before, or it does not match the order number.
	dintero_maybe_set_merchant_reference_2( $order, $transaction_id );

	// Get the order from Dintero to ensure the merchant reference was set, get any potential card tokens and other data we need to store.
	$params        = array( 'includes' => 'card.payment_token' );
	$dintero_order = Dintero()->api->get_order( $transaction_id, $params );
	if ( is_wp_error( $dintero_order ) ) {
		$order->add_order_note(
			sprintf(
				/* translators: %s the error message. */
				__( 'The order was completed, but the confirmation step failed due to a WP error. Error: %s', 'dintero-checkout-for-woocommerce' ),
				dintero_retrieve_error_message( $dintero_order )
			)
		);
		$order->save_meta_data();
		return;
	}

	// Set the required metadata from the Dintero order to the WooCommerce order.
	dintero_set_confirmation_order_meta( $dintero_order, $order );

	// Get the order from the database again to prevent any concurrency issues if the page loads twice at the same time.
	$order = wc_get_order( $order_id );

	$require_authorization = ( ! is_wp_error( $dintero_order ) && 'ON_HOLD' === $dintero_order['status'] );
	if ( $require_authorization ) { // If the order needs to be authenticated.
		dintero_process_require_authentication( $order, $transaction_id, $settings['order_status_pending_authorization'] ?? 'manual-review' );
	} else { // Otherwise process the authenticated order.
		dintero_process_authorized_order( $order, $settings, $transaction_id );
	}

	$order->save_meta_data();
}

/**
 * The URL to the branding image.
 *
 * @param  string $icon_color A hexadecimal RGB value for the foreground color. Default is #cecece.
 * @return string URL
 */
function dintero_get_brand_image_url( $icon_color = 'cecece' ) {
	$settings = get_option( 'woocommerce_dintero_checkout_settings' );

	$variant  = $settings['branding_logo_color_mode'] ?? 'colors';
	$color    = str_replace( '#', '', $icon_color );
	$width    = 600;
	$template = 'dintero_top_frame';
	$account  = ( ( 'yes' === $settings['test_mode'] ) ? 'T' : 'P' ) . $settings['account_id'];
	$profile  = $settings['profile_id'];

	if ( 'yes' !== $settings['branding_logo_color'] ) {
		$variant = 'mono';
		$color   = str_replace( '#', '', $settings['branding_logo_color_custom'] );
	}

	return "https://checkout.dintero.com/v1/branding/accounts/{$account}/profiles/{$profile}/variant/{$variant}/color/{$color}/width/{$width}/{$template}.svg";
}

/**
 * Generates the keyword-variant to be used for search engine optimization purposes on icons.
 *
 * @return string
 */
function dintero_keyword_backlinks() {
	$locale = get_locale();
	if ( 'sv' === substr( $locale, 0, 2 ) ) {
		$keywords = array(
			'Dintero logo - checkout med swish, visa, mastercard, walley och mobilepay',
			'Dintero logo - betalningslösning for woocommerce',
			'Dintero logo - betalningslösning med swish, walley, visa, mastercard och mobilepay',
			'Dintero logo - Split Payments för plattform och marknadsplats',
		);
	} elseif ( 'nb' === substr( $locale, 0, 2 ) ) {
		$keywords = array(
			'Dintero logo - en enkel betalingsløsning på nett',
			'Dintero logo - checkout med vipps, visa, mastercard, walley og mobilePay',
			'Dintero logo - checkout for woocommerce',
			'Dintero logo - Split Payments for plattform og markedsplass',
		);
	} else {
		/* For all other languages, default to the English variant. */
		$keywords = array(
			'Dintero logo - Dintero checkout delivers simple online payment solutions',
			'Dintero logo - Dintero payment methods for woocommerce',
			'Dintero logo - Simple payment solutions for woocommerce payment gateway',
			'Dintero logo - Split Payments for platforms and marketplaces',
		);
	}

	$index = get_transient( 'dintero_checkout_keyword_backlinks' );
	if ( empty( $index ) ) {
		$index = hexdec(
			crc32( parse_url( get_site_url(), PHP_URL_HOST ) )
		);
		set_transient( 'dintero_checkout_keyword_backlinks', $index );
	}

	return $keywords[ $index % count( $keywords ) ];
}

/**
 * Generates the 'alt' text for the image used for the purpose of backlinks.
 *
 * @return string
 */
function dintero_alt_backlinks() {
	switch ( substr( get_locale(), 0, 2 ) ) {
		case 'sv':
			return 'Dintero logo, klicka här för att visa Dinteros hemsida.';
		case 'nb':
			return 'Dintero logo, klikk for å vis Dinteros nettside.';
		default:
			return "Dintero logo, click to view Dintero's website.";
	}
}
/**
 * Get a order id from the merchant reference.
 *
 * @param string $merchant_reference The merchant reference from dintero.
 * @return int The WC order ID or 0 if no match was found.
 */
function dintero_get_order_id_by_merchant_reference( $merchant_reference ) {
	$key    = '_dintero_merchant_reference';
	$orders = wc_get_orders(
		array(
			'meta_key'     => $key,
			'meta_value'   => $merchant_reference,
			'limit'        => 1,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'meta_compare' => '=',
		)
	);

	$order = reset( $orders );
	if ( empty( $order ) || $merchant_reference !== $order->get_meta( $key ) ) {
		return 0;
	}

	return $order->get_id() ?? 0;
}

/**
 * Retrieve the error message(s).
 *
 * @param WP_Error $error An instance of WP_Error.
 * @return string
 */
function dintero_retrieve_error_message( $error ) {
	$message = $error->get_error_message();
	if ( is_array( $message ) ) {
		$message = implode( ' ', $message );
	}
	return $message;
}

/**
 * Retrieve the payment method name from a payment product type string.
 *
 * Remove duplicate words from the payment method type (e.g., swish.swish → Swish).
 * Otherwise, prints as is (e.g., collector.invoice → Collector Invoice).
 *
 * @param string $payment_product_type The payment product type.
 * @return string
 */
function dintero_get_payment_method_name( $payment_product_type ) {
	if ( ! is_string( $payment_product_type ) ) {
		return $payment_product_type;
	}

	// Remove any dots (e.g., "collector.invoice" → "collector invoice").
	$payment_method = str_replace( '.', ' ', $payment_product_type );
	// Change to uppercase (e.g., "collector invoice" → "Collector Invoice").
	$payment_method = ucwords( $payment_method );
	// Convert into array (e.g., "Collector Invoice" → ["Collector", "Invoice"] ).
	$payment_method = explode( ' ', $payment_method );
	// Remove any duplicates ( e.g., ["Swish", "Swish"] → ["Swish"] ).
	$payment_method = array_unique( $payment_method );
	// Convert to string (e.g., "Collector Invoice" or "Swish").
	$payment_method = implode( $payment_method );

	return $payment_method;
}

/**
 * Whether Dintero Checkout Express is enabled.
 *
 * @param array $settings The Dintero Checkout plugin settings.
 * @return bool
 */
function dwc_is_express( $settings ) {
	if ( isset( $settings['checkout_flow'] ) ) {
		return strpos( $settings['checkout_flow'], 'express' ) !== false;
	}

	return 'express' === $settings['checkout_type'];
}

/**
 * Whether the selected form factor is embedded.
 *
 * @param array $settings The Dintero Checkout plugin settings.
 * @return bool
 */
function dwc_is_embedded( $settings ) {
	if ( isset( $settings['checkout_flow'] ) ) {
		return strpos( $settings['checkout_flow'], 'embedded' ) !== false || dwc_is_popout( $settings );
	}

	return 'embedded' === $settings['form_factor'];
}

/**
 * Whether the selected form factor is redirect.
 *
 * @param array $settings The Dintero Checkout plugin settings.
 * @return bool
 */
function dwc_is_redirect( $settings ) {
	if ( isset( $settings['checkout_flow'] ) ) {
		return strpos( $settings['checkout_flow'], 'redirect' ) !== false;
	}

	return 'redirect' === $settings['form_factor'];
}

/**
 * Whether pop-out is selected.
 *
 * @param array $settings The Dintero Checkout plugin settings.
 * @return bool
 */
function dwc_is_popout( $settings ) {
	if ( isset( $settings['checkout_flow'] ) ) {
		return strpos( $settings['checkout_flow'], 'popout' ) !== false;
	}

	return 'yes' === $settings['checkout_popout'] && 'embedded' === $settings['form_factor'];
}

/**
 * Whether we can update the checkout.
 *
 * @return bool
 */
function dwc_can_update_checkout() {
	// If we are not on checkout, return false.
	if ( ! is_checkout() ) {
		return false;
	}

	// If there is not an ajax call happening, then just return true.
	if ( ! is_ajax() ) {
		return true;
	}

	// Get the current ajax action from the query string.
	$ajax = filter_input( INPUT_GET, 'wc-ajax', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

	// Get the posted data to see if we have locked the iframe or not.
	$raw_post_data = filter_input( INPUT_POST, 'post_data', FILTER_SANITIZE_URL ) ?? '';
	parse_str( $raw_post_data, $post_data );
	if ( ! is_array( $post_data ) || ! isset( $post_data['dintero_locked'] ) ) {
		return false;
	}

	return 'update_order_review' === $ajax; // We only want to update the checkout during update_order_review if we are doing ajax.
}

/**
 * Save the organization number to the order if available.
 *
 * @param array    $dintero_order The Dintero order from the GET request.
 * @param WC_Order $order The Woo order.
 * @return void
 */
function dintero_maybe_save_org_nr( $dintero_order, $order ) {
	$billing_org_nr = $dintero_order['billing_address']['organization_number'] ?? '';

	if ( ! empty( $billing_org_nr ) ) {
		$order->update_meta_data( '_billing_org_nr', wc_clean( $billing_org_nr ) );
		$order->save();
	}
}

/**
 * Returns if shipping is handled by the iframe.
 *
 * @return boolean
 */
function dwc_is_shipping_in_iframe() {
	$settings = wp_parse_args(
		get_option( 'woocommerce_dintero_checkout_settings', array() ),
		array(
			'checkout_flow'              => 'express_popout',
			'express_shipping_in_iframe' => 'no',
		)
	);

	return dwc_is_express( $settings ) && wc_string_to_bool( $settings['express_shipping_in_iframe'] ?? false );
}
