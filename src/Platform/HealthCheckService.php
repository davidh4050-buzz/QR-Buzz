<?php
namespace QRBuzz\Platform;

use QRBuzz\Billing\StripeService;
use QRBuzz\Database\Schema;
use QRBuzz\QR\QRGenerator;

class HealthCheckService {

    public function checks(): array {
        global $wpdb;
        $tables = [Schema::workspacesTable(), Schema::qrcodesTable(), Schema::scansTable(), Schema::campaignsTable(), Schema::subscriptionsTable(), Schema::authIdentitiesTable(), Schema::auditEventsTable(), Schema::applicationErrorsTable(), Schema::featureFlagsTable()];
        $missing = [];
        foreach ($tables as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) { $missing[] = $table; }
        }
        $generator = new QRGenerator();
        $stripe = new StripeService();
        return [
            ['Database connection', $wpdb->dbh ? 'healthy' : 'failure', $wpdb->dbh ? 'WordPress database connection is available.' : 'WordPress database connection is unavailable.', 'Check database credentials and server availability.'],
            ['Database schema', empty($missing) ? 'healthy' : 'failure', empty($missing) ? 'Required QR Buzz tables are present.' : count($missing) . ' required tables are missing.', empty($missing) ? 'No action needed.' : 'Deactivate and reactivate QR Buzz or run the installer migration.'],
            ['QR generation', class_exists(QRGenerator::class) ? 'healthy' : 'failure', 'Local QR generator class is available.', 'Confirm bundled dependencies are present in the release ZIP.'],
            ['SVG downloads', $generator->supportsSvg() ? 'healthy' : 'warning', $generator->supportsSvg() ? 'SVG writer is available.' : 'SVG writer is not available.', 'Use PNG downloads or check bundled dependency installation.'],
            ['REST API', function_exists('register_rest_route') ? 'healthy' : 'failure', 'WordPress REST API functions are available.', 'Check WordPress installation health.'],
            ['Google authentication', $this->googleConfigured() ? 'healthy' : 'warning', $this->googleConfigured() ? 'Google Client ID and secret are present.' : 'Google Sign-In is not configured; email authentication remains available.', 'Set QR_BUZZ_GOOGLE_CLIENT_ID and QR_BUZZ_GOOGLE_CLIENT_SECRET outside source control.'],
            ['Stripe checkout', $stripe->configured() ? 'healthy' : 'warning', $stripe->configured() ? 'Stripe is configured in ' . $stripe->mode() . ' mode.' : 'Stripe checkout configuration is incomplete.', 'Configure the secret key and paid Price IDs outside source control.'],
            ['Pro Price', $stripe->priceConfigured('pro') ? 'healthy' : 'warning', $stripe->priceConfigured('pro') ? 'Pro Price ID is configured.' : 'Pro Price ID is missing.', 'Set QR_BUZZ_STRIPE_PRO_PRICE_ID.'],
            ['Business Price', $stripe->priceConfigured('business') ? 'healthy' : 'warning', $stripe->priceConfigured('business') ? 'Business Price ID is configured.' : 'Business Price ID is missing.', 'Set QR_BUZZ_STRIPE_BUSINESS_PRICE_ID.'],
            ['Stripe webhook', $stripe->webhookConfigured() ? 'healthy' : 'warning', $stripe->webhookConfigured() ? 'Stripe webhook signing secret is configured.' : 'Stripe webhook signing secret is not configured.', 'Set QR_BUZZ_STRIPE_WEBHOOK_SECRET.'],
            ['Customer Portal', $stripe->portalAvailable() ? 'healthy' : 'warning', $stripe->portalAvailable() ? 'Portal sessions are available; confirm enabled features in Stripe.' : 'Portal sessions are unavailable.', 'Configure the Stripe Customer Portal.'],
            ['Email sending', function_exists('wp_mail') ? 'unknown' : 'failure', function_exists('wp_mail') ? 'WordPress mail function is available; delivery depends on site mail configuration.' : 'WordPress mail function is unavailable.', 'Send a verification email test from a user detail screen.'],
            ['Cron', defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'warning' : 'healthy', defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'WP-Cron is disabled by constant.' : 'WP-Cron is not disabled by constant.', 'Use a real server cron if WP-Cron is disabled.'],
            ['Uploads', wp_upload_dir()['error'] ? 'failure' : 'healthy', wp_upload_dir()['error'] ? (string) wp_upload_dir()['error'] : 'Upload directory is available.', 'Check upload directory permissions.'],
            ['PHP version', version_compare(PHP_VERSION, '8.3', '>=') ? 'healthy' : 'failure', 'Current PHP version: ' . PHP_VERSION, 'Use PHP 8.3 or newer.'],
            ['WordPress version', version_compare(get_bloginfo('version'), '7.0', '>=') ? 'healthy' : 'warning', 'Current WordPress version: ' . get_bloginfo('version'), 'QR Buzz targets WordPress 7.0 or newer.'],
            ['HTTPS', is_ssl() ? 'healthy' : 'warning', is_ssl() ? 'Site is using HTTPS.' : 'Site is not using HTTPS for this request.', 'Use HTTPS for production.'],
            ['Permalinks', get_option('permalink_structure') ? 'healthy' : 'warning', get_option('permalink_structure') ? 'Pretty permalinks are enabled.' : 'Plain permalinks may affect app route usability.', 'Enable pretty permalinks for hosted app routes.'],
        ];
    }

    private function googleConfigured(): bool { $id = (defined('QR_BUZZ_GOOGLE_CLIENT_ID') && QR_BUZZ_GOOGLE_CLIENT_ID) || getenv('QR_BUZZ_GOOGLE_CLIENT_ID'); $secret = (defined('QR_BUZZ_GOOGLE_CLIENT_SECRET') && QR_BUZZ_GOOGLE_CLIENT_SECRET) || getenv('QR_BUZZ_GOOGLE_CLIENT_SECRET'); return (bool) ($id && $secret); }
}
