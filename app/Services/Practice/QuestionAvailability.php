<?php

namespace App\Services\Practice;

use App\Models\QuestionBankQuestion;

/** Số câu đã duyệt hiện có theo (kỹ năng, dạng bài) — để so với khung đề (docs/luyen-tap-ngan-hang-cau-hoi.md §5.3). */
class QuestionAvailability
{
    /**
     * Số câu đã duyệt của một khối × kỳ, khóa "{skill}|{type}" (một truy vấn GROUP BY).
     *
     * @return array<string, int>
     */
    public function counts(int $grade, string $term): array
    {
        return $this->all()["{$grade}|{$term}"] ?? [];
    }

    /**
     * Toàn bộ khối × kỳ trong một truy vấn: khóa ngoài "{grade}|{term}", khóa trong "{skill}|{type}".
     *
     * @return array<string, array<string, int>>
     */
    public function all(): array
    {
        $result = [];
        QuestionBankQuestion::query()->approved()
            ->selectRaw('grade, term, skill, type, count(*) as total')
            ->groupBy('grade', 'term', 'skill', 'type')
            ->get()
            ->each(function ($row) use (&$result) {
                $result["{$row->grade}|{$row->term}"]["{$row->skill}|{$row->type}"] = (int) $row->total;
            });

        return $result;
    }

    /**
     * Số câu bốc được (ước lượng) cho một khung đề: Σ min(số câu khung cần, số câu đã duyệt) theo (kỹ năng, dạng bài).
     * Các dòng khung trùng (kỹ năng, dạng) cộng dồn vì đề không lặp câu. Ngữ liệu nhiều câu có thể làm số thật lệch ít.
     *
     * @param  iterable<array{skill: string, type: string, count: int|string}>  $items  dòng khung đề
     * @param  array<string, int>  $counts  từ `counts()`: "{skill}|{type}" → số câu đã duyệt
     */
    public function drawable(iterable $items, array $counts): int
    {
        $wanted = [];
        foreach ($items as $row) {
            $key = "{$row['skill']}|{$row['type']}";
            $wanted[$key] = ($wanted[$key] ?? 0) + max(0, (int) $row['count']);
        }

        return array_sum(array_map(fn (int $count, string $key) => min($count, $counts[$key] ?? 0), $wanted, array_keys($wanted)));
    }
}
