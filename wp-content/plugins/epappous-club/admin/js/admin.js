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

    /* ─── Points Log Debug Modal ─── */

    var debugExplanations = {
        birthday_bonus: function (d) {
            return 'Το μέλος <strong>' + d.member_name + '</strong> είχε γενέθλια. ' +
                'Ένα ημερήσιο cron job ελέγχει κάθε μέρα ποια μέλη έχουν γενέθλια (βάσει date_of_birth) ' +
                'και αποδίδει αυτόματα <strong>' + d.points + ' πόντους</strong>. ' +
                'Αυτό γίνεται μία φορά ανά ημερολογιακό έτος (reference_id = ' + d.reference_id + ' = το έτος). ' +
                'Η τιμή ρυθμίζεται στο Ρυθμίσεις → Πόντοι → Μπόνους Γενεθλίων.';
        },
        referral_bonus_referrer: function (d) {
            return 'Το μέλος <strong>' + d.member_name + '</strong> προσκάλεσε κάποιον (μέλος #' + d.reference_id + ') ' +
                'μέσω referral link και κέρδισε <strong>' + d.points + ' πόντους</strong> ως ανταμοιβή. ' +
                'Η ανταμοιβή δόθηκε κατά την εγγραφή του νέου μέλους. ' +
                'Ρυθμίζεται στο Ρυθμίσεις → Referral → Ανταμοιβή Αυτού που Προσκαλεί.';
        },
        referral_bonus_referred: function (d) {
            return 'Το μέλος <strong>' + d.member_name + '</strong> εγγράφηκε μέσω referral link ' +
                'από μέλος #' + d.reference_id + ' και κέρδισε <strong>' + d.points + ' πόντους</strong> ως μπόνους εγγραφής. ' +
                'Ρυθμίζεται στο Ρυθμίσεις → Referral → Ανταμοιβή Νέου Μέλους.';
        },
        referral_purchase_referrer: function (d) {
            return 'Ο referred φίλος ολοκλήρωσε αγορά (παραγγελία #' + d.reference_id + '). ' +
                'Ο referrer <strong>' + d.member_name + '</strong> κέρδισε <strong>' + d.points + ' πόντους</strong>. ' +
                'Ρυθμίζεται στο Ρυθμίσεις → Referral (Track Purchase + Reward Referrer).';
        },
        referral_purchase_referred: function (d) {
            return 'Το μέλος <strong>' + d.member_name + '</strong> ολοκλήρωσε την πρώτη αγορά (παραγγελία #' + d.reference_id + ') ' +
                'αφού εγγράφηκε μέσω referral. Κέρδισε <strong>' + d.points + ' πόντους</strong>. ' +
                'Ρυθμίζεται στο Ρυθμίσεις → Referral (Track Purchase + Reward Referred).';
        },
        gift_redemption: function (d) {
            return 'Το μέλος <strong>' + d.member_name + '</strong> εξαργύρωσε ένα δώρο (gift #' + d.reference_id + '). ' +
                'Αφαιρέθηκαν <strong>' + Math.abs(d.points) + ' πόντοι</strong> από το υπόλοιπό του. ' +
                'Η εξαργύρωση γίνεται μέσω της σελίδας δώρων και ελέγχεται tier, απόθεμα, και υπόλοιπο πόντων.';
        },
        order_earning: function (d) {
            return 'Το μέλος <strong>' + d.member_name + '</strong> ολοκλήρωσε παραγγελία #' + d.reference_id + ' ' +
                'και κέρδισε <strong>' + d.points + ' πόντους</strong> βάσει του ποσού αγοράς. ' +
                'Υπολογισμός: ποσό × πόντοι_ανά_€ × tier_multiplier. ' +
                'Ρυθμίζεται στο Ρυθμίσεις → Πόντοι (Πόντοι ανά €) και Βαθμίδες (Πολλαπλασιαστής).';
        },
        manual_adjustment: function (d) {
            return 'Χειροκίνητη προσαρμογή πόντων από διαχειριστή. ' +
                '<strong>' + (d.points >= 0 ? '+' : '') + d.points + ' πόντοι</strong> στο μέλος ' +
                '<strong>' + d.member_name + '</strong>.';
        },
        points_expiry: function (d) {
            return 'Αυτόματη λήξη πόντων. <strong>' + Math.abs(d.points) + ' πόντοι</strong> έληξαν ' +
                'βάσει της ρύθμισης Λήξη Πόντων (' + d.reference_id + ' ημέρες). ' +
                'Ρυθμίζεται στο Ρυθμίσεις → Πόντοι → Λήξη Πόντων.';
        },
        signup_bonus: function (d) {
            return 'Μπόνους εγγραφής. Το μέλος <strong>' + d.member_name + '</strong> κέρδισε ' +
                '<strong>' + d.points + ' πόντους</strong> κατά την εγγραφή στο club.';
        },
        checkout_redemption: function (d) {
            return 'Το μέλος <strong>' + d.member_name + '</strong> χρησιμοποίησε <strong>' + Math.abs(d.points) +
                ' πόντους</strong> ως έκπτωση στην παραγγελία #' + d.reference_id + '. ' +
                'Η αξία μετατράπηκε σε € βάσει της ρύθμισης Αξία Πόντου (epc_points_value_euro). ' +
                'Μέγιστο ποσοστό έκπτωσης: epc_max_redeem_percent. Ελάχιστοι πόντοι: epc_min_redeem_points.';
        }
    };

    function getDefaultExplanation(d) {
        return 'Ο λόγος "<strong>' + d.reason + '</strong>" δεν αναγνωρίζεται ως γνωστός τύπος. ' +
            'Πόντοι: <strong>' + d.points + '</strong>. ' +
            'Reference: ' + d.reference_type + ' #' + d.reference_id + '. ' +
            'Ελέγξτε τον κώδικα για custom λόγους χρέωσης πόντων.';
    }

    $(document).on('click', '.epc-debug-btn', function () {
        var data = $(this).data('log');
        if (!data) return;

        var explainFn = debugExplanations[data.reason] || getDefaultExplanation;
        var explanation = explainFn(data);

        var html = '<div class="epc-debug-section">' +
            '<h4>Στοιχεία Μέλους</h4>' +
            '<div class="epc-debug-grid">' +
            '<span class="epc-debug-label">ID Μέλους:</span><span class="epc-debug-value">' + data.member_id + '</span>' +
            '<span class="epc-debug-label">Όνομα:</span><span class="epc-debug-value">' + (data.member_name || '—') + '</span>' +
            '<span class="epc-debug-label">Email:</span><span class="epc-debug-value">' + (data.member_email || '—') + '</span>' +
            '<span class="epc-debug-label">Tier:</span><span class="epc-debug-value">' + (data.member_tier || '—') + '</span>' +
            '<span class="epc-debug-label">Τρέχοντες Πόντοι:</span><span class="epc-debug-value">' + data.member_points + '</span>' +
            '<span class="epc-debug-label">Referral Code:</span><span class="epc-debug-value"><code>' + (data.referral_code || '—') + '</code></span>' +
            '</div></div>';

        html += '<div class="epc-debug-section">' +
            '<h4>Στοιχεία Εγγραφής</h4>' +
            '<div class="epc-debug-grid">' +
            '<span class="epc-debug-label">Log ID:</span><span class="epc-debug-value">#' + data.id + '</span>' +
            '<span class="epc-debug-label">Πόντοι:</span><span class="epc-debug-value"><strong>' + (data.points >= 0 ? '+' : '') + data.points + '</strong></span>' +
            '<span class="epc-debug-label">Λόγος (key):</span><span class="epc-debug-value"><code>' + data.reason + '</code></span>' +
            '<span class="epc-debug-label">Λόγος:</span><span class="epc-debug-value">' + data.reason_label + '</span>' +
            '<span class="epc-debug-label">Reference:</span><span class="epc-debug-value"><code>' + data.reference_type + ' #' + data.reference_id + '</code></span>' +
            '<span class="epc-debug-label">Ημερομηνία:</span><span class="epc-debug-value">' + data.created_at + '</span>' +
            '</div></div>';

        html += '<div class="epc-debug-explanation">' +
            '<strong>Γιατί δόθηκαν αυτοί οι πόντοι;</strong>' +
            explanation +
            '</div>';

        $('#epc-debug-content').html(html);
        $('#epc-debug-modal').show();
    });

    $(document).on('click', '#epc-debug-modal .epc-modal-close, #epc-debug-modal .epc-modal-close-btn, #epc-debug-modal .epc-modal-overlay', function () {
        $('#epc-debug-modal').hide();
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
