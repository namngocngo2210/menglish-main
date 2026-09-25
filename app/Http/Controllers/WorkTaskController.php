<?php

namespace App\Http\Controllers;

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
use App\Services\SessionScheduleService;
use Carbon\Carbon;
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
        $defaultTab = ($currentUser && $currentUser->hasRole('admin')) ? 'all' : 'mine';
        $tab = $request->get('tab', $defaultTab); // 'mine', 'assigned', 'all'
        $status = $request->get('status', 'all');
        $taskType = $request->get('task_type', 'all');
        $search = $request->get('q', '');

        $query = WorkTask::with(['creator', 'assignee', 'branch', 'classModel']);

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

        $users = User::where('is_active', true)->orderBy('name')->get();
        $branches = Branch::where('is_active', true)->get();
        $classes = ClassModel::where('status', 'active')->get();

        $counts = [
            'all' => WorkTask::count(),
            'mine' => WorkTask::where('assignee_id', $currentUserId)->count(),
            'assigned' => WorkTask::where('creator_id', $currentUserId)->count(),
            'overdue' => WorkTask::where('status', 'overdue')->count(),
            'pending' => WorkTask::where('status', 'pending_confirmation')->count(),
        ];

        return view('tasks.index', compact('tasks', 'tab', 'status', 'taskType', 'search', 'users', 'branches', 'classes', 'counts'));
    }

    /**
     * 2. Form Giao việc mới (Create & Store)
     */
    public function create()
    {
        $users = User::where('is_active', true)->orderBy('name')->get();
        $branches = Branch::where('is_active', true)->get();
        $classes = ClassModel::where('status', 'active')->get();

        return view('tasks.create', compact('users', 'branches', 'classes'));
    }

    public function store(Request $request)
    {
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

        return redirect()->route('tasks.index')->with('success', "Đã giao việc '{$task->title}' thành công cho nhân sự!");
    }

    /**
     * Cập nhật trạng thái công việc (Modal status change)
     */
    public function updateStatus(Request $request, $id)
    {
        $task = WorkTask::findOrFail($id);
        $status = $request->input('status');
        $reason = $request->input('reason');
        $note = $request->input('note');

        $updateData = ['status' => $status];

        if ($status === 'blocked') {
            $updateData['blocked_reason'] = $reason ?? $note;
        } elseif ($status === 'completed') {
            $updateData['completed_at'] = now();
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
    public function classesDashboard(Request $request)
    {
        $branchId = $request->get('branch_id');
        $date = $request->get('date', now()->format('Y-m-d'));
        $week = $request->get('week', now()->format('Y-\WW'));

        $branches = Branch::where('is_active', true)->get();
        $selectedBranch = $branchId ? Branch::find($branchId) : $branches->first();

        // Danh sách lớp hôm nay
        $classes = ClassModel::with(['course', 'teacher', 'assistant', 'branch'])
            ->where('status', 'active')
            ->get();

        // Danh sách trợ giảng làm việc hôm nay
        $assistantsToday = User::role('assistant')
            ->where('is_active', true)
            ->get();

        if ($assistantsToday->isEmpty()) {
            $assistantsToday = User::where('email', 'like', 'ta.%')
                ->orWhere('name', 'like', '%Trợ giảng%')
                ->orWhere('name', 'like', '%Anh Tuấn%')
                ->orWhere('name', 'like', '%Ngọc Trâm%')
                ->orWhere('name', 'like', '%Hải Yến%')
                ->get();
        }

        return view('tasks.classes-dashboard', compact('branches', 'selectedBranch', 'classes', 'assistantsToday', 'date', 'week'));
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
        foreach ($validated['tasks'] as $item) {
            $hasAttachClass = isset($item['attach_class']) && ($item['attach_class'] == '1' || $item['attach_class'] == 'on');

            WorkTask::create([
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

        return redirect()->route('tasks.index')->with('success', "Đã tạo thành công {$createdCount} nhiệm vụ cho Trợ giảng!");
    }

    /**
     * 5. Nhiệm vụ hôm nay (TA Portal view)
     */
    public function taPortal(Request $request)
    {
        $taUser = Auth::user();

        // Nếu là admin test, lấy user TA mẫu đầu tiên hoặc user hiện tại
        if (! $taUser || $taUser->hasRole('admin')) {
            $taUser = User::where('email', 'ta.tuan@menglish.edu.vn')->first() ?? Auth::user() ?? User::first();
        }

        $today = now()->toDateString();

        $tasks = WorkTask::with(['classModel', 'branch'])
            ->where('assignee_id', $taUser->id)
            ->whereDate('due_date', '<=', $today)
            ->orderBy('id', 'desc')
            ->get();

        // Nếu chưa có task nào của TA này hôm nay, fallback lấy toàn bộ task của TA
        if ($tasks->isEmpty()) {
            $tasks = WorkTask::with(['classModel', 'branch'])
                ->where('assignee_id', $taUser->id)
                ->get();
        }

        $beforeTasks = $tasks->where('time_slot_category', 'before');
        $duringTasks = $tasks->where('time_slot_category', 'during');
        $afterTasks = $tasks->where('time_slot_category', 'after');

        return view('tasks.ta-portal', compact('taUser', 'beforeTasks', 'duringTasks', 'afterTasks', 'tasks'));
    }

    /**
     * Cập nhật tiến độ hoàn thành từ TA Portal (Hoàn thành / Hoàn thành gấp)
     */
    public function completeTask(Request $request, $id)
    {
        $task = WorkTask::findOrFail($id);
        abort_unless((int) $task->assignee_id === (int) Auth::id() || $request->user()->can('work_task.approve'), 403);
        $note = $request->input('note');
        $hasImage = $request->hasFile('proof_image') || ! empty($request->input('proof_image_url'));

        $imagePath = null;
        if ($request->hasFile('proof_image')) {
            $imagePath = $request->file('proof_image')->store('task_proofs', 'public');
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
            $imagePath = $request->file('board_image')->store('class_reports', 'public');
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

        $pendingTasks = WorkTask::with(['assignee', 'creator', 'classModel', 'classReport'])
            ->where('status', 'pending_confirmation')
            ->latest()
            ->get();

        $selectedTask = $selectedId
            ? $pendingTasks->firstWhere('id', $selectedId)
            : $pendingTasks->first();

        return view('tasks.manual-approvals', compact('pendingTasks', 'selectedTask'));
    }

    public function approveTask(Request $request, $id)
    {
        $task = WorkTask::findOrFail($id);
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
        $adminNote = $request->input('admin_note', 'Yêu cầu bổ sung hình ảnh hoặc tài liệu minh chứng.');

        $task->update([
            'status' => 'in_progress',
            'rejection_reason' => $adminNote,
        ]);

        return redirect()->route('tasks.manual-approvals')->with('info', "Đã từ chối/yêu cầu bổ sung cho công việc '{$task->title}'!");
    }

    /**
     * 8. TKB & Cấu hình lịch lặp báo cáo phòng nhân sự
     */
    public function scheduleConfig(Request $request)
    {
        $classes = ClassModel::with(['teacher', 'assistant', 'branch', 'scheduleConfig'])->get();
        $branches = Branch::where('is_active', true)->get();

        // Dữ liệu nhu cầu nhân sự chỉ từ seeder/nhập liệu thật; trước đây trang GET
        // tự cấy 7 dòng số liệu giả (shifts/staff hardcode) vào DB khi bảng trống.
        $demands = HrDailyDemand::orderBy('report_date')->get();

        return view('tasks.schedule-config', compact('classes', 'branches', 'demands'));
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
        $demands = $request->input('demands', []);
        foreach ($demands as $id => $val) {
            HrDailyDemand::where('id', $id)->update(['staff_needed' => intval($val)]);
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
    public function kpiDashboard(Request $request)
    {
        $teachers = User::where('is_active', true)
            ->where(function ($q) {
                $q->whereHas('roles', function ($rq) {
                    $rq->whereIn('name', ['teacher', 'assistant', 'academic_staff']);
                })->orWhere('email', 'like', 'teacher%')
                    ->orWhere('email', 'like', 'ta%');
            })
            ->get();

        if ($teachers->isEmpty()) {
            $teachers = User::where('is_active', true)->take(6)->get();
        }

        $kpiData = $teachers->map(function ($u, $idx) {
            $assignedClasses = ClassModel::where('teacher_id', $u->id)->orWhere('assistant_id', $u->id)->get();
            $totalStudents = Student::whereIn('current_class_id', $assignedClasses->pluck('id'))->count();
            $activeStudents = Student::whereIn('current_class_id', $assignedClasses->pluck('id'))->where('status', 'studying')->count();
            $retentionRate = $totalStudents > 0 ? round(($activeStudents / $totalStudents) * 100, 1).'%' : '100.0%';

            $totalTasks = WorkTask::where('assignee_id', $u->id)->count();
            $completedTasks = WorkTask::where('assignee_id', $u->id)->where('status', 'completed')->count();
            $taskRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1).'%' : '100.0%';

            $colors = ['bg-orange-600', 'bg-blue-600', 'bg-emerald-600', 'bg-purple-600', 'bg-indigo-600'];
            $color = $colors[$idx % count($colors)].' text-white';

            return [
                'code' => 'GV-'.str_pad($u->id, 3, '0', STR_PAD_LEFT),
                'name' => $u->name,
                'initial' => Str::substr($u->name, 0, 2),
                'bg_color' => $color,
                'retention_rate' => $retentionRate,
                'attendance_rate' => '96.8%',
                'homework_rate' => $taskRate,
            ];
        });

        return view('tasks.kpi-dashboard', compact('teachers', 'kpiData'));
    }
}
