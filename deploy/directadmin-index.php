<?php

/*
 * public_html/index.php cho shared hosting DirectAdmin.
 * Code Laravel nằm NGOÀI public_html (thư mục APP_DIR bên dưới, cùng cấp với public_html),
 * public_html chỉ chứa nội dung thư mục public/. Workflow deploy thay __APP_DIR__ bằng tên thư mục thật.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

error_reporting(E_ALL & ~E_DEPRECATED);

$appPath = __DIR__.'/../__APP_DIR__';

if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appPath.'/vendor/autoload.php';

$app = require_once $appPath.'/bootstrap/app.php';

// public_path() phải trỏ về public_html (upload, storage:link, asset build).
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
