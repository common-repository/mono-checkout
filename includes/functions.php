<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if (!function_exists('monobank_checkout')) {
    function monobank_checkout($productId, $qty = 1) {
        print wp_kses(
            do_shortcode('[monobank_checkout product_id=' . $productId . ' quantity=' . $qty . ']'),
            [
                'div' => [],
                'a' => [],
                'svg' => [],
                'img' => [],
                'image' => [],
            ]
        );
    }
}