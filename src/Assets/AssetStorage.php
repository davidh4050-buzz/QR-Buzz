<?php
namespace QRBuzz\Assets;

use QRBuzz\Models\Asset;
use QRBuzz\Workspace\WorkspaceService;

class AssetStorage {

    public const MAX_BYTES = 5242880;
    public const MAX_DIMENSION = 6000;

    private AssetRepository $repository;
    private WorkspaceService $workspaces;

    public function __construct(?AssetRepository $repository = null, ?WorkspaceService $workspaces = null) {
        $this->workspaces = $workspaces ?: new WorkspaceService();
        $this->repository = $repository ?: new AssetRepository($this->workspaces);
    }

    public function upload(array $file): Asset {
        if (empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException('No image file was received.');
        }
        if (!empty($file['error'])) {
            throw new \RuntimeException('The image upload failed.');
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('Images must be 5 MB or smaller.');
        }

        $tmp = (string) $file['tmp_name'];
        $original = sanitize_file_name((string) ($file['name'] ?? 'logo.png'));
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $checked = wp_check_filetype_and_ext($tmp, $original, $allowed);
        $mime = (string) ($checked['type'] ?? '');
        if (!in_array($mime, $allowed, true)) {
            throw new \RuntimeException('Please upload a PNG, JPG, JPEG or WebP image.');
        }

        $size = getimagesize($tmp);
        if (!$size || empty($size[0]) || empty($size[1])) {
            throw new \RuntimeException('The uploaded file is not a valid image.');
        }
        if ((int) $size[0] > self::MAX_DIMENSION || (int) $size[1] > self::MAX_DIMENSION) {
            throw new \RuntimeException('Images must be 6000px or smaller on each side.');
        }

        [$directory, $relativeDirectory] = $this->directory();
        if (!wp_mkdir_p($directory)) {
            throw new \RuntimeException('QR Buzz could not create the asset folder.');
        }

        $stored = wp_unique_filename($directory, $original);
        $target = trailingslashit($directory) . $stored;
        if (!move_uploaded_file($tmp, $target)) {
            throw new \RuntimeException('QR Buzz could not save the uploaded image.');
        }

        $name = preg_replace('/\.[^.]+$/', '', $original);
        $id = $this->repository->create([
            'display_name' => $name ?: 'Logo asset',
            'original_filename' => $original,
            'stored_filename' => $stored,
            'mime_type' => $mime,
            'file_size' => (int) $file['size'],
            'width' => (int) $size[0],
            'height' => (int) $size[1],
            'storage_path' => trailingslashit($relativeDirectory) . $stored,
        ]);

        $asset = $this->repository->find($id);
        if (!$asset) {
            throw new \RuntimeException('QR Buzz saved the file but could not register it.');
        }
        return $this->withUrl($asset);
    }

    public function withUrl(Asset $asset): Asset {
        $upload = wp_upload_dir();
        $asset->url = trailingslashit((string) $upload['baseurl']) . ltrim($asset->storagePath, '/');
        return $asset;
    }

    /** @param Asset[] $assets */
    public function withUrls(array $assets): array {
        return array_map(fn(Asset $asset): Asset => $this->withUrl($asset), $assets);
    }

    public function path(Asset $asset): ?string {
        $upload = wp_upload_dir();
        $base = wp_normalize_path((string) $upload['basedir']);
        $path = wp_normalize_path($base . '/' . ltrim($asset->storagePath, '/'));
        return str_starts_with($path, $base) && is_readable($path) ? $path : null;
    }

    public function pathForAssetId(int $assetId): ?string {
        $asset = $this->repository->find($assetId);
        return $asset ? $this->path($asset) : null;
    }

    public function deleteFile(Asset $asset): void {
        $path = $this->path($asset);
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    private function directory(): array {
        $upload = wp_upload_dir();
        $workspace = max(1, $this->workspaces->id());
        $user = max(1, get_current_user_id());
        $relative = 'qr-buzz/assets/workspace-' . $workspace . '/user-' . $user;
        return [trailingslashit((string) $upload['basedir']) . $relative, $relative];
    }
}
