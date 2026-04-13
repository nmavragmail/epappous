(function ($) {
    'use strict';

    function showMessage($container, type, text) {
        $container.html('<div class="epc-msg epc-msg-' + type + '">' + text + '</div>');
    }

    /* ─── Registration Form ─── */

    $('#epc-register-form').on('submit', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $('#epc-register-submit');
        var $msg = $('#epc-register-messages');

        $btn.prop('disabled', true).text('Υποβολή...');
        $msg.empty();

        $.post(epcFront.ajaxUrl, $form.serialize(), function (response) {
            if (response.success) {
                showMessage($msg, 'success', response.data.message);
                $form[0].reset();
            } else {
                showMessage($msg, 'error', response.data);
            }
        }).fail(function () {
            showMessage($msg, 'error', 'Σφάλμα δικτύου. Δοκίμασε ξανά.');
        }).always(function () {
            $btn.prop('disabled', false).text('Εγγραφή');
        });
    });

    /* ─── Gift Redemption ─── */

    $(document).on('click', '.epc-redeem-btn', function () {
        var $btn = $(this);
        if (!confirm('Είσαι σίγουρος/η ότι θέλεις να εξαργυρώσεις αυτό το δώρο;')) {
            return;
        }
        $btn.prop('disabled', true).text('...');

        $.post(epcFront.ajaxUrl, {
            action: 'epc_redeem_gift',
            gift_id: $btn.data('gift-id'),
            rule_id: $btn.data('rule-id') || 0,
            nonce: $btn.data('nonce')
        }, function (response) {
            var $msg = $('#epc-gift-catalog-messages');
            if (response.success) {
                showMessage($msg, 'success', response.data);
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                showMessage($msg, 'error', response.data);
                $btn.prop('disabled', false).text('Εξαργύρωση');
            }
        }).fail(function () {
            showMessage($('#epc-gift-catalog-messages'), 'error', 'Σφάλμα δικτύου.');
            $btn.prop('disabled', false).text('Εξαργύρωση');
        });
    });

    /* ─── Profile Form ─── */

    $('#epc-profile-form').on('submit', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $msg = $('#epc-profile-messages');

        $.post(epcFront.ajaxUrl, $form.serialize(), function (response) {
            if (response.success) {
                showMessage($msg, 'success', response.data);
            } else {
                showMessage($msg, 'error', response.data);
            }
        }).fail(function () {
            showMessage($msg, 'error', 'Σφάλμα δικτύου.');
        });
    });

})(jQuery);
