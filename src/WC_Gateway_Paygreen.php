<?php

namespace Paygreen\Module;

use Exception;
use Paygreen\Sdk\Payment\V3\Environment;
use WC_AJAX;
use WC_Order;
use WC_Payment_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 *  WC_Gateway_Paygreen class.
 */
class WC_Gateway_Paygreen extends WC_Paygreen_Payment_Gateway {
    const ID = 'bank_card';

    /** @var array */
    protected $categories;

    /** @var array */
    protected $categoriesRestrictions;

    /** @var string */
    protected $environment;

    /** @var string */
    protected $shop_id;

    /** @var string */
    protected $public_key;

    /** @var string */
    protected $secret_key;

    /** @var string */
    protected $sub_shop_id;

    public function __construct() {
        $this->id = 'paygreen_payment';
        $this->platform = self::ID;
        $this->icon = ''; // apply_filters( 'woocommerce_gateway_icon', plugins_url( 'assets/img/logo-bank_card.svg', WC_PAYGREEN_PAYMENT_MAIN_FILE ) );
        $this->has_fields = true;
        $this->method_title = 'PayGreen';
        $this->method_description = __( 'PayGreen is a 100% French online payment solution that allows you to accept and manage payments on your e-commerce in a simple and efficient way. We are the first payment platform focused on sustainable development.', 'paygreen-payment-gateway' );
        $this->categories = array();
        $this->categoriesRestrictions = array();

        // gateways can support subscriptions, refunds, saved payment methods,
        $this->supports = array(
            'products'
        );

        // Load the settings.
        $this->init_settings();

        $this->main_settings = get_option( 'woocommerce_paygreen_payment_settings' );
        $this->title = $this->get_option( 'title', __( 'Pay with PayGreen', 'paygreen-payment-gateway' ));
        $this->description = $this->get_option( 'description');
        $this->enabled = $this->get_option( 'enabled' );
        $this->environment = $this->get_option( 'environment' );
        $this->shop_id = $this->get_option( 'shop_id' );
        $this->public_key = $this->get_option( 'public_key' );
        $this->secret_key = $this->get_option( 'secret_key' );
        $this->sub_shop_id = $this->get_option( 'sub_shop_id' );

        $this->form_fields = array(
            'screen_button' => array(
                'id'    => 'menu',
                'type'  => 'screen_button',
            )
        );

        if (empty($this->description)) {
            $this->has_fields = false;
        }

        // Method with all the options fields
        $this->init_form_fields();

        // This action hook saves the settings
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        add_filter( 'woocommerce_available_payment_gateways', [ $this, 'prepare_order_pay_page' ] );
        add_filter( 'woocommerce_payment_successful_result', [ $this, 'modify_successful_payment_result' ], 99999, 2 );

        // Note: display error is in the parent class.
        add_action( 'admin_notices', [ $this, 'display_errors' ], 9999 );

        // Modify gateway title on order details page
        add_filter('woocommerce_gateway_title', [ $this, 'modify_gateway_method_title'], 10, 2);

        // Modify payment method title on invoice
        add_action('woocommerce_checkout_create_order', [ $this, 'modify_order_before_save'], 10, 2);
    }

    public function modify_gateway_method_title($title, $id)
    {
        return is_admin() && $id === $this->id ? $this->method_title : $title;
    }

    public function modify_order_before_save($order, $data)
    {
        if (isset($data['payment_method']) && $data['payment_method'] === $this->id) {
            $order->set_payment_method_title($this->method_title);
        }
    }

    /**
     * Initialize the gateway form.
     *
     * @return void
     */
    public function init_form_fields() {
        // Shop not logged in
        if (!isset($this->settings['token']) || $this->settings['token'] === 0) {
            $this->init_settings_fields();
            return;
        }

        // Method with all the options fields
        if( isset($_GET['screen']) && 'domain_availability' === $_GET['screen'] ) {
            $this->init_domain_availability_fields();
        } else {
            $this->init_settings_fields();
        }
    }

