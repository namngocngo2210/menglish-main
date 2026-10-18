<?php

// Cloudflare R2 (S3 API), một bucket riêng tư cho mỗi môi trường. File công khai không mở thẳng từ bucket:
// /storage/... và /uploads/... đi qua UploadedFileController, chuyển hướng sang URL ký có hạn.
$r2 = fn (string $root, ?string $url = null): array => [
    'driver' => 's3',
    'key' => env('R2_ACCESS_KEY_ID'),
    'secret' => env('R2_SECRET_ACCESS_KEY'),
    'region' => 'auto',
    'bucket' => env('R2_BUCKET'),
    'endpoint' => env('R2_ENDPOINT'),
    'use_path_style_endpoint' => true,
    // aws-sdk-php mới tự gửi checksum CRC cho mọi request; chỉ gửi khi bắt buộc để tương thích R2.
    'request_checksum_calculation' => 'when_required',
    'response_checksum_validation' => 'when_required',
    'root' => $root,
    'url' => $url,
    'visibility' => 'private',
    // R2 không có ACL theo từng file: di chuyển / chép file không đọc lại quyền của file nguồn.
    'retain_visibility' => false,
    // Lỗi ghi lên R2 phải báo ngay, không trả false rồi lưu đường dẫn rỗng vào DB.
    'throw' => true,
    'report' => false,
];

$r2Disks = [
    // Thư mục trong bucket trùng đường dẫn URL (S3 ghép url + root): url() ra APP_URL/storage/... và APP_URL/uploads/...
    'r2_private' => $r2('private'),
    'r2_public' => $r2('storage', env('APP_URL')),
    'r2_uploads' => $r2('uploads', env('APP_URL')),
];

$onR2 = env('UPLOAD_STORAGE', 'local') === 'r2';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        // Ba disk chứa file người dùng tải lên: 'local' (riêng tư), 'public' (xem qua /storage/...),
        // 'uploads' (xem qua /uploads/..., thay cho thư mục public/uploads cũ). UPLOAD_STORAGE=r2 chuyển
        // cả ba sang Cloudflare R2 (mỗi disk một thư mục trong bucket), code gọi tên disk giữ nguyên.
        'local' => $onR2 ? $r2Disks['r2_private'] : [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Không bật route /storage/{path} của Laravel: đường dẫn đó dành cho file công khai (UploadedFileController).
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => $onR2 ? $r2Disks['r2_public'] : [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'uploads' => $onR2 ? $r2Disks['r2_uploads'] : [
            'driver' => 'local',
            'root' => public_path('uploads'),
            'url' => env('APP_URL').'/uploads',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Luôn khai báo để lệnh uploads:copy-to-r2 chép file cũ lên R2 trước khi bật UPLOAD_STORAGE=r2.
        ...$r2Disks,

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
