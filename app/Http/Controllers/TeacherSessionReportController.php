<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\ClassLessonProgress;
use App\Models\ClassReport;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\TeacherSessionReport;
use App\Models\User;
use App\Services\Reports\ClassReportSessionMetrics;
use App\Services\SessionLessonService;
use App\Services\SupportListService;
use App\Services\Syllabus\ClassSyllabus;
use App\Services\Syllabus\LessonTaught;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Báo cáo buổi học của giáo viên (chủ dự án 27/10/2026), mở từ nút trên từng buổi ở Trang lớp → tab Điểm danh & báo cáo buổi.
 *   - Chỉ GV chính của buổi nộp (GVNN không nộp); người khác xem được lớp thì xem (chỉ đọc, không duyệt).
 *   - Khối "Việc của buổi" đọc dữ liệu GV đã làm ở màn khác: điểm danh (bắt buộc trước khi nộp), đã dạy bài, BTVN.
 *   - Nhận xét từng học sinh nhập ngay trong báo cáo (lưu chung bản ghi với màn Nhận xét của Cổng GV).
 *   - Không ảnh. Hạn nộp: TeacherSessionReport::deadline (SLA gv.session_report); nộp sau hạn → "Nộp trễ".
 */
class TeacherSessionReportController extends Controller
{
    /** Nhận xét GV coi là "cần chú ý" khi gợi ý danh sách học sinh. */
    private const WEAK_REMARKS = ['grammar' => 'Cần cố gắng', 'attitude' => 'Cần cố gắng', 'result' => 'Không đạt'];

    /** Cột "Thực hành" mặc định khi lớp chưa có báo cáo nào (thay cột "Thực hành ngữ pháp" cũ). */
    private const DEFAULT_PRACTICE_COLUMNS = ['Ngữ pháp'];

    private const MAX_PRACTICE_COLUMNS = 6;

    /** Sự vụ trong buổi ghi vào Nhật ký sự vụ (StaffReport journal), đánh dấu nguồn để báo cáo buổi sửa / xóa lại được. */
    public const INCIDENT_SOURCE = 'session_report';

    public const SEVERITIES = ['normal' => 'Bình thường', 'important' => 'Quan trọng', 'urgent' => 'Khẩn'];

