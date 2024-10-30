if (typeof(MONO_API) === 'undefined') {
  var MONO_API = {};
}

MONO_API.t = function (msg) {
  if (mono.translations.hasOwnProperty(msg)) {
    return mono.translations[msg];
  }
  return msg;
};

MONO_API.buy_product = function (product_id, quantity, variation_id) {
  return new Promise((resolve, reject) => {
    jQuery.post(
      mono.ajax_url,
      {
        action: 'mono_buy_product',
        product_id: product_id,
        quantity: Math.max(1, quantity ? quantity : 1),
        variation_id: variation_id
      },
      function(response) {
        if (response && response.result === 'success') {
          //location.href = response.url;
          resolve(response);
        } else {
          reject(response);
        }
      },
      'JSON'
    );
  });
};

MONO_API.buy_current_product = function (el) {
  let form = el.closest('form');
  if (!form) {
    form = document.querySelector('form.cart');
  }

  let submitBtn = form.querySelector('.single_add_to_cart_button');
  if (!submitBtn) {
    submitBtn = form.querySelector('[type=submit]');
  }
  if (submitBtn) {
    if (submitBtn.classList.contains('disabled') || submitBtn.disabled) {
      if (submitBtn.classList.contains('wc-variation-selection-needed')) {
        jQuery(submitBtn).click();
        return new Promise((_, reject) => { reject(false); });
      }
      return new Promise((_, reject) => { reject(false); });
    }
  }

  let formData = new FormData(form);
  if (formData) {
    let product_id = formData.get('product_id');
    if (!product_id) { product_id = el.closest('form').getAttribute('data-product_id'); }
    if (!product_id) { product_id = el.getAttribute('data-product-id'); }
    if (!product_id) { product_id = form.querySelector('[name=add-to-cart]') ? form.querySelector('[name=add-to-cart]').value : null; }
    let variation_id = parseInt(formData.get('variation_id'));
    if (!variation_id) { variation_id = null; }

    let quantity = formData.get('qty');
    if (!quantity) { quantity = formData.get('quantity'); }
    if (!quantity) { quantity = 1; }
    return this.buy_product(product_id, quantity, variation_id);
  }
  return new Promise((resolve, reject) => {
    reject(MONO_API.t("Can't find current product"));
  })
};

MONO_API.buy_shortcode_product = function (btn) {
  console.log(arguments);
  let product_id = btn.getAttribute('data-product-id');
  let quantity = btn.getAttribute('data-product-qty');
  return this.buy_product(product_id, quantity);
};

MONO_API.buy_cart = function () {
  let cart_form = document.querySelector('.mono-cart-form');
  let form_data = null;
  let data = {};
  if (!cart_form) {
    cart_form = document.querySelector('.woocommerce-cart-form');
  }
  if (cart_form) {
    form_data = new FormData(cart_form);
    for (const [key, value] of form_data) {
      data[key] = value;
    }
  }
  data['action'] = 'mono_buy_cart';

  return new Promise((resolve, reject) => {
    jQuery.post(
      mono.ajax_url,
      data,
      function(response) {
        if (response && response.result === 'success') {
          //location.href = response.url;
          resolve(response);
        } else {
          reject(response);
        }
      },
      'JSON'
    );
  });
};

document.addEventListener("DOMContentLoaded", () => {
  jQuery(document).on('click', '[data-mono-action]', function (e) {
    const el = this;
    let el_errors = document.querySelector('.woocommerce-notices-wrapper');
    if (!el_errors) {
      el_errors = el.parentNode.querySelector('.mono-error');
      if (!el_errors) {
        el_errors = document.createElement('div');
        el_errors.className = 'mono-error';
        el.parentNode.appendChild(el_errors);
      }
    }
    const action = el.getAttribute('data-mono-action');
    if (MONO_API.hasOwnProperty(action) && !el.classList.contains('loading')) {
      el_errors.innerHTML = '';
      el.classList.add('loading');
      MONO_API[action](el)
        .then(result => {
          location.href = result.redirect;
        })
        .catch(error => {
          el.classList.remove('loading');
          if (!error) {
            return;
          }
          console.error('[mono checkout]', error);
          if (typeof(error) === 'object') {
            el_errors.innerHTML+= error.error;
          } else {
            el_errors.innerHTML+= error;
          }
          el_errors.scrollIntoView({behavior: 'smooth'});
        });
    }
    e.preventDefault();
    return false;
  });
});