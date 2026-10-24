<?php

namespace App\Support\Practice;

/** Hằng số dùng chung cho Ngân hàng câu hỏi & Luyện tập. */
class PracticeCatalog
{
    public const TERMS = [
        'gk1' => 'Giữa kì 1',
        'ck1' => 'Cuối kì 1',
        'gk2' => 'Giữa kì 2',
        'ck2' => 'Cuối kì 2',
    ];

    /** Tên kỳ trong dữ liệu nguồn → mã kỳ. */
    public const SOURCE_TERMS = [
        'Giữa kì I' => 'gk1',
        'Cuối kì I' => 'ck1',
        'Giữa kì II' => 'gk2',
        'Cuối kì II' => 'ck2',
    ];

    /** Kỹ năng theo thứ tự xuất hiện trong đề. */
    public const SKILLS = [
        'Ngữ âm',
        'Từ vựng & Ngữ pháp',
        'Giao tiếp & Biển báo',
        'Đọc hiểu',
        'Viết',
    ];

    public const GRADES = [6, 7, 8, 9];

    public const DEFAULT_DURATION = 45;

    /** Khung đề mặc định (40 câu). @var list<array{skill: string, type: string, count: int}> */
    public const DEFAULT_ITEMS = [
        ['skill' => 'Ngữ âm', 'type' => 'Phát âm', 'count' => 2],
        ['skill' => 'Ngữ âm', 'type' => 'Trọng âm', 'count' => 2],
        ['skill' => 'Từ vựng & Ngữ pháp', 'type' => 'Chọn đáp án đúng', 'count' => 12],
        ['skill' => 'Từ vựng & Ngữ pháp', 'type' => 'Tìm lỗi sai', 'count' => 2],
        ['skill' => 'Giao tiếp & Biển báo', 'type' => 'Hội thoại', 'count' => 2],
        ['skill' => 'Giao tiếp & Biển báo', 'type' => 'Biển báo / Thông báo', 'count' => 2],
        ['skill' => 'Đọc hiểu', 'type' => 'Điền từ – đoạn văn', 'count' => 5],
        ['skill' => 'Đọc hiểu', 'type' => 'Đọc hiểu – trả lời câu hỏi', 'count' => 5],
        ['skill' => 'Viết', 'type' => 'Câu đồng nghĩa', 'count' => 4],
        ['skill' => 'Viết', 'type' => 'Dựng câu từ gợi ý', 'count' => 2],
        ['skill' => 'Viết', 'type' => 'Sắp xếp câu thành đoạn', 'count' => 2],
    ];

    public static function skillOrder(string $skill): int
    {
        $i = array_search($skill, self::SKILLS, true);

        return $i === false ? 99 : $i;
    }
}
