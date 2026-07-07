/* jshint esversion: 6 */
/**
 * Split Payment Modal – Frontend Script
 * Handles the checkout payment modal that allows customers to pay for
 * shipping and order total independently.
 */
(function ($) {
    'use strict';

    const { restUrl, nonce, currency, i18n } = window.spgData || {};

    // Collect query-string params injected by the gateway after redirect.
    const params        = new URLSearchParams(window.location.search);
    const orderId       = params.get('spg_order_id');
    const sessionId     = params.get('spg_session_id');
    const shippingUrl   = decodeURIComponent(params.get('spg_shipping_url') || '');
    const totalUrl      = decodeURIComponent(params.get('spg_total_url') || '');
    const shippingAmt   = parseFloat(params.get('spg_shipping_amount') || 0);
    const totalAmt      = parseFloat(params.get('spg_total_amount')    || 0);
    const shippingGw    = params.get('spg_shipping_gw') || '';
    const totalGw       = params.get('spg_total_gw')    || '';

    let shippingPaid = false;
    let totalPaid    = false;
    let pollInterval = null;

    // ── Init ─────────────────────────────────────────────────────────────────
    $(document).ready(function () {
        if (!orderId || !sessionId) return;
        renderModal();
        startPolling();
    });

    // ── Modal rendering ───────────────────────────────────────────────────────
    function renderModal() {
        const html = `
            <div class="spg-overlay" id="spg-overlay"></div>
            <div class="spg-modal" id="spg-modal" role="dialog" aria-modal="true" aria-label="${escapeHtml(i18n.payTitle || 'Split Payment')}">
                <h2>${escapeHtml(i18n.payTitle || 'Complete Your Payment')}</h2>

                <table class="spg-summary" aria-label="Payment summary">
                    <tbody>
                        <tr class="spg-row-shipping">
                            <td class="spg-label">${escapeHtml(i18n.shippingLabel || 'Shipping')}</td>
                            <td class="spg-amount">${formatAmount(shippingAmt)}</td>
                            <td class="spg-gateway">${escapeHtml(shippingGw)}</td>
                        </tr>
                        <tr class="spg-row-total">
                            <td class="spg-label">${escapeHtml(i18n.totalLabel || 'Order Total')}</td>
                            <td class="spg-amount">${formatAmount(totalAmt)}</td>
                            <td class="spg-gateway">${escapeHtml(totalGw)}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="spg-status-section">
                    <div class="spg-status-row" id="spg-shipping-status-row">
                        <span class="spg-status-label">${escapeHtml(i18n.payShipping || 'Shipping Payment')}</span>
                        <span class="spg-indicator loading" id="spg-shipping-indicator" title="Pending">⏳</span>
                    </div>
                    <div class="spg-status-row" id="spg-total-status-row">
                        <span class="spg-status-label">${escapeHtml(i18n.payTotal || 'Order Payment')}</span>
                        <span class="spg-indicator loading" id="spg-total-indicator" title="Pending">⏳</span>
                    </div>
                </div>

                <div class="spg-buttons">
                    <button class="spg-btn spg-btn-shipping" id="spg-pay-shipping"
                            aria-label="${escapeHtml(i18n.payShipping || 'Pay Shipping')}">
                        ${escapeHtml(i18n.payShipping || 'Pay Shipping')} – ${formatAmount(shippingAmt)}
                    </button>
                    <button class="spg-btn spg-btn-total" id="spg-pay-total"
                            aria-label="${escapeHtml(i18n.payTotal || 'Pay Total')}">
                        ${escapeHtml(i18n.payTotal || 'Pay Order')} – ${formatAmount(totalAmt)}
                    </button>
                    <button class="spg-btn spg-btn-finalize" id="spg-finalize" disabled
                            aria-label="${escapeHtml(i18n.finalize || 'Finalize Order')}">
                        ${escapeHtml(i18n.finalize || 'Finalize Order')} 🔒
                    </button>
                </div>
            </div>
        `;

        $('body').append(html);
        bindModalEvents();
    }

    function bindModalEvents() {
        $('#spg-pay-shipping').on('click', function () {
            if (shippingUrl) openPaymentWindow(shippingUrl, 'shipping');
        });

        $('#spg-pay-total').on('click', function () {
            if (totalUrl) openPaymentWindow(totalUrl, 'total');
        });

        $('#spg-finalize').on('click', finalizeOrder);
    }

    // ── Payment windows ────────────────────────────────────────────────────────
    function openPaymentWindow(url, type) {
        const popup = window.open(url, `spg_${type}_payment`, 'width=900,height=700,scrollbars=yes');

        if (!popup) {
            // Fallback: navigate in same tab.
            window.location.href = url;
            return;
        }

        // Monitor popup close so we can trigger a poll immediately.
        const monitor = setInterval(function () {
            if (popup.closed) {
                clearInterval(monitor);
                pollStatus();
            }
        }, 500);
    }

    // ── Polling ────────────────────────────────────────────────────────────────
    function startPolling() {
        pollStatus(); // Immediate first check.
        pollInterval = setInterval(pollStatus, 3000);
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
            url:     restUrl + 'split-payment/validate',
            method:  'POST',
            headers: { 'X-WP-Nonce': nonce },
            data:    { order_id: orderId },
            success: function (response) {
                if (!response.success) return;
                const data = response.data;
                updateIndicator('shipping', data.shipping_paid);
                updateIndicator('total',    data.total_paid);

                if (data.is_complete) {
                    stopPolling();
                    enableFinalizeButton();
                }
            },
        });
    }

    // ── UI updates ─────────────────────────────────────────────────────────────
    function updateIndicator(type, paid) {
        const $indicator = $(`#spg-${type}-indicator`);
        if (paid) {
            $indicator
                .removeClass('loading error')
                .addClass('success')
                .text('✅')
                .attr('title', i18n.paid || 'Paid');
        }
    }

    function enableFinalizeButton() {
        const $btn = $('#spg-finalize');
        $btn.prop('disabled', false).text((i18n.finalize || 'Finalize Order') + ' ✅');
    }

    function finalizeOrder() {
        window.location.href = `${window.location.origin}/checkout/order-received/${orderId}/`;
    }

    // ── Utility ────────────────────────────────────────────────────────────────
    function formatAmount(amount) {
        return new Intl.NumberFormat(document.documentElement.lang || 'en', {
            style:    'currency',
            currency: currency || 'USD',
        }).format(amount);
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

}(jQuery));
