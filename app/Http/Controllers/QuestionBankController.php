<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\QuestionBankPassage;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionBank\QuestionBankImporter;
use App\Support\Audit;
use App\Support\Practice\PracticeCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;

/**
 * Ngân hàng câu hỏi (docs/luyen-tap-ngan-hang-cau-hoi.md §5.2): danh sách / lọc, duyệt – ẩn hàng loạt, sửa câu / ngữ liệu
 * (modal RendersModals), import ZIP. Mọi thao tác sửa tay đặt `edited_at` cho câu bị ảnh hưởng để import lại không ghi đè.
 */
class QuestionBankController extends Controller
{
    use RendersModals;

    private const OPTION_KEYS = ['A', 'B', 'C', 'D'];

    /** Thao tác hàng loạt → trạng thái đích + câu chữ nhật ký / thông báo (:n = số câu). */
    private const BULK_ACTIONS = [
        'approve' => ['status' => 'approved', 'log' => 'Duyệt :n câu hỏi', 'done' => 'Đã duyệt :n câu hỏi.'],
        'hide' => ['status' => 'hidden', 'log' => 'Ẩn :n câu hỏi', 'done' => 'Đã ẩn :n câu hỏi.'],
        'draft' => ['status' => 'draft', 'log' => 'Chuyển :n câu hỏi về Chưa duyệt', 'done' => 'Đã chuyển :n câu hỏi về Chưa duyệt.'],
    ];

    /** Số dòng lỗi import tối đa hiện trên trang. */
    private const IMPORT_ERROR_LINES = 20;

    public function index(Request $request): InertiaResponse
    {
        $filters = $this->filters($request);

        $counts = $this->filtered([...$filters, 'status' => null])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('QuestionBank/Index', [
            'questions' => $this->filtered($filters)
                ->with('passage:id,instruction,content,image_path')
                ->orderBy('id')
                ->paginate(min($request->perPage(30), 100)) // mỗi câu kèm ngữ liệu + đáp án: không cho "xem tất cả"
                ->withQueryString()
                ->through(fn (QuestionBankQuestion $question) => $this->questionRow($question)),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'statusCounts' => collect(QuestionBankQuestion::STATUSES)->map(fn ($label, string $status) => (int) ($counts[$status] ?? 0)),
            'canManage' => $request->user()->can('question_bank.manage'),
            'importErrors' => session('import_errors', []),
        ]);
    }

