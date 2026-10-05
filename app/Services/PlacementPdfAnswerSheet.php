<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Tạo đề test đầu vào từ file PDF: thí sinh xem nguyên file PDF (giữ tranh, bố cục) và trả lời trên phiếu đáp án.
 * Lớp này đọc chữ trong PDF để dựng sẵn phiếu: số câu, kỹ năng (theo tiêu đề phần), dạng câu (A/B/C/D hay điền từ)
 * và đáp án nếu PDF có phần "Answer key" / "Đáp án". Kết quả chỉ là bản nháp để Học vụ / Học thuật kiểm tra trước khi lưu;
 * câu hỏi cùng cấu trúc với đề soạn tay nên tự chấm Nghe, Đọc & Viết như cũ.
 */
class PlacementPdfAnswerSheet
{
    public const MAX_QUESTIONS = 200;

    /** Đáp án trắc nghiệm hợp lệ. */
    private const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    /** Số phương án mặc định khi PDF không ghi chữ phương án (câu chọn tranh A/B/C). */
    private const DEFAULT_OPTION_COUNT = 3;

    /**
     * Chữ của từng trang PDF. PDF scan (chỉ có ảnh) hoặc file hỏng → mảng rỗng / trang rỗng.
     *
     * @return list<string>
     */
    public static function extractPages(string $absolutePath): array
    {
        try {
            $config = new Config;
            $config->setRetainImageContent(false);
            $pdf = (new Parser([], $config))->parseFile($absolutePath);

            return array_map(fn ($page) => $page->getText(), $pdf->getPages());
        } catch (\Throwable $e) {
            Log::info('Không đọc được chữ trong PDF đề test: '.$e->getMessage());

            return [];
        }
    }

    /** Phần đáp án của file (sau tiêu đề "Answer key" / "Đáp án"); file chỉ có đáp án, không tiêu đề → cả file. */
    public static function keyText(string $text): string
    {
        $lines = self::lines($text);

        return self::splitAnswerKey($lines)[1] ?? implode("\n", $lines);
    }

