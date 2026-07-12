# Changelog

All notable changes to the Split Payment Gateway for WooCommerce will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0] - 2026-07-12

### 🚀 Major Refactor: Pre-Order Full-Page Payment Architecture

#### Breaking Changes
- **Payment flow moved from post-order modal to pre-order full-page interface**
  - Split payment now occurs **BEFORE** WooCommerce order creation
  - Customer no longer sees "order not valid" errors
  - Eliminates permission validation issues with fresh orders
  - **Action Required**: Test existing checkout workflows; notify users of improved UX

- **Modal overlay removed** in favor of full-page responsive design
  - No more off-screen positioning issues
  - Better mobile experience
  - New URL structure: `/spg-payment-page/`

- **Gateway process_payment() behavior changed**
  - Now redirects to `/spg-payment-page/` instead of returning redirect result
  - Order is created ONLY after both payment sections are confirmed
  - Session-based payment state management

#### Added
- **Full-page payment interface** (`includes/templates/split-payment-page.php`)
  - Responsive design for desktop, tablet, and mobile
  - Server-side QR code generation (no JavaScript QR library required)
  - Real-time payment status polling
  - Clear visual indicators: ⏳ pending → ✅ paid → ❌ failed
  - Separate sections for Subtotal and Shipping

- **New REST API endpoints**
  - `POST /spg/v1/checkout/split-payment` - Initiates split payment session without creating order
  - `POST /spg/v1/checkout/confirm-order` - Creates WooCommerce order after payment confirmation
  - Both endpoints properly handle server-side QR generation

- **Server-side QR code generation**
  - QR codes generated on PHP backend
  - SVG embedded directly in HTML response
  - No client-side QR library dependencies
  - Reliable across all browsers and devices

- **Atomic database operations**
  - `INSERT … ON DUPLICATE KEY UPDATE` for split_payments table
  - Prevents duplicate key errors under concurrent requests
  - Safe against race conditions between `process_payment()` and REST API calls

- **Gateway filtering improvements**
  - Payment methods filtered from `wp_spg_client_gateways WHERE is_active = 1`
  - Only configured gateways appear in UI
  - QR Transfer included only when aliases are configured
  - Uses parameterized SQL queries (`wpdb->prepare()`)

#### Fixed
- ✅ Duplicate key errors on `spg_split_payments` INSERT (v1.3.0 issue)
- ✅ Unconfigured payment methods displaying in UI (v1.3.0 issue)
- ✅ "Order not valid" errors on fresh order checkout
- ✅ Modal appearing off-screen on mobile devices
- ✅ QR codes not rendering in client-side modal
- ✅ Thank-you page redirect failing after payment

#### Changed
- `SPG_Split_Payment_Gateway::process_payment()` now returns redirect to `/spg-payment-page/` instead of order-pay page
- `SPG_Split_Payment_Service::initiate()` now uses atomic INSERT … ON DUPLICATE KEY UPDATE
- Database query for available methods now uses `wpdb->prepare()` for security
- Order creation deferred until both payments confirmed in new `SPG_Rest_Api::confirm_order()` endpoint
- All assets enqueued conditionally: legacy modal CSS/JS for backward compatibility, new page assets for `/spg-payment-page/`

#### Deprecated
- Legacy modal-based checkout flow (still functional for backward compatibility in v2.0)
- `SPG_Rest_Api::initiate_payment()` endpoint behavior changed; consider migrating to new flow

#### Removed
- Client-side QR code generation from modal (replaced with server-side SVG)
- Modal overlay positioning logic (full-page responsive design)

#### Migration Guide for Store Owners
1. **Update the plugin** to v2.0.0
2. **Activate the plugin** - Database migrations run automatically
3. **Test a sample checkout**:
   - Add item to cart
   - Go to checkout
   - Select Split Payment Gateway
   - Click "Place Order" → redirects to `/spg-payment-page/`
   - Select payment methods
   - Complete payments
   - Verify redirect to thank-you page
4. **If issues occur**: Check WordPress error logs in `wp-content/debug.log` (enable `WP_DEBUG` if not set)

#### Migration Guide for Developers
- If you extended the modal UI, refactor to use new page template: `includes/templates/split-payment-page.php`
- If you customized `process_payment()` redirect behavior, update to redirect to `/spg-payment-page/` with session ID
- If you relied on `initiate_payment` REST endpoint, migrate to new `split-payment` and `confirm-order` endpoints
- Server-side QR generation now handles all QR needs; remove any client-side QR rendering code

#### Technical Details
- Uses `INSERT … ON DUPLICATE KEY UPDATE` to prevent race conditions
- Session-based state (no premature order creation)
- Parameterized SQL queries throughout
- WooCommerce HPOS compatible
- Full backward compatibility with v1.3.0 settings and gateways

---

## [1.2.0] - 2026-07-09

### 🎨 New Features
- **Modern Admin UI**: Complete redesign with WooCommerce 2024 standards
  - Card components with modern styling
  - Toggle switches with smooth animations
  - Responsive dropdown selects
  - Professional color palette (WooCommerce official blue)

- **Simplified Split Rules**: Removed confusing percentage system
  - Simple method selection: QR Transfer vs MercadoPago
  - For Shipping: choose payment method
  - For Subtotal: choose payment method
  - Much more intuitive configuration

- **Responsive Design**: Works perfectly on all devices
  - Desktop: 3-column layout
  - Tablet: 2-column layout
  - Mobile: Single column, optimized touch targets

- **Improved Accessibility**: WCAG AA compliant
  - Proper ARIA labels on all inputs
  - Keyboard navigation support
  - Focus states clearly visible
  - Screen reader friendly

### 🔧 Improvements
- Modern CSS with variables and utilities
- Smooth transitions and animations
- Better error handling and feedback
- Reusable component system
- Optimized performance
- Better loading states
- Toast notifications for user feedback

### 🐛 Bug Fixes
- PHP CodeSniffer formatting issues
- PHP 7.4 compatibility in tests
- Proper escaping of all output

### 📊 Quality Metrics
- ✅ 41 tests passing
- ✅ 0 warnings or errors
- ✅ Full WordPress/WooCommerce compliance
- ✅ Compatible with WordPress 6.0+
- ✅ Compatible with WooCommerce 8.0+
- ✅ PHP 7.4 - 8.2 compatible

---

## [1.1.0] - Previous Release
See GitHub releases for details

## [1.0.0] - Initial Release
Initial version with MercadoPago and QR Transfer support

---

## Support
- 📖 [Documentation](https://github.com/Rodogaby1985/pasarela-pagos-divididos/wiki)
- 🐛 [Report Issues](https://github.com/Rodogaby1985/pasarela-pagos-divididos/issues)
- 💬 [Discussions](https://github.com/Rodogaby1985/pasarela-pagos-divididos/discussions)
