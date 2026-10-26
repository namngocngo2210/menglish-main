<?php

namespace App\Http\Controllers;

use App\Models\PracticeAttempt;
use App\Models\PracticeExam;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionBank\PracticeExamImporter;
use App\Support\Audit;
use App\Support\Practice\PracticeCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Đề cố định (docs/luyen-tap-ngan-hang-cau-hoi.md §11.2): danh sách theo khối, import Excel + Word / ZIP, xem đề kèm đáp án,
 * sửa tên / thời gian, mở – ẩn – về nháp, xoá đề chưa có lượt làm. Câu / ngữ liệu sửa bằng form của Ngân hàng câu hỏi.
 */
class PracticeExamController extends Controller
{
    /** Chuyển trạng thái → [trạng thái đích, thông báo]. */
    private const ACTIONS = [
        'publish' => [PracticeExam::PUBLISHED, 'Đã mở đề cho học viên.'],
        'hide' => [PracticeExam::HIDDEN, 'Đã ẩn đề.'],
        'draft' => [PracticeExam::DRAFT, 'Đã chuyển đề về Nháp.'],
    ];

    public function index(Request $request): Response
    {
        $exams = PracticeExam::query()->withCount('questions')->get();
        $stats = PracticeAttempt::query()->toBase()
            ->whereNotNull('exam_id')
            ->where('status', PracticeAttempt::SUBMITTED)
            ->groupBy('exam_id')
            ->selectRaw('exam_id, count(*) as attempts, count(distinct student_id) as students, avg(score) as avg_score')
            ->get()->keyBy('exam_id');
        $termOrder = array_flip(array_keys(PracticeCatalog::TERMS));

        return Inertia::render('QuestionBank/Exams', [
            'grades' => collect(PracticeCatalog::GRADES)->map(fn (int $grade) => [
                'grade' => $grade,
                'exams' => $exams->where('grade', $grade)
                    ->sortBy(fn (PracticeExam $e) => [$termOrder[$e->term] ?? 99, $e->code])
                    ->values()
                    ->map(fn (PracticeExam $e) => [
                        ...$this->examRow($e),
                        'attempts' => (int) ($stats[$e->id]->attempts ?? 0),
                        'students' => (int) ($stats[$e->id]->students ?? 0),
                        'avg_score' => isset($stats[$e->id]) ? round((float) $stats[$e->id]->avg_score, 2) : null,
                    ])->all(),
            ])->all(),
            'canManage' => $request->user()->can('question_bank.manage'),
            'importReport' => session('exam_import_report'),
        ]);
    }

    public function show(Request $request, PracticeExam $exam): Response
    {
        $exam->loadCount('questions');
        $questions = $exam->questions()->with('passage:id,instruction,content')->get();

        return Inertia::render('QuestionBank/ExamShow', [
            'exam' => [
                ...$this->examRow($exam),
                'attempts' => $exam->attempts()->count(),
            ],
            'questions' => $questions->map(fn (QuestionBankQuestion $q) => [
                'id' => $q->id,
                'position' => $q->position,
                'skill' => $q->skill,
                'type' => $q->type,
                'instruction' => $q->instruction ?? $q->passage?->instruction,
                'stem' => $q->stem,
                'options' => $q->options,
                'answer' => $q->answer,
                'edited' => $q->edited_at !== null,
                'passage' => $q->passage ? ['id' => $q->passage->id, 'content' => $q->passage->content] : null,
            ])->all(),
            'canManage' => $request->user()->can('question_bank.manage'),
        ]);
    }

