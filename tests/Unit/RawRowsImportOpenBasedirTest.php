<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Hosting bật open_basedir + file xlsx có đường dẫn sheet tuyệt đối (openpyxl, Google Sheets...) → trước đây
 * "Không đọc được file ... open_basedir restriction in effect. File(/xl/worksheets/sheet1.xml)". Chạy trong tiến trình
 * PHP riêng vì open_basedir không nới lại được trong cùng tiến trình.
 */
class RawRowsImportOpenBasedirTest extends TestCase
{
    public function test_xlsx_with_absolute_sheet_path_is_read_under_open_basedir(): void
    {
        $base = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/rawrows-'.bin2hex(random_bytes(4));
        mkdir($tmp);
        $script = $tmp.'/run.php';
        file_put_contents($script, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$xlsx = Maatwebsite\Excel\Facades\Excel::raw(
    new App\Exports\ArrayExport(['Họ tên', 'Số điện thoại'], [['Nguyễn An', '0912345678']]),
    Maatwebsite\Excel\Excel::XLSX
);
$path = $argv[2].'/khach.xlsx';
file_put_contents($path, $xlsx);
// Giống file openpyxl: Target tuyệt đối "/xl/worksheets/sheet1.xml".
$zip = new ZipArchive;
$zip->open($path);
$rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
$zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('Target="worksheets/', 'Target="/xl/worksheets/', $rels));
$zip->close();

$rows = App\Imports\RawRowsImport::firstSheet(new Illuminate\Http\UploadedFile($path, 'khach.xlsx', null, null, true));
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
PHP);

        $process = new Process([
            (new PhpExecutableFinder)->find(false), '-d', 'open_basedir='.$base.PATH_SEPARATOR.$tmp.PATH_SEPARATOR.sys_get_temp_dir(),
            $script, $base, $tmp,
        ], $base, ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null']);
        $process->run();

        array_map('unlink', glob($tmp.'/*'));
        rmdir($tmp);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame([['Họ tên', 'Số điện thoại'], ['Nguyễn An', '0912345678']], json_decode($process->getOutput(), true));
    }
}
