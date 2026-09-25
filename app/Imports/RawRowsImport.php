<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

/**
 * Đọc thô các dòng của file Excel / CSV (dòng 1 là tiêu đề) — xử lý / kiểm tra ở controller.
 * Dùng với Excel::toArray(new RawRowsImport, $file).
 */
class RawRowsImport implements SkipsEmptyRows {}
