# Changelog

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

### 📦 Changes
- Updated database schema version to 1.2.0
- DB migration for existing installations
- Simplified routing logic
- Removed percentage-based distribution

---

## [1.1.0] - Previous Release
See GitHub releases for details

## [1.0.0] - Initial Release
Initial version with MercadoPago and QR Transfer support
