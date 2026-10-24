<?php

namespace App\Services\GoogleDrive;

use RuntimeException;

/** Lỗi gọi Google Drive API (thông điệp lấy từ error.message của Google). */
class DriveException extends RuntimeException {}