    public function show(Request $request, int $id, int $session): Response
    {
        [$class, $classSession] = $this->resolve($request, $id, $session);
        $user = $request->user();
        $report = TeacherSessionReport::where('class_session_id', $classSession->id)->first();
        $teacherId = TeacherSessionReport::responsibleTeacherId($classSession, $class);
        $canSubmit = $teacherId === (int) $user->id;
        $roster = $class->rosterStudents();
        $lesson = app(SessionLessonService::class)->lessonsFor(collect([$classSession]))[$classSession->id] ?? null;
        $deadline = TeacherSessionReport::deadline($classSession);
        $shared = app(SupportListService::class)->sessionRows($classSession)->where('source', '!=', SupportListService::SOURCE_TEACHER_REPORT)->values();
        $homework = $this->previousHomework($class, $classSession, $roster);

        return Inertia::render('Classes/SessionReport', [
            'classInfo' => [
                'id' => $class->id,
                'name' => $class->name,
                'code' => $class->code,
                'url' => route('classes.show', ['id' => $class->id, 'tab' => 'sessions']),
            ],
            'sessionInfo' => [
                'id' => $classSession->id,
                'no' => $lesson['no'] ?? null,
                'date' => $classSession->date->format('d/m/Y'),
                'weekday' => $classSession->date->dayOfWeek,
                'time' => $classSession->start_time && $classSession->end_time
                    ? $classSession->start_time->format('H:i').' - '.$classSession->end_time->format('H:i') : null,
                'room' => $classSession->room ?: ($class->room ?: null),
                'lesson' => $lesson['title'] ?? null,
                'teacher' => $teacherId ? User::find($teacherId, ['id', 'name'])?->name : null,
            ],
            'checklist' => $this->checklist($class, $classSession, $roster, $canSubmit),
            'attendanceDone' => StudentAttendance::where('class_session_id', $classSession->id)->exists(),
            'students' => Ui::options($roster, fn (Student $st) => "{$st->name} ({$st->code})"),
            // Nhận xét từng học sinh chỉ nhập ở đây (màn Nhận xét riêng của Cổng GV đã bỏ); HS vắng bị khóa.
            'remarkStudents' => $this->remarkStudents($class, $classSession, $roster, $homework['status']),
            // BTVN của buổi trước: mỗi hạng mục đã giao một cột (✓ đúng hạn · đồng hồ nộp trễ · ✗ chưa nộp).
            'homeworkColumns' => $homework['columns'],
            'homeworkTitle' => $homework['title'],
            // Cột "Thực hành" do GV đặt tên; buổi chưa có báo cáo lấy theo báo cáo gần nhất của lớp.
            'practiceColumns' => $this->practiceColumns($class, $report),
            'incidents' => $this->sessionIncidents($classSession)->map(fn (StaffReport $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'content' => $r->content,
                'severity' => $r->severity ?: 'normal',
                'status' => $r->status,
            ])->values()->all(),
            'severities' => self::SEVERITIES,
            'remarkOptions' => TeacherPortalController::REMARK_OPTIONS,
            'trialGuests' => TeacherPortalController::trialGuestsProps(TeacherPortalController::sessionTrialGuests($classSession)),
            // Học sinh đã có trong danh sách cần chú ý chung của buổi (vắng buổi này / TA báo) — GV không phải nhập lại.
            'sharedAttention' => $shared->map(fn ($row) => [
                'student' => $row->student?->name,
                'reason' => $row->reason,
                'action' => $row->action_plan,
                'source' => $row->source_label,
            ])->values()->all(),
            'suggestions' => $report ? [] : $this->suggestions($class, $classSession, $roster, $shared->pluck('student_id')),
            'report' => $report ? [
                'content' => $report->content,
                'class_situation' => $report->class_situation,
                'proposal' => $report->proposal,
                'attention' => collect($report->attention ?? [])->map(fn ($row) => [
                    'student_id' => (string) ($row['student_id'] ?? ''),
                    'reason' => $row['reason'] ?? '',
                    'action' => $row['action'] ?? '',
                    'student' => $roster->firstWhere('id', (int) ($row['student_id'] ?? 0))?->name,
                ])->values()->all(),
                'submitted_at' => $report->submitted_at->format('H:i d/m/Y'),
                'updated_at' => $report->updated_at?->format('H:i d/m/Y'),
                'late' => $report->setRelation('session', $classSession)->isLate(),
                'teacher' => $report->teacher?->name,
            ] : null,
            // Điền sẵn "Nội dung đã dạy" từ giáo trình của buổi.
            'lessonText' => $lesson['summary'] ?? null,
            'deadline' => $deadline->format('H:i d/m/Y'),
            'overdue' => ! $report && $deadline->isPast(),
            'canSubmit' => $canSubmit,
            // Nộp báo cáo = ghi nhận "Đã dạy" (chủ dự án 27/10/2026): GV chọn dùng buổi giãn đã duyệt nếu dạy chưa xong bài.
            'syllabus' => $this->syllabusState($class, $classSession),
        ]);
    }

    /** @return array{marked: bool, confirmed: bool, extra_left: int}|null null = lớp không học theo syllabus */
    private function syllabusState(ClassModel $class, ClassSession $session): ?array
    {
        $taught = app(LessonTaught::class);
        $assignment = $taught->openAssignment($class);
        if (! $assignment) {
            return null;
        }

        return [
            'marked' => $taught->isMarked($assignment, $session),
            'confirmed' => $assignment->isConfirmed(),
            'extra_left' => (int) app(ClassSyllabus::class)->timeline($assignment)['extra_left'],
        ];
    }

