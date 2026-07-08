<?php
/**
 * Admin Settings – Gateways configuration page.
 *
 * Dedicated page for configuring MercadoPago and QR Transfer (Argentina).
 *
 * Variables available:
 *   $mp_settings  (array) MercadoPago settings from wp_options.
 *   $qr_settings  (array) QR Transfer settings from wp_options.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap spg-admin-wrap">
	<h1><?php esc_html_e( 'Split Payment – Gateway Configuration', 'split-payment-gateway' ); ?></h1>

	<!-- ── MercadoPago ─────────────────────────────────────────────────────── -->
	<div class="spg-gateway-section">
		<h2>
			<span class="spg-gateway-icon">💙</span>
			<?php esc_html_e( 'MercadoPago', 'split-payment-gateway' ); ?>
			<label class="spg-toggle">
				<input type="checkbox" id="spg-mp-enabled" value="1"
					<?php checked( $mp_settings['enabled'], 'yes' ); ?>>
				<span class="spg-toggle-slider"></span>
			</label>
		</h2>

		<div id="spg-mp-body" class="spg-section-body">
			<table class="form-table">
				<tr>
					<th><label for="spg-mp-sandbox"><?php esc_html_e( 'Environment', 'split-payment-gateway' ); ?></label></th>
					<td>
						<select id="spg-mp-sandbox" name="mp_sandbox">
							<option value="yes" <?php selected( $mp_settings['sandbox'], 'yes' ); ?>>
								<?php esc_html_e( 'Sandbox (Testing)', 'split-payment-gateway' ); ?>
							</option>
							<option value="no" <?php selected( $mp_settings['sandbox'], 'no' ); ?>>
								<?php esc_html_e( 'Production (Live)', 'split-payment-gateway' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Use Sandbox for testing without real money.', 'split-payment-gateway' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="spg-mp-access-token"><?php esc_html_e( 'Access Token', 'split-payment-gateway' ); ?></label></th>
					<td>
						<input type="password"
								id="spg-mp-access-token"
								name="mp_access_token"
								class="regular-text"
								autocomplete="new-password"
								placeholder="<?php echo esc_attr( $mp_settings['access_token'] ? '••••••••' : 'APP_USR-...' ); ?>">
						<p class="description">
							<?php
							printf(
								/* translators: URL */
								esc_html__( 'Get your token from %s.', 'split-payment-gateway' ),
								'<a href="https://www.mercadopago.com.ar/developers/panel/credentials" target="_blank" rel="noopener">mercadopago.com.ar/developers</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="spg-mp-user-id"><?php esc_html_e( 'User ID', 'split-payment-gateway' ); ?></label></th>
					<td>
						<input type="text"
								id="spg-mp-user-id"
								name="mp_user_id"
								class="regular-text"
								value="<?php echo esc_attr( $mp_settings['user_id'] ); ?>"
								placeholder="123456789">
						<p class="description">
							<?php esc_html_e( 'Your MercadoPago numeric User ID.', 'split-payment-gateway' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Verify Credentials', 'split-payment-gateway' ); ?></th>
					<td>
						<button class="button" id="spg-mp-verify-credentials">
							<?php esc_html_e( 'Verify Credentials', 'split-payment-gateway' ); ?>
						</button>
						<span id="spg-mp-credentials-status" class="spg-status-badge" style="display:none;"></span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Webhook', 'split-payment-gateway' ); ?></th>
					<td>
						<button class="button" id="spg-mp-create-webhook">
							<?php esc_html_e( 'Create / Verify Webhook', 'split-payment-gateway' ); ?>
						</button>
						<span id="spg-mp-webhook-status" class="spg-status-badge" style="display:none;"></span>
						<p class="description">
							<?php esc_html_e( 'Webhook URL:', 'split-payment-gateway' ); ?>
							<code><?php echo esc_html( rest_url( 'spg/v1/webhooks/mercadopago' ) ); ?></code>
						</p>
						<?php if ( ! empty( $mp_settings['webhook_id'] ) ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: webhook ID */
									esc_html__( 'Active webhook ID: %s', 'split-payment-gateway' ),
									'<code>' . esc_html( $mp_settings['webhook_id'] ) . '</code>'
								);
								?>
								<span class="spg-status-badge spg-status-active">✅ <?php esc_html_e( 'Active', 'split-payment-gateway' ); ?></span>
							</p>
						<?php else : ?>
							<p class="description">
								<span class="spg-status-badge spg-status-inactive">❌ <?php esc_html_e( 'No webhook configured', 'split-payment-gateway' ); ?></span>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<p>
				<button class="button button-primary" id="spg-mp-save">
					<?php esc_html_e( 'Save MercadoPago Settings', 'split-payment-gateway' ); ?>
				</button>
			</p>
		</div>
	</div>

	<hr>

	<!-- ── QR Transfer (Argentina) ─────────────────────────────────────────── -->
	<div class="spg-gateway-section">
		<h2>
			<span class="spg-gateway-icon">📱</span>
			<?php esc_html_e( 'QR Transfer (Argentina)', 'split-payment-gateway' ); ?>
			<label class="spg-toggle">
				<input type="checkbox" id="spg-qr-enabled" value="1"
					<?php checked( $qr_settings['enabled'], 'yes' ); ?>>
				<span class="spg-toggle-slider"></span>
			</label>
		</h2>

		<div id="spg-qr-body" class="spg-section-body">
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'País', 'split-payment-gateway' ); ?></th>
					<td>
						<input type="text" value="🇦🇷 Argentina" class="regular-text" readonly>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Moneda', 'split-payment-gateway' ); ?></th>
					<td>
						<input type="text" value="ARS (Peso Argentino)" class="regular-text" readonly>
					</td>
				</tr>
			</table>

			<!-- Subtotal account -->
			<h3><?php esc_html_e( '🛒 Subtotal – Cuenta Tienda', 'split-payment-gateway' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><label for="spg-qr-alias-subtotal"><?php esc_html_e( 'Alias', 'split-payment-gateway' ); ?></label></th>
					<td>
						<input type="text"
								id="spg-qr-alias-subtotal"
								name="qr_alias_subtotal"
								class="regular-text"
								value="<?php echo esc_attr( $qr_settings['alias_subtotal'] ); ?>"
								placeholder="tienda.empresa.ar">
						<p class="description">
							<?php esc_html_e( 'Alias bancario de la cuenta donde recibirás el pago del subtotal.', 'split-payment-gateway' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="spg-qr-cbu-subtotal"><?php esc_html_e( 'CBU / CVU (fallback)', 'split-payment-gateway' ); ?></label></th>
					<td>
						<input type="text"
								id="spg-qr-cbu-subtotal"
								name="qr_cbu_subtotal"
								class="regular-text"
								value="<?php echo esc_attr( $qr_settings['cbu_subtotal'] ); ?>"
								placeholder="0000000000000000000000">
						<p class="description">
							<?php esc_html_e( 'CBU o CVU usado como alternativa si el alias no es reconocido.', 'split-payment-gateway' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="spg-qr-holder-subtotal"><?php esc_html_e( 'Titular', 'split-payment-gateway' ); ?></label></th>
					<td>
						<input type="text"
								id="spg-qr-holder-subtotal"
								name="qr_holder_subtotal"
								class="regular-text"
								value="<?php echo esc_attr( $qr_settings['holder_subtotal'] ); ?>"
								placeholder="Nombre del titular">
					</td>
				</tr>
			</table>

			<!-- Shipping account -->
			<h3><?php esc_html_e( '🚚 Envío – Cuenta Operador Logístico', 'split-payment-gateway' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><label for="spg-qr-alias-shipping"><?php esc_html_e( 'Alias', 'split-payment-gateway' ); ?></label></th>
					<td>
						<input type="text"
								id="spg-qr-alias-shipping"
								name="qr_alias_shipping"
								class="regular-text"
								value="<?php echo esc_attr( $qr_settings['alias_shipping'] ); ?>"
								placeholder="operador.logistico.ar">
						<p class="description">
							<?php esc_html_e( 'Alias bancario de la cuenta del operador logístico.', 'split-payment-gateway' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="spg-qr-cbu-shipping"><?php esc_html_e( 'CBU / CVU (fallback)', 'split-payment-gateway' ); ?></label></th>
					<td>
						<input type="text"
								id="spg-qr-cbu-shipping"
								name="qr_cbu_shipping"
								class="regular-text"
								value="<?php echo esc_attr( $qr_settings['cbu_shipping'] ); ?>"
								placeholder="0000000000000000000000">
					</td>
				</tr>
				<tr>
					<th><label for="spg-qr-holder-shipping"><?php esc_html_e( 'Titular', 'split-payment-gateway' ); ?></label></th>
					<td>
						<input type="text"
								id="spg-qr-holder-shipping"
								name="qr_holder_shipping"
								class="regular-text"
								value="<?php echo esc_attr( $qr_settings['holder_shipping'] ); ?>"
								placeholder="Nombre del titular">
					</td>
				</tr>
				<tr>
					<th><label for="spg-qr-webhook-secret"><?php esc_html_e( 'Webhook Secret', 'split-payment-gateway' ); ?></label></th>
					<td>
						<input type="password"
								id="spg-qr-webhook-secret"
								name="qr_webhook_secret"
								class="regular-text"
								autocomplete="new-password"
								placeholder="<?php echo esc_attr( $qr_settings['webhook_secret'] ? '••••••••' : '' ); ?>">
						<p class="description">
							<?php esc_html_e( 'Secreto HMAC-SHA256 para validar notificaciones del banco. Dejar en blanco para mantener el valor actual.', 'split-payment-gateway' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'URL de webhook:', 'split-payment-gateway' ); ?>
							<code><?php echo esc_html( rest_url( 'spg/v1/webhooks/qr-transfer' ) ); ?></code>
						</p>
					</td>
				</tr>
			</table>

			<p>
				<button class="button button-primary" id="spg-qr-save">
					<?php esc_html_e( 'Guardar Configuración QR Transfer', 'split-payment-gateway' ); ?>
				</button>
			</p>
		</div>
	</div>

	<div id="spg-gw-notice" class="notice" style="display:none;"></div>
</div><!-- /wrap -->
