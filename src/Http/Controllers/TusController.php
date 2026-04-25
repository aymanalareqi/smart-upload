<?php

namespace Alareqi\SmartUpload\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use TusPhp\Cache\FileStore;
use TusPhp\Tus\Server as TusServer;

class TusController extends Controller
{
    protected TusServer $server;

    public function __construct()
    {
        // Configure Tus server
        $config = config('smart-upload');
        $tempDisk = $config['temporary_file_upload']['disk'] ?? 'local';
        $tempPath = $config['temporary_file_upload']['directory'] ?? 'tus_tmp';

        $disk = Storage::disk($tempDisk);

        // Ensure the temporary directory exists on the disk
        if (! $disk->exists($tempPath)) {
            $disk->makeDirectory($tempPath);
        }

        // TUS-PHP requires a local file path for its buffer
        $uploadDir = $disk->path($tempPath);

        $cacheDir = storage_path('framework/cache/tus');
        if (! file_exists($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        // Initialize server with custom file cache directory
        $cacheAdapter = new FileStore($cacheDir);
        $this->server = new TusServer($cacheAdapter);

        $this->server->setUploadDir($uploadDir);
        // We set the API path to /api/tus to match our route in routes/api.php
        $this->server->setApiPath('/api/tus');
    }

    /**
     * Handle TUS upload requests.
     */
    public function handle(Request $request)
    {
        try {
            $response = $this->server->serve();

            // After handling, check if any upload was completed in this request
            if ($request->isMethod('PATCH') || $request->isMethod('POST')) {
                // For TUS, completion is usually checked after a PATCH
                // However, some clients might send everything in one go or we check by key
                $path = $request->getPathInfo();
                $key = basename($path);

                // If key is 'tus', it's the creation request, no completion yet
                if ($key !== 'tus' && $key !== '' && $this->isUploadComplete($key)) {
                    $this->finalizeSmartUpload($key);
                }
            }

            return $response;
        } catch (\Exception $e) {
            Log::error('TUS Error: '.$e->getMessage(), [
                'exception' => $e,
                'path' => $request->getPathInfo(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'message' => 'TUS Server Error: '.$e->getMessage(),
                'path' => $request->getPathInfo(),
            ], 500);
        }
    }

    /**
     * Check if the TUS upload is complete.
     */
    protected function isUploadComplete(string $key): bool
    {
        $uploadDir = $this->server->getUploadDir();
        $filePath = $uploadDir.DIRECTORY_SEPARATOR.$key;

        $metadata = $this->server->getCache()->get($key);

        if (! $metadata) {
            return false;
        }

        return (int) $metadata['offset'] === (int) $metadata['size'];
    }

    /**
     * Finalize the upload by creating a SmartUpload compatible cache entry.
     */
    protected function finalizeSmartUpload(string $key): void
    {
        $metadata = $this->server->getCache()->get($key);
        if (! $metadata) {
            return;
        }

        $originalName = $metadata['metadata']['name'] ?? 'file';
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $extension = $extension ? '.'.$extension : '';

        $config = config('smart-upload');
        $tempDisk = $config['temporary_file_upload']['disk'] ?? 'local';
        $tempPath = $config['temporary_file_upload']['directory'] ?? 'tus_tmp';
        $expirationHours = $config['expiration_hours'] ?? 24;

        $disk = Storage::disk($tempDisk);

        // Current file path (from TUS buffer)
        $uploadDir = $this->server->getUploadDir();
        $sourcePath = $uploadDir.DIRECTORY_SEPARATOR.$metadata['name'];

        // Target file path (SmartUpload style)
        $storedFilename = $key.$extension;
        $targetSubPath = $tempPath.'/'.$storedFilename;

        // Ensure the source exists and target does not
        if (file_exists($sourcePath) && ! $disk->exists($targetSubPath)) {
            // Move file to the final temporary destination on the disk
            if ($tempDisk === 'local') {
                rename($sourcePath, $disk->path($targetSubPath));
            } else {
                $disk->put($targetSubPath, fopen($sourcePath, 'r+'));
                @unlink($sourcePath);
            }
        }

        $smartMetadata = [
            'uuid' => $key,
            'path' => $targetSubPath,
            'extension' => $extension,
            'original_name' => $originalName,
            'size' => $metadata['size'],
            'mime_type' => $metadata['metadata']['type'] ?? 'application/octet-stream',
            'expires_at' => now()->addHours($expirationHours)->toIso8601String(),
        ];

        $cacheDriver = config('smart-upload.cache.driver', 'file');
        Cache::store($cacheDriver)->put("smart_upload_{$key}", $smartMetadata, $expirationHours * 60);
    }
}