    /** Duyệt / ẩn / chuyển về Chưa duyệt: các câu đã chọn (`ids`) hoặc mọi câu đang lọc (`all_matching`) — một câu lệnh UPDATE. */
    public function bulk(Request $request): RedirectResponse
    {
        $allMatching = $request->boolean('all_matching');
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::BULK_ACTIONS))],
            'all_matching' => ['nullable', 'boolean'],
            'ids' => [Rule::requiredIf(! $allMatching), 'array', 'max:1000'],
            'ids.*' => ['integer'],
        ], [
            'ids.required' => 'Chưa chọn câu hỏi nào.',
        ]);

        $action = self::BULK_ACTIONS[$data['action']];
        $filters = $this->filters($request);
        $query = $allMatching
            ? $this->filtered($filters)
            : QuestionBankQuestion::query()->whereIn('id', $data['ids']);

        $now = now();
        $changes = ['status' => $action['status'], 'edited_at' => $now];
        if ($action['status'] === 'approved') {
            $changes += ['approved_by' => $request->user()->id, 'approved_at' => $now];
        } elseif ($action['status'] === 'draft') {
            $changes += ['approved_by' => null, 'approved_at' => null];
        }

        // Câu đã ở trạng thái đích giữ nguyên (không đổi người / lúc duyệt).
        $count = $query->where('status', '!=', $action['status'])->update($changes);

        if ($count === 0) {
            return back()->with('info', 'Không có câu hỏi nào cần đổi trạng thái.');
        }

        activity(Audit::logNameFor(QuestionBankQuestion::class))
            ->causedBy($request->user())
            ->event('updated')
            ->withProperties([
                'action' => $data['action'],
                'count' => $count,
                ...($allMatching ? ['filters' => array_filter($filters, fn ($v) => $v !== null && $v !== '')] : ['ids' => $data['ids']]),
            ])
            ->log(str_replace(':n', (string) $count, $action['log']));

        return back()->with('status', str_replace(':n', (string) $count, $action['done']));
    }

    public function editQuestion(QuestionBankQuestion $question): InertiaResponse
    {
        $question->load('passage:id,instruction,content,image_path');

        return $this->modalPage('QuestionBank/QuestionForm', [
            'question' => $this->questionRow($question),
            'statuses' => $this->options(QuestionBankQuestion::STATUSES),
        ]);
    }

    public function updateQuestion(Request $request, QuestionBankQuestion $question): RedirectResponse
    {
        $data = $request->validate([
            'instruction' => ['nullable', 'string', 'max:2000'],
            'stem' => ['required', 'string', 'max:5000'],
            'options' => ['required', 'array:'.implode(',', self::OPTION_KEYS)],
            ...collect(self::OPTION_KEYS)->mapWithKeys(fn (string $key) => ["options.{$key}" => ['required', 'string', 'max:1000']])->all(),
            'answer' => ['required', Rule::in(self::OPTION_KEYS)],
            'knowledge' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys(QuestionBankQuestion::STATUSES))],
        ], [], [
            'stem' => 'câu hỏi',
            'answer' => 'đáp án',
            ...collect(self::OPTION_KEYS)->mapWithKeys(fn (string $key) => ["options.{$key}" => "phương án {$key}"])->all(),
        ]);

        $question->fill([
            'instruction' => $data['instruction'] ?? null,
            'stem' => $data['stem'],
            'options' => collect(self::OPTION_KEYS)->mapWithKeys(fn (string $key) => [$key => $data['options'][$key]])->all(),
            'answer' => $data['answer'],
            'knowledge' => $data['knowledge'] ?? null,
            'status' => $data['status'],
            'edited_at' => now(),
        ]);
        if ($question->isDirty('status')) {
            match ($question->status) {
                'approved' => $question->fill(['approved_by' => $request->user()->id, 'approved_at' => now()]),
                'draft' => $question->fill(['approved_by' => null, 'approved_at' => null]),
                default => null,
            };
        }
        $question->save();

        return $this->modalSaved('Đã lưu câu hỏi.', route('question-bank.index'));
    }

    public function editPassage(QuestionBankPassage $passage): InertiaResponse
    {
        $passage->load('questions:id,passage_id,position,stem,status');

        return $this->modalPage('QuestionBank/PassageForm', [
            'passage' => [
                'id' => $passage->id,
                'grade' => $passage->grade,
                'term_label' => PracticeCatalog::TERMS[$passage->term] ?? $passage->term,
                'skill' => $passage->skill,
                'type' => $passage->type,
                'instruction' => $passage->instruction,
                'content' => $passage->content,
                'image_url' => $this->imageUrl($passage->image_path),
                'questions' => $passage->questions->map(fn (QuestionBankQuestion $q) => [
                    'id' => $q->id,
                    'position' => $q->position,
                    'stem' => $q->stem,
                    'status_label' => QuestionBankQuestion::STATUSES[$q->status] ?? $q->status,
                ])->all(),
            ],
        ]);
    }

    /** Sửa ngữ liệu dùng chung: mọi câu của ngữ liệu được đánh dấu đã sửa (import lại giữ bản đã sửa). */
    public function updatePassage(Request $request, QuestionBankPassage $passage): RedirectResponse
    {
        $data = $request->validate([
            'instruction' => ['nullable', 'string', 'max:2000'],
            'content' => ['required', 'string', 'max:20000'],
        ], [], ['content' => 'ngữ liệu']);

        DB::transaction(function () use ($request, $passage, $data) {
            $passage->fill(['instruction' => $data['instruction'] ?? null, 'content' => $data['content']]);
            $changes = $passage->getDirty();
            $passage->save();

            QuestionBankQuestion::query()->where('passage_id', $passage->id)->update(['edited_at' => now()]);

            activity(Audit::logNameFor(QuestionBankQuestion::class))
                ->causedBy($request->user())
                ->performedOn($passage)
                ->event('updated')
                ->withProperties(['old' => array_intersect_key($passage->getPrevious(), $changes), 'attributes' => $changes])
                ->log("Sửa ngữ liệu #{$passage->id}");
        });

        return $this->modalSaved('Đã lưu ngữ liệu.', route('question-bank.index'));
    }

    /** Import file ZIP (data/*.json + hinh/*) chạy đồng bộ; một dòng nhật ký tổng thay cho từng câu. */
    public function import(Request $request, QuestionBankImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:zip', 'max:102400'],
        ], [], ['file' => 'file ZIP']);

        // Vài nghìn câu + ảnh. Chỉ đặt khi chạy qua web: set_time_limit() trong CLI (test) giết cả tiến trình PHPUnit
        // 300 giây sau lần gọi cuối (như CrmImportController).
        if (! app()->runningInConsole()) {
            @set_time_limit(300);
        }

        try {
            $summary = activity()->withoutLogs(fn () => $importer->importZip($request->file('file')->getRealPath()));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        activity(Audit::logNameFor(QuestionBankQuestion::class))
            ->causedBy($request->user())
            ->withProperties([
                'file' => $request->file('file')->getClientOriginalName(),
                'created' => $summary->created,
                'updated' => $summary->updated,
                'kept' => $summary->kept,
                'skipped' => $summary->skipped,
                'errors' => count($summary->errors),
            ])
            ->log('Import ngân hàng câu hỏi: '.$summary->message());

        $errors = array_slice($summary->errors, 0, self::IMPORT_ERROR_LINES);
        if (count($summary->errors) > self::IMPORT_ERROR_LINES) {
            $errors[] = '… và '.(count($summary->errors) - self::IMPORT_ERROR_LINES).' lỗi khác.';
        }

        return back()->with('status', $summary->message())->with('import_errors', $errors);
    }

    /**
     * Bộ lọc đang áp dụng (chỉ giá trị hợp lệ). Đọc cả query lẫn body: thao tác "tất cả câu đang lọc" gửi lại bộ lọc.
     *
     * @return array{grade: ?int, term: ?string, skill: ?string, type: ?string, status: ?string, q: string}
     */
    private function filters(Request $request): array
    {
        $pick = fn (string $key, array $allowed) => in_array($value = (string) $request->input($key, ''), $allowed, true) ? $value : null;
        $grade = (int) $request->input('grade');
        $type = trim((string) $request->input('type', ''));

        return [
            'grade' => in_array($grade, PracticeCatalog::GRADES, true) ? $grade : null,
            'term' => $pick('term', array_keys(PracticeCatalog::TERMS)),
            'skill' => $pick('skill', PracticeCatalog::SKILLS),
            'type' => $type !== '' ? $type : null,
            'status' => $pick('status', array_keys(QuestionBankQuestion::STATUSES)),
            'q' => trim((string) $request->input('q', '')),
        ];
    }

    /**
     * Truy vấn theo bộ lọc — dùng chung cho danh sách, số đếm trạng thái và thao tác "tất cả câu đang lọc".
     *
     * @param  array{grade: ?int, term: ?string, skill: ?string, type: ?string, status: ?string, q: string}  $filters
     */
    private function filtered(array $filters): Builder
    {
        $search = $filters['q'];

        return QuestionBankQuestion::query()
            ->when($filters['grade'] !== null, fn (Builder $q) => $q->where('grade', $filters['grade']))
            ->when($filters['term'] !== null, fn (Builder $q) => $q->where('term', $filters['term']))
            ->when($filters['skill'] !== null, fn (Builder $q) => $q->where('skill', $filters['skill']))
            ->when($filters['type'] !== null, fn (Builder $q) => $q->where('type', $filters['type']))
            ->when($filters['status'] !== null, fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('stem', 'like', "%{$search}%")
                ->orWhere('options', 'like', "%{$search}%")
                ->orWhere('knowledge', 'like', "%{$search}%")
                ->orWhere('source_key', 'like', "%{$search}%")
                ->orWhereHas('passage', fn (Builder $p) => $p->where('content', 'like', "%{$search}%"))));
    }

    /** @return array<string, list<array{value: int|string, label: string}>> */
    private function filterOptions(): array
    {
        $types = QuestionBankQuestion::query()->select('skill', 'type')->distinct()->get()
            ->sortBy(fn (QuestionBankQuestion $row) => [PracticeCatalog::skillOrder($row->skill), $row->type])
            ->pluck('type')->unique()->values();

        return [
            'grades' => array_map(fn (int $grade) => ['value' => $grade, 'label' => "Khối {$grade}"], PracticeCatalog::GRADES),
            'terms' => $this->options(PracticeCatalog::TERMS),
            'skills' => array_map(fn (string $skill) => ['value' => $skill, 'label' => $skill], PracticeCatalog::SKILLS),
            'types' => $types->map(fn (string $type) => ['value' => $type, 'label' => $type])->all(),
            'statuses' => $this->options(QuestionBankQuestion::STATUSES),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private function options(array $labels): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }

    /** Màn của Học thuật: gửi cả đáp án để duyệt. */
    private function questionRow(QuestionBankQuestion $question): array
    {
        $passage = $question->passage;

        return [
            'id' => $question->id,
            'grade' => $question->grade,
            'term_label' => PracticeCatalog::TERMS[$question->term] ?? $question->term,
            'skill' => $question->skill,
            'type' => $question->type,
            'knowledge' => $question->knowledge,
            'instruction' => $question->instruction,
            'stem' => $question->stem,
            'options' => $question->options,
            'answer' => $question->answer,
            'image_url' => $this->imageUrl($question->image_path),
            'status' => $question->status,
            'status_label' => QuestionBankQuestion::STATUSES[$question->status] ?? $question->status,
            'edited' => $question->edited_at !== null,
            'source_key' => $question->source_key,
            'source' => implode(' · ', array_filter([$question->source_school, $question->source_year, $question->source_file])),
            'passage' => $passage ? [
                'id' => $passage->id,
                'instruction' => $passage->instruction,
                'content' => $passage->content,
                'image_url' => $this->imageUrl($passage->image_path),
            ] : null,
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
