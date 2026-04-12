/**
 * 2NET Loyalty — Front-end JavaScript
 */
(function ($) {
    'use strict';

    if (typeof twonetLoyalty === 'undefined') {
        return;
    }

    var ajaxUrl = twonetLoyalty.ajax_url;
    var nonce   = twonetLoyalty.nonce;
    var i18n    = twonetLoyalty.i18n;

    /**
     * Helper: post AJAX with loading state on button.
     */
    function doAjax(action, data, $btn) {
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('...');

        data.action = action;
        data.nonce  = nonce;

        return $.post(ajaxUrl, data)
            .done(function (response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    if (typeof response.data.new_balance !== 'undefined') {
                        $('.twonet-balance-number').text(
                            response.data.new_balance.toLocaleString('el-GR')
                        );
                    }
                    // Reload cart if on cart page.
                    if ($('body').hasClass('woocommerce-cart')) {
                        setTimeout(function () {
                            location.reload();
                        }, 1500);
                    }
                } else {
                    showNotice(response.data.message || i18n.error, 'error');
                }
            })
            .fail(function () {
                showNotice(i18n.error, 'error');
            })
            .always(function () {
                $btn.prop('disabled', false).text(originalText);
            });
    }

    /**
     * Show a temporary notice.
     */
    function showNotice(message, type) {
        var $notice = $('<div class="twonet-notice twonet-notice-' + type + '">' + message + '</div>');
        $('body').append($notice);

        setTimeout(function () {
            $notice.addClass('visible');
        }, 10);

        setTimeout(function () {
            $notice.removeClass('visible');
            setTimeout(function () {
                $notice.remove();
            }, 300);
        }, 4000);
    }

    // Cart redeem form.
    $(document).on('submit', '.twonet-redeem-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var action = $form.data('action');
        var multiples = $form.find('[name="multiples"]').val() || 1;

        if (!confirm(i18n.confirm_redeem)) {
            return;
        }

        doAjax(action, { multiples: multiples }, $form.find('button'));
    });

    // Buy coupon form.
    $(document).on('submit', '.twonet-buy-coupon-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var action = $form.data('action');
        var multiples = $form.find('[name="multiples"]').val() || 1;

        doAjax(action, { multiples: multiples }, $form.find('button'));
    });

    // Redeem gift button.
    $(document).on('click', '.twonet-redeem-gift-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var productId = $btn.data('product-id');

        if (!confirm(i18n.confirm_redeem)) {
            return;
        }

        doAjax('2net_redeem_gift', { product_id: productId }, $btn);
    });

    // Copy referral link.
    $(document).on('click', '.twonet-copy-referral', function (e) {
        e.preventDefault();
        var $target = $($(this).data('target'));
        $target[0].select();
        $target[0].setSelectionRange(0, 99999);

        try {
            navigator.clipboard.writeText($target.val());
            showNotice('Copied!', 'success');
        } catch (err) {
            document.execCommand('copy');
            showNotice('Copied!', 'success');
        }
    });

    // Inject notice styles dynamically.
    $('<style>')
        .text(
            '.twonet-notice {' +
            '  position: fixed; top: 20px; right: 20px; z-index: 99999;' +
            '  padding: 12px 20px; border-radius: 6px; font-size: 14px;' +
            '  box-shadow: 0 4px 12px rgba(0,0,0,0.15);' +
            '  opacity: 0; transform: translateY(-10px);' +
            '  transition: opacity 0.3s, transform 0.3s;' +
            '}' +
            '.twonet-notice.visible { opacity: 1; transform: translateY(0); }' +
            '.twonet-notice-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }' +
            '.twonet-notice-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }'
        )
        .appendTo('head');

})(jQuery);
