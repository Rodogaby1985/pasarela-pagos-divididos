# Split Payment Gateway for WooCommerce

**Pasarela de pagos agregadora multi-procesador con segregación fiscal de envíos y totales.**

A WordPress/WooCommerce plugin that acts as a payment bridge, allowing store owners to route the **shipping payment** and the **order-total payment** through independent, configurable gateways — enabling full fiscal segregation between the logistics operator and the merchant.

---

## Features

| Category | Details |
|---|---|
| **Split Payments** | Separate charges for shipping vs. order total, each routed to a different processor |
| **Unlimited Gateways** | MercadoPago, Nave, Stripe, PayPal out-of-the-box; add more via a simple adapter class |
| **Dynamic Routing** | Rule-based engine (priority, amount ranges, currency/country conditions) + per-client defaults |
| **Fiscal Segregation** | Each gateway stores its own fiscal entity name, tax ID, and address for audit trails |
| **Checkout Modal** | Responsive frontend modal with real-time ⏳ → ✅ → ❌ payment status indicators |
| **Admin Panel** | WooCommerce sub-menu for gateway configuration, split rules, dashboard and webhook logs |
| **REST API** | Endpoints for payment initiation, validation, webhooks, and fiscal reports |
| **Split Refunds** | Pro-rata refund splitting across both gateways |
| **Audit & Reconciliation** | Full transaction reconciliation table with fiscal document tracking |
| **Security** | HMAC-SHA256 webhook validation, AES-256-CBC credential encryption, nonce verification |

---

## Requirements

| Component | Minimum Version |
|---|---|
| PHP | 7.4 |
| WordPress | 5.0 |
| WooCommerce | 6.0 |
| MySQL / MariaDB | 5.7 / 10.3 |

---

## File Structure

```
split-payment-gateway-woocommerce/
├── split-payment-plugin.php          ← Main plugin entry point
├── uninstall.php
├── composer.json
├── package.json
├── webpack.config.js
├── .env.example
│
├── includes/
│   ├── class-split-payment-gateway.php     ← WooCommerce Payment Gateway class
│   ├── class-payment-routing-engine.php    ← Gateway routing logic
│   ├── class-split-distribution-engine.php ← Amount split calculations
│   ├── class-gateway-adapter-factory.php   ← Adapter registry & factory
│   ├── class-webhook-orchestrator.php      ← Unified webhook handler
│   ├── class-split-payment-service.php     ← High-level payment orchestration
│   │
│   ├── adapters/
│   │   ├── class-base-adapter.php
│   │   ├── class-mercadopago-adapter.php
│   │   ├── class-nave-adapter.php
│   │   ├── class-stripe-adapter.php
│   │   └── class-paypal-adapter.php
│   │
│   ├── api/
│   │   └── class-rest-api.php
│   │
│   ├── database/
│   │   ├── schema.sql
│   │   └── class-migrations.php
│   │
│   └── traits/
│       ├── trait-security.php
│       └── trait-logger.php
│
├── admin/
│   ├── class-admin-settings.php
│   ├── class-admin-dashboard.php
│   ├── templates/
│   │   ├── settings-page.php
│   │   └── dashboard-page.php
│   └── assets/
│       ├── js/admin-settings.js
│       └── css/admin-settings.css
│
├── assets/
│   ├── js/split-payment-modal.js
│   ├── css/split-payment-modal.css
│   └── templates/payment-modal.html
│
└── tests/
    ├── test-payment-routing.php
    └── test-split-distribution.php
```

---

## Installation

1. Copy (or clone) the plugin folder to `wp-content/plugins/split-payment-gateway-woocommerce/`.
2. Activate from **WordPress Admin → Plugins**.
3. The plugin will automatically create all required database tables on activation.
4. Navigate to **WooCommerce → Split Payment** to configure gateways and split rules.

---

## Configuration

### 1. Add a Gateway

Go to **WooCommerce → Split Payment → Payment Gateways** and click **Add Gateway**.

Fill in:
- **Gateway type** (MercadoPago, Nave, Stripe, PayPal, or a custom adapter)
- **API credentials** (stored AES-256 encrypted)
- **Fiscal entity** details (name, tax ID, address) for invoice/audit purposes
- **Default for Shipping** and/or **Default for Total**

### 2. Define Split Rules (optional)

Go to the **Split Rules** tab. Rules let you override which gateway is used based on conditions such as amount ranges, currency, or shipping country.

### 3. Enable the Gateway in WooCommerce

Go to **WooCommerce → Settings → Payments**, enable **Split Payment Gateway**, and set your **Client ID**.

---

## Payment Flow

```
Customer clicks Place Order
        ↓
WooCommerce calls process_payment()
        ↓
SPG_Split_Payment_Service::initiate()
  ├─ Routing Engine picks Shipping Gateway → initiates payment → gets redirect URL
  └─ Routing Engine picks Total Gateway   → initiates payment → gets redirect URL
        ↓
Customer is shown the SPG Modal
  ├─ "Pay Shipping" button → opens Shipping Gateway in popup
  └─ "Pay Total" button    → opens Total Gateway in popup
        ↓
Each gateway fires a webhook → SPG_Webhook_Orchestrator
  ├─ Validates signature
  ├─ Updates split_payments record
  └─ When BOTH are paid → calls WC_Order::payment_complete()
        ↓
Modal polls /spg/v1/split-payment/validate every 3 s
  └─ Both ✅ → "Finalize Order" button is enabled
        ↓
Customer clicks Finalize → redirected to order-received page
```

---

## REST API Endpoints

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/wp-json/spg/v1/split-payment/initiate` | Initiate a split payment for an order |
| `POST` | `/wp-json/spg/v1/split-payment/validate` | Poll payment status |
| `POST` | `/wp-json/spg/v1/webhooks/{gateway}` | Receive webhook notifications |
| `GET`  | `/wp-json/spg/v1/admin/fiscal-report/{client_id}` | Fiscal/audit report (admin only) |

---

## Adding a Custom Gateway

1. Create a class that extends `SPG_Base_Adapter` and implements `initiate()`, `get_status()`, `refund()`, `verify_webhook()`, and `parse_webhook()`.
2. Register it during the `spg_register_gateway_adapters` action:

```php
add_action('spg_register_gateway_adapters', function() {
    SPG_Gateway_Adapter_Factory::register('mygateway', 'My_Custom_Gateway_Adapter');
});
```

---

## Running Tests

```bash
composer install
./vendor/bin/phpunit tests/
```

---

## Security

- Webhook signatures are validated via HMAC-SHA256 before any processing.
- Gateway credentials are encrypted with AES-256-CBC before DB storage.
- All admin AJAX actions are protected by WordPress nonces and capability checks.
- Tokenisation is fully delegated to each gateway — card numbers are never handled by this plugin.

---

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).