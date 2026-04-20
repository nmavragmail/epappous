(function ($) {
    'use strict';

    /**
     * Helpers for translatable strings (provided via wp_localize_script in PHP).
     * Falls back to the raw key if PHP localization is not yet loaded.
     */
    function epcI(key, fallback) {
        try {
            var parts = key.split('.');
            var node = epcAdmin.i18n;
            for (var i = 0; i < parts.length; i++) {
                if (!node || typeof node[parts[i]] === 'undefined') {
                    return typeof fallback === 'string' ? fallback : key;
                }
                node = node[parts[i]];
            }
            return typeof node === 'string' ? node : (typeof fallback === 'string' ? fallback : key);
        } catch (e) {
            return typeof fallback === 'string' ? fallback : key;
        }
    }

    /** Replace {placeholders} in a translated template with values. */
    function epcSprintf(template, vars) {
        if (typeof template !== 'string') return '';
        return template.replace(/\{(\w+)\}/g, function (_, k) {
            return (vars && typeof vars[k] !== 'undefined') ? vars[k] : '';
        });
    }

    /* ─── Tiers Management (disabled — re-enable with admin Tiers tab + epappous-club.php EPC_Tiers loader)
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
            '<input type="text" class="epc-tier-label" placeholder="' + epcI('placeholderTierLabel') + '" value="' + data.label + '" />' +
            '<input type="number" class="epc-tier-min" placeholder="' + epcI('placeholderTierMin') + '" value="' + data.min_points + '" min="0" />' +
            '<input type="number" class="epc-tier-mult" placeholder="' + epcI('placeholderTierMult') + '" step="0.1" value="' + data.multiplier + '" min="1" />' +
            '<input type="text" class="epc-tier-color epc-color-picker" placeholder="' + epcI('placeholderTierColor') + '" value="' + data.color + '" />' +
            '</div>' +
            '<button type="button" class="button epc-remove-tier" title="' + epcI('remove') + '">' +
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

    $('#epc-settings-form').on('submit', function () {
        collectTiers();
    });
    */

    function collectWooEarnStatuses() {
        var statuses = [];
        $('.epc-woo-earn-status:checked').each(function () {
            statuses.push($(this).val());
        });
        if (!statuses.length) {
            statuses = ['completed'];
            $('.epc-woo-earn-status[value="completed"]').prop('checked', true);
        }
        $('#epc_woo_earn_statuses_json').val(JSON.stringify(statuses));
    }

    $(document).on('change', '.epc-woo-earn-status', function () {
        collectWooEarnStatuses();
    });

    $('#epc-settings-form').on('submit', function () {
        collectWooEarnStatuses();
    });

    collectWooEarnStatuses();

    /* ─── Gift Modal ─── */

    function openGiftModal(gift) {
        var $modal = $('#epc-gift-modal');
        if (gift) {
            $('#epc-gift-modal-title').text(epcI('editGift'));
            $('#epc-gift-id').val(gift.id);
            $('#epc-gift-title').val(gift.title);
            $('#epc-gift-description').val(gift.description);
            $('#epc-gift-points').val(gift.points_required);
            $('#epc-gift-stock').val(gift.stock);
            // $('#epc-gift-tier').val(gift.tier_required);
            $('#epc-gift-product-id').val(gift.product_id || '');
            $('#epc-gift-image').val(gift.image_url || '');
            $('#epc-gift-active').prop('checked', parseInt(gift.is_active, 10) === 1);
        } else {
            $('#epc-gift-modal-title').text(epcI('newGift'));
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
                alert(epcI('error') + ' ' + (response.data || ''));
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
        if (!confirm(epcI('confirmDelete'))) {
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
            title: epcI('mediaSelectTitle'),
            button: { text: epcI('mediaUseImage') },
            multiple: false
        });
        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $input.val(attachment.url);
        });
        frame.open();
    });

    /* ─── Points Log Debug Modal ─── */

    function getReasonExplanation(d) {
        var key = 'debug.reasons.' + d.reason;
        var template = epcI(key, '');
        if (!template) {
            return epcSprintf(epcI('debug.unknownReason'), d);
        }
        var vars = {
            member_name:    d.member_name || '',
            points:         d.points,
            abs_points:     Math.abs(d.points),
            signed_points:  (d.points >= 0 ? '+' : '') + d.points,
            reference_id:   d.reference_id,
            reference_type: d.reference_type,
            reason:         d.reason
        };
        return epcSprintf(template, vars);
    }

    $(document).on('click', '.epc-debug-btn', function () {
        var data = $(this).data('log');
        if (!data) return;

        var explanation = getReasonExplanation(data);

        var html = '<div class="epc-debug-section">' +
            '<h4>' + epcI('debug.sectionMember') + '</h4>' +
            '<div class="epc-debug-grid">' +
            '<span class="epc-debug-label">' + epcI('debug.memberId') + '</span><span class="epc-debug-value">' + data.member_id + '</span>' +
            '<span class="epc-debug-label">' + epcI('debug.name') + '</span><span class="epc-debug-value">' + (data.member_name || '—') + '</span>' +
            '<span class="epc-debug-label">' + epcI('debug.email') + '</span><span class="epc-debug-value">' + (data.member_email || '—') + '</span>' +
            '<span class="epc-debug-label">' + epcI('debug.currentPoints') + '</span><span class="epc-debug-value">' + data.member_points + '</span>' +
            '<span class="epc-debug-label">' + epcI('debug.referralCode') + '</span><span class="epc-debug-value"><code>' + (data.referral_code || '—') + '</code></span>' +
            '</div></div>';

        html += '<div class="epc-debug-section">' +
            '<h4>' + epcI('debug.sectionLog') + '</h4>' +
            '<div class="epc-debug-grid">' +
            '<span class="epc-debug-label">' + epcI('debug.logId') + '</span><span class="epc-debug-value">#' + data.id + '</span>' +
            '<span class="epc-debug-label">' + epcI('debug.points') + '</span><span class="epc-debug-value"><strong>' + (data.points >= 0 ? '+' : '') + data.points + '</strong></span>' +
            '<span class="epc-debug-label">' + epcI('debug.reasonKey') + '</span><span class="epc-debug-value"><code>' + data.reason + '</code></span>' +
            '<span class="epc-debug-label">' + epcI('debug.reason') + '</span><span class="epc-debug-value">' + data.reason_label + '</span>' +
            '<span class="epc-debug-label">' + epcI('debug.reference') + '</span><span class="epc-debug-value"><code>' + data.reference_type + ' #' + data.reference_id + '</code></span>' +
            '<span class="epc-debug-label">' + epcI('debug.date') + '</span><span class="epc-debug-value">' + data.created_at + '</span>' +
            '</div></div>';

        html += '<div class="epc-debug-explanation">' +
            '<strong>' + epcI('debug.whyGiven') + '</strong>' +
            explanation +
            '</div>';

        $('#epc-debug-content').html(html);
        $('#epc-debug-modal').show();
    });

    $(document).on('click', '#epc-debug-modal .epc-modal-close, #epc-debug-modal .epc-modal-close-btn, #epc-debug-modal .epc-modal-overlay', function () {
        $('#epc-debug-modal').hide();
    });

    /* ─── Redemption history toggle (User Profile) ─── */

    $(document).on('click', '.epc-redeem-history-toggle', function () {
        var $btn  = $(this);
        var $list = $btn.closest('.epc-redeem-history').find('.epc-redeem-history-list');
        $list.slideToggle(200);
        $btn.toggleClass('open');
    });

    /* ─── Admin Notes (User Profile) ─── */

    function epcEscapeHtml(text) {
        return $('<span>').text(text).html();
    }

    function epcNoteItemHtml(d, nonce) {
        return '<div class="epc-note-item" data-note-id="' + d.id + '">' +
            '<div class="epc-note-date">' + d.date + '<br /><span>' + d.time + '</span></div>' +
            '<div class="epc-note-main">' +
            '<div class="epc-note-view">' +
            '<div class="epc-note-body">' + epcEscapeHtml(d.note) + '</div>' +
            '<div class="epc-note-actions">' +
            '<button type="button" class="button-link epc-edit-note-btn">' + epcI('editNote') + '</button>' +
            '<button type="button" class="button-link epc-delete-note-btn" data-note-id="' + d.id + '" data-nonce="' + nonce + '">' + epcI('deleteNote') + '</button>' +
            '</div></div>' +
            '<div class="epc-note-edit" style="display:none;">' +
            '<textarea class="epc-note-edit-text" rows="3">' + epcEscapeHtml(d.note) + '</textarea>' +
            '<div class="epc-note-edit-actions">' +
            '<button type="button" class="button button-primary epc-save-note-btn" data-note-id="' + d.id + '" data-user-id="' + d.user_id + '" data-nonce="' + nonce + '">' + epcI('saveNote') + '</button>' +
            '<button type="button" class="button epc-cancel-note-edit-btn">' + epcI('cancelNote') + '</button>' +
            '</div></div></div>' +
            '<div class="epc-note-meta"><small>' + epcEscapeHtml(d.author_name || '') + '</small></div></div>';
    }

    $(document).on('change', '.epc-cassette-received-input', function () {
        var yes = $('.epc-cassette-received-input[value="yes"]').is(':checked');
        $('.epc-cassette-date-input').prop('disabled', !yes);
    });

    $(document).on('click', '.epc-save-cassette-btn', function () {
        var $btn = $(this);
        var userId = $btn.data('user-id');
        var nonce = $btn.data('nonce');
        var received = $('.epc-cassette-received-input:checked').val() || 'no';
        var giftDate = $('.epc-cassette-date-input').val() || '';
        $btn.prop('disabled', true);
        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_save_cassette_gift',
            user_id: userId,
            received: received,
            gift_date: giftDate,
            nonce: nonce
        }, function (response) {
            var $msg = $('.epc-cassette-saved-msg');
            if (response.success) {
                $msg.text(epcI('saved')).css('color', '#10b981').show();
                setTimeout(function () { $msg.fadeOut(400); }, 2000);
                if (response.data && response.data.audit_text) {
                    $('.epc-cassette-audit').text(response.data.audit_text).show();
                }
            } else {
                $msg.text(epcI('error')).css('color', '#ef4444').show();
            }
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.epc-send-cassette-email-btn', function () {
        var $btn = $(this);
        var originalText = $btn.text();
        var orderId = parseInt($btn.data('order-id'), 10) || 0;
        var userId = parseInt($btn.data('user-id'), 10) || 0;
        var nonce = $btn.data('nonce') || epcAdmin.nonce;
        var $msg = $btn.closest('.epc-order-gift-actions').find('.epc-order-gift-msg');

        if (!orderId || !userId) {
            if ($msg.length) {
                $msg.text(epcI('genericError')).css('color', '#ef4444').show();
            }
            return;
        }

        $btn.prop('disabled', true).text(epcI('cassetteEmailSending', 'Αποστολή...'));
        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_send_cassette_gift_email',
            order_id: orderId,
            user_id: userId,
            nonce: nonce
        }, function (response) {
            if (!$msg.length) return;
            if (response.success) {
                var text = (response.data && response.data.message) ? response.data.message : epcI('saved');
                $msg.text(text).css('color', '#10b981').show();
                setTimeout(function () { $msg.fadeOut(400); }, 3500);
            } else {
                var err = (response && response.data && response.data.message) ? response.data.message : (response.data || epcI('error'));
                $msg.text(err).css('color', '#ef4444').show();
            }
        }).fail(function () {
            if ($msg.length) {
                $msg.text(epcI('error')).css('color', '#ef4444').show();
            }
        }).always(function () {
            $btn.prop('disabled', false).text(originalText || epcI('cassetteEmailButton', 'Ενημέρωση πελάτη για κασσετίνα'));
        });
    });

    $(document).on('click', '.epc-add-note-btn', function () {
        var $btn = $(this);
        var userId = $btn.data('user-id');
        var nonce = $btn.data('nonce');
        var $textarea = $('#epc-new-note');
        var note = $textarea.val().trim();

        if (!note) return;
        $btn.prop('disabled', true);

        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_add_note',
            user_id: userId,
            note: note,
            nonce: nonce
        }, function (response) {
            if (response.success) {
                var d = response.data;
                d.user_id = userId;
                var html = epcNoteItemHtml(d, nonce);
                $('.epc-no-notes').remove();
                $('#epc-notes-timeline').prepend(html);
                $textarea.val('');

                var $msg = $('.epc-note-saved-msg');
                $msg.stop(true).fadeIn(200);
                setTimeout(function () { $msg.fadeOut(400); }, 2000);
            }
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.epc-edit-note-btn', function () {
        var $item = $(this).closest('.epc-note-item');
        $item.find('.epc-note-view').hide();
        $item.find('.epc-note-edit').show();
    });

    $(document).on('click', '.epc-cancel-note-edit-btn', function () {
        var $item = $(this).closest('.epc-note-item');
        var original = $item.find('.epc-note-body').text();
        $item.find('.epc-note-edit-text').val(original);
        $item.find('.epc-note-edit').hide();
        $item.find('.epc-note-view').show();
    });

    $(document).on('click', '.epc-save-note-btn', function () {
        var $btn = $(this);
        var noteId = $btn.data('note-id');
        var userId = $btn.data('user-id');
        var nonce = $btn.data('nonce');
        var $item = $btn.closest('.epc-note-item');
        var noteText = $item.find('.epc-note-edit-text').val().trim();
        if (!noteId || !noteText) return;
        $btn.prop('disabled', true);
        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_update_note',
            note_id: noteId,
            user_id: userId,
            note: noteText,
            nonce: nonce
        }, function (response) {
            if (response.success) {
                $item.find('.epc-note-body').text(noteText);
                var $hint = $item.find('.epc-note-updated-hint');
                var hintText = epcI('noteEditedPrefix') + ' ' + response.data.updated_str;
                if ($hint.length) {
                    $hint.text(hintText);
                } else {
                    $item.find('.epc-note-date').append('<br /><span class="epc-note-updated-hint">' + hintText + '</span>');
                }
                $item.find('.epc-note-edit').hide();
                $item.find('.epc-note-view').show();
            }
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.epc-delete-note-btn', function () {
        if (!confirm(epcI('confirmDeleteNote'))) return;
        var $btn = $(this);
        var noteId = $btn.data('note-id');
        var nonce = $btn.data('nonce');

        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_delete_note',
            note_id: noteId,
            nonce: nonce
        }, function (response) {
            if (response.success) {
                $btn.closest('.epc-note-item').fadeOut(200, function () { $(this).remove(); });
            }
        });
    });

    /* ─── Membership Toggle (User Profile) ─── */

    $(document).on('change', '.epc-membership-toggle-input', function () {
        var $toggle = $(this);
        var memberId = $toggle.data('member-id');
        var nonce = $toggle.data('nonce');
        var enable = $toggle.is(':checked');

        $toggle.prop('disabled', true);

        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_toggle_membership',
            member_id: memberId,
            enable: enable ? 1 : 0,
            nonce: nonce
        }, function (response) {
            if (response.success) {
                var $label = $toggle.closest('.epc-membership-toggle').find('.epc-membership-toggle-label');
                $label.text(enable ? epcI('membershipActive') : epcI('membershipInactive'));

                var $badge = $toggle.closest('td').find('.epc-profile-badge');
                $badge.removeClass('epc-profile-badge-active epc-profile-badge-inactive');
                if (enable) {
                    $badge.addClass('epc-profile-badge-active').text(epcI('membershipActiveBadge'));
                } else {
                    $badge.addClass('epc-profile-badge-inactive').text(epcI('membershipInactiveBadge'));
                }
            } else {
                $toggle.prop('checked', !enable);
            }
        }).always(function () {
            $toggle.prop('disabled', false);
        });
    });

    /* ─── Enroll Non-member (User Profile) ─── */

    $(document).on('click', '.epc-enroll-member-btn', function () {
        var $btn = $(this);
        var userId = $btn.data('user-id');
        var nonce = $btn.data('nonce');

        $btn.prop('disabled', true).text(epcI('enrolling'));

        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_toggle_membership',
            user_id: userId,
            enable: 1,
            nonce: nonce
        }, function (response) {
            if (response.success && response.data.reload) {
                location.reload();
            } else {
                $btn.prop('disabled', false).text(epcI('enrollInClub'));
                alert(response.data || epcI('genericError'));
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(epcI('enrollInClub'));
        });
    });

    /* ─── Points Adjustment (User Profile) ─── */

    $(document).on('click', '.epc-points-adjust-btn', function () {
        var $btn = $(this);
        var memberId = $btn.data('member-id');
        var nonce = $btn.data('nonce');
        var $row = $btn.closest('.epc-points-adjust-row');
        var type = $row.find('.epc-points-adjust-type').val();
        var amount = parseInt($row.find('.epc-points-adjust-amount').val(), 10);
        var reason = $row.find('.epc-points-adjust-reason').val().trim();
        var $msg = $btn.closest('td').find('.epc-points-adjust-msg');

        if (!amount || amount < 1) {
            $msg.removeClass('success').addClass('error').html('<span class="dashicons dashicons-warning"></span> ' + epcI('fillPoints')).stop(true).fadeIn(200);
            setTimeout(function () { $msg.fadeOut(400); }, 3000);
            return;
        }

        $btn.prop('disabled', true);

        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_adjust_points',
            member_id: memberId,
            type: type,
            amount: amount,
            reason: reason,
            nonce: nonce
        }, function (response) {
            if (response.success) {
                var d = response.data;
                var currency = $('.epc-points-display').text().split(' ')[0] || '';
                $('.epc-points-display').text(currency + ' ' + d.new_points.toLocaleString('el-GR'));

                var sign = d.points_delta >= 0 ? '+' : '';
                $msg.removeClass('error').addClass('success')
                    .html('<span class="dashicons dashicons-yes-alt"></span> ' + sign + d.points_delta + ' ' + epcI('pointsWord') + ' — ' + d.admin_name + ' — ' + d.date)
                    .stop(true).fadeIn(200);
                setTimeout(function () { $msg.fadeOut(400); }, 4000);

                $row.find('.epc-points-adjust-amount').val('');
                $row.find('.epc-points-adjust-reason').val('');
            } else {
                $msg.removeClass('success').addClass('error')
                    .html('<span class="dashicons dashicons-warning"></span> ' + (response.data || epcI('genericError')))
                    .stop(true).fadeIn(200);
                setTimeout(function () { $msg.fadeOut(400); }, 3000);
            }
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ─── Points Adjustment Modal (Points Log Page) ─── */

    $(document).on('click', '#epc-log-adjust-btn', function () {
        $('#epc-log-adjust-modal').show();
        $('#epc-log-member-search').val('').focus();
        $('#epc-log-member-id').val('');
        $('#epc-log-member-selected').hide().empty();
        $('#epc-log-member-results').hide().empty();
        $('#epc-log-adjust-amount').val('');
        $('#epc-log-adjust-reason').val('');
        $('#epc-log-adjust-type').val('add');
    });

    $(document).on('click', '#epc-log-adjust-modal .epc-modal-close, #epc-log-adjust-modal .epc-modal-close-btn, #epc-log-adjust-modal .epc-modal-overlay', function () {
        $('#epc-log-adjust-modal').hide();
    });

    var memberSearchTimer = null;

    $(document).on('input', '#epc-log-member-search', function () {
        var q = $(this).val().trim();
        var $results = $('#epc-log-member-results');

        clearTimeout(memberSearchTimer);

        if (q.length < 2) {
            $results.hide().empty();
            return;
        }

        memberSearchTimer = setTimeout(function () {
            $.get(epcAdmin.ajaxUrl, {
                action: 'epc_search_members',
                q: q,
                nonce: epcAdmin.nonce
            }, function (response) {
                $results.empty();
                if (response.success && response.data.length) {
                    response.data.forEach(function (m) {
                        $results.append(
                            '<div class="epc-member-result" data-id="' + m.id + '" data-name="' + $('<span>').text(m.name).html() + '" data-email="' + $('<span>').text(m.email).html() + '" data-points="' + m.points + '" ' +
                            'style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f3f4f6;">' +
                            '<strong>' + $('<span>').text(m.name).html() + '</strong> <small style="color:#9ca3af;">' + $('<span>').text(m.email).html() + '</small>' +
                            ' <span style="color:#4f46e5;font-weight:600;float:right;">' + m.points + ' ' + epcI('pointsWord') + '</span>' +
                            '</div>'
                        );
                    });
                } else {
                    $results.append('<div style="padding:8px 12px;font-size:13px;color:#9ca3af;">' + epcI('noMembersFound') + '</div>');
                }
                $results.show();
            });
        }, 300);
    });

    $(document).on('click', '.epc-member-result', function () {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var email = $(this).data('email');
        var points = $(this).data('points');
        $('#epc-log-member-id').val(id);
        $('#epc-log-member-search').val('');
        $('#epc-log-member-results').hide().empty();
        $('#epc-log-member-selected').html('<strong>' + name + '</strong> — ' + email + ' — <span style="color:#4f46e5;">' + points + ' ' + epcI('pointsWord') + '</span> <button type="button" class="epc-log-member-clear" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:12px;margin-left:8px;">✕</button>').show();
    });

    $(document).on('mouseenter', '.epc-member-result', function () {
        $(this).css('background', '#f3f4f6');
    }).on('mouseleave', '.epc-member-result', function () {
        $(this).css('background', '#fff');
    });

    $(document).on('click', '.epc-log-member-clear', function () {
        $('#epc-log-member-id').val('');
        $('#epc-log-member-selected').hide().empty();
    });

    $(document).on('click', '#epc-log-adjust-submit', function () {
        var $btn = $(this);
        var memberId = $('#epc-log-member-id').val();
        var type = $('#epc-log-adjust-type').val();
        var amount = parseInt($('#epc-log-adjust-amount').val(), 10);
        var reason = $('#epc-log-adjust-reason').val().trim();

        if (!memberId) {
            alert(epcI('pickMember'));
            return;
        }
        if (!amount || amount < 1) {
            alert(epcI('fillPoints'));
            return;
        }

        $btn.prop('disabled', true).text(epcI('savingEllipsis'));

        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_adjust_points',
            member_id: memberId,
            type: type,
            amount: amount,
            reason: reason,
            nonce: epcAdmin.nonce
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data || epcI('genericError'));
                $btn.prop('disabled', false).text(epcI('apply'));
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(epcI('apply'));
        });
    });

    /* ─── Gift Rules Management ─── */

    $(document).on('click', '#epc-add-rule-btn', function () {
        $('#epc-gift-rule-modal').show();
        $('#epc-rule-form')[0].reset();
        $('#epc-rule-id').val('');
        $('#epc-product-search-value').val('');
        $('#epc-product-search-input').val('');
        $('#epc-product-search-results').hide().empty();
        $('.epc-rule-value-group').hide();
        $('#epc-rule-value-product').show();
    });

    $(document).on('click', '#epc-gift-rule-modal .epc-modal-close, #epc-gift-rule-modal .epc-modal-close-btn, #epc-gift-rule-modal .epc-modal-overlay', function () {
        $('#epc-gift-rule-modal').hide();
    });

    $(document).on('change', '#epc-rule-type', function () {
        var type = $(this).val();
        $('.epc-rule-value-group').hide();
        $('#epc-rule-value-' + type).show();
    });

    $('#epc-rule-form').on('submit', function (e) {
        e.preventDefault();

        // Resolve the correct rule_value based on the selected type
        var type = $('#epc-rule-type').val();
        var val  = '';
        if (type === 'product') {
            val = $('#epc-product-search-value').val();
        } else if (type === 'category') {
            val = $('select[name="rule_value_category"]').val();
        } else if (type === 'tag') {
            val = $('select[name="rule_value_tag"]').val();
        }
        $('#epc-rule-value-hidden').val(val);

        var data   = $(this).serialize();
        var ruleId = $('#epc-rule-id').val();
        var action = ruleId ? 'epc_update_gift_rule' : 'epc_add_gift_rule';

        $.post(epcAdmin.ajaxUrl, data + '&action=' + action, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data || 'Error');
            }
        });
    });

    $(document).on('click', '.epc-delete-rule-btn', function () {
        if (!confirm(epcI('confirmDeleteRule'))) return;
        var id = $(this).data('id');
        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_delete_gift_rule',
            id: id,
            nonce: epcAdmin.nonce
        }, function (response) {
            if (response.success) location.reload();
        });
    });

    $(document).on('click', '.epc-toggle-rule-btn', function () {
        var id = $(this).data('id');
        $.post(epcAdmin.ajaxUrl, {
            action: 'epc_toggle_gift_rule',
            id: id,
            nonce: epcAdmin.nonce
        }, function (response) {
            if (response.success) location.reload();
        });
    });

    /* ─── WC Product Search (native AJAX) ─── */

    var productSearchTimer = null;

    $(document).on('input', '#epc-product-search-input', function () {
        var q = $(this).val().trim();
        var $results = $('#epc-product-search-results');

        clearTimeout(productSearchTimer);

        if (q.length < 2) {
            $results.hide().empty();
            return;
        }

        productSearchTimer = setTimeout(function () {
            $.get(epcAdmin.ajaxUrl, {
                action: 'epc_search_products',
                q: q,
                nonce: epcAdmin.nonce
            }, function (response) {
                $results.empty();
                if (response.success && response.data.length) {
                    response.data.forEach(function (item) {
                        $results.append(
                            '<div class="epc-product-result" data-id="' + item.id + '" data-text="' + $('<span>').text(item.text).html() + '" ' +
                            'style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f3f4f6;">' +
                            $('<span>').text(item.text).html() + '</div>'
                        );
                    });
                } else {
                    $results.append('<div style="padding:8px 12px;font-size:13px;color:#9ca3af;">' + epcI('noProductsFound') + '</div>');
                }
                $results.show();
            });
        }, 300);
    });

    $(document).on('click', '.epc-product-result', function () {
        var id = $(this).data('id');
        var text = $(this).data('text');
        $('#epc-product-search-input').val(text);
        $('#epc-product-search-value').val(id);
        $('#epc-product-search-results').hide().empty();
    });

    $(document).on('mouseenter', '.epc-product-result', function () {
        $(this).css('background', '#f3f4f6');
    }).on('mouseleave', '.epc-product-result', function () {
        $(this).css('background', '#fff');
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#epc-rule-value-product').length) {
            $('#epc-product-search-results').hide();
        }
    });

    /* ─── Init Color Pickers ─── */

    $(document).ready(function () {
        if ($.fn.wpColorPicker) {
            $('.epc-color-picker').wpColorPicker({
                change: function () {
                    var color = $(this).wpColorPicker('color');
                    $(this).closest('.epc-tier-row').find('.epc-tier-color-preview').css('background', color);
                    if (typeof collectTiers === 'function') {
                        collectTiers();
                    }
                }
            });
        }

        /* ─── Move EPC profile boxes to the top of the profile form ─── */
        var $form = $('#your-profile, #createuser');
        if ($form.length) {
            var $boxes = $form.find('.epc-profile-box');
            if ($boxes.length) {
                var $firstChild = $form.children().first();
                $boxes.detach().insertBefore($firstChild);
            }
        }
    });

})(jQuery);
