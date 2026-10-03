<?php

namespace App\Http\Controllers;

use App\Models\AcademicObservation;
use App\Models\ClassModel;
use App\Models\QaObservation;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Support\Rbac;
use App\Support\ReportPeriod;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dự giờ (mockup "QA Observation — Dự giờ đội vận hành" và "Đánh giá dự giờ" học thuật).
 *  - Vận hành: mỗi lượt dự giờ = 1 buổi quan sát một giáo viên ở một lớp đang học, xếp loại 4 mức (class_quality.observe_operations).
 *  - Học thuật: mỗi lớp 1 đánh giá / tháng — % chuyên cần, % đạt yêu cầu, đã dự giờ hay chưa, 6 tiêu chí nhận xét
 *    (class_quality.observe_academic). % chuyên cần gợi ý sẵn từ điểm danh của lớp trong tháng.
 * Chỉ thấy / ghi cho lớp trong phạm vi Lớp học của người dùng (ClassModel::visibleTo).
 */
class ObservationController extends Controller
{
    // ───────────────────────── DỰ GIỜ VẬN HÀNH ─────────────────────────
    public function operations(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'teacher_id' => ['nullable', 'integer'],
            'rating' => ['nullable', Rule::in(array_keys(QaObservation::RATINGS))],
        ]);

        $observations = QaObservation::query()
            ->whereHas('classModel', fn ($q) => $q->visibleTo($user))
            ->with(['classModel:id,code,name,branch_id', 'classModel.branch:id,name', 'teacher:id,name,employee_code', 'observer:id,name'])
            ->when($filters['teacher_id'] ?? null, fn ($q, $id) => $q->where('teacher_id', $id))
            ->when($filters['rating'] ?? null, fn ($q, $rating) => $q->where('rating', $rating))
            ->latest('observed_on')->latest('id')
            ->paginate($request->perPage(15))->withQueryString()
            ->through(fn (QaObservation $o) => [
                'id' => $o->id,
                'observed_on' => $o->observed_on->toDateString(),
                'teacher_id' => $o->teacher_id,
                'teacher' => $o->teacher?->name,
                'teacher_code' => $o->teacher?->employee_code,
                'class_id' => $o->class_id,
                'class' => $o->classModel ? $o->classModel->code.' · '.$o->classModel->name : null,
                'branch' => $o->classModel?->branch?->name,
                'observer' => $o->observer?->name,
                'rating' => $o->rating,
                'rating_label' => QaObservation::RATINGS[$o->rating] ?? $o->rating,
                'rating_color' => QaObservation::RATING_COLORS[$o->rating] ?? 'neutral',
                'notes' => collect(QaObservation::NOTE_FIELDS)->keys()->mapWithKeys(fn ($f) => [$f => $o->{$f}])->all(),
            ]);

        // Form: chỉ giáo viên đang có lớp hoạt động; lớp lọc theo giáo viên đã chọn (GV chính hoặc GVNN của lớp).
        $classes = ClassModel::visibleTo($user)->where('status', 'active')
            ->with(['teacher:id,name', 'foreignTeacher:id,name'])
            ->orderBy('code')->get(['id', 'code', 'name', 'teacher_id', 'foreign_teacher_id']);
        $teachers = $classes->flatMap(fn (ClassModel $c) => [$c->teacher, $c->foreignTeacher])->filter()->unique('id');

        return Inertia::render('ClassQuality/Operations', [
            'observations' => $observations,
            'classes' => $classes->map(fn (ClassModel $c) => [
                'value' => $c->id,
                'label' => $c->code.' · '.$c->name,
                'teacher_ids' => array_values(array_filter([$c->teacher_id, $c->foreign_teacher_id])),
            ])->values(),
            'teachers' => $teachers->sortBy('name')->map(fn (User $t) => [
                'value' => $t->id,
                'label' => $t->name.' ('.$classes->filter(fn (ClassModel $c) => in_array($t->id, [$c->teacher_id, $c->foreign_teacher_id], true))->count().' lớp đang dạy)',
            ])->values(),
            'filterTeachers' => Ui::options(User::whereIn('id', QaObservation::query()->select('teacher_id'))->orderBy('name')->get(['id', 'name']), 'name'),
            'ratings' => Ui::options(QaObservation::RATINGS),
            'noteFields' => QaObservation::NOTE_FIELDS,
            'canRecord' => $user->can('class_quality.observe_operations'),
            'today' => now()->toDateString(),
        ]);
    }

    public function storeOperations(Request $request): RedirectResponse
    {
        QaObservation::create($this->validatedOperations($request) + ['observer_id' => $request->user()->id]);

        return back()->with('success', 'Đã ghi nhận lượt dự giờ.');
    }

    public function updateOperations(Request $request, int $id): RedirectResponse
    {
        $observation = $this->visibleOperations($request->user(), $id);
        $observation->update($this->validatedOperations($request, $observation));

        return back()->with('success', 'Đã cập nhật lượt dự giờ.');
    }

    public function destroyOperations(Request $request, int $id): RedirectResponse
    {
        $this->visibleOperations($request->user(), $id)->delete();

        return back()->with('success', 'Đã xóa lượt dự giờ.');
    }

    private function visibleOperations(User $user, int $id): QaObservation
    {
        return QaObservation::whereHas('classModel', fn ($q) => $q->visibleTo($user))->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validatedOperations(Request $request, ?QaObservation $current = null): array
    {
        $data = $request->validate([
            'observed_on' => ['required', 'date', 'before_or_equal:today'],
            'teacher_id' => ['required', 'integer'],
            'class_id' => ['required', 'integer'],
            'rating' => ['required', Rule::in(array_keys(QaObservation::RATINGS))],
            ...collect(QaObservation::NOTE_FIELDS)->keys()->mapWithKeys(fn ($f) => [$f => ['nullable', 'string', 'max:5000']])->all(),
        ], [
            'observed_on.required' => 'Bắt buộc chọn ngày dự giờ.',
            'observed_on.before_or_equal' => 'Ngày dự giờ không được ở tương lai.',
            'teacher_id.required' => 'Bắt buộc chọn giáo viên.',
            'class_id.required' => 'Bắt buộc chọn lớp.',
            'rating.required' => 'Bắt buộc đánh giá xếp loại.',
        ]);

        // Lớp đang hoạt động trong phạm vi; khi sửa vẫn giữ được lớp cũ (có thể đã kết thúc).
        $class = ClassModel::visibleTo($request->user())
            ->where(fn ($q) => $q->where('status', 'active')->when($current, fn ($q) => $q->orWhere('id', $current->class_id)))
            ->find($data['class_id']);
        if (! $class) {
            throw ValidationException::withMessages(['class_id' => 'Lớp không còn hoạt động hoặc nằm ngoài phạm vi bạn được xem.']);
        }
        if (! in_array((int) $data['teacher_id'], [(int) $class->teacher_id, (int) $class->foreign_teacher_id], true)) {
            throw ValidationException::withMessages(['class_id' => 'Giáo viên đã chọn không dạy lớp này.']);
        }

        return $data;
    }

    // ───────────────────────── ĐÁNH GIÁ DỰ GIỜ HỌC THUẬT ─────────────────────────
    public function academic(Request $request): Response
    {
        $user = $request->user();
        $month = ReportPeriod::pick($request->input('month'), ReportPeriod::MONTH_PATTERN, ReportPeriod::currentMonth());
        [$from, $to] = ReportPeriod::monthRange($month);
        $status = in_array($request->input('status'), ['observed', 'pending'], true) ? $request->input('status') : null;

        $classes = ClassModel::visibleTo($user)->runningBetween($from, $to)
            ->with(['teacher:id,name', 'course:id,name'])
            ->when($status, fn ($q) => $status === 'observed'
                ? $q->whereHas('academicObservations', fn ($o) => $o->where('month', $month)->where('observed', true))
                : $q->whereDoesntHave('academicObservations', fn ($o) => $o->where('month', $month)->where('observed', true)))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderBy('code')
            ->paginate($request->perPage(20))->withQueryString();

        $classIds = $classes->getCollection()->pluck('id');
        $evaluations = AcademicObservation::with('observer:id,name')->where('month', $month)->whereIn('class_id', $classIds)->get()->keyBy('class_id');
        $attendance = $this->attendanceRates($classIds->all(), $from->toDateString(), $to->toDateString());

        $observedCount = AcademicObservation::where('month', $month)->where('observed', true)
            ->whereIn('class_id', ClassModel::visibleTo($user)->runningBetween($from, $to)->select('id'))->count();

        return Inertia::render('ClassQuality/Academic', [
            'month' => $month,
            'months' => ReportPeriod::monthOptions(),
            'classes' => $classes->through(function (ClassModel $c) use ($evaluations, $attendance) {
                $e = $evaluations->get($c->id);

                return [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'teacher' => $c->teacher?->name,
                    'course' => $c->course?->name,
                    'suggested_attendance' => $attendance[$c->id] ?? null,
                    'evaluation' => $e ? [
                        'observed' => $e->observed,
                        'observed_on' => $e->observed_on?->toDateString(),
                        'observer_id' => $e->observer_id,
                        'observer' => $e->observer?->name,
                        'attendance_rate' => $e->attendance_rate,
                        'pass_rate' => $e->pass_rate,
                        'action_notes' => $e->action_notes,
                        'criteria' => collect(AcademicObservation::CRITERIA)->keys()->mapWithKeys(fn ($f) => [$f => $e->{$f}])->all(),
                    ] : null,
                ];
            }),
            'stats' => ['total' => ClassModel::visibleTo($user)->runningBetween($from, $to)->count(), 'observed' => $observedCount],
            'criteria' => AcademicObservation::CRITERIA,
            'observers' => Ui::options(Rbac::scopeUsersWithPermission(User::where('is_active', true), 'class_quality.observe_academic')->orderBy('name')->get(['id', 'name']), 'name'),
            'canRecord' => $user->can('class_quality.observe_academic'),
            'currentUserId' => $user->id,
        ]);
    }

    public function saveAcademic(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer'],
            'month' => ['required', 'regex:'.ReportPeriod::MONTH_PATTERN],
            'attendance_rate' => ['nullable', 'numeric', 'between:0,100'],
            'pass_rate' => ['nullable', 'numeric', 'between:0,100'],
            'observed' => ['boolean'],
            'observed_on' => ['nullable', 'required_if:observed,true', 'date', 'before_or_equal:today'],
            'observer_id' => ['nullable', 'required_if:observed,true', 'integer', 'exists:users,id'],
            'action_notes' => ['nullable', 'string', 'max:5000'],
            ...collect(AcademicObservation::CRITERIA)->keys()->mapWithKeys(fn ($f) => [$f => ['nullable', 'string', 'max:5000']])->all(),
        ], [
            'observed_on.required_if' => 'Chọn ngày dự giờ.',
            'observer_id.required_if' => 'Chọn người dự giờ.',
            'attendance_rate.between' => 'Tỉ lệ chuyên cần từ 0 đến 100%.',
            'pass_rate.between' => 'Tỉ lệ đạt yêu cầu từ 0 đến 100%.',
        ]);

        [$from, $to] = ReportPeriod::monthRange($data['month']);
        $class = ClassModel::visibleTo($request->user())->runningBetween($from, $to)->find($data['class_id']);
        if (! $class) {
            throw ValidationException::withMessages(['class_id' => 'Lớp không học trong tháng đã chọn hoặc nằm ngoài phạm vi bạn được xem.']);
        }
        $observed = (bool) ($data['observed'] ?? false);
        if ($observed && ! str_starts_with($data['observed_on'], $data['month'])) {
            throw ValidationException::withMessages(['observed_on' => 'Ngày dự giờ phải nằm trong tháng đánh giá.']);
        }

        // Chưa dự giờ: chỉ lưu tỉ lệ, xóa phần đánh giá chi tiết.
        $details = ['observed_on', 'observer_id', 'action_notes', ...array_keys(AcademicObservation::CRITERIA)];
        $values = collect($details)->mapWithKeys(fn ($f) => [$f => $observed ? ($data[$f] ?? null) : null])->all();

        AcademicObservation::updateOrCreate(
            ['class_id' => $class->id, 'month' => $data['month']],
            $values + [
                'observed' => $observed,
                'attendance_rate' => $data['attendance_rate'] ?? null,
                'pass_rate' => $data['pass_rate'] ?? null,
                'updated_by' => $request->user()->id,
            ],
        );

        return back()->with('success', "Đã lưu đánh giá dự giờ lớp {$class->code} ".ReportPeriod::monthLabel($data['month']).'.');
    }

    /**
     * % chuyên cần theo lớp trong khoảng ngày: (có mặt + đi muộn) / tổng lượt điểm danh.
     *
     * @param  list<int>  $classIds
     * @return array<int, float>
     */
    private function attendanceRates(array $classIds, string $from, string $to): array
    {
        if ($classIds === []) {
            return [];
        }

        return StudentAttendance::query()
            ->whereIn('class_id', $classIds)
            ->whereDate('session_date', '>=', $from)
            ->whereDate('session_date', '<=', $to)
            ->selectRaw("class_id, count(*) as total, sum(case when status in ('present', 'late') then 1 else 0 end) as attended")
            ->groupBy('class_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->class_id => $row->total ? round($row->attended * 100 / $row->total, 1) : null])
            ->filter(fn ($rate) => $rate !== null)
            ->all();
    }
}
