<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Đọc thô toàn bộ ô của file (xlsx / xls / csv) thành mảng (dòng 1 là tiêu đề); việc hiểu cột và
 * kiểm tra do nơi gọi làm (CrmImportController, TuitionImportService). Dùng với Excel::toArray().
 * Ô được đọc dạng chuỗi để "500.000" (kiểu Việt Nam) hay SĐT "09..." không bị đổi kiểu.
 */
class RawRowsImport extends StringValueBinder implements SkipsEmptyRows, WithCalculatedFormulas, WithCustomValueBinder {}
