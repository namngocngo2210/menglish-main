<?php

namespace App\Support;

/**
 * Chế độ khoá màn hình dùng chung cho test đầu vào và Luyện tập: số lần rời bài tối đa, các loại vi phạm
 * trình duyệt ghi nhận được và cách làm sạch nhật ký vi phạm do trình duyệt gửi lên.
 */
class ExamViolations
{
    /** Rời bài thi tới lần thứ này thì bài tự nộp. */
    public const MAX = 3;

    /** Các loại vi phạm trang làm bài ghi nhận được (khoá => nhãn hiển thị cho người chấm). */
    public const TYPES = [
        'hidden' => 'Chuyển sang tab / ứng dụng khác',
        'blur' => 'Rời khỏi cửa sổ bài thi',
        'fullscreen_exit' => 'Thoát chế độ toàn màn hình',
    ];

    private const MAX_LOG_ENTRIES = 50;

    private const MAX_AT_LENGTH = 40;

    public static function isValidType(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    /**
     * Nhật ký vi phạm (chuỗi JSON hoặc mảng): chỉ giữ loại vi phạm đã biết và thời điểm, tối đa 50 dòng.
     *
     * @return array<int, array{type: string, at: string}>|null
     */
    public static function sanitizeLog(mixed $raw): ?array
    {
        $entries = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
        if (! is_array($entries)) {
            return null;
        }

        $log = [];
        foreach (array_slice($entries, 0, self::MAX_LOG_ENTRIES) as $entry) {
            $type = is_array($entry) ? ($entry['type'] ?? null) : null;
            if (! is_string($type) || ! self::isValidType($type)) {
                continue;
            }
            $at = is_string($entry['at'] ?? null) ? substr($entry['at'], 0, self::MAX_AT_LENGTH) : '';
            $log[] = ['type' => $type, 'at' => $at];
        }

        return $log ?: null;
    }
}
