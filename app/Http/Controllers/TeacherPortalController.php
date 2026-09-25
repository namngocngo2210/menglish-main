<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Homework;
use App\Models\MiniTestScore;
use App\Models\PayrollPeriod;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cổng Giáo viên: lịch dạy theo ngày/tuần (từ buổi học thật), check-in nhiều ca cùng lúc, và
 * điểm danh theo từng buổi học. Giáo viên / GVNN / trợ giảng dùng cho lớp mình; Học vụ, Học thuật,
 * Quản lý cơ sở (trong phạm vi lớp được xem) và Admin điểm danh thay khi cần.
 */
class TeacherPortalController extends Controller
{
    /** Các vai trò được truy cập cổng giáo viên. */
    private const TEACHER_ROLES = ['teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant', 'academic_staff', 'academic_lead', 'manager'];

    /** Vai trò được điểm danh thay giáo viên (trong phạm vi lớp mình được xem). */
    private const ON_BEHALF_ROLES = ['academic_staff', 'academic_lead', 'manager'];

    /** Số ngày nhìn lại để nhắc "buổi chưa điểm danh" (điểm danh bù). */
    private const MAKEUP_LOOKBACK_DAYS = 30;

    private function guardTeacher(): void
    {
        $user = Auth::user();
        abort_unless(
            $user && ($user->hasAnyRole(self::TEACHER_ROLES) || $user->hasRole('admin')),
            403,
            'Chỉ giáo viên / trợ giảng mới truy cập được cổng này.'
        );
    }

    /** Các lớp giáo viên hiện tại phụ trách (chính / trợ giảng / GVNN). */
    private function myClasses(int $teacherId)
    {
        return ClassModel::with(['branch', 'course'])
            ->where('status', 'active')
            ->where(function ($q) use ($teacherId) {
                $q->where('teacher_id', $teacherId)
                    ->orWhere('assistant_id', $teacherId)
                    ->orWhere('foreign_teacher_id', $teacherId);
            })
            ->orderBy('name')
            ->get();
    }

    /** Người dùng là GV / GVNN / TA của lớp hoặc của buổi học. */
    private function isAssignedStaff(User $user, ClassModel $class, ?ClassSession $session = null): bool
    {
        $ids = [$class->teacher_id, $class->assistant_id, $class->foreign_teacher_id];
        if ($session) {
            array_push($ids, $session->teacher_id, $session->assistant_id, $session->foreign_teacher_id);
        }

        return in_array((int) $user->id, array_map('intval', array_filter($ids)), true);
    }

