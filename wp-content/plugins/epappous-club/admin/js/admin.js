(function ($) {
    'use strict';

    /* ─── Tiers Management ─── */

    function collectTiers() {
        var tiers = [];
        $('#epc-tiers-container .epc-tier-row').each(function () {
            tiers.push({
                slug: $(this).find('.epc-tier-slug').val(),
                label: $(this).find('.epc-tier-label').val(),
                min_points: parseInt($(this).find('.epc-tier-min').val(), 10) || 0,
                multiplier: parseFloat($(this).find('.epc-tier-mult').val()) || 1,
                color: $(this).find('.epc-tier-color').val() || '#6b7280'
            });
        });
        $('#epc_tiers_json').val(JSON.stringify(tiers));
    }

    function tierRowHtml(index, data) {
        data = data || { slug: '', label: '', min_points: 0, multiplier: 1.0, color: '#6b7280' };
        return '<div class="epc-tier-row" data-index="' + index + '">' +
            '<div class="epc-tier-color-preview" style="background:' + data.color + '"></div>' +
            '<div class="epc-tier-fields">' +
            '<input type="text" class="epc-tier-slug" placeholder="Slug" value="' + data.slug + '" />' +
            '<input type="text" class="epc-tier-label" placeholder="Ετικέτα" value="' + data.label + '" />' +
            '<input type="number" class="epc-tier-min" placeholder="Ελ. πόντοι" value="' + data.min_points + '" min="0" />' +
            '<input type="number" class="epc-tier-mult" placeholder="Πολ/στής" step="0.1" value="' + data.multiplier + '" min="1" />' +
            '<input type="text" class="epc-tier-color epc-color-picker" placeholder="Χρώμα" value="' + data.color + '" />' +
            '</div>' +
            '<button type="button" class="button epc-remove-tier" title="Αφαίρεση">' +
            '<span class="dashicons dashicons-trash"></span>' +
            '</button>' +
            '</div>';
    }

    $(document).on('click', '#epc-add-tier', function () {
        var count = $('#epc-tiers-container .epc-tier-row').length;
        var $row = $(tierRowHtml(count, null));
        $('#epc-tiers-container').append($row);
        $row.find('.epc-color-picker').wpColorPicker({
            change: function () {
                var color = $(this).wpColorPicker('color');
                $(this).closest('.epc-tier-row').find('.epc-tier-color-preview').css('background', color);
                collectTiers();
            }
        });
    });

    $(document).on('click', '.epc-remove-tier', function () {
        $(this).closest('.epc-tier-row').remove();
        collectTiers();
    });

    $(document).on('input change', '.epc-tier-slug, .epc-tier-label, .epc-tier-min, .epc-tier-mult', function () {
        collectTiers();
    });

    /* ─── Settings form: serialize tiers before submit ─── */

    $('#epc-settings-form').on('submit', function () {
        collectTiers();
    });

    /* ─── Gift Modal ─── */

    function openGiftModal(gift) {
        var $modal = $('#epc-gift-modal');
        if (gift) {
            $('#epc-gift-modal-title').text('Επεξεργασία Δώρου');
            $('#epc-gift-id').val(gift.id);
            $('#epc-gift-title').val(gift.title);
            $('#epc-gift-description').val(gift.description);
            $('#epc-gift-points').val(gift.points_required);
            $('#epc-gift-stock').val(gift.stock);
            $('#epc-gift-tier').val(gift.tier_required);
            $('#epc-gift-product-id').val(gift.product_id || '');
            $('#epc-gift-image').val(gift.image_url || '');
            $('#epc-gift-active').prop('checked', parseInt(gift.is_active, 10) === 1);
        } else {
            $('#epc-gift-modal-title').text('Νέο Δώρο');
            $('#epc-gift-form')[0].reset();
            $('#epc-gift-id').val('');
            $('#epc-gift-active').prop('checked', true);
        }
        $modal.show();
    }

    function closeGiftModal() {
        $('#epc-gift-modal').hide();
    }

    $(document).on('click', '#epc-add-gift-btn', function () {
        openGiftModal(null);
    });

    $(document).on('click', '.epc-edit-gift', function () {
        var gift = $(this).data('gift');
        openGiftModal(gift);
    });

    $(document).on('click', '.epc-modal-close, .epc-modal-close-btn, .epc-modal-overlay', closeGiftModal);

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            closeGiftModal();
        }
    });

    $('#epc-gift-form').on('submit', function (e) {
        e.preventDefault();
        var formData = $(this).serialize();
        var giftId = $('#epc-gift-id').val();
        var action = giftId ? 'epc_update_gift' : 'epc_add_gift';

        $.post(epcAdmin.ajaxUrl, formData + '&action=' + action, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(epcAdmin.i18n.error + ' ' + (response.data || ''));
            }
        });
    });

    $(document).on('click', '.epc-toggle-gift-btn', function () {
        var id = $(this).data('id');
        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_toggle_gift',
            id: id,
            nonce: epcAdmin.nonce
        }, function (response) {
            if (response.success) {
                location.reload();
            }
        });
    });

    $(document).on('click', '.epc-delete-gift-btn', function () {
        if (!confirm(epcAdmin.i18n.confirmDelete)) {
            return;
        }
        var id = $(this).data('id');
        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_delete_gift',
            id: id,
            nonce: epcAdmin.nonce
        }, function (response) {
            if (response.success) {
                location.reload();
            }
        });
    });

    /* ─── WP Media Uploader ─── */

    $(document).on('click', '.epc-upload-image', function (e) {
        e.preventDefault();
        var $input = $(this).prev('input');
        var frame = wp.media({
            title: 'Επιλογή εικόνας',
            button: { text: 'Χρήση εικόνας' },
            multiple: false
        });
        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $input.val(attachment.url);
        });
        frame.open();
    });

    /* ─── Init Color Pickers ─── */

    $(document).ready(function () {
        if ($.fn.wpColorPicker) {
            $('.epc-color-picker').wpColorPicker({
                change: function () {
                    var color = $(this).wpColorPicker('color');
                    $(this).closest('.epc-tier-row').find('.epc-tier-color-preview').css('background', color);
                    collectTiers();
                }
            });
        }
    });

})(jQuery);
