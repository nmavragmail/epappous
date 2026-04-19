(function ($) {
    'use strict';

    function epcFI(key, fallback) {
        try {
            return (epcFront.i18n && typeof epcFront.i18n[key] !== 'undefined')
                ? epcFront.i18n[key]
                : (typeof fallback === 'string' ? fallback : key);
        } catch (e) {
            return typeof fallback === 'string' ? fallback : key;
        }
    }

    function showMessage($container, type, text) {
        $container.html('<div class="epc-msg epc-msg-' + type + '">' + text + '</div>');
    }

    /* ─── Registration Form ─── */

    $('#epc-register-form').on('submit', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $('#epc-register-submit');
        var $msg = $('#epc-register-messages');

        $btn.prop('disabled', true).text(epcFI('submitting'));
        $msg.empty();

        $.post(epcFront.ajaxUrl, $form.serialize(), function (response) {
            if (response.success) {
                showMessage($msg, 'success', response.data.message);
                $form[0].reset();
            } else {
                showMessage($msg, 'error', response.data);
            }
        }).fail(function () {
            showMessage($msg, 'error', epcFI('networkError'));
        }).always(function () {
            $btn.prop('disabled', false).text(epcFI('register'));
        });
    });

    /* ─── Referral link copy (member dashboard) ─── */

    $(document).on('click', '.epc-copy-ref-link', function () {
        var text = $(this).data('copy') || ($('#epc-ref-share-url').length ? $('#epc-ref-share-url').val() : '');
        if (!text) {
            return;
        }
        function ok() {
            var $btn = $('.epc-copy-ref-link');
            var prev = $btn.text();
            $btn.text(epcFI('copied'));
            setTimeout(function () { $btn.text(prev); }, 2000);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(ok);
        } else {
            var $inp = $('#epc-ref-share-url');
            $inp.trigger('focus').trigger('select');
            try {
                document.execCommand('copy');
                ok();
            } catch (e) {}
        }
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
            showMessage($msg, 'error', epcFI('networkErrorShort'));
        });
    });

})(jQuery);
