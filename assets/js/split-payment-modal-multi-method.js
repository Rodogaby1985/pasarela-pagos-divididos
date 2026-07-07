/* jshint esversion: 6 */
/**
 * Split Payment Modal – Multi-Method Frontend Script
 *
 * Extends the base modal to support:
 *  - Per-section payment method selection (QR Transfer vs. gateway)
 *  - Inline QR code display with a 15-minute countdown timer
 *  - Parallel processing of both payments (shipping + subtotal)
 *  - Independent polling until both sections are confirmed
 *
 * Requires:
 *  - jQuery (WordPress default)
 *  - spgData (localised via wp_localize_script in split-payment-plugin.php)
 *
 * Optional QR rendering library (qrcode-generator):
 *  To enable full QR image rendering, include qrcode-generator before this script:
 *
 *    Option A – CDN (add to your theme or child theme):
 *      <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
 *
 *    Option B – npm/webpack build:
 *      const qrcode = require('qrcode-generator');
 *      window.qrcode = qrcode;
 *
 *    Option C – Enqueue via WordPress (recommended):
 *      wp_enqueue_script('qrcode-generator',
 *          'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js',
 *          array(), '1.4.4', true);
 *      // Add 'qrcode-generator' to the $deps array of 'spg-modal-js' in the plugin.
 *
 *  If the library is not loaded, a text fallback is shown with the transfer data.
 */
