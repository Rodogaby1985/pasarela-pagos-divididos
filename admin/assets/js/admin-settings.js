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
    });

    $('#spg-cancel-rule').on('click', function () {
        $('#spg-rule-form').slideUp();
    });

    $(document).on('click', '.spg-edit-rule', function () {
        const id = $(this).data('id');
        const row = $(`#spg-rules-table tr[data-id="${id}"]`);
        const cells = row.find('td');
        $('#spg-rule-id').val(id);
        $('#spg-rule-name').val(cells.eq(0).text().trim());
        $('#spg-rule-shipping-gw').val(cells.eq(1).text().trim());
        $('#spg-rule-total-gw').val(cells.eq(2).text().trim());
        $('#spg-rule-ship-pct').val(parseFloat(cells.eq(3).text().trim()));
        $('#spg-rule-total-pct').val(parseFloat(cells.eq(4).text().trim()));
        $('#spg-rule-priority').val(parseInt(cells.eq(5).text().trim(), 10));
        $('#spg-rule-active').prop('checked', cells.eq(6).text().trim() === '✅');
        $('#spg-rule-form').slideDown();
    });

    $(document).on('click', '.spg-delete-rule', function () {
        if (!confirm(i18n.confirmDelete)) return;
        const id = $(this).data('id');
        ajaxAction('spg_delete_rule', { id }, function () {
            $(`#spg-rules-table tr[data-id="${id}"]`).fadeOut(300, function () { $(this).remove(); });
        });
    });

    $('#spg-save-rule').on('click', function () {
        const data = {
            id:                  $('#spg-rule-id').val(),
            client_id:           $('input[name="client_id"]').val(),
            rule_name:           $('#spg-rule-name').val(),
            shipping_gateway:    $('#spg-rule-shipping-gw').val(),
            total_gateway:       $('#spg-rule-total-gw').val(),
            shipping_percentage: $('#spg-rule-ship-pct').val(),
            total_percentage:    $('#spg-rule-total-pct').val(),
            priority:            $('#spg-rule-priority').val(),
            is_active:           $('#spg-rule-active').is(':checked') ? 1 : 0,
        };
        ajaxAction('spg_save_rule', data, function () {
            showNotice(i18n.saved, 'success');
            setTimeout(() => location.reload(), 800);
        });
    });

    // ── Helpers ───────────────────────────────────────────────────────────────

    function ajaxAction(action, data, onSuccess) {
        $.post(ajaxUrl, { action, nonce, ...data }, function (response) {
            if (response.success) {
                onSuccess(response);
            } else {
                showNotice(response.data?.message || i18n.error, 'error');
            }
        }).fail(function () {
            showNotice(i18n.error, 'error');
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
        $('#spg-rule-ship-pct').val(100);
        $('#spg-rule-total-pct').val(100);
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

}(jQuery));
