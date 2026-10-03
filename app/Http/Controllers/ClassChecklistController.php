<?php

namespace App\Http\Controllers;

use App\Models\ClassChecklist;
use App\Models\ClassModel;
use App\Support\ReportPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checklist Học phí & Feedback theo lớp (mockup 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/09): mỗi lớp có học trong tháng một dòng,
 * 6 mục Có / Không / N-A + ghi chú học phí. Lưu cả trang một lần (class_quality.checklist). Lớp theo phạm vi Lớp học.
 * Dòng "đến hạn = Có" mà việc cần làm "Không" được đánh dấu thiếu sót (ClassChecklist::gaps).
 */
class ClassChecklistController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $month = ReportPeriod::pick($request->input('month'), ReportPeriod::MONTH_PATTERN, ReportPeriod::currentMonth());
        [$from, $to] = ReportPeriod::monthRange($month);

        $classes = ClassModel::visibleTo($user)->runningBetween($from, $to)
            ->with('teacher:id,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderBy('code')
            ->paginate($request->perPage(30))->withQueryString();

        $checklists = ClassChecklist::where('month', $month)
            ->whereIn('class_id', $classes->getCollection()->pluck('id'))
            ->get()->keyBy('class_id');

        return Inertia::render('ClassQuality/Checklist', [
            'month' => $month,
            'months' => ReportPeriod::monthOptions(6, 1),
            'classes' => $classes->through(function (ClassModel $c) use ($checklists) {
                $row = $checklists->get($c->id);

                return [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'teacher' => $c->teacher?->name,
                    'values' => collect(ClassChecklist::ITEMS)->keys()->mapWithKeys(fn ($f) => [$f => $row?->{$f}])->all(),
                    'tuition_note' => $row?->tuition_note,
                    'gaps' => $row?->gaps() ?? [],
                ];
            }),
            'items' => ClassChecklist::ITEMS,
            'followUps' => ClassChecklist::FOLLOW_UPS,
            'answers' => ClassChecklist::ANSWERS,
            'canEdit' => $user->can('class_quality.checklist'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $answer = ['nullable', Rule::in(array_keys(ClassChecklist::ANSWERS))];
        $data = $request->validate([
            'month' => ['required', 'regex:'.ReportPeriod::MONTH_PATTERN],
            'rows' => ['required', 'array', 'max:200'],
            'rows.*.class_id' => ['required', 'integer', 'distinct'],
            'rows.*.tuition_note' => ['nullable', 'string', 'max:1000'],
            ...collect(ClassChecklist::ITEMS)->keys()->mapWithKeys(fn ($f) => ["rows.*.{$f}" => $answer])->all(),
        ]);

        [$from, $to] = ReportPeriod::monthRange($data['month']);
        $classIds = collect($data['rows'])->pluck('class_id')->map(fn ($id) => (int) $id);
        $allowed = ClassModel::visibleTo($request->user())->runningBetween($from, $to)->whereIn('id', $classIds)->pluck('id');
        if ($allowed->count() !== $classIds->count()) {
            throw ValidationException::withMessages(['rows' => 'Có lớp không học trong tháng đã chọn hoặc nằm ngoài phạm vi bạn được xem.']);
        }

        $existing = ClassChecklist::where('month', $data['month'])->whereIn('class_id', $classIds)->get()->keyBy('class_id');
        $saved = 0;
        DB::transaction(function () use ($data, $existing, $request, &$saved) {
            foreach ($data['rows'] as $row) {
                $values = collect(ClassChecklist::ITEMS)->keys()->mapWithKeys(fn ($f) => [$f => $row[$f] ?? null])->all()
                    + ['tuition_note' => filled($row['tuition_note'] ?? null) ? $row['tuition_note'] : null];
                $current = $existing->get((int) $row['class_id']);
                // Dòng chưa đánh dấu gì và chưa có bản ghi: bỏ qua, không tạo bản ghi rỗng.
                if (! $current && collect($values)->filter()->isEmpty()) {
                    continue;
                }
                ClassChecklist::updateOrCreate(
                    ['class_id' => (int) $row['class_id'], 'month' => $data['month']],
                    $values + ['updated_by' => $request->user()->id],
                );
                $saved++;
            }
        });

        return back()->with('success', $saved
            ? "Đã lưu checklist {$saved} lớp — ".ReportPeriod::monthLabel($data['month']).'.'
            : 'Chưa có lớp nào được đánh dấu.');
    }
}
