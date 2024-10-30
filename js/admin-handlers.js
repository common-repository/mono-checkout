jQuery(function ($) {
  $(document).on('click', '.mono-code-toggle', function () {
    $(this).closest('.note_content').find('pre').toggle();
  });
  $(document).on('click', '.mono-update-order', function () {
    let $select = $('select[name="wc_order_action"]');
    if ($select.length > 0) {
      let select = $select[0];
      select.selectedIndex = $(select).find('option[value="mono-update-payment"]').index('option');
      $('.wc-reload').click();
    }
  });
});