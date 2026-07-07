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
		<a href="#tab-rules"    class="nav-tab"><?php esc_html_e( 'Split Rules', 'split-payment-gateway' ); ?></a>
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

	<!-- Tab: Split Rules -->
	<div id="tab-rules" class="spg-tab-content" style="display:none;">
		<h2><?php esc_html_e( 'Split Rules', 'split-payment-gateway' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Define routing rules that determine which gateway is used for each payment type. Rules are evaluated in priority order.', 'split-payment-gateway' ); ?>
		</p>

		<table class="wp-list-table widefat fixed striped" id="spg-rules-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Rule Name', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Shipping Gateway', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Total Gateway', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Shipping %', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Total %', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Priority', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Active', 'split-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'split-payment-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rules ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No rules configured yet.', 'split-payment-gateway' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rules as $rule ) : ?>
						<tr data-id="<?php echo esc_attr( $rule['id'] ); ?>">
							<td><?php echo esc_html( $rule['rule_name'] ); ?></td>
							<td><?php echo esc_html( $rule['shipping_gateway'] ); ?></td>
							<td><?php echo esc_html( $rule['total_gateway'] ); ?></td>
							<td><?php echo esc_html( $rule['shipping_percentage'] ); ?>%</td>
							<td><?php echo esc_html( $rule['total_percentage'] ); ?>%</td>
							<td><?php echo esc_html( $rule['priority'] ); ?></td>
							<td><?php echo $rule['is_active'] ? '✅' : '❌'; ?></td>
							<td>
								<button class="button spg-edit-rule" data-id="<?php echo esc_attr( $rule['id'] ); ?>">
									<?php esc_html_e( 'Edit', 'split-payment-gateway' ); ?>
								</button>
								<button class="button spg-delete-rule" data-id="<?php echo esc_attr( $rule['id'] ); ?>">
									<?php esc_html_e( 'Delete', 'split-payment-gateway' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<p>
			<button class="button button-primary" id="spg-add-rule">
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
					<td><input type="text" id="spg-rule-name" name="rule_name" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="spg-rule-shipping-gw"><?php esc_html_e( 'Shipping Gateway', 'split-payment-gateway' ); ?></label></th>
					<td>
						<select id="spg-rule-shipping-gw" name="shipping_gateway">
							<option value=""><?php esc_html_e( '— Select —', 'split-payment-gateway' ); ?></option>
							<option value="mercadopago">MercadoPago</option>
							<option value="nave">Nave</option>
							<option value="stripe">Stripe</option>
							<option value="paypal">PayPal</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="spg-rule-total-gw"><?php esc_html_e( 'Total Gateway', 'split-payment-gateway' ); ?></label></th>
					<td>
						<select id="spg-rule-total-gw" name="total_gateway">
							<option value=""><?php esc_html_e( '— Select —', 'split-payment-gateway' ); ?></option>
							<option value="mercadopago">MercadoPago</option>
							<option value="nave">Nave</option>
							<option value="stripe">Stripe</option>
							<option value="paypal">PayPal</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="spg-rule-ship-pct"><?php esc_html_e( 'Shipping %', 'split-payment-gateway' ); ?></label></th>
					<td><input type="number" id="spg-rule-ship-pct" name="shipping_percentage" min="0" max="100" step="0.01" value="100" class="small-text"></td>
				</tr>
				<tr>
					<th><label for="spg-rule-total-pct"><?php esc_html_e( 'Total %', 'split-payment-gateway' ); ?></label></th>
					<td><input type="number" id="spg-rule-total-pct" name="total_percentage" min="0" max="100" step="0.01" value="100" class="small-text"></td>
				</tr>
				<tr>
					<th><label for="spg-rule-priority"><?php esc_html_e( 'Priority', 'split-payment-gateway' ); ?></label></th>
					<td><input type="number" id="spg-rule-priority" name="priority" min="1" value="10" class="small-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Active', 'split-payment-gateway' ); ?></th>
					<td><input type="checkbox" id="spg-rule-active" name="is_active" value="1" checked></td>
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
