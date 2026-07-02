<?php
namespace QRBuzz\Redirect;

use QRBuzz\Database\QRRepository;

class RedirectHandler {

    private QRRepository $repository;
    private DestinationResolver $resolver;

    public function __construct(?QRRepository $repository = null, ?DestinationResolver $resolver = null) {
        $this->repository = $repository ?: new QRRepository();
        $this->resolver = $resolver ?: new DestinationResolver();
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

        if (!$qrCode) {
            status_header(404);
            nocache_headers();
            include get_query_template('404');
            exit;
        }

        $resolution = $this->resolver->resolve($qrCode);
        $this->repository->logScan($qrCode, $_SERVER, $resolution);

        if ($resolution->shouldRedirect && $resolution->destinationUrl) {
            $this->safeRedirect($resolution->destinationUrl);
        }

        $this->renderMessage($resolution);
    }

    private function safeRedirect(string $destinationUrl): void {
        $host = wp_parse_url($destinationUrl, PHP_URL_HOST);

        if ($host) {
            add_filter(
                'allowed_redirect_hosts',
                static function(array $hosts) use ($host): array {
                    $hosts[] = $host;
                    return array_unique($hosts);
                }
            );
        }

        wp_safe_redirect(esc_url_raw($destinationUrl), 302);
        exit;
    }

    private function renderMessage(Resolution $resolution): void {
        status_header($resolution->scanStatus === 'expired' ? 410 : 200);
        nocache_headers();

        echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html__('QR Buzz', 'qr-buzz') . '</title>';
        echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f7;color:#1d2327}.qrbuzz-message{max-width:560px;margin:12vh auto;background:#fff;border:1px solid #c3c4c7;padding:28px;text-align:center}.qrbuzz-message h1{margin-top:0;font-size:24px}</style>';
        echo '</head><body><main class="qrbuzz-message"><h1>' . esc_html__('QR Buzz', 'qr-buzz') . '</h1><p>' . esc_html($resolution->message) . '</p></main></body></html>';
        exit;
    }
}
