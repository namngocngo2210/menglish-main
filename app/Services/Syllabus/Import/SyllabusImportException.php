<?php

namespace App\Services\Syllabus\Import;

use RuntimeException;

/** Lỗi nghiệp vụ khi ghi gói import vào DB (vd. mã giáo trình đã tồn tại); message hiển thị được cho người dùng. */
class SyllabusImportException extends RuntimeException {}
