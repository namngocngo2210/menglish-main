<?php

namespace App\Services\QuestionBank;

use App\Models\PracticeExam;
use App\Models\QuestionBankPassage;
use App\Models\QuestionBankQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;
use UnexpectedValueException;
use ZipArchive;

/**
 * Import đề cố định (docs/luyen-tap-ngan-hang-cau-hoi.md §11.2) từ file Excel đề kèm đáp án (mỗi sheet một mã đề) và
 * file Word của đề (để lấy gạch chân — Excel làm mất gạch chân câu Phát âm). Nhận file .xlsx / .docx lẻ hoặc ZIP chứa
 * chúng (nén nguyên thư mục như "Đề GK Cấp 2/Khối 6/…").
 *
 * - Khối, kỳ, mã đề, thời gian lấy từ tiêu đề sheet ("… GIỮA HỌC KÌ I … TIẾNG ANH 6 – MÃ ĐỀ 601", "Thời gian làm bài: 60 phút").
 * - Dòng "Câu N" đủ A–D + đáp án → câu hỏi; dòng chỉ có cột đầu: hướng dẫn ("Mark the letter…", "Read the following…"),
 *   ngữ liệu chung của các câu sau, hoặc "Câu N: …" (phần đề tách riêng của câu N, mẫu khối 8).
 * - Đề mới / đề còn Nháp → ghi (thay toàn bộ câu); đề đã mở / đã ẩn → giữ nguyên (không ghi đè đề học viên đang làm).
 * - Câu của đề gắn `exam_id`, không vào kho trộn đề.
 */
class PracticeExamImporter
{
    public const MAX_ENTRIES = 300;

    public const MAX_UNCOMPRESSED_BYTES = 52428800; // 50 MB

    private const EXTENSIONS = ['xlsx', 'docx'];

    private const OPTION_KEYS = ['A', 'B', 'C', 'D'];

    /** Thời gian mặc định khi tiêu đề không ghi. */
    private const DEFAULT_DURATION = 60;

    private const TERMS = [
        'GIỮA HỌC KÌ I' => 'gk1',
        'CUỐI HỌC KÌ I' => 'ck1',
        'GIỮA HỌC KÌ II' => 'gk2',
        'CUỐI HỌC KÌ II' => 'ck2',
    ];

    /**
     * Dạng bài trong file → kỹ năng trong danh mục (PracticeCatalog::SKILLS); khớp tiền tố, theo thứ tự.
     * Dạng không khớp → "Đọc hiểu" nếu bắt đầu bằng "Đọc", ngược lại "Từ vựng & Ngữ pháp".
     */
    private const SKILL_RULES = [
        'Phát âm' => 'Ngữ âm',
        'Trọng âm' => 'Ngữ âm',
        'Ngữ âm' => 'Ngữ âm',
        'Đọc – Biển báo' => 'Giao tiếp & Biển báo',
        'Đọc – Tin nhắn' => 'Giao tiếp & Biển báo',
        'Đọc – Thông báo' => 'Giao tiếp & Biển báo',
        'Biển báo' => 'Giao tiếp & Biển báo',
        'Giao tiếp' => 'Giao tiếp & Biển báo',
        'Hội thoại' => 'Giao tiếp & Biển báo',
        'Đọc' => 'Đọc hiểu',
        'Viết' => 'Viết',
    ];

    /** Dòng hướng dẫn (không phải ngữ liệu). */
    private const INSTRUCTION_PATTERN = '/^(Mark the letter|Read the following|Choose the|Put the sentences|Listen)/iu';