    public function init_settings_fields(){
        $available_environments = array(
            Environment::ENVIRONMENT_SANDBOX => 'Sandbox',
            Environment::ENVIRONMENT_PRODUCTION => 'Production',
        );

        if (getenv('PAYGREEN_DEBUG')) {
            $available_environments[Environment::ENVIRONMENT_RECETTE] = 'Recette';
        }

        $this->form_fields = array_merge( $this->form_fields, array(
            'enabled' => array(
                'title'       => __( 'Enable/Disable', 'paygreen-payment-gateway' ),
                'label'       => __( 'Enable Paygreen Gateway', 'paygreen-payment-gateway' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'environment' => array(
                'title'       => __( 'Environment', 'paygreen-payment-gateway' ),
                'label'       => __( 'Enable Test Mode', 'paygreen-payment-gateway' ),
                'type'        => 'select',
                'description' => __( 'Place the payment gateway in test mode using test API keys.', 'paygreen-payment-gateway' ),
                'default'     => 'yes',
                'desc_tip'    => true,
                'options' => $available_environments
            ),
            'shop_id' => array(
                'title'       => __( 'Shop id', 'paygreen-payment-gateway' ),
                'type'        => 'text'
            ),
            'public_key' => array(
                'title'       => __( 'Public Key', 'paygreen-payment-gateway' ),
                'type'        => 'text'
            ),
            'secret_key' => array(
                'title'       => __( 'Secret Key', 'paygreen-payment-gateway' ),
                'type'        => 'text'
            ),
            'sub_shop_id' => array(
                'title'       => __( 'Sub-Shop identifier', 'paygreen-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'If this store is a sub-shop and you want to create payments in its name, add its id.', 'paygreen-payment-gateway' )
            ),
            'title'       => array(
                'title'       => __( 'Title', 'paygreen-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'paygreen-payment-gateway' ),
                'default'     => __( 'Pay with PayGreen', 'paygreen-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Payment secured', 'paygreen-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'You can set a message such as "Payment secured with PayGreen." above your hosted fields.', 'paygreen-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
        ));
    }

    public function generate_screen_button_html() {

        ?>
        <div>
            <a href="<?php echo admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paygreen_payment&screen=settings' ); ?>" class="button"><?php echo __( 'Settings', 'paygreen-payment-gateway' ); ?></a>
            <?php
            if (isset($this->settings['token'])) {
                ?>
                <a href="<?php echo admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paygreen_payment&screen=domain_availability' ); ?>" class="button"><?php echo __( 'Payment method availability', 'paygreen-payment-gateway' ); ?></a>
                <?php
            }
            ?>
        </div>
        <?php

    }

    public function init_domain_availability_fields()
    {
        $this->form_fields = array_merge( $this->form_fields, array(
            'title' => array(
                'description'       => __( 'PayGreen offers payment by meal ticket or holiday voucher.<br/> On this page you can define which category is compatible with these payment methods.', 'paygreen-payment-gateway' ),
                'type'        => 'title',
            ),
            'available_for_food' => array(
                'title' => __( 'Available for food payment methods', 'paygreen-payment-gateway' ),
                'description' => __( 'Selected categories will be eligible for : Swile, Connecs, Wedoofood, Restoflash.', 'paygreen-payment-gateway' ),
                'type' => 'multiselect',
                'options' => $this->get_all_categories(),
            ),
            'available_for_travel' => array(
                'title' => __( 'Available for travel payment methods', 'paygreen-payment-gateway' ),
                'description' => __( 'Selected categories will be eligible for : ANCV.', 'paygreen-payment-gateway' ),
                'type' => 'multiselect',
                'options' => $this->get_all_categories(),
            ),
            'shipping_cost_exclusion' => array(
                'title' => __( 'Exclusion of shipping costs', 'paygreen-payment-gateway' ),
                'label' => __( 'By checking this box, shipping costs will be excluded from the calculation of eligible amounts.', 'paygreen-payment-gateway' ),
                'type' => 'checkbox',
                'default'     => 'no'
            ),
        ));
    }

    public function get_all_categories() {

        $result = array();

        $taxonomy     = 'product_cat';
        $orderby      = 'name';
        $show_count   = 0;      // 1 for yes, 0 for no
        $pad_counts   = 0;      // 1 for yes, 0 for no
        $hierarchical = 1;      // 1 for yes, 0 for no
        $title        = '';
        $empty        = 0;

        $args = array(
            'taxonomy'     => $taxonomy,
            'orderby'      => $orderby,
            'show_count'   => $show_count,
            'pad_counts'   => $pad_counts,
            'hierarchical' => $hierarchical,
            'title_li'     => $title,
            'hide_empty'   => $empty
        );
        $all_categories = get_categories( $args );
        foreach ($all_categories as $cat) {
            if($cat->category_parent == 0) {
                $category_id = $cat->term_id;
                $result[$category_id] = $cat->name;
            }
        }
        $this->categories = $result;
        return $result;
    }

    /**
     * Adds the necessary hooks to modify the "Pay for order" page in order to clean
     * it up and prepare it for the paygreenjs modal to confirm a payment.
     *
     * @since 4.2
     * @param WC_Payment_Gateway[] $gateways A list of all available gateways.
     * @return WC_Payment_Gateway[]          Either the same list or an empty one in the right conditions.
     */
    public function prepare_order_pay_page( $gateways ) {
        if ( ! is_wc_endpoint_url( 'order-pay' ) || ! isset( $_GET['wc-paygreen-confirmation'] ) ) { // wpcs: csrf ok.
            return $gateways;
        }

        try {
            $this->prepare_payment_order_for_order_pay_page();
        } catch ( WC_Paygreen_Payment_Exception $e ) {
            // Just show the full order pay page if there was a problem preparing the Payment Intent
            return $gateways;
        }

        add_filter( 'woocommerce_checkout_show_terms', '__return_false' );
        add_filter( 'woocommerce_pay_order_button_html', '__return_false' );
        add_filter( 'woocommerce_available_payment_gateways', '__return_empty_array' );

        return [];
    }

    /**
     * Prepares the Payment Order for it to be completed in the "Pay for Order" page.
     *
     * @param WC_Order|null $order Order object, or null to get the order from the "order-pay" URL parameter
     *
     * @throws WC_Paygreen_Payment_Exception
     * @since 0.1.0
     */
    public function prepare_payment_order_for_order_pay_page( $order = null ) {
        if ( empty( $order ) ) {
            $order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
        }

        $payment_order = $this->create_payment_order( $order );
        WC_Paygreen_Payment_Helper::add_payment_order_to_order( $payment_order['payment_order_id'], $order );

        $this->order_pay_payment_order = $payment_order;
    }

    /**
     * Processes and saves options.
     * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
     *
     * @return void
     */
    public function process_admin_options()
    {
        $post_data = $this->get_post_data();

        if (isset($post_data['woocommerce_paygreen_payment_environment'])
            && $this->settings['environment'] !== $post_data['woocommerce_paygreen_payment_environment']
        ) {
            unset($this->settings['token']);
            unset($this->settings['token_expire_at']);
            update_option('woocommerce_paygreen_payment_settings', $this->settings);
        }

        if (isset($this->settings['token'])
            && !empty($post_data)
            && isset($post_data['woocommerce_paygreen_payment_environment'])
            && isset($post_data['woocommerce_paygreen_payment_shop_id'])
            && isset($post_data['woocommerce_paygreen_payment_public_key'])
            && isset($post_data['woocommerce_paygreen_payment_secret_key'])
            && array(
                $post_data['woocommerce_paygreen_payment_environment'],
                $post_data['woocommerce_paygreen_payment_shop_id'],
                $post_data['woocommerce_paygreen_payment_public_key'],
                $post_data['woocommerce_paygreen_payment_secret_key']
            ) !== array(
                $this->settings['environment'],
                $this->settings['shop_id'],
                $this->settings['public_key'],
                $this->settings['secret_key']
            ))
        {
            $gateways = WC()->payment_gateways->payment_gateways();

            foreach ($gateways as $gateway) {
                if (preg_match('/^paygreen_payment/', $gateway->id) && $gateway->enabled === 'yes') {
                    $gateway->update_option('enabled', 'no');
                }
            }

            $this->add_error(__('The PayGreen payment methods have been deactivated following the modification of your identifiers.', 'paygreen-payment-gateway'));
        }

        parent::process_admin_options();

        try {
            $client = WC_Paygreen_Payment_Api::get_paygreen_client(array(), true);

            $response = $client->getPublicKey($this->settings['public_key']);

            if ($response->getStatusCode() !== 200) {
                $this->add_error(__('Authentication failed. Please check your public key.', 'paygreen-payment-gateway'));
                return;
            }

            $data = json_decode($response->getBody()->getContents())->data;

            if ($data === null || $data->revoked_at !== null) {
                $this->add_error(__('Authentication failed. Your public key has expired.', 'paygreen-payment-gateway'));
            }

            WC_Paygreen_Payment_Api::register_payment_webhook();

        } catch (WC_Paygreen_Payment_Forbidden_Access_Exception $exception) {
            $this->add_error($exception->getLocalizedMessage());
        } catch(WC_Paygreen_Payment_Listener_Exception $exception) {
            $this->add_error($exception->getMessage());
        } catch (Exception $exception) {
            $this->add_error(__('Authentication failed. Please check your credentials or the selected environment.', 'paygreen-payment-gateway'));
        }
    }

    /**
     * Display payment form on checkout page
     *
     * @since 0.0.0
     * @return void
     */
    public function payment_fields() {
        ?>

        <div class="paygreen-payment-fields paygreen-payment-container">
        </div>

        <?php
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }
    }
}