<?php

namespace Alareqi\SmartUpload\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploader
{
    protected string $tempDisk;

    protected string $tempDirectory;

    protected int $expirationHours;

    public function __construct()
    {
        $config = config('smart-upload');

        $this->tempDisk = $config['temporary_file_upload']['disk'] ?? 'local';
        $this->tempDirectory = $config['temporary_file_upload']['directory'] ?? 'tmp';
        $this->expirationHours = $config['expiration_hours'] ?? 24;
    }

    public function uploadFile(UploadedFile $file): array
    {
        $uuid = (string) Str::uuid();

        $extension = $file->getClientOriginalExtension();
        $extension = $extension ? '.'.$extension : '';
        $storedFilename = $uuid.$extension;

        $expiresAt = now()->addHours($this->expirationHours);

        Storage::disk($this->tempDisk)->putFileAs(
            $this->tempDirectory,
            $file,
            $storedFilename
        );

        $path = $this->tempDirectory.'/'.$storedFilename;

        $metadata = [
            'uuid' => $uuid,
            'path' => $path,
            'extension' => $extension,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $cacheDriver = config('smart-upload.cache.driver', 'file');
        Cache::store($cacheDriver)->put("smart_upload_{$uuid}", $metadata, $this->expirationHours * 60);

        $disk = Storage::disk($this->tempDisk);

        if ($this->tempDisk === 's3') {
            $uploadUrl = $disk->temporaryUrl(
                $path,
                $expiresAt,
                ['Content-Type' => $file->getMimeType()]
            );
        } else {
            $uploadUrl = $disk->temporaryUrl($path, $expiresAt);
        }

        return [
            'uuid' => $uuid,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'temp_url' => $uploadUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    protected function findTempFile(string $uuid): ?string
    {
        $files = Storage::disk($this->tempDisk)->files($this->tempDirectory);

        foreach ($files as $file) {
            $filename = basename($file);
            if (str_starts_with($filename, $uuid.'.')) {
                return $file;
            }
        }

        return null;
    }

    public function convert(string $uuid, string $directory, ?string $filename = null): string
    {
        $cacheDriver = config('smart-upload.cache.driver', 'file');
        $metadata = Cache::store($cacheDriver)->get("smart_upload_{$uuid}");

        if (! $metadata) {
            throw new \RuntimeException("Temporary upload not found: {$uuid}");
        }

        $tempFile = $metadata['path'];

        if (! $tempFile || ! Storage::disk($this->tempDisk)->exists($tempFile)) {
            throw new \RuntimeException("Temporary file not found: {$uuid}");
        }

        $newFilename = $filename ?? $metadata['original_name'];

        if (! pathinfo($newFilename, PATHINFO_EXTENSION)) {
            $extension = pathinfo($metadata['original_name'], PATHINFO_EXTENSION);
            if ($extension) {
                $newFilename .= '.'.$extension;
            }
        }

        $path = $directory.'/'.$newFilename;

        $disk = config('smart-upload.disk', 'local');

        Storage::disk($disk)->writeStream(
            $path,
            Storage::disk($this->tempDisk)->readStream($tempFile)
        );

        Storage::disk($this->tempDisk)->delete($tempFile);
        Cache::store($cacheDriver)->forget("smart_upload_{$uuid}");

        return $path;
    }

    public function getUpload(string $uuid): ?array
    {
        $cacheDriver = config('smart-upload.cache.driver', 'file');

        return Cache::store($cacheDriver)->get("smart_upload_{$uuid}");
    }
}