    /** Học vụ / Học thuật / Quản lý (trong phạm vi lớp được xem) hoặc Admin: điểm danh thay GV. */
    private function canActOnBehalf(User $user, ClassModel $class): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->hasAnyRole(self::ON_BEHALF_ROLES)
            && ClassModel::query()->visibleTo($user)->whereKey($class->id)->exists();
    }

    private function authorizeClass(ClassModel $class, ?ClassSession $session = null): void
    {
        $user = Auth::user();
        abort_unless($user && ($this->isAssignedStaff($user, $class, $session) || $this->canActOnBehalf($user, $class)),
            403, 'Bạn không được phân công phụ trách lớp này.');
    }

    /**
     * Buổi học người dùng được phân công: theo nhân sự của buổi, hoặc buổi chưa gán GV thì theo
     * nhân sự của lớp.
     */
    private function staffSessionsQuery(int $userId): Builder
    {
        return ClassSession::query()->where(function (Builder $q) use ($userId) {
            $q->forStaff($userId)
                ->orWhere(fn (Builder $q) => $q->whereNull('teacher_id')
                    ->whereHas('classModel', fn (Builder $c) => $c->where(fn (Builder $c) => $c->where('teacher_id', $userId)
                        ->orWhere('assistant_id', $userId)
                        ->orWhere('foreign_teacher_id', $userId))));
        });
    }

    /**
     * Lịch dạy của tôi: các buổi hôm nay (check-in + điểm danh), lịch cả tuần và các buổi đã
     * qua chưa điểm danh (điểm danh bù) — tất cả lấy từ buổi học thật (class_sessions).
     */
    public function home(Request $request)
    {
        $this->guardTeacher();
        $teacher = Auth::user();
        $today = now()->toDateString();

        $weekAnchor = $this->parseDate($request->query('week')) ?? now();
        $weekStart = $weekAnchor->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(6);

        $with = ['classModel.branch', 'classModel.course', 'supportSession.student'];
        $todaySessions = $this->staffSessionsQuery($teacher->id)->with($with)
            ->whereDate('date', $today)
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time')
            ->get();

        $weekSessions = $this->staffSessionsQuery($teacher->id)->with($with)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('date')->orderBy('start_time')
            ->get();

        $pendingSessions = $this->staffSessionsQuery($teacher->id)->with($with)
            ->whereDate('date', '<', $today)
            ->whereDate('date', '>=', now()->subDays(self::MAKEUP_LOOKBACK_DAYS)->toDateString())
            ->where('status', '!=', 'cancelled')
            ->whereDoesntHave('attendances')
            ->orderByDesc('date')->orderBy('start_time')
            ->get();

        $sessionIds = $todaySessions->pluck('id');
        $checkins = TeacherTimesheet::where('user_id', $teacher->id)
            ->whereIn('class_session_id', $sessionIds)
            ->get()
            ->keyBy('class_session_id');
        $attendanceDone = StudentAttendance::whereIn('class_session_id', $weekSessions->pluck('id')->merge($sessionIds))
            ->distinct()
            ->pluck('class_session_id')
            ->flip();

        ClassModel::loadRosterCounts($todaySessions->pluck('classModel')->filter()->unique('id'));

        $shifts = $todaySessions->map(function (ClassSession $session) use ($checkins, $attendanceDone) {
            $ts = $checkins->get($session->id);

            return [
                'session' => $session,
                'class' => $session->classModel,
                'scheduled_time' => $session->start_time?->format('H:i').' - '.$session->end_time?->format('H:i'),
                'checked_in' => $ts && ! empty($ts->checkin_time),
                'checkin_time' => $ts?->checkin_time,
                'attendance_done' => $attendanceDone->has($session->id),
                'student_count' => $session->type === ClassSession::TYPE_SUPPORT ? 1 : (int) $session->classModel?->roster_count,
            ];
        });

        $stats = [
            'total' => $shifts->count(),
            'checked_in' => $shifts->where('checked_in', true)->count(),
            'attendance_done' => $shifts->where('attendance_done', true)->count(),
            'pending' => $pendingSessions->count(),
        ];

        $weekDays = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $weekSessions) {
            $day = $weekStart->copy()->addDays($offset);

            return [
                'date' => $day,
                'sessions' => $weekSessions->filter(fn (ClassSession $s) => $s->date->isSameDay($day))->values(),
            ];
        });

        return view('teacher.home', compact(
            'teacher', 'shifts', 'stats', 'today', 'weekDays', 'weekStart', 'pendingSessions', 'attendanceDone'
        ));
    }

    /**
     * Check-in NHIỀU ca cùng lúc: tick các buổi học hôm nay (session_ids) — hoặc theo lớp
     * (class_ids, giữ tương thích) — rồi bấm check-in.
     */
    public function checkin(Request $request)
    {
        $this->guardTeacher();
        $teacher = Auth::user();

        $validated = $request->validate([
            'session_ids' => 'required_without:class_ids|array|min:1',
            'session_ids.*' => 'integer|exists:class_sessions,id',
            'class_ids' => 'required_without:session_ids|array|min:1',
            'class_ids.*' => 'exists:classes,id',
        ], [
            'session_ids.required_without' => 'Vui lòng chọn ít nhất 1 ca dạy để check-in.',
            'class_ids.required_without' => 'Vui lòng chọn ít nhất 1 ca dạy để check-in.',
        ]);

        $now = now();
        if (PayrollPeriod::isLockedFor($now)) {
            $message = PayrollPeriod::lockedMessage($now);

            return back()->withErrors(['class_ids' => $message])->with('error', $message);
        }

        // Chỉ tính công cho buổi học có thật trên lịch hôm nay được phân công cho người này;
        // không có buổi thì không tạo chấm công mặc định.
        $targets = [];
        $skipped = [];
        foreach ($validated['session_ids'] ?? [] as $sessionId) {
            $session = ClassSession::with('classModel')->find($sessionId);
            $class = $session?->classModel;
            if (! $session || ! $class || ! $session->date->isSameDay($now) || $session->status === 'cancelled'
                || ! $this->staffSessionsQuery($teacher->id)->whereKey($session->id)->exists()) {
                $skipped[] = ($class?->name ?? "Buổi #{$sessionId}").': không phải buổi hôm nay được phân công cho bạn';

                continue;
            }
            $targets[$session->id] = [$class, $session];
        }
        foreach ($validated['class_ids'] ?? [] as $classId) {
            $class = ClassModel::findOrFail($classId);
            $this->authorizeClass($class);
            $sessions = ClassSession::where('class_id', $classId)
                ->whereDate('date', $now->toDateString())
                ->where('status', '!=', 'cancelled')
                ->orderBy('start_time')
                ->get();
            $session = $sessions->first(fn (ClassSession $s) => in_array($teacher->id, array_map('intval', [$s->teacher_id, $s->assistant_id, $s->foreign_teacher_id]), true))
                ?? $sessions->first(fn (ClassSession $s) => $s->teacher_id === null
                    && in_array($teacher->id, [(int) $class->teacher_id, (int) $class->assistant_id, (int) $class->foreign_teacher_id], true));

            if (! $session) {
                $skipped[] = "{$class->name}: không có buổi học hôm nay được phân công cho bạn";

                continue;
            }
            $targets[$session->id] = [$class, $session];
        }

        $count = 0;
        foreach ($targets as [$class, $session]) {
            $duplicate = TeacherTimesheet::findDuplicate($teacher->id, (int) $class->id, $now->toDateString(), $session->id);
            if ($duplicate && $duplicate->source === TeacherTimesheet::SOURCE_MANUAL) {
                $skipped[] = "{$class->name}: buổi này đã được Học vụ chấm công tay";

                continue;
            }

            $hours = max(0.5, abs(Carbon::parse($session->start_time)->diffInMinutes(Carbon::parse($session->end_time))) / 60);
            TeacherTimesheet::updateOrCreate(
                ['user_id' => $teacher->id, 'class_session_id' => $session->id],
                [
                    'class_id' => $class->id,
                    'teaching_date' => $now->toDateString(),
                    'scheduled_time' => $session->start_time->format('H:i').'-'.$session->end_time->format('H:i'),
                    'checkin_time' => $now->format('H:i'),
                    'hours' => $hours,
                    'type' => $session->type === ClassSession::TYPE_SUPPORT ? '1on1' : 'regular',
                    'source' => TeacherTimesheet::SOURCE_CHECKIN,
                    'status' => 'pending_review',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                ]
            );
            $count++;
        }

        if ($count === 0) {
            $message = 'Không check-in được ca nào — '.implode('; ', $skipped).'.';

            return back()->withErrors(['class_ids' => $message])->with('error', $message);
        }

        if ($skipped !== []) {
            return back()->with('success', "Đã check-in {$count} ca dạy hôm nay.")
                ->with('error', 'Bỏ qua: '.implode('; ', $skipped).'.');
        }

        return back()->with('success', "Đã check-in thành công {$count} ca dạy hôm nay!");
    }

    /**
     * Điểm danh theo từng buổi học. Buổi được chọn qua ?session={id} (link từ dashboard lớp /
     * lịch dạy) hoặc ?date=Y-m-d; mặc định là buổi hôm nay. Cho phép điểm danh bù buổi đã qua,
     * buổi học bù / phụ đạo; không cho buổi đã hủy hoặc chưa tới ngày.
     */
    public function attendance(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::with(['branch', 'teacher'])->findOrFail($classId);
        $user = Auth::user();

        $session = $this->resolveSession($request, $class, $user);
        $this->authorizeClass($class, $session);

        $existing = $session
            ? StudentAttendance::with('recorder')->where('class_session_id', $session->id)->get()->keyBy('student_id')
            : collect();
        $students = $session ? $this->sessionRoster($class, $session, $existing) : collect();
        $blockReason = $session ? $this->attendanceBlockReason($session) : null;
        $onBehalf = ! $this->isAssignedStaff($user, $class, $session);
        $today = ($session?->date ?? now())->toDateString();

        // Các buổi gần đây của lớp để chọn điểm danh bù / buổi học bù.
        $recentSessions = ClassSession::where('class_id', $class->id)
            ->whereDate('date', '>=', now()->subDays(self::MAKEUP_LOOKBACK_DAYS)->toDateString())
            ->whereDate('date', '<=', now()->toDateString())
            ->withCount('attendances')
            ->orderByDesc('date')->orderByDesc('start_time')
            ->get();

        return view('teacher.attendance', compact(
            'class', 'session', 'students', 'existing', 'today', 'blockReason', 'onBehalf', 'recentSessions'
        ));
    }

    /**
     * Lưu điểm danh của một buổi học (class_session_id). Học vụ/Quản lý lưu thay thì
     * user_id vẫn là GV của buổi, recorded_by là người lưu.
     */
    public function attendanceStore(Request $request, int $classId)
    {
        $this->guardTeacher();
        $user = Auth::user();
        $class = ClassModel::findOrFail($classId);

        $validated = $request->validate([
            'class_session_id' => 'nullable|integer',
            'status' => 'required|array',
            'status.*' => 'in:present,absent,late,excused',
            'note' => 'nullable|array',
            'note.*' => 'nullable|string|max:500',
        ]);

        $session = $this->resolveSession($request, $class, $user);
        $this->authorizeClass($class, $session);
        if (! $session) {
            return back()->withErrors(['session' => 'Lớp không có buổi học trong ngày này. Hãy chọn buổi cần điểm danh.']);
        }
        if ($reason = $this->attendanceBlockReason($session)) {
            return back()->withErrors(['session' => $reason]);
        }

        $existing = StudentAttendance::where('class_session_id', $session->id)->get()->keyBy('student_id');
        $rosterIds = $this->sessionRoster($class, $session, $existing)->pluck('id')->map(fn ($id) => (int) $id);
        foreach (array_keys($validated['status']) as $studentId) {
            abort_unless($rosterIds->contains((int) $studentId), 422, 'Học viên không thuộc lớp/buổi học này.');
        }

        $teacherId = $this->isAssignedStaff($user, $class, $session)
            ? $user->id
            : ($session->teacher_id ?? $class->teacher_id ?? $user->id);

        $count = DB::transaction(function () use ($validated, $session, $class, $teacherId, $user) {
            $count = 0;
            foreach ($validated['status'] as $studentId => $status) {
                StudentAttendance::updateOrCreate(
                    ['class_session_id' => $session->id, 'student_id' => $studentId],
                    [
                        'class_id' => $class->id,
                        'session_date' => $session->date->toDateString(),
                        'user_id' => $teacherId,
                        'recorded_by' => $user->id,
                        'status' => $status,
                        'review_status' => 'pending_review',
                        'reviewed_by' => null,
                        'reviewed_at' => null,
                        'review_note' => null,
                        'note' => $validated['note'][$studentId] ?? null,
                    ]
                );
                $count++;
            }

            // Đã điểm danh nghĩa là buổi đã diễn ra: chốt buổi để không bị xếp lại/ghi đè về sau.
            $session->update(['status' => 'completed']);

            return $count;
        });

        $when = $session->date->isToday() ? 'hôm nay' : 'ngày '.$session->date->format('d/m/Y').' (điểm danh bù)';

        return redirect()->route('teacher.attendance', ['classId' => $class->id, 'session' => $session->id])
            ->with('success', "Đã lưu điểm danh {$count} học viên lớp {$class->name} buổi {$when}.");
    }

    /**
     * Buổi học cần điểm danh: theo id (class_session_id / ?session), hoặc theo ngày (?date,
     * mặc định hôm nay) — ưu tiên buổi người dùng được phân công, rồi buổi chính khóa.
     */
    private function resolveSession(Request $request, ClassModel $class, User $user): ?ClassSession
    {
        $sessionId = $request->input('class_session_id') ?: $request->query('session');
        if ($sessionId) {
            return ClassSession::where('class_id', $class->id)->whereKey($sessionId)->firstOrFail();
        }

        $date = $this->parseDate($request->query('date')) ?? now();
        $sessions = ClassSession::where('class_id', $class->id)
            ->whereDate('date', $date->toDateString())
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time')
            ->get();

        return $sessions->first(fn (ClassSession $s) => in_array((int) $user->id, array_map('intval', [$s->teacher_id, $s->assistant_id, $s->foreign_teacher_id]), true)
            && $s->type !== ClassSession::TYPE_SUPPORT)
            ?? $sessions->firstWhere('type', '!=', ClassSession::TYPE_SUPPORT)
            ?? $sessions->first();
    }

    private function attendanceBlockReason(ClassSession $session): ?string
    {
        if ($session->status === 'cancelled') {
            return 'Buổi học này đã hủy'.($session->holiday_id ? ' (nghỉ lễ)' : '').' — không điểm danh.';
        }
        if ($session->date->isAfter(today())) {
            return 'Buổi học ngày '.$session->date->format('d/m/Y').' chưa diễn ra — chưa điểm danh được.';
        }

        return null;
    }

    /**
     * Học viên của buổi: buổi phụ đạo → học viên được xếp phụ đạo; buổi khác → danh sách lớp
     * thật (current_class_id + lớp liên kết, còn giữ chỗ). Luôn giữ học viên đã có điểm danh
     * của buổi (vd. đã thôi học sau đó) để sửa được dữ liệu cũ.
     */
    private function sessionRoster(ClassModel $class, ClassSession $session, Collection $existing): Collection
    {
        $students = $session->type === ClassSession::TYPE_SUPPORT
            ? Student::whereIn('id', SupportSession::where('class_session_id', $session->id)->pluck('student_id'))->get()
            : $class->rosterStudents();

        $missing = $existing->keys()->map(fn ($id) => (int) $id)->diff($students->pluck('id')->map(fn ($id) => (int) $id));
        if ($missing->isNotEmpty()) {
            $students = $students->concat(Student::whereIn('id', $missing)->get());
        }

        return $students->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    private function parseDate($value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    // ───────────────────────── GIAO BÀI TẬP VỀ NHÀ ─────────────────────────
    public function homework(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        $homeworks = Homework::where('class_id', $classId)->latest('due_date')->latest('id')->get();

        return view('teacher.homework', compact('class', 'homeworks'));
    }

    public function homeworkStore(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        Homework::create([
            'class_id' => $classId,
            'user_id' => Auth::id(),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
        ]);

        return back()->with('success', 'Đã giao bài tập về nhà cho lớp!');
    }

    public function homeworkDestroy(Request $request, int $classId, int $homeworkId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        Homework::where('class_id', $classId)->where('id', $homeworkId)->firstOrFail()->delete();

        return back()->with('success', 'Đã xoá bài tập!');
    }

    // ───────────────────────── NHẬP ĐIỂM MINI TEST ─────────────────────────
    public function scores(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        // Danh sách lớp thật (gồm học viên liên kết lớp khác, bỏ Thôi học/Hoàn thành/Bảo lưu).
        $class->setRelation('students', $class->rosterStudents());
        $testDate = $request->input('test_date', now()->toDateString());
        $testName = $request->input('name', 'Mini Test');

        $existing = MiniTestScore::where('class_id', $classId)
            ->where('name', $testName)
            ->whereDate('test_date', $testDate)
            ->get()
            ->keyBy('student_id');

        return view('teacher.scores', compact('class', 'existing', 'testDate', 'testName'));
    }

    public function scoresStore(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'test_date' => 'required|date',
            'max_score' => 'required|numeric|min:1',
            'score' => 'required|array',
            'score.*' => 'nullable|numeric|min:0',
            'note' => 'nullable|array',
        ]);

        $count = 0;
        foreach ($validated['score'] as $studentId => $score) {
            if ($score === null || $score === '') {
                continue;
            }
            abort_unless($class->hasOnRoster((int) $studentId), 422, 'Học viên không thuộc lớp này.');
            abort_if((float) $score > (float) $validated['max_score'], 422, 'Điểm không được vượt quá điểm tối đa.');
            MiniTestScore::updateOrCreate(
                [
                    'class_id' => $classId,
                    'student_id' => $studentId,
                    'name' => $validated['name'],
                    'test_date' => $validated['test_date'],
                ],
                [
                    'user_id' => Auth::id(),
                    'score' => $score,
                    'max_score' => $validated['max_score'],
                    'note' => $validated['note'][$studentId] ?? null,
                ]
            );
            $count++;
        }

        return redirect()->route('teacher.home')
            ->with('success', "Đã lưu điểm {$count} học sinh (bài {$validated['name']}) lớp {$class->name}!");
    }

    // ───────────────────────── NHẬN XÉT BỔ SUNG & STUBS ─────────────────────────
    public function remarks(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        // Danh sách lớp thật (gồm học viên liên kết lớp khác, bỏ Thôi học/Hoàn thành/Bảo lưu).
        $class->setRelation('students', $class->rosterStudents());
        $today = now()->toDateString();

        $attendance = StudentAttendance::where('class_id', $classId)
            ->whereDate('session_date', $today)
            ->get()
            ->keyBy('student_id');

        // Use AcademicRecord or a similar JSON store for now to store remarks since there isn't a dedicated table for generic teacher remarks without ClassReport.
        $record = AcademicRecord::where('module', 'teacher_remarks')
            ->where('record_code', $classId.'-'.$today)
            ->first();

        $existing = collect($record ? $record->data : []);

        return view('teacher.remarks', compact('class', 'today', 'attendance', 'existing'));
    }

    public function remarksStore(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        $validated = $request->validate([
            'remarks' => 'nullable|array',
        ]);

        $today = now()->toDateString();

        AcademicRecord::updateOrCreate(
            ['module' => 'teacher_remarks', 'record_code' => $classId.'-'.$today],
            [
                'title' => 'Nhận xét lớp '.$classId.' ngày '.$today,
                'status' => 'completed',
                'user_id' => Auth::id(),
                'data' => $validated['remarks'] ?? [],
            ]
        );

        return redirect()->route('teacher.home')->with('success', 'Đã lưu nhận xét buổi học!');
    }

    // ─────────────────────────────────────────────
    // Các màn hình bổ sung của Cổng GV (dữ liệu thật)
    // ─────────────────────────────────────────────

    /**
     * GV xem các chặng đang dạy và gửi yêu cầu cấp đề test về cho học vụ.
     */
    public function orderTest(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::with(['course', 'syllabusAssignments' => fn ($q) => $q->where('user_id', Auth::id())])
            ->findOrFail($classId);
        $this->authorizeClass($class);

        $assignments = $class->syllabusAssignments;

        // Lịch sử order đề của lớp này (cùng dữ liệu với màn Duyệt & phân phối đề của Học thuật)
        $requests = BigTestOrder::with('reviewer')
            ->where('class_id', $class->id)
            ->latest()
            ->take(10)
            ->get();

        return view('teacher.order-test', compact('class', 'assignments', 'requests'));
    }

    public function submitOrderTest(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);

        $validated = $request->validate([
            'stage_name' => 'required|string|max:255',
            'test_type' => 'required|in:mini,big',
            'exam_date' => 'nullable|date|after_or_equal:today',
            'note' => 'nullable|string|max:1000',
        ]);

        $examDate = isset($validated['exam_date']) ? Carbon::parse($validated['exam_date']) : null;
        $order = BigTestOrder::create([
            'code' => 'ORDTEST-'.strtoupper(Str::random(6)),
            'class_id' => $class->id,
            'teacher_id' => Auth::id(),
            'stage_name' => $validated['stage_name'],
            'test_type' => $validated['test_type'],
            'exam_date' => $examDate,
            // Hạn xử lý: đề phải phân phối trước ngày thi N ngày; không có ngày thi thì trong 3 ngày làm việc.
            'due_date' => $examDate
                ? $examDate->copy()->subDays(BigTestOrder::LEAD_DAYS)->max(today())
                : today()->addDays(BigTestOrder::LEAD_DAYS),
            'note' => $validated['note'] ?? null,
            'status' => 'pending',
        ]);

        AdminNotification::create([
            'type' => 'big_test_order',
            'title' => 'GV yêu cầu đề test: '.Auth::user()->name,
            'message' => 'Giáo viên '.Auth::user()->name.' yêu cầu đề '.$order->type_label." cho chặng \"{$validated['stage_name']}\" của lớp {$class->name}".(filled($validated['note'] ?? null) ? " — Ghi chú: {$validated['note']}" : ''),
            'data' => ['link' => route('syllabus.big-tests.distribution', ['order' => $order->id])],
            'is_read' => false,
        ]);

        return redirect()->back()->with('success', 'Đã gửi yêu cầu đề test tới Ban Học thuật.');
    }

    /**
     * Bảng điểm Big Test các lớp GV phụ trách (GV chính hoặc GVNN).
     */
    public function bigTestReport(Request $request)
    {
        $this->guardTeacher();
        $teacherId = Auth::id();

        $results = BigTestResult::with(['student', 'bigTest.classModel', 'grader'])
            ->whereHas('bigTest.classModel', fn ($q) => $q->where(function ($sub) use ($teacherId) {
                $sub->where('teacher_id', $teacherId)->orWhere('foreign_teacher_id', $teacherId);
            }))
            ->latest()
            ->paginate(20)->withQueryString();

        return view('teacher.big-test-report', compact('results'));
    }

    /**
     * Tổng hợp hoạt động giảng dạy của GV: giờ dạy đã duyệt, điểm danh đã chấm, điểm đã nhập.
     */
    public function generalReport(Request $request)
    {
        $this->guardTeacher();
        $teacherId = Auth::id();

        // Nhận cả dạng ?period=YYYY-MM (input type=month) lẫn ?month=&year=
        $period = $request->input('period');
        $month = (int) $request->input('month', $period ? substr($period, 5, 2) : now()->month);
        $year = (int) $request->input('year', $period ? substr($period, 0, 4) : now()->year);
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $timesheets = TeacherTimesheet::where('user_id', $teacherId)
            ->whereBetween('teaching_date', [$start->toDateString(), $end->toDateString()])
            ->get();
        $attendanceMarked = StudentAttendance::where('user_id', $teacherId)
            ->whereBetween('session_date', [$start->toDateString(), $end->toDateString()])
            ->count();
        $scoresEntered = MiniTestScore::where('user_id', $teacherId)
            ->whereBetween('test_date', [$start->toDateString(), $end->toDateString()])
            ->count();

        $myClasses = ClassModel::with('course')
            ->where(function ($q) use ($teacherId) {
                $q->where('teacher_id', $teacherId)->orWhere('foreign_teacher_id', $teacherId);
            })
            ->where('status', '!=', 'cancelled')
            ->get();

        return view('teacher.general-report', compact(
            'month', 'year', 'timesheets', 'attendanceMarked', 'scoresEntered', 'myClasses'
        ));
    }
}
