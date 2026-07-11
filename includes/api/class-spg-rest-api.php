<?php
/**
 * REST API endpoints for Split Payment Gateway.
 *
 * Namespace: spg/v1
 *
 * Routes:
 *   POST   /spg/v1/split-payment/initiate
 *   POST   /spg/v1/split-payment/validate
 *   POST   /spg/v1/webhooks/{gateway}
 *   GET    /spg/v1/admin/fiscal-report/{client_id}
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable Universal.Operators.DisallowShortTernary.Found,WordPress.WP.Capabilities.Unknown,Squiz.PHP.CommentedOutCode.Found,Generic.CodeAnalysis.EmptyStatement.DetectedCatch,WordPress.DB.DirectDatabaseQuery

/**
 * REST API routes for split payment operations.
 */
class SPG_Rest_Api {

	use SPG_Logger;
	use SPG_Security;

	const NAMESPACE = 'spg/v1';

	/**
	 * Register all REST routes.
	 * Called via rest_api_init.
	 */
	public static function register_routes() {
		$instance = new self();

		// Initiate split payment.
		register_rest_route(
			self::NAMESPACE,
			'/split-payment/initiate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $instance, 'initiate_payment' ),
				'permission_callback' => array( $instance, 'is_authenticated' ),
				'args'                => array(
					'order_id'        => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'shipping_method' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'total_method'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Validate payment status.
		register_rest_route(
			self::NAMESPACE,
			'/split-payment/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $instance, 'validate_payment' ),
				'permission_callback' => array( $instance, 'is_authenticated' ),
				'args'                => array(
					'order_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// QR Transfer: generate / refresh a QR code for a payment section.
		register_rest_route(
			self::NAMESPACE,
			'/qr/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $instance, 'generate_qr' ),
				'permission_callback' => array( $instance, 'is_authenticated' ),
				'args'                => array(
					'order_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'section'  => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'shipping', 'total' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// QR Transfer: webhook to confirm a payment externally.
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/qr_transfer',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $instance, 'handle_qr_webhook' ),
				'permission_callback' => '__return_true', // Signature validated inside.
			)
		);

		// Unified webhook receiver for traditional gateways.
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/(?P<gateway>[a-z0-9\-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $instance, 'handle_webhook' ),
				'permission_callback' => '__return_true', // Signature validated inside.
				'args'                => array(
					'gateway' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Fiscal report (admin only).
		register_rest_route(
			self::NAMESPACE,
			'/admin/fiscal-report/(?P<client_id>[a-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $instance, 'get_fiscal_report' ),
				'permission_callback' => array( $instance, 'is_admin' ),
				'args'                => array(
					'client_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'from'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'to'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Admin: create a webhook in MercadoPago.
		register_rest_route(
			self::NAMESPACE,
			'/admin/webhook/create',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $instance, 'admin_create_webhook' ),
				'permission_callback' => array( $instance, 'is_admin' ),
				'args'                => array(
					'gateway' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'mercadopago',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Admin: verify an active webhook in MercadoPago.
		register_rest_route(
			self::NAMESPACE,
			'/admin/webhook/verify',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $instance, 'admin_verify_webhook' ),
				'permission_callback' => array( $instance, 'is_admin' ),
				'args'                => array(
					'gateway' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'mercadopago',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// ── Payment-session endpoints (new full-page flow) ──────────────────────
		// These use a session_id (128-bit random token) as authentication so they
		// work for both logged-in and guest customers.

		// Initiate the payment session: resolves gateways, creates DB record, returns QR.
		register_rest_route(
			self::NAMESPACE,
			'/payment-session/initiate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $instance, 'initiate_payment_session' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session_id'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'shipping_method' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'total_method'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Poll the payment status of a session.
		register_rest_route(
			self::NAMESPACE,
			'/payment-session/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $instance, 'get_payment_session_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Complete the session: mark the WooCommerce order as paid.
		register_rest_route(
			self::NAMESPACE,
			'/payment-session/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $instance, 'complete_payment_session' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	// ── Handlers ──────────────────────────────────────────────────────────�[...]

	/**
	 * POST /spg/v1/split-payment/initiate
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function initiate_payment( WP_REST_Request $request ) {
		$order_id = $request->get_param( 'order_id' );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'spg_invalid_order', __( 'Order not found.', 'split-payment-gateway' ), array( 'status' => 404 ) );
		}

		// Verify ownership.
		if ( $order->get_user_id() !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'spg_forbidden', __( 'Access denied.', 'split-payment-gateway' ), array( 'status' => 403 ) );
		}

		// Collect optional method choices from the frontend.
		$method_choices = array();
		if ( $request->get_param( 'shipping_method' ) ) {
			$method_choices['shipping_method'] = $request->get_param( 'shipping_method' );
		}
		if ( $request->get_param( 'total_method' ) ) {
			$method_choices['total_method'] = $request->get_param( 'total_method' );
		}

		try {
			$service   = $this->get_service();
			$client_id = $this->get_client_id();
			$result    = $service->initiate( $order, $client_id, $method_choices );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $result,
				)
			);
		} catch ( Exception $e ) {
			$this->log_error( 'REST initiate error.', array( 'error' => $e->getMessage() ) );
			return new WP_Error( 'spg_initiate_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * POST /spg/v1/qr/generate
	 *
	 * Returns fresh QR data for a specific payment section of an existing order.
	 * Used when the customer requests a new QR (e.g. after expiry) without
	 * re-initiating the entire payment session.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_qr( WP_REST_Request $request ) {
		$order_id = $request->get_param( 'order_id' );
		$section  = $request->get_param( 'section' ); // 'shipping' or 'total'
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'spg_invalid_order', __( 'Order not found.', 'split-payment-gateway' ), array( 'status' => 404 ) );
		}

		if ( $order->get_user_id() !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'spg_forbidden', __( 'Access denied.', 'split-payment-gateway' ), array( 'status' => 403 ) );
		}

		global $wpdb;

		// Verify that the section uses QR Transfer.
		$meta_key    = ( 'shipping' === $section ) ? '_spg_shipping_method_type' : '_spg_total_method_type';
		$method_type = $order->get_meta( $meta_key );

		if ( 'qr_transfer' !== $method_type ) {
			return new WP_Error(
				'spg_not_qr',
				__( 'This payment section does not use QR Transfer.', 'split-payment-gateway' ),
				array( 'status' => 400 )
			);
		}

		// Look up existing QR record.
		$tx_meta_key   = ( 'shipping' === $section ) ? '_spg_shipping_tx_id' : '_spg_total_tx_id';
		$existing_hash = $order->get_meta( $tx_meta_key );

		if ( $existing_hash ) {
			try {
				$adapter   = SPG_Gateway_Adapter_Factory::instance()->get_adapter( 'qr_transfer' );
				$qr_record = $adapter->get_qr_record( $existing_hash );

				if ( $qr_record && 'pending' === $qr_record['status'] && strtotime( $qr_record['expires_at'] ) > time() ) {
					$qr_data = json_decode( $qr_record['qr_payload'], true );
					// Return existing valid QR with server-generated image.
					return rest_ensure_response(
						array(
							'success'    => true,
							'qr_data'    => $qr_data,
							'qr_image'   => $qr_data ? $this->generate_qr_image( $qr_data ) : '',
							'expires_at' => strtotime( $qr_record['expires_at'] ),
							'status'     => 'pending',
						)
					);
				}
			} catch ( Exception $e ) {
				// Fall through to generate a new QR.
			}
		}

		// Generate a fresh QR.
		try {
			$client_id = $this->get_client_id();
			$service   = $this->get_service();
			$result    = $service->initiate(
				$order,
				$client_id,
				array(
					'shipping_method' => ( 'shipping' === $section ) ? 'qr_transfer' : null,
					'total_method'    => ( 'total' === $section ) ? 'qr_transfer' : null,
				)
			);

			$qr_data    = ( 'shipping' === $section ) ? $result['shipping_qr_data'] : $result['total_qr_data'];
			$expires_at = ( 'shipping' === $section ) ? $result['shipping_expires_at'] : $result['total_expires_at'];

			return rest_ensure_response(
				array(
					'success'    => true,
					'qr_data'    => $qr_data,
					'qr_image'   => $qr_data ? $this->generate_qr_image( $qr_data ) : '',
					'expires_at' => $expires_at,
					'status'     => 'pending',
				)
			);

		} catch ( Exception $e ) {
			$this->log_error( 'QR generate error.', array( 'error' => $e->getMessage() ) );
			return new WP_Error( 'spg_qr_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * POST /spg/v1/webhooks/qr_transfer
	 *
	 * Dedicated webhook for QR Transfer payment confirmations.
	 * This is a convenience alias; the generic /webhooks/{gateway} route
	 * also handles 'qr_transfer'.
	 *
	 * Expected JSON body: { transaction_id, order_ref, amount, status, event }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_qr_webhook( WP_REST_Request $request ) {
		$raw_body = $request->get_body();
		$headers  = $this->extract_headers( $request );

		global $wpdb;
		$factory      = SPG_Gateway_Adapter_Factory::instance();
		$orchestrator = new SPG_Webhook_Orchestrator( $wpdb, $factory );

		$result      = $orchestrator->process( 'qr_transfer', $raw_body, $headers );
		$status_code = $result['success'] ? 200 : 400;

		return new WP_REST_Response(
			array(
				'success' => $result['success'],
				'message' => $result['message'],
			),
			$status_code
		);
	}

	/**
	 * POST /spg/v1/split-payment/validate
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_payment( WP_REST_Request $request ) {
		$order_id = $request->get_param( 'order_id' );

		try {
			$service    = $this->get_service();
			$validation = $service->validate( $order_id );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $validation,
				)
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'spg_validate_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * POST /spg/v1/webhooks/{gateway}
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$gateway_name = $request->get_param( 'gateway' );
		$raw_body     = $request->get_body();
		$headers      = $this->extract_headers( $request );

		global $wpdb;
		$factory      = SPG_Gateway_Adapter_Factory::instance();
		$orchestrator = new SPG_Webhook_Orchestrator( $wpdb, $factory );

		$result = $orchestrator->process( $gateway_name, $raw_body, $headers );

		$status_code = $result['success'] ? 200 : 400;

		return new WP_REST_Response(
			array(
				'success' => $result['success'],
				'message' => $result['message'],
			),
			$status_code
		);
	}

	// ── Payment-session handlers (new full-page flow) ─────────────────────────

	/**
	 * POST /spg/v1/payment-session/initiate
	 *
	 * Resolves gateways, initiates both payments, returns QR images / gateway
	 * redirect URLs.  Authentication is via the session_id token (128-bit random
	 * value created in process_payment() and stored in a transient).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function initiate_payment_session( WP_REST_Request $request ) {
		$session_id = $request->get_param( 'session_id' );
		$session    = get_transient( 'spg_payment_session_' . $session_id );

		if ( ! $session ) {
			return new WP_Error(
				'spg_invalid_session',
				__( 'Invalid or expired payment session.', 'split-payment-gateway' ),
				array( 'status' => 404 )
			);
		}

		$order_id = absint( $session['order_id'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'spg_invalid_order',
				__( 'Order not found.', 'split-payment-gateway' ),
				array( 'status' => 404 )
			);
		}

		$method_choices = array();
		if ( $request->get_param( 'shipping_method' ) ) {
			$method_choices['shipping_method'] = $request->get_param( 'shipping_method' );
		}
		if ( $request->get_param( 'total_method' ) ) {
			$method_choices['total_method'] = $request->get_param( 'total_method' );
		}

		try {
			$service   = $this->get_service();
			$client_id = sanitize_text_field( $session['client_id'] );
			$result    = $service->initiate( $order, $client_id, $method_choices );

			// Generate server-side QR images when applicable.
			if ( 'qr_transfer' === $result['shipping_method_type'] && ! empty( $result['shipping_qr_data'] ) ) {
				$result['shipping_qr_image'] = $this->generate_qr_image( $result['shipping_qr_data'] );
			}
			if ( 'qr_transfer' === $result['total_method_type'] && ! empty( $result['total_qr_data'] ) ) {
				$result['total_qr_image'] = $this->generate_qr_image( $result['total_qr_data'] );
			}

			// Include amounts in the result so the JS can display them.
			$result['shipping_amount'] = $order->get_shipping_total();
			$result['total_amount']    = $order->get_subtotal();

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $result,
				)
			);
		} catch ( Exception $e ) {
			$this->log_error( 'Payment session initiate error.', array( 'error' => $e->getMessage() ) );
			return new WP_Error( 'spg_initiate_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * GET /spg/v1/payment-session/status
	 *
	 * Polls the payment status of the session.  Returns whether each section
	 * (shipping, total) is paid and whether both are complete.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_payment_session_status( WP_REST_Request $request ) {
		$session_id = $request->get_param( 'session_id' );
		$session    = get_transient( 'spg_payment_session_' . $session_id );

		if ( ! $session ) {
			return new WP_Error(
				'spg_invalid_session',
				__( 'Invalid or expired payment session.', 'split-payment-gateway' ),
				array( 'status' => 404 )
			);
		}

		$order_id = absint( $session['order_id'] );

		try {
			$service    = $this->get_service();
			$validation = $service->validate( $order_id );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $validation,
				)
			);
		} catch ( Exception $e ) {
			// Payment not initiated yet (no DB record) – return pending status.
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'shipping_paid' => false,
						'total_paid'    => false,
						'is_complete'   => false,
						'status'        => 'pending',
					),
				)
			);
		}
	}

	/**
	 * POST /spg/v1/payment-session/complete
	 *
	 * Marks the WooCommerce order as payment_complete() after verifying that
	 * both the shipping and total sections have been paid.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete_payment_session( WP_REST_Request $request ) {
		$session_id = $request->get_param( 'session_id' );
		$session    = get_transient( 'spg_payment_session_' . $session_id );

		if ( ! $session ) {
			return new WP_Error(
				'spg_invalid_session',
				__( 'Invalid or expired payment session.', 'split-payment-gateway' ),
				array( 'status' => 404 )
			);
		}

		$order_id = absint( $session['order_id'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'spg_invalid_order',
				__( 'Order not found.', 'split-payment-gateway' ),
				array( 'status' => 404 )
			);
		}

		// Verify that both payments are actually confirmed.
		try {
			$service    = $this->get_service();
			$validation = $service->validate( $order_id );

			if ( ! $validation['is_complete'] ) {
				return new WP_Error(
					'spg_not_complete',
					__( 'Both payments must be completed before finalizing the order.', 'split-payment-gateway' ),
					array( 'status' => 400 )
				);
			}
		} catch ( Exception $e ) {
			return new WP_Error(
				'spg_validate_error',
				__( 'Could not verify payment status.', 'split-payment-gateway' ),
				array( 'status' => 500 )
			);
		}

		// Mark the order as paid.
		$order->payment_complete();
		$this->log_info( 'Payment session completed.', array( 'order_id' => $order_id ) );

		// Clean up the transient.
		delete_transient( 'spg_payment_session_' . $session_id );

		return rest_ensure_response(
			array(
				'success'  => true,
				'redirect' => $order->get_checkout_order_received_url(),
			)
		);
	}

	/**
	 * Seconds to cache a failed QR image response to avoid hammering the external service.
	 */
	const QR_FAILURE_CACHE_SECONDS = 60;

	/**
	 * Generate a server-side QR code image from a QR data array.
	 *
	 * Uses an external QR generation service (qrserver.com) via server-side
	 * HTTP request so the browser never contacts the external service directly.
	 * The result is cached in a transient to avoid repeated remote requests.
	 *
	 * Falls back to an empty string (JS will show transfer details instead) when
	 * the external service is unavailable.
	 *
	 * @param array $qr_data Structured QR payload built by the adapter.
	 * @return string Base64 data URI (data:image/png;base64,...) or empty string.
	 */
	private function generate_qr_image( array $qr_data ) {
		$text      = wp_json_encode( $qr_data );
		$cache_key = 'spg_qr_img_' . md5( $text );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$api_url  = add_query_arg(
			array(
				'size'   => '200x200',
				'format' => 'png',
				'data'   => $text,
			),
			'https://api.qrserver.com/v1/create-qr-code/'
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout'    => 10,
				'user-agent' => 'Split-Payment-Gateway/' . SPG_VERSION,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->log_warning(
				'QR image generation failed – will show text fallback.',
				array(
					'url'   => $api_url,
					'error' => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ),
				)
			);
			// Cache the failure briefly to avoid hammering the external service.
			set_transient( $cache_key, '', self::QR_FAILURE_CACHE_SECONDS );
			return '';
		}

		// Validate the Content-Type header to confirm we received an image.
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( false === strpos( $content_type, 'image/png' ) ) {
			$this->log_warning( 'QR image response had unexpected content-type.', array( 'content_type' => $content_type ) );
			set_transient( $cache_key, '', self::QR_FAILURE_CACHE_SECONDS );
			return '';
		}

		$body = wp_remote_retrieve_body( $response );

		// Guard against extremely large or empty responses (DoS protection).
		// A 200x200 PNG QR code should be well under 10 KB.
		$body_length = strlen( $body );
		if ( $body_length < 8 || $body_length > 102400 ) {
			$this->log_warning(
				'QR image response has unexpected size.',
				array( 'body_length' => $body_length )
			);
			set_transient( $cache_key, '', self::QR_FAILURE_CACHE_SECONDS );
			return '';
		}

		// Validate the PNG file signature (magic bytes: 89 50 4E 47 0D 0A 1A 0A).
		// This ensures the body actually contains a valid PNG even when the external
		// service responds with the correct Content-Type header.
		if ( "\x89PNG\r\n\x1a\n" !== substr( $body, 0, 8 ) ) {
			$this->log_warning( 'QR image response did not contain a valid PNG signature.' );
			set_transient( $cache_key, '', self::QR_FAILURE_CACHE_SECONDS );
			return '';
		}

		$data_uri = 'data:image/png;base64,' . base64_encode( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		set_transient( $cache_key, $data_uri, HOUR_IN_SECONDS );

		return $data_uri;
	}

	/**
	 * GET /spg/v1/admin/fiscal-report/{client_id}
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_fiscal_report( WP_REST_Request $request ) {
		global $wpdb;

		$client_id = $request->get_param( 'client_id' );
		$from      = $request->get_param( 'from' ) ?: gmdate( 'Y-m-01' );
		$to        = $request->get_param( 'to' ) ?: gmdate( 'Y-m-t' );

		// Validate date format (YYYY-MM-DD) to prevent unexpected SQL behaviour.
		$date_pattern = '/^\d{4}-\d{2}-\d{2}$/';
		if ( ! preg_match( $date_pattern, $from ) || ! preg_match( $date_pattern, $to ) ) {
			return new WP_Error(
				'spg_invalid_date',
				__( 'Date parameters must be in YYYY-MM-DD format.', 'split-payment-gateway' ),
				array( 'status' => 400 )
			);
		}

		// Ensure the dates are actually valid calendar dates.
		if ( ! checkdate( (int) substr( $from, 5, 2 ), (int) substr( $from, 8, 2 ), (int) substr( $from, 0, 4 ) ) ||
			! checkdate( (int) substr( $to, 5, 2 ), (int) substr( $to, 8, 2 ), (int) substr( $to, 0, 4 ) ) ) {
			return new WP_Error(
				'spg_invalid_date',
				__( 'One or more date parameters are not valid calendar dates.', 'split-payment-gateway' ),
				array( 'status' => 400 )
			);
		}

		$cache_key = 'spg_fiscal_' . md5( $client_id . '_' . $from . '_' . $to );
		$rows      = wp_cache_get( $cache_key, 'spg_fiscal_report' );

		if ( false === $rows ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"SELECT sp.*, tr.tx_type, tr.gateway, tr.tx_id, tr.amount,
					        tr.gateway_status, tr.fiscal_document_id, tr.reconciled
					 FROM `{$wpdb->prefix}spg_split_payments` sp
					 LEFT JOIN `{$wpdb->prefix}spg_transaction_reconciliation` tr
					       ON sp.id = tr.split_payment_id
					 WHERE sp.client_id = %s
					   AND DATE(sp.created_at) BETWEEN %s AND %s
					 ORDER BY sp.created_at DESC
					 LIMIT 500",
					$client_id,
					$from,
					$to
				),
				ARRAY_A
			);
			wp_cache_set( $cache_key, $rows, 'spg_fiscal_report', 5 * MINUTE_IN_SECONDS );
		}

		return rest_ensure_response(
			array(
				'success'   => true,
				'client_id' => $client_id,
				'from'      => $from,
				'to'        => $to,
				'total'     => count( $rows ),
				'data'      => $rows,
			)
		);
	}

	// ── Admin webhook management ──────────────────────────────────────────────

	/**
	 * POST /spg/v1/admin/webhook/create
	 *
	 * Creates a webhook in the configured gateway (currently MercadoPago).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_create_webhook( WP_REST_Request $request ) {
		$gateway = $request->get_param( 'gateway' ) ?: 'mercadopago';

		if ( 'mercadopago' !== $gateway ) {
			return new WP_Error(
				'spg_unsupported_gateway',
				__( 'Auto-webhook creation is only supported for MercadoPago.', 'split-payment-gateway' ),
				array( 'status' => 400 )
			);
		}

		$encrypted    = get_option( 'spg_mp_access_token', '' );
		$access_token = $encrypted ? $this->decrypt_access_token( $encrypted ) : '';

		if ( empty( $access_token ) ) {
			return new WP_Error(
				'spg_no_credentials',
				__( 'MercadoPago Access Token is not configured.', 'split-payment-gateway' ),
				array( 'status' => 400 )
			);
		}

		$validator = new SPG_Gateway_Credentials_Validator();
		$result    = $validator->create_mercadopago_webhook( $access_token );

		if ( $result['success'] && ! empty( $result['webhook_id'] ) ) {
			update_option( 'spg_mercadopago_webhook_id', $result['webhook_id'] );
		}

		return rest_ensure_response(
			array(
				'success'    => $result['success'],
				'webhook_id' => $result['webhook_id'] ?? '',
				'message'    => $result['message'],
			)
		);
	}

	/**
	 * GET /spg/v1/admin/webhook/verify
	 *
	 * Verifies whether a webhook is registered and active.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_verify_webhook( WP_REST_Request $request ) {
		$gateway = $request->get_param( 'gateway' ) ?: 'mercadopago';

		if ( 'mercadopago' !== $gateway ) {
			return new WP_Error(
				'spg_unsupported_gateway',
				__( 'Webhook verification is only supported for MercadoPago.', 'split-payment-gateway' ),
				array( 'status' => 400 )
			);
		}

		$encrypted    = get_option( 'spg_mp_access_token', '' );
		$access_token = $encrypted ? $this->decrypt_access_token( $encrypted ) : '';

		if ( empty( $access_token ) ) {
			return new WP_Error(
				'spg_no_credentials',
				__( 'MercadoPago Access Token is not configured.', 'split-payment-gateway' ),
				array( 'status' => 400 )
			);
		}

		$validator = new SPG_Gateway_Credentials_Validator();
		$result    = $validator->verify_mercadopago_webhook( $access_token );

		return rest_ensure_response(
			array(
				'active'     => $result['active'],
				'webhook_id' => $result['webhook_id'] ?? '',
				'message'    => $result['message'],
			)
		);
	}

	// ── Permission callbacks ───────────────────────────────────────────────────

	/**
	 * Allow authenticated users (logged-in WordPress users).
	 *
	 * @return bool|WP_Error
	 */
	public function is_authenticated() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error( 'spg_unauthorized', __( 'Authentication required.', 'split-payment-gateway' ), array( 'status' => 401 ) );
	}

	/**
	 * Allow WooCommerce admins only.
	 *
	 * @return bool|WP_Error
	 */
	public function is_admin() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		return new WP_Error( 'spg_forbidden', __( 'Admin access required.', 'split-payment-gateway' ), array( 'status' => 403 ) );
	}

	// ── Private helpers ───────────────────���────────────────────────────────────

	/**
	 * Decrypt a stored (AES-256) access token.
	 *
	 * @param string $encrypted Base64-encoded ciphertext.
	 * @return string Plaintext token, or empty string on failure.
	 */
	private function decrypt_access_token( $encrypted ) {
		try {
			$result = $this->decrypt( $encrypted );
			return is_string( $result ) ? $result : '';
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Build and return the service instance.
	 *
	 * @return SPG_Split_Payment_Service
	 */
	private function get_service() {
		global $wpdb;

		$factory      = SPG_Gateway_Adapter_Factory::instance();
		$router       = new SPG_Payment_Routing_Engine( $wpdb, $factory );
		$distribution = new SPG_Split_Distribution_Engine();

		return new SPG_Split_Payment_Service( $wpdb, $router, $factory, $distribution );
	}

	/**
	 * Get the client ID from gateway settings.
	 *
	 * @return string
	 */
	private function get_client_id() {
		$gateway_settings = get_option( 'woocommerce_split_payment_gateway_settings', array() );
		return sanitize_key( $gateway_settings['client_id'] ?? get_option( 'blogname', 'default' ) );
	}

	/**
	 * Extract relevant headers from a REST request.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return array
	 */
	private function extract_headers( WP_REST_Request $request ) {
		$raw     = $request->get_headers();
		$headers = array();
		foreach ( $raw as $key => $value ) {
			$headers[ $key ] = is_array( $value ) ? reset( $value ) : $value;
		}
		return $headers;
	}
}
