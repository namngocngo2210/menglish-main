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
     * Never exceeds $count, except one smallest unit when every unit is bigger than $count.
     *
     * @param  Collection<int, QuestionBankQuestion>  $candidates
     * @param  array<int, int>  $recent  question id => index
     * @return Collection<int, QuestionBankQuestion>
     */
    private function pick(Collection $candidates, int $count, array $recent): Collection
    {
        $isFresh = fn (Collection $qs) => ! $qs->contains(fn ($q) => isset($recent[$q->id]));
        // shuffled once, so "first with the wanted size" is a random pick among ties
        $units = $candidates
            ->groupBy(fn ($q) => $q->passage_id ? 'p'.$q->passage_id : 'q'.$q->id)
            ->map(fn (Collection $qs) => $qs->sortBy('position')->values())
            ->shuffle()
            ->values();

        $picked = collect();
        while ($picked->count() < $count && $units->isNotEmpty()) {
            $remaining = $count - $picked->count();
            $fitting = $units->filter(fn (Collection $qs) => $qs->count() <= $remaining);

            if ($fitting->isEmpty()) {
                // every unit is bigger than the missing count: take one smallest unit only when the row is still empty
                if ($picked->isEmpty()) {
                    $smallest = $units->sortBy(fn (Collection $qs) => [$qs->count(), $isFresh($qs) ? 0 : 1])->first();
                    $picked = $picked->concat($smallest);
                }
                break;
            }

            // fresh units first, then the largest fitting size, random among equal sizes
            $pool = $fitting->filter($isFresh);
            $pool = $pool->isNotEmpty() ? $pool : $fitting;
            $maxSize = $pool->max(fn (Collection $qs) => $qs->count());
            $key = $pool->search(fn (Collection $qs) => $qs->count() === $maxSize);
            $picked = $picked->concat($units->pull($key));
        }

        return $picked;
    }
}
