<?php

namespace App\Services\Syllabus\StageImport;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Đọc file syllabus 1 chặng theo form Sheet 1 (docs/kho-syllabus.md §3.3). Không ghi DB.
 *
 * Sheet được nhận diện bằng dòng tiêu đề có A = "Chặng" và B bắt đầu bằng "Buổi" (trong 20 dòng đầu). Cột được nhận
 * theo chữ tiêu đề, không theo vị trí — form của Học thuật thêm / bớt cột theo thời gian (vd. Link PPT GVNN). Dòng tiêu
 * đề phụ (Hoạt động gợi ý 1, Bài tập giấy...) có thể có hoặc không. Dòng ví dụ (cột A cùng màu nền với ô chú thích
 * "Dòng ví dụ") và dòng trống (chỉ có số buổi) được bỏ qua.
 */
class StageSheetReader
{
    private const HEADER_SCAN_ROWS = 20;

    /** Mọi thuộc tính dòng = null (cột không có trong file). */
    private const EMPTY_ROW = [
        'type' => null, 'unitCell' => null, 'unitObjectives' => null, 'content' => null, 'procedure' => null,
        'activity1' => null, 'activity2' => null, 'homeworkPaper' => null, 'homeworkQuizizz' => null, 'homeworkVideo' => null,
        'pptUrl' => null, 'quizizzUrl' => null, 'outputProduct' => null, 'milestone' => null, 'note' => null,
        'pptForeignUrl' => null, 'testUrl' => null,
    ];

    /** Cột chỉ có ở dòng tiêu đề phụ — nhận diện dòng đó (ô dữ liệu thường không bắt đầu bằng các tên này). */
    private const SUB_HEADER_FIELDS = ['activity1', 'activity2', 'homeworkPaper', 'homeworkQuizizz', 'homeworkVideo'];

    /** Cột link: ô có hyperlink → lấy URL của hyperlink (chữ hiển thị thường là tên file / "Link PPT"). */
    private const LINK_FIELDS = ['pptUrl', 'pptForeignUrl', 'quizizzUrl', 'testUrl'];

    /**
     * Tiêu đề cột (chữ thường, gộp khoảng trắng) → thuộc tính StageSheetRow; khớp tiền tố dài nhất ("link ppt gvnn"
     * trước "link ppt"). Cột không có trong danh sách (Chặng, Buổi, Chỉnh sửa thủ công...) bị bỏ qua.
     */
    private const HEADERS = [
        'loại buổi' => 'type',
        'unit' => 'unitCell',
        'objectives' => 'unitObjectives',
        'content' => 'content',
        'quy trình' => 'procedure',
        'hoạt động gợi ý 1' => 'activity1',
        'hoạt động gợi ý 2' => 'activity2',
        'bài tập giấy' => 'homeworkPaper',
        'bài tập quizizz' => 'homeworkQuizizz',
        'bài tập video' => 'homeworkVideo',
        'link ppt gvnn' => 'pptForeignUrl',
        'link ppt' => 'pptUrl',
        'link quizizz' => 'quizizzUrl',
        'link mini test' => 'testUrl',
        'link đề' => 'testUrl',
        'sản phẩm đầu ra' => 'outputProduct',
        'mốc thông báo' => 'milestone',
        'ghi chú' => 'note',
    ];

    public function read(string $path): StageSheet
    {
        try {
            $book = IOFactory::load($path);
        } catch (Throwable) {
            throw new StageImportException('Không đọc được file Excel — hãy dùng file .xlsx theo file mẫu.');
        }

        foreach ($book->getWorksheetIterator() as $sheet) {
            $headerRow = $this->headerRow($sheet);
            if ($headerRow !== null) {
                return $this->readSheet($sheet, $headerRow);
            }
        }

        throw new StageImportException('File không đúng form Syllabus mẫu (Sheet 1).');
    }

    private function headerRow(Worksheet $sheet): ?int
    {
        for ($row = 1; $row <= self::HEADER_SCAN_ROWS; $row++) {
            if ($this->text($sheet, 'A'.$row) === 'Chặng' && str_starts_with((string) $this->text($sheet, 'B'.$row), 'Buổi')) {
                return $row;
            }
        }

        return null;
    }

