<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Giống RawRowsImport nhưng KHÔNG bỏ dòng trống, để nơi gọi biết số dòng thật trong file
 * (dùng qua RawRowsImport::firstSheetNumbered()).
 */
class RawRowsKeepEmptyImport extends StringValueBinder implements WithCalculatedFormulas, WithCustomValueBinder
{
}
