#!/bin/bash
# =============================================================================
# build.sh — Split Payment Gateway build & package script
# =============================================================================
# Usage:
#   ./build.sh            — build and create the distributable ZIP
#   ./build.sh --clean    — remove previous build artefacts only
# =============================================================================

set -e

PLUGIN_SLUG="split-payment-plugin"
ZIP_NAME="${PLUGIN_SLUG}-READY.zip"
TMP_DIR="/tmp/${PLUGIN_SLUG}-build"
REPO_DIR="$(cd "$(dirname "$0")" && pwd)"

# ── helpers ───────────────────────────────────────────────────────────────────
info()    { echo "ℹ️  $*"; }
success() { echo "✅  $*"; }
warn()    { echo "⚠️  $*"; }
fail()    { echo "❌  $*" >&2; exit 1; }

# ── clean ─────────────────────────────────────────────────────────────────────
info "Cleaning previous build artefacts..."
rm -f  "${REPO_DIR}/${ZIP_NAME}"
rm -f  "${REPO_DIR}/${ZIP_NAME}.sha256"
rm -rf "${TMP_DIR}"

if [ "$1" = "--clean" ]; then
    success "Clean complete."
    exit 0
fi

# ── verify required tools ─────────────────────────────────────────────────────
command -v composer >/dev/null 2>&1 || fail "composer not found. Install from https://getcomposer.org"
command -v node     >/dev/null 2>&1 || fail "node not found. Install from https://nodejs.org"
command -v npm      >/dev/null 2>&1 || fail "npm not found. Install from https://nodejs.org"
command -v zip      >/dev/null 2>&1 || fail "zip not found. Install via: sudo apt-get install zip"

# ── install PHP dependencies ──────────────────────────────────────────────────
info "Installing PHP dependencies (composer)..."
cd "${REPO_DIR}"
composer install --no-dev --optimize-autoloader --no-progress

# ── install & compile JS/CSS assets ──────────────────────────────────────────
info "Installing Node.js dependencies (npm)..."
npm install --silent

info "Compiling assets (webpack)..."
npm run build

# ── copy to temp staging area ─────────────────────────────────────────────────
info "Staging files for packaging..."
mkdir -p "${TMP_DIR}/${PLUGIN_SLUG}"

rsync -a --quiet \
    --exclude='.git/' \
    --exclude='.github/' \
    --exclude='node_modules/' \
    --exclude='tests/' \
    --exclude='.env*' \
    --exclude='*.md' \
    --exclude='webpack.config.js' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='.gitignore' \
    --exclude='build.sh' \
    --exclude='build.bat' \
    --exclude='*.zip' \
    --exclude='*.sha256' \
    --exclude='phpunit.xml*' \
    --exclude='.phpunit*' \
    "${REPO_DIR}/" \
    "${TMP_DIR}/${PLUGIN_SLUG}/"

# ── create ZIP ────────────────────────────────────────────────────────────────
info "Creating distributable ZIP..."
cd "${TMP_DIR}"
zip -r -q "${ZIP_NAME}" "${PLUGIN_SLUG}/"

# ── move to repo root ─────────────────────────────────────────────────────────
mv "${TMP_DIR}/${ZIP_NAME}" "${REPO_DIR}/${ZIP_NAME}"

# ── generate checksum ─────────────────────────────────────────────────────────
info "Generating SHA-256 checksum..."
cd "${REPO_DIR}"
sha256sum "${ZIP_NAME}" > "${ZIP_NAME}.sha256"

# ── cleanup temp ─────────────────────────────────────────────────────────────
rm -rf "${TMP_DIR}"

# ── summary ───────────────────────────────────────────────────────────────────
success "Build complete!"
echo ""
echo "  📦  File  : ${ZIP_NAME}"
echo "  📊  Size  : $(du -h "${ZIP_NAME}" | cut -f1)"
echo "  🔐  SHA256: $(awk '{print $1}' "${ZIP_NAME}.sha256")"
echo ""
echo "Upload ${ZIP_NAME} to WordPress Admin → Plugins → Upload Plugin"
