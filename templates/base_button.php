<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
global $mono_action, $mono_product_id, $mono_product_qty, $mono_btn_width, $mono_btn_height, $product;
$mono = \mono\Mono::get_instance();
$phrase = __( 'Buy with mono checkout', 'mono-checkout' );
if (!@$mono_action) {
    $mono_action = 'buy_cart';
}
$imgStyle = '';
$attrStr = '';
if ($mono_btn_width > 0) {
    $imgStyle.= 'width:' . intval($mono_btn_width) . 'px !important;';
    $attrStr.= ' width="' . intval($mono_btn_width) . '" ';
}
if ($mono_btn_height > 0) {
    $imgStyle.= 'height:' . intval($mono_btn_height) . 'px !important;';
    $attrStr.= ' height="' . intval($mono_btn_height) . '" ';
}
if (!$mono_product_id and $product) {
    $mono_product_id = $product->get_id();
}
?>
<div class="monocheckout-wrapper">
    <a href="#"
       data-loading-phrase="<?php print esc_attr(__( 'Loading...', 'mono-checkout' )); ?>"
       <?php if (@$mono_product_id) { ?>
       data-product-id="<?php print intval($mono_product_id); ?>"
       data-product-qty="<?php print intval($mono_product_qty); ?>"
       <?php } ?>
       data-mono-action="<?php print esc_attr($mono_action); ?>">
        <?php if ($attrStr) { ?>
            <svg
                <?php if ($mono_btn_width > 0) { ?>
                    width="<?php print esc_attr(intval($mono_btn_width)); ?>"
                <?php } ?>
                <?php if ($mono_btn_height > 0) { ?>
                    height="<?php print esc_attr(intval($mono_btn_height)); ?>"
                <?php } ?>
            >
                <image preserveAspectRatio="none"
                       xlink:href="<?php print esc_url($mono->get_button_url()); ?>"
                        <?php if ($mono_btn_width > 0) { ?>
                            width="<?php print esc_attr(intval($mono_btn_width)); ?>"
                        <?php } ?>
                        <?php if ($mono_btn_height > 0) { ?>
                            height="<?php print esc_attr(intval($mono_btn_height)); ?>"
                        <?php } ?>
                />
            </svg>
        <?php } else { ?>
            <img src="<?php print esc_url($mono->get_button_url()); ?>"
                 style="<?php print esc_attr($imgStyle); ?>"
                 alt="<?php print esc_attr($phrase); ?>" />
        <?php } ?></a>
</div>