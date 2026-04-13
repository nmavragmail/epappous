(function ($) {
    'use strict';

    $(document.body).on('click', '.epc-apply-points-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true).text('...');

        $.post(epcCheckout.ajaxUrl, {
            action: 'epc_apply_points_discount',
            nonce: epcCheckout.nonce
        }, function () {
            $(document.body).trigger('update_checkout');
            $(document.body).trigger('wc_update_cart');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document.body).on('click', '.epc-remove-points-btn', function (e) {
        e.preventDefault();
        $.post(epcCheckout.ajaxUrl, {
            action: 'epc_remove_points_discount',
            nonce: epcCheckout.nonce
        }, function () {
            $(document.body).trigger('update_checkout');
            $(document.body).trigger('wc_update_cart');
        });
    });

})(jQuery);
