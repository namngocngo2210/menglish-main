<?php

namespace App\Services\Practice;

use RuntimeException;

/** Lỗi nghiệp vụ khi luyện tập (chưa có khối / đề, hết giờ, bài đã nộp…); message hiển thị được cho học viên. */
class PracticeException extends RuntimeException {}
