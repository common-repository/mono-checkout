<?php

namespace mono;

class Mono {

    public static $instance;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new Mono();
        }
        return self::$instance;
    }

    public static function init()
    {
        $instance = self::get_instance();
    }

    protected $canRun = false;

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var Mono_Gateway
     */
    protected $gateway;

    protected $notices = [];

    public function __construct() {

        if (self::$instance) {
            throw new \Exception('Use get_instance() instead.');
        }

        load_plugin_textdomain( 'mono-checkout', false, dirname(plugin_basename(MONO__PLUGIN_FILE)) . '/languages' );

        $this->canRun = self::check_requirements();

        add_action( 'admin_notices', [$this, 'admin_notices'] );
        add_filter( 'pre_kses', [$this, 'prepare_note_for_tooltip'], 10, 3 );

        add_filter( 'woocommerce_payment_gateways', [$this, 'register_gateway'] );
        add_action( 'woocommerce_after_add_to_cart_button', [$this, 'product_details_button']);
        add_action( 'woocommerce_after_cart_totals', [$this, 'cart_button']);
        add_action( 'woocommerce_widget_shopping_cart_after_buttons', [$this, 'cart_button']);
        add_action( 'woocommerce_before_checkout_form', [$this, 'checkout_button']);
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_scripts'] );

        add_action( 'wp_ajax_mono_buy_product', [$this, 'buy_product'] );
        add_action( 'wp_ajax_nopriv_mono_buy_product', [$this, 'buy_product'] );

        add_action( 'wp_ajax_mono_buy_cart', [$this, 'buy_cart'] );
        add_action( 'wp_ajax_nopriv_mono_buy_cart', [$this, 'buy_cart'] );

        add_shortcode( 'monobank_checkout', [$this, 'shortcode_monobank_checkout'] );

        add_filter( 'woocommerce_valid_order_statuses_for_cancel', [ $this, 'cancel_statuses' ], 10, 2 );
        add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', [ $this, 'complete_statuses' ], 10, 2 );

        $this->setup_notifications();
        $this->configure_order_email();

        $this->init_gateway();
    }

    public function setup_notifications() {

        $notificationList = [
            'WC_Email_Cancelled_Order' => [
                'woocommerce_order_status_cash_on_delivery_to_cancelled_notification',
            ],
            'WC_Email_Customer_On_Hold_Order' => [
                'woocommerce_order_status_not_authorized_to_on-hold_notification',
                'woocommerce_order_status_not_confirmed_to_on-hold_notification',
                'woocommerce_order_status_cash_on_delivery_to_on-hold_notification',
            ],
            'WC_Email_Customer_Processing_Order' => [
                'woocommerce_order_status_not_authorized_to_processing_notification',
                'woocommerce_order_status_not_confirmed_to_processing_notification',
            ],
            'WC_Email_Failed_Order' => [
                'woocommerce_order_status_not_authorized_to_failed_notification',
                'woocommerce_order_status_not_confirmed_to_failed_notification',
            ],
            'WC_Email_New_Order' => [
                'woocommerce_order_status_not_authorized_to_processing_notification',
                'woocommerce_order_status_not_authorized_to_completed_notification',
                'woocommerce_order_status_not_authorized_to_on-hold_notification',
                'woocommerce_order_status_not_confirmed_to_processing_notification',
                'woocommerce_order_status_not_confirmed_to_completed_notification',
                'woocommerce_order_status_not_confirmed_to_on-hold_notification',
                'woocommerce_order_status_not_authorized_to_cash_on_delivery_notification',
                'woocommerce_order_status_not_confirmed_to_cash_on_delivery_notification',
                'woocommerce_order_status_pending_to_cash_on_delivery_notification',
                'woocommerce_order_status_failed_to_cash_on_delivery_notification',
                'woocommerce_order_status_cancelled_to_cash_on_delivery_notification',
            ]
        ];

        add_filter( 'woocommerce_email_actions', function ($list) use ($notificationList) {
            foreach ($notificationList as $k => $v) {
                foreach ($v as $item) {
                    $list[] = str_replace('_notification', '', $item);
                }
            }

            return $list;
        });

        add_action( 'woocommerce_email', function (\WC_Emails $WC_emails) use ($notificationList) {
            foreach ($notificationList as $k => $v) {
                foreach ($v as $item) {
                    add_action( $item, array( $WC_emails->emails[$k], 'trigger' ), 10, 2 );
                }
            }
        } );
    }

    public function configure_order_email() {
        add_filter('woocommerce_mail_callback_params', [$this, 'replace_np_id_with_address'], 10, 2);
    }

    /**
     * @param $parameters array - array( $to, wp_specialchars_decode( $subject ), $message, $headers, $attachments )
     * @param $new_order_email \WC_Email_New_Order
     */
    public function replace_np_id_with_address( $parameters, $new_order_email ) {
        /** @var \WC_Order $order */
        $order = $new_order_email->object;
        if (Mono_Gateway::is_mono_order_by_id($order->get_id())) {
            if ($this->is_valid_branch_id($order->get_billing_address_1())) {
                $parameters[2] = str_replace($order->get_billing_address_1(), $order->get_shipping_address_1(), $parameters[2]);
            }

        }
        return $parameters;
    }

    public function is_valid_branch_id( $branch_id ) {
        return preg_match('/^[^\s]+$/i', $branch_id);
    }

    public function can_run() {
        return $this->canRun;
    }

    public function add_admin_notice( $notice ) {
        $this->notices[] = $notice;
    }

    public function prepare_note_for_tooltip( $content, $allowed_html, $allowed_protocols ) {
        if ($allowed_html == 'post' and strpos($content, '<a') === false) {
            $tmp = [];
            if (strpos($content, 'API answer') !== false) {
                $tmp = explode('API answer', $content);
            } elseif (strpos($content, __( 'API answer', 'mono-checkout' )) !== false) {
                $tmp = explode(__( 'API answer', 'mono-checkout'  ), $content);
            }
            if ($tmp) {
                return $tmp[0];
            }
        }
        return $content;
    }

    public function get_button_url()
    {
        $buttons = $this->get_buttons();
        $selected = $this->get_gateway()->get_option('button');
        return isset($buttons[$selected]) ? $buttons[$selected] : reset($buttons);
    }

    public function get_gateway()
    {
        if (!$this->gateway) {
            $this->gateway = new Mono_Gateway();
        }
        return $this->gateway;
    }

    public function get_buttons()
    {
        return [
            'black_normal' => plugin_dir_url(MONO__PLUGIN_FILE) . '/images/monocheckout_button_black_normal.svg',
            'black_short' => plugin_dir_url(MONO__PLUGIN_FILE) . '/images/monocheckout_button_black_short.svg',
            'white_normal' => plugin_dir_url(MONO__PLUGIN_FILE) . '/images/monocheckout_button_white_normal.svg',
            'white_short' => plugin_dir_url(MONO__PLUGIN_FILE) . '/images/monocheckout_button_white_short.svg',
        ];
    }

    public function enqueue_scripts()
    {
        if ($this->canRun) {
            wp_enqueue_script('mono-frontend-handlers', plugins_url('js/frontend-handlers.js', MONO__PLUGIN_FILE), array('jquery'), MONO_VERSION);
            wp_localize_script('mono-frontend-handlers', 'mono',
                array('ajax_url' => admin_url('admin-ajax.php')));

            wp_enqueue_style( 'mono-btn', plugin_dir_url( MONO__PLUGIN_FILE ) . 'css/mono-btn.css', array(), MONO_VERSION );
            if (is_checkout()) {
                wp_enqueue_style( 'mono-checkout', plugin_dir_url( MONO__PLUGIN_FILE ) . 'css/mono-checkout.css', array('mono-btn'), MONO_VERSION );
            }
            if ($this->is_single_payment_method()) {
                wp_enqueue_style( 'mono-single', plugin_dir_url( MONO__PLUGIN_FILE ) . 'css/mono-checkout-single.css', array('mono-btn'), MONO_VERSION );
            }
        }
    }

    public function admin_notices()
    {
        if (!$this->canRun) {
            if ( !self::is_outgoing_request_possible() ) {
                $message = __( 'mono checkout: Please, enable <code>allow_url_fopen</code> setting in PHP configuration (<a href="https://chemicloud.com/kb/article/how-to-enable-or-disable-allow_url_fopen-in-cpanel/" target="_blank">cPanel manual</a>)', 'mono-checkout' );
            } else {
                $message = __( 'mono requires Woocommerce to be activated. Plugin features are paused.', 'mono-checkout' );
            }
            $class   = 'notice notice-error';
            printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), wp_kses( $message, [ 'code' => [], 'a' => [ 'href' => [], 'target' => [] ] ] ) );
        }
        if (@$_GET['mono_error']) {
            $this->notices[] = ['error', base64_decode($_GET['mono_error'])];
        }
        if ($this->notices) {
            foreach ($this->notices as $notice) {
                $class = 'notice notice-' . $notice[0];
                $message = $notice[1];
                printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
            }
        }
    }

    public function init_gateway()
    {
        if (!$this->canRun) { return; }
        require_once 'class.gateway.php';
    }

    public function register_gateway($methods)
    {
        $methods[] = Mono_Gateway::class;
        return $methods;
    }

    public function cancel_statuses( $statuses, $order ) {
        if (@$order) {
            if (Mono_Gateway::is_mono_order_by_id($order->get_id())) {
                $statuses[] = 'not_authorized';
                $statuses[] = 'not_confirmed';
            }
        } else {
            $statuses[] = 'not_authorized';
            $statuses[] = 'not_confirmed';
        }
        return $statuses;
    }

    public function complete_statuses( $statuses, $order ) {
        if (@$order) {
            if (Mono_Gateway::is_mono_order_by_id($order->get_id())) {
                $statuses[] = 'not_authorized';
                $statuses[] = 'not_confirmed';
            }
        } else {
            $statuses[] = 'not_authorized';
            $statuses[] = 'not_confirmed';
        }
        return $statuses;
    }

    public function enabled_on_product_details()
    {
        if (!$this->is_gateway_enabled()) { return false; }
        return $this->get_gateway()->get_option('enabled_details') == 'yes';
    }

    public function enabled_in_cart()
    {
        if ($this->is_single_payment_method()) {
            return true;
        }
        if (!$this->is_gateway_enabled()) { return false; }
        return $this->get_gateway()->get_option('enabled_cart') == 'yes';
    }

    public function enabled_on_checkout()
    {
        if (!$this->is_gateway_enabled()) { return false; }
        return $this->get_gateway()->get_option('enabled_checkout') == 'yes';
    }

    public function is_gateway_enabled() {
        if ($this->get_gateway() and 'yes' === $this->get_gateway()->enabled) {
            return true;
        }
        return false;
    }

    protected function has_order_coupon() {
        $cart = WC()->cart;
        if ($cart->get_applied_coupons()) {
            return true;
        }
        return false;
    }

    public function is_single_payment_method()
    {
        if ($this->is_gateway_enabled()) {
            return count(WC()->payment_gateways()->get_available_payment_gateways()) === 1;
        }
        return false;
    }

    public function product_details_button()
    {

        if (!$this->canRun) { return; }
        if (!$this->enabled_on_product_details()) { return; }
        global $mono_btn_width, $mono_btn_height;
        $mono_btn_width = intval($this->get_gateway()->get_option('btn_details_width', 0));
        $mono_btn_height = intval($this->get_gateway()->get_option('btn_details_height', 0));
        $this->render('product_button');
    }

    public function cart_button()
    {
        if (!$this->canRun) { return; }
        if (!$this->enabled_in_cart()) { return; }
        if ($this->has_order_coupon()) {
            if (current_user_can( 'activate_plugins' )) {
                $this->render('button_error', ['message' => __( "mono checkout does not support order with coupons", "mono-checkout" )] );
            }
            return;
        }
        global $mono_btn_width, $mono_btn_height;
        $mono_btn_width = intval($this->get_gateway()->get_option('btn_cart_width', 0));
        $mono_btn_height = intval($this->get_gateway()->get_option('btn_cart_height', 0));
        $this->render('cart_button');
    }

    public function checkout_button()
    {
        if (!$this->canRun) { return; }
        if (!$this->enabled_on_checkout()) { return; }
        if ($this->has_order_coupon()) { return; }
        global $mono_btn_width, $mono_btn_height;
        $mono_btn_width = intval($this->get_gateway()->get_option('btn_checkout_width', 0));
        $mono_btn_height = intval($this->get_gateway()->get_option('btn_checkout_height', 0));
        $this->render('checkout_button');
    }

    protected function add_products_from_cart_to_order( \WC_Cart $cart, \WC_Order $order, $userCart = [] ) {
        $items = $cart->get_cart();
        foreach ($items as $item) {
            $qty = max(1, intval(@$userCart[$item['key']]['qty']) ?: $item['quantity']);
            $product = wc_get_product(@$item['variation_id'] ?: $item['product_id']);
            $total = wc_get_price_excluding_tax(
                $product,
                array(
                    'qty'   => $qty,
                    'order' => $order,
                    'price' => ( ($item['line_subtotal'] and $item['quantity']) ? ($item['line_subtotal'] / $item['quantity']) : $product->get_price() )
                )
            );
            $args = [
                'total' => $total,
                'subtotal' => $total,
            ];
            $order->add_product($product, $qty, $args);
        }
    }

    public function buy_product()
    {
        if (!$this->canRun) { return; }
        $product_id = intval(@$_POST['product_id']);
        $variation_id = intval(@$_POST['variation_id']);
        $qty = max(1, intval(@$_POST['quantity']));

        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            print wp_json_encode([
                'status' => false,
                'error' => 'WRONG_PRODUCT',
            ]);
            wp_die();
        }

        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($product->get_id(), $qty);
        WC()->cart->calculate_totals();

        $order = wc_create_order();
        if (WC()->session and WC()->session->get_customer_id()) {
            $order->set_customer_id(WC()->session->get_customer_id());
        }
        $order->set_status( 'not_authorized' );
        $this->add_products_from_cart_to_order(WC()->cart, $order);
        $order->calculate_totals();
        $order->save();

        $result = $this->buy_order($order);

        print wp_json_encode($result);
        wp_die();
    }

    public function buy_cart()
    {
        if (!$this->canRun) { return; }
        if ($this->has_order_coupon()) {
            wc_add_notice(__( 'This payment method is not applicable to orders with coupons.', 'mono-checkout' ), 'error' );
            print wp_json_encode([
                'result' => 'failed',
                'error' => wc_print_notices(true),
            ]);
            wp_die();
        }
        /** @var \WooCommerce $woocommerce */
        global $woocommerce;
        $userCart = rest_sanitize_object(@$_POST['cart']);

        $order = wc_create_order();
        if (WC()->session and WC()->session->get_customer_id()) {
            $order->set_customer_id(WC()->session->get_customer_id());
        }
        $order->set_status( 'not_authorized' );
        $this->add_products_from_cart_to_order(WC()->cart, $order, $userCart);
        foreach ($woocommerce->cart->get_applied_coupons() as $coupon) {
            $order->apply_coupon($coupon);
        }
        $order->calculate_totals();
        $order->save();

        $result = $this->buy_order($order);

        print wp_json_encode($result);
        wp_die();
    }

    public function buy_order(\WC_Order $order)
    {
        if (!$this->canRun) { return; }
        return $this->get_gateway()->process_payment($order->get_id());
    }

    public function shortcode_monobank_checkout($attrs)
    {
        global $mono_action, $mono_product_id, $mono_product_qty, $mono_btn_width, $mono_btn_height;
        $mono_action = 'buy_cart';
        if (@$attrs['product_id']) {
            $mono_action = 'buy_shortcode_product';
            $mono_product_qty = max(intval(@$attrs['quantity']), 1);
            $mono_product_id = $attrs['product_id'];
        }
        if (@$attrs['action']) {
            $mono_action = $attrs['action'];
        }
        $mono_btn_width = intval(@$attrs['width_px']);
        $mono_btn_height = intval(@$attrs['height_px']);
        ob_start();
        $this->render('shortcode_button');
        return ob_get_clean();
    }

    protected function render($file, $params = [])
    {
        include MONO__PLUGIN_DIR . '/templates/' . $file . '.php';
    }

    public static function plugin_activation()
    {
        if (!self::check_requirements()) {
            //die('Plugin requires Woocommerce to be activated.');
        }
    }

    protected static function check_requirements()
    {
        if ( !class_exists( 'woocommerce' ) ) { return false; }
        if ( !self::is_outgoing_request_possible() ) { return false; }
        return true;
    }

    protected static function is_outgoing_request_possible() {
        return (ini_get('allow_url_fopen' ) || extension_loaded("curl"));
    }
}