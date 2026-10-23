<?php

namespace App\Services\Syllabus\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipArchive;

/**
 * Mở gói import (.zip hoặc .xlsx) một cách an toàn và đọc 3 sheet + danh sách tài liệu.
 * Không ghi DB; việc hiểu nội dung do SyllabusPackageValidator làm.
 */
class SyllabusPackageReader
{
    public const MAX_ENTRIES = 500;

    public const MAX_UNCOMPRESSED_BYTES = 209715200; // 200 MB

    public const SHEETS = ['curriculum' => 'Giáo trình', 'stages' => 'Chặng', 'lessons' => 'Buổi'];

    public const CURRICULUM_COLUMNS = ['code' => 'Mã', 'title' => 'Tên', 'version' => 'Phiên bản', 'description' => 'Mô tả'];

    public const STAGE_COLUMNS = [
        'position' => 'Thứ tự', 'name' => 'Tên chặng', 'description' => 'Mô tả',
        'big_test_title' => 'Tên Big Test', 'big_test_note' => 'Ghi chú Big Test', 'overview_link' => 'Link mục lục',
    ];

    public const LESSON_COLUMNS = [
        'stage' => 'Chặng', 'unit_number' => 'Unit số', 'unit_title' => 'Tên unit', 'session_no' => 'Buổi số',
        'title' => 'Tên buổi', 'objectives' => 'Mục tiêu', 'content' => 'Hoạt động',
        'vocabulary_focus' => 'Từ vựng', 'grammar_focus' => 'Ngữ pháp', 'homework_guide' => 'BTVN',
    ];

    public const REQUIRED = [
        'curriculum' => ['code', 'title'],
        'stages' => ['position', 'name'],
        'lessons' => ['stage', 'unit_number', 'unit_title', 'session_no', 'title'],
    ];

    private const DOCS_DIR = 'tai-lieu';

    /**
     * @param  string  $path  file .xlsx hoặc .zip
     * @param  string  $workDir  nơi giải nén zip (bỏ qua giải nén nếu đã có file: trang xem trước đọc lại nhiều lần)
     */
    public function read(string $path, string $workDir): SyllabusPackage
    {
        $isZip = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip';
        $files = [];
        $ignored = [];
        $xlsxPath = $path;

        if ($isZip) {
            [$xlsxPath, $files, $ignored] = $this->openZip($path, $workDir);
        }

        $workbook = $this->loadWorkbook($xlsxPath);
        $missing = [];
        $tables = [];
        $columnSets = [
            'curriculum' => self::CURRICULUM_COLUMNS,
            'stages' => self::STAGE_COLUMNS,
            'lessons' => self::LESSON_COLUMNS,
        ];

        foreach (self::SHEETS as $key => $title) {
            $sheet = $this->findSheet($workbook, $title);
            if ($sheet === null) {
                $missing[] = 'Thiếu sheet "'.$title.'".';
                $tables[$key] = [];

                continue;
            }
            [$tables[$key], $absent] = $this->readSheet($sheet, $columnSets[$key], self::REQUIRED[$key]);
            foreach ($absent as $label) {
                $missing[] = 'Sheet '.$title.': thiếu cột "'.$label.'".';
            }
        }
        $workbook->disconnectWorksheets();

        return new SyllabusPackage($tables['curriculum'], $tables['stages'], $tables['lessons'], $files, $ignored, $missing, $isZip);
    }

