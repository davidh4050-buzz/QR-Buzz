<?php
namespace QRBuzz;

use QRBuzz\Admin\AdminPage;
use QRBuzz\Core\Requirements;
use QRBuzz\Database\Installer;
use QRBuzz\Database\QRRepository;
use QRBuzz\Redirect\RedirectHandler;

class Plugin {

    private QRRepository $repository;

    public function __construct(?QRRepository $repository = null) {
        $this->repository = $repository ?: new QRRepository();
    }

    public function init(): void {
        $this->maybeUpgradeDatabase();
        (new Requirements())->init();
        (new RedirectHandler($this->repository))->init();

        if (is_admin()) {
            (new AdminPage($this->repository))->init();
        }
    }

    private function maybeUpgradeDatabase(): void {
        if (get_option('qrbuzz_db_version') !== QR_BUZZ_VERSION) {
            Installer::createTables();
            update_option('qrbuzz_db_version', QR_BUZZ_VERSION);
        }
    }
}
