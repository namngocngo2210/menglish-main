<?php

namespace App\Services\QuestionBank;

use App\Models\QuestionBankPassage;
use App\Models\QuestionBankQuestion;
use App\Services\SafeUploadService;
use App\Support\Practice\PracticeCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Throwable;
use UnexpectedValueException;
use ZipArchive;

/**
 * Import ngân hàng câu hỏi từ ZIP gồm data/*.json (định dạng file tách câu) + hinh/* (spec §5.1).
 * Chạy đồng bộ; dùng chung cho màn Import và lệnh question-bank:import.
 *
 * - Chỉ nhận câu trắc nghiệm đủ A–D, có đáp án A–D, không `thieu`, không phải kỹ năng Nghe; còn lại bỏ qua kèm lý do.
 * - Mỗi file một transaction; file hỏng / sai khối / sai kỳ → ghi vào errors, các file khác vẫn import.
 * - Đã có source_key: câu còn draft và chưa sửa → cập nhật; đã duyệt / ẩn / đã sửa → giữ nguyên.
 * - ZIP không an toàn (đường dẫn `..` / tuyệt đối, đuôi lạ, quá nhiều mục, quá dung lượng) → InvalidArgumentException.
 */
class QuestionBankImporter
{
    public const MAX_ENTRIES = 5000;

    public const MAX_UNCOMPRESSED_BYTES = 104857600; // 100 MB

    private const ALLOWED_EXTENSIONS = ['json', 'png', 'jpg', 'jpeg'];

    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg'];

    private const DATA_DIR = 'data';

    private const IMAGE_DIR = 'hinh';

    /** Kỹ năng không đưa vào ngân hàng (không có file nghe). */
    private const EXCLUDED_SKILL = 'Nghe';

    /** @var array<string, string> tên ảnh trong ZIP => đường dẫn đã lưu (một ảnh dùng nhiều lần chỉ lưu một lần) */
    private array $storedImages = [];

