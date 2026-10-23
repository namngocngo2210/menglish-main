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
}
