# Installation Guide

## Option 1: Pre-compiled Package (Recommended)

**No Composer, Node.js, npm, or command-line access required.**

### Step 1: Download

1. Go to **https://github.com/Rodogaby1985/pasarela-pagos-divididos/releases**
2. Open the latest release
3. Download `split-payment-plugin-READY.zip`

### Step 2: Verify Integrity (Optional)

Download `split-payment-plugin-READY.zip.sha256` and verify the file was not corrupted in transit:

```bash
# Linux / macOS
sha256sum -c split-payment-plugin-READY.zip.sha256

# Windows PowerShell
(Get-FileHash split-payment-plugin-READY.zip -Algorithm SHA256).Hash
```

### Step 3: Install in WordPress

1. Log in to **WordPress Admin**
2. Navigate to **Plugins → Add New**
3. Click **Upload Plugin** (top of the page)
4. Click **Choose File** and select `split-payment-plugin-READY.zip`
5. Click **Install Now**
6. Wait for the installation to complete

### Step 4: Activate

1. Click **Activate Plugin**
2. The plugin automatically creates all required database tables

### Step 5: Configure

1. Go to **WooCommerce → Split Payment → Gateways**
2. Add and configure your payment gateways (MercadoPago, QR Transfer, etc.)
3. Save changes

### Step 6: Enable in WooCommerce

1. Go to **WooCommerce → Settings → Payments**
2. Enable **Split Payment Gateway**
3. Set your **Client ID**
4. Save changes

---

## Option 2: From Source (For Developers)

### Prerequisites

- PHP 7.4+
- Composer
- Node.js 16+
- npm

### Steps

```bash
# Clone the repository
git clone https://github.com/Rodogaby1985/pasarela-pagos-divididos.git
cd pasarela-pagos-divididos

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install & build frontend assets
npm install
npm run build

# Copy plugin folder to WordPress
cp -r . /path/to/wp-content/plugins/split-payment-gateway-woocommerce/
```

Alternatively, run the build script to generate a ready-to-install ZIP:

```bash
# Linux / macOS
chmod +x build.sh
./build.sh

# Windows
build.bat
```

Then upload `split-payment-plugin-READY.zip` via WordPress Admin → Plugins → Upload Plugin.

---

## Configuring MercadoPago

See [MERCADOPAGO-SETUP.md](MERCADOPAGO-SETUP.md) for detailed setup instructions.

**Quick steps:**

1. Go to **https://www.mercadopago.com.ar/developers/panel**
2. Get your **Access Token** (starts with `APP_USR-`) and **User ID**
3. In WordPress: **WooCommerce → Split Payment → Gateways → MercadoPago**
4. Enter your credentials and click **Create Webhook** to register the webhook automatically

---

## Configuring QR Transfer

See [QR-TRANSFER-SETUP.md](QR-TRANSFER-SETUP.md) for full instructions.

**Quick steps:**

1. Get the CBU/CVU/Alias for your store bank account (contact your bank)
2. Get the CBU/CVU/Alias for your logistics operator (optional)
3. In WordPress: **WooCommerce → Split Payment → QR Transfer**
4. Enter the aliases and a webhook secret
5. Configure your bank/aggregator to POST confirmations to:
   `https://yoursite.com/wp-json/spg/v1/webhooks/qr-transfer`

---

## Troubleshooting

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common issues and solutions.

### Quick fixes

| Problem | Solution |
|---------|----------|
| "File exceeds upload limit" | Increase `upload_max_filesize` in `wp-config.php` or ask your host |
| "Class not found" on activation | Re-download and verify the ZIP checksum |
| Missing "Split Payment" menu in WooCommerce | Ensure WooCommerce is installed and active |
| QR not displaying as image | Check browser console; CDN `qrcode-generator` script must load |
| MercadoPago webhook not created | Verify your Access Token has write permissions |

---

## Requirements

| Component | Minimum |
|---|---|
| WordPress | 5.0 |
| WooCommerce | 6.0 |
| PHP | 7.4 |
| MySQL / MariaDB | 5.7 / 10.3 |
