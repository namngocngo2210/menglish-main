<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Đọc thô toàn bộ ô của file (xlsx / xls / csv) thành mảng; việc hiểu cột và kiểm tra do TuitionImportService làm.
 * CSV được đọc dạng chuỗi để "500.000" (kiểu Việt Nam) không bị hiểu thành 500,0.
 */
class RawRowsImport extends StringValueBinder implements WithCalculatedFormulas, WithCustomValueBinder {}
