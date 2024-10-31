<?php

namespace Paygreen\Module;

use Exception;
use WC_Order;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WC_Paygreen_Payment_Order_Controller class.
 *
 * Handles in-checkout AJAX calls, related to Payment Orders.
 */
class WC_Paygreen_Payment_Order_Controller {

    /**
     * @var string
     */
    private $id;

    public function __construct() {
        $this->id = 'paygreen_payment';
        add_action( 'wc_ajax_wc_paygreen_payment_verify_payment_order', [ $this, 'verify_payment_order' ] );
    }

    /**
     * Handle PaymentOrder validation.
     */
    public function verify_payment_order() {
        // Verify nonce
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['nonce'] ), 'wc_paygreen_payment_verify_payment_order' ) ) {
            throw new WC_Paygreen_Payment_Exception( 'missing-nonce', __( 'CSRF verification failed.', 'paygreen-payment-gateway' ) );
        }

        // Load the order ID.
        $order_id = null;
        if ( isset( $_GET['order'] ) && absint( $_GET['order'] ) ) {
            $order_id = absint( $_GET['order'] );
        }

        // Retrieve the order.
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            throw new WC_Paygreen_Payment_Exception( 'missing-order', __( 'Missing order ID for payment confirmation', 'paygreen-payment-gateway' ) );
        }

        // Set order status
        $this->verify_payment_order_after_checkout( $order );

        // Redirect customer to thank you page
        $redirect_url = esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ); // wpcs: csrf ok.

        wp_safe_redirect( $redirect_url );

        exit();
    }

    /**
     * Executed between the "Checkout" and "Thank you" pages, this
     * method updates orders based on the status of associated payment order.
     *
     * @since 0.0.0
     * @param WC_Order $order The order which is in a transitional state.
     * @return void|WP_Error
     */
    public function verify_payment_order_after_checkout( $order ) {
        $payment_method = $order->get_payment_method();

        if ( strpos( $payment_method, $this->id ) !== 0 ) {
            // If this is not the payment method, a payment order would not be available.
            return;
        }

        try {
            $payment_order = $this->get_payment_order_from_order( $order );
        }
        catch (WC_Paygreen_Payment_Exception $e) {
            return new WP_Error('paygreen_payment_error', $e->getMessage());
        }

        if ( ! $payment_order ) {
            // No intent, redirect to the order received page for further actions.
            return;
        }

        // A webhook might have modified the order while the intent was retrieved. This ensures we are reading the right status.
        clean_post_cache( $order->get_id() );
        $order = wc_get_order( $order->get_id() );

        if ( ! ( $order->has_status( 'pending' ) || $order->has_status( 'failed' ) ) ) {
            // If the order is not in a pending or failed state, we don't need to do anything.
            return;
        }

        // TODO : lock order payment here

        if ( 'payment_order.successed' === $payment_order->data->status || 'payment_order.authorized' === $payment_order->data->status ) {
            WC()->cart->empty_cart();
            $order->payment_complete($payment_order->data->id);
        }
        elseif ( 'payment_order.pending' === $payment_order->data->status ) {
            $order->update_status( 'pending', __( 'Payment pending', 'paygreen-payment-gateway' ) );
        }
        elseif ( 'payment_order.refused' === $payment_order->data->status ) {
            $order->update_status( 'failed', __( 'Payment refused', 'paygreen-payment-gateway' ) );
        }
        elseif ( 'payment_order.expired' === $payment_order->data->status ) {
            $order->update_status( 'failed', __( 'Payment expired', 'paygreen-payment-gateway' ) );
        }
        elseif ( 'payment_order.canceled' === $payment_order->data->status ) {
            $order->update_status( 'cancelled', __( 'Payment canceled', 'paygreen-payment-gateway' ) );
        }
        elseif ( 'payment_order.error' === $payment_order->data->status ) {
            $order->update_status( 'failed', __( 'Payment error', 'paygreen-payment-gateway' ) );
        }
        elseif ( 'payment_order.refunded' === $payment_order->data->status ) {
            $order->update_status( 'refunded', __( 'Payment refunded', 'paygreen-payment-gateway' ) );
        }
        else {
            $order->update_status( 'failed', __( 'Payment failed', 'paygreen-payment-gateway' ) );
        }

        $order->add_order_note(
            sprintf(
            /* translators: $1%s payment order status */
                __( 'Paygreen fetch new status (%1$s)', 'paygreen-payment-gateway' ),
                $payment_order->data->status
            )
        );

        // TODO : unlock order payment here
    }

    /**
     * Retrieves the payment order, associated with an order.
     *
     * @param WC_Order $order The order to retrieve an intent for.
     * @return \stdClass|bool     The payment order data from api or `false`.
     * @throws WC_Paygreen_Payment_Exception
     * @since 0.0.0
     */
    public function get_payment_order_from_order( $order ) {
        $payment_order_id = $order->get_meta( '_paygreen_payment_order_id' );

        if ( $payment_order_id ) {
            $client = WC_Paygreen_Payment_Api::get_paygreen_client();
            try {
                $response = $client->getPaymentOrder($payment_order_id);
            }
            catch (Exception $e) {
                throw new WC_Paygreen_Payment_Exception( 'payment-order-error', __( 'Failed to retrieve payment order.', 'paygreen-payment-gateway' ) );
            }

            if ($response->getStatusCode() !== 200) {
                $error_response_message = print_r( $response->getBody()->getContents(), true );

                WC_Paygreen_Payment_Logger::error( "Failed to get payment order $payment_order_id. Response:" . $error_response_message );
                return false;
            }

            return json_decode($response->getBody()->getContents());
        }

        return false;
    }
}