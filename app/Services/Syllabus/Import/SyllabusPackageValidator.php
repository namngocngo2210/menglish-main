<?php

namespace App\Services\Syllabus\Import;

use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Services\SafeUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Áp quy tắc nghiệp vụ (spec 3.1, 3.3, 3.4) lên gói thô do SyllabusPackageReader đọc và dựng cây import.
 * Báo mọi lỗi một lượt; chỉ đọc DB để kiểm tra mã trùng.
 */
class SyllabusPackageValidator
{
    public const MAX_LESSONS = 1000;

    public const MAX_UNIT_NUMBER = 500;

    private const DOCS_DIR = 'tai-lieu';

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    public function validate(SyllabusPackage $package, bool $canUpload): SyllabusImportPlan
    {
        if ($package->missing !== []) {
            return new SyllabusImportPlan(null, [], [], $package->missing, []);
        }

        $this->errors = [];
        $this->warnings = [];

        $curriculum = $this->curriculum($package->curriculumRows);
        $stages = $this->stages($package->stageRows);
        $this->lessons($package->lessonRows, $stages);
        $shared = $this->documents($package->files, $stages, $canUpload);
        if ($package->isZip) {
            foreach ($stages as $position => $stage) {
                if ($stage['documents'] === []) {
                    $this->warnings[] = 'Chặng '.$position.' chưa có tài liệu.';
                }
            }
        }

        if ($package->ignored !== []) {
            $this->warnings[] = 'Đã bỏ qua '.count($package->ignored).' file hệ thống / ngoài thư mục tai-lieu.';
        }

        $tree = [];
        foreach ($stages as $stage) {
            $units = $stage['units'];
            ksort($units);
            foreach ($units as &$unit) {
                ksort($unit['lessons']);
                $unit['lessons'] = array_values($unit['lessons']);
            }
            unset($unit);
            $stage['units'] = array_values($units);
            $tree[] = $stage;
        }

        return new SyllabusImportPlan($curriculum, $tree, $shared, $this->errors, $this->warnings);
    }

    /** @return array{code: string, title: string, version: string, description: ?string}|null */
    private function curriculum(array $rows): ?array
    {
        if (count($rows) !== 1) {
            $this->errors[] = 'Sheet Giáo trình: chỉ điền 1 dòng.';
        }

        $first = null;
        foreach ($rows as $line => $row) {
            $first ??= [$line, $row];
            $this->required($row['code'], 'Giáo trình', $line, 'Mã');
            $this->required($row['title'], 'Giáo trình', $line, 'Tên');
            $this->maxLength($row['code'], 50, 'Giáo trình', $line, 'Mã');
            $this->maxLength($row['title'], 255, 'Giáo trình', $line, 'Tên');
            $this->maxLength($row['version'], 20, 'Giáo trình', $line, 'Phiên bản');
            $this->maxLength($row['description'], 5000, 'Giáo trình', $line, 'Mô tả');
        }
        if ($first === null) {
            return null;
        }

        [$line, $row] = $first;
        $code = (string) $row['code'];
        // Kiểm cả giáo trình đã xoá vì unique index tính cả bản xoá mềm.
        if ($code !== '' && mb_strlen($code) <= 50 && SyllabusCurriculum::withTrashed()->where('code', $code)->exists()) {
            $this->errors[] = 'Mã '.$code.' đã tồn tại — đổi mã khác.';
        }

        return [
            'code' => $code,
            'title' => (string) $row['title'],
            'version' => $row['version'] ?? 'v1',
            'description' => $row['description'],
        ];
    }

