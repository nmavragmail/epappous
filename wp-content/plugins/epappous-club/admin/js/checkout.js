(function ($) {
    'use strict';

    /**
     * Club signup at checkout: for guests, section is hidden until "Create an account" is checked.
     */
    function epcCheckoutClubInit() {
        var cfg = window.epcCheckout && window.epcCheckout.checkoutClub;
        if (!cfg || !cfg.needsCreateAccount) {
            return;
        }
        var $section = $('#epc-checkout-club-section');
        var $create = $('#createaccount');
        if (!$section.length || !$create.length) {
            return;
        }
        function sync() {
            var on = $create.is(':checked');
            $section.toggle(on);
            if (!on) {
                $('#epc_checkout_join_club').prop('checked', false);
                $('#epc_checkout_dob').val('');
                $('#epc-checkout-club-dob-wrap').hide();
            }
        }
        $create.on('change', sync);
        $(document.body).on('updated_checkout', function () {
            sync();
        });
        sync();
    }

    $(document.body).on('change', '#epc_checkout_join_club', function () {
        $('#epc-checkout-club-dob-wrap').toggle($(this).is(':checked'));
    });

    $(function () {
        epcCheckoutClubInit();
    });

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
