<?php

namespace App\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

/**
 * Đọc thô toàn bộ ô của file (xlsx / xls / csv) thành mảng (dòng 1 là tiêu đề); việc hiểu cột và
 * kiểm tra do nơi gọi làm (CrmImportController, TuitionImportService). Dùng qua RawRowsImport::firstSheet().
 * Ô được đọc dạng chuỗi để "500.000" (kiểu Việt Nam) hay SĐT "09..." không bị đổi kiểu.
 */
class RawRowsImport extends StringValueBinder implements SkipsEmptyRows, WithCalculatedFormulas, WithCustomValueBinder
{
    /**
     * Các dòng của sheet đầu tiên. Laravel Excel chép file vào thư mục tạm storage/framework/cache/laravel-excel
     * trước khi đọc; trên shared hosting bước này có thể hỏng (thư mục không ghi được...) dù file hoàn toàn hợp lệ,
     * nên khi lỗi thì đọc thẳng file upload bằng PhpSpreadsheet. Lỗi nào cũng được ghi log để tra nguyên nhân.
     */
    public static function firstSheet(UploadedFile $file): array
    {
        try {
            return self::ignoringOpenBasedirWarnings(fn () => Excel::toArray(new self, $file)[0] ?? []);
        } catch (\Throwable $e) {
            Log::warning('Laravel Excel không đọc được file nhập, thử đọc trực tiếp.', self::logContext($file, $e));
        }

        try {
            return self::ignoringOpenBasedirWarnings(fn () => self::readDirect($file));
        } catch (\Throwable $e) {
            Log::error('Không đọc được file nhập Excel/CSV.', self::logContext($file, $e));

            throw $e;
        }
    }

    /**
     * File xlsx do openpyxl / Google Sheets... xuất có đường dẫn sheet tuyệt đối ("/xl/worksheets/sheet1.xml");
     * PhpSpreadsheet gọi file_exists() trên đường dẫn đó → hosting bật open_basedir phát warning, Laravel đổi thành
     * ErrorException và cả file bị từ chối. Warning này vô hại (file_exists vẫn trả false, PhpSpreadsheet đọc tiếp
     * trong zip) nên bỏ qua riêng nó khi đọc; lỗi khác vẫn đi qua handler cũ.
     */
    public static function ignoringOpenBasedirWarnings(callable $read): mixed
    {
        $previous = null;
        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previous) {
            if (in_array($level, [E_WARNING, E_USER_WARNING], true) && str_contains($message, 'open_basedir restriction')) {
                return true;
            }

            return $previous ? $previous($level, $message, $file, $line) : false;
        });
        try {
            return $read();
        } finally {
            restore_error_handler();
        }
    }

    private static function readDirect(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $reader = IOFactory::createReader(match ($extension) {
            'xlsx' => IOFactory::READER_XLSX,
            'xls' => IOFactory::READER_XLS,
            default => IOFactory::READER_CSV,
        });
        if ($reader instanceof Csv) {
            $reader->setInputEncoding('UTF-8');
        }
        $reader->setReadDataOnly(true);

        $previousBinder = Cell::getValueBinder();
        Cell::setValueBinder(new StringValueBinder);
        try {
            $spreadsheet = $reader->load($file->getRealPath() ?: $file->getPathname());
            $rows = $spreadsheet->getSheet(0)->toArray(null, true, false, false);
            $spreadsheet->disconnectWorksheets();
        } finally {
            Cell::setValueBinder($previousBinder);
        }

        return array_values(array_filter($rows, fn (array $row) => collect($row)->contains(fn ($v) => $v !== null && $v !== '')));
    }

    private static function logContext(UploadedFile $file, \Throwable $e): array
    {
        return [
            'file' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'error' => get_class($e).': '.$e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
        ];
    }
}
