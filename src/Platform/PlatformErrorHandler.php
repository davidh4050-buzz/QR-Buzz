<?php
namespace QRBuzz\Platform;

class PlatformErrorHandler {

    private PlatformErrorRepository $errors;

    public function __construct(?PlatformErrorRepository $errors = null) { $this->errors = $errors ?: new PlatformErrorRepository(); }

    public function init(): void { register_shutdown_function([$this, 'captureFatal']); }

    public function captureFatal(): void {
        $error = error_get_last();
        if (!$error || !in_array((int) $error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) { return; }
        $message = (string) ($error['message'] ?? 'Unknown fatal error');
        if (stripos($message, 'QRBuzz') === false && stripos((string) ($error['file'] ?? ''), 'qr-buzz') === false) { return; }
        $this->errors->record('application', $message, ['user_id' => get_current_user_id(), 'source' => 'shutdown_handler'], 'failure');
    }
}
