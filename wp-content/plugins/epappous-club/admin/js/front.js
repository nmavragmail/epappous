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
