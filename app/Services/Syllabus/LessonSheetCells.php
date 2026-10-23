<?php

namespace App\Services\Syllabus;

use Illuminate\Support\Str;

/**
 * Đọc ô buổi học theo form Sheet 1 — dùng chung cho import 1 chặng (StageImport) và import cả giáo trình (file zip).
 * Mỗi hàm trả null = ô trống, false = giá trị không hợp lệ.
 */
class LessonSheetCells
{
    public const TYPE_ERROR = 'không hợp lệ (Học / Ôn tập / Grammar Basic / Mini test / Big test).';

    public const MILESTONE_ERROR = 'không hợp lệ (Order in ấn / Order mini test / Order Big Test).';

    private const TYPES = ['học' => 'lesson', 'ôn tập' => 'review', 'grammar basic' => 'grammar', 'mini test' => 'mini_test', 'big test' => 'big_test'];

    /** Chữ giữ chỗ của file mẫu ("link PPT"...) coi như ô trống. */
    private const PLACEHOLDERS = ['link ppt', 'link quizizz', 'link đề', 'link quizizz (nếu test online)'];

    /** @return string|null|false mã loại buổi (SyllabusLesson::TYPES) */
    public static function type(?string $text): string|null|false
    {
        if ($text === null) {
            return null;
        }
        $text = self::normalize($text);

        return self::TYPES[$text] ?? (in_array($text, self::TYPES, true) ? $text : false);
    }

    /** @return string|null|false mã mốc thông báo (SyllabusLesson::MILESTONES) */
    public static function milestone(?string $text): string|null|false
    {
        if ($text === null) {
            return null;
        }
        $text = self::normalize($text);

        return match (true) {
            str_contains($text, 'big test') => 'order_big_test',
            str_contains($text, 'mini test') => 'order_mini_test',
            str_contains($text, 'in ấn') => 'order_print',
            default => false,
        };
    }

    /** @return string|null|false URL http(s) tối đa 500 ký tự */
    public static function link(?string $text): string|null|false
    {
        if ($text === null || in_array(self::normalize($text), self::PLACEHOLDERS, true)) {
            return null;
        }

        return filter_var($text, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $text) && mb_strlen($text) <= 500 ? $text : false;
    }

    private static function normalize(string $text): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $text)));
    }
}
