<?php

namespace Alareqi\SmartUpload\Support;

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

    public function uploadFile(array $data): array
    {
        $filename = $data['filename'] ?? 'file';

        $uuid = (string) Str::uuid();

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $storedFilename = $uuid . '.' . $extension;

        $expiresAt = now()->addHours($this->expirationHours);

        $metadata = [
            'uuid' => $uuid,
            'original_name' => $filename,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $metadataFile = $this->tempDirectory . '/' . $uuid . '.meta.json';
        Storage::disk($this->tempDisk)->put($metadataFile, json_encode($metadata));

        $disk = Storage::disk($this->tempDisk);

        if ($this->tempDisk === 's3') {
            $uploadUrl = $disk->temporaryUrl(
                $this->tempDirectory . '/' . $storedFilename,
                $expiresAt,
                ['Content-Type' => 'application/octet-stream']
            );
        } else {
            $uploadUrl = $disk->path($this->tempDirectory . '/' . $storedFilename);
            $uploadUrl .= '?token=' . $uuid;
        }

        return [
            'uuid' => $uuid,
            'upload_url' => $uploadUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    protected function getMetadata(string $uuid): ?array
    {
        $metadataFile = $this->tempDirectory . '/' . $uuid . '.meta.json';

        if (! Storage::disk($this->tempDisk)->exists($metadataFile)) {
            return null;
        }

        $content = Storage::disk($this->tempDisk)->get($metadataFile);

        return json_decode($content, true);
    }

    protected function deleteMetadata(string $uuid): void
    {
        $metadataFile = $this->tempDirectory . '/' . $uuid . '.meta.json';
        Storage::disk($this->tempDisk)->delete($metadataFile);
    }

    protected function findTempFile(string $uuid): ?string
    {
        $files = Storage::disk($this->tempDisk)->files($this->tempDirectory);

        foreach ($files as $file) {
            $filename = basename($file);
            if (str_starts_with($filename, $uuid . '.')) {
                return $file;
            }
        }

        return null;
    }

    public function cancel(string $uuid): bool
    {
        $metadata = $this->getMetadata($uuid);

        if (! $metadata) {
            return false;
        }

        $tempFile = $this->findTempFile($uuid);

        if ($tempFile) {
            Storage::disk($this->tempDisk)->delete($tempFile);
        }

        $this->deleteMetadata($uuid);

        return true;
    }

    public function convert(string $uuid, string $directory, ?string $filename = null): string
    {
        $metadata = $this->getMetadata($uuid);

        if (! $metadata) {
            throw new \RuntimeException("Temporary upload not found: {$uuid}");
        }

        $originalName = $metadata['original_name'];
        $newFilename = $filename ?? $originalName;

        $path = $directory . '/' . $newFilename;

        $tempFile = $this->findTempFile($uuid);

        if (! $tempFile) {
            throw new \RuntimeException("Temporary file not found: {$uuid}");
        }

        $disk = config('smart-upload.disk', 'local');

        Storage::disk($disk)->writeStream(
            $path,
            Storage::disk($this->tempDisk)->readStream($tempFile)
        );

        Storage::disk($this->tempDisk)->delete($tempFile);
        $this->deleteMetadata($uuid);

        return $path;
    }

    public function getUpload(string $uuid): ?array
    {
        return $this->getMetadata($uuid);
    }
}
