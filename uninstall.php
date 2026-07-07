<?php
/**
 * Uninstall script for Split Payment Gateway.
 * Runs when the plugin is deleted from WordPress.
 *
 * @package SplitPaymentGateway
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Only remove data when the admin explicitly chose to do so.
$remove_data = get_option( 'spg_remove_data_on_uninstall', false );

if ( $remove_data ) {
	// Drop custom tables.
	$tables = array(
		$wpdb->prefix . 'spg_split_payments',
		$wpdb->prefix . 'spg_client_split_rules',
		$wpdb->prefix . 'spg_client_gateways',
		$wpdb->prefix . 'spg_webhook_logs',
		$wpdb->prefix . 'spg_transaction_reconciliation',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	// Remove plugin options.
	$options = array(
		'spg_version',
		'spg_settings',
		'spg_remove_data_on_uninstall',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove order meta.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_spg_' ) . '%'
		)
	);
}
