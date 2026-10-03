<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Helpers\AclHelper;
use App\Http\Concerns\RendersModals;
use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\HrDailyDemand;
use App\Models\PayrollPeriod;
use App\Models\Room;
use App\Models\SupportSession;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\ClassDashboardService;
use App\Services\KpiBoardService;
use App\Services\RoomService;
use App\Services\SafeUploadService;
use App\Services\SessionLessonService;
use App\Services\SessionScheduleService;
use App\Services\SupportListService;
use App\Support\DataScope;
use App\Support\Rbac;
use App\Support\Ui;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WorkTaskController extends Controller
{
    use RendersModals;

    /**
     * 1. Danh sách công việc (Task List)
     */
    public function index(Request $request)
    {
        $currentUser = Auth::user();
        $currentUserId = Auth::id();
        // Tab "Tất cả" chỉ dành cho người có quyền duyệt công việc; người khác
        // chỉ thấy việc mình được giao hoặc mình tạo.
        $canViewAll = $currentUser->can('work_task.approve');
        // Tab mặc định (giao diện): Super Admin mở "Tất cả", người khác "Việc của tôi".
        $defaultTab = ($currentUser && $currentUser->isSuperAdmin()) ? 'all' : 'mine';
        $tab = $request->get('tab', $defaultTab); // 'mine', 'assigned', 'all'
        if (! in_array($tab, ['mine', 'assigned', 'all'], true) || ($tab === 'all' && ! $canViewAll)) {
            $tab = 'mine';
        }
        $status = $request->get('status', 'all');
        $taskType = $request->get('task_type', 'all');
        $search = $request->get('q', '');

        $query = WorkTask::with(['creator', 'assignee.roles', 'branch', 'classModel']);
        $this->scopeVisibleTasks($query, $currentUser);

        if ($tab === 'mine') {
            $query->where('assignee_id', $currentUserId);
        } elseif ($tab === 'assigned') {
            $query->where('creator_id', $currentUserId);
        }

        if ($status !== 'all' && ! empty($status)) {
            $query->where('status', $status);
        }

        if ($taskType !== 'all' && ! empty($taskType)) {
            $query->where('task_type', $taskType);
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('assignee', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Sắp xếp ưu tiên: Quá hạn -> Bị chặn -> Chờ xác nhận -> Đang thực hiện -> Mới -> Hoàn thành -> Đã hủy
        $tasks = $query->orderByRaw("
            CASE 
                WHEN status = 'overdue' THEN 1
                WHEN status = 'blocked' THEN 2
                WHEN status = 'pending_confirmation' THEN 3
                WHEN status = 'in_progress' THEN 4
                WHEN status = 'new' THEN 5
                WHEN status = 'completed' THEN 6
                WHEN status = 'canceled' THEN 7
                ELSE 8
            END ASC
        ")->latest()->paginate($request->perPage(10))->withQueryString();

        // Form "Giao việc" tải riêng qua modal (tasks.create) nên danh sách không cần nạp nhân sự / chi nhánh / lớp.
        $visible = fn () => $this->scopeVisibleTasks(WorkTask::query(), $currentUser);
        $counts = [
            'all' => $visible()->count(),
            'mine' => WorkTask::where('assignee_id', $currentUserId)->count(),
            'assigned' => WorkTask::where('creator_id', $currentUserId)->count(),
            'overdue' => $visible()->where('status', 'overdue')->count(),
            'pending' => $visible()->where('status', 'pending_confirmation')->count(),
        ];

        $viewAssistantSlot = fn (WorkTask $task) => $task->time_slot_category && $task->assignee?->can('portal.assistant');

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks->through(fn (WorkTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'status_label' => $task->status_label,
                'task_type' => $task->task_type,
                'type_label' => $task->task_type_label.($task->frequency ? ' ('.(self::FREQUENCIES[$task->frequency] ?? $task->frequency).')' : ''),
                'class_label' => $task->classModel ? $task->classModel->name.($task->lesson_session ? ' · '.$task->lesson_session : '') : null,
                'slot_label' => $viewAssistantSlot($task) ? $task->time_slot_category_label : null,
                'blocked_reason' => $task->status === 'blocked' ? $task->blocked_reason : null,
                'assignee' => $task->assignee?->name,
                'creator' => $task->creator?->name,
                'due_date' => $task->due_date?->toDateString(),
                'due_time' => $task->due_time ? substr($task->due_time, 0, 5) : null,
                'allowed' => self::allowedTransitions($task, $currentUser),
            ]),
            'tab' => $tab,
            'status' => $status,
            'taskType' => $taskType,
            'counts' => $counts,
            'canViewAll' => $canViewAll,
        ]);
    }

    /** Nhãn tần suất việc lặp. */
    private const FREQUENCIES = ['daily' => 'Hàng ngày', 'weekly' => 'Hàng tuần', 'monthly' => 'Hàng tháng'];

    /** Nhãn nút chuyển trạng thái (việc đang chờ xác nhận mà trả về "Đang thực hiện" = "Trả về làm tiếp"). */
    private static function transitionOptions(WorkTask $task, array $allowed): array
    {
        $labels = [
            'in_progress' => $task->status === 'pending_confirmation' ? 'Trả về làm tiếp' : 'Đang thực hiện', 'blocked' => 'Bị chặn',
            'pending_confirmation' => 'Gửi chờ xác nhận', 'completed' => 'Xác nhận hoàn thành', 'canceled' => 'Hủy công việc',
        ];

        return array_map(fn (string $next) => ['value' => $next, 'label' => $labels[$next] ?? $next], $allowed);
    }

    /**
     * 2. Form Giao việc mới (Create & Store)
     */
    public function create()
    {
        $this->ensureCanCreateTask();
        $users = $this->assignableUsers(Auth::user());
        $branches = Branch::where('is_active', true)->get();
        $classes = ClassModel::query()->visibleTo(Auth::user())->where('status', 'active')->get();

        // Nút "Giao cho: Trợ giảng" trong form chuyển sang luồng giao việc theo ca (tasks.ta-assign — luật riêng).
        $canTaAssign = Auth::user()->can('work_task.assign');

        return $this->modalPage('Tasks/Create', [
            'title' => Auth::user()->can('work_task.create') ? 'Giao việc mới' : 'Đề xuất việc cho Admin / Học vụ',
            'users' => Ui::options($users, fn (User $u) => $u->name.' ('.($u->getRoleNames()->map(fn ($r) => AclHelper::shortRoleLabel($r))->implode(', ') ?: 'Nhân viên').')'),
            'branches' => Ui::options($branches, 'name'),
            'classes' => Ui::options($classes, fn (ClassModel $c) => "{$c->name} ({$c->code})"),
            'defaultDueDate' => now()->addDays(2)->format('Y-m-d'),
            'canTaAssign' => $canTaAssign,
        ]);
    }

    /**
     * Chi tiết công việc: mở từ danh sách → modal xem nhanh (htmx, đẩy URL); mở thẳng link → trang đầy đủ.
     * Chỉ công việc trong phạm vi được xem (cùng scope với danh sách) — ngoài phạm vi → 404.
     */
    public function show(Request $request, int $id)
    {
        $user = $request->user();
        $task = $this->scopeVisibleTasks(WorkTask::query(), $user)
            ->with(['creator:id,name', 'assignee:id,name', 'branch:id,name', 'classModel:id,name,code', 'confirmedBy:id,name'])
            ->findOrFail($id);
        $allowed = self::allowedTransitions($task, $user);

        return $this->modalPage('Tasks/Show', ['task' => $this->taskDetail($task), 'allowed' => self::transitionOptions($task, $allowed)]);
    }

    /** Dữ liệu chi tiết công việc (modal xem nhanh / trang đầy đủ). */
    private function taskDetail(WorkTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'status_label' => $task->status_label,
            'slot_label' => $task->time_slot_category && $task->lesson_session ? $task->time_slot_category_label : null,
            'rows' => array_values(array_filter([
                ['person', 'Người nhận', $task->assignee?->name ?? 'Chưa phân công'],
                ['assignment_ind', 'Người giao', $task->creator?->name ?? '—'],
                ['event', 'Hạn hoàn thành', ($task->due_date?->format('d/m/Y') ?? '—').($task->due_time ? ' · '.substr($task->due_time, 0, 5) : '')],
                ['repeat', 'Loại', $task->task_type_label.($task->frequency ? ' ('.(self::FREQUENCIES[$task->frequency] ?? $task->frequency).')' : '')],
                ['apartment', 'Chi nhánh', $task->branch?->name ?? '—'],
                $task->classModel ? ['school', 'Lớp', $task->classModel->name.($task->lesson_session ? ' · '.$task->lesson_session : '')] : null,
                $task->confirmedBy ? ['verified', 'Xác nhận bởi', $task->confirmedBy->name.($task->confirmed_at ? ' · '.$task->confirmed_at->format('H:i d/m/Y') : '')] : null,
            ])),
            'notes' => array_values(array_filter([
                $task->blocked_reason ? ['type' => 'error', 'title' => 'Lý do bị chặn', 'text' => $task->blocked_reason] : null,
                $task->rejection_reason ? ['type' => 'warning', 'title' => 'Lý do hủy / trả về', 'text' => $task->rejection_reason] : null,
                $task->completion_note ? ['type' => 'info', 'title' => 'Ghi chú / kết quả', 'text' => $task->completion_note] : null,
            ])),
        ];
    }

    public function store(Request $request)
    {
        $this->ensureCanCreateTask();

        $validated = $request->validate([
            'taskTitle' => 'required|string|max:255',
            'taskDescription' => 'nullable|string',
            'assignee' => 'required|exists:users,id',
            'dueDate' => 'required|date',
            'dueTime' => 'nullable|string|max:20',
            'taskType' => 'required|in:one-time,recurring,one_time',
            'frequency' => 'nullable|string|in:daily,weekly,monthly',
            'branch_id' => 'nullable|exists:branches,id',
            'class_id' => 'nullable|exists:classes,id',
            'time_slot_category' => 'nullable|in:before,during,after',
        ]);

        $taskTypeDb = ($validated['taskType'] === 'one-time' || $validated['taskType'] === 'one_time') ? 'one_time' : 'recurring';

        // Giao việc 2 chiều: người chỉ có quyền "đề xuất" (GV/TA) chỉ được giao
        // ngược cho Admin / Quản lý / Học vụ / Học thuật.
        $assignee = User::findOrFail($validated['assignee']);
        if (! $this->assignableUsers(Auth::user())->contains('id', $assignee->id)) {
            throw ValidationException::withMessages([
                'assignee' => 'Bạn chỉ được giao việc cho Admin, Quản lý cơ sở, Học vụ hoặc Học thuật.',
            ]);
        }

        $task = WorkTask::create([
            'title' => $validated['taskTitle'],
            'description' => $validated['taskDescription'] ?? null,
            'creator_id' => Auth::id() ?? 1,
            'assignee_id' => $validated['assignee'],
            'due_date' => $validated['dueDate'],
            'due_time' => $validated['dueTime'] ?? '18:00',
            'task_type' => $taskTypeDb,
            'frequency' => $taskTypeDb === 'recurring' ? ($validated['frequency'] ?? 'weekly') : null,
            'branch_id' => $validated['branch_id'] ?? null,
            'class_id' => $validated['class_id'] ?? null,
            'time_slot_category' => $validated['time_slot_category'] ?? 'during',
            'status' => 'new',
        ]);

        $this->notifyAssignee($task);

        return $this->modalSaved("Đã giao việc '{$task->title}' thành công cho nhân sự!", route('tasks.index'), 'success');
    }

    /**
     * Cập nhật trạng thái công việc (Modal status change)
     */
    public function updateStatus(Request $request, $id)
    {
        $task = WorkTask::findOrFail($id);
        $request->validate([
            'status' => 'required|string|in:'.implode(',', array_keys(self::STATUS_TRANSITIONS)),
            'reason' => 'nullable|string|max:1000',
            'note' => 'nullable|string|max:2000',
        ]);
        $status = $request->input('status');
        $reason = $request->input('reason');
        $note = $request->input('note');

        $user = $request->user();
        abort_unless($this->isTaskParticipant($task, $user), 403, 'Bạn không phụ trách công việc này.');

        if (! in_array($status, self::allowedTransitions($task, $user), true)) {
            $message = $status === 'completed' && (int) $task->assignee_id === (int) $user->id
                ? 'Người thực hiện không tự xác nhận hoàn thành: hãy gửi "Chờ xác nhận" để người giao việc duyệt.'
                : "Không thể chuyển công việc từ \"{$task->status_label}\" sang trạng thái này.";

            return back()->withErrors(['status' => $message]);
        }

        // Mockup "Thay đổi trạng thái": Bị chặn / Hủy bắt buộc ghi lý do.
        if (in_array($status, ['blocked', 'canceled'], true) && blank($reason ?? $note)) {
            return back()->withErrors(['reason' => $status === 'blocked'
                ? 'Vui lòng nhập lý do khiến công việc bị chặn.'
                : 'Vui lòng nhập lý do hủy công việc.']);
        }
        $note ??= $reason;

        $updateData = ['status' => $status];

        if ($status === 'blocked') {
            $updateData['blocked_reason'] = $reason ?? $note;
        } elseif ($status === 'completed') {
            $updateData['completed_at'] = now();
            $updateData['confirmed_by'] = $user->id;
            $updateData['confirmed_at'] = now();
            if ($note) {
                $updateData['completion_note'] = $note;
            }
        } elseif ($status === 'pending_confirmation') {
            if ($note) {
                $updateData['completion_note'] = $note;
            }
        } elseif ($status === 'canceled') {
            $updateData['rejection_reason'] = $reason ?? $note;
        }

        $task->update($updateData);

        if ($status === 'pending_confirmation') {
            $this->notifyTaskConfirmer($task->loadMissing('assignee'));
        }

        // Từ modal xem nhanh: đóng modal + làm mới danh sách; từ trang: quay lại như cũ.
        return $this->modalSaved('Đã cập nhật trạng thái công việc thành công!', url()->previous(), 'success');
    }

    /**
     * 3. Dashboard Lớp học theo ngày / Ma trận khung giờ tuần
     */
    public function classesDashboard(Request $request, ClassDashboardService $dashboard)
    {
        $validated = $request->validate([
            'tab' => ['nullable', 'in:day,week'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date' => ['nullable', 'date'],
            'week' => ['nullable', 'regex:/^\d{4}-W\d{2}$/'],
            'attendance' => ['nullable', 'in:done,missing,upcoming,cancelled'],
            'teacher_id' => ['nullable', 'integer'],
        ]);
        $viewer = $request->user();
        $attendanceFilter = $validated['attendance'] ?? null;
        $teacherFilter = isset($validated['teacher_id']) ? (int) $validated['teacher_id'] : null;
        $tab = $validated['tab'] ?? 'day';
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $today = CarbonImmutable::today();
        $day = CarbonImmutable::parse($validated['date'] ?? $today)->startOfDay();
        $weekStart = ClassDashboardService::weekStart($validated['week'] ?? $day->format('o-\WW'));
        $date = $day->toDateString();
        $week = $weekStart->format('o-\WW');

        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $selectedBranch = $branchId ? $branches->firstWhere('id', $branchId) : null;

        // Theo ngày: buổi học thật của ngày được chọn (kể cả buổi đã hủy/nghỉ lễ để học vụ nắm được).
        $daySessions = $dashboard->sessionsQuery($viewer, $branchId)->whereDate('date', $date)->get();
        // "Lọc thêm" (mockup): giáo viên và trạng thái điểm danh — lọc trên buổi thật trong ngày.
        $dayTeachers = $daySessions->flatMap(fn (ClassSession $s) => [$s->teacher, $s->foreignTeacher])->filter()->unique('id')->sortBy('name')->values();
        if ($teacherFilter) {
            $daySessions = $daySessions->filter(fn (ClassSession $s) => in_array($teacherFilter, [(int) $s->teacher_id, (int) $s->foreign_teacher_id], true))->values();
        }
        if ($attendanceFilter) {
            $daySessions = $daySessions->filter(fn (ClassSession $s) => $dashboard->attendanceState($s, $today)['key'] === $attendanceFilter)->values();
        }
        $dayStats = [
            'total' => $daySessions->where('status', '!=', 'cancelled')->count(),
            'done' => $daySessions->filter(fn ($s) => $s->status !== 'cancelled' && $s->attendances_count > 0)->count(),
            'missing' => $daySessions->filter(fn ($s) => $dashboard->attendanceState($s, $today)['key'] === 'missing')->count(),
            'cancelled' => $daySessions->where('status', 'cancelled')->count(),
        ];
        $seats = $daySessions->pluck('classModel')->filter()->unique('id')
            ->mapWithKeys(fn (ClassModel $class) => [$class->id => $class->occupiedSeats()]);
        // Trợ giảng không cố định theo lớp: ai có việc giao theo ca trong ngày là trợ giảng làm việc hôm đó.
        $shiftTasks = WorkTask::with('assignee:id,name')
            ->whereIn('assignee_id', Rbac::scopeUsersWithPermission(User::query(), 'portal.assistant')->where('is_active', true)->select('id'))
            ->whereDate('due_date', $date)
            ->when($branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->get(['id', 'assignee_id', 'time_slot_category', 'due_time', 'branch_id']);
        $assistantsToday = $dashboard->assistantsOnDuty($daySessions, $shiftTasks);

        // Theo tuần: ma trận khung giờ sinh từ buổi học trong tuần.
        $weekSessions = $dashboard->sessionsQuery($viewer, $branchId)
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekStart->addDays(6)->toDateString())
            ->get();
        $matrix = $dashboard->weekMatrix($weekSessions, $weekStart);

        // Xuất Excel đúng dữ liệu đang xem (tab ngày hoặc tuần).
        if ($request->boolean('export')) {
            $rows = ($tab === 'week' ? $weekSessions : $daySessions)->map(fn (ClassSession $s) => [
                $s->date?->format('d/m/Y'),
                trim(($s->start_time?->format('H:i') ?? '').' - '.($s->end_time?->format('H:i') ?? ''), ' -'),
                $s->classModel?->code,
                $s->classModel?->name,
                $s->branch?->name ?? $s->classModel?->branch?->name,
                $s->room ?: $s->classModel?->room,
                $s->teacher?->name,
                $s->foreignTeacher?->name,
                $s->assistant?->name,
                $dashboard->attendanceState($s, $today)['label'],
            ])->values()->all();

            return ArrayExport::download(
                'lich-lop-'.($tab === 'week' ? $week : $date),
                ['Ngày', 'Giờ', 'Mã lớp', 'Tên lớp', 'Chi nhánh', 'Phòng', 'Giáo viên', 'GVNN', 'Trợ giảng', 'Điểm danh'],
                $rows,
                $request->query('format', 'xlsx')
            );
        }

        $canRecord = $viewer->can('attendance_student.record');
        $dayRows = $daySessions->map(function (ClassSession $session) use ($dashboard, $today, $seats, $canRecord) {
            $class = $session->classModel;
            $state = $dashboard->attendanceState($session, $today);
            // teacher_id cũ = GV chính ?? GVNN: không lặp tên GVNN ở cột GV chính.
            $mainTeacher = $session->teacher_id && $session->teacher_id !== $session->foreign_teacher_id ? $session->teacher : null;
            $window = $dashboard->attendanceWindow($session);
            $open = $session->status !== 'cancelled' && $class && $canRecord;
            $action = match (true) {
                // Chưa tới giờ học → nút Điểm danh khóa kèm quy định cửa sổ 24h.
                $open && $state['key'] !== 'done' && $window === 'before' => ['kind' => 'locked'],
                $open && ! $session->date->gt($today) => [
                    'kind' => 'link',
                    'primary' => ! ($state['key'] === 'done' || $window === 'closed'),
                    'label' => $state['key'] === 'done' ? 'Xem điểm danh' : ($window === 'closed' ? 'Điểm danh bù' : 'Điểm danh'),
                    'title' => $window === 'closed' && $state['key'] !== 'done' ? 'Quá 24h sau giờ học — điểm danh bù, Học vụ sẽ rà soát' : null,
                    'href' => route('teacher.attendance', ['classId' => $class->id, 'session' => $session->id, 'date' => $session->date->toDateString()]),
                ],
                $session->status === 'cancelled' && $session->makeupSession => ['kind' => 'makeup', 'date' => $session->makeupSession->date->format('d/m')],
                default => ['kind' => 'none'],
            };

            return [
                'id' => $session->id,
                'class_name' => $class?->name ?? 'Lớp đã xóa',
                'class_code' => $class?->code,
                'type' => $session->type,
                'time' => $session->start_time?->format('H:i').' - '.$session->end_time?->format('H:i'),
                'room' => $session->room ?: '—',
                'teacher' => $mainTeacher?->name ?? '—',
                'foreign_teacher' => $session->foreignTeacher?->name ?? '—',
                'assistant' => $session->assistant?->name ?? '—',
                'seat' => ($class ? ($seats[$class->id] ?? 0) : 0).'/'.($class?->max_capacity ?: '∞'),
                'state' => $state + ['count' => $session->attendances_count],
                'holiday' => $session->holiday?->name,
                'action' => $action,
            ];
        })->values();

        return Inertia::render('Tasks/ClassesDashboard', [
            'tab' => $tab,
            'branches' => Ui::options($branches, 'name'),
            'branchId' => $branchId,
            'branchName' => $selectedBranch?->name,
            'date' => $date,
            'week' => $week,
            'isToday' => CarbonImmutable::parse($date)->isToday(),
            'noBranch' => ! $viewer->branch_id && DataScope::dependsOnBranch($viewer),
            'filters' => ['teacher_id' => $teacherFilter, 'attendance' => $attendanceFilter],
            'teachers' => Ui::options($dayTeachers, 'name'),
            'dayStats' => $dayStats,
            'dayRows' => $dayRows,
            'dayClassCount' => $daySessions->pluck('class_id')->unique()->count(),
            'assistantsToday' => $assistantsToday->map(fn (array $duty) => [
                'name' => $duty['user']->name,
                'detail' => collect([
                    $duty['slots'] ? implode(', ', $duty['slots']) : null,
                    $duty['tasks'] ? $duty['tasks'].' việc' : null,
                    $duty['sessions'] ? $duty['from'].' - '.$duty['to'].' · '.$duty['sessions'].' buổi' : null,
                ])->filter()->implode(' · '),
            ])->values(),
            'weekRange' => $weekStart->format('d/m').' - '.$weekStart->addDays(6)->format('d/m/Y'),
            'weekDays' => collect($matrix['days'])->map(fn ($day, $iso) => [
                'iso' => $iso,
                'label' => ClassDashboardService::WEEKDAYS[$iso],
                'date' => $day->format('d/m'),
                'today' => $day->isToday(),
            ])->values(),
            'weekRows' => collect($matrix['rows'])->map(fn ($cells, $slot) => [
                'slot' => $slot,
                'cells' => collect($cells)->map(fn ($sessions) => collect($sessions)->map(fn (ClassSession $session) => [
                    'id' => $session->id,
                    'href' => route('tasks.classes-dashboard', ['tab' => 'day', 'date' => $session->date->toDateString(), 'branch_id' => $branchId]),
                    'tone' => ClassDashboardService::tone($session),
                    'title' => $session->classModel?->name.' · '.$session->room,
                    'code' => $session->classModel?->code ?? $session->classModel?->name,
                    'room' => $session->room ?: 'Chưa có phòng',
                    'note' => $session->status === 'cancelled' ? ($session->holiday ? 'Nghỉ lễ' : 'Đã hủy') : ($session->attendances_count > 0 ? '✓ Đã điểm danh' : null),
                ])->values())->values(),
            ])->values(),
        ]);
    }

    /**
     * 4. Tạo lượt giao việc cho Trợ giảng (Batch assign form)
     */
    /** Giờ hạn mặc định của 3 ca trực khi đầu việc không gắn buổi học. */
    public const SLOT_DEFAULT_DUE = ['before' => '14:00', 'during' => '18:00', 'after' => '21:30'];

    /** Mốc khuyến nghị gửi nhiệm vụ trợ giảng trong ngày (mockup: "Khuyến nghị gửi trước 15h30"). */
    public const TA_ASSIGN_CUTOFF = '15:30';

    /** Trợ giảng được giao: người có quyền đối tượng "Cổng trợ giảng" (portal.assistant) đang hoạt động (phạm vi Chi nhánh: trong chi nhánh mình). */
    private function assignableAssistants(User $user)
    {
        $managed = self::branchLimit($user);

        return Rbac::scopeUsersWithPermission(User::query(), 'portal.assistant')
            ->where('is_active', true)
            ->whereNull('locked_at')
            ->when($managed !== null, fn ($q) => $q->whereIn('branch_id', $managed))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'branch_id']);
    }

    public function taAssignForm(Request $request)
    {
        $user = $request->user();
        $assistants = $this->assignableAssistants($user);
        $managed = self::branchLimit($user);
        $branches = Branch::where('is_active', true)
            ->when($managed !== null, fn ($q) => $q->whereIn('id', $managed))
            ->orderBy('name')->get();
        $classes = ClassModel::query()->visibleTo($user)
            ->whereIn('status', ['active', 'upcoming'])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'branch_id', 'schedule_text']);

        // Buổi học thật của từng lớp (7 ngày trước → 30 ngày tới) cho ô "Buổi học", lọc theo ngày giao trên trình duyệt.
        $sessions = ClassSession::whereIn('class_id', $classes->modelKeys())
            ->where('status', '!=', 'cancelled')
            ->where('type', '!=', ClassSession::TYPE_SUPPORT)
            ->whereDate('date', '>=', today()->subDays(7)->toDateString())
            ->whereDate('date', '<=', today()->addDays(30)->toDateString())
            ->orderBy('date')->orderBy('start_time')
            ->get();
        $lessons = $sessions->isNotEmpty() ? app(SessionLessonService::class)->lessonsFor($sessions) : [];
        $classSessions = $sessions->groupBy('class_id')->map(fn ($rows) => $rows->map(fn (ClassSession $s) => [
            'id' => $s->id,
            'date' => $s->date->toDateString(),
            'label' => self::sessionLabel($s, $lessons[$s->id] ?? null).' · '.$s->start_time?->format('H:i').'-'.$s->end_time?->format('H:i'),
        ])->values());

        return $this->modalPage('Tasks/TaAssign', [
            'assistants' => Ui::options($assistants, 'name'),
            'branches' => Ui::options($branches, 'name'),
            'classes' => Ui::options($classes, fn (ClassModel $c) => "{$c->name} ({$c->code})".($c->schedule_text ? ' - '.$c->schedule_text : '')),
            'classSessions' => $classSessions,
            'slots' => Ui::options(WorkTask::TIME_SLOTS),
            'today' => now()->toDateString(),
            'cutoff' => self::TA_ASSIGN_CUTOFF,
        ]);
    }

    public function taAssignStore(Request $request)
    {
        $validated = $request->validate([
            'assistant_id' => 'required|exists:users,id',
            'assign_date' => 'required|date',
            'branch_id' => 'nullable|exists:branches,id',
            'tasks' => 'required|array|min:1|max:30',
            'tasks.*.category' => 'required|in:before,during,after',
            'tasks.*.content' => 'required|string|max:500',
            'tasks.*.attach_class' => 'nullable',
            'tasks.*.class_id' => 'nullable|exists:classes,id',
            'tasks.*.class_session_id' => 'nullable|integer|exists:class_sessions,id',
            'tasks.*.session' => 'nullable|string|max:255',
        ], [
            'assistant_id.required' => 'Vui lòng chọn trợ giảng.',
            'tasks.*.content.required' => 'Nội dung đầu việc không được để trống.',
        ]);

        $user = $request->user();
        if (! $this->assignableAssistants($user)->contains('id', (int) $validated['assistant_id'])) {
            throw ValidationException::withMessages(['assistant_id' => 'Chỉ giao cho trợ giảng đang hoạt động trong phạm vi bạn quản lý.']);
        }
        $managed = self::branchLimit($user);
        if (! empty($validated['branch_id']) && $managed !== null && ! in_array((int) $validated['branch_id'], $managed, true)) {
            throw ValidationException::withMessages(['branch_id' => 'Bạn chỉ giao việc trong chi nhánh mình quản lý.']);
        }

        $assignDate = Carbon::parse($validated['assign_date'])->toDateString();
        $visibleClassIds = ClassModel::query()->visibleTo($user)->pluck('id')->all();
        $rows = [];
        foreach ($validated['tasks'] as $i => $item) {
            $attach = in_array($item['attach_class'] ?? null, ['1', 'on', 1, true], true);
            $session = null;
            if ($attach) {
                if (empty($item['class_id'])) {
                    throw ValidationException::withMessages(["tasks.{$i}.class_id" => 'Đầu việc #'.($i + 1).': đã chọn "Gắn lớp" thì phải chọn lớp học.']);
                }
                if (! in_array((int) $item['class_id'], $visibleClassIds, true)) {
                    throw ValidationException::withMessages(["tasks.{$i}.class_id" => 'Đầu việc #'.($i + 1).': lớp nằm ngoài phạm vi bạn quản lý.']);
                }
                if (! empty($item['class_session_id'])) {
                    $session = ClassSession::find($item['class_session_id']);
                    if (! $session || (int) $session->class_id !== (int) $item['class_id'] || $session->date->toDateString() !== $assignDate) {
                        throw ValidationException::withMessages(["tasks.{$i}.class_session_id" => 'Đầu việc #'.($i + 1).': buổi học không thuộc lớp hoặc không đúng ngày giao việc.']);
                    }
                } elseif (blank($item['session'] ?? null)) {
                    throw ValidationException::withMessages(["tasks.{$i}.class_session_id" => 'Đầu việc #'.($i + 1).': vui lòng chọn buổi học.']);
                }
            }
            $rows[] = [$item, $attach, $session];
        }

        $created = DB::transaction(function () use ($rows, $validated, $assignDate) {
            $created = collect();
            foreach ($rows as [$item, $attach, $session]) {
                $created->push(WorkTask::create([
                    'title' => $item['content'],
                    'description' => 'Nhiệm vụ trực ca '.WorkTask::TIME_SLOTS[$item['category']],
                    'creator_id' => Auth::id(),
                    'assignee_id' => $validated['assistant_id'],
                    'branch_id' => $validated['branch_id'] ?? ($session?->branch_id),
                    'class_id' => $attach ? $item['class_id'] : null,
                    'lesson_session' => $attach
                        ? ($session ? self::sessionLabel($session, app(SessionLessonService::class)->lessonsFor(collect([$session]))[$session->id] ?? null) : $item['session'])
                        : null,
                    'time_slot_category' => $item['category'],
                    'task_type' => 'one_time',
                    'due_date' => $assignDate,
                    'due_time' => self::slotDueTime($item['category'], $session),
                    'status' => 'new',
                ]));
            }

            return $created;
        });

        $this->notifyAssignee($created->last(), $created->count());

        // Gửi sau 15h30 cho nhiệm vụ trong ngày (hoặc ngày đã qua): vẫn lưu, báo Admin (mockup).
        $late = $assignDate < today()->toDateString()
            || ($assignDate === today()->toDateString() && now()->format('H:i') > self::TA_ASSIGN_CUTOFF);
        if ($late) {
            $assistant = User::find($validated['assistant_id']);
            User::role(Rbac::SUPER_ADMIN)->where('is_active', true)->whereNull('locked_at')->pluck('id')
                ->reject(fn ($id) => (int) $id === (int) Auth::id())
                ->each(fn ($adminId) => $this->notifyUser($adminId, 'task_assigned', 'Giao việc trợ giảng sau '.self::TA_ASSIGN_CUTOFF,
                    Auth::user()->name." giao {$created->count()} nhiệm vụ ngày ".Carbon::parse($assignDate)->format('d/m/Y')." cho {$assistant?->name} lúc ".now()->format('H:i').'.',
                    route('portal.ta-tasks', ['ta_id' => $validated['assistant_id'], 'date' => $assignDate])));
        }

        return $this->modalSaved(
            "Đã tạo thành công {$created->count()} nhiệm vụ cho Trợ giảng!".($late ? ' (Gửi sau '.self::TA_ASSIGN_CUTOFF.' — đã báo Admin.)' : ''),
            route('tasks.index'),
            'success',
        );
    }

    /**
     * Giờ hạn của ca trực: gắn buổi học → Trước giờ học = giờ bắt đầu buổi, Trong giờ học = giờ kết thúc,
     * Sau giờ học = kết thúc + 60 phút; không gắn buổi → mốc mặc định của ca.
     */
    private static function slotDueTime(string $category, ?ClassSession $session): string
    {
        if (! $session || ! $session->start_time || ! $session->end_time) {
            return self::SLOT_DEFAULT_DUE[$category];
        }

        return match ($category) {
            'before' => $session->start_time->format('H:i'),
            'during' => $session->end_time->format('H:i'),
            default => $session->end_time->copy()->addHour()->min($session->end_time->copy()->setTime(23, 59))->format('H:i'),
        };
    }

    /**
     * 5. Nhiệm vụ hôm nay (TA Portal view)
     */
    public function taPortal(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'ta_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $viewer = $request->user();
        $date = CarbonImmutable::parse($validated['date'] ?? now())->startOfDay();

        // Admin / quản lý / học vụ xem được nhiệm vụ của trợ giảng bất kỳ qua bộ chọn TA;
        // trợ giảng (và vai trò khác) chỉ xem nhiệm vụ của chính mình.
        $canPickTa = $viewer->can('work_task.assign') || $viewer->can('work_task.approve');
        $assistants = $canPickTa ? $this->assignableAssistants($viewer) : collect();

        if ($canPickTa) {
            if (isset($validated['ta_id'])) {
                $taId = (int) $validated['ta_id'];
                abort_unless($taId === (int) $viewer->id || $assistants->contains('id', $taId), 403, 'Trợ giảng này nằm ngoài phạm vi bạn quản lý.');
                $taUser = User::find($taId);
            } else {
                $taUser = $viewer->can('portal.assistant') ? $viewer : $assistants->first();
            }
        } else {
            $taUser = $viewer;
        }

        $tasks = collect();
        $sessions = collect();
        $overdueCount = 0;
        if ($taUser) {
            $tasks = WorkTask::with(['classModel:id,name,code,teacher_id', 'branch:id,name', 'creator:id,name', 'classReport'])
                ->where('assignee_id', $taUser->id)
                ->whereDate('due_date', $date->toDateString())
                ->orderBy('due_time')
                ->orderBy('id')
                ->get();
            $overdueCount = WorkTask::where('assignee_id', $taUser->id)
                ->whereDate('due_date', '<', $date->toDateString())
                ->whereNotIn('status', ['completed', 'pending_confirmation'])
                ->count();
            // Trợ giảng không cố định theo lớp: buổi trong ngày = buổi được gán (dữ liệu cũ) + buổi của các lớp có việc giao hôm nay.
            $taskClassIds = $tasks->pluck('class_id')->filter()->unique()->values()->all();
            $sessions = ClassSession::with(['classModel:id,name,code', 'branch:id,name'])
                ->where(fn ($q) => $q->forStaff($taUser->id)->orWhereIn('class_id', $taskClassIds))
                ->whereDate('date', $date->toDateString())
                ->where('status', '!=', 'cancelled')
                ->orderBy('start_time')
                ->get();
        }

        $beforeTasks = $tasks->where('time_slot_category', 'before');
        $duringTasks = $tasks->where('time_slot_category', 'during');
        // Nhiệm vụ không gắn ca được xếp vào "Sau giờ học" để không bị ẩn.
        $afterTasks = $tasks->reject(fn (WorkTask $task) => in_array($task->time_slot_category, ['before', 'during'], true));
        $canComplete = $taUser && ((int) $taUser->id === (int) $viewer->id || $viewer->can('work_task.approve'));
        $isToday = $date->isToday();

        $card = fn (WorkTask $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'status_label' => $task->status_label,
            'class_id' => $task->class_id,
            'class_label' => $task->classModel ? ($task->classModel->code ?? $task->classModel->name).($task->lesson_session ? ' · '.$task->lesson_session : '') : null,
            'blocked_reason' => $task->blocked_reason,
            'late_hours' => $task->status === 'overdue' ? max(1, $task->lateHours()) : 0,
            'completed_at' => $task->completed_at?->format('H:i'),
            'due_label' => ($task->due_time ? substr($task->due_time, 0, 5) : '—').', '.($isToday ? 'Hôm nay' : $task->due_date?->format('d/m/Y')),
            'rejection_reason' => in_array($task->status, ['in_progress', 'overdue'], true) ? $task->rejection_reason : null,
            // Việc chờ xác nhận: đang chờ ai (báo cáo trực lớp → người xác nhận theo Q8; việc thường → người giao việc).
            'pending_label' => $task->status === 'pending_confirmation'
                ? ($task->classReport && $task->classReport->status === ClassReport::STATUS_PENDING
                    ? 'Đang chờ '.mb_strtolower($task->classReport->confirmerRoleLabel()).' xác nhận báo cáo trực lớp'
                    : 'Đang chờ người giao việc xác nhận')
                : null,
        ];
        $groups = collect([
            ['key' => 'before', 'title' => 'Trước giờ học', 'icon' => 'schedule', 'tasks' => $beforeTasks],
            ['key' => 'during', 'title' => 'Trong giờ học', 'icon' => 'play_circle', 'tasks' => $duringTasks],
            ['key' => 'after', 'title' => 'Sau giờ học', 'icon' => 'task_alt', 'tasks' => $afterTasks],
        ]);

        return Inertia::render('Tasks/TaPortal', [
            'title' => $isToday ? 'Nhiệm vụ hằng ngày' : 'Nhiệm vụ ngày '.$date->format('d/m/Y'),
            'taUser' => $taUser ? ['id' => $taUser->id, 'name' => $taUser->name] : null,
            'date' => $date->toDateString(),
            'isToday' => $isToday,
            'canPickTa' => $canPickTa,
            'assistants' => Ui::options($assistants, 'name'),
            'overdueCount' => $overdueCount,
            'canComplete' => (bool) $canComplete,
            'hasTasks' => $tasks->isNotEmpty(),
            'firstOpen' => $groups->first(fn ($g) => $g['tasks']->isNotEmpty())['key'] ?? 'before',
            'groups' => $groups->map(fn ($g) => [...$g, 'tasks' => $g['tasks']->map($card)->values()->all()])->all(),
            'sessions' => $sessions->map(fn (ClassSession $s) => [
                'id' => $s->id,
                'class_name' => $s->classModel?->name,
                'start' => $s->start_time?->format('H:i'),
                'end' => $s->end_time?->format('H:i'),
                'room' => $s->room,
                'branch' => $s->branch?->name,
                'makeup' => $s->type === ClassSession::TYPE_MAKEUP,
            ])->values()->all(),
        ]);
    }

    /**
     * Cập nhật tiến độ hoàn thành từ TA Portal (Hoàn thành / Hoàn thành gấp)
     */
    public function completeTask(Request $request, $id)
    {
        $task = WorkTask::findOrFail($id);
        abort_unless((int) $task->assignee_id === (int) Auth::id() || $request->user()->can('work_task.approve'), 403);
        abort_if(in_array($task->status, ['completed', 'canceled', 'pending_confirmation'], true), 422, 'Nhiệm vụ đã hoàn thành, đã hủy hoặc đang chờ xác nhận.');
        $request->validate([
            'proof_image' => 'nullable|file|max:10240|mimes:'.implode(',', SafeUploadService::IMAGES),
            'proof_image_url' => 'nullable|url:http,https|max:2048',
        ]);
        $note = $request->input('note');
        $hasImage = $request->hasFile('proof_image') || ! empty($request->input('proof_image_url'));

        $imagePath = null;
        if ($request->hasFile('proof_image')) {
            $imagePath = SafeUploadService::store($request->file('proof_image'), 'task_proofs', SafeUploadService::IMAGES, 'proof_image');
        } elseif ($request->filled('proof_image_url')) {
            $imagePath = $request->input('proof_image_url');
        }

        // Quy tắc: Có ảnh đính kèm -> Hoàn thành ngay (completed); Không có ảnh -> Chờ xác nhận (pending_confirmation)
        if ($hasImage) {
            $task->update([
                'status' => 'completed',
                'completion_proof_image' => $imagePath,
                'completion_note' => $note,
                'completed_at' => now(),
            ]);
            $msg = "Đã hoàn thành nhiệm vụ '{$task->title}' thành công!";
        } else {
            $task->update([
                'status' => 'pending_confirmation',
                'completion_note' => $note,
            ]);
            $this->notifyTaskConfirmer($task->loadMissing('assignee'));
            $msg = "Đã gửi báo cáo tiến độ. Do không đính kèm ảnh, nhiệm vụ chuyển sang 'Chờ người giao việc xác nhận'!";
        }

        return redirect()->back()->with('success', $msg);
    }

    /**
     * 6. Nộp báo cáo trực lớp TA (Class Report Form & Store)
     */
    public function createClassReport(Request $request)
    {
        $user = $request->user();
        $task = $this->reportableTask($request->integer('task_id') ?: null, $user);

        // Chỉ lớp người nộp phụ trách (GV / GVNN / TA, hoặc trong phạm vi quản lý).
        $classes = ClassModel::query()->visibleTo($user)
            ->whereIn('status', ['active', 'upcoming'])
            ->orderBy('name')
            ->get();
        if ($task?->classModel && ! $classes->contains('id', $task->class_id)) {
            $classes->push($task->classModel);
        }

        $classId = $request->integer('class_id') ?: ($task?->class_id ?: $classes->first()?->id);
        $selectedClass = $classes->firstWhere('id', $classId) ?? $classes->first();
        $students = $selectedClass ? $selectedClass->rosterStudents() : collect();

        // Buổi học thật của lớp (30 ngày gần nhất tới hôm nay) cho ô "Buổi học" và "Buổi vắng".
        $sessions = $selectedClass
            ? ClassSession::where('class_id', $selectedClass->id)
                ->where('status', '!=', 'cancelled')
                ->where('type', '!=', ClassSession::TYPE_SUPPORT)
                ->whereDate('date', '<=', today()->toDateString())
                ->whereDate('date', '>=', today()->subDays(30)->toDateString())
                ->orderByDesc('date')->orderByDesc('start_time')
                ->limit(20)
                ->get()
            : collect();
        $lessons = $sessions->isNotEmpty() ? app(SessionLessonService::class)->lessonsFor($sessions) : [];
        $sessionOptions = $sessions->mapWithKeys(fn (ClassSession $s) => [$s->id => self::sessionLabel($s, $lessons[$s->id] ?? null)]);
        $defaultSessionId = $sessions->first(fn (ClassSession $s) => $s->date->isToday())?->id;

        $confirmerId = ClassReport::resolveConfirmerId($selectedClass, $task, (int) $user->id);
        $confirmer = $confirmerId ? User::find($confirmerId, ['id', 'name']) : null;
        $task?->loadMissing('creator:id,name');

        return $this->modalPage('Tasks/ClassReportCreate', [
            'classes' => Ui::options($classes, fn (ClassModel $c) => "{$c->name} ({$c->code})".($c->schedule_text ? ' - '.$c->schedule_text : '')),
            'selectedClassId' => $selectedClass?->id,
            'task' => $task ? [
                'id' => $task->id,
                'title' => $task->title,
                'class_id' => $task->class_id,
                'creator' => $task->creator?->name,
                'lesson_session' => $task->lesson_session,
            ] : null,
            'students' => Ui::options($students, fn ($st) => "{$st->name} ({$st->code})"),
            'sessionOptions' => Ui::options($sessionOptions),
            'defaultSessionId' => $defaultSessionId,
            // Dòng gợi ý "Không có ảnh →": chờ GV chính / chờ người giao việc / bắt buộc ảnh.
            'confirmMode' => $selectedClass?->teacher_id && (int) $selectedClass->teacher_id !== (int) $user->id ? 'teacher' : ($confirmer ? 'creator' : 'none'),
            'confirmer' => $confirmer?->name,
        ]);
    }

    /** "Buổi N - Nội dung bài (dd/mm)" cho một buổi học. */
    private static function sessionLabel(ClassSession $session, ?array $lesson): string
    {
        $label = $lesson ? 'Buổi '.$lesson['no'].($lesson['title'] ? ' - '.$lesson['title'] : '') : ($session->shift_name ?: 'Buổi học');

        return $label.' ('.$session->date->format('d/m').')';
    }

    /**
     * Đầu việc "Trực lớp" mà người nộp được gắn báo cáo: do chính họ thực hiện
     * (hoặc người có quyền duyệt nộp thay), chưa hoàn thành / hủy.
     */
    private function reportableTask(?int $taskId, User $user): ?WorkTask
    {
        if (! $taskId) {
            return null;
        }
        $task = WorkTask::with('classModel')->find($taskId);
        if (! $task || in_array($task->status, ['completed', 'canceled'], true)) {
            return null;
        }

        return (int) $task->assignee_id === (int) $user->id || $user->can('work_task.approve') ? $task : null;
    }

    public function storeClassReport(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'class_session_id' => 'nullable|integer|exists:class_sessions,id',
            'session_name' => 'required_without:class_session_id|nullable|string|max:255',
            'hom_nay_hoc_gi' => 'required|string',
            'nhat_ky_day' => 'nullable|string',
            'task_id' => 'nullable|exists:work_tasks,id',
            'supports' => 'nullable|array',
            'supports.*.student_id' => 'nullable|exists:students,id',
            'supports.*.absence_session' => 'nullable|string|max:255',
            'supports.*.reason' => 'nullable|string',
            'supports.*.action_plan' => 'nullable|string',
            'board_images' => 'nullable|array|max:10',
            'board_images.*' => 'file|max:10240|mimes:'.implode(',', SafeUploadService::IMAGES),
            'board_image' => 'nullable|file|max:10240|mimes:'.implode(',', SafeUploadService::IMAGES),
            'board_image_url' => 'nullable|url:http,https|max:2048',
        ], [
            'session_name.required_without' => 'Vui lòng chọn hoặc nhập buổi học.',
            'hom_nay_hoc_gi.required' => 'Vui lòng nhập "Hôm nay học gì".',
        ]);

        $user = $request->user();
        $class = ClassModel::findOrFail($validated['class_id']);
        abort_unless(
            $user->can('work_task.approve')
            || in_array((int) $user->id, array_map('intval', [$class->teacher_id, $class->assistant_id, $class->foreign_teacher_id]), true)
            || ClassModel::query()->visibleTo($user)->whereKey($class->id)->exists(),
            403
        );

        $session = null;
        if (! empty($validated['class_session_id'])) {
            $session = ClassSession::find($validated['class_session_id']);
            if (! $session || (int) $session->class_id !== (int) $class->id) {
                throw ValidationException::withMessages(['class_session_id' => 'Buổi học không thuộc lớp đã chọn.']);
            }
        }

        // Đầu việc "Trực lớp": gửi kèm (từ Portal TA) hoặc tự tìm việc đang mở của người nộp cho lớp trong ngày.
        $task = null;
        if (! empty($validated['task_id'])) {
            $task = $this->reportableTask((int) $validated['task_id'], $user);
            if (! $task || ($task->class_id && (int) $task->class_id !== (int) $class->id)) {
                throw ValidationException::withMessages(['task_id' => 'Đầu việc không thuộc bạn, đã đóng hoặc không gắn với lớp này.']);
            }
        } else {
            $task = WorkTask::where('assignee_id', $user->id)
                ->where('class_id', $class->id)
                ->whereDate('due_date', ($session?->date ?? today())->toDateString())
                ->whereIn('status', ['new', 'in_progress', 'overdue', 'blocked'])
                ->orderBy('due_time')->orderBy('id')
                ->first();
        }

        $files = array_values(array_filter(array_merge(
            (array) $request->file('board_images', []),
            $request->hasFile('board_image') ? [$request->file('board_image')] : []
        )));
        $hasImage = ! empty($files) || $request->filled('board_image_url');

        // A6 Q8: không ảnh → cần người xác nhận (GV chính; chưa có GV chính → người giao việc).
        $confirmerId = ClassReport::resolveConfirmerId($class, $task, (int) $user->id);
        if (! $hasImage && ! $confirmerId) {
            throw ValidationException::withMessages([
                'board_images' => 'Lớp chưa có GV chính và báo cáo không gắn đầu việc "Trực lớp" được giao, nên không có người xác nhận. '
                    .'Hãy đính kèm ít nhất 1 ảnh bảng/lớp hoặc nộp từ đầu việc "Trực lớp" trong Portal.',
            ]);
        }

        $paths = [];
        foreach ($files as $file) {
            $paths[] = SafeUploadService::store($file, 'class_reports', SafeUploadService::IMAGES, 'board_images');
        }
        if ($request->filled('board_image_url')) {
            $paths[] = $request->input('board_image_url');
        }

        $sessionName = trim((string) ($validated['session_name'] ?? ''));
        if ($sessionName === '' && $session) {
            $sessionName = self::sessionLabel($session, app(SessionLessonService::class)->lessonsFor(collect([$session]))[$session->id] ?? null);
        }

        $report = DB::transaction(function () use ($validated, $class, $session, $task, $user, $paths, $hasImage, $confirmerId, $sessionName) {
            $report = ClassReport::create([
                'task_id' => $task?->id,
                'class_id' => $class->id,
                'class_session_id' => $session?->id,
                'reporter_id' => $user->id,
                'confirmer_id' => $hasImage ? null : $confirmerId,
                'session_name' => $sessionName,
                'session_date' => ($session?->date ?? today())->toDateString(),
                'topics_learned' => $validated['hom_nay_hoc_gi'],
                'teaching_log' => $validated['nhat_ky_day'] ?? null,
                'board_image' => $paths[0] ?? null,
                'board_images' => $paths ?: null,
                'has_image' => $hasImage,
                // Có ≥ 1 ảnh → xác nhận ngay; không ảnh → chờ xác nhận.
                'status' => $hasImage ? ClassReport::STATUS_APPROVED : ClassReport::STATUS_PENDING,
                'approved_at' => $hasImage ? now() : null,
                'approved_by' => null,
            ]);

            foreach ($validated['supports'] ?? [] as $supp) {
                if (! empty($supp['student_id']) && ! empty($supp['reason'])) {
                    ClassReportStudentSupport::create([
                        'class_report_id' => $report->id,
                        'class_id' => $report->class_id,
                        'student_id' => $supp['student_id'],
                        'absence_session' => $supp['absence_session'] ?? null,
                        'reason' => $supp['reason'],
                        'action_plan' => $supp['action_plan'] ?? null,
                    ]);
                }
            }

            // Đầu việc "Trực lớp": có ảnh → tự Hoàn thành; không ảnh → Chờ xác nhận.
            if ($task) {
                $task->update([
                    'status' => $hasImage ? 'completed' : 'pending_confirmation',
                    'completed_at' => $hasImage ? now() : null,
                    'completion_proof_image' => $paths[0] ?? null,
                    'completion_note' => 'Báo cáo trực lớp: '.$validated['hom_nay_hoc_gi'],
                    'rejection_reason' => null,
                ]);
            }

            return $report;
        });

        if (! $hasImage) {
            $this->notifyClassReportConfirmer($report->load(['classModel', 'task']), $class);
        }

        $confirmerName = $confirmerId ? User::find($confirmerId)?->name : null;
        $msg = $hasImage
            ? 'Đã nộp báo cáo trực lớp kèm '.count($paths).' ảnh — đầu việc "Trực lớp" đã tự hoàn thành.'
            : 'Đã nộp báo cáo trực lớp (không có ảnh) — chờ '.($report->confirmerRoleLabel() === 'GV chính của lớp' ? 'GV chính' : 'người giao việc')
                .($confirmerName ? " {$confirmerName}" : '').' xác nhận.';

        return $this->modalSaved($msg, route('portal.ta-tasks'), 'success');
    }

    /**
     * 7. Xác nhận hoàn thành thủ công (Manual Task Approvals)
     */
    public function manualApprovals(Request $request)
    {
        $validated = $request->validate([
            'selected_id' => ['nullable', 'integer'],
            'report' => ['nullable', 'integer'],
            'kind' => ['nullable', 'in:task,report'],
            'q' => ['nullable', 'string', 'max:100'],
            'assignee_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        $kind = $validated['kind'] ?? null;
        $search = trim((string) ($validated['q'] ?? ''));
        $assigneeFilter = isset($validated['assignee_id']) ? (int) $validated['assignee_id'] : null;

        // Việc thường chờ xác nhận: người có quyền duyệt thấy trong phạm vi; người khác chỉ việc mình giao.
        // Việc "Trực lớp" có báo cáo chờ xác nhận đi theo luật Q8 (mục báo cáo bên dưới), không lặp ở đây.
        // Không ai duyệt việc của chính mình.
        $pendingQuery = WorkTask::with(['assignee', 'creator', 'classModel'])->awaitingConfirmationBy($user);
        $pendingTasks = $kind === 'report' ? collect() : $pendingQuery
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('title', 'like', "%{$search}%")
                ->orWhereHas('assignee', fn ($a) => $a->where('name', 'like', "%{$search}%"))))
            ->when($assigneeFilter, fn ($q) => $q->where('assignee_id', $assigneeFilter))
            ->latest('updated_at')
            ->get();

        // Báo cáo trực lớp chờ xác nhận: chỉ người xác nhận theo Q8 (GV chính / người giao việc).
        $pendingReports = $kind === 'task' ? collect() : ClassReport::with(['classModel', 'reporter', 'task.creator', 'studentSupports.student'])
            ->where('status', ClassReport::STATUS_PENDING)
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('session_name', 'like', "%{$search}%")
                ->orWhere('topics_learned', 'like', "%{$search}%")
                ->orWhereHas('reporter', fn ($a) => $a->where('name', 'like', "%{$search}%"))
                ->orWhereHas('classModel', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))))
            ->when($assigneeFilter, fn ($q) => $q->where('reporter_id', $assigneeFilter))
            ->latest()
            ->get()
            ->filter(fn (ClassReport $report) => $this->canReviewClassReport($report, $user))
            ->values();

        // Chi tiết mở trong modal khi URL chọn 1 mục (?selected_id= việc / ?report= báo cáo); không chọn → chỉ danh sách.
        $selectedReport = isset($validated['report']) ? $pendingReports->firstWhere('id', (int) $validated['report']) : null;
        $selectedTask = ! $selectedReport && isset($validated['selected_id'])
            ? $pendingTasks->firstWhere('id', (int) $validated['selected_id'])
            : null;

        $assigneeOptions = $pendingTasks->pluck('assignee')->merge($pendingReports->pluck('reporter'))
            ->filter()->unique('id')->sortBy('name');

        // Báo cáo + việc chờ xác nhận chung một danh sách, mới cập nhật trước; bấm dòng → chi tiết (?selected_id= / ?report=).
        $filterQuery = array_filter(['kind' => $kind, 'q' => $search ?: null, 'assignee_id' => $assigneeFilter]);
        $items = $pendingReports->toBase()->map(fn (ClassReport $r) => ['kind' => 'report', 'model' => $r, 'sort' => $r->created_at])
            ->merge($pendingTasks->map(fn (WorkTask $t) => ['kind' => 'task', 'model' => $t, 'sort' => $t->updated_at]))
            ->sortByDesc('sort')->values()
            ->map(function (array $item) use ($filterQuery, $selectedReport, $selectedTask) {
                $m = $item['model'];
                $isReport = $item['kind'] === 'report';

                return [
                    'key' => $item['kind'].'-'.$m->id,
                    'kind' => $item['kind'],
                    'title' => $isReport ? $m->session_name : $m->title,
                    'icon' => ($isReport || $m->classModel) ? 'class' : 'work',
                    'class_label' => $m->classModel?->name ?? ($isReport ? 'Lớp đã xóa' : 'Công việc chung'),
                    'person' => ($isReport ? $m->reporter : $m->assignee)?->name,
                    'date' => ($isReport ? $m->session_date : ($m->created_at ?? $m->due_date))?->format('d/m/Y'),
                    'url' => route('tasks.manual-approvals', $filterQuery + ($isReport ? ['report' => $m->id] : ['selected_id' => $m->id])),
                    'approve_url' => $isReport ? route('tasks.class-reports.approve', $m->id) : route('tasks.approve', $m->id),
                    'selected' => $isReport ? $selectedReport?->id === $m->id : $selectedTask?->id === $m->id,
                ];
            });

        return Inertia::render('Tasks/ManualApprovals', [
            'items' => $items,
            'selected' => ($selectedReport || $selectedTask) ? $this->approvalDetail($selectedReport, $selectedTask) : null,
            'assigneeOptions' => Ui::options($assigneeOptions, 'name'),
            'dismissUrl' => route('tasks.manual-approvals', $filterQuery),
        ]);
    }

    /** Chi tiết một mục chờ xác nhận (báo cáo trực lớp hoặc việc thường) cho modal xác nhận / trả về. */
    private function approvalDetail(?ClassReport $report, ?WorkTask $task): array
    {
        $isReport = (bool) $report;
        $m = $report ?? $task;
        $taskOfItem = $isReport ? $m->task : $m;
        $note = $isReport ? null : $m->completion_note;
        preg_match_all('~https?://[^\s<>"\']+~u', (string) ($isReport ? $m->topics_learned.' '.$m->teaching_log : $note), $noteLinks);

        return [
            'key' => ($isReport ? 'report' : 'task').'-'.$m->id,
            'kind' => $isReport ? 'report' : 'task',
            'title' => $isReport ? $m->session_name : $m->title,
            'updated' => $m->updated_at?->diffForHumans(),
            'class_label' => ($m->classModel?->name ?? 'Công việc chung').(! $isReport && $m->lesson_session ? ' · '.$m->lesson_session : ''),
            'person' => ($isReport ? $m->reporter : $m->assignee)?->name,
            'creator' => $taskOfItem?->creator?->name,
            'assigned_date' => ($taskOfItem?->created_at ?? $m->created_at)?->format('d/m/Y'),
            'due' => $taskOfItem?->due_date ? $taskOfItem->due_date->format('d/m/Y').' '.substr((string) ($taskOfItem->due_time ?: '23:59'), 0, 5) : null,
            'confirmer' => $isReport ? $m->confirmerRoleLabel() : null,
            'topics' => $isReport ? $m->topics_learned : null,
            'teaching_log' => $isReport ? $m->teaching_log : null,
            'supports' => $isReport ? $m->studentSupports->map(fn ($s) => ['id' => $s->id, 'name' => $s->student?->name ?? 'Học sinh', 'reason' => $s->reason])->values()->all() : [],
            'note' => $note,
            'links' => array_values(array_unique($noteLinks[0] ?? [])),
            'approve_url' => $isReport ? route('tasks.class-reports.approve', $m->id) : route('tasks.approve', $m->id),
            'reject_url' => $isReport ? route('tasks.class-reports.reject', $m->id) : route('tasks.reject', $m->id),
            'reject_field' => $isReport ? 'reason' : 'admin_note',
        ];
    }

    /**
     * Chuyển trạng thái hợp lệ của công việc.
     */
    public const STATUS_TRANSITIONS = [
        'new' => ['in_progress', 'blocked', 'pending_confirmation', 'canceled'],
        'in_progress' => ['blocked', 'pending_confirmation', 'completed', 'canceled'],
        'blocked' => ['in_progress', 'canceled'],
        'pending_confirmation' => ['completed', 'in_progress'],
        'overdue' => ['in_progress', 'pending_confirmation', 'completed', 'canceled'],
        'completed' => [],
        'canceled' => [],
    ];

    /**
     * Trạng thái mà $user được chuyển công việc sang:
     *  - Người thực hiện: bắt đầu / báo bị chặn / gửi chờ xác nhận (KHÔNG tự hoàn thành).
     *  - Người giao hoặc người có quyền duyệt (khác người thực hiện): hoàn thành, hủy, trả về.
     *
     * @return string[]
     */
    public static function allowedTransitions(WorkTask $task, User $user): array
    {
        $next = self::STATUS_TRANSITIONS[$task->status] ?? [];
        $isAssignee = (int) $task->assignee_id === (int) $user->id;
        $isCreator = (int) $task->creator_id === (int) $user->id;
        $isApprover = ! $isAssignee && ($isCreator || $user->can('work_task.approve'));

        return array_values(array_filter($next, function (string $status) use ($isAssignee, $isApprover, $isCreator, $task) {
            return match ($status) {
                'completed' => $isApprover,
                'canceled' => $isApprover || $isCreator,
                'pending_confirmation' => $isAssignee,
                'in_progress' => $isAssignee || ($isApprover && $task->status === 'pending_confirmation'),
                'blocked' => $isAssignee,
                default => false,
            };
        }));
    }

    /**
     * Duyệt báo cáo trực lớp chờ xác nhận (không có ảnh bảng): GV chính của lớp
     * hoặc Học vụ/Quản lý (work_task.approve). Người nộp không tự duyệt.
     */
    public function approveClassReport(Request $request, int $id)
    {
        $report = ClassReport::with(['classModel', 'task'])->findOrFail($id);
        abort_unless($this->canReviewClassReport($report, $request->user()), 403, 'Chỉ GV chính của lớp (lớp chưa có GV chính: người giao việc) được xác nhận báo cáo này.');
        abort_unless($report->status === ClassReport::STATUS_PENDING, 422, 'Báo cáo không ở trạng thái chờ xác nhận.');

        $this->confirmClassReport($report, $request->user(), $request->input('admin_note'));

        return back()->with('success', 'Đã xác nhận báo cáo trực lớp — đầu việc "Trực lớp" đã hoàn thành.');
    }

    public function rejectClassReport(Request $request, int $id)
    {
        $validated = $request->validate(['reason' => 'required|string|max:1000'], ['reason.required' => 'Vui lòng nhập lý do trả về.']);
        $report = ClassReport::with(['classModel', 'task'])->findOrFail($id);
        abort_unless($this->canReviewClassReport($report, $request->user()), 403, 'Chỉ GV chính của lớp (lớp chưa có GV chính: người giao việc) được xác nhận báo cáo này.');
        abort_unless($report->status === ClassReport::STATUS_PENDING, 422, 'Báo cáo không ở trạng thái chờ xác nhận.');

        $this->returnClassReport($report, $request->user(), $validated['reason']);

        return back()->with('info', 'Đã trả báo cáo trực lớp về cho người nộp.');
    }

    private function confirmClassReport(ClassReport $report, User $actor, ?string $note = null): void
    {
        DB::transaction(function () use ($report, $actor, $note) {
            $report->update([
                'status' => ClassReport::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);

            if ($report->task && $report->task->status === 'pending_confirmation') {
                $report->task->update([
                    'status' => 'completed',
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => now(),
                    'completed_at' => now(),
                    'completion_note' => trim(($report->task->completion_note ?? '').($note ? "\n[Ghi chú xác nhận]: {$note}" : '')),
                ]);
            }
        });

        $this->notifyUser($report->reporter_id, 'class_report_pending', 'Báo cáo trực lớp đã được xác nhận',
            "{$actor->name} đã xác nhận báo cáo trực lớp {$report->session_name}.", route('portal.ta-tasks'));
    }

    private function returnClassReport(ClassReport $report, User $actor, string $reason): void
    {
        DB::transaction(function () use ($report, $reason) {
            $report->update([
                'status' => ClassReport::STATUS_REJECTED,
                'rejection_reason' => $reason,
            ]);

            if ($report->task && $report->task->status === 'pending_confirmation') {
                $report->task->update([
                    'status' => 'in_progress',
                    'rejection_reason' => $reason,
                ]);
            }
        });

        $this->notifyUser($report->reporter_id, 'class_report_pending', 'Báo cáo trực lớp bị trả về',
            "{$actor->name}: {$reason}", route('portal.ta-tasks'));
    }

    /**
     * Giới hạn danh sách công việc được xem:
     *  - Có quyền duyệt (Admin/Quản lý/Học vụ/Học thuật): tất cả; Quản lý cơ sở
     *    chỉ việc thuộc chi nhánh mình (hoặc việc mình tạo/được giao).
     *  - Người khác: chỉ việc mình tạo hoặc được giao.
     */
    private function scopeVisibleTasks($query, User $user)
    {
        return $query->visibleTo($user);
    }

    /**
     * Chi nhánh giới hạn khi giao việc / xem KPI nhân sự: phạm vi Công việc "Chi nhánh" (mặc định Quản lý cơ sở) → chi
     * nhánh của mình; mức khác → null (không giới hạn theo chi nhánh).
     *
     * @return list<int>|null
     */
    private static function branchLimit(User $user): ?array
    {
        return DataScope::level($user, 'work_task') === DataScope::BRANCH ? $user->branchIds() : null;
    }

    /**
     * Không tự duyệt việc của chính mình; chỉ người giao việc hoặc người có
     * quyền duyệt (trong phạm vi) được duyệt; chỉ duyệt việc đang chờ xác nhận.
     */
    private function ensureCanApprove(WorkTask $task, User $user): void
    {
        abort_if((int) $task->assignee_id === (int) $user->id, 403, 'Không thể tự duyệt công việc của chính mình.');
        abort_unless(
            (int) $task->creator_id === (int) $user->id
                || ($user->can('work_task.approve') && $this->isTaskParticipant($task, $user)),
            403,
            'Bạn không có quyền duyệt công việc này.'
        );
        abort_unless($task->status === 'pending_confirmation', 422, 'Công việc không ở trạng thái chờ xác nhận.');
    }

    private function isTaskParticipant(WorkTask $task, User $user): bool
    {
        if ((int) $task->assignee_id === (int) $user->id || (int) $task->creator_id === (int) $user->id) {
            return true;
        }

        return $user->can('work_task.approve')
            && $this->scopeVisibleTasks(WorkTask::query()->whereKey($task->id), $user)->exists();
    }

    /**
     * Người được giao việc: người có quyền giao việc (work_task.create) giao cho
     * mọi nhân sự đang hoạt động (Quản lý cơ sở: trong chi nhánh mình); người chỉ
     * có quyền đề xuất (work_task.request — GV/TA) chỉ giao ngược cho Admin,
     * Quản lý cơ sở, Học vụ, Học thuật.
     */
    private function assignableUsers(User $user)
    {
        $query = User::where('is_active', true)->whereNull('locked_at')->orderBy('name');

        if (! $user->can('work_task.create')) {
            // Đề xuất ngược (GV / TA): chỉ giao cho người có quyền duyệt công việc.
            Rbac::scopeUsersWithPermission($query, 'work_task.approve');
        } elseif (($managed = self::branchLimit($user)) !== null) {
            $query->where(fn ($q) => $q->whereIn('branch_id', $managed)->orWhere('id', $user->id));
        }

        return $query->get();
    }

    private function ensureCanCreateTask(): void
    {
        $user = Auth::user();
        abort_unless($user && ($user->can('work_task.create') || $user->can('work_task.request')), 403, 'Bạn không có quyền giao việc.');
    }

    private function pendingReportOf(WorkTask $task): ?ClassReport
    {
        return ClassReport::with(['classModel', 'task'])
            ->where('task_id', $task->id)
            ->where('status', ClassReport::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * A6 Q8: chỉ đúng người xác nhận — GV chính của lớp; lớp chưa có GV chính thì
     * người giao đầu việc "Trực lớp". Người nộp không tự xác nhận.
     */
    private function canReviewClassReport(ClassReport $report, User $user): bool
    {
        return $report->isConfirmableBy($user);
    }

    private function notifyAssignee(WorkTask $task, int $count = 1): void
    {
        if (! $task->assignee_id || (int) $task->assignee_id === (int) Auth::id()) {
            return;
        }

        $creator = Auth::user()?->name ?? 'Hệ thống';
        $title = $count > 1 ? "Bạn được giao {$count} nhiệm vụ mới" : "Bạn được giao việc: {$task->title}";
        $message = $count > 1
            ? "{$creator} đã giao {$count} nhiệm vụ trực ca ngày ".$task->due_date?->format('d/m/Y').'.'
            : "{$creator} đã giao việc, hạn ".$task->due_date?->format('d/m/Y').'.';

        $this->notifyUser($task->assignee_id, 'task_assigned', $title, $message, route('tasks.index', ['tab' => 'mine']), ['task_id' => $task->id]);
    }

    /** Báo đúng một người xác nhận (GV chính, hoặc người giao việc khi lớp chưa có GV chính). */
    private function notifyClassReportConfirmer(ClassReport $report, ClassModel $class): void
    {
        $confirmerId = $report->currentConfirmerId();
        if (! $confirmerId) {
            return;
        }

        $this->notifyUser($confirmerId, 'class_report_pending', 'Báo cáo trực lớp chờ xác nhận',
            "Báo cáo {$report->session_name} lớp {$class->name} không có ảnh bảng — bạn là ".mb_strtolower($report->confirmerRoleLabel()).', cần xác nhận.',
            route('tasks.manual-approvals', ['report' => $report->id]), ['class_report_id' => $report->id]);
    }

    /** Người thực hiện gửi "Chờ xác nhận" (không ảnh) → báo người giao việc. */
    private function notifyTaskConfirmer(WorkTask $task): void
    {
        if (! $task->creator_id || (int) $task->creator_id === (int) $task->assignee_id) {
            return;
        }

        $this->notifyUser($task->creator_id, 'task_assigned', "Việc chờ xác nhận: {$task->title}",
            ($task->assignee?->name ?? 'Người thực hiện').' đã báo hoàn thành (không ảnh minh chứng), cần bạn xác nhận.',
            route('tasks.manual-approvals', ['selected_id' => $task->id]), ['task_id' => $task->id]);
    }

    private function notifyUser(?int $userId, string $type, string $title, string $message, string $link, array $data = []): void
    {
        if (! $userId) {
            return;
        }

        AdminNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => array_merge($data, ['link' => $link]),
            'is_read' => false,
        ]);
    }

    public function approveTask(Request $request, $id)
    {
        $task = WorkTask::findOrFail($id);

        // Đầu việc "Trực lớp" có báo cáo chờ xác nhận: theo luật Q8 (GV chính / người giao việc).
        if ($report = $this->pendingReportOf($task)) {
            abort_unless($this->canReviewClassReport($report, $request->user()), 403, 'Chỉ GV chính của lớp (lớp chưa có GV chính: người giao việc) được xác nhận báo cáo trực lớp này.');
            $this->confirmClassReport($report, $request->user(), $request->input('admin_note'));

            return redirect()->route('tasks.manual-approvals')->with('success', "Đã xác nhận hoàn thành công việc '{$task->title}'!");
        }

        $this->ensureCanApprove($task, $request->user());
        $adminNote = $request->input('admin_note');

        $task->update([
            'status' => 'completed',
            'confirmed_by' => Auth::id() ?? 1,
            'confirmed_at' => now(),
            'completed_at' => now(),
            'rejection_reason' => null,
        ]);

        if ($adminNote) {
            $task->completion_note = ($task->completion_note ? $task->completion_note."\n[Ghi chú duyệt]: " : '[Ghi chú duyệt]: ').$adminNote;
            $task->save();
        }

        return redirect()->route('tasks.manual-approvals')->with('success', "Đã xác nhận hoàn thành công việc '{$task->title}'!");
    }

    public function rejectTask(Request $request, $id)
    {
        $task = WorkTask::findOrFail($id);
        $request->validate(['admin_note' => 'required|string|max:1000'], ['admin_note.required' => 'Vui lòng nhập lý do từ chối / yêu cầu bổ sung.']);

        if ($report = $this->pendingReportOf($task)) {
            abort_unless($this->canReviewClassReport($report, $request->user()), 403, 'Chỉ GV chính của lớp (lớp chưa có GV chính: người giao việc) được xác nhận báo cáo trực lớp này.');
            $this->returnClassReport($report, $request->user(), $request->input('admin_note'));

            return redirect()->route('tasks.manual-approvals')->with('info', "Đã từ chối/yêu cầu bổ sung cho công việc '{$task->title}'!");
        }

        $this->ensureCanApprove($task, $request->user());
        $adminNote = $request->input('admin_note');

        $task->update([
            'status' => 'in_progress',
            'rejection_reason' => $adminNote,
        ]);

        $this->notifyUser($task->assignee_id, 'task_assigned', "Công việc bị trả về: {$task->title}", $adminNote, route('tasks.index', ['tab' => 'mine']), ['task_id' => $task->id]);

        return redirect()->route('tasks.manual-approvals')->with('info', "Đã từ chối/yêu cầu bổ sung cho công việc '{$task->title}'!");
    }

    /**
     * 8. TKB & Cấu hình lịch lặp báo cáo phòng nhân sự
     */
    public function scheduleConfig(Request $request)
    {
        $validated = $request->validate([
            'report_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'report_date' => ['nullable', 'date'],
            'class_id' => ['nullable', 'integer'],
            'class_q' => ['nullable', 'string', 'max:100'],
        ]);
        $viewer = $request->user();
        $classSearch = trim((string) ($validated['class_q'] ?? ''));

        // Chỉ lớp người xem phụ trách (nhân sự quản lý lớp thấy tất cả).
        $classes = ClassModel::visibleTo($viewer)
            ->with(['teacher:id,name', 'assistant:id,name', 'foreignTeacher:id,name', 'branch:id,name', 'scheduleConfig'])
            ->orderBy('name')
            ->get();
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        // Bảng "Danh sách lớp hiện tại": tìm phía server theo tên / mã lớp (form chọn lớp vẫn dùng toàn bộ danh sách).
        $needle = mb_strtolower($classSearch);
        $listClasses = $needle === '' ? $classes : $classes->filter(
            fn (ClassModel $c) => str_contains(mb_strtolower($c->name.' '.$c->code), $needle)
        )->values();

        // Năm học cho ô chọn "Năm học áp dụng": năm trước → năm sau, cộng các năm học đã lưu.
        $academicYears = collect(range(now()->year - 1, now()->year + 1))
            ->map(fn (int $y) => $y.' - '.($y + 1))
            ->merge($classes->pluck('scheduleConfig.academic_year')->filter())
            ->unique()->sort()->values();

        // Dữ liệu điền sẵn form khi chọn lớp đã có TKB (sửa lịch; buổi quá khứ/đã điểm danh được giữ nguyên).
        $scheduleData = $classes->mapWithKeys(fn (ClassModel $class) => [$class->id => [
            'academic_year' => $class->scheduleConfig?->academic_year,
            'start_date' => $class->start_date?->toDateString(),
            'end_date' => $class->end_date?->toDateString(),
            'slot1_day' => $class->scheduleConfig?->slot1_day,
            'slot1_start' => $class->scheduleConfig?->slot1_start ? substr($class->scheduleConfig->slot1_start, 0, 5) : null,
            'slot1_end' => $class->scheduleConfig?->slot1_end ? substr($class->scheduleConfig->slot1_end, 0, 5) : null,
            'slot2_day' => $class->scheduleConfig?->slot2_day,
            'slot2_start' => $class->scheduleConfig?->slot2_start ? substr($class->scheduleConfig->slot2_start, 0, 5) : null,
            'slot2_end' => $class->scheduleConfig?->slot2_end ? substr($class->scheduleConfig->slot2_end, 0, 5) : null,
            'status' => $class->status,
            'branch_id' => $class->branch_id,
            'room_id' => $class->room_id,
        ]]);

        // Báo cáo phòng / nhân sự: 7 ngày từ ngày chọn, theo chi nhánh, số liệu từ buổi học thật.
        $reportBranchId = (int) ($validated['report_branch_id'] ?? $viewer->branch_id ?? $branches->first()?->id);
        $reportStart = CarbonImmutable::parse($validated['report_date'] ?? now())->startOfDay();
        $reportEnd = $reportStart->addDays(6);
        $visibleClassIds = $classes->modelKeys();
        $sessionsInRange = fn (CarbonImmutable $from, CarbonImmutable $to) => ClassSession::query()
            ->where('branch_id', $reportBranchId)
            ->whereIn('class_id', $visibleClassIds)
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['id', 'class_id', 'date', 'room', 'teacher_id', 'foreign_teacher_id', 'assistant_id']);

        $weekSessions = $sessionsInRange($reportStart, $reportEnd);
        $previousWeekSessions = $sessionsInRange($reportStart->subDays(7), $reportStart->subDay());
        $savedDemands = HrDailyDemand::where('branch_id', $reportBranchId)
            ->whereDate('report_date', '>=', $reportStart->toDateString())
            ->whereDate('report_date', '<=', $reportEnd->toDateString())
            ->get()
            ->keyBy(fn (HrDailyDemand $demand) => $demand->report_date->toDateString());

        // Phòng khả dụng = phòng của chi nhánh không có buổi học nào trong ngày (tính trên mọi lớp, không chỉ lớp người xem phụ trách).
        $branchRoomNames = Room::where('branch_id', $reportBranchId)->pluck('name');
        $usedRoomsByDay = ClassSession::query()
            ->where('branch_id', $reportBranchId)
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', $reportStart->toDateString())
            ->whereDate('date', '<=', $reportEnd->toDateString())
            ->whereIn('room', $branchRoomNames)
            ->get(['date', 'room'])
            ->groupBy(fn (ClassSession $s) => $s->date->toDateString())
            ->map(fn ($sessions) => $sessions->pluck('room')->unique()->count());

        $report = collect(range(0, 6))->map(function (int $offset) use ($reportStart, $weekSessions, $savedDemands, $branchRoomNames, $usedRoomsByDay) {
            $day = $reportStart->addDays($offset);
            $sessions = $weekSessions->filter(fn (ClassSession $s) => $s->date->isSameDay($day));
            $assistants = $sessions->pluck('assistant_id')->filter()->unique()->count();

            return [
                'date' => $day,
                'label' => ClassDashboardService::WEEKDAYS[$day->isoWeekday()],
                'shifts' => $sessions->count(),
                'rooms' => $sessions->pluck('room')->filter()->unique()->count(),
                'rooms_total' => $branchRoomNames->count(),
                'rooms_free' => $branchRoomNames->count() - $usedRoomsByDay->get($day->toDateString(), 0),
                'teachers' => $sessions->flatMap(fn ($s) => [$s->teacher_id, $s->foreign_teacher_id])->filter()->unique()->count(),
                'assistants' => $assistants,
                'staff_needed' => $savedDemands->get($day->toDateString())?->staff_needed ?? $assistants,
                'saved' => $savedDemands->has($day->toDateString()),
            ];
        });
        $classCountChange = [
            'previous' => $previousWeekSessions->pluck('class_id')->unique()->count(),
            'current' => $weekSessions->pluck('class_id')->unique()->count(),
        ];

        // Buổi sắp tới bị hủy do ngày nghỉ lễ thêm sau (kèm ngày học bù).
        $holidaySessions = ClassSession::with(['classModel:id,name,code', 'holiday:id,name', 'makeupSession:id,rescheduled_from_id,date,start_time'])
            ->whereNotNull('holiday_id')
            ->where('status', 'cancelled')
            ->whereIn('class_id', $visibleClassIds)
            ->whereDate('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->limit(50)
            ->get();

        $selectedClassId = old('class_id', $validated['class_id'] ?? null);

        // Xuất Excel báo cáo phòng / nhân sự 7 ngày của chi nhánh đang chọn.
        if ($request->boolean('export')) {
            $branchName = $branches->firstWhere('id', $reportBranchId)?->name ?? '';
            $rows = $report->map(fn (array $d) => [
                $d['label'].' '.$d['date']->format('d/m/Y'),
                $branchName,
                $d['shifts'],
                $d['rooms'],
                $d['teachers'],
                $d['assistants'],
                $d['staff_needed'],
            ])->values()->all();

            return ArrayExport::download(
                'bao-cao-phong-nhan-su-'.$reportStart->format('Ymd'),
                ['Ngày', 'Chi nhánh', 'Số ca', 'Số phòng', 'Số GV/GVNN', 'Số trợ giảng', 'Nhu cầu nhân sự'],
                $rows,
                $request->query('format', 'xlsx')
            );
        }

        // Báo cáo phòng / nhân sự là việc khác với xếp lịch: mở riêng qua nút trên đầu trang; đang lọc báo cáo thì vẫn ở màn báo cáo.
        $view = $request->query('view') === 'report' || $request->hasAny(['report_branch_id', 'report_date']) ? 'report' : 'config';
        $defaults = [
            'academic_year' => now()->format('Y').' - '.now()->addYear()->format('Y'),
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'slot1_day' => 'Thứ 2', 'slot1_start' => '18:00', 'slot1_end' => '19:30',
            'slot2_day' => '', 'slot2_start' => '18:00', 'slot2_end' => '19:30',
            'room_id' => '',
        ];
        $initial = ['class_id' => (string) ($selectedClassId ?? '')];
        foreach ($defaults as $key => $default) {
            $initial[$key] = ($selectedClassId ? ($scheduleData[$selectedClassId][$key] ?? null) : null) ?? $default;
        }
        $schedulable = $classes->reject(fn (ClassModel $c) => in_array($c->status, ['cancelled', 'completed'], true));

        return Inertia::render('Tasks/ScheduleConfig', [
            'view' => $view,
            'canSchedule' => $viewer->can('work_task.assign'),
            // Ô "Chọn phòng học": phòng của chi nhánh lớp đang chọn (lọc phía Vue), kèm lớp đang dùng phòng.
            'rooms' => RoomController::classFormOptions($schedulable->pluck('branch_id')->filter()->unique()),
            'newClasses' => Ui::options($schedulable->filter(fn (ClassModel $c) => ! $c->scheduleConfig), fn (ClassModel $c) => "{$c->name} ({$c->code})"),
            'scheduledClasses' => Ui::options($schedulable->filter(fn (ClassModel $c) => (bool) $c->scheduleConfig), fn (ClassModel $c) => "{$c->name} ({$c->code}) · {$c->schedule_text}"),
            'academicYears' => $academicYears->map(fn ($y) => ['value' => $y, 'label' => "Năm học {$y}"])->values(),
            'scheduleData' => $scheduleData,
            'initial' => $initial,
            'holidaySessions' => $holidaySessions->map(fn (ClassSession $s) => [
                'id' => $s->id,
                'class_name' => $s->classModel?->name,
                'class_code' => $s->classModel?->code,
                'when' => $s->date->format('d/m/Y').' '.$s->start_time?->format('H:i'),
                'holiday' => $s->holiday?->name,
                'makeup' => $s->makeupSession ? $s->makeupSession->date->format('d/m/Y').' '.$s->makeupSession->start_time?->format('H:i') : null,
            ])->values(),
            'classSearch' => $classSearch,
            'listClasses' => $listClasses->map(fn (ClassModel $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'schedule_text' => $c->schedule_text,
                'teacher' => $c->teacher?->name ?? $c->foreignTeacher?->name,
                'status' => $c->status,
                'has_config' => (bool) $c->scheduleConfig,
                'room' => $c->room,
            ])->values(),
            'branches' => Ui::options($branches, 'name'),
            'reportBranchId' => $reportBranchId,
            'reportStart' => $reportStart->toDateString(),
            'report' => $report->map(fn (array $d) => [...$d, 'day' => $d['date']->format('d/m'), 'date' => $d['date']->toDateString()])->values(),
            'classCountChange' => $classCountChange,
        ]);
    }

    public function updateScheduleConfig(Request $request, SessionScheduleService $schedule, RoomService $rooms)
    {
        // Toggle class status or update schedule
        if ($request->has('toggle_class_id')) {
            abort_unless($request->user()->can('class.update'), 403, 'Bạn không có quyền đổi trạng thái lớp học.');
            $cls = ClassModel::findOrFail($request->input('toggle_class_id'));
            // Chỉ chuyển qua lại Đang học ↔ Đã kết thúc; lớp đã hủy/chưa xếp lịch không được "mở lại" từ đây.
            if (! in_array($cls->status, ['active', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'toggle_class_id' => "Lớp {$cls->name} đang ở trạng thái '{$cls->status}', chỉ lớp Đang học/Đã kết thúc mới đổi trạng thái được tại đây.",
                ]);
            }
            $completing = $cls->status === 'active';
            $releasedRoom = $completing ? $cls->room : null;
            $cls->status = $completing ? 'completed' : 'active';
            // Kết thúc lớp giải phóng phòng cho lớp khác (buổi đã sinh giữ nguyên phòng như dữ liệu lịch sử).
            if ($completing) {
                $cls->room_id = null;
                $cls->room = null;
            }
            $cls->save();

            if ($completing) {
                return redirect()->back()->with('success', "Đã kết thúc lớp {$cls->name}.".($releasedRoom ? " {$releasedRoom} đã được giải phóng khỏi lớp này." : ''));
            }

            $reopened = redirect()->back()->with('success', "Đã mở lại lớp {$cls->name}.");

            return $cls->room ? $reopened : $reopened->with('warning', 'Lớp chưa có phòng học — vui lòng gán lại phòng ở Lịch & TKB lớp hoặc Sửa lớp.');
        }

        $payload = $request->validate([
            'class_id' => ['required', 'exists:classes,id'],
            'academic_year' => ['nullable', 'string', 'max:30'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'slot1_day' => ['nullable', 'in:Thứ 2,Thứ 3,Thứ 4,Thứ 5,Thứ 6,Thứ 7,Chủ nhật'],
            'slot1_start' => ['nullable', 'date_format:H:i'],
            'slot1_end' => ['nullable', 'date_format:H:i', 'after:slot1_start'],
            'slot2_day' => ['nullable', 'in:Thứ 2,Thứ 3,Thứ 4,Thứ 5,Thứ 6,Thứ 7,Chủ nhật'],
            'slot2_start' => ['nullable', 'date_format:H:i'],
            'slot2_end' => ['nullable', 'date_format:H:i', 'after:slot2_start'],
            'activate' => ['nullable', 'boolean'],
            'room_id' => ['nullable', 'integer'],
            'transfer_room' => ['nullable', 'boolean'],
        ]);

        $class = ClassModel::findOrFail($payload['class_id']);
        abort_unless(ClassModel::visibleTo($request->user())->whereKey($class->id)->exists(), 403, 'Bạn không phụ trách lớp này.');
        $startDate = Carbon::parse($payload['start_date'] ?? $class->start_date ?? now())->startOfDay();
        $endDate = Carbon::parse($payload['end_date'] ?? $class->end_date ?? $startDate->copy()->addMonths(3))->startOfDay();

        // Ca không chọn ngày học được coi là không có (trước đây Slot 2 trống bị gán mặc định "Thứ 7 18:00-19:30").
        $slots = [];
        foreach ([1, 2] as $n) {
            if (filled($payload["slot{$n}_day"] ?? null)) {
                $slots[] = [
                    'day' => $payload["slot{$n}_day"],
                    'start' => $payload["slot{$n}_start"] ?? '18:00',
                    'end' => $payload["slot{$n}_end"] ?? '19:30',
                    'name' => "Slot {$n}",
                ];
            }
        }
        if (empty($slots)) {
            throw ValidationException::withMessages(['slot1_day' => 'Cần chọn ít nhất một ca học (ngày trong tuần).']);
        }

        // Hai ca trùng ngày và chồng giờ là cấu hình vô nghĩa — chặn trước khi tạo buổi học
        if (count($slots) === 2
            && $slots[0]['day'] === $slots[1]['day']
            && strcmp($slots[0]['start'], $slots[1]['end']) < 0
            && strcmp($slots[1]['start'], $slots[0]['end']) < 0) {
            throw ValidationException::withMessages([
                'slot2_start' => "Hai ca học trùng ngày {$slots[0]['day']} và chồng giờ ({$slots[0]['start']}-{$slots[0]['end']} và {$slots[1]['start']}-{$slots[1]['end']}).",
            ]);
        }

        // Không bao giờ sinh lại buổi quá khứ: chỉ xếp từ hôm nay trở đi. Buổi đã có dữ liệu
        // thực tế (điểm danh/check-in/đã dạy) được giữ nguyên và không bị sinh trùng.
        $generateFrom = $startDate->copy()->max(now()->startOfDay());
        $replaceableIds = ClassSession::where('class_id', $class->id)->replaceable()->pluck('id');
        $keptSessions = ClassSession::where('class_id', $class->id)
            ->where('type', '!=', ClassSession::TYPE_SUPPORT)
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', $generateFrom->toDateString())
            ->whereKeyNot($replaceableIds)
            ->get()
            ->groupBy(fn (ClassSession $session) => $session->date->toDateString());

        $sessions = array_values(array_filter(
            $schedule->generate($slots, $generateFrom, $endDate, $class->branch_id),
            fn (array $session) => ! $keptSessions->get($session['date'], collect())->contains(
                fn (ClassSession $kept) => $kept->shift_name === $session['name']
                    || (strcmp(substr((string) $kept->getRawOriginal('start_time'), 0, 5), $session['end']) < 0
                        && strcmp(substr((string) $kept->getRawOriginal('end_time'), 0, 5), $session['start']) > 0)
            )
        ));

        if (count($sessions) > 300) {
            throw ValidationException::withMessages([
                'end_date' => 'Thời khóa biểu quá dài ('.count($sessions).' buổi). Giới hạn tối đa 300 buổi mỗi lần xếp lịch.',
            ]);
        }

        if (empty($sessions) && $keptSessions->isEmpty()) {
            throw ValidationException::withMessages([
                'start_date' => 'Không tạo được buổi học nào. Kiểm tra lại khoảng thời gian (chỉ xếp được từ hôm nay trở đi), ngày học trong tuần và lịch nghỉ lễ.',
            ]);
        }

        // Phòng học: form gửi room_id (rỗng = bỏ phòng) thì đổi phòng của lớp; không gửi thì giữ phòng hiện tại.
        $changeRoom = $request->has('room_id');
        $room = null;
        if ($changeRoom && filled($payload['room_id'] ?? null)) {
            $room = Room::whereKey($payload['room_id'])->where('branch_id', $class->branch_id)->first()
                ?? throw ValidationException::withMessages(['room_id' => 'Phòng học không thuộc chi nhánh của lớp, vui lòng chọn lại.']);
        }
        [$roomId, $roomName] = $changeRoom ? [$room?->id, $room?->name] : [$class->room_id, $class->room];
        $previousRoom = $class->room;

        // Ca học tuần trùng với lớp khác đang gắn phòng: lớp đang học → chặn; lớp chưa bắt đầu học → hỏi chuyển phòng
        // (xác nhận thì gỡ phòng khỏi các lớp đó).
        $transfer = collect();
        if ($room) {
            $conflicts = $rooms->slotConflicts($room, $slots, $class->id);
            $blocking = $conflicts->first(fn (array $c) => $c['class']->isStudying());
            if ($blocking) {
                throw ValidationException::withMessages([
                    'room_id' => "{$room->name} đã có lớp {$blocking['class']->name} đang học vào {$blocking['slot']} — vui lòng chọn phòng khác.",
                ]);
            }
            $transfer = $conflicts->pluck('class');
            if ($transfer->isNotEmpty() && ! ($payload['transfer_room'] ?? false)) {
                throw ValidationException::withMessages([
                    'room_transfer' => collect(["{$room->name} đang được gán cho lớp {$transfer->pluck('name')->implode(', ')} (chưa bắt đầu học). Chuyển phòng sang lớp này?"])
                        ->merge($conflicts->map(fn (array $c) => "{$c['class']->name} — dự kiến {$c['slot']}: sẽ bị hủy gán phòng"))
                        ->implode("\n"),
                ]);
            }
        }

        // Lớp chưa xếp lịch được kích hoạt khi có TKB; lớp "sắp khai giảng" chỉ kích hoạt khi người dùng
        // chọn rõ; lớp đã kết thúc/đã hủy giữ nguyên trạng thái.
        $status = match (true) {
            $class->status === 'pending_schedule' => 'active',
            $class->status === 'upcoming' && ($payload['activate'] ?? false) => 'active',
            default => $class->status,
        };

        DB::transaction(function () use ($class, $payload, $startDate, $endDate, $slots, $sessions, $replaceableIds, $status, $schedule, $rooms, $room, $transfer, $roomId, $roomName, $previousRoom) {
            // Chuyển phòng đã xác nhận: gỡ phòng khỏi lớp chưa bắt đầu học trước khi kiểm tra trùng buổi.
            if ($room && $transfer->isNotEmpty()) {
                $rooms->detach($room, $transfer);
            }

            $conflict = $schedule->findConflict(
                array_map(fn (array $session) => $session + ['room' => $roomName], $sessions),
                $class->branch_id,
                [$class->teacher_id, $class->assistant_id, $class->foreign_teacher_id],
                $roomName,
                $class->id,
            );
            if ($conflict) {
                [$session, $existing] = $conflict;
                throw ValidationException::withMessages([
                    'class_id' => "Xung đột {$session['date']} {$session['start']}-{$session['end']} với lớp {$existing->classModel?->name} ({$existing->classModel?->code}).",
                ]);
            }

            ClassScheduleConfig::updateOrCreate(
                ['class_id' => $class->id],
                [
                    'academic_year' => $payload['academic_year'] ?? $startDate->format('Y').' - '.$endDate->format('Y'),
                    'slot1_day' => $slots[0]['day'],
                    'slot1_start' => $slots[0]['start'],
                    'slot1_end' => $slots[0]['end'],
                    'slot2_day' => $slots[1]['day'] ?? null,
                    'slot2_start' => $slots[1]['start'] ?? null,
                    'slot2_end' => $slots[1]['end'] ?? null,
                ]
            );

            ClassSession::whereKey($replaceableIds)->delete();

            // Buổi sắp tới được giữ lại (học bù…) đang dùng phòng cũ của lớp cũng chuyển sang phòng mới.
            if ($roomName !== $previousRoom) {
                ClassSession::where('class_id', $class->id)->staffSyncable()
                    ->where(fn ($query) => $query->whereNull('room')->orWhere('room', $previousRoom))
                    ->update(['room' => $roomName]);
            }

            foreach ($sessions as $session) {
                ClassSession::create([
                    'class_id' => $class->id,
                    'branch_id' => $class->branch_id,
                    'date' => $session['date'],
                    'shift_name' => $session['name'],
                    'type' => ClassSession::TYPE_REGULAR,
                    'start_time' => $session['start'],
                    'end_time' => $session['end'],
                    'room' => $roomName,
                    'teacher_id' => $class->teacher_id ?? $class->foreign_teacher_id,
                    'foreign_teacher_id' => $class->foreign_teacher_id,
                    'assistant_id' => $class->assistant_id,
                    'status' => 'scheduled',
                ]);
            }

            $class->update([
                'start_date' => $startDate,
                'end_date' => $endDate,
                'schedule_text' => collect($slots)->map(fn ($slot) => "{$slot['day']} {$slot['start']}-{$slot['end']}")->implode('; '),
                'status' => $status,
                'room_id' => $roomId,
                'room' => $roomName,
            ]);
        });

        return redirect()->back()->with('success', 'Đã lưu lịch và tạo '.count($sessions).' buổi học thực tế.');
    }

    public function saveHrDemand(Request $request)
    {
        // Nhu cầu nhân sự lưu theo chi nhánh + ngày (demands[Y-m-d] = số người); số ca tính lại từ buổi học thật.
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'demands' => ['required', 'array', 'max:31'],
            'demands.*' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        foreach ($validated['demands'] as $date => $staffNeeded) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || $staffNeeded === null) {
                continue;
            }
            $day = Carbon::parse($date);
            $shifts = ClassSession::where('branch_id', $validated['branch_id'])
                ->where('status', '!=', 'cancelled')
                ->whereDate('date', $day->toDateString())
                ->count();
            // whereDate thay vì updateOrCreate: cột date có thể lưu kèm giờ (SQLite) nên so sánh bằng chuỗi sẽ tạo trùng.
            $demand = HrDailyDemand::where('branch_id', $validated['branch_id'])
                ->whereDate('report_date', $day->toDateString())
                ->first() ?? new HrDailyDemand(['branch_id' => $validated['branch_id'], 'report_date' => $day->toDateString()]);
            $demand->fill([
                'day_of_week' => ClassDashboardService::WEEKDAYS[$day->isoWeekday()],
                'shift_count' => $shifts,
                'staff_needed' => (int) $staffNeeded,
            ])->save();
        }

        return redirect()->back()->with('success', 'Đã lưu báo cáo nhu cầu nhân sự thành công!');
    }

    /**
     * Danh sách bổ trợ + xếp buổi phụ đạo. Danh sách gồm học viên vắng học, điểm mini test /
     * Big Test dưới 7 (tự thêm — SupportListService) và học viên ghi trong báo cáo trực lớp;
     * chỉ lớp người dùng được xem.
     */
    public function supportSessions(Request $request)
    {
        $user = $request->user();
        $visibleClassIds = ClassModel::query()->visibleTo($user)->pluck('id')->all();
        $source = $request->query('source');
        $sources = SupportListService::SOURCE_LABELS;

        $pendingSupports = ClassReportStudentSupport::with(['student', 'classModel', 'classReport.classModel'])
            ->whereDoesntHave('supportSession')
            ->where(fn ($q) => $q->whereIn('class_id', $visibleClassIds)
                ->orWhere(fn ($q) => $q->whereNull('class_id')
                    ->whereHas('classReport', fn ($r) => $r->whereIn('class_id', $visibleClassIds))))
            ->when($source && isset($sources[$source]), fn ($q) => $q->where('source', $source))
            ->latest()
            ->paginate(20, ['*'], 'list_page')
            ->withQueryString();

        $sessions = SupportSession::with(['student', 'classModel', 'teacher', 'supportItem'])
            ->whereIn('class_id', $visibleClassIds)
            ->latest('session_date')
            ->paginate(20, ['*'], 'session_page')
            ->withQueryString();

        $classes = ClassModel::whereIn('id', $visibleClassIds)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('name')
            ->get();
        $classRosters = ClassModel::rosterStudentsFor($classes);

        // Theo flow BA, buổi bổ trợ do CM/Học vụ hoặc TA đảm nhận (không nhất thiết GV chính),
        // nên picker bao gồm cả trợ giảng và học vụ bên cạnh các vai trò giáo viên.
        $teachers = User::where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['teacher', 'teacher_fulltime', 'teacher_parttime', 'academic_lead', 'academic_staff', 'assistant']))
            ->orderBy('name')
            ->get();

        $selectedSupport = $request->filled('support')
            ? ClassReportStudentSupport::with('classReport')->whereDoesntHave('supportSession')->find($request->query('support'))
            : null;
        if ($selectedSupport && ! in_array($selectedSupport->resolvedClassId(), $visibleClassIds, true)) {
            $selectedSupport = null;
        }

        $canApprove = $user->can('work_task.approve');

        return Inertia::render('Tasks/SupportSessions', [
            'pendingSupports' => $pendingSupports->through(fn (ClassReportStudentSupport $item) => [
                'id' => $item->id,
                'student' => $item->student?->name,
                'class_name' => $item->classModel?->name ?? $item->classReport?->classModel?->name,
                'source' => $item->source,
                'source_label' => $item->source_label,
                'reason' => $item->reason,
                'action_plan' => $item->action_plan,
                'date' => $item->created_at?->format('d/m/Y'),
            ]),
            'sessions' => $sessions->through(fn (SupportSession $session) => [
                'id' => $session->id,
                'student' => $session->student?->name,
                'class_name' => $session->classModel?->name,
                'source' => $session->supportItem?->source,
                'source_label' => $session->supportItem?->source_label,
                'reason' => $session->reason,
                'date' => $session->session_date->format('d/m/Y'),
                'time' => substr((string) $session->start_time, 0, 5).'-'.substr((string) $session->end_time, 0, 5),
                'room' => $session->room,
                'teacher' => $session->teacher?->name,
                'status' => $session->status,
                'status_label' => $session->status_label,
                'can_complete' => $session->status !== 'completed' && ((int) $session->teacher_id === (int) $user->id || $canApprove),
            ]),
            'sources' => collect($sources)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'source' => $source,
            'classes' => Ui::options($classes, 'name'),
            // Học viên theo lớp (nhóm theo tên lớp) cho ô "Học viên".
            'studentGroups' => $classes->filter(fn (ClassModel $c) => $classRosters[$c->id]->isNotEmpty())->map(fn (ClassModel $c) => [
                'label' => $c->name,
                'class_id' => $c->id,
                'options' => Ui::options($classRosters[$c->id], fn ($st) => "{$st->name} ({$st->code})"),
            ])->values(),
            'teachers' => Ui::options($teachers, 'name'),
            'selectedSupport' => $selectedSupport ? [
                'id' => $selectedSupport->id,
                'student' => $selectedSupport->loadMissing('student')->student?->name,
                'source_label' => $selectedSupport->source_label,
                'reason' => $selectedSupport->reason,
                'class_id' => $selectedSupport->resolvedClassId(),
                'student_id' => $selectedSupport->student_id,
            ] : null,
            'defaultDate' => now()->addDay()->format('Y-m-d'),
        ]);
    }

    public function storeSupportSession(Request $request)
    {
        $validated = $request->validate([
            'class_report_student_support_id' => ['nullable', 'exists:class_report_student_supports,id'],
            'class_id' => ['required', 'exists:classes,id'],
            'student_id' => ['required', 'exists:students,id'],
            'teacher_id' => ['required', 'exists:users,id'],
            'session_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $class = ClassModel::findOrFail($validated['class_id']);
        abort_unless(ClassModel::query()->visibleTo($request->user())->whereKey($class->id)->exists(), 403, 'Lớp này nằm ngoài phạm vi bạn được quản lý.');
        abort_unless($class->hasOnRoster((int) $validated['student_id']), 422, 'Học viên không thuộc lớp đã chọn.');

        if (! empty($validated['class_report_student_support_id'])) {
            $item = ClassReportStudentSupport::with(['classReport', 'supportSession'])->findOrFail($validated['class_report_student_support_id']);
            abort_unless((int) $item->student_id === (int) $validated['student_id'] && $item->resolvedClassId() === (int) $class->id,
                422, 'Dòng bổ trợ không khớp học viên/lớp đã chọn.');
            abort_if($item->supportSession !== null, 422, 'Học viên này đã được xếp buổi bổ trợ cho mục này.');
            if (empty($validated['reason'])) {
                $validated['reason'] = $item->reason;
            }
        }

        // Trùng lịch: bỏ qua buổi đã hủy; người dạy trùng nếu là GV / GVNN / trợ giảng của buổi khác.
        $start = ClassSession::normalizeTime($validated['start_time']);
        $end = ClassSession::normalizeTime($validated['end_time']);
        $conflict = ClassSession::whereDate('date', $validated['session_date'])
            ->where('status', '!=', 'cancelled')
            ->where('start_time', '<', $end)->where('end_time', '>', $start)
            ->where(function ($query) use ($validated, $class) {
                $query->forStaff((int) $validated['teacher_id']);
                if (! empty($validated['room'])) {
                    $query->orWhere(fn ($room) => $room->where('branch_id', $class->branch_id)->where('room', $validated['room']));
                }
            })->exists();
        if ($conflict) {
            return redirect()->back()->withInput()->withErrors(['start_time' => 'Người dạy hoặc phòng học bị trùng lịch với buổi khác trong khung giờ này.']);
        }

        DB::transaction(function () use ($validated, $class) {
            $classSession = ClassSession::create([
                'class_id' => $class->id,
                'branch_id' => $class->branch_id,
                'date' => $validated['session_date'],
                'shift_name' => 'Phụ đạo 1-1',
                'type' => ClassSession::TYPE_SUPPORT,
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'room' => $validated['room'] ?? null,
                'teacher_id' => $validated['teacher_id'],
                'status' => 'scheduled',
                'notes' => 'Buổi phụ đạo cho học viên #'.$validated['student_id'],
            ]);
            SupportSession::create($validated + [
                'scheduled_by' => Auth::id(),
                'class_session_id' => $classSession->id,
                'status' => 'scheduled',
            ]);
        });

        return redirect()->route('tasks.support-sessions')->with('success', 'Đã xếp lịch phụ đạo và giữ chỗ giáo viên/phòng học.');
    }

    public function completeSupportSession(Request $request, int $id)
    {
        $session = SupportSession::with('classSession')->findOrFail($id);
        abort_unless((int) $session->teacher_id === (int) Auth::id() || $request->user()->can('work_task.approve'), 403);
        abort_if($session->status === 'completed', 422, 'Buổi phụ đạo đã hoàn thành.');
        $validated = $request->validate(['completion_note' => ['nullable', 'string', 'max:2000']]);
        if (PayrollPeriod::isLockedFor($session->session_date)) {
            $message = PayrollPeriod::lockedMessage($session->session_date);

            return redirect()->back()->withErrors(['session_date' => $message])->with('error', $message);
        }

        DB::transaction(function () use ($session, $validated) {
            $session->update(['status' => 'completed', 'completed_at' => now(), 'completion_note' => $validated['completion_note'] ?? null]);
            $session->classSession?->update(['status' => 'completed']);
            $minutes = Carbon::parse($session->start_time)->diffInMinutes(Carbon::parse($session->end_time));
            TeacherTimesheet::updateOrCreate(
                ['user_id' => $session->teacher_id, 'class_session_id' => $session->class_session_id],
                [
                    'class_id' => $session->class_id,
                    'teaching_date' => $session->session_date,
                    'scheduled_time' => $session->start_time.'-'.$session->end_time,
                    'hours' => max(.5, $minutes / 60),
                    'type' => '1on1',
                    'status' => 'pending_review',
                    'notes' => 'Phụ đạo học viên #'.$session->student_id,
                ]
            );
        });

        return redirect()->back()->with('success', 'Đã hoàn thành buổi phụ đạo; bảng công được chuyển sang chờ duyệt.');
    }

    /**
     * 9. Bảng KPI tự động
     */
    public function kpiDashboard(Request $request, KpiBoardService $kpi)
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'month_to' => ['nullable', 'date_format:Y-m'],
            'user_id' => ['nullable', 'integer'],
        ]);
        // Kỳ báo cáo (mockup "Tháng 10/2023 - Tháng 11/2023"): từ tháng → đến tháng.
        $month = $validated['month'] ?? now()->format('Y-m');
        $monthTo = $validated['month_to'] ?? $month;
        if ($monthTo < $month) {
            [$month, $monthTo] = [$monthTo, $month];
        }
        $from = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $to = CarbonImmutable::createFromFormat('!Y-m', $monthTo)->endOfMonth();

        // KPI nhân sự theo phạm vi Công việc: Toàn hệ thống → mọi nhân sự; Chi nhánh (Quản lý cơ sở) → chi nhánh mình;
        // Của tôi → chỉ KPI của mình.
        $viewer = $request->user();
        $managed = self::branchLimit($viewer);
        $canSeeStaff = DataScope::level($viewer, 'work_task') !== DataScope::OWN;
        $scopedStaff = fn () => $kpi->staffQuery()
            ->when(! $canSeeStaff, fn ($q) => $q->whereKey($viewer->id))
            ->when($canSeeStaff && $managed !== null, fn ($q) => $q->whereIn('branch_id', $managed));
        if (! $canSeeStaff) {
            $validated['user_id'] = $viewer->id;
        }

        $staffOptions = $scopedStaff()->get(['id', 'name', 'employee_code']);

        // Xuất Excel toàn bộ nhân sự theo bộ lọc hiện tại (không phân trang).
        if ($request->boolean('export')) {
            $fmt = fn ($v) => $v === null ? 'Chưa có dữ liệu' : $v.'%';
            $exportStaff = $scopedStaff()
                ->when($validated['user_id'] ?? null, fn ($q, $userId) => $q->whereKey($userId))
                ->get();
            $metrics = $kpi->metricsForMany($exportStaff, $from, $to);
            $rows = $exportStaff
                ->map(function (User $user) use ($metrics, $fmt) {
                    $m = $metrics[$user->id];

                    return [
                        $user->employee_code ?: 'NS-'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
                        $user->name,
                        $m['classes'] ?? 0,
                        $fmt($m['attendance'] ?? null), $m['attendance_detail'] ?? '',
                        $fmt($m['homework'] ?? null), $m['homework_detail'] ?? '',
                        $fmt($m['tasks'] ?? null), $m['tasks_detail'] ?? '',
                        $fmt($m['retention'] ?? null), $m['retention_detail'] ?? '',
                    ];
                })->all();

            return ArrayExport::download(
                'kpi-'.$month.($monthTo !== $month ? '-'.$monthTo : ''),
                ['Mã NS', 'Nhân sự', 'Số lớp', 'Chuyên cần', 'Chi tiết chuyên cần', 'Bài tập', 'Chi tiết bài tập', 'Công việc', 'Chi tiết công việc', 'Giữ chân', 'Chi tiết giữ chân'],
                $rows,
                $request->query('format', 'xlsx')
            );
        }
        $staff = $scopedStaff()
            ->when($validated['user_id'] ?? null, fn ($q, $userId) => $q->whereKey($userId))
            ->paginate($request->perPage(20))
            ->withQueryString();

        $metrics = $kpi->metricsForMany($staff->getCollection(), $from, $to);

        return Inertia::render('Tasks/KpiDashboard', [
            'staff' => $staff->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'code' => $user->employee_code ?: 'NS-'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
            ] + collect($metrics[$user->id])->only([
                'classes', 'retention', 'retention_detail', 'attendance', 'attendance_detail',
                'homework', 'homework_detail', 'tasks', 'tasks_detail',
            ])->all()),
            'staffOptions' => $canSeeStaff ? Ui::options($staffOptions, fn (User $u) => $u->name.($u->employee_code ? ' ('.$u->employee_code.')' : '')) : [],
            'month' => $month,
            'monthTo' => $monthTo,
            'periodLabel' => 'Tháng '.$from->format('m/Y').($monthTo !== $month ? ' - Tháng '.$to->format('m/Y') : '').' ('.$from->format('d/m/Y').' - '.$to->format('d/m/Y').')',
            'canSeeStaff' => $canSeeStaff,
        ]);
    }
}
