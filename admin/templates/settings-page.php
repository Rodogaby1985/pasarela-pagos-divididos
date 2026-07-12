<?php
/**
 * Admin Settings page template.
 *
 * Variables available:
 *   $client_id (string)
 *   $gateways  (array)
 *   $rules     (array)
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap spg-admin-wrap">
	<h1><?php esc_html_e( 'Split Payment Gateway – Settings', 'split-payment-gateway' ); ?></h1>

	<!-- Tabs -->
	<nav class="nav-tab-wrapper spg-tabs" id="spg-settings-tabs">
		<a href="#tab-gateways" class="nav-tab nav-tab-active"><?php esc_html_e( 'Payment Gateways', 'split-payment-gateway' ); ?></a>
		<a href="#tab-qr" class="nav-tab"><?php esc_html_e( 'QR Transfer', 'split-payment-gateway' ); ?></a>
		<a href="#tab-rules" class="nav-tab"><?php esc_html_e( 'Split Rules', 'split-payment-gateway' ); ?></a>
	</nav>

	<!-- Tab: Gateways -->
	<div id="tab-gateways" class="spg-tab-content">
		<h2><?php esc_html_e( 'Configured Gateways', 'split-payment-gateway' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Add the payment gateways your store uses. API credentials are encrypted at rest.', 'split-payment-gateway' ); ?>
		</p>

		<table class="wp-list-table widefat fixed striped" id="spg-gateways-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Gateway', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Display Name', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Default Shipping', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Default Total', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Fiscal Entity', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Status', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'split-payment-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $gateways ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No gateways configured yet.', 'split-payment-gateway' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $gateways as $gw ) : ?>
						<tr data-id="<?php echo esc_attr( $gw['id'] ); ?>">
							<td><?php echo esc_html( $gw['gateway_name'] ); ?></td>
							<td><?php echo esc_html( $gw['display_name'] ); ?></td>
							<td><?php echo $gw['is_default_shipping'] ? '✅' : '—'; ?></td>
							<td><?php echo $gw['is_default_total'] ? '✅' : '—'; ?></td>
							<td><?php echo esc_html( $gw['fiscal_entity_name'] ); ?></td>
							<td><?php echo $gw['is_active'] ? esc_html__( 'Active', 'split-payment-gateway' ) : esc_html__( 'Inactive', 'split-payment-gateway' ); ?></td>
							<td>
								<button class="button spg-edit-gateway" data-id="<?php echo esc_attr( $gw['id'] ); ?>">
									<?php esc_html_e( 'Edit', 'split-payment-gateway' ); ?>
								</button>
								<button class="button spg-delete-gateway" data-id="<?php echo esc_attr( $gw['id'] ); ?>">
									<?php esc_html_e( 'Delete', 'split-payment-gateway' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<p>
			<button class="button button-primary" id="spg-add-gateway">
				<?php esc_html_e( 'Add Gateway', 'split-payment-gateway' ); ?>
			</button>
		</p>

		<!-- Add/Edit Gateway Form (hidden by default) -->
		<div id="spg-gateway-form" class="spg-form-panel" style="display:none;">
			<h3><?php esc_html_e( 'Gateway Details', 'split-payment-gateway' ); ?></h3>
			<input type="hidden" id="spg-gateway-id" value="">
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>">

			<table class="form-table">
				<tr>
					<th><label for="spg-gw-name"><?php esc_html_e( 'Gateway', 'split-payment-gateway' ); ?></label></th>
					<td>
						<select id="spg-gw-name" name="gateway_name">
							<option value=""><?php esc_html_e( '— Select —', 'split-payment-gateway' ); ?></option>
							<option value="mercadopago">MercadoPago</option>
							<option value="nave">Nave</option>
							<option value="stripe">Stripe</option>
							<option value="paypal">PayPal</option>
							<option value="qr_transfer"><?php esc_html_e( 'QR Transfer', 'split-payment-gateway' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="spg-gw-display"><?php esc_html_e( 'Display Name', 'split-payment-gateway' ); ?></label></th>
					<td><input type="text" id="spg-gw-display" name="display_name" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="spg-gw-credentials"><?php esc_html_e( 'Credentials (JSON)', 'split-payment-gateway' ); ?></label></th>
					<td>
						<div id="spg-credentials-fields"></div>
						<p class="description"><?php esc_html_e( 'Fields depend on the selected gateway. Credentials are encrypted before saving.', 'split-payment-gateway' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Default for Shipping', 'split-payment-gateway' ); ?></th>
					<td><input type="checkbox" id="spg-gw-default-shipping" name="is_default_shipping" value="1"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Default for Total', 'split-payment-gateway' ); ?></th>
					<td><input type="checkbox" id="spg-gw-default-total" name="is_default_total" value="1"></td>
				</tr>
				<tr>
					<th><label for="spg-gw-fiscal-name"><?php esc_html_e( 'Fiscal Entity Name', 'split-payment-gateway' ); ?></label></th>
					<td><input type="text" id="spg-gw-fiscal-name" name="fiscal_entity_name" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="spg-gw-fiscal-taxid"><?php esc_html_e( 'Tax ID (VAT / CUIT)', 'split-payment-gateway' ); ?></label></th>
					<td><input type="text" id="spg-gw-fiscal-taxid" name="fiscal_tax_id" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="spg-gw-fiscal-address"><?php esc_html_e( 'Fiscal Address', 'split-payment-gateway' ); ?></label></th>
					<td><textarea id="spg-gw-fiscal-address" name="fiscal_address" rows="3" class="large-text"></textarea></td>
				</tr>
			</table>

			<p>
				<button class="button button-primary" id="spg-save-gateway"><?php esc_html_e( 'Save Gateway', 'split-payment-gateway' ); ?></button>
				<button class="button" id="spg-cancel-gateway"><?php esc_html_e( 'Cancel', 'split-payment-gateway' ); ?></button>
			</p>
		</div>
	</div><!-- /tab-gateways -->

	<!-- Tab: QR Transfer -->
	<div id="tab-qr" class="spg-tab-content" style="display:none;">
		<h2><?php esc_html_e( 'QR Transfer Configuration', 'split-payment-gateway' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Configure bank aliases for QR Transfer payments. Customers scan the QR with their banking app and transfer directly to the configured alias.', 'split-payment-gateway' ); ?>
		</p>

		<table class="form-table" id="spg-qr-settings-form">
			<tr>
				<th><label for="spg-qr-alias-subtotal"><?php esc_html_e( 'Subtotal Alias (store)', 'split-payment-gateway' ); ?></label></th>
				<td>
					<input type="text" id="spg-qr-alias-subtotal" name="qr_alias_subtotal"
						class="regular-text"
						value="<?php echo esc_attr( $qr_settings['qr_alias_subtotal'] ?? '' ); ?>"
						placeholder="tienda.empresa">
					<p class="description">
						<?php esc_html_e( 'CBU, CVU, or alias for the store account. Customers use this to pay for products.', 'split-payment-gateway' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="spg-qr-alias-shipping"><?php esc_html_e( 'Shipping Alias (logistics)', 'split-payment-gateway' ); ?></label></th>
				<td>
					<input type="text" id="spg-qr-alias-shipping" name="qr_alias_shipping"
						class="regular-text"
						value="<?php echo esc_attr( $qr_settings['qr_alias_shipping'] ?? '' ); ?>"
						placeholder="operador.logistico">
					<p class="description">
						<?php esc_html_e( 'CBU, CVU, or alias for the logistics operator account. Customers use this to pay for shipping.', 'split-payment-gateway' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="spg-qr-country"><?php esc_html_e( 'Country / Standard', 'split-payment-gateway' ); ?></label></th>
				<td>
					<select id="spg-qr-country" name="qr_country">
						<option value="AR" <?php selected( $qr_settings['qr_country'] ?? 'AR', 'AR' ); ?>>
							<?php esc_html_e( 'Argentina (CBU / CVU / Alias)', 'split-payment-gateway' ); ?>
						</option>
						<option value="CL" <?php selected( $qr_settings['qr_country'] ?? 'AR', 'CL' ); ?>>
							<?php esc_html_e( 'Chile (CuentaRUT / RUT)', 'split-payment-gateway' ); ?>
						</option>
						<option value="MX" <?php selected( $qr_settings['qr_country'] ?? 'AR', 'MX' ); ?>>
							<?php esc_html_e( 'México (CLABE / CoDi)', 'split-payment-gateway' ); ?>
						</option>
						<option value="GENERIC" <?php selected( $qr_settings['qr_country'] ?? 'AR', 'GENERIC' ); ?>>
							<?php esc_html_e( 'Generic (any alias string)', 'split-payment-gateway' ); ?>
						</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="spg-qr-webhook-secret"><?php esc_html_e( 'Webhook Secret', 'split-payment-gateway' ); ?></label></th>
				<td>
					<input type="password" id="spg-qr-webhook-secret" name="qr_webhook_secret"
						class="regular-text"
						placeholder="<?php echo esc_attr( $qr_settings['qr_webhook_secret'] ? '••••••••' : '' ); ?>"
						autocomplete="new-password">
					<p class="description">
						<?php esc_html_e( 'HMAC-SHA256 secret used to validate QR Transfer webhook notifications. Leave blank to keep existing value.', 'split-payment-gateway' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Webhook URL:', 'split-payment-gateway' ); ?>
						<code><?php echo esc_html( rest_url( 'spg/v1/webhooks/qr-transfer' ) ); ?></code>
					</p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'CBI Settings (Argentina)', 'split-payment-gateway' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Required for CBI (Código de Barras Interoperable) QR codes, compatible with all Argentine banks and digital wallets (MercadoPago, MODO, BBVA, Santander, etc.).', 'split-payment-gateway' ); ?>
		</p>

		<table class="form-table">
			<tr>
				<th><label for="spg-qr-merchant-name"><?php esc_html_e( 'Merchant Name', 'split-payment-gateway' ); ?></label></th>
				<td>
					<input type="text" id="spg-qr-merchant-name" name="qr_merchant_name"
						class="regular-text"
						value="<?php echo esc_attr( $qr_settings['qr_merchant_name'] ?? '' ); ?>"
						maxlength="25"
						placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
					<p class="description">
						<?php esc_html_e( 'Store name as it appears to the customer in their banking app (max 25 characters). Defaults to the site name if empty.', 'split-payment-gateway' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="spg-qr-merchant-city"><?php esc_html_e( 'Merchant City', 'split-payment-gateway' ); ?></label></th>
				<td>
					<input type="text" id="spg-qr-merchant-city" name="qr_merchant_city"
						class="regular-text"
						value="<?php echo esc_attr( $qr_settings['qr_merchant_city'] ?? '' ); ?>"
						maxlength="15"
						placeholder="Buenos Aires">
					<p class="description">
						<?php esc_html_e( 'City of the merchant (max 15 characters). Required by the CBI standard.', 'split-payment-gateway' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="spg-qr-psp-id"><?php esc_html_e( 'PSP ID', 'split-payment-gateway' ); ?></label></th>
				<td>
					<input type="text" id="spg-qr-psp-id" name="qr_psp_id"
						class="regular-text"
						value="<?php echo esc_attr( $qr_settings['qr_psp_id'] ?? '00000031' ); ?>"
						placeholder="00000031">
					<p class="description">
						<?php esc_html_e( 'Payment Service Provider identifier. Default "00000031" corresponds to Red Link. Only change this if your PSP provides a different ID.', 'split-payment-gateway' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<p>
			<button class="button button-primary" id="spg-save-qr-settings">
				<?php esc_html_e( 'Save QR Settings', 'split-payment-gateway' ); ?>
			</button>
		</p>

		<hr>

		<h3><?php esc_html_e( 'How it works', 'split-payment-gateway' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Customer selects "QR Transfer" for a payment section at checkout.', 'split-payment-gateway' ); ?></li>
			<li><?php esc_html_e( 'For Argentina: the plugin generates a CBI-standard QR (BCRA Com. "A" 6506) understood by all banking apps.', 'split-payment-gateway' ); ?></li>
			<li><?php esc_html_e( 'Customer scans the QR with their banking app (e.g. Mercado Pago, MODO, bank app).', 'split-payment-gateway' ); ?></li>
			<li><?php esc_html_e( 'After transfer, the bank or aggregator sends a webhook to confirm payment.', 'split-payment-gateway' ); ?></li>
			<li><?php esc_html_e( 'The order is automatically confirmed when both payments are received.', 'split-payment-gateway' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'QR Data Format (Argentina – CBI)', 'split-payment-gateway' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'For Argentina, the QR encodes a CBI (Código de Barras Interoperable) TLV payload compatible with all Argentine banks:', 'split-payment-gateway' ); ?>
		</p>
		<pre style="background:#f7f7f7;padding:12px;border:1px solid #ddd;border-radius:4px;word-break:break-all;">000201010212262X0005ALIAS01YYY&lt;alias&gt;020800000031540Z&lt;amount&gt;5802AR59XX&lt;merchant&gt;60YY&lt;city&gt;6304XXXX</pre>
	</div><!-- /tab-qr -->

	<!-- Tab: Split Rules -->
	<div id="tab-rules" class="spg-tab-content" style="display:none;">
		<h2><?php esc_html_e( 'Split Rules', 'split-payment-gateway' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Define routing rules that determine which payment method is used for shipping and subtotal. Rules are evaluated in priority order (lower number = higher priority).', 'split-payment-gateway' ); ?>
		</p>

		<div class="spg-rules-grid" id="spg-rules-cards">
			<?php if ( empty( $rules ) ) : ?>
				<div class="spg-empty-state">
					<span class="dashicons dashicons-list-view spg-empty-icon"></span>
					<p><?php esc_html_e( 'No rules configured yet. Click "Add Rule" to get started.', 'split-payment-gateway' ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $rules as $rule ) : ?>
					<div class="spg-rule-card <?php echo $rule['is_active'] ? 'spg-rule-active' : 'spg-rule-inactive'; ?>"
						data-id="<?php echo esc_attr( $rule['id'] ); ?>">
						<div class="spg-rule-card-header">
							<strong class="spg-rule-name"><?php echo esc_html( $rule['rule_name'] ); ?></strong>
							<span class="spg-rule-status-badge <?php echo $rule['is_active'] ? 'spg-badge-active' : 'spg-badge-inactive'; ?>">
								<?php echo $rule['is_active'] ? esc_html__( 'Active', 'split-payment-gateway' ) : esc_html__( 'Inactive', 'split-payment-gateway' ); ?>
							</span>
						</div>
						<div class="spg-rule-card-body">
							<div class="spg-rule-detail">
								<span class="spg-rule-icon" aria-hidden="true">🚚</span>
								<span class="spg-rule-label"><?php esc_html_e( 'Shipping:', 'split-payment-gateway' ); ?></span>
								<span class="spg-rule-value"><?php echo esc_html( $rule['shipping_gateway'] ); ?></span>
							</div>
							<div class="spg-rule-detail">
								<span class="spg-rule-icon" aria-hidden="true">🛒</span>
								<span class="spg-rule-label"><?php esc_html_e( 'Subtotal:', 'split-payment-gateway' ); ?></span>
								<span class="spg-rule-value"><?php echo esc_html( $rule['total_gateway'] ); ?></span>
							</div>
							<div class="spg-rule-detail">
								<span class="spg-rule-icon" aria-hidden="true">⬆️</span>
								<span class="spg-rule-label"><?php esc_html_e( 'Priority:', 'split-payment-gateway' ); ?></span>
								<span class="spg-rule-value"><?php echo esc_html( $rule['priority'] ); ?></span>
							</div>
						</div>
						<div class="spg-rule-card-actions">
							<button class="button spg-edit-rule" data-id="<?php echo esc_attr( $rule['id'] ); ?>">
								<?php esc_html_e( 'Edit', 'split-payment-gateway' ); ?>
							</button>
							<button class="button spg-delete-rule spg-btn-danger" data-id="<?php echo esc_attr( $rule['id'] ); ?>">
								<?php esc_html_e( 'Delete', 'split-payment-gateway' ); ?>
							</button>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<p>
			<button class="button button-primary spg-btn-add" id="spg-add-rule">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e( 'Add Rule', 'split-payment-gateway' ); ?>
			</button>
		</p>

		<!-- Add/Edit Rule Form -->
		<div id="spg-rule-form" class="spg-form-panel" style="display:none;">
			<h3><?php esc_html_e( 'Rule Details', 'split-payment-gateway' ); ?></h3>
			<input type="hidden" id="spg-rule-id" value="">

			<table class="form-table">
				<tr>
					<th><label for="spg-rule-name"><?php esc_html_e( 'Rule Name', 'split-payment-gateway' ); ?></label></th>
					<td><input type="text" id="spg-rule-name" name="rule_name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Default Rule', 'split-payment-gateway' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="spg-rule-shipping-gw"><span aria-hidden="true">🚚</span> <?php esc_html_e( 'Shipping Payment Method', 'split-payment-gateway' ); ?></label></th>
					<td>
						<select id="spg-rule-shipping-gw" name="shipping_gateway" class="spg-select-modern">
							<option value=""><?php esc_html_e( '— Select —', 'split-payment-gateway' ); ?></option>
							<option value="mercadopago">MercadoPago</option>
							<option value="nave">Nave</option>
							<option value="stripe">Stripe</option>
							<option value="paypal">PayPal</option>
							<option value="qr_transfer"><?php esc_html_e( 'QR Transfer', 'split-payment-gateway' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Payment method used to collect the shipping cost.', 'split-payment-gateway' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="spg-rule-total-gw"><span aria-hidden="true">🛒</span> <?php esc_html_e( 'Subtotal Payment Method', 'split-payment-gateway' ); ?></label></th>
					<td>
						<select id="spg-rule-total-gw" name="total_gateway" class="spg-select-modern">
							<option value=""><?php esc_html_e( '— Select —', 'split-payment-gateway' ); ?></option>
							<option value="mercadopago">MercadoPago</option>
							<option value="nave">Nave</option>
							<option value="stripe">Stripe</option>
							<option value="paypal">PayPal</option>
							<option value="qr_transfer"><?php esc_html_e( 'QR Transfer', 'split-payment-gateway' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Payment method used to collect the order subtotal (products).', 'split-payment-gateway' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="spg-rule-priority"><?php esc_html_e( 'Priority', 'split-payment-gateway' ); ?></label></th>
					<td>
						<input type="number" id="spg-rule-priority" name="priority" min="1" value="10" class="small-text">
						<p class="description"><?php esc_html_e( 'Lower number = evaluated first. Use 10, 20, 30… to leave room for future rules.', 'split-payment-gateway' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Active', 'split-payment-gateway' ); ?></th>
					<td>
						<label class="spg-toggle-label">
							<input type="checkbox" id="spg-rule-active" name="is_active" value="1" checked>
							<span class="spg-toggle-text"><?php esc_html_e( 'Enable this rule', 'split-payment-gateway' ); ?></span>
						</label>
					</td>
				</tr>
			</table>

			<p>
				<button class="button button-primary" id="spg-save-rule"><?php esc_html_e( 'Save Rule', 'split-payment-gateway' ); ?></button>
				<button class="button" id="spg-cancel-rule"><?php esc_html_e( 'Cancel', 'split-payment-gateway' ); ?></button>
			</p>
		</div>
	</div><!-- /tab-rules -->

	<div id="spg-notice" class="notice" style="display:none;"></div>
</div><!-- /wrap -->
