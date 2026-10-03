<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\TeacherMeetingReport;
use App\Models\User;
use App\Support\Rbac;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Báo cáo họp giáo viên theo tuần (mockup "Báo cáo họp giáo viên"): Học thuật ghi nội dung họp với từng giáo viên
 * (ghi chú syllabus, bảng điểm, tình hình lớp, order học thuật, đề xuất) và tình trạng xử lý.
 * Ghi / sửa / xóa: class_quality.teacher_meeting. Báo cáo gắn lớp chỉ hiện khi lớp nằm trong phạm vi Lớp học.
 */
class TeacherMeetingReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'week' => ['nullable', 'date'],
            'teacher_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(array_keys(TeacherMeetingReport::STATUSES))],
        ]);
        $week = isset($filters['week']) ? Carbon::parse($filters['week'])->startOfWeek()->toDateString() : null;

        $reports = TeacherMeetingReport::query()
            ->visibleTo($user)
            ->with(['teacher:id,name', 'classModel:id,code,name,course_id', 'classModel.course:id,name', 'author:id,name'])
            ->when($week, fn ($q) => $q->whereDate('week_start', $week))
            ->when($filters['teacher_id'] ?? null, fn ($q, $id) => $q->where('teacher_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('week_start')->latest('id')
            ->paginate($request->perPage(15))->withQueryString()
            ->through(fn (TeacherMeetingReport $r) => [
                'id' => $r->id,
                'week_start' => $r->week_start->toDateString(),
                'week_end' => $r->week_start->copy()->endOfWeek()->toDateString(),
                'teacher_id' => $r->teacher_id,
                'teacher' => $r->teacher?->name,
                'class_id' => $r->class_id,
                'class' => $r->classModel ? $r->classModel->code.' · '.$r->classModel->name : null,
                'course' => $r->classModel?->course?->name,
                'author' => $r->author?->name,
                'syllabus_note' => $r->syllabus_note,
                'scores_note' => $r->scores_note,
                'class_note' => $r->class_note,
                'academic_order' => $r->academic_order,
                'recommendation' => $r->recommendation,
                'status' => $r->status,
                'status_label' => TeacherMeetingReport::STATUSES[$r->status] ?? $r->status,
                'status_color' => TeacherMeetingReport::STATUS_COLORS[$r->status] ?? 'neutral',
            ]);

        $classes = ClassModel::visibleTo($user)->where('status', 'active')
            ->with('course:id,name')
            ->orderBy('code')->get(['id', 'code', 'name', 'course_id', 'teacher_id', 'foreign_teacher_id']);

        return Inertia::render('ClassQuality/TeacherMeetings', [
            'reports' => $reports,
            'teachers' => Ui::options(Rbac::scopeUsersWithPermission(User::where('is_active', true), 'class.teach')->orderBy('name')->get(['id', 'name']), 'name'),
            'classes' => $classes->map(fn (ClassModel $c) => [
                'value' => $c->id,
                'label' => $c->code.' · '.$c->name,
                'course' => $c->course?->name,
                'teacher_ids' => array_values(array_filter([$c->teacher_id, $c->foreign_teacher_id])),
            ])->values(),
            'statuses' => Ui::options(TeacherMeetingReport::STATUSES),
            'canManage' => $user->can('class_quality.teacher_meeting'),
            'currentWeek' => now()->startOfWeek()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        TeacherMeetingReport::create($this->validated($request) + ['author_id' => $request->user()->id]);

        return back()->with('success', 'Đã lưu báo cáo họp giáo viên.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $report = TeacherMeetingReport::visibleTo($request->user())->findOrFail($id);
        $report->update($this->validated($request, $report));

        return back()->with('success', 'Đã cập nhật báo cáo họp giáo viên.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        TeacherMeetingReport::visibleTo($request->user())->findOrFail($id)->delete();

        return back()->with('success', 'Đã xóa báo cáo họp giáo viên.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?TeacherMeetingReport $current = null): array
    {
        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'teacher_id' => ['required', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'syllabus_note' => ['nullable', 'string', 'max:5000'],
            'scores_note' => ['nullable', 'string', 'max:5000'],
            'class_note' => ['required', 'string', 'max:5000'],
            'academic_order' => ['nullable', 'string', 'max:5000'],
            'recommendation' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(array_keys(TeacherMeetingReport::STATUSES))],
        ], [
            'week_start.required' => 'Chọn tuần họp.',
            'teacher_id.required' => 'Chọn giáo viên.',
            'class_note.required' => 'Nhập ghi chú tình hình lớp / học sinh.',
            'status.required' => 'Chọn tình trạng xử lý.',
        ]);

        $teacherOk = Rbac::scopeUsersWithPermission(User::query(), 'class.teach')->whereKey($data['teacher_id'])->exists();
        if (! $teacherOk) {
            throw ValidationException::withMessages(['teacher_id' => 'Người được chọn không phải giáo viên.']);
        }

        if (! empty($data['class_id'])) {
            $class = ClassModel::visibleTo($request->user())
                ->where(fn ($q) => $q->where('status', 'active')->when($current, fn ($q) => $q->orWhere('id', $current->class_id)))
                ->find($data['class_id']);
            if (! $class) {
                throw ValidationException::withMessages(['class_id' => 'Lớp không còn hoạt động hoặc nằm ngoài phạm vi bạn được xem.']);
            }
            if (! in_array((int) $data['teacher_id'], [(int) $class->teacher_id, (int) $class->foreign_teacher_id], true)) {
                throw ValidationException::withMessages(['class_id' => 'Giáo viên đã chọn không dạy lớp này.']);
            }
        }

        $data['week_start'] = Carbon::parse($data['week_start'])->startOfWeek()->toDateString();
        $data['class_id'] = $data['class_id'] ?? null;

        return $data;
    }
}
