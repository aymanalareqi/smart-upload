<?php

namespace Alareqi\SmartUpload;

use Alareqi\SmartUpload\Commands\SmartUploadCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SmartUploadServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('smart-upload')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_smart_upload_table')
            ->hasCommand(SmartUploadCommand::class);
    }
}
