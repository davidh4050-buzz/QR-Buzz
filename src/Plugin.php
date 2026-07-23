<?php
namespace QRBuzz;

use QRBuzz\Admin\AdminPage;
use QRBuzz\Admin\AnalyticsPage;
use QRBuzz\Admin\CampaignsPage;
use QRBuzz\Admin\DownloadController;
use QRBuzz\Admin\ExportController;
use QRBuzz\Admin\PlanGuard;
use QRBuzz\Admin\PlatformPage;
use QRBuzz\Admin\QRPreviewController;
use QRBuzz\App\HostedAppController;
use QRBuzz\Billing\WebhookController;
use QRBuzz\Core\Requirements;
use QRBuzz\Database\Installer;
use QRBuzz\Database\QRRepository;
use QRBuzz\REST\RestController;
use QRBuzz\Redirect\RedirectHandler;

class Plugin {

    private QRRepository $repository;

    public function __construct(?QRRepository $repository = null) { $this->repository = $repository ?: new QRRepository(); }

    public function init(): void {
        $this->maybeUpgradeDatabase();
        Installer::registerRoles();
        (new Requirements())->init();
        (new RedirectHandler($this->repository))->init();
        (new RestController())->init();
        (new WebhookController())->init();
        (new HostedAppController())->init();

        if (is_admin()) {
            (new PlatformPage())->init();
            (new PlanGuard())->init();
            (new DownloadController($this->repository))->init();
            (new ExportController($this->repository))->init();
            (new QRPreviewController($this->repository))->init();
            (new AdminPage($this->repository))->init();
            (new CampaignsPage())->init();
            (new AnalyticsPage())->init();
        }
    }

    private function maybeUpgradeDatabase(): void {
        if (get_option('qrbuzz_db_version') !== QR_BUZZ_VERSION) {
            Installer::createTables();
            update_option('qrbuzz_db_version', QR_BUZZ_VERSION);
        }
    }
}
