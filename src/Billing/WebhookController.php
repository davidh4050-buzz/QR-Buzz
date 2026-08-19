<?php
namespace QRBuzz\Billing;

use WP_REST_Request;
use WP_REST_Response;

class WebhookController {

    private StripeService $stripe;

    public function __construct(?StripeService $stripe = null) { $this->stripe = $stripe ?: new StripeService(); }
    public function init(): void { add_action('rest_api_init', [$this, 'routes']); }
    public function routes(): void { register_rest_route('qr-buzz/v1', '/billing/stripe-webhook', ['methods' => 'POST', 'callback' => [$this, 'handle'], 'permission_callback' => '__return_true']); }

    public function handle(WP_REST_Request $request): WP_REST_Response {
        $result = $this->stripe->handleWebhook((string) $request->get_body(), (string) $request->get_header('stripe-signature'));
        if (is_wp_error($result)) { return new WP_REST_Response(['error' => $result->get_error_message()], 400); }
        return new WP_REST_Response(['received' => true], 200);
    }
}
