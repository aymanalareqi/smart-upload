<?php

namespace Alareqi\SmartUpload;

use Alareqi\SmartUpload\Jobs\CleanupExpiredUploads;
use Alareqi\SmartUpload\Support\FileUploader;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SmartUploadServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('smart-upload')
            ->hasConfigFile()
            ->hasRoutes('api');
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton('smart-upload', function ($app) {
            return new FileUploader;
        });
    }
}