    /** Import file .zip / .xlsx / .docx (nhiều file một lần); kết quả từng đề hiện lại ở danh sách. */
    public function import(Request $request, PracticeExamImporter $importer): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:30'],
            'files.*' => ['file', 'max:51200', function (string $attribute, $file, $fail) {
                if (! in_array(strtolower($file->getClientOriginalExtension()), ['zip', 'xlsx', 'docx'], true)) {
                    $fail('Chỉ nhận file .zip, .xlsx, .docx.');
                }
            }],
        ], [], ['files' => 'file đề', 'files.*' => 'file đề']);

        if (! app()->runningInConsole()) {
            @set_time_limit(300);
        }

        try {
            $summary = activity()->withoutLogs(fn () => $importer->import(array_map(
                fn ($file) => ['path' => $file->getRealPath(), 'name' => $file->getClientOriginalName()],
                $request->file('files'),
            )));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['files' => $e->getMessage()]);
        }

        activity(Audit::logNameFor(QuestionBankQuestion::class))
            ->causedBy($request->user())
            ->withProperties([
                'files' => array_map(fn ($file) => $file->getClientOriginalName(), $request->file('files')),
                'exam_ids' => $summary->examIds,
                'errors' => count($summary->errors),
            ])
            ->log('Import đề cố định: '.$summary->message());

        return back()
            ->with($summary->saved === [] ? 'error' : 'status', $summary->message())
            ->with('exam_import_report', [
                'saved' => $summary->saved,
                'kept' => $summary->kept,
                'errors' => $summary->errors,
                'warnings' => array_slice($summary->warnings, 0, 50),
            ]);
    }

    public function update(Request $request, PracticeExam $exam): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:240'],
        ], [], ['title' => 'tên đề', 'duration_minutes' => 'thời gian']);

        $exam->update($data);
        $this->log($request, $exam, 'Sửa đề cố định '.$exam->label());

        return back()->with('status', 'Đã lưu đề.');
    }

    public function status(Request $request, PracticeExam $exam): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(array_keys(self::ACTIONS))]]);
        [$status, $message] = self::ACTIONS[$data['action']];

        if ($status === PracticeExam::PUBLISHED && ! $exam->questions()->exists()) {
            return back()->with('error', 'Đề chưa có câu hỏi.');
        }
        // Nháp = import lại được (thay toàn bộ câu): không cho khi học viên đã làm đề
        if ($status === PracticeExam::DRAFT && $exam->attempts()->exists()) {
            return back()->with('error', 'Học viên đã làm đề này nên không chuyển về Nháp được. Hãy ẩn đề, sửa từng câu nếu cần.');
        }

        $exam->update([
            'status' => $status,
            'published_at' => $status === PracticeExam::PUBLISHED ? ($exam->published_at ?? now()) : $exam->published_at,
        ]);
        $this->log($request, $exam, PracticeExam::STATUSES[$status].' đề cố định '.$exam->label());

        return back()->with('status', $message);
    }

    public function destroy(Request $request, PracticeExam $exam): RedirectResponse
    {
        if ($exam->attempts()->exists()) {
            return back()->with('error', 'Học viên đã làm đề này nên không xoá được. Hãy ẩn đề.');
        }

        $label = "khối {$exam->grade} {$exam->label()}";
        $exam->delete(); // câu / ngữ liệu xoá theo (cascade)
        $this->log($request, $exam, "Xoá đề cố định {$label}");

        return redirect()->route('question-bank.exams.index')->with('status', 'Đã xoá đề.');
    }

    private function examRow(PracticeExam $exam): array
    {
        return [
            'id' => $exam->id,
            'grade' => $exam->grade,
            'term' => $exam->term,
            'term_label' => $exam->termLabel(),
            'code' => $exam->code,
            'label' => $exam->label(),
            'title' => $exam->title,
            'duration_minutes' => $exam->duration_minutes,
            'status' => $exam->status,
            'status_label' => PracticeExam::STATUSES[$exam->status] ?? $exam->status,
            'question_count' => (int) $exam->questions_count,
            'source_file' => $exam->source_file,
            'warnings' => $exam->import_warnings ?? [],
        ];
    }

    private function log(Request $request, PracticeExam $exam, string $message): void
    {
        activity(Audit::logNameFor(QuestionBankQuestion::class))
            ->causedBy($request->user())
            ->event('updated')
            ->withProperties(['exam_id' => $exam->id, 'status' => $exam->status])
            ->log($message);
    }
}