(function ($) {
    'use strict';

    const {
        restUrl,
        nonce,
        currency,
        i18n,
        availableMethods,
        qrExpirySeconds,
    } = window.spgData || {};

    // ── URL params injected after payment initiation redirect ─────────────────
    const params         = new URLSearchParams(window.location.search);
    const orderId        = params.get('spg_order_id');
    const sessionId      = params.get('spg_session_id');
    const shippingAmt    = parseFloat(params.get('spg_shipping_amount') || 0);
    const totalAmt       = parseFloat(params.get('spg_total_amount')    || 0);

    // ── State ─────────────────────────────────────────────────────────────────
    let shippingPaid     = false;
    let totalPaid        = false;
    let pollInterval     = null;
    let qrTimers         = {};   // section → intervalId
    let qrExpiries       = {};   // section → Unix timestamp
    let selectedMethods  = {
        shipping : '',
        total    : '',
    };
    let paymentData      = null; // result from initiate API

    // ── Init ──────────────────────────────────────────────────────────────────
    $(document).ready(function () {
        if (!orderId || !sessionId) return;

        initiateAndRender();
    });

    // ── Payment initiation ────────────────────────────────────────────────────
    function initiateAndRender() {
        renderMethodSelectionModal();
    }

    /**
     * Called once the customer has selected a method for each section.
     * Calls the REST API and then renders the payment modal.
     */
    function startPayment() {
        const $btn = $('#spg-start-payment');
        $btn.prop('disabled', true).text(escapeHtml(i18n.paying || 'Processing...'));

        $.ajax({
            url     : restUrl + 'split-payment/initiate',
            method  : 'POST',
            headers : { 'X-WP-Nonce': nonce },
            data    : {
                order_id        : orderId,
                shipping_method : selectedMethods.shipping,
                total_method    : selectedMethods.total,
            },
            success : function (response) {
                if (!response.success) {
                    showError(i18n.error || 'Could not initiate payment.');
                    $btn.prop('disabled', false).text(i18n.finalize || 'Continue');
                    return;
                }
                paymentData = response.data;
                renderPaymentModal();
                startPolling();
            },
            error   : function () {
                showError(i18n.error || 'Could not initiate payment.');
                $btn.prop('disabled', false).text(i18n.finalize || 'Continue');
            },
        });
    }

    // ── Method selection modal ────────────────────────────────────────────────
    function renderMethodSelectionModal() {
        const methods = Array.isArray(availableMethods) ? availableMethods : [];

        // Build radio lists for each section.
        const shippingMethodsHtml = buildMethodRadios('shipping', methods);
        const totalMethodsHtml    = buildMethodRadios('total', methods);

        // Pre-select first available methods.
        if (methods.length > 0) {
            selectedMethods.shipping = methods[0].slug;
            selectedMethods.total    = methods[0].slug;
        }

        const html = `
            <div class="spg-overlay" id="spg-overlay"></div>
            <div class="spg-modal spg-modal--select" id="spg-modal" role="dialog" aria-modal="true"
                 aria-label="${escapeHtml(i18n.payTitle || 'Split Payment')}">
                <h2>${escapeHtml(i18n.payTitle || 'Complete Your Payment')}</h2>

                <div class="spg-section-panel">
                    <div class="spg-section-header">
                        <span class="spg-section-title">${escapeHtml(i18n.subtotalLabel || 'Subtotal')}</span>
                        <span class="spg-section-amount">${formatAmount(totalAmt)}</span>
                    </div>
                    <div class="spg-method-list" id="spg-methods-total">
                        ${totalMethodsHtml}
                    </div>
                </div>

                <div class="spg-section-panel">
                    <div class="spg-section-header">
                        <span class="spg-section-title">${escapeHtml(i18n.shippingLabel || 'Shipping')}</span>
                        <span class="spg-section-amount">${formatAmount(shippingAmt)}</span>
                    </div>
                    <div class="spg-method-list" id="spg-methods-shipping">
                        ${shippingMethodsHtml}
                    </div>
                </div>

                <div class="spg-buttons">
                    <button class="spg-btn spg-btn-finalize" id="spg-start-payment">
                        ${escapeHtml(i18n.finalize || 'Continue')} →
                    </button>
                </div>

                <div id="spg-error-notice" class="spg-error-notice" style="display:none;"></div>
            </div>
        `;

        $('body').append(html);
        bindSelectionEvents();
    }

    function buildMethodRadios(section, methods) {
        if (!methods.length) {
            return '<p class="description">No payment methods configured.</p>';
        }
        return methods.map(function (m, idx) {
            const isChecked = idx === 0 ? 'checked' : '';
            const typeLabel = (m.type === 'qr')
                ? ' <span class="spg-method-badge spg-method-badge--qr">QR</span>'
                : '';
            return `
                <label class="spg-method-label${isChecked ? ' spg-method-label--selected' : ''}">
                    <input type="radio" name="spg_method_${escapeAttr(section)}"
                           value="${escapeAttr(m.slug)}" ${isChecked}
                           class="spg-method-radio">
                    <span class="spg-method-name">${escapeHtml(m.label)}${typeLabel}</span>
                </label>
            `;
        }).join('');
    }

    function bindSelectionEvents() {
        // Method selection.
        $(document).on('change', '.spg-method-radio', function () {
            const section = $(this).attr('name').replace('spg_method_', '');
            const value   = $(this).val();
            selectedMethods[section] = value;

            // Update visual selection.
            $(`.spg-method-label`).each(function () {
                const radio = $(this).find('.spg-method-radio');
                if (radio.attr('name') === `spg_method_${section}`) {
                    $(this).toggleClass('spg-method-label--selected', radio.is(':checked'));
                }
            });
        });

        // Start payment button.
        $('#spg-start-payment').on('click', function () {
            if (!selectedMethods.shipping || !selectedMethods.total) {
                showError('Please select a payment method for each section.');
                return;
            }
            startPayment();
        });
    }

    // ── Payment modal (after initiation) ──────────────────────────────────────
    function renderPaymentModal() {
        // Replace the selection modal with the payment modal.
        $('#spg-modal').remove();

        const d              = paymentData;
        const shippingIsQR   = d.shipping_method_type === 'qr_transfer';
        const totalIsQR      = d.total_method_type    === 'qr_transfer';

        const shippingContent = shippingIsQR
            ? buildQrPanel('shipping', d.shipping_qr_data, d.shipping_expires_at)
            : buildGatewayButton('shipping', d.shipping_payment_url, d.shipping_gateway, shippingAmt);

        const totalContent = totalIsQR
            ? buildQrPanel('total', d.total_qr_data, d.total_expires_at)
            : buildGatewayButton('total', d.total_payment_url, d.total_gateway, totalAmt);

        const html = `
            <div class="spg-modal spg-modal--pay" id="spg-modal" role="dialog" aria-modal="true"
                 aria-label="${escapeHtml(i18n.payTitle || 'Split Payment')}">
                <h2>${escapeHtml(i18n.payTitle || 'Complete Your Payment')}</h2>

                <!-- SUBTOTAL section -->
                <div class="spg-payment-section" id="spg-section-total">
                    <div class="spg-section-header">
                        <span class="spg-section-title">${escapeHtml(i18n.subtotalLabel || 'Subtotal')}</span>
                        <span class="spg-section-amount">${formatAmount(totalAmt)}</span>
                        <span class="spg-indicator loading" id="spg-total-indicator" title="Pending">⏳</span>
                    </div>
                    <div class="spg-section-content">${totalContent}</div>
                </div>

                <!-- SHIPPING section -->
                <div class="spg-payment-section" id="spg-section-shipping">
                    <div class="spg-section-header">
                        <span class="spg-section-title">${escapeHtml(i18n.shippingLabel || 'Shipping')}</span>
                        <span class="spg-section-amount">${formatAmount(shippingAmt)}</span>
                        <span class="spg-indicator loading" id="spg-shipping-indicator" title="Pending">⏳</span>
                    </div>
                    <div class="spg-section-content">${shippingContent}</div>
                </div>

                <div class="spg-buttons">
                    <button class="spg-btn spg-btn-finalize" id="spg-finalize" disabled>
                        ${escapeHtml(i18n.finalize || 'Finalize Order')} 🔒
                    </button>
                </div>
            </div>
        `;

        $('body').append(html);
        bindPaymentEvents();

        // Start QR timers.
        if (shippingIsQR && d.shipping_expires_at) {
            startQrTimer('shipping', d.shipping_expires_at);
        }
        if (totalIsQR && d.total_expires_at) {
            startQrTimer('total', d.total_expires_at);
        }

        // Render QR codes asynchronously.
        if (shippingIsQR && d.shipping_qr_data) {
            renderQrCode('shipping', d.shipping_qr_data);
        }
        if (totalIsQR && d.total_qr_data) {
            renderQrCode('total', d.total_qr_data);
        }
    }

    function buildQrPanel(section, qrData, expiresAt) {
        if (!qrData) return '';

        const aliasHtml = qrData.alias
            ? `<p class="spg-qr-alias">${escapeHtml(i18n.qrAlias || 'Alias:')} <strong>${escapeHtml(qrData.alias)}</strong></p>`
            : '';

        return `
            <div class="spg-qr-panel" id="spg-qr-panel-${escapeAttr(section)}">
                <div class="spg-qr-canvas-wrap">
                    <div id="spg-qr-canvas-${escapeAttr(section)}" class="spg-qr-canvas"></div>
                </div>
                ${aliasHtml}
                <p class="spg-qr-instruction">${escapeHtml(i18n.qrInstruction || 'Scan with your banking app')}</p>
                <p class="spg-qr-timer" id="spg-qr-timer-${escapeAttr(section)}"></p>
                <button class="spg-btn spg-btn-qr-refresh" id="spg-qr-refresh-${escapeAttr(section)}" style="display:none;">
                    ${escapeHtml(i18n.qrRefresh || 'Refresh QR')}
                </button>
            </div>
        `;
    }

    function buildGatewayButton(section, url, gwName, amount) {
        const safeUrl = sanitizeRedirectUrl(url);
        const label   = section === 'shipping'
            ? (i18n.payShipping || 'Pay Shipping')
            : (i18n.payTotal    || 'Pay Total');

        return `
            <button class="spg-btn spg-btn-gateway" id="spg-pay-${escapeAttr(section)}"
                    data-url="${escapeAttr(safeUrl)}" data-section="${escapeAttr(section)}"
                    ${safeUrl ? '' : 'disabled'}>
                ${escapeHtml(label)} – ${formatAmount(amount)}
                ${gwName ? `<small>(${escapeHtml(gwName)})</small>` : ''}
            </button>
        `;
    }

    function bindPaymentEvents() {
        // Gateway pay buttons.
        $(document).on('click', '.spg-btn-gateway', function () {
            const url     = sanitizeRedirectUrl($(this).data('url') || '');
            const section = $(this).data('section') || '';
            if (url && section) openPaymentWindow(url, section);
        });

        // QR refresh buttons.
        $(document).on('click', '.spg-btn-qr-refresh', function () {
            const section = $(this).attr('id').replace('spg-qr-refresh-', '');
            refreshQr(section);
        });

        // Finalize.
        $('#spg-finalize').on('click', finalizeOrder);
    }

    // ── QR rendering ──────────────────────────────────────────────────────────
    /**
     * Render a QR code image inside the designated container.
     * Uses the lightweight qrcode-generator library loaded below.
     *
     * @param {string} section  'shipping' or 'total'
     * @param {Object} qrData   QR payload object from the server.
     */
    function renderQrCode(section, qrData) {
        const $container = $(`#spg-qr-canvas-${section}`);
        if (!$container.length) return;

        // Encode the full payload as a JSON string → QR.
        const text = JSON.stringify(qrData);

        try {
            // Use the bundled minimal QR generator (qrCodeGenerate defined below).
            const svgString = qrCodeGenerate(text);
            $container.html(svgString);
        } catch (e) {
            $container.html('<p class="spg-qr-error">⚠ Could not render QR. Please use the alias above.</p>');
        }
    }

    function refreshQr(section) {
        const $panel   = $(`#spg-qr-panel-${section}`);
        const $refresh = $(`#spg-qr-refresh-${section}`);
        const $timer   = $(`#spg-qr-timer-${section}`);
        const $canvas  = $(`#spg-qr-canvas-${section}`);

        $refresh.prop('disabled', true).text('...');
        $canvas.html('<div class="spg-qr-loading">⏳</div>');

        $.ajax({
            url     : restUrl + 'qr/generate',
            method  : 'POST',
            headers : { 'X-WP-Nonce': nonce },
            data    : { order_id: orderId, section: section },
            success : function (response) {
                if (response.success && response.qr_data) {
                    renderQrCode(section, response.qr_data);
                    qrExpiries[section] = response.expires_at;
                    startQrTimer(section, response.expires_at);
                    $refresh.hide();
                    $timer.show();
                } else {
                    $canvas.html('<p class="spg-qr-error">⚠ Could not refresh QR.</p>');
                }
            },
            error   : function () {
                $canvas.html('<p class="spg-qr-error">⚠ Network error.</p>');
            },
            complete : function () {
                $refresh.prop('disabled', false).text(i18n.qrRefresh || 'Refresh QR');
            },
        });
    }

    // ── QR countdown timer ─────────────────────────────────────────────────────
    function startQrTimer(section, expiresAt) {
        clearQrTimer(section);
        qrExpiries[section] = expiresAt;

        qrTimers[section] = setInterval(function () {
            const remaining = expiresAt - Math.floor(Date.now() / 1000);
            const $timer    = $(`#spg-qr-timer-${section}`);
            const $refresh  = $(`#spg-qr-refresh-${section}`);

            if (remaining <= 0) {
                clearQrTimer(section);
                $timer.text(i18n.qrExpired || 'QR expired.').addClass('spg-qr-timer--expired');
                $refresh.show();
                $(`#spg-qr-canvas-${section}`).addClass('spg-qr-canvas--expired');
                return;
            }

            const mins = Math.floor(remaining / 60).toString().padStart(2, '0');
            const secs = (remaining % 60).toString().padStart(2, '0');
            $timer.text(`${i18n.qrExpires || 'Expires in'} ${mins}:${secs}`);
        }, 1000);
    }

    function clearQrTimer(section) {
        if (qrTimers[section]) {
            clearInterval(qrTimers[section]);
            delete qrTimers[section];
        }
    }

    // ── Payment popup (gateway) ────────────────────────────────────────────────
    function openPaymentWindow(url, section) {
        if (!url || !isValidHttpUrl(url)) {
            console.error('[SPG] Invalid payment URL for section:', section);
            return;
        }

        const popup = window.open(url, `spg_${section}_payment`, 'width=900,height=700,scrollbars=yes');

        if (!popup) {
            window.location.href = url;
            return;
        }

        const monitor = setInterval(function () {
            if (popup.closed) {
                clearInterval(monitor);
                pollStatus();
            }
        }, 500);
    }

    // ── Polling ────────────────────────────────────────────────────────────────
    function startPolling() {
        pollStatus();
        pollInterval = setInterval(pollStatus, 2000);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    function pollStatus() {
        if (!orderId) return;

        $.ajax({
            url     : restUrl + 'split-payment/validate',
            method  : 'POST',
            headers : { 'X-WP-Nonce': nonce },
            data    : { order_id: orderId },
            success : function (response) {
                if (!response.success) return;
                const data = response.data;

                updateSectionIndicator('shipping', data.shipping_paid);
                updateSectionIndicator('total',    data.total_paid);

                shippingPaid = data.shipping_paid;
                totalPaid    = data.total_paid;

                if (data.is_complete) {
                    stopPolling();
                    // Stop all QR timers – payment complete.
                    clearQrTimer('shipping');
                    clearQrTimer('total');
                    enableFinalizeButton();
                }
            },
        });
    }

    // ── UI helpers ─────────────────────────────────────────────────────────────
    function updateSectionIndicator(section, paid) {
        const $indicator = $(`#spg-${section}-indicator`);
        const $section   = $(`#spg-section-${section}`);

        if (paid) {
            $indicator.removeClass('loading error').addClass('success').text('✅').attr('title', i18n.paid || 'Paid');
            $section.addClass('spg-payment-section--paid');
            // Hide QR / gateway button once paid.
            $section.find('.spg-qr-panel, .spg-btn-gateway').fadeOut(400);
        }
    }

    function enableFinalizeButton() {
        $('#spg-finalize').prop('disabled', false).text((i18n.finalize || 'Finalize Order') + ' ✅');
    }

    function finalizeOrder() {
        const orderReceivedUrl = spgData.orderReceivedUrl ? sanitizeRedirectUrl(spgData.orderReceivedUrl) : null;
        if (orderReceivedUrl) {
            window.location.href = orderReceivedUrl;
        } else {
            window.location.reload();
        }
    }

    function showError(msg) {
        const $notice = $('#spg-error-notice');
        if ($notice.length) {
            $notice.text(msg).show();
        }
    }

    // ── Utility ────────────────────────────────────────────────────────────────
    function formatAmount(amount) {
        return new Intl.NumberFormat(document.documentElement.lang || 'es', {
            style    : 'currency',
            currency : currency || 'ARS',
        }).format(amount);
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return String(str || '').replace(/[^a-z0-9_\-]/gi, '-');
    }

    function sanitizeRedirectUrl(url) {
        if (!url) return '';
        try {
            const parsed = new URL(url);
            if (parsed.protocol === 'http:' || parsed.protocol === 'https:') return url;
        } catch (e) { /* not valid */ }
        return '';
    }

    function isValidHttpUrl(url) {
        return sanitizeRedirectUrl(url) !== '';
    }

    // ── Minimal QR Code Generator (inline) ────────────────────────────────────
    /**
     * Generate a minimal QR SVG for the given text.
     *
     * This uses the qrcode-generator library pattern. For production use,
     * replace with `endroid/qr-code` server-side or import a full JS QR library.
     *
     * @param  {string} text  Text to encode.
     * @return {string}       SVG markup string.
     */
    function qrCodeGenerate(text) {
        // Attempt to use the global qrcode library (qrcode-generator npm package).
        if (typeof qrcode === 'function') {
            try {
                const qr = qrcode(0, 'M');
                qr.addData(text);
                qr.make();
                return qr.createSvgTag({ scalable: true });
            } catch (e) { /* fall through */ }
        }

        // Fallback: display a text representation when no QR library is loaded.
        return `
            <div class="spg-qr-fallback">
                <p class="spg-qr-fallback-label">📲 Datos de transferencia:</p>
                <pre class="spg-qr-fallback-text">${escapeHtml(text)}</pre>
            </div>
        `;
    }

}(jQuery));