    public function store(Request $request, int $id, int $session): RedirectResponse
    {
        [$class, $classSession] = $this->resolve($request, $id, $session);
        $user = $request->user();
        abort_unless(TeacherSessionReport::responsibleTeacherId($classSession, $class) === (int) $user->id, 403, 'Chỉ giáo viên chính của buổi nộp báo cáo buổi.');

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
            'class_situation' => 'nullable|string|max:5000',
            'proposal' => 'nullable|string|max:5000',
            'attention' => 'nullable|array|max:50',
            'attention.*.student_id' => 'nullable|integer',
            'attention.*.reason' => 'nullable|string|max:1000',
            'attention.*.action' => 'nullable|string|max:1000',
            'remarks' => 'nullable|array',
            'remarks.*' => 'array',
            'remarks.*.bonus' => 'nullable|numeric|min:0|max:999',
            'remarks.*.hw_score' => 'nullable|numeric|min:0|max:100',
            'remarks.*.practice' => 'nullable|array|max:'.self::MAX_PRACTICE_COLUMNS,
            'remarks.*.practice.*' => 'nullable|string|max:255',
            'remarks.*.grammar' => 'nullable|string|max:255',
            // Cột Monsters của bản báo cáo cũ (thay bằng Điểm thưởng) — vẫn nhận để không mất dữ liệu cũ khi gửi lại.
            'remarks.*.monsters_group' => 'nullable|string|max:255',
            'remarks.*.monsters_bonus' => 'nullable|string|max:255',
            'remarks.*.attitude' => 'nullable|string|max:255',
            'remarks.*.result' => 'nullable|string|max:255',
            'remarks.*.comment' => 'nullable|string|max:2000',
            'practice_columns' => 'nullable|array|max:'.self::MAX_PRACTICE_COLUMNS,
            'practice_columns.*' => 'nullable|string|max:60',
            'incidents' => 'nullable|array|max:20',
            'incidents.*.id' => 'nullable|integer',
            'incidents.*.title' => 'nullable|string|max:255',
            'incidents.*.content' => 'nullable|string|max:5000',
            'incidents.*.severity' => 'nullable|in:'.implode(',', array_keys(self::SEVERITIES)),
            'use_extra' => 'nullable|boolean',
        ], [
            'content.required' => 'Vui lòng nhập "Nội dung đã dạy".',
            'remarks.*.bonus.*' => 'Điểm thưởng là số từ 0 đến 999.',
            'remarks.*.hw_score.*' => 'Điểm BTVN là số từ 0 đến 100.',
        ]);
        foreach ($validated['incidents'] ?? [] as $i => $row) {
            if (blank($row['title'] ?? null) && filled($row['content'] ?? null)) {
                throw ValidationException::withMessages(["incidents.{$i}.title" => 'Nhập tiêu đề sự vụ.']);
            }
        }
        if (! StudentAttendance::where('class_session_id', $classSession->id)->exists()) {
            throw ValidationException::withMessages(['attendance' => 'Cần điểm danh buổi này trước khi nộp báo cáo buổi.']);
        }

        $rosterIds = $class->rosterStudents()->pluck('id')->map(fn ($sid) => (int) $sid);
        $attention = collect($validated['attention'] ?? [])
            ->filter(fn (array $row) => filled($row['student_id'] ?? null) && $rosterIds->contains((int) $row['student_id']))
            ->map(fn (array $row) => [
                'student_id' => (int) $row['student_id'],
                'reason' => trim((string) ($row['reason'] ?? '')),
                'action' => trim((string) ($row['action'] ?? '')),
            ])->unique('student_id')->values()->all();

        // Cột thực hành gửi theo vị trí (practice_columns[i]); nhận xét gửi practice[i] → lưu theo tên cột. Cột trống tên bị bỏ.
        $practiceColumns = collect($validated['practice_columns'] ?? [])->map(fn ($label) => trim((string) $label));
        $remarks = collect($validated['remarks'] ?? [])->map(function (array $remark) use ($practiceColumns) {
            $remark['practice'] = $practiceColumns->mapWithKeys(fn (string $label, $idx) => $label === '' ? [] : [$label => trim((string) ($remark['practice'][$idx] ?? ''))])
                ->filter(fn ($v) => $v !== '')->all();
            if (! $remark['practice']) {
                unset($remark['practice']);
            }

            return collect($remark)->map(fn ($v) => is_string($v) ? trim($v) : $v)->all();
        })->all();
        $practiceColumns = $practiceColumns->filter()->unique()->values()->all();

        $report = TeacherSessionReport::firstOrNew(['class_session_id' => $classSession->id]);
        $report->fill([
            'class_id' => $class->id,
            'teacher_id' => $user->id,
            'content' => trim($validated['content']),
            'class_situation' => filled($validated['class_situation'] ?? null) ? trim($validated['class_situation']) : null,
            'proposal' => filled($validated['proposal'] ?? null) ? trim($validated['proposal']) : null,
            'attention' => $attention ?: null,
            'practice_columns' => $practiceColumns,
        ]);
        $report->submitted_at ??= now();
        $created = ! $report->exists;
        $urgent = DB::transaction(function () use ($report, $class, $classSession, $user, $validated, $remarks, $rosterIds) {
            $report->save();
            // Nhận xét học sinh gửi kèm báo cáo = "Lưu nhận xét" (bản chính thức) ở màn Nhận xét. Không gõ gì và chưa có bản
            // nhận xét nào → không tạo bản trống (bản trống vẫn tính là "đã gửi nhận xét").
            $typed = collect($remarks)->flatten()->filter(fn ($v) => filled($v))->isNotEmpty();
            if ($typed || TeacherPortalController::sessionRemarks($class, $classSession)->isNotEmpty()) {
                TeacherPortalController::saveSessionRemarks($class, $classSession, (int) $user->id, $remarks, $rosterIds, false);
            }
            // Một danh sách "Học sinh cần chú ý" chung với TA; "Nội dung đã dạy" chỉ GV nhập — báo cáo trực lớp của buổi lấy lại.
            app(SupportListService::class)->syncTeacherReport($report, $classSession);
            ClassReport::where('class_session_id', $classSession->id)->update(['topics_learned' => $report->content]);

            return $this->syncIncidents($class, $classSession, $user, $validated['incidents'] ?? []);
        });
        foreach ($urgent as $entry) {
            StaffReportController::notifyUrgent($entry, $user);
        }

        $message = $created
            ? ($report->setRelation('session', $classSession)->isLate() ? 'Đã nộp báo cáo buổi (nộp trễ hạn).' : 'Đã nộp báo cáo buổi.')
            : 'Đã cập nhật báo cáo buổi.';
        $message .= $this->markTaught($class, $classSession, $user, ! empty($validated['use_extra']));

        return redirect()->route('classes.show', ['id' => $class->id, 'tab' => 'sessions'])->with('success', $message);
    }

    /**
     * Nộp báo cáo buổi = ghi nhận "Đã dạy" vào tiến độ chặng (thay nút "Đã dạy" ở Bài giảng hôm nay, chủ dự án 27/10/2026).
     * Không ghi nhận được (chặng chưa xác nhận, buổi trước chưa có báo cáo…) thì báo cáo vẫn lưu, kèm lời nhắc.
     */
    private function markTaught(ClassModel $class, ClassSession $session, User $user, bool $useExtra): string
    {
        $taught = app(LessonTaught::class);
        $assignment = $taught->openAssignment($class);
        if (! $assignment || $taught->isMarked($assignment, $session)
            || ! in_array($session->type, [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP], true)
            || $session->date->lt(($assignment->start_date ?? $assignment->opened_at)->copy()->startOfDay())) {
            return '';
        }
        try {
            return ' Đã ghi nhận Đã dạy '.$taught->mark($class, $assignment, $session, $user, null, $useExtra).'.';
        } catch (ValidationException $e) {
            return ' Chưa ghi nhận Đã dạy: '.collect($e->errors())->flatten()->first();
        }
    }

    /**
     * Sự vụ trong buổi (chủ dự án: "sự vụ đưa luôn vào báo cáo buổi"): mỗi dòng là một mục Nhật ký sự vụ của lớp, gắn buổi qua
     * data.class_session_id. Dòng bỏ khỏi form → xóa mục đó (chỉ mục do báo cáo buổi tạo, chưa xử lý xong).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<StaffReport> sự vụ Khẩn mới (hoặc vừa nâng lên Khẩn) cần báo ngay
     */
    private function syncIncidents(ClassModel $class, ClassSession $session, User $user, array $rows): array
    {
        $existing = $this->sessionIncidents($session)->keyBy('id');
        $kept = [];
        $urgent = [];
        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $attrs = [
                'title' => $title,
                'content' => filled($row['content'] ?? null) ? trim($row['content']) : null,
                'severity' => $row['severity'] ?? 'normal',
            ];
            $entry = filled($row['id'] ?? null) ? $existing->get((int) $row['id']) : null;
            if ($entry) {
                $wasUrgent = $entry->severity === 'urgent';
                $entry->update($attrs);
            } else {
                $wasUrgent = false;
                $entry = StaffReport::create($attrs + [
                    'user_id' => $user->id,
                    'class_id' => $class->id,
                    'type' => 'journal',
                    'report_date' => $session->date->toDateString(),
                    'status' => 'open',
                    'data' => ['class_session_id' => $session->id, 'source' => self::INCIDENT_SOURCE],
                ]);
            }
            $kept[] = $entry->id;
            if ($entry->severity === 'urgent' && ! $wasUrgent) {
                $urgent[] = $entry;
            }
        }
        $existing->except($kept)->filter(fn (StaffReport $r) => $r->status === 'open')->each->delete();

        return $urgent;
    }

    /** @return Collection<int, StaffReport> sự vụ báo cáo buổi đã ghi cho buổi này */
    private function sessionIncidents(ClassSession $session): Collection
    {
        return StaffReport::where('type', 'journal')->where('class_id', $session->class_id)
            ->where('data->class_session_id', $session->id)->where('data->source', self::INCIDENT_SOURCE)
            ->orderBy('id')->get();
    }

    /** @return list<string> */
    private function practiceColumns(ClassModel $class, ?TeacherSessionReport $report): array
    {
        if ($report?->practice_columns) {
            return array_values($report->practice_columns);
        }
        $last = TeacherSessionReport::where('class_id', $class->id)->whereNotNull('practice_columns')->latest('submitted_at')->latest('id')->value('practice_columns');
        $last = is_string($last) ? json_decode($last, true) : $last;

        return $last ? array_values((array) $last) : self::DEFAULT_PRACTICE_COLUMNS;
    }

    /**
     * BTVN giao ở buổi trước (buổi thường / học bù gần nhất trước buổi này): cột = hạng mục đã giao, trạng thái từng học sinh
     * theo giỏ nộp của hạng mục — done (đúng hạn) · late (nộp sau hạn) · missing (chưa nộp).
     *
     * @return array{title: ?string, columns: list<array{key: string, label: string, icon: string}>, status: array<int, array<string, string>>}
     */
    private function previousHomework(ClassModel $class, ClassSession $session, Collection $roster): array
    {
        $empty = ['title' => null, 'columns' => [], 'status' => []];
        $previous = ClassSession::where('class_id', $class->id)->whereKeyNot($session->id)
            ->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP])->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereDate('date', '<', $session->date->toDateString())
                ->orWhere(fn ($q) => $q->whereDate('date', $session->date->toDateString())->where('start_time', '<', $session->start_time?->format('H:i:s') ?? '00:00:00')))
            ->orderByDesc('date')->orderByDesc('start_time')->first();
        if (! $previous) {
            return $empty;
        }
        $homework = Homework::where('class_id', $class->id)
            ->where(fn ($q) => $q->where('class_session_id', $previous->id)
                ->orWhere(fn ($q) => $q->whereNull('class_session_id')->whereDate('created_at', $previous->date->toDateString())))
            ->latest('id')->first();
        $categories = collect($homework?->items ?? [])->filter(fn ($text) => filled($text))->keys();
        if (! $homework || $categories->isEmpty()) {
            return $empty;
        }
        $due = $homework->due_at ?? $homework->due_date?->copy()->endOfDay();
        $submissions = HomeworkSubmission::where('homework_id', $homework->id)->get()->groupBy('student_id');
        $status = [];
        foreach ($roster as $student) {
            $mine = $submissions->get($student->id, collect())->keyBy('basket');
            foreach ($categories as $cat) {
                $sub = $mine->get(Homework::basketOf($cat));
                $status[$student->id][$cat] = ! $sub ? 'missing'
                    : ($due && $sub->submitted_at && $sub->submitted_at->gt($due) ? 'late' : 'done');
            }
        }

        return [
            'title' => 'BTVN buổi '.$previous->date->format('d/m'),
            'columns' => $categories->map(fn ($cat) => [
                'key' => $cat,
                'label' => Homework::CATEGORIES[$cat][0] ?? $cat,
                'icon' => Homework::CATEGORIES[$cat][1] ?? 'assignment',
            ])->values()->all(),
            'status' => $status,
        ];
    }

    /** @return array{0: ClassModel, 1: ClassSession} */
    private function resolve(Request $request, int $id, int $session): array
    {
        $class = ClassModel::query()->visibleTo($request->user())->find($id);
        abort_unless($class, 403);
        $classSession = ClassSession::where('class_id', $class->id)->whereKey($session)
            ->where('status', '!=', 'cancelled')->whereDate('date', '<=', today()->toDateString())
            ->firstOrFail();

        return [$class, $classSession];
    }

    /**
     * Việc của GV trong buổi: số liệu chung với báo cáo trực lớp (sĩ số, đi muộn, BTVN, nhận xét) + "Đã dạy bài".
     * GV nộp thì mỗi mục kèm link mở đúng màn để làm / xem.
     *
     * @return list<array<string, mixed>>
     */
    private function checklist(ClassModel $class, ClassSession $session, Collection $roster, bool $withLinks): array
    {
        $metrics = collect(ClassReportSessionMetrics::forSessions(collect([$session]), $roster)[$session->id] ?? [])->keyBy('key');
        $progress = ClassLessonProgress::with('lesson:id,title,session_no')->where('class_session_id', $session->id)->latest('taught_at')->first();
        $attendance = $metrics->get('attendance');
        $homework = $metrics->get('homework');
        $attended = StudentAttendance::where('class_session_id', $session->id)->exists();
        $assigned = Homework::where('class_id', $class->id)
            ->where(fn ($q) => $q->where('class_session_id', $session->id)
                ->orWhere(fn ($q) => $q->whereNull('class_session_id')->whereDate('created_at', $session->date->toDateString())))
            ->latest('id')->first(['id', 'title']);
        $link = fn (string $label, string $url) => $withLinks ? ['label' => $label, 'url' => $url] : null;

        return array_values(array_filter([
            [
                'key' => 'attendance',
                'label' => 'Điểm danh',
                'required' => true,
                'done' => $attended,
                'value' => $attended ? 'Có mặt '.($attendance['value'] ?? '—').' · Đi muộn '.($metrics->get('late')['value'] ?? '0') : 'Chưa điểm danh',
                'details' => $attended ? ($attendance['details'] ?? []) : [],
                'link' => $link($attended ? 'Sửa điểm danh' : 'Điểm danh', route('teacher.attendance', ['classId' => $class->id, 'session' => $session->id])),
                'drawer' => true,
            ],
            [
                'key' => 'taught',
                'label' => 'Đã dạy bài',
                'done' => (bool) $progress,
                'value' => $progress
                    ? 'Đã đánh dấu'.($progress->kind === ClassLessonProgress::KIND_EXTRA ? ' · buổi giãn' : ($progress->lesson?->title ? ' · '.$progress->lesson->title : ''))
                    : 'Nộp báo cáo buổi là ghi nhận Đã dạy',
                'details' => $progress?->pace_note ? [['text' => 'Ghi chú tiến độ: '.$progress->pace_note]] : [],
                'link' => $link('Xem bài giảng', route('teacher.lesson-today', ['class' => $class->id])),
            ],
            [
                'key' => 'homework',
                'label' => 'Bài tập về nhà',
                'done' => (bool) $assigned,
                'value' => $assigned ? 'Đã giao: '.$assigned->title : 'Chưa giao trong buổi',
                'details' => $homework && ($homework['value'] ?? '—') !== '—' ? [['text' => 'Bài giao trước buổi: đã nộp '.$homework['value']]] : [],
                // Mở ngăn kéo "Giao bài" ngay trên báo cáo (cùng route màn Bài tập, gửi kèm header remote modal).
                'link' => $link($assigned ? 'Sửa / giao thêm' : 'Giao bài', route('teacher.homework', ['classId' => $class->id, 'session' => $session->id])),
                'drawer' => true,
            ],
        ]));
    }

    /**
     * Danh sách học sinh để nhận xét: trạng thái điểm danh của buổi + nhận xét đã lưu.
     *
     * @return list<array<string, mixed>>
     */
    private function remarkStudents(ClassModel $class, ClassSession $session, Collection $roster, array $homework = []): array
    {
        $attendance = StudentAttendance::where('class_session_id', $session->id)->pluck('status', 'student_id');
        $saved = TeacherPortalController::sessionRemarks($class, $session);

        return $roster->map(function (Student $student) use ($attendance, $saved, $homework) {
            $status = $attendance->get($student->id) ?? 'none';

            return [
                'id' => $student->id,
                'name' => $student->name,
                'att_status' => $status,
                'absent' => in_array($status, ['absent', 'excused'], true),
                'remark' => TeacherPortalController::remarkFields((array) ($saved->get($student->id) ?? $saved->get((string) $student->id) ?? [])),
                'homework' => (object) ($homework[$student->id] ?? []),
            ];
        })->values()->all();
    }

    /**
     * Gợi ý "Học sinh cần chú ý": được GV nhận xét "Cần cố gắng" / "Không đạt" mà chưa có trong danh sách chung của buổi
     * (học sinh vắng đã tự vào danh sách chung).
     *
     * @param  Collection<int, int|string>  $listed
     * @return list<array{student_id: string, reason: string, action: string}>
     */
    private function suggestions(ClassModel $class, ClassSession $session, Collection $roster, Collection $listed): array
    {
        $ids = $roster->pluck('id')->map(fn ($sid) => (int) $sid)->diff($listed->map(fn ($sid) => (int) $sid));
        $rows = collect();
        $record = AcademicRecord::where('module', 'teacher_remarks')->where('record_code', TeacherPortalController::remarkRecordCode($session))->first();
        foreach ((array) ($record?->data ?? []) as $studentId => $remark) {
            $remark = (array) $remark;
            $weak = collect(self::WEAK_REMARKS)->filter(fn (string $value, string $field) => ($remark[$field] ?? null) === $value)->keys();
            if ($weak->isNotEmpty() && $ids->contains((int) $studentId) && ! $rows->has((int) $studentId)) {
                $labels = ['grammar' => 'Ngữ pháp', 'attitude' => 'Thái độ', 'result' => 'Kết quả'];
                $rows->put((int) $studentId, 'Nhận xét: '.$weak->map(fn ($f) => $labels[$f].' '.mb_strtolower(self::WEAK_REMARKS[$f]))->implode(', '));
            }
        }

        return $rows->map(fn (string $reason, int $studentId) => ['student_id' => (string) $studentId, 'reason' => $reason, 'action' => ''])->values()->all();
    }
}
