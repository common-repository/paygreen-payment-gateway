<?php

namespace Paygreen\Module;

use Paygreen\Sdk\Payment\V3\Enum\DomainEnum;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Paygreen_Payment_Helper {

    /**
     * Adds payment order id and order note to order if payment order is not already saved
     *
     * @since 0.0.0
     * @param $payment_order_id
     * @param $order
     */
    public static function add_payment_order_to_order( $payment_order_id, $order ) {

        $old_payment_order_id = $order->get_meta( '_paygreen_payment_order_id' );

        if ( $old_payment_order_id === $payment_order_id ) {
            return;
        }

        $order->add_order_note(
            sprintf(
                /* translators: $1%s payment order id */
                __( 'Paygreen payment order created (Payment Order ID: %1$s)', 'paygreen-payment-gateway' ),
                $payment_order_id
            )
        );

        $order->update_meta_data( '_paygreen_payment_order_id', $payment_order_id );
        $order->save();
    }

    /**
     * Gets the order by Paygreen payment order.
     *
     * @since 0.0.0
     * @param string $payment_order_id
     */
    public static function get_order_by_payment_order_id( $payment_order_id ) {
        global $wpdb;

        $order_id = $wpdb->get_var( $wpdb->prepare( "SELECT DISTINCT ID FROM $wpdb->posts as posts LEFT JOIN $wpdb->postmeta as meta ON posts.ID = meta.post_id WHERE meta.meta_value = %s AND meta.meta_key = %s", $payment_order_id, '_paygreen_payment_order_id' ) );

        if ( ! empty( $order_id ) ) {
            return wc_get_order( $order_id );
        }

        wc_get_logger()->debug( 'PAYGREEN - Order not found for POID : ' . $payment_order_id);

        return false;
    }

    /**
     * Gets the webhook URL for Paygreen triggers. Used mainly for
     * asyncronous redirect payment methods in which statuses are
     * not immediately chargeable.
     *
     * @since 0.0.0
     * @return string
     */
    public static function get_webhook_url() {
        return add_query_arg( 'wc-api', 'wc_paygreen_payment', trailingslashit( get_home_url() ) );
    }

    /**
     * Returns the amount of the order payable in TRD.
     *
     * @param ?WC_Order $order If an order is not placed, the cart will be used.
     *
     * @return array
     * @since 0.0.4
     */
    public static function get_cart_eligible_amount($order = null)
    {
        $items = [];
        $settings = get_option('woocommerce_paygreen_payment_settings');

        $food = $travel = 0;
        $totalAmount = 0;

        if ($order) {
            foreach ($order->get_items() as $item) {
                $totalAmount = $order->get_total() * 100;
                $items[] = [
                    'product_id' => $item->get_data()['product_id'],
                    'quantity' => $item->get_quantity(),
                ];
            }
        }
        else if (WC()->cart) {
            $cart = WC()->cart;
            $totalAmount = ($cart->get_cart_contents_total() + $cart->get_cart_contents_tax()) * 100;
            $items = $cart->get_cart_contents();
        }

        if ( $items === [] || ( ! isset( $settings['available_for_food'] ) && ! isset( $settings['available_for_travel']  ) ) ) {
            return array(
                DomainEnum::FOOD => $food,
                DomainEnum::TRAVEL => $travel,
            );
        }

        foreach ( $items as $item ) {
            $product_id = $item['product_id'];
            $quantity = $item['quantity'];
            $product = wc_get_product( $product_id );
            $price = $product->get_price() * 100;

            if ($product->is_taxable()) {
                $price = wc_get_price_including_tax($product, array(
                    'price' => $price,
                ));
            }

            $terms = get_the_terms( $product_id, 'product_cat' );

            foreach ($terms as $term) {

                if (isset( $settings['available_for_food'] )  && !empty($settings['available_for_food']) && in_array((string)$term->term_id, $settings['available_for_food'])) {
                    $food += ($price * $quantity);
                    break;
                }

                if (isset( $settings['available_for_travel'] ) && !empty($settings['available_for_travel']) && in_array((string)$term->term_id, $settings['available_for_travel'])) {
                    $travel += ($price * $quantity);
                    break;
                }
            }
        }

        $eligibleAmounts = array(
            DomainEnum::FOOD => (int) $food,
            DomainEnum::TRAVEL => (int) $travel,
        );

        if ($eligibleAmounts[DomainEnum::FOOD] > $totalAmount) {
            $eligibleAmounts[DomainEnum::FOOD] = (int) $totalAmount;
        }

        if ($eligibleAmounts[DomainEnum::TRAVEL] > $totalAmount) {
            $eligibleAmounts[DomainEnum::TRAVEL] = (int) $totalAmount;
        }

        $settings = get_option('woocommerce_paygreen_payment_settings');

        if (!isset($settings['shipping_cost_exclusion']) || $settings['shipping_cost_exclusion'] === 'no') {
            $eligibleAmounts[DomainEnum::FOOD] += (int) ($order->get_shipping_total() * 100);
            $eligibleAmounts[DomainEnum::TRAVEL] += (int) ($order->get_shipping_total() * 100);
        }

        return $eligibleAmounts;
    }
}