    /**
     * @param  array<int, array{path: string, name: string}>  $files  file đã tải lên (.zip / .xlsx / .docx)
     *
     * @throws InvalidArgumentException file không đọc được / không an toàn / không có Excel đề
     */
    public function import(array $files): ExamImportSummary
    {
        $workDir = rtrim(sys_get_temp_dir(), '/').'/practice-exam-import-'.Str::random(20);

        try {
            File::ensureDirectoryExists($workDir, 0700);
            [$sheets, $docs] = $this->collect($files, $workDir);
            if ($sheets === []) {
                throw new InvalidArgumentException('Không tìm thấy file Excel (.xlsx) đề kèm đáp án.');
            }

            $underlines = [];
            foreach ($docs as $doc) {
                try {
                    $parsed = $this->parseDocx($doc['path']);
                } catch (Throwable) {
                    continue; // file Word chỉ để lấy gạch chân; đọc lỗi thì dùng chữ trong Excel
                }
                if ($parsed['code'] !== null) {
                    $underlines[$parsed['code']] = $parsed['questions'];
                }
            }

            $summary = new ExamImportSummary;
            foreach ($sheets as $sheet) {
                try {
                    $exam = $this->parseSheet($sheet['rows'], $sheet['title']);
                } catch (UnexpectedValueException $e) {
                    $summary->errors[] = "{$sheet['file']} / {$sheet['title']}: {$e->getMessage()}";

                    continue;
                }
                $exam['source_file'] = $sheet['file'];
                $this->applyUnderlines($exam, $underlines[$exam['code']] ?? null);
                $this->save($exam, $summary);
            }

            return $summary;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    // ---- Thu thập file ----

    /**
     * @return array{0: list<array{file: string, title: string, rows: list<list<mixed>>}>, 1: list<array{path: string, name: string}>}
     */
    private function collect(array $files, string $workDir): array
    {
        $local = [];
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext === 'zip') {
                array_push($local, ...$this->extract($file['path'], $workDir));
            } elseif (in_array($ext, self::EXTENSIONS, true)) {
                $local[] = ['path' => $file['path'], 'name' => basename($file['name'])];
            } else {
                throw new InvalidArgumentException('Chỉ nhận file .zip, .xlsx, .docx: '.Str::limit($file['name'], 100));
            }
        }

        $sheets = [];
        $docs = [];
        foreach ($local as $file) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext === 'docx') {
                $docs[] = $file;

                continue;
            }
            try {
                $reader = IOFactory::createReader('Xlsx');
                $reader->setReadDataOnly(true);
                $book = $reader->load($file['path']);
            } catch (Throwable) {
                throw new InvalidArgumentException('Không đọc được file Excel: '.Str::limit($file['name'], 100));
            }
            foreach ($book->getWorksheetIterator() as $worksheet) {
                $sheets[] = [
                    'file' => $file['name'],
                    'title' => $worksheet->getTitle(),
                    'rows' => $worksheet->toArray(null, true, false, false),
                ];
            }
            $book->disconnectWorksheets();
        }

        return [$sheets, $docs];
    }

    /**
     * Giải nén các file .xlsx / .docx trong ZIP (bỏ file rác, file khác đuôi); chặn đường dẫn `..` / tuyệt đối, ZIP bom.
     *
     * @return list<array{path: string, name: string}>
     */
    private function extract(string $zipPath, string $workDir): array
    {
        $zip = new ZipArchive;
        if (! is_file($zipPath) || $zip->open($zipPath) !== true) {
            throw new InvalidArgumentException('Không đọc được file ZIP. Hãy kiểm tra lại file đã chọn.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new InvalidArgumentException('File ZIP có quá '.self::MAX_ENTRIES.' mục.');
            }
            $out = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                    throw new InvalidArgumentException('File ZIP có đường dẫn không hợp lệ.');
                }
                $base = basename(str_replace('\\', '/', $name));
                if (str_ends_with($name, '/') || str_contains($name, '__MACOSX') || str_starts_with($base, '.') || str_starts_with($base, '~$')
                    || ! in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                    continue;
                }
                if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                    throw new InvalidArgumentException('File ZIP có mật khẩu. Hãy nén lại không đặt mật khẩu.');
                }
                $total += (int) $stat['size'];
                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new InvalidArgumentException('File ZIP quá '.(self::MAX_UNCOMPRESSED_BYTES / 1048576).' MB sau giải nén.');
                }
                $content = $zip->getFromIndex($i, self::MAX_UNCOMPRESSED_BYTES);
                if ($content === false || strlen($content) > (int) $stat['size']) {
                    throw new InvalidArgumentException('Không giải nén được file ZIP.');
                }
                $path = $workDir.'/'.count($out).'.'.strtolower(pathinfo($base, PATHINFO_EXTENSION));
                file_put_contents($path, $content);
                $out[] = ['path' => $path, 'name' => $this->utf8($base)];
            }

            return $out;
        } finally {
            $zip->close();
        }
    }

    // ---- Excel ----

    /**
     * Đọc một sheet đề.
     *
     * @param  list<list<mixed>>  $rows
     * @return array{grade: int, term: string, code: string, title: string, duration: int, warnings: list<string>,
     *     passages: array<int, array{instruction: ?string, content: string}>,
     *     questions: list<array{no: int, type: string, skill: string, instruction: ?string, passage: ?int, stem: string, options: array<string, string>, answer: string}>}
     *
     * @throws UnexpectedValueException
     */
    public function parseSheet(array $rows, string $sheetTitle = ''): array
    {
        $title = null;
        $duration = null;
        $instruction = null;
        $passage = null;
        $passages = [];
        $preambles = [];
        $questions = [];
        $key = [];
        $inKey = false;
        $warnings = [];

        foreach ($rows as $row) {
            $cells = array_map(fn ($v) => $this->cell($v), array_pad(array_values($row), 8, null));
            $first = $cells[0];
            $rest = array_filter(array_slice($cells, 1), fn ($v) => $v !== '');
            if ($first === '' && $rest === []) {
                continue;
            }

            if ($inKey) {
                foreach ($cells as $value) {
                    if (preg_match('/^(\d+)\s*[.:)]\s*([A-D])$/u', $value, $m)) {
                        $key[(int) $m[1]] = $m[2];
                    }
                }

                continue;
            }
            if ($title === null && str_contains(mb_strtoupper($first), 'ĐỀ KIỂM TRA')) {
                $title = $first;

                continue;
            }
            if ($duration === null && preg_match('/Thời gian làm bài:\s*(\d+)\s*phút/iu', $first, $m)) {
                $duration = (int) $m[1];

                continue;
            }
            if (preg_match('/^ĐÁP ÁN/u', mb_strtoupper($first))) {
                $inKey = true;

                continue;
            }
            if ($first === 'Câu' && ($cells[1] ?? '') !== '') {
                continue; // dòng tiêu đề cột
            }

            if (preg_match('/^Câu\s+(\d+)$/u', $first, $m)) {
                $no = (int) $m[1];
                $options = [];
                foreach (self::OPTION_KEYS as $i => $letter) {
                    $options[$letter] = $this->stripOptionLetter($cells[3 + $i], $letter);
                }
                $answer = mb_strtoupper($cells[7]);
                if (in_array('', $options, true) || ! in_array($answer, self::OPTION_KEYS, true)) {
                    throw new UnexpectedValueException("Câu {$no} thiếu phương án A–D hoặc đáp án.");
                }
                $stem = $cells[2];
                if (isset($preambles[$no])) {
                    $stem = trim($stem."\n\n".$preambles[$no]);
                }
                $type = $cells[1] !== '' ? $cells[1] : 'Khác';
                $questions[] = [
                    'no' => $no,
                    'type' => Str::limit($type, 250, ''),
                    'skill' => $this->skillFor($type),
                    'instruction' => $instruction,
                    'passage' => $passage,
                    'stem' => $stem,
                    'options' => $options,
                    'answer' => $answer,
                ];

                continue;
            }

            if ($rest !== []) {
                continue; // dòng lạ có nhiều cột: bỏ qua
            }
            if (preg_match('/^Câu\s+(\d+)\s*[:.]\s*(.+)$/su', $first, $m)) {
                $preambles[(int) $m[1]] = trim($m[2]);
            } elseif (preg_match(self::INSTRUCTION_PATTERN, $first)) {
                $instruction = $first;
                $passage = null;
            } else {
                $passages[] = ['instruction' => $instruction, 'content' => $first];
                $passage = array_key_last($passages);
            }
        }

        if ($title === null) {
            throw new UnexpectedValueException('Không thấy tiêu đề "ĐỀ KIỂM TRA …" ở đầu sheet.');
        }
        $upper = mb_strtoupper($title);
        $grade = preg_match('/TIẾNG ANH\s*(\d{1,2})/u', $upper, $m) ? (int) $m[1] : null;
        $code = preg_match('/MÃ ĐỀ\s*[:.]?\s*([0-9A-Z]+)/u', $upper, $m) ? $m[1]
            : (preg_match('/(\d{3,})/', $sheetTitle, $m) ? $m[1] : null);
        $term = null;
        // kì II trước kì I ("HỌC KÌ II" chứa "HỌC KÌ I")
        foreach (['GIỮA HỌC KÌ II', 'CUỐI HỌC KÌ II', 'GIỮA HỌC KÌ I', 'CUỐI HỌC KÌ I'] as $label) {
            if (str_contains(str_replace(['KỲ', 'KÌ'], 'KÌ', $upper), $label)) {
                $term = self::TERMS[$label];
                break;
            }
        }
        if ($grade === null || ! in_array($grade, [6, 7, 8, 9], true)) {
            throw new UnexpectedValueException('Không xác định được khối 6–9 từ tiêu đề ("TIẾNG ANH 6"…).');
        }
        if ($term === null) {
            throw new UnexpectedValueException('Không xác định được kỳ từ tiêu đề ("GIỮA HỌC KÌ I"…).');
        }
        if ($code === null) {
            throw new UnexpectedValueException('Không xác định được mã đề ("MÃ ĐỀ 601").');
        }
        if ($questions === []) {
            throw new UnexpectedValueException('Không có câu hỏi nào.');
        }

        usort($questions, fn ($a, $b) => $a['no'] <=> $b['no']);
        $numbers = array_column($questions, 'no');
        if (count(array_unique($numbers)) !== count($numbers)) {
            throw new UnexpectedValueException('Có số câu bị trùng.');
        }
        $missing = array_diff(range(1, max($numbers)), $numbers);
        if ($missing !== []) {
            $warnings[] = 'Thiếu câu '.implode(', ', $missing).'.';
        }
        foreach ($questions as $q) {
            if (isset($key[$q['no']]) && $key[$q['no']] !== $q['answer']) {
                $warnings[] = "Câu {$q['no']}: đáp án trong bảng ({$q['answer']}) khác bảng đáp án cuối sheet ({$key[$q['no']]}); đang dùng {$q['answer']}.";
            }
        }

        return [
            'grade' => $grade,
            'term' => $term,
            'code' => $code,
            'title' => Str::limit($title, 250, ''),
            'duration' => $duration ?? self::DEFAULT_DURATION,
            'warnings' => $warnings,
            'passages' => $passages,
            'questions' => $questions,
        ];
    }

    private function skillFor(string $type): string
    {
        foreach (self::SKILL_RULES as $prefix => $skill) {
            if (str_starts_with($type, $prefix)) {
                return $skill;
            }
        }

        return 'Từ vựng & Ngữ pháp';
    }

    private function stripOptionLetter(string $value, string $letter): string
    {
        return trim((string) preg_replace('/^'.$letter.'\s*[.)]\s*/u', '', $value));
    }

    private function cell(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }
        // `[x]` là markup gạch chân của ngân hàng câu hỏi: ngoặc vuông có sẵn trong Excel (vd. "[Biển báo]") đổi thành ngoặc tròn
        $value = str_replace(["\r\n", "\r", '[', ']'], ["\n", "\n", '(', ')'], (string) $value);

        return trim($value);
    }

    // ---- Word (gạch chân) ----

    /**
     * Đọc mã đề và từng câu ("Question N. …") của file Word, giữ gạch chân dạng `[x]`.
     *
     * @return array{code: ?string, questions: array<int, string>}
     */
    public function parseDocx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new UnexpectedValueException('Không đọc được file Word.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new UnexpectedValueException('Không đọc được file Word.');
        }

        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new UnexpectedValueException('Không đọc được file Word.');
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $code = null;
        $questions = [];
        $current = null;
        foreach ($xp->query('//w:body//w:p') as $p) {
            $text = '';
            foreach ($xp->query('.//w:r', $p) as $r) {
                $u = $xp->query('./w:rPr/w:u', $r)->item(0);
                $underlined = $u instanceof \DOMElement && ! in_array($u->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val'), ['none', ''], true);
                $run = '';
                foreach ($xp->query('./w:t|./w:tab|./w:br', $r) as $node) {
                    $run .= $node->localName === 't' ? $node->textContent : ($node->localName === 'tab' ? "\t" : "\n");
                }
                $text .= $underlined && trim($run) !== '' ? '['.$run.']' : $run;
            }
            $text = str_replace('][', '', $text);

            if ($code === null && preg_match('/Mã đề\s*:?\s*([0-9A-Z]+)/iu', $text, $m)) {
                $code = $m[1];
            }
            if (preg_match('/^\s*Question\s+(\d+)\s*[.:]\s*(.*)$/su', $text, $m)) {
                $current = (int) $m[1];
                $questions[$current] = $m[2];
            } elseif ($current !== null) {
                if (preg_match(self::INSTRUCTION_PATTERN, trim($text))) {
                    $current = null;
                } else {
                    $questions[$current] .= "\n".$text;
                }
            }
        }

        return ['code' => $code, 'questions' => $questions];
    }

    /**
     * Thay phương án (và đề) bằng bản có gạch chân từ Word khi bỏ gạch chân đi thì trùng khớp chữ trong Excel.
     * Câu Phát âm vẫn không có gạch chân → cảnh báo để Học thuật sửa tay.
     *
     * @param  array<int, string>|null  $docQuestions
     */
    private function applyUnderlines(array &$exam, ?array $docQuestions): void
    {
        foreach ($exam['questions'] as &$q) {
            $doc = $docQuestions[$q['no']] ?? null;
            if ($doc !== null) {
                $parts = $this->splitOptions($doc);
                if ($parts !== null) {
                    foreach (self::OPTION_KEYS as $letter) {
                        $candidate = trim($parts[$letter]);
                        if (str_contains($candidate, '[') && $this->plain($candidate) === $this->plain($q['options'][$letter])) {
                            $q['options'][$letter] = $candidate;
                        }
                    }
                    $stem = trim($parts['stem']);
                    if (str_contains($stem, '[') && $q['stem'] !== '' && $this->plain($stem) === $this->plain($q['stem'])) {
                        $q['stem'] = $stem;
                    }
                }
            }
            if ($q['type'] === 'Phát âm' && ! str_contains(implode('', $q['options']), '[')) {
                $exam['warnings'][] = "Câu {$q['no']} (Phát âm): chưa có gạch chân — cần file Word của đề hoặc sửa tay (viết [x] để gạch chân).";
            }
        }
        unset($q);
    }

    /** @return array{stem: string, A: string, B: string, C: string, D: string}|null */
    private function splitOptions(string $text): ?array
    {
        $positions = [];
        $offset = 0;
        foreach (self::OPTION_KEYS as $letter) {
            $pos = mb_strpos($text, $letter.'. ', $offset);
            if ($pos === false) {
                return null;
            }
            $positions[$letter] = $pos;
            $offset = $pos + 3;
        }
        $result = ['stem' => mb_substr($text, 0, $positions['A'])];
        $letters = self::OPTION_KEYS;
        foreach ($letters as $i => $letter) {
            $start = $positions[$letter] + 3;
            $end = isset($letters[$i + 1]) ? $positions[$letters[$i + 1]] : mb_strlen($text);
            $result[$letter] = mb_substr($text, $start, $end - $start);
        }

        return $result;
    }

    private function plain(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(['[', ']'], '', $value)));
    }

    // ---- Lưu ----

    private function save(array $parsed, ExamImportSummary $summary): void
    {
        $label = "Khối {$parsed['grade']} – Đề {$parsed['code']}";
        $exam = PracticeExam::query()
            ->where('grade', $parsed['grade'])->where('term', $parsed['term'])->where('code', $parsed['code'])
            ->first();

        if ($exam !== null && $exam->status !== PracticeExam::DRAFT) {
            $summary->kept[] = "{$label}: đã mở / đã ẩn — giữ nguyên. Muốn thay, chuyển đề về Nháp rồi import lại.";

            return;
        }

        DB::transaction(function () use ($parsed, &$exam) {
            $attributes = [
                'grade' => $parsed['grade'],
                'term' => $parsed['term'],
                'code' => $parsed['code'],
                'title' => $parsed['title'],
                'duration_minutes' => $parsed['duration'],
                'source_file' => Str::limit($parsed['source_file'], 250, ''),
                'import_warnings' => $parsed['warnings'],
            ];
            if ($exam === null) {
                $exam = PracticeExam::query()->create($attributes + ['status' => PracticeExam::DRAFT]);
            } else {
                $exam->update($attributes);
                // đề Nháp chưa có lượt làm của học viên: thay toàn bộ câu
                QuestionBankQuestion::query()->where('exam_id', $exam->id)->delete();
                QuestionBankPassage::query()->where('exam_id', $exam->id)->delete();
            }

            $common = [
                'exam_id' => $exam->id,
                'grade' => $parsed['grade'],
                'term' => $parsed['term'],
                'source_file' => Str::limit($parsed['source_file'], 250, ''),
            ];
            $passageIds = [];
            foreach ($parsed['questions'] as $q) {
                $passageId = null;
                if ($q['passage'] !== null) {
                    $passageId = $passageIds[$q['passage']] ??= QuestionBankPassage::query()->create($common + [
                        'skill' => $q['skill'],
                        'type' => $q['type'],
                        'instruction' => $parsed['passages'][$q['passage']]['instruction'],
                        'content' => $parsed['passages'][$q['passage']]['content'],
                        'source_key' => sprintf('EX%d-P%d', $exam->id, $q['no']),
                    ])->id;
                }
                QuestionBankQuestion::query()->create($common + [
                    'passage_id' => $passageId,
                    'position' => $q['no'],
                    'skill' => $q['skill'],
                    'type' => $q['type'],
                    'instruction' => $passageId === null ? $q['instruction'] : null,
                    'stem' => $q['stem'],
                    'options' => $q['options'],
                    'answer' => $q['answer'],
                    'status' => 'approved',
                    'approved_at' => now(),
                    'source_key' => sprintf('EX%d-Q%d', $exam->id, $q['no']),
                ]);
            }
        });

        $count = count($parsed['questions']);
        $summary->saved[] = $exam->wasRecentlyCreated ? "{$label}: thêm mới {$count} câu." : "{$label}: cập nhật {$count} câu.";
        $summary->examIds[] = $exam->id;
        foreach ($parsed['warnings'] as $warning) {
            $summary->warnings[] = "{$label} – {$warning}";
        }
    }

    /** Tên file trong ZIP nén trên Windows có thể không phải UTF-8. */
    private function utf8(string $name): string
    {
        return mb_check_encoding($name, 'UTF-8') ? $name : (mb_convert_encoding($name, 'UTF-8', 'CP437') ?: $name);
    }
}
