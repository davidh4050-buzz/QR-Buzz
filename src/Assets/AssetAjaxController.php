<?php
namespace QRBuzz\Assets;

class AssetAjaxController {

    private AssetRepository $repository;
    private AssetStorage $storage;

    public function __construct(?AssetRepository $repository = null, ?AssetStorage $storage = null) {
        $this->repository = $repository ?: new AssetRepository();
        $this->storage = $storage ?: new AssetStorage($this->repository);
    }

    public function init(): void {
        add_action('wp_ajax_qrbuzz_assets_list', [$this, 'listAssets']);
        add_action('wp_ajax_qrbuzz_assets_upload', [$this, 'upload']);
        add_action('wp_ajax_qrbuzz_assets_rename', [$this, 'rename']);
        add_action('wp_ajax_qrbuzz_assets_delete', [$this, 'delete']);
    }

    public function listAssets(): void {
        $this->guard();
        wp_send_json_success(['assets' => $this->assetPayload($this->storage->withUrls($this->repository->all()))]);
    }

    public function upload(): void {
        $this->guard();
        if (empty($_FILES['asset']) || !is_array($_FILES['asset'])) {
            wp_send_json_error(['message' => __('Choose an image to upload.', 'qr-buzz')], 400);
        }
        try {
            $asset = $this->storage->upload($_FILES['asset']);
            wp_send_json_success(['asset' => $asset->toArray(), 'message' => __('Logo added to your Asset Library.', 'qr-buzz')]);
        } catch (\Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()], 400);
        }
    }

    public function rename(): void {
        $this->guard();
        $id = absint($_POST['asset_id'] ?? 0);
        $name = sanitize_text_field((string) ($_POST['display_name'] ?? ''));
        if (!$id || $name === '' || !$this->repository->rename($id, $name)) {
            wp_send_json_error(['message' => __('The asset could not be renamed.', 'qr-buzz')], 400);
        }
        $asset = $this->repository->find($id);
        wp_send_json_success(['asset' => $asset ? $this->storage->withUrl($asset)->toArray() : null, 'message' => __('Asset renamed.', 'qr-buzz')]);
    }

    public function delete(): void {
        $this->guard();
        $id = absint($_POST['asset_id'] ?? 0);
        $asset = $id ? $this->repository->find($id) : null;
        if (!$asset) {
            wp_send_json_error(['message' => __('Asset not found.', 'qr-buzz')], 404);
        }
        if ($this->repository->usageCount($id) > 0) {
            wp_send_json_error(['message' => __('This asset is used by one or more QR codes. Remove it from those QR codes before deleting it.', 'qr-buzz')], 409);
        }
        $this->storage->deleteFile($asset);
        $this->repository->delete($id);
        wp_send_json_success(['asset_id' => $id, 'message' => __('Asset deleted.', 'qr-buzz')]);
    }

    private function guard(): void {
        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_send_json_error(['message' => __('You do not have permission to manage QR Buzz assets.', 'qr-buzz')], 403);
        }
        check_ajax_referer('qrbuzz_assets', 'nonce');
    }

    private function assetPayload(array $assets): array {
        return array_map(static fn($asset): array => $asset->toArray(), $assets);
    }
}