    private function loadWorkbook(string $xlsxPath): Spreadsheet
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);

            return $reader->load($xlsxPath);
        } catch (\Throwable) {
            throw new SyllabusPackageException('Không đọc được file syllabus.xlsx. Hãy dùng đúng file mẫu (định dạng .xlsx).');
        }
    }

    private function findSheet(Spreadsheet $workbook, string $title): ?Worksheet
    {
        $want = mb_strtolower(trim($title));
        foreach ($workbook->getWorksheetIterator() as $sheet) {
            if (mb_strtolower(trim($sheet->getTitle())) === $want) {
                return $sheet;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $columns  khoá => tiêu đề cột
     * @param  list<string>  $required
     * @return array{0: array<int, array<string, ?string>>, 1: list<string>} [dòng theo số dòng Excel, tiêu đề cột bắt buộc còn thiếu]
     */
    private function readSheet(Worksheet $sheet, array $columns, array $required): array
    {
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $maxRow = $sheet->getHighestDataRow();
        // Một ô lạc ở cuối sheet (dòng ~1 triệu) sẽ buộc dựng hàng triệu ô: chặn trước khi duyệt.
        if ($maxRow > SyllabusPackageValidator::MAX_LESSONS + 10) {
            throw new SyllabusPackageException('Sheet '.$sheet->getTitle().': quá nhiều dòng (tối đa '.SyllabusPackageValidator::MAX_LESSONS.' buổi).');
        }

        $byHeader = [];
        foreach ($columns as $key => $label) {
            $byHeader[$this->normalizeHeader($label)] = $key;
        }
        $colOf = [];
        for ($c = 1; $c <= $maxCol; $c++) {
            $key = $byHeader[$this->normalizeHeader((string) $sheet->getCell([$c, 1])->getValue())] ?? null;
            if ($key !== null && ! isset($colOf[$key])) {
                $colOf[$key] = $c;
            }
        }

        $absent = [];
        foreach ($required as $key) {
            if (! isset($colOf[$key])) {
                $absent[] = $columns[$key];
            }
        }
        if ($absent !== []) {
            return [[], $absent];
        }

        $rows = [];
        for ($r = 2; $r <= $maxRow; $r++) {
            $row = [];
            $any = false;
            foreach ($columns as $key => $label) {
                $value = null;
                if (isset($colOf[$key])) {
                    $cell = $sheet->getCell([$colOf[$key], $r]);
                    // Công thức: dùng giá trị Excel đã lưu, không tính lại (công thức lỗi sẽ ném exception).
                    $value = trim((string) ($cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getFormattedValue()));
                    $value = $value === '' ? null : $value;
                }
                $any = $any || $value !== null;
                $row[$key] = $value;
            }
            if ($any) {
                $rows[$r] = $row;
            }
        }

        return [$rows, []];
    }

    private function normalizeHeader(string $header): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($header)));
    }

    /**
     * Kiểm tra toàn bộ entry trước khi giải nén, rồi giải nén từng file hợp lệ.
     *
     * @return array{0: string, 1: list<PackageFile>, 2: list<string>} [đường dẫn xlsx đã giải nén, tài liệu, file bị bỏ qua]
     */
    private function openZip(string $path, string $workDir): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new SyllabusPackageException('Không đọc được file zip. Hãy kiểm tra lại file đã tải lên.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new SyllabusPackageException('File zip có quá '.self::MAX_ENTRIES.' file.');
            }

            // $entries: chỉ số trong zip => [đoạn đường dẫn đã chuẩn hoá, tên gốc bị chuyển mã?]
            $entries = [];
            $ignored = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
                if ($stat === false) {
                    throw new SyllabusPackageException('Không đọc được file zip. Hãy kiểm tra lại file đã tải lên.');
                }
                if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                    throw new SyllabusPackageException('File zip có mật khẩu. Hãy nén lại không đặt mật khẩu.');
                }

                $name = (string) $stat['name'];
                $renamed = false;
                if (! mb_check_encoding($name, 'UTF-8')) {
                    $name = $this->toUtf8($name);
                    $renamed = true;
                }
                if ($this->isUnsafeName($name)) {
                    throw new SyllabusPackageException('File zip có đường dẫn không hợp lệ: '.mb_substr($name, 0, 100));
                }
                if (str_ends_with($name, '/')) {
                    continue; // thư mục
                }

                $total += $this->declaredSize($stat);
                if ($total > $this->maxUncompressedBytes()) {
                    throw $this->tooLarge();
                }

                $segments = array_values(array_filter(explode('/', $name), fn (string $s) => $s !== '' && $s !== '.'));
                if ($this->isJunk($segments)) {
                    $ignored[] = implode('/', $segments);

                    continue;
                }
                $entries[$i] = [$segments, $renamed];
            }

            $entries = $this->stripWrappingFolder($entries);

            // Hai entry chuẩn hoá về cùng một đường dẫn sẽ đè lên nhau trên đĩa.
            $seen = [];
            foreach ($entries as [$segments]) {
                $relative = implode('/', $segments);
                if (isset($seen[$relative])) {
                    throw new SyllabusPackageException('File zip có hai file trùng đường dẫn: '.mb_substr($relative, 0, 100));
                }
                $seen[$relative] = true;
            }

            $xlsx = [];
            foreach ($entries as $i => [$segments]) {
                if (count($segments) === 1 && strtolower(pathinfo($segments[0], PATHINFO_EXTENSION)) === 'xlsx') {
                    $xlsx[] = $i;
                }
            }
            if (count($xlsx) === 0) {
                throw new SyllabusPackageException('Không tìm thấy file syllabus.xlsx ở thư mục gốc của file zip.');
            }
            if (count($xlsx) > 1) {
                throw new SyllabusPackageException('Ở thư mục gốc của file zip chỉ được có 1 file .xlsx.');
            }

            $files = [];
            $xlsxPath = '';
            $extract = $this->workDirIsEmpty($workDir);
            $written = [];
            $realTotal = 0;
            try {
                foreach ($entries as $i => [$segments, $renamed]) {
                    $relative = implode('/', $segments);
                    $isXlsx = $i === $xlsx[0];
                    $isDoc = $segments[0] === self::DOCS_DIR && count($segments) > 1;
                    if (! $isXlsx && ! $isDoc) {
                        $ignored[] = $relative;

                        continue;
                    }
                    $local = rtrim($workDir, '/').'/'.$relative;
                    $size = $extract ? $this->extractEntry($zip, $i, $local, $realTotal, $written) : (is_file($local) ? (int) filesize($local) : 0);
                    if ($isXlsx) {
                        $xlsxPath = $local;
                    } else {
                        $files[] = new PackageFile($relative, $local, $size, $renamed);
                    }
                }
            } catch (SyllabusPackageException $e) {
                // Không để lại file dở dang của lần đọc lỗi.
                foreach ($written as $file) {
                    @unlink($file);
                }
                throw $e;
            }
        } finally {
            $zip->close();
        }

        return [$xlsxPath, $files, $ignored];
    }

    /** Tên entry không phải UTF-8 thường là CP437 (zip cũ / Windows); mbstring không có CP437 nên dùng iconv. */
    private function toUtf8(string $name): string
    {
        $converted = function_exists('iconv') ? @iconv('CP437', 'UTF-8//IGNORE', $name) : false;

        return $converted !== false && $converted !== '' ? $converted : mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
    }

    /** Giới hạn dung lượng giải nén; tách ra để test hạ thấp được. */
    protected function maxUncompressedBytes(): int
    {
        return self::MAX_UNCOMPRESSED_BYTES;
    }

    /** Dung lượng entry theo header zip (header có thể khai gian: extractEntry đếm lại byte thực). */
    protected function declaredSize(array $stat): int
    {
        return (int) $stat['size'];
    }

    private function tooLarge(): SyllabusPackageException
    {
        return new SyllabusPackageException('File zip quá '.(int) ($this->maxUncompressedBytes() / 1048576).' MB sau giải nén.');
    }

    private function isUnsafeName(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '/') || preg_match('/[\\\\:\x00-\x1f]/', $name)) {
            return true;
        }

        return in_array('..', explode('/', $name), true);
    }

    /** @param  list<string>  $segments */
    private function isJunk(array $segments): bool
    {
        if ($segments === [] || in_array('__MACOSX', $segments, true)) {
            return true;
        }
        $base = end($segments);

        return $base === '.DS_Store' || strcasecmp($base, 'Thumbs.db') === 0 || str_starts_with($base, '._')
            || str_starts_with($base, '~$'); // file khoá của Office khi đang mở
    }

    /**
     * Người dùng hay nén cả thư mục nên mọi file nằm dưới một thư mục bọc: bỏ thư mục đó
     * (trừ khi gốc đã có file hoặc thư mục đó chính là tai-lieu/).
     *
     * @param  array<int, array{0: list<string>, 1: bool}>  $entries
     * @return array<int, array{0: list<string>, 1: bool}>
     */
    private function stripWrappingFolder(array $entries): array
    {
        if ($entries === []) {
            return $entries;
        }
        $roots = [];
        foreach ($entries as [$segments]) {
            if (count($segments) === 1) {
                return $entries; // có file nằm thẳng ở gốc: không có thư mục bọc
            }
            $roots[$segments[0]] = true;
        }
        if (count($roots) !== 1 || isset($roots[self::DOCS_DIR])) {
            return $entries;
        }

        foreach ($entries as $i => [$segments, $renamed]) {
            $entries[$i] = [array_slice($segments, 1), $renamed];
        }

        return $entries;
    }

    private function workDirIsEmpty(string $workDir): bool
    {
        if (! is_dir($workDir)) {
            return true;
        }

        return count(array_diff(scandir($workDir) ?: [], ['.', '..'])) === 0;
    }

    /** Ghi một entry ra đĩa; đếm lại dung lượng thực tế phòng header khai gian. */
    private function extractEntry(ZipArchive $zip, int $index, string $local, int &$realTotal, array &$written): int
    {
        $dir = dirname($local);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new SyllabusPackageException('Không giải nén được file zip (không ghi được thư mục tạm).');
        }
        $in = $zip->getStreamIndex($index);
        $out = fopen($local, 'wb');
        if ($in === false || $out === false) {
            throw new SyllabusPackageException('Không giải nén được file zip.');
        }

        $written[] = $local;
        $size = 0;
        try {
            while (! feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    throw new SyllabusPackageException('Không giải nén được file zip.');
                }
                $size += strlen($chunk);
                $realTotal += strlen($chunk);
                if ($realTotal > $this->maxUncompressedBytes()) {
                    throw $this->tooLarge();
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $size;
    }
}