    /**
     * @throws InvalidArgumentException ZIP không đọc được / không an toàn / không có data/*.json
     */
    public function importZip(string $zipPath): ImportSummary
    {
        $this->storedImages = [];
        $workDir = rtrim(sys_get_temp_dir(), '/').'/question-bank-import-'.Str::random(20);

        try {
            $this->extract($zipPath, $workDir);
            $root = $this->locateRoot($workDir);
            $dataFiles = $this->dataFiles($root);

            $created = $updated = $kept = $skipped = 0;
            $errors = [];
            $skipReasons = [];
            foreach ($dataFiles as $file) {
                try {
                    $result = $this->importFile($file, $root.'/'.self::IMAGE_DIR);
                } catch (UnexpectedValueException $e) {
                    $errors[] = basename($file).': '.$e->getMessage();

                    continue;
                } catch (Throwable $e) {
                    report($e);
                    $errors[] = basename($file).': lỗi khi lưu dữ liệu ('.Str::limit($e->getMessage(), 200).').';

                    continue;
                }

                $created += $result['created'];
                $updated += $result['updated'];
                $kept += $result['kept'];
                $skipped += $result['skipped'];
                array_push($errors, ...$result['errors']);
                foreach ($result['skipReasons'] as $reason => $count) {
                    $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + $count;
                }
            }

            return new ImportSummary($created, $updated, $kept, $skipped, $errors, $skipReasons);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * @return array{created: int, updated: int, kept: int, skipped: int, errors: list<string>, skipReasons: array<string, int>}
     *
     * @throws UnexpectedValueException lỗi cả file
     */
    private function importFile(string $path, string $imageDir): array
    {
        $name = basename($path);
        [$source, $groups] = $this->parseFile($path);

        $fileNo = $source['file_no'];
        $common = [
            'grade' => $source['grade'],
            'term' => $source['term'],
            'source_school' => $this->optionalString($source['truong'] ?? null),
            'source_year' => $this->optionalString($source['nam_hoc'] ?? null),
            'source_file' => $this->optionalString($source['path'] ?? null),
        ];

        // Tính trước khoá của mọi câu / ngữ liệu để nạp bản ghi đã có bằng 2 truy vấn.
        $plan = [];
        $passageNo = 0;
        foreach ($groups as $group) {
            $passageContent = $this->optionalString($group['passage'] ?? null);
            $plan[] = [
                'group' => $group,
                'passage' => $passageContent,
                'passageKey' => $passageContent !== null ? sprintf('F%03d-NL%02d', $fileNo, ++$passageNo) : null,
            ];
        }
        $questionKeys = [];
        foreach ($groups as $group) {
            foreach ($group['questions'] as $question) {
                if (is_array($question) && $this->optionalString($question['no'] ?? null) !== null) {
                    $questionKeys[] = $this->questionKey($fileNo, $question['no']);
                }
            }
        }

        $result = DB::transaction(function () use ($plan, $common, $fileNo, $questionKeys, $imageDir, $name) {
            $existingQuestions = QuestionBankQuestion::query()->whereIn('source_key', $questionKeys)->get()->keyBy('source_key');
            $existingPassages = QuestionBankPassage::query()
                ->whereIn('source_key', array_filter(array_column($plan, 'passageKey')))
                ->get()->keyBy('source_key');

            $result = ['created' => 0, 'updated' => 0, 'kept' => 0, 'skipped' => 0, 'errors' => [], 'skipReasons' => [], 'replacedImages' => []];

            foreach ($plan as ['group' => $group, 'passage' => $passageContent, 'passageKey' => $passageKey]) {
                $groupFields = [
                    'skill' => $this->optionalString($group['skill'] ?? null) ?? '',
                    'type' => $this->optionalString($group['type'] ?? null) ?? '',
                    'instruction' => $this->optionalString($group['instruction'] ?? null),
                ];

                $accepted = [];
                foreach ($group['questions'] as $index => $question) {
                    $reason = $this->rejectionReason($question, $groupFields);
                    if ($reason !== null) {
                        $result['skipped']++;
                        $result['skipReasons'][$reason] = ($result['skipReasons'][$reason] ?? 0) + 1;

                        continue;
                    }
                    $accepted[$index + 1] = $question;
                }
                if ($accepted === []) {
                    continue; // ngữ liệu chỉ tạo khi có ít nhất 1 câu được nhận
                }

                $passageId = null;
                if ($passageKey !== null) {
                    $passageId = $this->upsertPassage($existingPassages->get($passageKey), $passageKey, $passageContent, $groupFields + $common);
                }

                foreach ($accepted as $position => $question) {
                    $key = $this->questionKey($fileNo, $question['no']);
                    $existing = $existingQuestions->get($key);
                    if ($existing && ($existing->status !== 'draft' || $existing->edited_at !== null)) {
                        $result['kept']++;

                        continue;
                    }

                    $imagePath = $this->imageFor($question, $imageDir, $name, $result['errors']);
                    if ($imagePath === null && $existing && $this->imageName($question) !== null) {
                        $imagePath = $existing->image_path; // ảnh mới thiếu / hỏng: giữ ảnh cũ
                    }

                    $attributes = $common + [
                        'passage_id' => $passageId,
                        'position' => $position,
                        'skill' => $groupFields['skill'],
                        'type' => $groupFields['type'],
                        'knowledge' => $this->knowledge($question['kien_thuc'] ?? null),
                        'instruction' => $groupFields['instruction'],
                        'stem' => $this->optionalString($question['stem'] ?? null) ?? '',
                        'options' => $this->normalizedOptions($question['options']),
                        'answer' => $question['answer'],
                        'image_path' => $imagePath,
                    ];

                    if ($existing) {
                        if ($existing->image_path !== null && $existing->image_path !== $imagePath) {
                            $result['replacedImages'][] = $existing->image_path;
                        }
                        $existing->update($attributes);
                        $result['updated']++;
                    } else {
                        QuestionBankQuestion::query()->create($attributes + ['source_key' => $key, 'status' => 'draft']);
                        $result['created']++;
                    }
                }
            }

            return $result;
        });

        // Sau commit: ảnh cũ bị thay không còn ai dùng thì xoá.
        $this->deleteUnusedImages($result['replacedImages']);
        unset($result['replacedImages']);

        return $result;
    }

    /**
     * Đọc + kiểm tra phần chung của file.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     *
     * @throws UnexpectedValueException
     */
    private function parseFile(string $path): array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('JSON không hợp lệ ('.$e->getMessage().').');
        }
        if (! is_array($data) || ! is_array($data['source'] ?? null) || ! is_array($data['groups'] ?? null)) {
            throw new UnexpectedValueException('thiếu "source" hoặc "groups".');
        }

        $source = $data['source'];
        $fileNo = filter_var($source['file_no'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($fileNo === false) {
            throw new UnexpectedValueException('"source.file_no" không hợp lệ.');
        }
        $grade = filter_var($source['khoi'] ?? null, FILTER_VALIDATE_INT);
        if ($grade === false || ! in_array($grade, PracticeCatalog::GRADES, true)) {
            throw new UnexpectedValueException('khối "'.$this->display($source['khoi'] ?? null).'" không thuộc khối 6–9.');
        }
        $term = PracticeCatalog::SOURCE_TERMS[$source['ky'] ?? ''] ?? null;
        if ($term === null) {
            throw new UnexpectedValueException('kỳ "'.$this->display($source['ky'] ?? null).'" không xác định (cần Giữa kì I, Cuối kì I, Giữa kì II hoặc Cuối kì II).');
        }

        $groups = array_values($data['groups']);
        foreach ($groups as $i => $group) {
            if (! is_array($group) || ! is_array($group['questions'] ?? null)) {
                throw new UnexpectedValueException('nhóm câu thứ '.($i + 1).' không hợp lệ.');
            }
            $groups[$i]['questions'] = array_values($group['questions']);
        }

        return [['file_no' => $fileNo, 'grade' => $grade, 'term' => $term] + $source, $groups];
    }

    /** Lý do bỏ qua câu, hoặc null nếu nhận. */
    private function rejectionReason(mixed $question, array $groupFields): ?string
    {
        if ($groupFields['skill'] === self::EXCLUDED_SKILL) {
            return 'Câu kỹ năng Nghe';
        }
        if (! is_array($question) || $this->optionalString($question['no'] ?? null) === null) {
            return 'Câu không có số thứ tự';
        }
        if ($groupFields['skill'] === '' || $groupFields['type'] === '') {
            return 'Nhóm câu thiếu kỹ năng / dạng bài';
        }
        if (($question['thieu'] ?? false) === true) {
            return 'Câu thiếu dữ liệu';
        }
        $options = $question['options'] ?? null;
        if (! is_array($options) || count($options) !== 4 || array_diff(['A', 'B', 'C', 'D'], array_keys($options)) !== []) {
            return 'Không đủ 4 phương án A–D';
        }
        foreach ($options as $option) {
            if (! is_scalar($option) || trim((string) $option) === '') {
                return 'Không đủ 4 phương án A–D';
            }
        }
        if (! in_array($question['answer'] ?? null, ['A', 'B', 'C', 'D'], true)) {
            return 'Đáp án không phải A–D';
        }
        if (isset($question['stem']) && ! is_scalar($question['stem'])) {
            return 'Đề câu hỏi không hợp lệ';
        }

        return null;
    }

    /** Tạo / cập nhật ngữ liệu; ngữ liệu có câu đã duyệt / ẩn / đã sửa thì giữ nguyên. Trả về id. */
    private function upsertPassage(?QuestionBankPassage $existing, string $key, string $content, array $fields): int
    {
        $attributes = [
            'grade' => $fields['grade'],
            'term' => $fields['term'],
            'skill' => $fields['skill'],
            'type' => $fields['type'],
            'instruction' => $fields['instruction'],
            'content' => $content,
            'source_school' => $fields['source_school'],
            'source_year' => $fields['source_year'],
            'source_file' => $fields['source_file'],
        ];

        if (! $existing) {
            return QuestionBankPassage::query()->create($attributes + ['source_key' => $key])->id;
        }

        $reviewed = QuestionBankQuestion::query()
            ->where('passage_id', $existing->id)
            ->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhereNotNull('edited_at'))
            ->exists();
        if (! $reviewed) {
            $existing->update($attributes);
        }

        return $existing->id;
    }

    /**
     * Lưu ảnh hinh/<anh> của câu (mỗi tên ảnh lưu một lần cho cả lần import). Thiếu / hỏng → ghi lỗi, trả null.
     *
     * @param  list<string>  $errors
     */
    private function imageFor(array $question, string $imageDir, string $fileName, array &$errors): ?string
    {
        $image = $this->imageName($question);
        if ($image === null) {
            return null;
        }
        if (isset($this->storedImages[$image])) {
            return $this->storedImages[$image];
        }

        $label = $fileName.': câu '.$question['no'];
        $local = $imageDir.'/'.$image;
        if (! $this->isSafeImageName($image) || ! is_file($local)) {
            $errors[] = $label.' thiếu ảnh '.self::IMAGE_DIR.'/'.$image.'.';

            return null;
        }

        try {
            $stored = SafeUploadService::store(
                new UploadedFile($local, $image, null, null, true),
                'question_bank/'.now()->format('Y/m'),
                SafeUploadService::IMAGES,
            );
        } catch (ValidationException) {
            $errors[] = $label.': '.self::IMAGE_DIR.'/'.$image.' không phải ảnh hợp lệ.';

            return null;
        }

        return $this->storedImages[$image] = $stored;
    }

    private function imageName(array $question): ?string
    {
        return $this->optionalString($question['anh'] ?? null);
    }

    private function isSafeImageName(string $image): bool
    {
        return basename($image) === $image
            && ! str_contains($image, '..')
            && ! str_contains($image, '\\')
            && in_array(strtolower(pathinfo($image, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    /** Xoá ảnh cũ bị thay khi cập nhật câu, nếu không còn câu / ngữ liệu nào dùng. */
    private function deleteUnusedImages(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            $used = QuestionBankQuestion::query()->where('image_path', $path)->exists()
                || QuestionBankPassage::query()->where('image_path', $path)->exists();
            if (! $used) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    /** @return array{A: string, B: string, C: string, D: string} */
    private function normalizedOptions(array $options): array
    {
        return [
            'A' => trim((string) $options['A']),
            'B' => trim((string) $options['B']),
            'C' => trim((string) $options['C']),
            'D' => trim((string) $options['D']),
        ];
    }

    private function knowledge(mixed $value): ?string
    {
        $value = $this->optionalString($value);

        return $value === null ? null : Str::limit($value, 250, '…');
    }

    private function questionKey(int $fileNo, mixed $no): string
    {
        return sprintf('F%03d-%s', $fileNo, trim((string) $no));
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function display(mixed $value): string
    {
        return is_scalar($value) ? Str::limit((string) $value, 50) : '';
    }

    // ---- ZIP ----

    /**
     * Kiểm tra mọi mục trước khi giải nén, rồi giải nén (đếm lại dung lượng thực phòng header khai gian).
     *
     * @throws InvalidArgumentException
     */
    private function extract(string $zipPath, string $workDir): void
    {
        $zip = new ZipArchive;
        if (! is_file($zipPath) || $zip->open($zipPath) !== true) {
            throw new InvalidArgumentException('Không đọc được file ZIP. Hãy kiểm tra lại file đã chọn.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new InvalidArgumentException('File ZIP có quá '.number_format(self::MAX_ENTRIES, 0, ',', '.').' mục.');
            }

            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new InvalidArgumentException('Không đọc được file ZIP. Hãy kiểm tra lại file đã chọn.');
                }
                $name = (string) $stat['name'];
                if ($this->isUnsafeEntryName($name)) {
                    throw new InvalidArgumentException('File ZIP có đường dẫn không hợp lệ: '.Str::limit($name, 100));
                }
                if (str_ends_with($name, '/') || $this->isJunk($name)) {
                    continue;
                }
                if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ALLOWED_EXTENSIONS, true)) {
                    throw new InvalidArgumentException('File ZIP chứa file không được phép: '.Str::limit($name, 100).' (chỉ nhận .json, .png, .jpg, .jpeg).');
                }
                if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                    throw new InvalidArgumentException('File ZIP có mật khẩu. Hãy nén lại không đặt mật khẩu.');
                }
                $entries[$i] = $name;
            }

            File::ensureDirectoryExists($workDir, 0700);
            $total = 0;
            foreach ($entries as $i => $name) {
                $this->extractEntry($zip, $i, $workDir.'/'.$name, $total);
            }
        } finally {
            $zip->close();
        }
    }

    private function isUnsafeEntryName(string $name): bool
    {
        return $name === ''
            || str_contains($name, '..')
            || str_starts_with($name, '/')
            || str_starts_with($name, '\\')
            || preg_match('/[:\x00-\x1f]/', $name) === 1;
    }

    /** File rác do hệ điều hành thêm khi nén (macOS / Windows). */
    private function isJunk(string $name): bool
    {
        $segments = explode('/', $name);
        $base = end($segments);

        return in_array('__MACOSX', $segments, true)
            || $base === '.DS_Store'
            || strcasecmp($base, 'Thumbs.db') === 0
            || str_starts_with($base, '._');
    }

    private function extractEntry(ZipArchive $zip, int $index, string $local, int &$total): void
    {
        File::ensureDirectoryExists(dirname($local), 0700);
        $in = $zip->getStreamIndex($index);
        $out = fopen($local, 'wb');
        if ($in === false || $out === false) {
            throw new InvalidArgumentException('Không giải nén được file ZIP.');
        }

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    throw new InvalidArgumentException('Không giải nén được file ZIP.');
                }
                $total += strlen($chunk);
                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new InvalidArgumentException('File ZIP quá '.(self::MAX_UNCOMPRESSED_BYTES / 1048576).' MB sau giải nén.');
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** Thư mục chứa data/: gốc ZIP, hoặc một thư mục bọc duy nhất (người dùng hay nén cả thư mục). */
    private function locateRoot(string $workDir): string
    {
        if (is_dir($workDir.'/'.self::DATA_DIR)) {
            return $workDir;
        }
        $children = File::directories($workDir);
        if (count($children) === 1 && is_dir($children[0].'/'.self::DATA_DIR)) {
            return $children[0];
        }

        throw new InvalidArgumentException('Không tìm thấy thư mục data/ chứa file .json trong ZIP.');
    }

    /** @return list<string> */
    private function dataFiles(string $root): array
    {
        $files = array_values(array_filter(
            File::files($root.'/'.self::DATA_DIR),
            fn ($file) => strtolower($file->getExtension()) === 'json',
        ));
        $paths = array_map(fn ($file) => $file->getPathname(), $files);
        if ($paths === []) {
            throw new InvalidArgumentException('Không tìm thấy file .json nào trong thư mục data/ của ZIP.');
        }
        natsort($paths);

        return array_values($paths);
    }
}
