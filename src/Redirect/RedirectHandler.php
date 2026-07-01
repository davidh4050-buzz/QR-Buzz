<?php
namespace QRBuzz\Redirect;

use QRBuzz\Database\QRRepository;

class RedirectHandler {

    private QRRepository $repository;

    public function __construct(?QRRepository $repository = null) {
        $this->repository = $repository ?: new QRRepository();
    }

    public function init(): void {
        add_action('init', [$this, 'addRewriteRule']);
        add_filter('query_vars', [$this, 'addQueryVar']);
        add_action('template_redirect', [$this, 'maybeRedirect']);
    }

    public function addRewriteRule(): void {
        add_rewrite_rule('^q/([A-Za-z0-9_-]+)/?$', 'index.php?qrbuzz_code=$matches[1]', 'top');
    }

    public function addQueryVar(array $vars): array {
        $vars[] = 'qrbuzz_code';
        return $vars;
    }

    public function maybeRedirect(): void {
        $shortcode = get_query_var('qrbuzz_code');

        if (!$shortcode) {
            return;
        }

        $shortcode = sanitize_text_field((string) $shortcode);
        $qrCode = $this->repository->findByShortcode($shortcode);

        if (!$qrCode || !$qrCode->active) {
            status_header(404);
            nocache_headers();
            include get_query_template('404');
            exit;
        }

        $this->repository->logScan($qrCode, $_SERVER);
        wp_redirect(esc_url_raw($qrCode->destinationUrl), 302);
        exit;
    }
}
