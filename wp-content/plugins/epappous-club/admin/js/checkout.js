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

    /* ─── Points Redeem Slider ─── */

    function formatMoney(amount, prefix) {
        var n = Number(amount) || 0;
        // Greek-style: comma decimals, € suffix.
        var str = n.toLocaleString('el-GR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return (prefix || '') + str + '\u00A0€';
    }

    function syncSliderUI($box) {
        var $slider = $box.find('.epc-redeem-slider');
        var pointValue = parseFloat($box.data('point-value')) || 0;
        var points = parseInt($slider.val(), 10) || 0;
        var euros = points * pointValue;

        $box.find('.epc-redeem-current-points').text(points.toLocaleString('el-GR'));
        $box.find('.epc-redeem-amount').text(formatMoney(euros, '-'));

        var min = parseInt($slider.attr('min'), 10) || 0;
        var max = parseInt($slider.attr('max'), 10) || 0;
        var range = max - min;
        var pct = range > 0 ? ((points - min) / range) * 100 : 0;
        if (pct < 0) pct = 0;
        if (pct > 100) pct = 100;
        $slider.css('--epc-fill', pct + '%');
    }

    $(document.body).on('input change', '.epc-redeem-slider', function () {
        var $box = $(this).closest('.epc-redeem-box');
        syncSliderUI($box);
    });

    // Toggle slider visibility (turn redemption on/off without changing the amount).
    $(document.body).on('change', '.epc-redeem-toggle-input', function () {
        var $box = $(this).closest('.epc-redeem-box');
        var on = $(this).is(':checked');
        $box.toggleClass('epc-redeem-box--active', on);
    });

    // Initial paint after DOM ready and after WC re-renders the totals.
    function paintSliders() {
        $('.epc-redeem-box').each(function () {
            syncSliderUI($(this));
        });
    }
    $(paintSliders);
    $(document.body).on('updated_cart_totals updated_checkout', paintSliders);

    $(document.body).on('click', '.epc-apply-points-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $box = $btn.closest('.epc-redeem-box');
        var $slider = $box.find('.epc-redeem-slider');
        var points = parseInt($slider.val(), 10) || 0;

        $btn.prop('disabled', true).text('...');

        $.post(epcCheckout.ajaxUrl, {
            action: 'epc_apply_points_discount',
            nonce: epcCheckout.nonce,
            points: points
        }, function (response) {
            if (response && response.success === false && response.data && response.data.message) {
                alert(response.data.message);
            }
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

    /* ─── Cart referral link copy ─── */

    $(document.body).on('click', '.epc-cart-referral-copy', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var text = $btn.data('copy') || $('#epc-cart-ref-link').val() || '';
        if (!text) return;

        function flash() {
            var $icon = $btn.find('.dashicons');
            var prev = $icon.attr('class') || '';
            $icon.attr('class', prev + ' dashicons-yes');
            setTimeout(function () { $icon.attr('class', prev); }, 1500);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(flash);
        } else {
            var $inp = $('#epc-cart-ref-link');
            if ($inp.length) {
                $inp.trigger('focus').trigger('select');
                try { document.execCommand('copy'); flash(); } catch (err) {}
            }
        }
    });

})(jQuery);
