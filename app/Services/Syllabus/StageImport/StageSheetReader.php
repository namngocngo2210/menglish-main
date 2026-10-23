<?php

namespace App\Services\Syllabus\StageImport;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Đọc file syllabus 1 chặng theo form Sheet 1 (docs/kho-syllabus.md §3.3). Không ghi DB.
 *
 * Sheet được nhận diện bằng dòng tiêu đề có A = "Chặng" và B bắt đầu bằng "Buổi" (trong 20 dòng đầu); sau tiêu đề có
 * 1 dòng tiêu đề phụ, dữ liệu bắt đầu ngay sau. Dòng ví dụ (cột A cùng màu nền với ô chú thích "Dòng ví dụ") và dòng
 * trống (chỉ có số buổi) được bỏ qua.
 */
class StageSheetReader
{
    private const HEADER_SCAN_ROWS = 20;

    /** Cột link PPT / Quizizz: ô có hyperlink → lấy URL của hyperlink (chữ hiển thị thường là "Link PPT"). */
    private const LINK_COLUMNS = ['M', 'N'];

    /** Cột dữ liệu Sheet 1 → thuộc tính StageSheetRow (cột A Chặng, B Buổi, Q Chỉnh sửa thủ công: bỏ qua). */
    private const COLUMNS = [
        'C' => 'type', 'D' => 'unitCell', 'E' => 'unitObjectives', 'F' => 'content', 'G' => 'procedure',
        'H' => 'activity1', 'I' => 'activity2', 'J' => 'homeworkPaper', 'K' => 'homeworkQuizizz', 'L' => 'homeworkVideo',
        'M' => 'pptUrl', 'N' => 'quizizzUrl', 'O' => 'outputProduct', 'P' => 'milestone', 'R' => 'note',
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
        $exampleColor = $this->exampleColor($sheet, $headerRow);
        $rows = [];
        $skipped = 0;

        for ($line = $headerRow + 2, $last = $sheet->getHighestDataRow(); $line <= $last; $line++) {
            $values = [];
            foreach (self::COLUMNS as $column => $field) {
                $values[$field] = in_array($column, self::LINK_COLUMNS, true) ? $this->link($sheet, $column.$line) : $this->text($sheet, $column.$line);
            }
            if (array_filter($values, fn ($v) => $v !== null) === []) {
                continue;
            }
            if ($exampleColor !== null && $this->fillColor($sheet, 'A'.$line) === $exampleColor) {
                $skipped++;

                continue;
            }
            $rows[] = new StageSheetRow($line, ...$values);
        }

        return new StageSheet(
            curriculumLabel: (string) $this->labelValue($sheet, $headerRow, 'Chương trình'),
            stageName: (string) $this->labelValue($sheet, $headerRow, 'Chặng'),
            version: (string) $this->labelValue($sheet, $headerRow, 'Phiên bản'),
            rows: $rows,
            skippedExamples: $skipped,
        );
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
