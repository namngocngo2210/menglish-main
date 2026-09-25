<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\HrDailyDemand;
use App\Models\Student;
use App\Models\SupportSession;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\ClassDashboardService;
use App\Services\KpiBoardService;
use App\Services\SafeUploadService;
use App\Services\SessionScheduleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkTaskController extends Controller
{
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
        $defaultTab = ($currentUser && $currentUser->hasRole('admin')) ? 'all' : 'mine';
        $tab = $request->get('tab', $defaultTab); // 'mine', 'assigned', 'all'
        if (! in_array($tab, ['mine', 'assigned', 'all'], true) || ($tab === 'all' && ! $canViewAll)) {
            $tab = 'mine';
        }
        $status = $request->get('status', 'all');
        $taskType = $request->get('task_type', 'all');
        $search = $request->get('q', '');

        $query = WorkTask::with(['creator', 'assignee', 'branch', 'classModel']);
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

        $users = $this->assignableUsers($currentUser);
        $branches = Branch::where('is_active', true)->get();
        $classes = ClassModel::query()->visibleTo($currentUser)->where('status', 'active')->get();

        $visible = fn () => $this->scopeVisibleTasks(WorkTask::query(), $currentUser);
        $counts = [
            'all' => $visible()->count(),
            'mine' => WorkTask::where('assignee_id', $currentUserId)->count(),
            'assigned' => WorkTask::where('creator_id', $currentUserId)->count(),
            'overdue' => $visible()->where('status', 'overdue')->count(),
            'pending' => $visible()->where('status', 'pending_confirmation')->count(),
        ];

        return view('tasks.index', compact('tasks', 'tab', 'status', 'taskType', 'search', 'users', 'branches', 'classes', 'counts', 'canViewAll'));
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

        return view('tasks.create', compact('users', 'branches', 'classes'));
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

        return redirect()->route('tasks.index')->with('success', "Đã giao việc '{$task->title}' thành công cho nhân sự!");
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

            return redirect()->back()->withErrors(['status' => $message]);
        }

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

        return redirect()->back()->with('success', 'Đã cập nhật trạng thái công việc thành công!');
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
        ]);
        $viewer = $request->user();
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
        $dayStats = [
            'total' => $daySessions->where('status', '!=', 'cancelled')->count(),
            'done' => $daySessions->filter(fn ($s) => $s->status !== 'cancelled' && $s->attendances_count > 0)->count(),
            'missing' => $daySessions->filter(fn ($s) => $dashboard->attendanceState($s, $today)['key'] === 'missing')->count(),
            'cancelled' => $daySessions->where('status', 'cancelled')->count(),
        ];
        $seats = $daySessions->pluck('classModel')->filter()->unique('id')
            ->mapWithKeys(fn (ClassModel $class) => [$class->id => $class->occupiedSeats()]);
        $assistantsToday = $dashboard->assistantsOnDuty($daySessions);

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

            return \App\Exports\ArrayExport::download(
                'lich-lop-'.($tab === 'week' ? $week : $date),
                ['Ngày', 'Giờ', 'Mã lớp', 'Tên lớp', 'Chi nhánh', 'Phòng', 'Giáo viên', 'GVNN', 'Trợ giảng', 'Điểm danh'],
                $rows,
                $request->query('format', 'xlsx')
            );
        }

        return view('tasks.classes-dashboard', compact(
            'tab', 'branches', 'branchId', 'selectedBranch', 'date', 'week', 'today',
            'daySessions', 'dayStats', 'seats', 'assistantsToday', 'weekStart', 'matrix', 'dashboard'
        ));
    }

    /**
     * 4. Tạo lượt giao việc cho Trợ giảng (Batch assign form)
     */
    public function taAssignForm(Request $request)
    {
        $assistants = User::where('is_active', true)
            ->where(function ($q) {
                $q->whereHas('roles', function ($rq) {
                    $rq->whereIn('name', ['assistant', 'academic_staff', 'teacher']);
                })->orWhere('email', 'like', 'ta.%');
            })
            ->orderBy('name')
            ->get();

        $branches = Branch::where('is_active', true)->get();
        $classes = ClassModel::where('status', 'active')->get();

        return view('tasks.ta-assign', compact('assistants', 'branches', 'classes'));
    }

    public function taAssignStore(Request $request)
    {
        $validated = $request->validate([
            'assistant_id' => 'required|exists:users,id',
            'assign_date' => 'required|date',
            'branch_id' => 'nullable|exists:branches,id',
            'tasks' => 'required|array|min:1',
            'tasks.*.category' => 'required|in:before,during,after',
            'tasks.*.content' => 'required|string|max:500',
            'tasks.*.attach_class' => 'nullable',
            'tasks.*.class_id' => 'nullable|exists:classes,id',
            'tasks.*.session' => 'nullable|string|max:255',
        ]);

        $createdCount = 0;
        $lastTask = null;
        foreach ($validated['tasks'] as $item) {
            $hasAttachClass = isset($item['attach_class']) && ($item['attach_class'] == '1' || $item['attach_class'] == 'on');

            $lastTask = WorkTask::create([
                'title' => $item['content'],
                'description' => 'Nhiệm vụ trực ca '.($item['category'] === 'before' ? 'Trước giờ học' : ($item['category'] === 'during' ? 'Trong giờ học' : 'Sau giờ học')),
                'creator_id' => Auth::id() ?? 1,
                'assignee_id' => $validated['assistant_id'],
                'branch_id' => $validated['branch_id'] ?? null,
                'class_id' => $hasAttachClass ? ($item['class_id'] ?? null) : null,
                'lesson_session' => $hasAttachClass ? ($item['session'] ?? null) : null,
                'time_slot_category' => $item['category'],
                'task_type' => 'one_time',
                'due_date' => $validated['assign_date'],
                'due_time' => $item['category'] === 'before' ? '14:00' : ($item['category'] === 'during' ? '18:00' : '21:30'),
                'status' => 'new',
            ]);
            $createdCount++;
        }

        if ($lastTask) {
            $this->notifyAssignee($lastTask, $createdCount);
        }

        return redirect()->route('tasks.index')->with('success', "Đã tạo thành công {$createdCount} nhiệm vụ cho Trợ giảng!");
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
        $assistants = $canPickTa
            ? User::role('assistant')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])
            : collect();

        if ($canPickTa) {
            $taUser = isset($validated['ta_id'])
                ? User::find($validated['ta_id'])
                : ($viewer->hasRole('assistant') ? $viewer : $assistants->first());
        } else {
            $taUser = $viewer;
        }

        $tasks = collect();
        $sessions = collect();
        $overdueCount = 0;
        if ($taUser) {
            $tasks = WorkTask::with(['classModel:id,name,code', 'branch:id,name'])
                ->where('assignee_id', $taUser->id)
                ->whereDate('due_date', $date->toDateString())
                ->orderBy('due_time')
                ->orderBy('id')
                ->get();
            $overdueCount = WorkTask::where('assignee_id', $taUser->id)
                ->whereDate('due_date', '<', $date->toDateString())
                ->whereNotIn('status', ['completed', 'pending_confirmation'])
                ->count();
            $sessions = ClassSession::with(['classModel:id,name,code', 'branch:id,name'])
                ->forStaff($taUser->id)
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

        return view('tasks.ta-portal', compact(
            'taUser', 'tasks', 'beforeTasks', 'duringTasks', 'afterTasks', 'sessions',
            'date', 'isToday', 'canPickTa', 'assistants', 'overdueCount', 'canComplete'
        ));
    }

    /**
     * Cập nhật tiến độ hoàn thành từ TA Portal (Hoàn thành / Hoàn thành gấp)
     */
    public function completeTask(Request $request, $id)
    {
        $task = WorkTask::findOrFail($id);
        abort_unless((int) $task->assignee_id === (int) Auth::id() || $request->user()->can('work_task.approve'), 403);
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
            $msg = "Đã gửi báo cáo tiến độ. Do không đính kèm ảnh, nhiệm vụ chuyển sang 'Chờ người giao việc xác nhận'!";
        }

        return redirect()->back()->with('success', $msg);
    }

    /**
     * 6. Nộp báo cáo trực lớp TA (Class Report Form & Store)
     */
    public function createClassReport(Request $request)
    {
        $classes = ClassModel::where('status', 'active')->get();
        $classId = $request->get('class_id', $classes->first()?->id);
        $taskId = $request->get('task_id');

        $selectedClass = ClassModel::with('students')->find($classId) ?? $classes->first();
        $students = $selectedClass?->students ?? Student::all();

        return view('tasks.class-report-create', compact('classes', 'selectedClass', 'students', 'taskId'));
    }

    public function storeClassReport(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'session_name' => 'required|string|max:255',
            'hom_nay_hoc_gi' => 'required|string',
            'nhat_ky_day' => 'nullable|string',
            'task_id' => 'nullable|exists:work_tasks,id',
            'supports' => 'nullable|array',
            'supports.*.student_id' => 'nullable|exists:students,id',
            'supports.*.absence_session' => 'nullable|string',
            'supports.*.reason' => 'nullable|string',
            'supports.*.action_plan' => 'nullable|string',
            'board_image' => 'nullable|file|max:10240|mimes:'.implode(',', SafeUploadService::IMAGES),
            'board_image_url' => 'nullable|url:http,https|max:2048',
        ]);

        $class = ClassModel::findOrFail($validated['class_id']);
        abort_unless(
            $request->user()->can('work_task.approve')
            || in_array((int) Auth::id(), array_map('intval', [$class->teacher_id, $class->assistant_id, $class->foreign_teacher_id]), true),
            403
        );

        $hasImage = $request->hasFile('board_image') || ! empty($request->input('board_image_url'));
        $imagePath = null;
        if ($request->hasFile('board_image')) {
            $imagePath = SafeUploadService::store($request->file('board_image'), 'class_reports', SafeUploadService::IMAGES, 'board_image');
        } elseif ($request->filled('board_image_url')) {
            $imagePath = $request->input('board_image_url');
        }

        // Quy tắc: Có ảnh đính kèm -> hoàn thành ngay (approved); Không có ảnh -> chờ GV chính xác nhận (pending_approval)
        $status = $hasImage ? 'approved' : 'pending_approval';

        $report = ClassReport::create([
            'task_id' => $validated['task_id'] ?? null,
            'class_id' => $validated['class_id'],
            'reporter_id' => Auth::id() ?? 1,
            'session_name' => $validated['session_name'],
            'session_date' => now()->toDateString(),
            'topics_learned' => $validated['hom_nay_hoc_gi'],
            'teaching_log' => $validated['nhat_ky_day'] ?? null,
            'board_image' => $imagePath,
            'has_image' => $hasImage,
            'status' => $status,
            'approved_at' => $hasImage ? now() : null,
            'approved_by' => $hasImage ? Auth::id() : null,
        ]);

        // Lưu danh sách học sinh cần bổ trợ
        if (! empty($validated['supports'])) {
            foreach ($validated['supports'] as $supp) {
                if (! empty($supp['student_id']) && ! empty($supp['reason'])) {
                    ClassReportStudentSupport::create([
                        'class_report_id' => $report->id,
                        'student_id' => $supp['student_id'],
                        'absence_session' => $supp['absence_session'] ?? null,
                        'reason' => $supp['reason'],
                        'action_plan' => $supp['action_plan'] ?? null,
                    ]);
                }
            }
        }

        // Cập nhật work task liên quan nếu có
        if (! empty($validated['task_id'])) {
            $task = WorkTask::find($validated['task_id']);
            if ($task) {
                $task->update([
                    'status' => $hasImage ? 'completed' : 'pending_confirmation',
                    'completed_at' => $hasImage ? now() : null,
                    'completion_proof_image' => $imagePath,
                    'completion_note' => 'Báo cáo trực lớp: '.$validated['hom_nay_hoc_gi'],
                ]);
            }
        }

        if (! $hasImage) {
            $this->notifyClassReportReviewers($report, $class);
        }

        $msg = $hasImage
            ? 'Đã nộp báo cáo trực lớp thành công kèm hình ảnh minh chứng!'
            : 'Đã nộp báo cáo trực lớp (Không có ảnh, hệ thống đang chờ GV chính xác nhận)!';

        return redirect()->route('portal.ta-tasks')->with('success', $msg);
    }

    /**
     * 7. Xác nhận hoàn thành thủ công (Manual Task Approvals)
     */
    public function manualApprovals(Request $request)
    {
        $selectedId = $request->get('selected_id');

        $user = $request->user();

        // Người có quyền duyệt thấy việc chờ xác nhận trong phạm vi; người khác
        // chỉ thấy việc do chính mình giao. Không ai duyệt việc của chính mình.
        $pendingQuery = WorkTask::with(['assignee', 'creator', 'classModel', 'classReport'])
            ->where('status', 'pending_confirmation')
            ->where(fn ($q) => $q->whereNull('assignee_id')->orWhere('assignee_id', '!=', $user->id));
        if ($user->can('work_task.approve')) {
            $this->scopeVisibleTasks($pendingQuery, $user);
        } else {
            $pendingQuery->where('creator_id', $user->id);
        }
        $pendingTasks = $pendingQuery->latest()->get();

        $selectedTask = $selectedId
            ? $pendingTasks->firstWhere('id', $selectedId)
            : $pendingTasks->first();

        $pendingReports = ClassReport::with(['classModel', 'reporter', 'task'])
            ->where('status', 'pending_approval')
            ->latest()
            ->get()
            ->filter(fn (ClassReport $report) => $this->canReviewClassReport($report, $user))
            ->values();

        return view('tasks.manual-approvals', compact('pendingTasks', 'selectedTask', 'pendingReports'));
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
        abort_unless($this->canReviewClassReport($report, $request->user()), 403, 'Bạn không có quyền duyệt báo cáo này.');
        abort_unless($report->status === 'pending_approval', 422, 'Báo cáo không ở trạng thái chờ duyệt.');

        DB::transaction(function () use ($report, $request) {
            $report->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);

            if ($report->task && $report->task->status === 'pending_confirmation') {
                $report->task->update([
                    'status' => 'completed',
                    'confirmed_by' => $request->user()->id,
                    'confirmed_at' => now(),
                    'completed_at' => now(),
                ]);
            }
        });

        $this->notifyUser($report->reporter_id, 'class_report_pending', 'Báo cáo trực lớp đã được duyệt',
            "{$request->user()->name} đã duyệt báo cáo trực lớp {$report->session_name}.", route('portal.ta-tasks'));

        return back()->with('success', 'Đã duyệt báo cáo trực lớp.');
    }

    public function rejectClassReport(Request $request, int $id)
    {
        $validated = $request->validate(['reason' => 'required|string|max:1000']);
        $report = ClassReport::with(['classModel', 'task'])->findOrFail($id);
        abort_unless($this->canReviewClassReport($report, $request->user()), 403, 'Bạn không có quyền duyệt báo cáo này.');
        abort_unless($report->status === 'pending_approval', 422, 'Báo cáo không ở trạng thái chờ duyệt.');

        DB::transaction(function () use ($report, $validated) {
            $report->update([
                'status' => 'rejected',
                'rejection_reason' => $validated['reason'],
            ]);

            if ($report->task && $report->task->status === 'pending_confirmation') {
                $report->task->update([
                    'status' => 'in_progress',
                    'rejection_reason' => $validated['reason'],
                ]);
            }
        });

        $this->notifyUser($report->reporter_id, 'class_report_pending', 'Báo cáo trực lớp bị trả về',
            "{$request->user()->name}: {$validated['reason']}", route('portal.ta-tasks'));

        return back()->with('info', 'Đã trả báo cáo trực lớp về cho người nộp.');
    }

    /**
     * Giới hạn danh sách công việc được xem:
     *  - Có quyền duyệt (Admin/Quản lý/Học vụ/Học thuật): tất cả; Quản lý cơ sở
     *    chỉ việc thuộc chi nhánh mình (hoặc việc mình tạo/được giao).
     *  - Người khác: chỉ việc mình tạo hoặc được giao.
     */
    private function scopeVisibleTasks($query, User $user)
    {
        if ($user->can('work_task.approve')) {
            $managed = $user->managedBranchIds();
            if ($managed !== null) {
                $query->where(function ($q) use ($user, $managed) {
                    $q->whereIn('branch_id', $managed)
                        ->orWhereHas('assignee', fn ($a) => $a->whereIn('branch_id', $managed))
                        ->orWhere('creator_id', $user->id)
                        ->orWhere('assignee_id', $user->id);
                });
            }

            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('creator_id', $user->id)->orWhere('assignee_id', $user->id);
        });
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
            $query->whereHas('roles', fn ($r) => $r->whereIn('name', self::REQUEST_TARGET_ROLES));
        } elseif (($managed = $user->managedBranchIds()) !== null) {
            $query->where(fn ($q) => $q->whereIn('branch_id', $managed)->orWhere('id', $user->id));
        }

        return $query->get();
    }

    public const REQUEST_TARGET_ROLES = ['admin', 'manager', 'academic_staff', 'academic_lead'];

    private function ensureCanCreateTask(): void
    {
        $user = Auth::user();
        abort_unless($user && ($user->can('work_task.create') || $user->can('work_task.request')), 403, 'Bạn không có quyền giao việc.');
    }

    private function canReviewClassReport(ClassReport $report, User $user): bool
    {
        if ((int) $report->reporter_id === (int) $user->id) {
            return false;
        }

        if ($report->classModel && (int) $report->classModel->teacher_id === (int) $user->id) {
            return true;
        }

        if (! $user->can('work_task.approve')) {
            return false;
        }

        $managed = $user->managedBranchIds();

        return $managed === null || in_array((int) $report->classModel?->branch_id, $managed, true);
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

    private function notifyClassReportReviewers(ClassReport $report, ClassModel $class): void
    {
        $recipientIds = collect([$class->teacher_id])->filter()
            ->reject(fn ($id) => (int) $id === (int) $report->reporter_id)
            ->unique();

        // Lớp chưa có GV chính: báo cho người có quyền duyệt công việc cùng chi nhánh.
        if ($recipientIds->isEmpty()) {
            $recipientIds = User::permission('work_task.approve')
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('branch_id', $class->branch_id)->orWhereHas('roles', fn ($r) => $r->where('name', 'admin')))
                ->pluck('id')
                ->reject(fn ($id) => (int) $id === (int) $report->reporter_id);
        }

        foreach ($recipientIds as $userId) {
            $this->notifyUser($userId, 'class_report_pending', 'Báo cáo trực lớp chờ duyệt',
                "Báo cáo {$report->session_name} lớp {$class->name} chưa có ảnh bảng, cần xác nhận.",
                route('tasks.manual-approvals'), ['class_report_id' => $report->id]);
        }
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
        $this->ensureCanApprove($task, $request->user());
        $adminNote = $request->input('admin_note', 'Yêu cầu bổ sung hình ảnh hoặc tài liệu minh chứng.');

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
        ]);
        $viewer = $request->user();

        // Chỉ lớp người xem phụ trách (nhân sự quản lý lớp thấy tất cả).
        $classes = ClassModel::visibleTo($viewer)
            ->with(['teacher:id,name', 'assistant:id,name', 'foreignTeacher:id,name', 'branch:id,name', 'scheduleConfig'])
            ->orderBy('name')
            ->get();
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

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

        $report = collect(range(0, 6))->map(function (int $offset) use ($reportStart, $weekSessions, $savedDemands) {
            $day = $reportStart->addDays($offset);
            $sessions = $weekSessions->filter(fn (ClassSession $s) => $s->date->isSameDay($day));
            $assistants = $sessions->pluck('assistant_id')->filter()->unique()->count();

            return [
                'date' => $day,
                'label' => ClassDashboardService::WEEKDAYS[$day->isoWeekday()],
                'shifts' => $sessions->count(),
                'rooms' => $sessions->pluck('room')->filter()->unique()->count(),
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

            return \App\Exports\ArrayExport::download(
                'bao-cao-phong-nhan-su-'.$reportStart->format('Ymd'),
                ['Ngày', 'Chi nhánh', 'Số ca', 'Số phòng', 'Số GV/GVNN', 'Số trợ giảng', 'Nhu cầu nhân sự'],
                $rows,
                $request->query('format', 'xlsx')
            );
        }

        return view('tasks.schedule-config', compact(
            'classes', 'branches', 'scheduleData', 'selectedClassId',
            'reportBranchId', 'reportStart', 'reportEnd', 'report', 'classCountChange', 'holidaySessions'
        ));
    }

    public function updateScheduleConfig(Request $request, SessionScheduleService $schedule)
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
            $cls->status = $cls->status === 'active' ? 'completed' : 'active';
            $cls->save();

            return redirect()->back()->with('success', "Đã cập nhật trạng thái lớp {$cls->name}!");
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

        $conflict = $schedule->findConflict(
            array_map(fn (array $session) => $session + ['room' => $class->room], $sessions),
            $class->branch_id,
            [$class->teacher_id, $class->assistant_id, $class->foreign_teacher_id],
            $class->room,
            $class->id,
        );
        if ($conflict) {
            [$session, $existing] = $conflict;
            throw ValidationException::withMessages([
                'class_id' => "Xung đột {$session['date']} {$session['start']}-{$session['end']} với lớp {$existing->classModel?->name} ({$existing->classModel?->code}).",
            ]);
        }

        // Lớp chưa xếp lịch được kích hoạt khi có TKB; lớp "sắp khai giảng" chỉ kích hoạt khi người dùng
        // chọn rõ; lớp đã kết thúc/đã hủy giữ nguyên trạng thái.
        $status = match (true) {
            $class->status === 'pending_schedule' => 'active',
            $class->status === 'upcoming' && ($payload['activate'] ?? false) => 'active',
            default => $class->status,
        };

        DB::transaction(function () use ($class, $payload, $startDate, $endDate, $slots, $sessions, $replaceableIds, $status) {
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

            foreach ($sessions as $session) {
                ClassSession::create([
                    'class_id' => $class->id,
                    'branch_id' => $class->branch_id,
                    'date' => $session['date'],
                    'shift_name' => $session['name'],
                    'type' => ClassSession::TYPE_REGULAR,
                    'start_time' => $session['start'],
                    'end_time' => $session['end'],
                    'room' => $class->room,
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

    public function supportSessions()
    {
        $pendingSupports = ClassReportStudentSupport::with(['student', 'classReport.classModel'])
            ->whereDoesntHave('supportSession')->latest()->get();
        $sessions = SupportSession::with(['student', 'classModel', 'teacher'])->latest('session_date')->get();
        $classes = ClassModel::with('students')->where('status', 'active')->get();
        // Theo flow BA, buổi bổ trợ do CM/Học vụ hoặc TA đảm nhận (không nhất thiết GV chính),
        // nên picker bao gồm cả trợ giảng và học vụ bên cạnh các vai trò giáo viên.
        $teachers = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['teacher', 'teacher_fulltime', 'teacher_parttime', 'academic_lead', 'academic_staff', 'assistant']))->get();

        return view('tasks.support-sessions', compact('pendingSupports', 'sessions', 'classes', 'teachers'));
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
        abort_unless($class->students()->whereKey($validated['student_id'])->exists(), 422, 'Học viên không thuộc lớp đã chọn.');

        $conflict = ClassSession::whereDate('date', $validated['session_date'])
            ->where('start_time', '<', $validated['end_time'])->where('end_time', '>', $validated['start_time'])
            ->where(function ($query) use ($validated, $class) {
                $query->where('teacher_id', $validated['teacher_id']);
                if (! empty($validated['room'])) {
                    $query->orWhere(fn ($room) => $room->where('branch_id', $class->branch_id)->where('room', $validated['room']));
                }
            })->exists();
        abort_if($conflict, 422, 'Giáo viên hoặc phòng học bị trùng lịch phụ đạo.');

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

        return redirect()->back()->with('success', 'Đã xếp lịch phụ đạo và giữ chỗ giáo viên/phòng học.');
    }

    public function completeSupportSession(Request $request, int $id)
    {
        $session = SupportSession::with('classSession')->findOrFail($id);
        abort_unless((int) $session->teacher_id === (int) Auth::id() || $request->user()->can('work_task.approve'), 403);
        abort_if($session->status === 'completed', 422, 'Buổi phụ đạo đã hoàn thành.');
        $validated = $request->validate(['completion_note' => ['nullable', 'string', 'max:2000']]);
        if (\App\Models\PayrollPeriod::isLockedFor($session->session_date)) {
            $message = \App\Models\PayrollPeriod::lockedMessage($session->session_date);

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
            'user_id' => ['nullable', 'integer'],
        ]);
        $month = $validated['month'] ?? now()->format('Y-m');
        $from = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $to = $from->endOfMonth();

        $staffOptions = $kpi->staffQuery()->get(['id', 'name']);

        // Xuất Excel toàn bộ nhân sự theo bộ lọc hiện tại (không phân trang).
        if ($request->boolean('export')) {
            $fmt = fn ($v) => $v === null ? 'Chưa có dữ liệu' : $v.'%';
            $rows = $kpi->staffQuery()
                ->when($validated['user_id'] ?? null, fn ($q, $userId) => $q->whereKey($userId))
                ->get()
                ->map(function (User $user) use ($kpi, $from, $to, $fmt) {
                    $m = $kpi->metricsFor($user, $from, $to);

                    return [
                        'NS-'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
                        $user->name,
                        $m['classes'] ?? 0,
                        $fmt($m['attendance'] ?? null), $m['attendance_detail'] ?? '',
                        $fmt($m['homework'] ?? null), $m['homework_detail'] ?? '',
                        $fmt($m['tasks'] ?? null), $m['tasks_detail'] ?? '',
                        $fmt($m['retention'] ?? null), $m['retention_detail'] ?? '',
                    ];
                })->all();

            return \App\Exports\ArrayExport::download(
                'kpi-'.$month,
                ['Mã NS', 'Nhân sự', 'Số lớp', 'Chuyên cần', 'Chi tiết chuyên cần', 'Bài tập', 'Chi tiết bài tập', 'Công việc', 'Chi tiết công việc', 'Giữ chân', 'Chi tiết giữ chân'],
                $rows,
                $request->query('format', 'xlsx')
            );
        }
        $staff = $kpi->staffQuery()
            ->when($validated['user_id'] ?? null, fn ($q, $userId) => $q->whereKey($userId))
            ->paginate($request->perPage(20))
            ->withQueryString();

        $kpiData = $staff->getCollection()->map(fn (User $user) => [
            'user' => $user,
            'code' => 'NS-'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
        ] + $kpi->metricsFor($user, $from, $to));

        return view('tasks.kpi-dashboard', compact('staff', 'staffOptions', 'kpiData', 'month', 'from', 'to'));
    }
}
