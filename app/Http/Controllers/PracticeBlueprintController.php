<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\PracticeBlueprint;
use App\Models\QuestionBankQuestion;
use App\Services\Practice\QuestionAvailability;
use App\Support\Audit;
use App\Support\Practice\PracticeCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** Khung đề luyện tập khối × kỳ (docs/luyen-tap-ngan-hang-cau-hoi.md §5.3): xem lưới kèm số câu đã duyệt, sửa khung. */
class PracticeBlueprintController extends Controller
{
    use RendersModals;

    public function index(Request $request, QuestionAvailability $availability): InertiaResponse
    {
        $available = $availability->all();
        $saved = PracticeBlueprint::query()->get()->keyBy(fn (PracticeBlueprint $b) => "{$b->grade}|{$b->term}");

        $cells = [];
        foreach (PracticeCatalog::GRADES as $grade) {
            foreach (PracticeCatalog::TERMS as $term => $termLabel) {
                $blueprint = $saved["{$grade}|{$term}"] ?? PracticeBlueprint::forGradeTerm($grade, $term);
                $counts = $available["{$grade}|{$term}"] ?? [];
                $items = collect($blueprint->items)->map(function (array $item) use ($counts) {
                    $have = $counts["{$item['skill']}|{$item['type']}"] ?? 0;

                    return [...$item, 'available' => $have, 'short' => $have < $item['count']];
                })->values();

                $cells[] = [
                    'grade' => $grade,
                    'term' => $term,
                    'term_label' => $termLabel,
                    'duration_minutes' => $blueprint->duration_minutes,
                    'total' => (int) $items->sum('count'),
                    'items' => $items->all(),
                ];
            }
        }

        return Inertia::render('QuestionBank/Blueprints', [
            'cells' => $cells,
            'canManage' => $request->user()->can('question_bank.manage'),
        ]);
    }

    public function edit(int $grade, string $term): InertiaResponse
    {
        $this->assertGradeTerm($grade, $term);
        $blueprint = PracticeBlueprint::forGradeTerm($grade, $term);

        return $this->modalPage('QuestionBank/BlueprintForm', [
            'blueprint' => [
                'grade' => $grade,
                'term' => $term,
                'term_label' => PracticeCatalog::TERMS[$term],
                'duration_minutes' => $blueprint->duration_minutes,
                'items' => $blueprint->items,
            ],
            'skills' => PracticeCatalog::SKILLS,
            'typeSuggestions' => $this->typeSuggestions(),
        ]);
    }

    public function update(Request $request, int $grade, string $term): RedirectResponse
    {
        $this->assertGradeTerm($grade, $term);

        $data = $request->validate([
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:180'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.skill' => ['required', Rule::in(PracticeCatalog::SKILLS)],
            'items.*.type' => ['required', 'string', 'max:100'],
            'items.*.count' => ['required', 'integer', 'min:0', 'max:100'],
        ], [], [
            'duration_minutes' => 'thời gian làm bài',
            'items' => 'khung đề',
            'items.*.skill' => 'kỹ năng',
            'items.*.type' => 'dạng bài',
            'items.*.count' => 'số câu',
        ]);

        $items = collect($data['items'])->map(fn (array $item) => [
            'skill' => $item['skill'],
            'type' => trim($item['type']),
            'count' => (int) $item['count'],
        ])->values()->all();

        PracticeBlueprint::query()->updateOrCreate(
            ['grade' => $grade, 'term' => $term],
            ['duration_minutes' => (int) $data['duration_minutes'], 'items' => $items],
        );

        activity(Audit::logNameFor(QuestionBankQuestion::class))
            ->causedBy($request->user())
            ->event('updated')
            ->withProperties(['grade' => $grade, 'term' => $term, 'duration_minutes' => (int) $data['duration_minutes'], 'items' => $items])
            ->log("Sửa khung đề khối {$grade} – ".PracticeCatalog::TERMS[$term]);

        return $this->modalSaved('Đã lưu khung đề.', route('question-bank.blueprints.index'));
    }

    private function assertGradeTerm(int $grade, string $term): void
    {
        abort_unless(in_array($grade, PracticeCatalog::GRADES, true) && isset(PracticeCatalog::TERMS[$term]), 404);
    }

    /**
     * Các dạng bài đang có trong ngân hàng theo kỹ năng — gợi ý để nhập đúng tên dạng bài mà importer đã ghi.
     *
     * @return array<string, list<string>>
     */
    private function typeSuggestions(): array
    {
        $suggestions = [];
        QuestionBankQuestion::query()->inBank()->select('skill', 'type')->distinct()->orderBy('type')->get()
            ->each(function (QuestionBankQuestion $row) use (&$suggestions) {
                $suggestions[$row->skill][] = $row->type;
            });

        return $suggestions;
    }
}
