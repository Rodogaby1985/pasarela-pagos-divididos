/* jshint esversion: 6 */
/**
 * Split Payment Full-Page Frontend Script
 *
 * Powers the /spg-payment-page/ full-page payment interface.
 * Unlike the old modal approach, this runs outside of WooCommerce's checkout
 * context and does not require a logged-in user or WC order-pay validation.
 *
 * Flow:
 *   1. Customer selects payment methods for Subtotal and Shipping.
 *   2. "Start Payment" calls POST /spg/v1/payment-session/initiate.
 *   3. Response includes QR images (server-generated) or gateway URLs.
 *   4. Polling calls GET /spg/v1/payment-session/status every 2 s.
 *   5. When both sections are paid the "Finalize Order" button is enabled.
 *   6. "Finalize Order" calls POST /spg/v1/payment-session/complete.
 *   7. Browser is redirected to the WooCommerce thank-you page.
 *
 * @package SplitPaymentGateway
 */
(function () {
    'use strict';

    // ── Config from PHP ──────────────────────────────────────────────────────
    var cfg = window.spgPageData || {};

    var restUrl        = cfg.restUrl        || '';
    var nonce          = cfg.nonce          || '';
    var sessionId      = cfg.sessionId      || '';
    var orderId        = cfg.orderId        || '';
    var shippingAmt    = parseFloat( cfg.shippingAmount || 0 );
    var totalAmt       = parseFloat( cfg.totalAmount    || 0 );
    var currencySymbol = cfg.currencySymbol || '$';
    var i18n           = cfg.i18n           || {};
    var qrExpiry       = parseInt( cfg.qrExpirySeconds || 900, 10 );

    // ── Constants ────────────────────────────────────────────────────────────
    var POLL_INTERVAL_MS        = 2500; // How often (ms) to poll payment status.
    var COPY_FEEDBACK_DURATION_MS = 1500; // How long (ms) to show "Copied!" feedback.

    // ── State ────────────────────────────────────────────────────────────────
    var shippingPaid    = false;
    var totalPaid       = false;
    var pollTimer       = null;
    var qrCountdowns    = {};      // section → { timerId, expiresAt }
    var paymentData     = null;    // response from initiate endpoint
    var selectedMethods = { shipping: '', total: '' };

    // ── Helpers ──────────────────────────────────────────────────────────────

    function fmt( amount ) {
        return currencySymbol + parseFloat( amount ).toFixed( 2 );
    }

    function escHtml( s ) {
        if ( ! s ) return '';
        return String( s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    function el( id ) {
        return document.getElementById( id );
    }

    function show( id ) {
        var e = el( id );
        if ( e ) e.style.display = '';
    }

    function hide( id ) {
        var e = el( id );
        if ( e ) e.style.display = 'none';
    }

    function setText( id, text ) {
        var e = el( id );
        if ( e ) e.textContent = text;
    }

    function setHtml( id, html ) {
        var e = el( id );
        if ( e ) e.innerHTML = html;
    }

    function showNotice( msg, type ) {
        var notice = el( 'spg-page-notice' );
        if ( ! notice ) return;
        notice.className = 'spg-notice is-' + ( type || 'error' );
        notice.textContent = msg;
        notice.style.display = '';
        notice.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
    }

    function clearNotice() {
        var notice = el( 'spg-page-notice' );
        if ( notice ) {
            notice.style.display = 'none';
            notice.textContent   = '';
        }
    }

    // ── Fetch helpers ────────────────────────────────────────────────────────

    function apiFetch( method, endpoint, data ) {
        var url  = restUrl + endpoint;
        var opts = {
            method:  method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   nonce,
            },
        };
        if ( data && ( method === 'POST' || method === 'PUT' ) ) {
            opts.body = JSON.stringify( data );
        }
        if ( data && method === 'GET' ) {
            var qs = Object.keys( data ).map( function ( k ) {
                return encodeURIComponent( k ) + '=' + encodeURIComponent( data[ k ] );
            } ).join( '&' );
            url += '?' + qs;
        }
        return fetch( url, opts ).then( function ( resp ) {
            return resp.json();
        } );
    }

    // ── Init ─────────────────────────────────────────────────────────────────

    document.addEventListener( 'DOMContentLoaded', function () {
        if ( ! sessionId ) {
            showNotice( 'Missing payment session. Please return to checkout.' );
            return;
        }

        // Fill in amounts.
        setText( 'spg-pay-total-amount',    fmt( totalAmt ) );
        setText( 'spg-pay-shipping-amount', fmt( shippingAmt ) );

        // Wire radio buttons → enable/disable Start Payment.
        wireRadioButtons();
    } );

    // ── Method selection ─────────────────────────────────────────────────────

    function wireRadioButtons() {
        var radios = document.querySelectorAll( '.spg-method-radio' );
        radios.forEach( function ( radio ) {
            radio.addEventListener( 'change', onMethodChange );
        } );
    }

    function onMethodChange() {
        var totalSel    = document.querySelector( 'input[name="spg-total-method"]:checked' );
        var shippingSel = document.querySelector( 'input[name="spg-shipping-method"]:checked' );
        selectedMethods.total    = totalSel    ? totalSel.value    : '';
        selectedMethods.shipping = shippingSel ? shippingSel.value : '';

        var btn = el( 'spg-start-payment' );
        if ( btn ) {
            btn.disabled = ! ( selectedMethods.total && selectedMethods.shipping );
        }
    }

    // Attach start button.
    document.addEventListener( 'DOMContentLoaded', function () {
        var startBtn = el( 'spg-start-payment' );
        if ( startBtn ) {
            startBtn.addEventListener( 'click', onStartPayment );
        }
        var finalizeBtn = el( 'spg-finalize' );
        if ( finalizeBtn ) {
            finalizeBtn.addEventListener( 'click', onFinalizeOrder );
        }
    } );

    // ── Start payment ─────────────────────────────────────────────────────────

    function onStartPayment() {
        var startBtn = el( 'spg-start-payment' );
        if ( startBtn ) {
            startBtn.disabled  = true;
            startBtn.innerHTML = '<span class="spg-spinner"></span>' + escHtml( i18n.processing || 'Processing…' );
        }
        clearNotice();

        apiFetch( 'POST', 'payment-session/initiate', {
            session_id:      sessionId,
            shipping_method: selectedMethods.shipping,
            total_method:    selectedMethods.total,
        } ).then( function ( resp ) {
            if ( ! resp || ! resp.success ) {
                showNotice( ( resp && resp.message ) || i18n.errorInitiate || 'Could not start payment.', 'error' );
                if ( startBtn ) {
                    startBtn.disabled  = false;
                    startBtn.textContent = i18n.startPayment || 'Start Payment';
                }
                return;
            }

            paymentData = resp.data;

            hide( 'spg-step-select' );
            show( 'spg-step-pay' );

            renderPaySection( 'total',    paymentData );
            renderPaySection( 'shipping', paymentData );

            startPolling();

        } ).catch( function () {
            showNotice( i18n.errorInitiate || 'Could not start payment.', 'error' );
            if ( startBtn ) {
                startBtn.disabled  = false;
                startBtn.textContent = i18n.startPayment || 'Start Payment';
            }
        } );
    }

    // ── Render payment section ────────────────────────────────────────────────

    function renderPaySection( section, data ) {
        // section is 'total' or 'shipping'
        var methodType = ( section === 'total' ) ? data.total_method_type    : data.shipping_method_type;
        var qrData     = ( section === 'total' ) ? data.total_qr_data        : data.shipping_qr_data;
        var qrImage    = ( section === 'total' ) ? data.total_qr_image       : data.shipping_qr_image;
        var payUrl     = ( section === 'total' ) ? data.total_payment_url    : data.shipping_payment_url;
        var expiresAt  = ( section === 'total' ) ? data.total_expires_at     : data.shipping_expires_at;
        var amount     = ( section === 'total' ) ? data.total_amount         : data.shipping_amount;

        // If amount came from data, update display.
        if ( amount ) {
            setText( 'spg-pay-' + section + '-amount', fmt( amount ) );
        }

        var bodyEl = el( 'spg-pay-' + section + '-body' );
        if ( ! bodyEl ) return;

        if ( methodType === 'qr_transfer' ) {
            bodyEl.innerHTML = buildQrHtml( section, qrData, qrImage );
            startQrCountdown( section, expiresAt );
            wireQrRefresh( section );
            wireCopyButtons( bodyEl );
        } else {
            bodyEl.innerHTML = buildGatewayHtml( section, payUrl );
            wireGatewayButton( section, payUrl );
        }
    }

    function buildQrHtml( section, qrData, qrImage ) {
        var html = '<div class="spg-qr-container">';

        // QR image.
        if ( qrImage ) {
            html += '<div class="spg-qr-image">';
            html += '<img src="' + escHtml( qrImage ) + '" alt="QR Code" width="200" height="200">';
            html += '</div>';
        }

        // Transfer details.
        if ( qrData ) {
            html += '<div class="spg-qr-detail">';
            if ( qrData.alias ) {
                html += '<div class="spg-qr-detail-row">';
                html +=   '<span class="label">' + escHtml( i18n.qrAlias || 'Alias:' ) + '</span>';
                html +=   '<span class="value" id="spg-' + section + '-qr-alias">' + escHtml( qrData.alias ) + '</span>';
                html +=   '<button class="spg-copy-btn" data-copy-from="spg-' + section + '-qr-alias">📋</button>';
                html += '</div>';
            }
            if ( qrData.amount ) {
                html += '<div class="spg-qr-detail-row">';
                html +=   '<span class="label">Monto:</span>';
                html +=   '<span class="value">' + escHtml( currencySymbol + qrData.amount ) + '</span>';
                html += '</div>';
            }
            if ( qrData.concept ) {
                html += '<div class="spg-qr-detail-row">';
                html +=   '<span class="label">Concepto:</span>';
                html +=   '<span class="value">' + escHtml( qrData.concept ) + '</span>';
                html += '</div>';
            }
            html += '</div>';
        }

        // Timer.
        html += '<div class="spg-qr-timer" id="spg-' + section + '-qr-timer">';
        html +=   escHtml( i18n.qrExpires || 'Expires in' ) + ' <span class="countdown" id="spg-' + section + '-countdown">--:--</span>';
        html += '</div>';

        html += '<button class="spg-btn spg-btn-secondary spg-qr-refresh-btn" id="spg-' + section + '-qr-refresh" style="display:none;">';
        html +=   '🔄 ' + escHtml( i18n.qrRefresh || 'Refresh QR' );
        html += '</button>';

        html += '</div>';
        return html;
    }

    function buildGatewayHtml( section, payUrl ) {
        var html = '';
        if ( payUrl ) {
            html += '<a href="' + escHtml( payUrl ) + '" target="_blank" class="spg-btn spg-btn-primary spg-gateway-pay-btn" id="spg-' + section + '-gateway-btn">';
            html +=   '💳 ' + escHtml( i18n.payWithGateway || 'Pay with card / wallet' );
            html += '</a>';
        } else {
            html += '<p>' + escHtml( i18n.errorInitiate || 'Payment URL not available.' ) + '</p>';
        }
        return html;
    }

    // ── QR Countdown ─────────────────────────────────────────────────────────

    function startQrCountdown( section, expiresAt ) {
        if ( qrCountdowns[ section ] ) {
            clearInterval( qrCountdowns[ section ].timerId );
        }
        if ( ! expiresAt ) {
            expiresAt = Math.floor( Date.now() / 1000 ) + qrExpiry;
        }
        qrCountdowns[ section ] = { timerId: null, expiresAt: parseInt( expiresAt, 10 ) };

        function tick() {
            var now     = Math.floor( Date.now() / 1000 );
            var secs    = qrCountdowns[ section ].expiresAt - now;
            var cntEl   = el( 'spg-' + section + '-countdown' );
            var refBtn  = el( 'spg-' + section + '-qr-refresh' );

            if ( secs <= 0 ) {
                clearInterval( qrCountdowns[ section ].timerId );
                if ( cntEl ) cntEl.textContent = '00:00';
                if ( refBtn ) refBtn.style.display = '';
                showQrExpired( section );
                return;
            }

            var mm = String( Math.floor( secs / 60 ) ).padStart( 2, '0' );
            var ss = String( secs % 60 ).padStart( 2, '0' );
            if ( cntEl ) cntEl.textContent = mm + ':' + ss;
        }

        tick();
        qrCountdowns[ section ].timerId = setInterval( tick, 1000 );
    }

    function showQrExpired( section ) {
        var timerEl = el( 'spg-' + section + '-qr-timer' );
        if ( timerEl ) {
            timerEl.textContent = i18n.qrExpired || 'QR expired. Click refresh to get a new one.';
            timerEl.style.color = '#dc2626';
        }
    }

    // ── QR refresh ───────────────────────────────────────────────────────────

    function wireQrRefresh( section ) {
        // Delegated: button is rendered after wireQrRefresh is called.
        var body = el( 'spg-pay-' + section + '-body' );
        if ( ! body ) return;
        body.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '#spg-' + section + '-qr-refresh' );
            if ( ! btn ) return;
            btn.disabled   = true;
            btn.textContent = '…';

            apiFetch( 'POST', 'qr/generate', {
                order_id: orderId,
                section:  section,
            } ).then( function ( resp ) {
                if ( resp && resp.success ) {
                    // Re-render with updated QR data.
                    var qrImage = null;
                    if ( paymentData ) {
                        // Trigger a fresh render with the refreshed QR image.
                        if ( section === 'total' ) {
                            paymentData.total_qr_data    = resp.qr_data;
                            paymentData.total_qr_image   = resp.qr_image || null;
                            paymentData.total_expires_at = resp.expires_at;
                        } else {
                            paymentData.shipping_qr_data    = resp.qr_data;
                            paymentData.shipping_qr_image   = resp.qr_image || null;
                            paymentData.shipping_expires_at = resp.expires_at;
                        }
                        renderPaySection( section, paymentData );
                    }
                } else {
                    btn.disabled    = false;
                    btn.textContent = '🔄 ' + ( i18n.qrRefresh || 'Refresh QR' );
                }
            } ).catch( function () {
                btn.disabled    = false;
                btn.textContent = '🔄 ' + ( i18n.qrRefresh || 'Refresh QR' );
            } );
        } );
    }

    // ── Copy buttons ─────────────────────────────────────────────────────────

    function wireCopyButtons( container ) {
        container.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '.spg-copy-btn[data-copy-from]' );
            if ( ! btn ) return;
            var srcEl = el( btn.getAttribute( 'data-copy-from' ) );
            if ( srcEl ) {
                navigator.clipboard.writeText( srcEl.textContent.trim() ).then( function () {
                    var orig = btn.textContent;
                    btn.textContent = i18n.copied || 'Copied!';
                    setTimeout( function () { btn.textContent = orig; }, COPY_FEEDBACK_DURATION_MS );
                } );
            }
        } );
    }

    // ── Gateway button ────────────────────────────────────────────────────────

    function wireGatewayButton( section ) {
        // The gateway button is an <a> tag – mark section as "processing" on click.
        var body = el( 'spg-pay-' + section + '-body' );
        if ( ! body ) return;
        body.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '#spg-' + section + '-gateway-btn' );
            if ( ! btn ) return;
            setStatusText( section, i18n.processing || 'Processing…' );
        } );
    }

    // ── Polling ───────────────────────────────────────────────────────────────

    function startPolling() {
        if ( pollTimer ) clearInterval( pollTimer );
        pollTimer = setInterval( pollStatus, POLL_INTERVAL_MS );
    }

    function stopPolling() {
        if ( pollTimer ) {
            clearInterval( pollTimer );
            pollTimer = null;
        }
    }

    function pollStatus() {
        apiFetch( 'GET', 'payment-session/status', { session_id: sessionId } )
            .then( function ( resp ) {
                if ( ! resp || ! resp.success ) return;
                var d = resp.data || {};

                if ( d.shipping_paid && ! shippingPaid ) {
                    shippingPaid = true;
                    markSectionPaid( 'shipping' );
                }
                if ( d.total_paid && ! totalPaid ) {
                    totalPaid = true;
                    markSectionPaid( 'total' );
                }

                if ( d.is_complete || ( shippingPaid && totalPaid ) ) {
                    stopPolling();
                    onBothPaid();
                }
            } )
            .catch( function () {
                // Network error – keep polling.
            } );
    }

    function markSectionPaid( section ) {
        // Update badge.
        var badge = el( 'spg-' + section + '-status-badge' );
        if ( badge ) badge.textContent = '✅';

        // Update status text.
        var statusText = el( 'spg-' + section + '-status-text' );
        if ( statusText ) {
            statusText.textContent = i18n.statusPaid || '✅ Paid';
            statusText.classList.add( 'is-paid' );
        }

        // Stop countdown for this section.
        if ( qrCountdowns[ section ] && qrCountdowns[ section ].timerId ) {
            clearInterval( qrCountdowns[ section ].timerId );
        }
    }

    function setStatusText( section, text ) {
        var el = document.getElementById( 'spg-' + section + '-status-text' );
        if ( el ) el.textContent = text;
    }

    // ── Both payments complete ────────────────────────────────────────────────

    function onBothPaid() {
        show( 'spg-both-paid-notice' );
        var btn = el( 'spg-finalize' );
        if ( btn ) btn.disabled = false;
    }

    // ── Finalize order ────────────────────────────────────────────────────────

    function onFinalizeOrder() {
        var btn = el( 'spg-finalize' );
        if ( btn ) {
            btn.disabled  = true;
            btn.innerHTML = '<span class="spg-spinner"></span>' + escHtml( i18n.processing || 'Processing…' );
        }
        clearNotice();

        apiFetch( 'POST', 'payment-session/complete', { session_id: sessionId } )
            .then( function ( resp ) {
                if ( resp && resp.success && resp.redirect ) {
                    window.location.href = resp.redirect;
                } else {
                    showNotice( ( resp && resp.message ) || i18n.errorComplete || 'Could not finalize order.', 'error' );
                    if ( btn ) {
                        btn.disabled  = false;
                        btn.textContent = '🔒 ' + ( i18n.finalizeOrder || 'Finalize Order' );
                    }
                }
            } )
            .catch( function () {
                showNotice( i18n.errorComplete || 'Could not finalize order. Please contact support.', 'error' );
                if ( btn ) {
                    btn.disabled  = false;
                    btn.textContent = '🔒 ' + ( i18n.finalizeOrder || 'Finalize Order' );
                }
            } );
    }

}() );
