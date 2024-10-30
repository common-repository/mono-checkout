<?php
/**
 * @package mono
 */
/*
Plugin Name: mono checkout
Plugin URI: https://checkout.mono.bank/woocomerce
Description: модуль Чекауту від monobank це спосіб автоматизувати процес оформлення покупки на вашому сайті. Доступний функціонал: предзаповнення даних отримувача, рекомендації по доставці та оплаті, всі доступні способи оплати від monobank: еквайринг, Покупка частинами та оплата при отриманні. Має бути підключений інтернет-еквайринг від monobank
Version: 1.8.4
Requires at least: 5.8
Requires PHP: 7.4
Author: monobank
Author URI: https://monobank.ua/
License: GPLv2 or later
Text Domain: mono-checkout
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

define( 'MONO_VERSION', '1.8.4' );
define( 'MONO__MINIMUM_WP_VERSION', '5.8' );
define( 'MONO__PLUGIN_FILE', __FILE__ );
define( 'MONO__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once MONO__PLUGIN_DIR . '/includes/class.mono.php';
require_once MONO__PLUGIN_DIR . '/includes/MonoApi.php';
require_once MONO__PLUGIN_DIR . '/includes/functions.php';

register_activation_hook( __FILE__, array( \mono\Mono::class, 'plugin_activation' ) );

add_filter( 'woocommerce_register_shop_order_post_statuses', function ( $order_statuses ) {
    /** @var \mono\Mono $instance */
    $instance = \mono\Mono::get_instance();
    if ($instance->can_run() and  'yes' == $instance->get_gateway()->enabled) {
        return \mono\Mono_Gateway::register_custom_order_status($order_statuses);
    }
    return $order_statuses;
} );

add_filter( 'wc_order_statuses', function ( $order_statuses ) {
    /** @var \mono\Mono $instance */
    $instance = \mono\Mono::get_instance();
    if ($instance->can_run() and  'yes' == $instance->get_gateway()->enabled) {
        return \mono\Mono_Gateway::add_custom_order_status($order_statuses);
    }
    return $order_statuses;
});

add_action( 'plugins_loaded', [\mono\Mono::class, 'init']);
