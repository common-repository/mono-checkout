<?php

namespace mono;

class Mono_Gateway extends \WC_Payment_Gateway {

    /**
     * @var MonoApi
     */
    public $api;

    public static $initialized = false;

    public function __construct() {
        $this->id = 'monocheckout';

        $this->has_fields = false;
        $this->method_title = 'mono checkout';
        $this->method_description = 'Payment method from monobank';
        $this->order_button_text = __( 'Go to mono checkout', 'mono-checkout' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title = 'mono checkout';
        $this->description = $this->get_option('description');
        $this->icon = $this->get_option('icon');

        if (!self::$initialized) {
            self::$initialized = true;
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_api_' . $this->id, array( $this, 'process_callback' ) );
            add_action( 'woocommerce_api_' . $this->id . '_success', array( $this, 'process_return' ) );

            add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'admin_display_client_callback' ] );
            add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'admin_display_client_comments' ] );
            add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'admin_display_np_info' ] );
            add_action( 'woocommerce_order_actions_end', [ $this, 'admin_display_refresh_button' ] );
            add_filter( 'woocommerce_order_actions', [ $this, 'admin_custom_order_actions' ], 10, 2);
            add_action( 'woocommerce_process_shop_order_meta', [ $this, 'admin_process_shop_order_meta' ], 50, 2);
            add_action( 'woocommerce_admin_order_data_after_payment_info', [ $this, 'admin_order_data_after_payment_info' ], 50, 2);

            add_action( 'admin_enqueue_scripts', [$this, 'admin_scripts'] );
        }
    }

    // ADMIN PANEL
    public static function register_custom_order_status ( $order_statuses ) {
        // Status must start with "wc-"!
        $order_statuses['wc-not_authorized'] = array(
            'label'                     => __( 'Not authorized', 'mono-checkout' ),
            'public'                    => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list'    => true,
            'exclude_from_search'       => false,
            'label_count'               => _n_noop( __( 'Not authorized <span class="count">(%s)</span>', 'mono-checkout' ), __( 'Not authorized <span class="count">(%s)</span>', 'mono-checkout' ) )
        );
        $order_statuses['wc-not_confirmed'] = array(
            'label'                     => __( 'Not confirmed', 'mono-checkout' ),
            'public'                    => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list'    => true,
            'exclude_from_search'       => false,
            'label_count'               => _n_noop( __( 'Not confirmed <span class="count">(%s)</span>', 'mono-checkout' ), __( 'Not confirmed <span class="count">(%s)</span>', 'mono-checkout' ) )
        );
        $order_statuses['wc-cash_on_delivery'] = array(
            'label'                     => __( 'Payment on delivery', 'mono-checkout' ),
            'public'                    => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list'    => true,
            'exclude_from_search'       => false,
            'label_count'               => _n_noop( __( 'Payment on delivery <span class="count">(%s)</span>', 'mono-checkout' ), __( 'Payment on delivery <span class="count">(%s)</span>', 'mono-checkout' ) )
        );
        return $order_statuses;
    }

    public static function add_custom_order_status( $order_statuses ) {
        $new_order_statuses = array();
        foreach ( $order_statuses as $key => $status ) {
            $new_order_statuses[ $key ] = $status;
            if ( 'wc-pending' === $key ) {
                $new_order_statuses['wc-not_authorized'] = __( 'Not authorized', 'mono-checkout' );
                $new_order_statuses['wc-not_confirmed'] = __( 'Not confirmed', 'mono-checkout' );
                $new_order_statuses['wc-cash_on_delivery'] = __( 'Payment on delivery', 'mono-checkout' );
            }
        }
        return $new_order_statuses;
    }

    public function admin_display_client_callback( \WC_Order $order ) {
        if ($order and $this->is_mono_order($order->get_id())) {
            echo '<p><strong>' . __( 'Call client', 'mono-checkout' ) . ':</strong> ' . ( get_post_meta( $order->get_id(), 'mono_client_callback', true ) ? __( 'Yes', 'mono-checkout' ) : __( 'No', 'mono-checkout' ) ) . '</p>';
        }
    }

    public function admin_display_client_comments( \WC_Order $order ) {
        if ($order and $this->is_mono_order($order->get_id())) {
            $comments = get_post_meta( $order->get_id(), 'mono_client_comments', true );
            echo '<p><strong>' . __( 'Comments', 'mono-checkout' ) . ':</strong> ' . ( $comments ? nl2br(esc_html($comments)) : '-' ) . '</p>';
        }
    }

    public function admin_display_np_info( \WC_Order $order ) {
        if ($order and $this->is_mono_order($order->get_id())) {
            $deliveryAddressInfoEncoded = get_post_meta( $order->get_id(), 'mono_deliveryAddressInfo', true );
            if ($deliveryAddressInfoEncoded) {
                $deliveryAddressInfo = json_decode($deliveryAddressInfoEncoded, true);
                if ($deliveryAddressInfo) {
                    echo '<p><strong>' . __( 'Region', 'mono-checkout' ) . ':</strong> ' . esc_html( @$deliveryAddressInfo['areaName'] );
                    echo ' (<a href="" onclick="try {navigator.clipboard.writeText(\'' . esc_attr( @$deliveryAddressInfo['areaRef'] ) . '\');}catch(e){} return false;">' . __( 'copy code', 'mono-checkout' ) . '</a>)</p>';
                    echo '<p><strong>' . __( 'City', 'mono-checkout' ) . ':</strong> ' . esc_html( @$deliveryAddressInfo['cityName'] );
                    echo ' (<a href="" onclick="try {navigator.clipboard.writeText(\'' . esc_attr( @$deliveryAddressInfo['cityRef'] ) . '\');}catch(e){} return false;">' . __( 'copy code', 'mono-checkout' ) . '</a>)</p>';
                }
            }
        }
    }

    public function admin_custom_order_actions( $actions, \WC_Order $order ) {
        if ($order and $this->is_mono_order($order->get_id())) {
            $actions['mono-update-payment'] = __('Update payment status', 'mono-checkout');
        }
        return $actions;
    }

    public function admin_process_shop_order_meta( $order_id, $order ) {
        if ($order and $this->is_mono_order($order_id)) {
            if (filter_input(INPUT_POST, 'wc_order_action') === 'mono-update-payment') {
                $result = $this->get_api()->update_order( $this->get_order_ref( $order_id ) );
                $orderObj = $order;
                if ($order instanceof \WP_Post) {
                    $orderObj = new \WC_Order( $order_id );
                }
                $this->process_mono_order_information(
                    $result,
                    $orderObj,
                    function ( $error ) {
                        if (!$error) {
                            $error = __("Technical error", 'mono-checkout');
                        }
                        $url = wp_get_referer();
                        if (strpos($url, '?') === false) {
                            $url.= '?';
                        } else {
                            $url.= '&';
                        }
                        $url.= 'mono_error=' . urlencode(base64_encode($error));
                        wp_redirect($url);
                        exit;
                    }
                );
            }
        }
    }

    public function admin_display_refresh_button( $order_id ) {
        if ($this->is_mono_order( $order_id ) and !$this->has_final_state( $order_id )) {
            ?>
            <li class="wide">
                <button type="submit" class="button mono-update-order" name="save"><?php print __('Update payment status', 'mono-checkout'); ?></button>
            </li>
            <?php
        }
    }

    public function admin_order_data_after_payment_info( \WC_Order $order ) {
        if ($this->is_mono_order( $order->get_id() )) {
            $statuses = $this->get_statuses();
            if (array_key_exists( $order->get_status(), $statuses )) {
                $descriptions = $this->get_status_descriptions();
                $description = $descriptions[$statuses[$order->get_status()]];
                ?>
                <div class="mono-notice">
                    <p><?php print esc_html(__( $description, 'mono-checkout' )); ?></p>
                </div>
                <?php
            }
        }
    }

    protected static function get_mono_order_id( $order_id ) {
        return get_post_meta( $order_id, 'mono_order_id', true );
    }

    protected function is_mono_order( $order_id ) {
        return self::is_mono_order_by_id( $order_id );
    }

    public static function is_mono_order_by_id( $order_id ) {
        return !!self::get_mono_order_id( $order_id );
    }

    protected function has_final_state( $order_id ) {
        return (get_post_meta( $order_id, 'mono_order_state', true ) == 11);
    }

    public function admin_scripts()
    {
        wp_enqueue_script( 'mono-handlers', plugin_dir_url( MONO__PLUGIN_FILE ) . 'js/admin-handlers.js', array( 'jquery' ), MONO_VERSION );
        wp_enqueue_style( 'mono-admin', plugin_dir_url( MONO__PLUGIN_FILE ) . 'css/mono-admin.css', array(), MONO_VERSION );
    }

    public function init_form_fields()
    {
        $mono = Mono::get_instance();
        $this->form_fields = array(
            'enabled' => array(
                'title' => __( 'Enable module', 'mono-checkout' ),
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'yes'
            ),
            'token' => array(
                'title' => __( 'Token', 'mono-checkout' ),
                'type' => 'password',
                'description' => __( 'API token from mono checkout. Get your token at <a href="https://web.monobank.ua/" target="_blank">web.monobank.ua</a>', 'mono-checkout' ),
                'default' => '',
                'desc_tip'      => false,
            ),
            'order_prefix' => array(
                'title' => __( 'Order prefix', 'mono-checkout' ),
                'type' => 'text',
                'description' => __( 'Prepended to order numbers to distinguish between different stores.', 'mono-checkout' ),
                'default' => $this->get_default_order_prefix(),
                'desc_tip'      => false,
            ),
            'description' => array(
                'title' => __( 'Checkout description', 'mono-checkout' ),
                'type' => 'textarea',
                'default' => ''
            ),
            'enabled_checkout' => array(
                'title' => __( 'Enable on checkout', 'mono-checkout' ),
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'yes'
            ),
            'btn_checkout_width' => array(
                'title' => __( 'Checkout button width (px)', 'mono-checkout' ),
                'type' => 'decimal',
            ),
            'btn_checkout_height' => array(
                'title' => __( 'Checkout button height (px)', 'mono-checkout' ),
                'type' => 'decimal',
            ),
            'enabled_details' => array(
                'title' => __( 'Enable on product details', 'mono-checkout' ),
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'yes'
            ),
            'btn_details_width' => array(
                'title' => __( 'Product details button width (px)', 'mono-checkout' ),
                'type' => 'decimal',
            ),
            'btn_details_height' => array(
                'title' => __( 'Product details button height (px)', 'mono-checkout' ),
                'type' => 'decimal',
            ),
            'enabled_cart' => array(
                'title' => __( 'Enable in cart', 'mono-checkout' ),
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'yes'
            ),
            'btn_cart_width' => array(
                'title' => __( 'Cart button width (px)', 'mono-checkout' ),
                'type' => 'decimal',
            ),
            'btn_cart_height' => array(
                'title' => __( 'Cart button height (px)', 'mono-checkout' ),
                'type' => 'decimal',
            ),
            'button' => array(
                'title' => __( 'Button style', 'mono-checkout' ),
                'type' => 'radio_list',
                'default' => 'black_normal',
                'options' => $mono->get_buttons(),
            ),
            'delivery_methods' => array(
                'title' => __( 'Delivery options', 'mono-checkout' ),
                'type' => 'multiselect',
                'default' => '',
                'options' => $this->getShippingOptions(),
                'description' => __( 'Use Ctrl for multiple choices', 'mono-checkout' ),
            ),
            'free_delivery_from' => array(
                'title' => __( 'Free delivery from', 'mono-checkout' ),
                'type' => 'price',
                'default' => '',
                'description' => __( 'Free delivery from this order subtotal. Empty for paid delivery.', 'mono-checkout' ),
            ),
            'payment_methods' => array(
                'title' => __( 'Payment methods', 'mono-checkout' ),
                'type' => 'multiselect',
                'default' => '',
                'options' => $this->getPaymentMethods(),
                'description' => __( 'Use Ctrl for multiple choices', 'mono-checkout' ),
            ),
            'payments_number' => array(
                'title' => __( 'Number of payments', 'mono-checkout' ),
                'type' => 'decimal',
                'default' => '3',
                'description' => __( 'Number of payments for Purchase in parts.', 'mono-checkout' ),
            ),
            'base_url' => array(
                'title' => __( 'Base URL', 'mono-checkout' ),
                'type' => 'text',
                'description' => __( 'Base URL for mono checkout API.', 'mono-checkout' ),
                'default' => 'https://api.monobank.ua/personal/checkout/order/',
                'desc_tip'      => true,
            ),
        );
    }

    public function get_default_order_prefix() {
        $urlparts = wp_parse_url(home_url());
        $domain = $urlparts['host'];
        $prefix = substr($domain, 0, 3);
        if (!$prefix) {
            $prefix = substr(md5(time()), 0 , 3);
        }
        if (strlen($prefix) < 3) {
            $prefix = substr($prefix . $prefix . $prefix, 0, 3);
        }
        return strtoupper($prefix);
    }

    protected function getShippingOptions()
    {
        return [
            'pickup' => __( 'Pickup', 'mono-checkout' ),
            'courier' => __( 'Courier', 'mono-checkout' ),
            'np_brnm' => __( 'Nova Poshta', 'mono-checkout' ),
            'np_box' => __( 'NP Postbox', 'mono-checkout' ),
        ];
    }

    protected function getPaymentMethods()
    {
        return [
            'card' => __( 'Card', 'mono-checkout' ),
            'payment_on_delivery' => __( 'Payment on delivery', 'mono-checkout' ),
            'part_purchase' => __( 'Purchase in parts', 'mono-checkout' ),
        ];
    }

    public function get_statuses() {
        return [
            'not_authorized' => 'Not authorized',
            'not_confirmed' => 'Not confirmed',
            'pending' => 'Pending payment',
            'cash_on_delivery' => 'Payment on delivery',
            'processing' => 'Processing',
            'failed' => 'Failed',
        ];
    }

    public function get_status_descriptions() {
        __( 'Pending payment', 'mono-checkout' );
        return [
            'Not authorized' => 'Користувач не пройшов авторизацію при вході в чекаут. Товар не відправляємо.',
            'Not confirmed' => 'Користувач авторизувався в чекауті але не підтвердив покупку. Товар не відправляємо.',
            'Pending payment' => 'Користувач підтвердив оплату та перейшов на екран еквайрингу для підтвердження платежу. Підтвердження в процесі. Необхідно оновити статус трохи пізніше. Товар не відправляємо.',
            'Payment on delivery' => 'Користувач вибрав оплату при отриманні та підтвердив покупку. Можна відправляти товар.',
            'Processing' => 'Користувач вибрав оплату карткою або ПЧ та підтвердив покупку. Користувач здійснив оплату успішно. Можна відправляти товар.',
            'Failed' => 'Користувач підтвердив покупку але при платежі виникла помилка. Товар не відправляємо. Просимо користувача повторити оплату.',
        ];
    }

    public function admin_options() {
        $logo = plugin_dir_url(MONO__PLUGIN_FILE) . '/images/monocheckout_logo_black.svg';

        $appstore = plugin_dir_url(MONO__PLUGIN_FILE) . '/images/appstore.png';
        $googleplay = plugin_dir_url(MONO__PLUGIN_FILE) . '/images/googleplay.png';
        $huawei = plugin_dir_url(MONO__PLUGIN_FILE) . '/images/huawei.png';

        echo '<h2><img src="' . esc_attr($logo) . '" alt="' . esc_attr( $this->get_method_title() ) . '" style="width:200px;" />';
        wc_back_link( __( 'Return to payments', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) );
        echo '</h2>';
        ?>
            <div>
                <div>
                    <p><a href="#faq"><?php esc_html(__( 'Need help? Check out our FAQ or contact our support.', 'mono-checkout' )); ?></a></p>
                </div>
                <div>
                    <p><?php print wp_kses( __( 'To get token, please, send request in your monobank app and then visit <a href="https://web.monobank.ua/" target="_blank">web.monobank.ua</a>', 'mono-checkout'), ['a' => ['href' => [], 'title' => [], 'target' => []]]); ?></p>
                    <p><?php print wp_kses( __( 'More details on <a href="https://www.monobank.ua/" target="_blank">monobank.ua</a>', 'mono-checkout'), ['a' => ['href' => [], 'title' => [], 'target' => []]]); ?></p>
                    <p><?php print esc_html( sprintf(__( 'Your callback URL: %s', 'mono-checkout'), home_url('/?wc-api=' . $this->id)) ); ?></p>
                    <p><?php print esc_html( sprintf(__( 'Your return URL: %s', 'mono-checkout'), home_url('/?wc-api=' . $this->id . '_success')) ); ?></p>
                </div>
                <div>
                    <h4><?php esc_html_e( 'Get your monobank app now:', 'mono-checkout' ); ?></h4>
                    <div>
                        <div style="display:inline-block" class="link-panel__item link-panel__ios">
                            <a class="link-panel__link badge-app-store" href="https://apps.apple.com/ua/app/apple-store/id1287005205?l=uk" target="_blank">
                                <img src="<?php print esc_attr($appstore); ?>" alt="app store" style="width:150px;" />
                            </a>
                        </div>
                        <div style="display:inline-block" class="link-panel__item link-panel__google">
                            <a class="link-panel__link badge-google-play" href="https://play.app.goo.gl/?link=https://play.google.com/store/apps/details?id%3Dcom.ftband.mono%26ddl%3D1%26pcampaignid%3Dweb_ddl_1" target="_blank">
                                <img src="<?php print esc_attr($googleplay); ?>" alt="google play" style="width:150px;" />
                            </a>
                        </div>
                        <div style="display:inline-block" class="link-panel__item link-panel__huawei">
                            <a class="link-panel__link badge-huawei-app-gallery" href="https://appgallery.huawei.com/app/C101355935" target="_blank">
                                <img src="<?php print esc_attr($huawei); ?>" alt="huawei app gallery" style="width:150px;" />
                            </a>
                        </div>
                    </div>
                </div>
                <div>
                    <h4><?php print __( 'Statuses of mono checkout orders:', 'mono-checkout' ); ?></h4>
                    <dl class="status-list">
                        <?php foreach ($this->get_status_descriptions() as $k => $v) { ?>
                        <dt><?php print __( __( $k, 'woocommerce' ), 'mono-checkout'); ?></dt>
                        <dd><?php print __( $v, 'mono-checkout'); ?></dd>
                        <?php } ?>
                    </dl>
                </div>
                <hr/>
                <div style="background: #fff;
                            border: 1px solid orange;
                            border-left-width: 4px;
                            box-shadow: 0 1px 1px rgba(0,0,0,.04);
                            margin: 5px 0px 2px;
                            padding: 1px 12px;">
                    <p><?php print wp_kses(__( '<strong>Important:</strong> mono checkout does not support orders with coupons.', 'mono-checkout'), ['strong' => []]); ?></p>
                </div>
            </div>
        <hr/>
        <h2><?php esc_html_e( 'Settings', 'mono-checkout' ); ?></h2>
        <?php
        echo '<table class="form-table">';
        $this->generate_settings_html( $this->get_form_fields(), true );
        echo '</table>';
        $this->get_admin_method_css();

        ?>
            <div id="faq" style="visibility:hidden;">
                <hr/>
                <a name="faq"></a>
                <h2><?php print __( "Frequent errors", "mono-checkout" ); ?></h2>
                <dl class="status-list">
                    <dt><?php print __( 'Fill in your mono checkout Token.', 'mono-checkout' ); ?></dt>
                    <dd><?php print __( 'Please, get your token at <a href="https://web.monobank.ua" target="_blank">web.monobank.ua</a>', 'mono-checkout'); ?></dd>
                    <dt><?php print __( 'Check your mono checkout Token.', 'mono-checkout' ); ?></dt>
                    <dd><?php print __( 'Please, make sure you used correct token from <a href="https://web.monobank.ua" target="_blank">web.monobank.ua</a>', 'mono-checkout'); ?></dd>
                    <dt><?php print __( 'Payment method X is not available for your store.', 'mono-checkout' ); ?></dt>
                    <dd><?php print __( 'Please, contact our support at <a href="https://web.monobank.ua" target="_blank">web.monobank.ua</a> to enable corresponding payment method for your account. You can disable it temporarily to keep using mono checkout.', 'mono-checkout'); ?></dd>
                    <dt><?php print __( 'Delivery method X is not available for your store.', 'mono-checkout' ); ?></dt>
                    <dd><?php print __( 'Please, contact our support at <a href="https://web.monobank.ua" target="_blank">web.monobank.ua</a> to enable corresponding delivery method for your account. You can disable it temporarily to keep using mono checkout.', 'mono-checkout'); ?></dd>
                    <dt><?php print __( 'Checkout is disabled in your account.', 'mono-checkout' ); ?></dt>
                    <dd><?php print __( 'Please, enable your checkout in your account at <a href="https://web.monobank.ua" target="_blank">web.monobank.ua</a>', 'mono-checkout'); ?></dd>
                </dl>
            </div>
        <script>
            jQuery(function ($) {
                $('#faq').insertAfter('#mainform');
                $('#faq').css('visibility', 'visible');
            });
        </script>
        <?php
    }

    public function get_admin_method_css()
    {
        $url = plugin_dir_url(MONO__PLUGIN_FILE) . '/css/admin-method.css';
        print "<link rel='stylesheet' href='" . esc_url($url) . "' media='all' />";
    }

    public function generate_radio_list_html($key, $data)
    {
        $field_key = $this->get_field_key( $key );

        $defaults  = array(
            'title'             => '',
            'label'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'type'              => 'text',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args( $data, $defaults );

        if ( ! $data['label'] ) {
            $data['label'] = $data['title'];
        }

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?>
                    <?php echo wp_kses(
                        $this->get_tooltip_html( $data ),
                        array(
                            'br'     => array(),
                            'em'     => array(),
                            'strong' => array(),
                            'small'  => array(),
                            'span'   => array(),
                            'ul'     => array(),
                            'li'     => array(),
                            'ol'     => array(),
                            'p'      => array(),
                        )
                    ); ?></label>
            </th>
            <td class="forminp radio_list">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
                    <?php foreach ($data['options'] as $k => $option) { ?>
                    <label for="<?php echo esc_attr( $field_key ); ?>_<?php print esc_attr($k); ?>">
                        <input <?php disabled( $data['disabled'], true ); ?> class="<?php echo esc_attr( $data['class'] ); ?>"
                             type="radio" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>_<?php print esc_attr($k); ?>"
                             style="<?php echo esc_attr( $data['css'] ); ?>" value="<?php print esc_attr($k); ?>"
                             <?php checked( $this->get_option( $key ), $k ); ?>
                             <?php echo wp_kses_post($this->get_custom_attribute_html( $data )); ?> />
                        <div><img src="<?php print esc_attr($option); ?>" alt="option <?php print esc_attr($k); ?>" /></div>
                    </label>
                    <?php } ?>
                    <?php echo wp_kses_post($this->get_description_html( $data )); ?>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    // END ADMIN PANEL

    protected function get_order_ref( $order_id ) {
        $prefix = $this->get_option('order_prefix') ?: $this->get_default_order_prefix();
        return $prefix . '-' . $order_id;
    }

    public function process_payment( $order_id ) {
        global $woocommerce;
        $order = new \WC_Order( $order_id );
        $order->set_payment_method($this);

        $count = 0;
        $products = [];
        $hasShipping = false;
        foreach ($order->get_items() as $item) {
            $count+= $item->get_quantity();
            /** @var \WC_Product $product */
            $product = $item->get_product();
            if ($product) {
                $price = $product->get_price();
                if ($item->get_quantity()) {
                    $price = $item->get_subtotal() / $item->get_quantity();
                }
                $products[] = [
                    "code_product" => $product->get_id(),
                    "name" => $product->get_name(),
                    "cnt" => $item->get_quantity(),
                    "price" => $price,
                ];
                if (!$product->is_virtual()) {
                    $hasShipping = true;
                }
            }
        }

        $currencies = include('currencies.php');
        $ccy = 980;
        $woocommerce_currency = get_woocommerce_currency();
        if (array_key_exists($woocommerce_currency, $currencies)) {
            $ccy = $currencies[$woocommerce_currency]['numericCode'];
        }

        $delivery_methods = $this->get_option('delivery_methods');
        $free_delivery_from = $this->get_option('free_delivery_from');
        $payment_methods = $this->get_option('payment_methods');
        $payments_number = $this->get_option('payments_number');
        $prefix = $this->get_option('order_prefix') ?: $this->get_default_order_prefix();

        $request = [
            "order_ref" => $this->get_order_ref( $order_id ),
            "amount" => $order->get_total(),
            "count" => $count,
            "products" => $products,
            "ccy" => $ccy,
        ];

        if ($delivery_methods) { $request['dlv_method_list'] = $delivery_methods; }
        if (!$free_delivery_from and $free_delivery_from !== '0') { $request['dlv_pay_merchant'] = 0; }
        elseif ($order->get_total() > floatval($free_delivery_from)) { $request['dlv_pay_merchant'] = 1; }
        else { $request['dlv_pay_merchant'] = 0; }
        if ($payment_methods) { $request['payment_method_list'] = $payment_methods; }
        if (!$hasShipping) {
            if (!@$request['payment_method_list']) {
                $request['payment_method_list'] = array_keys($this->getPaymentMethods());
            }
            $request['payment_method_list'] = array_values(array_filter(
                $request['payment_method_list'],
                function ($p) { return $p != 'payment_on_delivery'; }
            ));
        }
        if ($payments_number) { $request['payments_number'] = intval($payments_number); }

        $request['callback_url'] = home_url('/?wc-api=' . $this->id);
        $request['return_url'] = home_url('/?wc-api=' . $this->id . '_success');

        $api = $this->get_api();

        $result = $api->create_order( $request );
        $resultRaw = $api->last_raw_response;

        if (!$result) {
            $errorDescription = $api->last_error ?: __("Technical error", 'mono-checkout');
            if ($errorDescription == "Missing required header 'X-Token'") {
                $errorDescription = __( 'Fill in your mono checkout Token.', 'mono-checkout' );
                if (current_user_can( 'activate_plugins' )) {
                    $errorDescription.= ' <a href="' . esc_attr(admin_url('admin.php?page=wc-settings&tab=checkout&section=monocheckout#faq')) . '">' . __( 'More info', 'mono-checkout' ) . '</a>';
                }
            } elseif ($errorDescription == "forbidden") {
                $errorDescription = __( 'Check your mono checkout Token.', 'mono-checkout' );
                if (current_user_can( 'activate_plugins' )) {
                    $errorDescription.= ' <a href="' . esc_attr(admin_url('admin.php?page=wc-settings&tab=checkout&section=monocheckout#faq')) . '">' . __( 'More info', 'mono-checkout' ) . '</a>';
                }
            } elseif (stripos($errorDescription, "[payment_method_list]: ") !== false) {
                $tmp = explode(': ', $errorDescription);
                $methodCode = end($tmp);
                $paymentOptions = $this->getPaymentMethods();
                $methodName = (@$paymentOptions[$methodCode] ?: $methodCode);
                $errorDescription = sprintf(__( 'Payment method "%s" is not available for your store.', 'mono-checkout' ), esc_html($methodName));
                if (current_user_can( 'activate_plugins' )) {
                    $errorDescription.= ' <a href="' . esc_attr(admin_url('admin.php?page=wc-settings&tab=checkout&section=monocheckout#faq')) . '">' . __( 'More info', 'mono-checkout' ) . '</a>';
                }
            } elseif (stripos($errorDescription, "[dlv_method_list]: ") !== false) {
                $tmp = explode(': ', $errorDescription);
                $methodCode = end($tmp);
                $shippingOptions = $this->getShippingOptions();
                $methodName = (@$shippingOptions[$methodCode] ?: $methodCode);
                $errorDescription = sprintf(__( 'Delivery method "%s" is not available for your store.', 'mono-checkout' ), esc_html($methodName));
                if (current_user_can( 'activate_plugins' )) {
                    $errorDescription.= ' <a href="' . esc_attr(admin_url('admin.php?page=wc-settings&tab=checkout&section=monocheckout#faq')) . '">' . __( 'More info', 'mono-checkout' ) . '</a>';
                }
            } elseif (stripos($errorDescription, "BLOCKED") !== false) {
                $errorDescription = __( 'Checkout is disabled in your account.', 'mono-checkout' );
                if (current_user_can( 'activate_plugins' )) {
                    $errorDescription.= ' <a href="' . esc_attr(admin_url('admin.php?page=wc-settings&tab=checkout&section=monocheckout#faq')) . '">' . __( 'More info', 'mono-checkout' ) . '</a>';
                }
            }
            wc_add_notice(sprintf(__('Payment error: %s', 'mono-checkout'), $errorDescription), 'error' );
            $order->add_order_note(
                sprintf(
                    wp_kses(
                        __('Wrong answer from mono checkout.<br/><a class="mono-code-toggle">API answer</a><pre class="mono-api-answer">%s</pre>', 'mono-checkout'),
                        ['br' => [], 'strong' => [], 'a' => ['class' => [], 'href' => [], 'target' => []], 'pre' => ['class' => [], 'style' => []]]
                    ),
                    esc_html($resultRaw)
                )
            );
            $order->update_status('failed');
            return ([
                'result' => 'failed',
                'error' => wc_print_notices(true)
            ]);
        } elseif (@$result['errorDescription']) {
            wc_add_notice(sprintf(__('Payment error: %s', 'mono-checkout'), sanitize_text_field($result['errorDescription'])), 'error' );
            $order->add_order_note(
                    sprintf(
                        wp_kses(
                            __('mono checkout declined order: %1$s<br/><a class="mono-code-toggle">API answer</a><pre class="mono-api-answer">%2$s</pre>', 'mono-checkout'),
                            ['br' => [], 'strong' => [], 'a' => ['class' => [], 'href' => [], 'target' => []], 'pre' => ['class' => [], 'style' => []]]
                        ),
                        esc_html($result['errorDescription']),
                        esc_html($resultRaw)
                    )
            );
            $order->update_status('failed');
            return ([
                'result' => 'failed',
                'error' => wc_print_notices(true)
            ]);
        } elseif (@$result['errCode']) {
            wc_add_notice(sprintf(__('Payment error: %s', 'mono-checkout'), sanitize_text_field($result['errText'])), 'error' );
            $order->add_order_note(
                    sprintf(
                        wp_kses(
                            __('mono checkout declined order: %1$s (code: %2$s)<br/><a class="mono-code-toggle">API answer</a><pre class="mono-api-answer">%3$s</pre>', 'mono-checkout'),
                            ['br' => [], 'strong' => [], 'a' => ['class' => [], 'href' => [], 'target' => []], 'pre' => ['class' => [], 'style' => []]]
                        ),
                        esc_html($result['errText']),
                        esc_html($result['errCode']),
                        esc_html($resultRaw)
                    )
            );
            $order->update_status('failed');
            return ([
                'result' => 'failed',
                'error' => wc_print_notices(true)
            ]);
        } else {
            update_post_meta( $order->get_id(), 'mono_order_id', $result['order_id'] );
            $order->add_order_note(
                sprintf(
                    wp_kses(
                        __( 'mono ID: <strong>%1$s</strong><br/><a href="%2$s" target="_blank">Checkout link</a><br/><a class="mono-code-toggle">API answer</a><pre class="mono-api-answer">%3$s</pre>', 'mono-checkout' ),
                        ['br' => [], 'strong' => [], 'a' => ['class' => [], 'href' => [], 'target' => []], 'pre' => ['class' => [], 'style' => []]]
                    ),
                    esc_html($result['order_id']),
                    esc_url($result['redirect_url']),
                    esc_html($resultRaw)
                )
            );
            $order->update_status('not_authorized');
            $order->set_transaction_id(sanitize_text_field($result['order_id']));
            $order->save();
            setcookie('mono_order', $result['order_id'] . '||' . $order->get_id(), strtotime('+1 day'), '/');
            return ([
                'result' => 'success',
                'redirect' => $result['redirect_url']
            ]);
        }
    }

    protected function get_api() {
        if (!$this->api) {
            $url       = $this->get_option( 'base_url' );
            $token     = $this->get_option( 'token' );
            $pub_key = $this->get_option( 'pub_key' );
            if (!$pub_key) {
                $pub_key = MonoApi::fetch_pub_key( $token, $url );
                if ($pub_key) {
                    $this->update_option( 'pub_key', $pub_key );
                }
            }
            $this->api = new MonoApi( $token, $url, $pub_key );
        }
        return $this->api;
    }

    public function process_callback()
    {
        $json = file_get_contents('php://input');
        if (!$this->get_api()->validate_webhook( @$_SERVER['HTTP_X_SIGN'], $json )) { // incorrect signature
            http_response_code(400);
            wp_die('', '', 400);
        }

        $data = rest_sanitize_object(json_decode($json, true));
        if ($data) {
            $tmp = explode( '-', $data['basket_id']);
            $order_id = end( $tmp );
            $order = new \WC_Order($order_id);
            if ($order && $order->get_transaction_id() == $data['orderId']) {
                $session = WC()->session->get_session($order->get_customer_id());
                /** @var \WC_Cart $cart */
                $cart = $session['cart'];
                if ($cart and is_object($cart)) {
                    $cart->empty_cart();
                }
                $this->process_mono_order_information( $data, $order );
                $order->add_order_note(
                    sprintf(
                        wp_kses(
                            __( 'mono checkout status update:<strong>%1$s</strong><br/><a class="mono-code-toggle">API answer</a><pre class="mono-api-answer">%2$s</pre>', 'mono-checkout' ),
                            ['br' => [], 'strong' => [], 'a' => ['class' => [], 'href' => [], 'target' => []], 'pre' => ['class' => [], 'style' => []]]
                        ),
                        esc_html($data['generalStatus']),
                        esc_html($json)
                    )
                );
                do_action( 'woocommerce_checkout_order_processed', $order->get_id(), [], $order );
            }
        }
        add_filter('wp_doing_ajax', function () { return true; });
        wp_die();
    }

    public function process_return() {
        $url = home_url('/');
        if (@$_COOKIE['mono_order']) {
            $tmp = explode('||', $_COOKIE['mono_order']);
            $order = new \WC_Order(@$tmp[1]);
            if ($order and $order->get_id()) {
                WC()->cart->empty_cart();
                $url = $this->get_return_url( $order );
            }
        }
        wp_redirect($url);
        print "Redirecting to order...";
        exit;
//        add_filter('wp_doing_ajax', function () { return true; });
//        wp_die();
    }

    protected function process_mono_order_information( $data, \WC_Order $order, $errorCb = null ) {
        if (!$data) {
            if ( $errorCb ) {
                $errorCb( $this->get_api()->last_error );
            }
            return false;
        }
        if (@$data['mainClientInfo']) {
            if (@$data['mainClientInfo']['first_name'] and !$order->get_billing_first_name()) {
                $order->set_billing_first_name(sanitize_text_field($data['mainClientInfo']['first_name']));
            }
            if (@$data['mainClientInfo']['last_name'] and !$order->get_billing_last_name()) {
                $order->set_billing_last_name(sanitize_text_field($data['mainClientInfo']['last_name']));
            }
            if (@$data['mainClientInfo']['email'] and !$order->get_billing_email()) {
                $order->set_billing_email(sanitize_text_field($data['mainClientInfo']['email']));
            }
            if (@$data['mainClientInfo']['phoneNumber'] and !$order->get_billing_phone()) {
                $order->set_billing_phone(sanitize_text_field($data['mainClientInfo']['phoneNumber']));
            }
            $order->set_billing_country('UA');
        }
        if (@$data['deliveryRecipientInfo']) {
            if (@$data['deliveryRecipientInfo']['first_name'] and !$order->get_shipping_first_name()) {
                $order->set_shipping_first_name(sanitize_text_field($data['deliveryRecipientInfo']['first_name']));
            }
            if (@$data['deliveryRecipientInfo']['last_name'] and !$order->get_shipping_last_name()) {
                $order->set_shipping_last_name(sanitize_text_field($data['deliveryRecipientInfo']['last_name']));
            }
            if (@$data['deliveryRecipientInfo']['phoneNumber'] and !$order->get_shipping_phone()) {
                $order->set_shipping_phone(sanitize_text_field($data['deliveryRecipientInfo']['phoneNumber']));
            }
            if (@$data['delivery_branch_address']) {
                $order->set_shipping_address_1(sanitize_text_field($data['delivery_branch_address']));
            }
            if (@$data['delivery_branch_id']) {
                $order->set_billing_address_1(sanitize_text_field($data['delivery_branch_id']));
            }
            $order->set_shipping_country('UA');
        }
        if (@$data['deliveryAddressInfo']) {
            if (@$data['deliveryAddressInfo']['cityName']) {
                $order->set_shipping_city(sanitize_text_field($data['deliveryAddressInfo']['cityName']));
            }
            if (@$data['deliveryAddressInfo']['areaName']) {
                $order->set_shipping_state(sanitize_text_field($data['deliveryAddressInfo']['areaName']));
            }
            update_post_meta( $order->get_id(), 'mono_deliveryAddressInfo', addslashes(wp_json_encode($data['deliveryAddressInfo'])) );
        }

        update_post_meta( $order->get_id(), 'mono_client_callback', !!@$data['clientCallback'] );
        update_post_meta( $order->get_id(), 'mono_client_comments', @$data['comment'] );
        update_post_meta( $order->get_id(), 'mono_order_state', intval(@$data['order_state']) );

        $country_code = 'UA';

        $calculate_tax_for = array(
            'country' => $country_code,
            'state' => '', // Can be set (optional)
            'postcode' => '', // Can be set (optional)
            'city' => '', // Can be set (optional)
        );

        if (!$order->get_items('shipping')) {
            $item = new \WC_Order_Item_Shipping();
            $item->set_method_title( sanitize_text_field(@$data['delivery_method_desc']) );
            $item->calculate_taxes( $calculate_tax_for );
            $order->add_item( $item );
        }

        $order->calculate_totals();

        switch ($data['generalStatus']) {
            case 'not_authorized':
                $order->set_status('not_authorized');
                break;
            case 'not_confirmed':
                $order->set_status('not_confirmed');
                break;
            case 'in_process':
                $order->set_status('pending');
                break;

            case 'payment_on_delivery':
                $order->set_status('cash_on_delivery');
                break;
            case 'success':
                $order->payment_complete($order->get_transaction_id());
                break;
            case 'fail':
                $order->set_status('failed');
                break;
        }

        $order->save();
    }
}