    /**
     * Trang (đánh số từ 1) có phần đáp án — thí sinh xem nguyên file nên file đề phát cho thí sinh không được còn trang này.
     *
     * @param  list<string>  $pages
     */
    public static function answerKeyPage(array $pages): ?int
    {
        for ($i = count($pages) - 1; $i >= 0; $i--) {
            [, $keyText] = self::splitAnswerKey(self::lines($pages[$i]));
            if ($keyText !== null && self::parseKey($keyText) !== []) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * Dựng phiếu đáp án từ chữ của PDF.
     *
     * @return array{questions: list<array<string, mixed>>, answers_found: int, warnings: list<string>}
     */
    public static function build(string $text): array
    {
        $lines = self::lines($text);
        if ($lines === []) {
            return [
                'questions' => [],
                'answers_found' => 0,
                'warnings' => ['Không đọc được chữ trong file PDF (có thể là bản scan). Nhập số câu để tạo phiếu rồi dán đáp án.'],
            ];
        }

        [$questionLines, $keyText] = self::splitAnswerKey($lines);
        $key = $keyText !== null ? self::parseKey($keyText) : [];
        $found = self::parseQuestions($questionLines);

        // PDF không đánh số câu ở đầu dòng nhưng có bảng đáp án → mỗi mục đáp án là một câu.
        if ($found === [] && $key !== []) {
            $found = array_map(fn (array $entry) => [
                'number' => $entry['number'], 'text' => '', 'skill' => 'reading', 'section' => '',
            ], $key);
        }

        $warnings = [];
        $found = array_slice($found, 0, self::MAX_QUESTIONS);
        $answers = self::matchKey($found, $key);

        if ($found === []) {
            $warnings[] = 'Không tìm thấy câu hỏi đánh số trong PDF. Nhập số câu để tạo phiếu rồi dán đáp án.';
        } elseif ($key === []) {
            $warnings[] = 'PDF chưa có phần đáp án (Answer key / Đáp án). Chọn đáp án đúng cho từng câu hoặc dán nhanh dạng "1A 2B 3 apple".';
        }

        $questions = [];
        foreach ($found as $i => $q) {
            $questions[] = self::question($i + 1, $q, $answers[$i] ?? '');
        }

        // Bài viết tự luận / Speaking không có đáp án: chỉ so với các câu chấm tự động.
        $gradable = array_filter($questions, fn ($q) => in_array($q['type'], ['multiple_choice', 'fill_blank'], true));
        $missing = count(array_filter($gradable, fn ($q) => $q['correct_answer'] === ''));
        if ($questions !== [] && $key !== []) {
            if (count($key) !== count($gradable)) {
                $warnings[] = 'PDF có '.count($gradable).' câu chấm tự động nhưng phần đáp án có '.count($key).' mục — kiểm tra lại từng câu.';
            }
            if ($missing > 0) {
                $warnings[] = $missing.' câu chưa có đáp án — những câu này không được tự chấm.';
            }
        }

        return [
            'questions' => $questions,
            'answers_found' => count(array_filter($answers, fn ($a) => $a !== '')),
            'warnings' => $warnings,
        ];
    }

    /**
     * Phiếu trống N câu (PDF scan): mỗi câu trắc nghiệm A–C, kỹ năng Đọc; Học vụ sửa kỹ năng / dạng / đáp án trên phiếu.
     *
     * @return list<array<string, mixed>>
     */
    public static function blankSheet(int $count): array
    {
        $count = max(1, min(self::MAX_QUESTIONS, $count));

        return array_map(fn (int $n) => self::question($n, ['number' => $n, 'text' => '', 'skill' => 'reading', 'section' => ''], ''), range(1, $count));
    }

    /**
     * Đáp án dán nhanh: "1A 2B 3. apple", "1-A, 2-B", "Câu 1: A" … → [số câu => đáp án].
     *
     * @return list<array{number: int, answer: string}>
     */
    public static function parseKey(string $text): array
    {
        $text = str_replace(["\r", "\t", "\u{00A0}"], ["\n", ' ', ' '], $text);
        // "Part 1 – Listening:" trong bảng đáp án là tiêu đề, không phải câu 1.
        $text = preg_replace('/\b(?:part|phần|section|exercise|bài)\s*[\dIVX]+\b[^:\n\d]{0,40}:?/iu', "\n", $text);
        $text = preg_replace('/\b(?:question|câu|q)\s*(?=\d)/iu', '', $text);
        // Số câu: "12." "12)" "12:" "12 -" hoặc dính liền chữ đáp án in hoa ("12A", "12 A").
        $marker = '/(?<![\w.,])(\d{1,3})(?:\s*[.):–-]\s*|\s*(?=[A-H](?![\w\'’])))/u';
        $entries = [];
        foreach (preg_split('/\n+/u', $text) as $line) {
            if (! preg_match_all($marker, $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $count = count($m[0]);
            for ($i = 0; $i < $count; $i++) {
                $start = $m[0][$i][1] + strlen($m[0][$i][0]);
                $end = $i + 1 < $count ? $m[0][$i + 1][1] : strlen($line);
                $answer = self::cleanAnswer(substr($line, $start, $end - $start));
                if ($answer !== '') {
                    $entries[] = ['number' => (int) $m[1][$i][0], 'answer' => $answer];
                }
            }
        }

        return $entries;
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\t", "\u{00A0}", "\u{2026}"], ["\n", "\n", ' ', ' ', '...'], $text);

        return array_values(array_filter(
            array_map(fn ($line) => trim(preg_replace('/ {2,}/u', ' ', $line)), explode("\n", $text)),
            fn ($line) => $line !== ''
        ));
    }

    /**
     * Tách phần đáp án (từ dòng tiêu đề "Answer key" / "Đáp án" cuối cùng trở đi).
     *
     * @param  list<string>  $lines
     * @return array{0: list<string>, 1: ?string}
     */
    private static function splitAnswerKey(array $lines): array
    {
        $heading = '/^(?:answer\s*keys?|answers|đáp\s*án(?:\s*(?:đúng|chi\s*tiết))?)\s*[:.\-–]?\s*(.*)$/iu';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match($heading, $lines[$i], $m) && ($m[1] === '' || preg_match('/^(?:\d|part|phần|section)/iu', $m[1]))) {
                return [array_slice($lines, 0, $i), trim($m[1]."\n".implode("\n", array_slice($lines, $i + 1)))];
            }
        }

        return [$lines, null];
    }

    /**
     * Câu hỏi = dòng bắt đầu bằng số thứ tự ("1.", "2)", "Question 3:", "Câu 4."); các dòng sau nối vào câu cho tới câu kế tiếp.
     * Dòng tiêu đề phần (Part / Section / chữ in hoa / câu lệnh "Listen and…") đổi kỹ năng cho các câu sau.
     *
     * @param  list<string>  $lines
     * @return list<array{number: int, text: string, skill: string, section: string}>
     */
    private static function parseQuestions(array $lines): array
    {
        $questions = [];
        $current = null;
        $skill = 'reading';
        $section = '';

        foreach ($lines as $line) {
            if (preg_match('/^(?:(?:question|câu|q)\s*)?(\d{1,3})\s*[.):]\s*(.*)$/iu', $line, $m)
                || preg_match('/^(?:question|câu)\s+(\d{1,3})\b\s*(.*)$/iu', $line, $m)) {
                if ($current !== null) {
                    $questions[] = $current;
                }
                $current = [
                    'number' => (int) $m[1],
                    'text' => $m[2],
                    'skill' => self::skillOf($m[2]) === 'listening' ? 'listening' : $skill,
                    'section' => $section,
                ];

                continue;
            }

            if (self::isHeading($line)) {
                if ($current !== null) {
                    $questions[] = $current;
                    $current = null;
                }
                $skill = self::skillOf($line) ?? $skill;
                $section = Str::limit($line, 120, '…');

                continue;
            }

            if ($current !== null && mb_strlen($current['text']) < 2000) {
                $current['text'] .= "\n".$line;
            }
        }
        if ($current !== null) {
            $questions[] = $current;
        }

        return $questions;
    }

    private static function isHeading(string $line): bool
    {
        if (preg_match('/^\(?[A-H][.)]\s|^(?:[A-H]\s+)+[A-H]$/u', $line)) {
            return false; // dòng phương án "A. YES  B. NO" / nhãn tranh "A B C D"
        }
        if (preg_match('/^(?:part|section|phần|exercise|ex\.|bài|task)\s*[\dIVX]+\b/iu', $line)) {
            return true;
        }
        if (preg_match('/^(?:listen|look|read|write|choose|circle|tick|fill|complete|match|answer|nghe|đọc|viết|khoanh|điền|chọn|nối|trả lời)\b/iu', $line)) {
            return true;
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $line);

        return mb_strlen($letters) >= 4 && mb_strlen($line) <= 80 && $letters === mb_strtoupper($letters);
    }

    private static function skillOf(string $text): ?string
    {
        $patterns = [
            'listening' => '/\blisten|\bnghe\b|\baudio\b|\btrack\s*\d/iu',
            'speaking' => '/\bspeak|\bnói\b|\binterview/iu',
            'grammar' => '/\bgrammar|\bvocab|\bngữ pháp|\btừ vựng|use of english/iu',
            'reading' => '/\bread|\bđọc\b/iu',
            'writing' => '/\bwrit|\bviết\b/iu',
        ];
        foreach ($patterns as $skill => $pattern) {
            if (preg_match($pattern, $text)) {
                return $skill;
            }
        }

        return null;
    }

    /**
     * Ghép đáp án vào câu: số câu trong đề và trong đáp án đều không trùng → theo số câu; đề đánh số lại từng phần → theo thứ tự.
     *
     * @param  list<array{number: int}>  $questions
     * @param  list<array{number: int, answer: string}>  $key
     * @return list<string>
     */
    private static function matchKey(array $questions, array $key): array
    {
        $qNumbers = array_column($questions, 'number');
        $kNumbers = array_column($key, 'number');
        $byNumber = count(array_unique($qNumbers)) === count($qNumbers) && count(array_unique($kNumbers)) === count($kNumbers);
        $keyByNumber = array_column($key, 'answer', 'number');

        return array_map(
            fn (array $q, int $i) => (string) ($byNumber ? ($keyByNumber[$q['number']] ?? '') : ($key[$i]['answer'] ?? '')),
            $questions,
            array_keys($questions)
        );
    }

    /** @param  array{number: int, text: string, skill: string, section: string}  $found */
    private static function question(int $id, array $found, string $answer): array
    {
        [$stem, $options] = self::splitOptions($found['text']);
        $skill = $found['skill'];
        $letter = self::letter($answer);

        if (count($options) >= 2) {
            $type = 'multiple_choice';
        } elseif ($skill === 'speaking') {
            $type = 'speaking_prompt';
        } elseif ($answer === '' && $skill === 'writing' && preg_match('/\bwords?\b|paragraph|email|letter|story|essay|đoạn văn|bài viết/iu', $stem)) {
            $type = 'essay';
        } elseif (($answer !== '' && $letter === null) || ($answer === '' && preg_match('/_{2,}|\.{3,}/u', $stem))) {
            $type = 'fill_blank';
        } else {
            $type = 'multiple_choice';
        }

        if ($type === 'multiple_choice' && count($options) < 2) {
            $last = max(self::DEFAULT_OPTION_COUNT, $letter ? array_search($letter, self::LETTERS, true) + 1 : 0);
            $options = array_map(fn ($key) => ['key' => $key, 'text' => ''], array_slice(self::LETTERS, 0, $last));
        }
        if ($type === 'multiple_choice') {
            $answer = $letter && in_array($letter, array_column($options, 'key'), true) ? $letter : '';
        }

        return [
            'id' => $id,
            'number' => (string) $found['number'],
            'skill' => $skill,
            'type' => $type,
            'section' => $found['section'],
            'title' => Str::limit(trim(preg_replace('/\s+/u', ' ', $stem)), 500, '…'),
            'audio_url' => '',
            'passage' => '',
            'options' => $type === 'multiple_choice' ? $options : [],
            'correct_answer' => in_array($type, ['multiple_choice', 'fill_blank'], true) ? $answer : '',
            'points' => 1,
            'explanation' => '',
            'teacher_note' => '',
        ];
    }

    /**
     * Tách phương án A./B./C. (hoặc (A), a)) khỏi nội dung câu. Chỉ nhận dãy liên tiếp bắt đầu từ A.
     *
     * @return array{0: string, 1: list<array{key: string, text: string}>}
     */
    private static function splitOptions(string $text): array
    {
        if (! preg_match_all('/(?:^|(?<=\s))(?:\(([A-Ha-h])\)|([A-H])[.)]|([a-h])\))(?=\s|$)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return self::pictureOptions($text);
        }

        $markers = [];
        $expected = 0;
        foreach ($m[0] as $i => $match) {
            $letter = strtoupper($m[1][$i][0] ?: ($m[2][$i][0] ?: $m[3][$i][0]));
            if ($letter === self::LETTERS[0] && $expected > 0 && count($markers) < 2) {
                // "A" sau một "A" lẻ (VD: chữ "A." trong câu) → bắt đầu lại dãy.
                $markers = [];
                $expected = 0;
            }
            if ($letter === (self::LETTERS[$expected] ?? null)) {
                $markers[] = ['key' => $letter, 'start' => $match[1], 'end' => $match[1] + strlen($match[0])];
                $expected++;
            }
        }
        if (count($markers) < 2) {
            return self::pictureOptions($text);
        }

        $options = [];
        foreach ($markers as $i => $marker) {
            $end = $markers[$i + 1]['start'] ?? strlen($text);
            $options[] = ['key' => $marker['key'], 'text' => Str::limit(trim(preg_replace('/\s+/u', ' ', substr($text, $marker['end'], $end - $marker['end']))), 255, '…')];
        }

        return [substr($text, 0, $markers[0]['start']), $options];
    }

    /**
     * Câu chọn tranh: dưới câu hỏi chỉ có dòng nhãn tranh "A B C" (chữ không kèm dấu chấm) → số phương án theo nhãn, nội dung trống.
     *
     * @return array{0: string, 1: list<array{key: string, text: string}>}
     */
    private static function pictureOptions(string $text): array
    {
        if (! preg_match('/\n\s*((?:[A-H]\s+){1,7}[A-H])\s*(?=\n|$)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return [$text, []];
        }
        $letters = preg_split('/\s+/u', $m[1][0]);
        if ($letters !== array_slice(self::LETTERS, 0, count($letters))) {
            return [$text, []];
        }

        return [
            substr($text, 0, $m[0][1]).substr($text, $m[0][1] + strlen($m[0][0])),
            array_map(fn ($key) => ['key' => $key, 'text' => ''], $letters),
        ];
    }

    private static function letter(string $answer): ?string
    {
        return preg_match('/^\(?([A-Ha-h])\)?\.?$/u', trim($answer), $m) ? strtoupper($m[1]) : null;
    }

    private static function cleanAnswer(string $answer): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $answer), " \t,;"), 255, '');
    }
}
