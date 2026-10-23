<?php

namespace App\Services\Practice;

use App\Models\PracticeBlueprint;
use App\Models\QuestionBankQuestion;
use App\Support\Practice\PracticeCatalog;
use Illuminate\Support\Collection;

/** Sinh đề luyện tập từ khung đề: chỉ bốc câu đã duyệt, ưu tiên câu chưa gặp gần đây. */
class PracticeTestGenerator
{
    /**
     * @param  list<int>  $recentQuestionIds  Câu học viên đã gặp trong các lượt gần nhất cùng khối × kỳ.
     * @return Collection<int, QuestionBankQuestion> Theo thứ tự đề, đã nạp `passage`.
     */
    public function generate(PracticeBlueprint $blueprint, array $recentQuestionIds = []): Collection
    {
        $recent = array_flip($recentQuestionIds);
        $rows = collect($blueprint->items ?? [])
            ->filter(fn ($row) => (int) ($row['count'] ?? 0) > 0)
            ->values()
            // sortBy is stable, so duplicate/same-skill rows keep their configured order
            ->sortBy(fn ($row) => PracticeCatalog::skillOrder((string) $row['skill']))
            ->values();

        $result = collect();
        $used = [];

        foreach ($rows as $row) {
            $candidates = QuestionBankQuestion::query()->approved()
                ->where('grade', $blueprint->grade)
                ->where('term', $blueprint->term)
                ->where('skill', $row['skill'])
                ->where('type', $row['type'])
                ->with('passage')
                ->orderBy('position')
                ->get()
                ->reject(fn ($q) => isset($used[$q->id]));

            foreach ($this->pick($candidates, (int) $row['count'], $recent) as $question) {
                $used[$question->id] = true;
                $result->push($question);
            }
        }

        return $result->values();
    }

    public function hasQuestions(int $grade, string $term): bool
    {
        return QuestionBankQuestion::query()->approved()
            ->where('grade', $grade)->where('term', $term)->exists();
    }

    /**
     * Passages are units (all approved questions, by position); standalone questions are single units.
     *
     * @param  Collection<int, QuestionBankQuestion>  $candidates
     * @param  array<int, int>  $recent  question id => index
     * @return Collection<int, QuestionBankQuestion>
     */
    private function pick(Collection $candidates, int $count, array $recent): Collection
    {
        $units = $candidates
            ->groupBy(fn ($q) => $q->passage_id ? 'p'.$q->passage_id : 'q'.$q->id)
            ->map(fn (Collection $qs) => $qs->sortBy('position')->values())
            ->shuffle()
            // fresh units (no recently seen question) first; sort is stable so shuffle order is kept
            ->sortBy(fn (Collection $qs) => $qs->contains(fn ($q) => isset($recent[$q->id])) ? 1 : 0)
            ->values();

        $picked = collect();
        while ($picked->count() < $count && $units->isNotEmpty()) {
            $remaining = $count - $picked->count();
            $index = $units->search(fn (Collection $qs) => $qs->count() <= $remaining);
            // every unit is bigger than what is missing: take the first one anyway
            $unit = $units->pull($index === false ? $units->keys()->first() : $index);
            $picked = $picked->concat($unit);
            $units = $units->values();
        }

        return $picked;
    }
}
