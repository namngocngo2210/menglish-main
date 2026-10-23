<?php

namespace App\Services\Syllabus\StageImport;

use RuntimeException;

/** File import chặng không đọc được / không đúng form Sheet 1 (thông điệp tiếng Việt hiển thị cho người dùng). */
class StageImportException extends RuntimeException {}
