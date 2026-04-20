<?php

// config for Alareqi/SmartUpload

return [
    'disk' => env('SMART_UPLOAD_DISK', env('FILESYSTEM_DISK', 'local')),

    'temp_directory' => env('SMART_UPLOAD_TEMP_DIR', 'smart-upload-tmp'),

    'expiration_hours' => (int) env('SMART_UPLOAD_EXPIRATION_HOURS', 24),

    'max_file_size' => (int) env('SMART_UPLOAD_MAX_FILE_SIZE', 10240),

    'allowed_mimes' => explode(',', env('SMART_UPLOAD_ALLOWED_MIMES', 'jpg,jpeg,png,gif,pdf,doc,docx')),

    'cache' => [
        'driver' => env('SMART_UPLOAD_CACHE_DRIVER', 'file'),
    ],

    'temporary_file_upload' => [
        'disk' => env('SMART_UPLOAD_TEMP_DISK', 'local'),

        'directory' => env('SMART_UPLOAD_TEMP_PATH', 'tmp'),

        'rules' => env('SMART_UPLOAD_TEMP_RULES', 'file|mimes:jpg,jpeg,png,gif,pdf|max:10240'),

        'middleware' => env('SMART_UPLOAD_TEMP_MIDDLEWARE', 'throttle:5,1'),
    ],
];
