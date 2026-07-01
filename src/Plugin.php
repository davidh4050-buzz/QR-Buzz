<?php
namespace QRBuzz;

use QRBuzz\Admin\AdminPage;

class Plugin {
    public function init(): void {
        if (is_admin()) {
            (new AdminPage())->init();
        }
    }
}
