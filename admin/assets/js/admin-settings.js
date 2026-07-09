/* jshint esversion: 6 */
(function ($) {
    'use strict';

    const { ajaxUrl, nonce, gateways, i18n } = window.spgAdmin || {};

    // ── Credential field definitions per gateway ─────────────────────────────
    const credentialFields = {
        mercadopago: [
            { key: 'access_token',    label: 'Access Token',    type: 'password' },
            { key: 'public_key',      label: 'Public Key',      type: 'text' },
            { key: 'webhook_secret',  label: 'Webhook Secret',  type: 'password' },
        ],
        nave: [
            { key: 'api_key',         label: 'API Key',         type: 'password' },
            { key: 'webhook_secret',  label: 'Webhook Secret',  type: 'password' },
        ],
        stripe: [
            { key: 'secret_key',      label: 'Secret Key',      type: 'password' },
            { key: 'publishable_key', label: 'Publishable Key', type: 'text' },
            { key: 'webhook_secret',  label: 'Webhook Secret',  type: 'password' },
        ],
        paypal: [
            { key: 'client_id',     label: 'Client ID',     type: 'text' },
            { key: 'client_secret', label: 'Client Secret', type: 'password' },
            { key: 'webhook_id',    label: 'Webhook ID',    type: 'text' },
            { key: 'mode',          label: 'Mode',          type: 'select', options: ['live', 'sandbox'] },
        ],
    };

    // ── Tabs ─────────────────────────────────────────────────────────────────
    $(document).on('click', '.spg-tabs a', function (e) {
        e.preventDefault();
        const target = $(this).attr('href');
        $('.spg-tabs a').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.spg-tab-content').hide();
        $(target).show();
    });

    // ── Gateway form ─────────────────────────────────────────────────────────
    $('#spg-add-gateway').on('click', function () {
        resetGatewayForm();
        $('#spg-gateway-form').slideDown();
    });

    $('#spg-cancel-gateway').on('click', function () {
        $('#spg-gateway-form').slideUp();
    });

    $('#spg-gw-name').on('change', function () {
        renderCredentialFields($(this).val(), {});
    });

    $(document).on('click', '.spg-edit-gateway', function () {
        const id = $(this).data('id');
        loadGateway(id);
    });

    $(document).on('click', '.spg-delete-gateway', function () {
        if (!confirm(i18n.confirmDelete)) return;
        const id = $(this).data('id');
        ajaxAction('spg_delete_gateway', { id }, function () {
            $(`#spg-gateways-table tr[data-id="${id}"]`).fadeOut(300, function () { $(this).remove(); });
        });
    });

    $('#spg-save-gateway').on('click', function () {
        const data = collectGatewayData();
        ajaxAction('spg_save_gateway', data, function (response) {
            showNotice(i18n.saved, 'success');
            setTimeout(() => location.reload(), 800);
        });
    });

    // ── Rule form ─────────────────────────────────────────────────────────────
    $('#spg-add-rule').on('click', function () {
        resetRuleForm();
        $('#spg-rule-form').slideDown();
        $('html, body').animate({ scrollTop: $('#spg-rule-form').offset().top - 60 }, 400);
    });

    $('#spg-cancel-rule').on('click', function () {
        $('#spg-rule-form').slideUp();
    });

    $(document).on('click', '.spg-edit-rule', function () {
        const id   = $(this).data('id');
        const card = $(`.spg-rule-card[data-id="${id}"]`);
        $('#spg-rule-id').val(id);
        $('#spg-rule-name').val(card.find('.spg-rule-name').text().trim());
        // Read values from the detail rows (icon + label + value).
        const details = card.find('.spg-rule-detail');
        $('#spg-rule-shipping-gw').val(details.eq(0).find('.spg-rule-value').text().trim());
        $('#spg-rule-total-gw').val(details.eq(1).find('.spg-rule-value').text().trim());
        $('#spg-rule-priority').val(parseInt(details.eq(2).find('.spg-rule-value').text().trim(), 10));
        $('#spg-rule-active').prop('checked', card.hasClass('spg-rule-active'));
        $('#spg-rule-form').slideDown();
        $('html, body').animate({ scrollTop: $('#spg-rule-form').offset().top - 60 }, 400);
    });

    $(document).on('click', '.spg-delete-rule', function () {
        if (!confirm(i18n.confirmDelete)) return;
        const id = $(this).data('id');
        ajaxAction('spg_delete_rule', { id }, function () {
            $(`.spg-rule-card[data-id="${id}"]`).fadeOut(300, function () { $(this).remove(); });
        });
    });

    $('#spg-save-rule').on('click', function () {
        const data = {
            id:               $('#spg-rule-id').val(),
            client_id:        $('input[name="client_id"]').val(),
            rule_name:        $('#spg-rule-name').val(),
            shipping_gateway: $('#spg-rule-shipping-gw').val(),
            total_gateway:    $('#spg-rule-total-gw').val(),
            priority:         $('#spg-rule-priority').val(),
            is_active:        $('#spg-rule-active').is(':checked') ? 1 : 0,
        };
        ajaxAction('spg_save_rule', data, function () {
            showNotice(i18n.saved, 'success');
            setTimeout(() => location.reload(), 800);
        });
    });

    // ── QR Transfer settings ──────────────────────────────────────────────────
    $('#spg-save-qr-settings').on('click', function () {
        const data = {
            qr_alias_subtotal : $('#spg-qr-alias-subtotal').val(),
            qr_alias_shipping : $('#spg-qr-alias-shipping').val(),
            qr_country        : $('#spg-qr-country').val(),
            qr_webhook_secret : $('#spg-qr-webhook-secret').val(),
        };
        ajaxAction('spg_save_qr_settings', data, function () {
            showNotice(i18n.saved, 'success');
            // Clear the secret field so it is not accidentally re-submitted.
            $('#spg-qr-webhook-secret').val('');
        });
    });

    // ── Helpers ───────────────────────────────────────────────────────────────

    function ajaxAction(action, data, onSuccess, onError) {
        $.post(ajaxUrl, { action, nonce, ...data }, function (response) {
            if (response.success) {
                if (typeof onSuccess === 'function') onSuccess(response.data || response);
            } else {
                if (typeof onError === 'function') {
                    onError(response.data || {});
                } else {
                    showNotice(response.data?.message || i18n.error, 'error');
                }
            }
        }).fail(function () {
            const msg = i18n.error || 'Request failed.';
            if (typeof onError === 'function') {
                onError({ message: msg });
            } else {
                showNotice(msg, 'error');
            }
        });
    }

    function showNotice(message, type) {
        const $notice = $('#spg-notice');
        $notice
            .removeClass('notice-success notice-error')
            .addClass(`notice notice-${type}`)
            .html(`<p>${message}</p>`)
            .show();
        setTimeout(() => $notice.fadeOut(), 4000);
    }

    function resetGatewayForm() {
        $('#spg-gateway-id').val('');
        $('#spg-gw-name').val('');
        $('#spg-gw-display').val('');
        $('#spg-gw-default-shipping').prop('checked', false);
        $('#spg-gw-default-total').prop('checked', false);
        $('#spg-gw-fiscal-name').val('');
        $('#spg-gw-fiscal-taxid').val('');
        $('#spg-gw-fiscal-address').val('');
        $('#spg-credentials-fields').empty();
    }

    function resetRuleForm() {
        $('#spg-rule-id').val('');
        $('#spg-rule-name').val('');
        $('#spg-rule-shipping-gw').val('');
        $('#spg-rule-total-gw').val('');
        $('#spg-rule-priority').val(10);
        $('#spg-rule-active').prop('checked', true);
    }

    function renderCredentialFields(gatewayName, existingValues) {
        const $container = $('#spg-credentials-fields');
        $container.empty();

        const fields = credentialFields[gatewayName];
        if (!fields) return;

        fields.forEach(function (field) {
            let input;
            if (field.type === 'select') {
                const opts = field.options.map(o => `<option value="${o}"${existingValues[field.key] === o ? ' selected' : ''}>${o}</option>`).join('');
                input = `<select name="credentials[${field.key}]">${opts}</select>`;
            } else {
                const val = existingValues[field.key] ? '' : ''; // Credentials not echoed for security.
                const ph  = existingValues[field.key] ? '(unchanged)' : '';
                input = `<input type="${field.type}" name="credentials[${field.key}]" class="regular-text" placeholder="${ph}">`;
            }
            $container.append(`
                <p>
                    <label><strong>${field.label}</strong></label><br>
                    ${input}
                </p>
            `);
        });
    }

    function loadGateway(id) {
        resetGatewayForm();
        $('#spg-gateway-id').val(id);

        const row  = $(`#spg-gateways-table tr[data-id="${id}"]`);
        const cells = row.find('td');

        $('#spg-gw-name').val(cells.eq(0).text().trim()).trigger('change');
        $('#spg-gw-display').val(cells.eq(1).text().trim());
        $('#spg-gw-default-shipping').prop('checked', cells.eq(2).text().trim() === '✅');
        $('#spg-gw-default-total').prop('checked', cells.eq(3).text().trim() === '✅');
        $('#spg-gw-fiscal-name').val(cells.eq(4).text().trim());

        $('#spg-gateway-form').slideDown();
    }

    function collectGatewayData() {
        const data = {
            id:                  $('#spg-gateway-id').val(),
            client_id:           $('input[name="client_id"]').val(),
            gateway_name:        $('#spg-gw-name').val(),
            display_name:        $('#spg-gw-display').val(),
            is_default_shipping: $('#spg-gw-default-shipping').is(':checked') ? 1 : 0,
            is_default_total:    $('#spg-gw-default-total').is(':checked')    ? 1 : 0,
            fiscal_entity_name:  $('#spg-gw-fiscal-name').val(),
            fiscal_tax_id:       $('#spg-gw-fiscal-taxid').val(),
            fiscal_address:      $('#spg-gw-fiscal-address').val(),
            credentials:         {},
        };

        $('#spg-credentials-fields input, #spg-credentials-fields select').each(function () {
            const name  = $(this).attr('name').match(/credentials\[(.+)\]/);
            if (name && $(this).val()) {
                data.credentials[name[1]] = $(this).val();
            }
        });

        return data;
    }

    // ── Gateways configuration page (settings-page-gateways.php) ──────────────

    // Save MercadoPago settings.
    $('#spg-mp-save').on('click', function () {
        const data = {
            mp_enabled:      $('#spg-mp-enabled').is(':checked') ? 'yes' : 'no',
            mp_sandbox:      $('#spg-mp-sandbox').val(),
            mp_access_token: $('#spg-mp-access-token').val(),
            mp_user_id:      $('#spg-mp-user-id').val(),
        };
        ajaxAction('spg_save_mp_settings', data, function () {
            showGwNotice(i18n.saved, 'success');
        });
    });

    // Verify MercadoPago credentials.
    $('#spg-mp-verify-credentials').on('click', function () {
        const $btn  = $(this);
        const $stat = $('#spg-mp-credentials-status');
        $btn.prop('disabled', true).text(i18n.verifying || 'Verifying...');
        $stat.hide();

        const data = {
            mp_access_token: $('#spg-mp-access-token').val(),
            mp_user_id:      $('#spg-mp-user-id').val(),
        };

        ajaxAction('spg_verify_mp_credentials', data, function (response) {
            $btn.prop('disabled', false).text('Verify Credentials');
            $stat.text('✅ ' + (response.message || i18n.credentialsOk))
                 .removeClass('spg-status-error').addClass('spg-status-active')
                 .show();
        }, function (response) {
            $btn.prop('disabled', false).text('Verify Credentials');
            $stat.text('❌ ' + (response.message || i18n.error))
                 .removeClass('spg-status-active').addClass('spg-status-error')
                 .show();
        });
    });

    // Create / verify MercadoPago webhook.
    $('#spg-mp-create-webhook').on('click', function () {
        const $btn  = $(this);
        const $stat = $('#spg-mp-webhook-status');
        $btn.prop('disabled', true).text(i18n.creating || 'Creating webhook...');
        $stat.hide();

        const data = {
            mp_access_token: $('#spg-mp-access-token').val(),
        };

        ajaxAction('spg_create_mp_webhook', data, function (response) {
            $btn.prop('disabled', false).text('Create / Verify Webhook');
            $stat.text('✅ ' + (response.message || i18n.webhookCreated))
                 .removeClass('spg-status-error').addClass('spg-status-active')
                 .show();
        }, function (response) {
            $btn.prop('disabled', false).text('Create / Verify Webhook');
            $stat.text('❌ ' + (response.message || i18n.error))
                 .removeClass('spg-status-active').addClass('spg-status-error')
                 .show();
        });
    });

    // Save QR Transfer settings (gateways page).
    $('#spg-qr-save').on('click', function () {
        const data = {
            qr_enabled:          $('#spg-qr-enabled').is(':checked') ? 'yes' : 'no',
            qr_alias_subtotal:   $('#spg-qr-alias-subtotal').val(),
            qr_cbu_subtotal:     $('#spg-qr-cbu-subtotal').val(),
            qr_holder_subtotal:  $('#spg-qr-holder-subtotal').val(),
            qr_alias_shipping:   $('#spg-qr-alias-shipping').val(),
            qr_cbu_shipping:     $('#spg-qr-cbu-shipping').val(),
            qr_holder_shipping:  $('#spg-qr-holder-shipping').val(),
            qr_webhook_secret:   $('#spg-qr-webhook-secret').val(),
        };
        ajaxAction('spg_save_qr_gateways_settings', data, function () {
            showGwNotice(i18n.saved, 'success');
        });
    });

    // Show a notice on the gateways page.
    function showGwNotice(message, type) {
        const $el = $('#spg-gw-notice');
        if (!$el.length) {
            showNotice(message, type);
            return;
        }
        $el.removeClass('notice-success notice-error')
           .addClass('notice notice-' + (type === 'success' ? 'success' : 'error'))
           .html('<p>' + message + '</p>')
           .show();
        setTimeout(function () { $el.fadeOut(); }, 3000);
    }

}(jQuery));