    /**
     * @param  array<int, array<string, ?string>>  $rows
     * @return array<int, array<string, mixed>> chặng theo thứ tự
     */
    private function stages(array $rows): array
    {
        $stages = [];
        $linesByPosition = [];
        foreach ($rows as $line => $row) {
            $position = $this->integer($row['position'], 'Chặng', $line, 'Thứ tự');
            $this->required($row['name'], 'Chặng', $line, 'Tên chặng');
            $this->maxLength($row['name'], 255, 'Chặng', $line, 'Tên chặng');
            $this->maxLength($row['description'], 5000, 'Chặng', $line, 'Mô tả');
            $this->maxLength($row['big_test_title'], 255, 'Chặng', $line, 'Tên Big Test');
            $this->maxLength($row['big_test_note'], 5000, 'Chặng', $line, 'Ghi chú Big Test');
            if ($row['overview_link'] !== null) {
                if (mb_strlen($row['overview_link']) > 500) {
                    $this->errors[] = 'Sheet Chặng, dòng '.$line.': Link mục lục tối đa 500 ký tự.';
                } elseif (filter_var($row['overview_link'], FILTER_VALIDATE_URL) === false
                    || ! in_array(strtolower((string) parse_url($row['overview_link'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    $this->errors[] = 'Sheet Chặng, dòng '.$line.': Link mục lục không phải URL hợp lệ.';
                }
            }
            if ($position !== null && ($position < 1 || $position > self::MAX_LESSONS)) {
                $this->errors[] = 'Sheet Chặng, dòng '.$line.': Thứ tự phải từ 1 đến '.self::MAX_LESSONS.'.';
                $position = null;
            }
            if ($position === null) {
                continue;
            }
            $linesByPosition[$position][] = $line;
            $stages[$position] ??= [
                'position' => $position,
                'name' => (string) $row['name'],
                'description' => $row['description'],
                'big_test_title' => $row['big_test_title'],
                'big_test_note' => $row['big_test_note'],
                'overview_link' => $row['overview_link'],
                'units' => [],
                'documents' => [],
            ];
        }

        foreach ($linesByPosition as $position => $lines) {
            if (count($lines) > 1) {
                $this->errors[] = 'Sheet Chặng: thứ tự '.$position.' bị trùng (dòng '.implode(', ', $lines).').';
            }
        }
        if ($rows === []) {
            $this->errors[] = 'Sheet Chặng: chưa có chặng nào.';
        }
        $missing = $this->missingFromOne(array_keys($stages));
        if ($missing !== []) {
            $this->errors[] = 'Sheet Chặng: thứ tự phải liên tục từ 1 (thiếu '.implode(', ', $missing).').';
        }
        ksort($stages);

        return $stages;
    }

    /**
     * @param  array<int, array<string, ?string>>  $rows
     * @param  array<int, array<string, mixed>>  $stages  được bổ sung unit / buổi
     */
    private function lessons(array $rows, array &$stages): void
    {
        if (count($rows) > self::MAX_LESSONS) {
            $this->errors[] = 'Sheet Buổi: tối đa '.self::MAX_LESSONS.' buổi.';
        }

        $linesBySession = [];
        $stagesOfUnit = [];
        $namesOfUnit = [];
        $stagesWithLessons = [];
        foreach ($rows as $line => $row) {
            // Ô Chặng chấp nhận cả "1" lẫn "Chặng 1" (file mẫu ghi theo tên).
            $stageCell = $row['stage'] === null ? null : trim((string) preg_replace('/^chặng\\s*/iu', '', $row['stage']));
            $stageNo = $this->integer($stageCell === '' ? null : $stageCell, 'Buổi', $line, 'Chặng');
            $unitNo = $this->integer($row['unit_number'], 'Buổi', $line, 'Unit số');
            $sessionNo = $this->integer($row['session_no'], 'Buổi', $line, 'Buổi số');
            $this->required($row['unit_title'], 'Buổi', $line, 'Tên unit');
            $this->required($row['title'], 'Buổi', $line, 'Tên buổi');
            $this->maxLength($row['unit_title'], 255, 'Buổi', $line, 'Tên unit');
            $this->maxLength($row['title'], 255, 'Buổi', $line, 'Tên buổi');
            if ($unitNo !== null && ($unitNo < 1 || $unitNo > self::MAX_UNIT_NUMBER)) {
                $this->errors[] = 'Sheet Buổi, dòng '.$line.': Unit số phải từ 1 đến '.self::MAX_UNIT_NUMBER.'.';
                $unitNo = null;
            }
            if ($sessionNo !== null && ($sessionNo < 1 || $sessionNo > self::MAX_LESSONS)) {
                $this->errors[] = 'Sheet Buổi, dòng '.$line.': Buổi số phải từ 1 đến '.self::MAX_LESSONS.'.';
                $sessionNo = null;
            }
            if ($stageNo !== null && ! isset($stages[$stageNo])) {
                $this->errors[] = 'Sheet Buổi, dòng '.$line.': Chặng '.$stageNo.' không có trong sheet Chặng.';
                $stageNo = null;
            }
            if ($sessionNo !== null) {
                $linesBySession[$sessionNo][] = $line;
            }
            if ($unitNo !== null && $stageNo !== null) {
                $stagesOfUnit[$unitNo][$stageNo] = true;
            }
            if ($unitNo !== null && $row['unit_title'] !== null) {
                $namesOfUnit[$unitNo][$row['unit_title']] = true;
            }
            if ($stageNo === null || $unitNo === null || $sessionNo === null || $row['title'] === null) {
                continue;
            }

            $stagesWithLessons[$stageNo] = true;
            $unit = &$stages[$stageNo]['units'][$unitNo];
            $unit ??= ['unit_number' => $unitNo, 'title' => (string) $row['unit_title'], 'lessons' => []];
            // Trùng buổi số: giữ dòng đầu, lỗi đã báo ở dưới.
            $unit['lessons'][$sessionNo] ??= [
                'session_no' => $sessionNo,
                'title' => (string) $row['title'],
                'objectives' => $row['objectives'],
                'content' => $row['content'],
                'vocabulary_focus' => $row['vocabulary_focus'],
                'grammar_focus' => $row['grammar_focus'],
                'homework_guide' => $row['homework_guide'],
            ];
            unset($unit);
        }

        foreach ($linesBySession as $sessionNo => $lines) {
            if (count($lines) > 1) {
                $this->errors[] = 'Sheet Buổi: buổi số '.$sessionNo.' bị trùng (dòng '.implode(', ', $lines).').';
            }
        }
        $missing = $this->missingFromOne(array_keys($linesBySession));
        if ($missing !== []) {
            $this->errors[] = 'Sheet Buổi: thiếu buổi số '.implode(', ', $missing).'.';
        }
        ksort($stagesOfUnit);
        foreach ($stagesOfUnit as $unitNo => $set) {
            if (count($set) > 1) {
                $list = array_keys($set);
                sort($list);
                $this->errors[] = 'Sheet Buổi: Unit '.$unitNo.' nằm ở nhiều chặng ('.implode(', ', $list).').';
            }
        }
        ksort($namesOfUnit);
        foreach ($namesOfUnit as $unitNo => $set) {
            if (count($set) > 1) {
                $this->errors[] = 'Sheet Buổi: Unit '.$unitNo.' có nhiều tên khác nhau.';
            }
        }
        foreach ($stages as $position => $stage) {
            if (! isset($stagesWithLessons[$position])) {
                $this->errors[] = 'Chặng '.$position.' chưa có buổi nào.';
            }
        }
    }

    /**
     * Gắn tài liệu vào chặng theo thư mục; trả về tài liệu chung (gốc tai-lieu/).
     *
     * @param  list<PackageFile>  $files
     * @param  array<int, array<string, mixed>>  $stages
     * @return list<array{title: string, original_name: string, extension: string, size: int, local_path: string}>
     */
    private function documents(array $files, array &$stages, bool $canUpload): array
    {
        $shared = [];
        $reportedFolders = [];
        $renamed = [];
        foreach ($files as $file) {
            $segments = explode('/', $file->path);
            $depth = count($segments);
            if ($segments[0] !== self::DOCS_DIR || $depth < 2) {
                continue; // Reader chỉ trả file trong tai-lieu/; phòng thủ
            }

            $stageNo = null;
            if ($depth > 3) {
                $this->errors[] = $file->path.': chỉ đặt file trực tiếp trong thư mục chặng.';

                continue;
            }
            if ($depth === 3) {
                $folder = $segments[1];
                if (! preg_match('/^chang-(\d+)$/i', $folder, $m)) {
                    $this->folderError($reportedFolders, $folder, 'tai-lieu/'.$folder.'/: tên thư mục phải là chang-<thứ tự>.');

                    continue;
                }
                $stageNo = (int) $m[1];
                if (! isset($stages[$stageNo])) {
                    $this->folderError($reportedFolders, $folder, 'tai-lieu/'.$folder.'/: không có Chặng '.$stageNo.'.');

                    continue;
                }
            }

            $document = $this->document($file);
            if ($file->renamed) {
                $renamed[] = $file->path.': tên file không đọc được — đổi tên ở trang Tài liệu sau khi import.';
            }
            if ($document === null) {
                continue;
            }
            if ($stageNo === null) {
                $shared[] = $document;
            } else {
                $stages[$stageNo]['documents'][] = $document;
            }
        }

        if ($files !== [] && ! $canUpload) {
            $this->errors[] = 'Bạn không có quyền upload tài liệu — bỏ thư mục tai-lieu hoặc nhờ người có quyền.';
        }
        array_push($this->warnings, ...$renamed);

        return $shared;
    }

    /** @return array{title: string, original_name: string, extension: string, size: int, local_path: string}|null */
    private function document(PackageFile $file): ?array
    {
        if ($file->size > SyllabusDocument::MAX_KB * 1024) {
            $this->errors[] = $file->path.': quá '.(int) (SyllabusDocument::MAX_KB / 1024).' MB.';

            return null;
        }

        $name = basename($file->path);
        try {
            // Kiểm tra theo nội dung, không tin đuôi file.
            $extension = SafeUploadService::extensionFor(
                new UploadedFile($file->localPath, $name, null, null, true),
                SyllabusDocument::ALLOWED_EXTENSIONS
            );
        } catch (ValidationException) {
            $this->errors[] = $file->path.': định dạng không được phép.';

            return null;
        }

        // File tên ".pdf" không có phần tên: dùng cả tên file làm tiêu đề.
        $title = pathinfo($name, PATHINFO_FILENAME);

        return [
            'title' => mb_substr(trim($title) !== '' ? $title : $name, 0, 255),
            'original_name' => $name,
            'extension' => $extension,
            'size' => $file->size,
            'local_path' => $file->localPath,
        ];
    }

    /** @param  array<string, true>  $reported */
    private function folderError(array &$reported, string $folder, string $message): void
    {
        if (! isset($reported[$folder])) {
            $reported[$folder] = true;
            $this->errors[] = $message;
        }
    }

    /**
     * Số nguyên từ ô Excel: chấp nhận "3", "3.0", " 3 ", "03"; lỗi thì ghi vào errors và trả null.
     */
    private function integer(?string $value, string $sheet, int $line, string $label): ?int
    {
        if ($value === null) {
            $this->errors[] = 'Sheet '.$sheet.', dòng '.$line.': thiếu '.$label.'.';

            return null;
        }
        if (! preg_match('/^\d+(\.0+)?$/', $value) || strlen(explode('.', $value)[0]) > 9) {
            $this->errors[] = 'Sheet '.$sheet.', dòng '.$line.': '.$label.' phải là số nguyên.';

            return null;
        }

        return (int) $value;
    }

    private function required(?string $value, string $sheet, int $line, string $label): void
    {
        if ($value === null) {
            $this->errors[] = 'Sheet '.$sheet.', dòng '.$line.': thiếu '.$label.'.';
        }
    }

    private function maxLength(?string $value, int $max, string $sheet, int $line, string $label): void
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $this->errors[] = 'Sheet '.$sheet.', dòng '.$line.': '.$label.' tối đa '.$max.' ký tự.';
        }
    }

    /**
     * Các số thiếu trong dãy 1…max.
     *
     * @param  list<int>  $numbers
     * @return list<int>
     */
    private function missingFromOne(array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }
        $present = array_flip($numbers);
        $missing = [];
        for ($n = 1, $max = max($numbers); $n <= $max; $n++) {
            if (! isset($present[$n])) {
                $missing[] = $n;
            }
        }

        return $missing;
    }
}