    private function readSheet(Worksheet $sheet, int $headerRow): StageSheet
    {
        [$columns, $firstLine] = $this->columns($sheet, $headerRow);
        if (! in_array('content', $columns, true) || ! in_array('unitCell', $columns, true)) {
            throw new StageImportException('File thiếu cột UNIT hoặc Content (nội dung từng tiết) ở dòng tiêu đề — hãy dùng form Syllabus mẫu.');
        }
        $exampleColor = $this->exampleColor($sheet, $headerRow);
        $rows = [];
        $skipped = 0;

        for ($line = $firstLine, $last = $sheet->getHighestDataRow(); $line <= $last; $line++) {
            $values = [];
            foreach ($columns as $column => $field) {
                $values[$field] = in_array($field, self::LINK_FIELDS, true) ? $this->link($sheet, $column.$line) : $this->text($sheet, $column.$line);
            }
            if (array_filter($values, fn ($v) => $v !== null) === []) {
                continue;
            }
            if ($exampleColor !== null && $this->fillColor($sheet, 'A'.$line) === $exampleColor) {
                $skipped++;

                continue;
            }
            $rows[] = new StageSheetRow(...array_merge(self::EMPTY_ROW, $values, ['line' => $line]));
        }

        return new StageSheet(
            curriculumLabel: (string) $this->labelValue($sheet, $headerRow, 'Chương trình'),
            stageName: (string) $this->labelValue($sheet, $headerRow, 'Chặng'),
            version: (string) $this->labelValue($sheet, $headerRow, 'Phiên bản'),
            rows: $rows,
            skippedExamples: $skipped,
        );
    }

    /**
     * Cột dữ liệu theo chữ tiêu đề; dòng ngay dưới là tiêu đề phụ khi có ô khớp tên cột (form cũ gộp ô "HOẠT ĐỘNG GỢI Ý"
     * và "HOMEWORK", tên cột con nằm ở dòng dưới) — khi đó dữ liệu bắt đầu sau dòng tiêu đề phụ.
     *
     * @return array{0: array<string, string>, 1: int} [cột => thuộc tính, dòng dữ liệu đầu tiên]
     */
    private function columns(Worksheet $sheet, int $headerRow): array
    {
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $header = [];
        $sub = [];
        for ($i = 1; $i <= $lastColumn; $i++) {
            $column = Coordinate::stringFromColumnIndex($i);
            $header[$column] = $this->field($this->text($sheet, $column.$headerRow));
            $sub[$column] = $this->field($this->text($sheet, $column.($headerRow + 1)));
        }
        $hasSubHeader = array_intersect($sub, self::SUB_HEADER_FIELDS) !== [] && $this->text($sheet, 'B'.($headerRow + 1)) === null;

        $columns = [];
        foreach ($header as $column => $field) {
            $field = ($hasSubHeader ? $sub[$column] : null) ?? $field;
            // Hai cột cùng nghĩa: giữ cột đầu tiên.
            if ($field !== null && ! in_array($field, $columns, true)) {
                $columns[$column] = $field;
            }
        }

        return [$columns, $headerRow + ($hasSubHeader ? 2 : 1)];
    }

    private function field(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }
        $title = Str::lower(trim(preg_replace('/\s+/u', ' ', $title)));
        $best = null;
        foreach (self::HEADERS as $prefix => $field) {
            if (str_starts_with($title, $prefix) && ($best === null || mb_strlen($prefix) > mb_strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? null : self::HEADERS[$best];
    }

    /** Giá trị của ô nhãn đầu sheet (cột A bắt đầu bằng $label): ô có giá trị đầu tiên từ B đến G. */
    private function labelValue(Worksheet $sheet, int $headerRow, string $label): ?string
    {
        for ($row = 1; $row < $headerRow; $row++) {
            if (! str_starts_with((string) $this->text($sheet, 'A'.$row), $label)) {
                continue;
            }
            foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $column) {
                if (($value = $this->text($sheet, $column.$row)) !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** Màu nền ô chú thích bên trái ô có chữ "Dòng ví dụ" (vùng đầu sheet). */
    private function exampleColor(Worksheet $sheet, int $headerRow): ?string
    {
        foreach ($sheet->getRowIterator(1, $headerRow - 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $column = $cell->getColumn();
                if ($column !== 'A' && str_contains((string) $this->text($sheet, $cell->getCoordinate()), 'Dòng ví dụ')) {
                    $swatch = chr(ord($column) - 1).$row->getRowIndex();

                    return $this->fillColor($sheet, $swatch);
                }
            }
        }

        return null;
    }

    private function fillColor(Worksheet $sheet, string $coordinate): ?string
    {
        $fill = $sheet->getStyle($coordinate)->getFill();

        return $fill->getFillType() === Fill::FILL_SOLID ? strtoupper($fill->getStartColor()->getRGB()) : null;
    }

    private function link(Worksheet $sheet, string $coordinate): ?string
    {
        if ($sheet->cellExists($coordinate) && ($url = trim($sheet->getCell($coordinate)->getHyperlink()->getUrl())) !== '') {
            return $url;
        }

        return $this->text($sheet, $coordinate);
    }

    private function text(Worksheet $sheet, string $coordinate): ?string
    {
        if (! $sheet->cellExists($coordinate)) {
            return null;
        }
        $value = $sheet->getCell($coordinate)->getValue();
        $value = $value instanceof RichText ? $value->getPlainText() : (string) $value;
        $value = trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }
}
