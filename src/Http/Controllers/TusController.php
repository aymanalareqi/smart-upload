<?php

namespace Alareqi\SmartUpload\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use TusPhp\Cache\FileStore;
use TusPhp\Events\UploadComplete;
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
        if (!$disk->exists($tempPath)) {
            $disk->makeDirectory($tempPath);
        }

        // TUS-PHP requires a local file path for its buffer
        $uploadDir = $disk->path($tempPath);

        $cacheDir = storage_path('framework/cache/tus');
        if (!file_exists($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }


        // Initialize server with custom file cache directory
        $cacheAdapter = new FileStore($cacheDir);
        $this->server = new TusServer($cacheAdapter);

        $this->server->setUploadDir($uploadDir);
        // We set the API path to /api/tus to match our route in routes/api.php
        $this->server->setApiPath('/api/tus');

        // Add event listener for upload completion
        $this->server->event()->addListener(UploadComplete::NAME, function (UploadComplete $event) {
            $this->finalizeSmartUpload($event->getFile());
        });
    }

    /**
     * Handle TUS upload requests.
     */
    public function handle(Request $request)
    {
        try {
            $response = $this->server->serve();

            return $response;
        } catch (\Exception $e) {
            Log::error('TUS Error: ' . $e->getMessage(), [
                'exception' => $e,
                'path' => $request->getPathInfo(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'message' => 'TUS Server Error: ' . $e->getMessage(),
                'path' => $request->getPathInfo(),
            ], 500);
        }
    }

    /**
     * Finalize the upload by creating a SmartUpload compatible cache entry.
     */
    protected function finalizeSmartUpload(\TusPhp\File $file): void
    {
        $key = $file->getKey();
        $metadata = $file->details();

        $originalName = $metadata['metadata']['name'] ?? 'file';
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $extension = $extension ? '.' . $extension : '';

        $config = config('smart-upload');
        $tempDisk = $config['temporary_file_upload']['disk'] ?? 'local';
        $tempPath = $config['temporary_file_upload']['directory'] ?? 'tus_tmp';
        $expirationHours = $config['expiration_hours'] ?? 24;

        $disk = Storage::disk($tempDisk);

        // Current file path (from TUS buffer)
        $uploadDir = $this->server->getUploadDir();
        $sourcePath = $uploadDir . DIRECTORY_SEPARATOR . $metadata['name'];

        // Target file path (SmartUpload style)
        $storedFilename = $key . $extension;
        $targetSubPath = $tempPath . '/' . $storedFilename;

        // Ensure the source exists and target does not
        if (file_exists($sourcePath) && !$disk->exists($targetSubPath)) {
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
