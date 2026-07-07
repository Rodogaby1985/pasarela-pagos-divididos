<?php
/**
 * Admin Dashboard page template.
 *
 * Variables:
 *   $payments     (array)
 *   $webhook_logs (array)
 *   $stats        (array)
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap spg-admin-wrap">
	<h1><?php esc_html_e( 'Split Payment Gateway – Dashboard', 'split-payment-gateway' ); ?></h1>

	<!-- Summary Cards -->
	<div class="spg-stats-row">
		<div class="spg-stat-card">
			<span class="spg-stat-label"><?php esc_html_e( 'Total Payments', 'split-payment-gateway' ); ?></span>
			<span class="spg-stat-value"><?php echo esc_html( number_format_i18n( $stats['total_payments'] ) ); ?></span>
		</div>
		<div class="spg-stat-card spg-stat-success">
			<span class="spg-stat-label"><?php esc_html_e( 'Completed', 'split-payment-gateway' ); ?></span>
			<span class="spg-stat-value"><?php echo esc_html( number_format_i18n( $stats['completed'] ) ); ?></span>
		</div>
		<div class="spg-stat-card spg-stat-error">
			<span class="spg-stat-label"><?php esc_html_e( 'Failed', 'split-payment-gateway' ); ?></span>
			<span class="spg-stat-value"><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></span>
		</div>
		<div class="spg-stat-card spg-stat-revenue">
			<span class="spg-stat-label"><?php esc_html_e( 'Total Revenue', 'split-payment-gateway' ); ?></span>
			<span class="spg-stat-value"><?php echo esc_html( wc_price( $stats['total_revenue'] ) ); ?></span>
		</div>
		<div class="spg-stat-card <?php echo $stats['pending_webhooks'] > 0 ? 'spg-stat-warning' : ''; ?>">
			<span class="spg-stat-label"><?php esc_html_e( 'Pending Webhooks', 'split-payment-gateway' ); ?></span>
			<span class="spg-stat-value"><?php echo esc_html( number_format_i18n( $stats['pending_webhooks'] ) ); ?></span>
		</div>
	</div>

	<!-- Recent Payments -->
	<h2><?php esc_html_e( 'Recent Split Payments', 'split-payment-gateway' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Order', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Status', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Shipping Gateway', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Shipping Amount', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Total Gateway', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Total Amount', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Currency', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Date', 'split-payment-gateway' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $payments ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'No payments recorded yet.', 'split-payment-gateway' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $payments as $p ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( $p['order_id'] ) ); ?>">
								#<?php echo esc_html( $p['order_id'] ); ?>
							</a>
						</td>
						<td>
							<span class="spg-badge spg-badge-<?php echo esc_attr( $p['status'] ); ?>">
								<?php echo esc_html( $p['status'] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $p['shipping_gateway'] ); ?></td>
						<td><?php echo esc_html( wc_price( $p['shipping_amount'], array( 'currency' => $p['currency'] ) ) ); ?></td>
						<td><?php echo esc_html( $p['total_gateway'] ); ?></td>
						<td><?php echo esc_html( wc_price( $p['total_amount'], array( 'currency' => $p['currency'] ) ) ); ?></td>
						<td><?php echo esc_html( $p['currency'] ); ?></td>
						<td><?php echo esc_html( $p['created_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<!-- Webhook Logs -->
	<h2 style="margin-top:2em;"><?php esc_html_e( 'Recent Webhook Logs', 'split-payment-gateway' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Gateway', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Event', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Order', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Processed', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Error', 'split-payment-gateway' ); ?></th>
				<th><?php esc_html_e( 'Date', 'split-payment-gateway' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $webhook_logs ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No webhook logs yet.', 'split-payment-gateway' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $webhook_logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log['id'] ); ?></td>
						<td><?php echo esc_html( $log['gateway'] ); ?></td>
						<td><?php echo esc_html( $log['event_type'] ); ?></td>
						<td><?php echo $log['order_id'] ? esc_html( '#' . $log['order_id'] ) : '—'; ?></td>
						<td><?php echo $log['processed'] ? '✅' : '⏳'; ?></td>
						<td><?php echo esc_html( $log['error'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $log['created_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
