<?php

namespace Paygreen\Module;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WC_Paygreen_Payment_Webhook_Handler class.
 *
 * Handles webhooks from Paygreen on orders that are not immediately chargeable.
 */
class WC_Paygreen_Payment_Webhook_Handler {
    const VALIDATION_SUCCEEDED                 = 'validation_succeeded';
    const VALIDATION_FAILED_EMPTY_HEADERS      = 'empty_headers';
    const VALIDATION_FAILED_EMPTY_BODY         = 'empty_body';
    const VALIDATION_FAILED_SIGNATURE_MISMATCH = 'signature_mismatch';

    private $settings;

    public function __construct() {
        $this->settings = get_option('woocommerce_paygreen_payment_settings');
        add_action( 'woocommerce_api_wc_paygreen_payment', [ $this, 'check_for_webhook' ] );
    }

    /**
     * Check incoming requests for Paygreen Webhook data and process them.
     *
     * @since 0.0.0
     */
    public function check_for_webhook() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] )
            || ( 'POST' !== $_SERVER['REQUEST_METHOD'] )
            || ! isset( $_GET['wc-api'] )
            || ( 'wc_paygreen_payment' !== $_GET['wc-api'] )
        ) {
            return;
        }

        $request_body    = file_get_contents( 'php://input' );
        $request_headers = array_change_key_case( $this->get_request_headers(), CASE_UPPER );

        // Validate it to make sure it is legit.
        $validation_result = $this->validate_request( $request_headers, $request_body );

        if ( self::VALIDATION_SUCCEEDED === $validation_result ) {
            $this->process_webhook( $request_body );

            status_header( 200 );
            exit;
        } else {
            status_header( 400 );
            exit;
        }
    }

    /**
     * Gets the incoming request headers. Some servers are not using
     * Apache and "getallheaders()" will not work so we may need to
     * build our own headers.
     *
     * @since 0.0.0
     */
    public function get_request_headers() {
        if ( ! function_exists( 'getallheaders' ) ) {
            $headers = [];

            foreach ( $_SERVER as $name => $value ) {
                if ( 'HTTP_' === substr( $name, 0, 5 ) ) {
                    $headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
                }
            }

            return $headers;
        } else {
            return getallheaders();
        }
    }

    /**
     * Verify the incoming webhook notification to make sure it is legit.
     *
     * @since 0.0.0
     * @param array $request_headers The request headers from Paygreen.
     * @param string $request_body    The request body from Paygreen.
     * @return string The validation result (e.g. self::VALIDATION_SUCCEEDED )
     */
    public function validate_request( $request_headers, $request_body ) {
        if ( empty( $request_headers ) ) {
            return self::VALIDATION_FAILED_EMPTY_HEADERS;
        }
        if ( empty( $request_body ) ) {
            return self::VALIDATION_FAILED_EMPTY_BODY;
        }

        // Generate the expected signature.
        $expected_signature = base64_encode(hash_hmac('sha256', $request_body, $this->settings['listener_hmac_key'], true));

        // Check if the expected signature is present.
        if ( $expected_signature !== $request_headers['SIGNATURE'] ) {
            return self::VALIDATION_FAILED_SIGNATURE_MISMATCH;
        }

        return self::VALIDATION_SUCCEEDED;
    }

    /**
     * Processes the incoming webhook.
     *
     * @since 0.0.0
     * @param string $request_body
     */
    public function process_webhook( $request_body ) {
        $notification = json_decode( $request_body );

        switch ($notification->status) {
            case 'payment_order.refunded':
                $this->process_webhook_refund( $notification );
                break;
            default:
                $this->process_webhook_payment( $notification );
                break;
        }
    }

    /**
     * Process webhook refund.
     *
     * @since 0.0.0
     * @param object $notification
     */
    public function process_webhook_refund( $notification ) {
        $order = WC_Paygreen_Payment_Helper::get_order_by_payment_order_id($notification->id);

        if (!$order) {
            // If the order is not found, we do nothing.
            return;
        }

        $order->update_status( 'refunded', __( 'Payment refunded', 'paygreen-payment-gateway' ) );

        $order->add_order_note(
            sprintf(
            /* translators: $1%s payment order status */
                __( 'Paygreen send new status (%1$s) from webhook', 'paygreen-payment-gateway' ),
                $notification->status
            )
        );
    }

    /**
     * Process webhook payments.
     *
     * @since 0.0.0
     * @param object $notification
     */
    public function process_webhook_payment( $notification ) {
        $order = WC_Paygreen_Payment_Helper::get_order_by_payment_order_id($notification->id);

        if (!$order) {
            // If the order is not found, we do nothing.
            return;
        }

        if ( ! ( $order->has_status( 'pending' ) || $order->has_status( 'failed' ) ) ) {
            // If the order is not in a pending or failed state, we don't need to do anything.
            return;
        }

        if ( 'payment_order.successed' === $notification->status || 'payment_order.authorized' === $notification->status ) {
            WC()->cart->empty_cart();
            $order->payment_complete($notification->id);
        }
        elseif ( 'payment_order.pending' === $notification->status ) {
            $order->update_status( 'pending', __( 'Payment pending', 'paygreen-payment-gateway' ) );
        }
        elseif ( 'payment_order.refused' === $notification->status ) {
            $order->update_status( 'failed', __( 'Payment refused', 'paygreen-payment-gateway' ) );
        }
        elseif ( 'payment_order.expired' === $notification->status ) {
            $order->update_status( 'failed', __( 'Payment expired', 'paygreen-payment-gateway' ) );
        }
        elseif ( 'payment_order.canceled' === $notification->status ) {
            $order->update_status( 'cancelled', __( 'Payment canceled', 'paygreen-payment-gateway' ) );
        }
        elseif ( 'payment_order.error' === $notification->status ) {
            $order->update_status( 'failed', __( 'Payment error', 'paygreen-payment-gateway' ) );
        }
        elseif ( 'payment_order.refunded' === $notification->status ) {
            $order->update_status( 'refunded', __( 'Payment refunded', 'paygreen-payment-gateway' ) );
        }
        else {
            $order->update_status( 'failed', __( 'Payment failed', 'paygreen-payment-gateway' ) );
        }

        $order->add_order_note(
            sprintf(
            /* translators: $1%s payment order status */
                __( 'Paygreen send new status (%1$s) from webhook', 'paygreen-payment-gateway' ),
                $notification->status
            )
        );

    }
}