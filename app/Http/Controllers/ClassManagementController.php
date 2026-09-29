<?php

namespace App\Http\Controllers;

use App\Support\DataScope;
use App\Support\Rbac;
use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\CrmCustomer;
use App\Models\CrmTrialBooking;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\ClassDashboardService;
use App\Services\SessionScheduleService;
use App\Support\ClassLifecycle;
use App\Models\StaffReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClassManagementController extends Controller
{
    public function __construct(private readonly SessionScheduleService $schedule) {}

    /**
     * "Lịch học thử": các buổi học thử đặt từ hồ sơ khách CRM (crm_trial_bookings), trong phạm vi khách user được xem.
     * Đặt / hủy học thử làm trong hồ sơ khách (gắn lead, giáo viên buổi đó thấy và nhận xét).
     */
    public function trialBooking(Request $request)
    {
        $this->ensureCanBrowseClasses();
        $scope = $request->input('scope') === 'past' ? 'past' : 'upcoming';
        $status = array_key_exists((string) $request->input('status'), CrmTrialBooking::STATUSES) ? $request->input('status') : null;

        $bookings = CrmTrialBooking::query()
            ->with(['customer:id,code,name,parent_name,phone,test_score,stage,assigned_user_id', 'customer.assignedUser:id,name', 'session', 'classModel.course', 'classModel.branch', 'feedbackBy:id,name', 'bookedBy:id,name'])
            ->whereIn('crm_trial_bookings.customer_id', CrmCustomer::query()->visibleTo($request->user())->select('id'))
            ->when($status, fn ($query) => $query->where('crm_trial_bookings.status', $status))
            ->join('class_sessions', 'class_sessions.id', '=', 'crm_trial_bookings.class_session_id')
            ->when($scope === 'past',
                fn ($query) => $query->whereDate('class_sessions.date', '<', today())->orderByDesc('class_sessions.date')->orderByDesc('class_sessions.start_time'),
                fn ($query) => $query->whereDate('class_sessions.date', '>=', today())->orderBy('class_sessions.date')->orderBy('class_sessions.start_time'))
            ->select('crm_trial_bookings.*')
            ->paginate($request->perPage(20))
            ->withQueryString();

        return view('classes.trial-booking', compact('bookings', 'scope', 'status'));
    }

    /**
     * Flow 1 - Bước #2: Tạo lớp mới
     * Khớp 100% UI: 01_Web_Admin/13_tao_lop_moi
     */
    public function create(Request $request)
    {
        abort_if(! auth()->user()->can('class.create'), 403, 'Bạn không có quyền tạo lớp học.');

        $branches = Branch::where('is_active', true)->get();
        $courses = Course::where('is_active', true)->get();
        $levels = CourseLevel::where('is_active', true)->get();

        [$teachers, $assistants] = $this->teachingStaffOptions();
        $foreignTeachers = $teachers;

        return view('classes.create', compact('branches', 'courses', 'levels', 'teachers', 'assistants', 'foreignTeachers'));
    }

    /**
     * Lưu lớp học mới. Nếu form đã render thời khóa biểu (schedule_sessions_json),
     * hệ thống tạo luôn các buổi học thực tế, ghi ngày khai giảng và kích hoạt lớp.
     */
    private const CAPACITY_MESSAGES = [
        'min_students.lte' => 'Ngưỡng khai giảng không được lớn hơn sĩ số tối đa.',
        'min_students.min' => 'Ngưỡng khai giảng phải từ 1 học viên.',
    ];

    public function store(Request $request)
    {
        abort_if(! auth()->user()->can('class.create'), 403, 'Bạn không có quyền tạo lớp học.');

        $validated = $request->validate([
            'ten_lop' => 'required|string|max:255',
            'ma_lop' => 'nullable|string|max:50',
            'chi_nhanh' => 'required',
            'chuong_trinh' => 'required|string|max:100',
            'cap_do' => 'required|string|max:100',
            'si_so_toi_da' => 'required|integer|min:1|max:100',
            // Ngưỡng khai giảng (số học viên tối thiểu để mở lớp) không được vượt sĩ số tối đa.
            'min_students' => 'nullable|integer|min:1|max:100|lte:si_so_toi_da',
            'phong_hoc' => 'nullable|string|max:50',
            'giao_vien_chinh' => 'nullable|integer|exists:users,id',
            // Trợ giảng không cố định theo lớp: làm theo ca + phân công công việc (Giao việc trợ giảng), không gán khi tạo lớp.
            'giao_vien_nn' => 'nullable|integer|exists:users,id',
            'hoc_phi' => 'nullable|numeric|min:0',
            'ghi_chu' => 'nullable|string',
            'schedule_sessions_json' => 'nullable|string|max:200000',
        ], self::CAPACITY_MESSAGES);

        $scheduleSessions = $this->parseScheduleSessions($request->input('schedule_sessions_json'));

        // Branch mapping (id or string) — sai tên chi nhánh phải báo lỗi,
        // không âm thầm gán về chi nhánh khác (trước đây fallback Branch::first()).
        $branchId = is_numeric($validated['chi_nhanh'])
            ? (int) $validated['chi_nhanh']
            : Branch::where('code', $validated['chi_nhanh'])->orWhere('name', 'LIKE', "%{$validated['chi_nhanh']}%")->value('id');
        if (! $branchId || ! Branch::whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['chi_nhanh' => 'Chi nhánh không tồn tại, vui lòng chọn lại.']);
        }
        $branchId = (int) $branchId;
        abort_unless(DataScope::coversBranch(auth()->user(), 'class', $branchId), 403, 'Bạn chỉ được quản lý lớp thuộc chi nhánh của mình.');

        // TKB do client render chưa biết lịch nghỉ lễ: loại các buổi rơi vào ngày nghỉ
        // toàn hệ thống hoặc ngày nghỉ riêng của chi nhánh trước khi tạo buổi học.
        if ($scheduleSessions) {
            $scheduleSessions = $this->schedule->withoutHolidays($scheduleSessions, $branchId);
            if (! $scheduleSessions) {
                throw ValidationException::withMessages(['schedule_sessions_json' => 'Tất cả buổi học đều rơi vào ngày nghỉ lễ, vui lòng chọn lại lịch.']);
            }
        }

        $this->assertValidTeachingStaff($validated);

        // Mã lớp sinh tự động nếu để trống
        $code = ! empty($validated['ma_lop'])
            ? strtoupper(trim($validated['ma_lop']))
            : strtoupper(Str::slug($validated['chuong_trinh']).'-'.Str::random(4));

        // Kiểm tra trùng mã
        $originalCode = $code;
        $counter = 1;
        while (ClassModel::where('code', $code)->exists()) {
            $code = $originalCode.'-'.$counter;
            $counter++;
        }

        $course = Course::where('name', $validated['chuong_trinh'])->first();
        $teacherId = ! empty($validated['giao_vien_chinh']) && is_numeric($validated['giao_vien_chinh']) ? (int) $validated['giao_vien_chinh'] : null;
        $foreignTeacherId = ! empty($validated['giao_vien_nn']) && is_numeric($validated['giao_vien_nn']) ? (int) $validated['giao_vien_nn'] : null;
        $defaultRoom = $validated['phong_hoc'] ?? null;

        // Chặn trùng phòng / trùng nhân sự với các buổi đã có của lớp khác
        $this->assertNoScheduleConflicts($scheduleSessions, (int) $branchId, array_filter([$teacherId, $foreignTeacherId]), $defaultRoom);

        // Tạo lớp học + các buổi học trong một transaction để không sót lớp rỗng khi lịch lỗi
        $class = DB::transaction(function () use ($validated, $branchId, $code, $course, $teacherId, $foreignTeacherId, $defaultRoom, $scheduleSessions) {
            $class = ClassModel::create([
                'code' => $code,
                'name' => $validated['ten_lop'],
                'branch_id' => $branchId,
                'course_id' => $course?->id,
                'program' => $validated['chuong_trinh'],
                'level' => $validated['cap_do'],
                'max_capacity' => $validated['si_so_toi_da'],
                'min_students' => $validated['min_students'] ?? min(ClassModel::DEFAULT_MIN_STUDENTS, (int) $validated['si_so_toi_da']),
                'room' => $defaultRoom,
                'teacher_id' => $teacherId,
                'foreign_teacher_id' => $foreignTeacherId,
                'tuition_fee' => $validated['hoc_phi'] ?? null,
                'notes' => $validated['ghi_chu'] ?? null,
                'status' => $scheduleSessions ? 'active' : 'pending_schedule',
            ]);

            foreach ($scheduleSessions as $session) {
                ClassSession::create([
                    'class_id' => $class->id,
                    'branch_id' => $class->branch_id,
                    'date' => $session['date'],
                    'shift_name' => $session['shift'],
                    'start_time' => $session['start'],
                    'end_time' => $session['end'],
                    'room' => $session['room'] ?? ($class->room ?: null),
                    'teacher_id' => $class->teacher_id ?? $class->foreign_teacher_id,
                    'foreign_teacher_id' => $class->foreign_teacher_id,
                    'status' => 'scheduled',
                ]);
            }

            if ($scheduleSessions) {
                $class->update([
                    'start_date' => $scheduleSessions[0]['date'],
                    'end_date' => $scheduleSessions[count($scheduleSessions) - 1]['date'],
                    'schedule_text' => collect($scheduleSessions)
                        ->map(fn ($session) => "{$session['shift']} {$session['start']}-{$session['end']}")
                        ->unique()
                        ->implode('; '),
                ]);
            }

            return $class;
        });

        // Lưu bản ghi vào AcademicRecord để đồng bộ cả 2 hệ thống
        AcademicRecord::create([
            'screen_key' => '01_Web_Admin/13_tao_lop_moi',
            'module' => 'classes',
            'record_code' => $class->code,
            'title' => 'Lớp mới: '.$class->name,
            'status' => 'active',
            'data' => [
                'class_id' => $class->id,
                'name' => $class->name,
                'code' => $class->code,
                'branch' => $class->branch?->name,
                'program' => $class->program,
                'level' => $class->level,
                'max_capacity' => $class->max_capacity,
                'room' => $class->room,
                'tuition_fee' => $class->tuition_fee,
                'scheduled_sessions' => count($scheduleSessions),
            ],
            'user_id' => Auth::id(),
        ]);

        $message = $scheduleSessions
            ? "Tạo lớp học '{$class->name}' ({$class->code}) thành công với ".count($scheduleSessions).' buổi học đã lên lịch; lớp đã được kích hoạt.'
            : "Tạo lớp học '{$class->name}' ({$class->code}) thành công! Bước tiếp theo: cấu hình lịch học.";

        // Lớp chưa có lịch → mở thẳng tab Lịch & buổi học (bước tiếp theo của vòng đời).
        return redirect()->route('classes.show', ['id' => $class->id] + ($scheduleSessions ? [] : ['tab' => 'schedule']))
            ->with('success', $message);
    }

    /**
     * Danh sách chọn GV chính / GVNN (quyền đối tượng class.teach) và trợ giảng (class.assist), đang hoạt động.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function teachingStaffOptions(): array
    {
        return [
            Rbac::scopeUsersWithPermission(User::where('is_active', true), 'class.teach')->orderBy('name')->get(),
            Rbac::scopeUsersWithPermission(User::where('is_active', true), 'class.assist')->orderBy('name')->get(),
        ];
    }

    /**
     * GV chính/GVNN phải có quyền "Được xếp dạy lớp" (class.teach — mặc định giáo viên, Học thuật, Quản lý cơ sở);
     * trợ giảng phải có "Được xếp làm trợ giảng" (class.assist — mặc định trợ giảng, Học vụ), đang hoạt động.
     * Chặn gán tài khoản sai (UI đã lọc dropdown nhưng API vẫn có thể gửi id tùy ý).
     */
    protected function assertValidTeachingStaff(array $validated): void
    {
        $rules = [
            'giao_vien_chinh' => 'class.teach',
            'giao_vien_nn' => 'class.teach',
            'tro_giang' => 'class.assist',
        ];
        foreach ($rules as $field => $permission) {
            if (empty($validated[$field])) {
                continue;
            }
            $user = User::whereKey($validated[$field])->where('is_active', true)->first();
            if (! $user || ! $user->can($permission)) {
                throw ValidationException::withMessages([
                    $field => 'Người được gán phải đang hoạt động và đúng vai trò (giáo viên/quản lý hoặc trợ giảng/học vụ).',
                ]);
            }
        }
    }

    /**
     * Giải mã payload thời khóa biểu render từ form tạo lớp (Alpine),
     * chuẩn hoá và chặn các buổi chồng giờ trong cùng đợt.
     */
    protected function parseScheduleSessions(?string $json): array
    {
        if (! $json || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['schedule_sessions_json' => 'Dữ liệu thời khóa biểu không đọc được.']);
        }
        if (count($decoded) > 200) {
            throw ValidationException::withMessages(['schedule_sessions_json' => 'Thời khóa biểu vượt quá 200 buổi mỗi lần tạo.']);
        }

        $sessions = [];
        foreach ($decoded as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages(['schedule_sessions_json' => 'Buổi học thứ '.($index + 1).' không hợp lệ.']);
            }
            $entry = validator($raw, [
                'date' => ['required', 'date_format:Y-m-d'],
                'shift' => ['required', 'string', 'max:50'],
                'start' => ['required', 'date_format:H:i'],
                'end' => ['required', 'date_format:H:i'],
                'room' => ['nullable', 'string', 'max:50'],
            ])->validate();

            if (strcmp($entry['end'], $entry['start']) <= 0) {
                throw ValidationException::withMessages(['schedule_sessions_json' => "Buổi {$entry['shift']} ngày {$entry['date']} có giờ kết thúc không sau giờ bắt đầu."]);
            }

            $sessions[] = [
                'date' => $entry['date'],
                'shift' => $entry['shift'],
                'start' => $entry['start'],
                'end' => $entry['end'],
                'room' => trim($entry['room'] ?? '') ?: null,
            ];
        }

        usort($sessions, fn ($a, $b) => [$a['date'], $a['start']] <=> [$b['date'], $b['start']]);

        foreach ($sessions as $i => $session) {
            if ($i > 0
                && $sessions[$i - 1]['date'] === $session['date']
                && strcmp($session['start'], $sessions[$i - 1]['end']) < 0) {
                throw ValidationException::withMessages(['schedule_sessions_json' => "Hai buổi ngày {$session['date']} bị chồng giờ ({$sessions[$i - 1]['start']}-{$sessions[$i - 1]['end']} và {$session['start']}-{$session['end']})."]);
            }
        }

        return $sessions;
    }

    /**
     * Đối chiếu đợt buổi vừa render với ClassSession đã tồn tại của lớp khác:
     * trùng phòng cùng chi nhánh hoặc trùng giáo viên/trợ giảng/GVNN là từ chối.
     */
    protected function assertNoScheduleConflicts(array $sessions, int $branchId, array $resourceIds, ?string $defaultRoom): void
    {
        $conflict = $this->schedule->findConflict($sessions, $branchId, $resourceIds, $defaultRoom);
        if ($conflict) {
            [$session, $existing] = $conflict;
            throw ValidationException::withMessages([
                'schedule_sessions_json' => "Xung đột lịch {$session['date']} {$session['start']}-{$session['end']} với lớp {$existing->classModel?->name} ({$existing->classModel?->code}).",
            ]);
        }
    }

    /**
     * Đổi GV/TA/phòng của lớp sẽ áp lên các buổi sắp tới: chạy cùng kiểm tra xung đột
     * như khi tạo lớp để không xếp một người/phòng vào hai lớp cùng giờ.
     */
    protected function assertStaffChangeHasNoConflicts(ClassModel $class, $futureSessions, int $branchId, array $new): void
    {
        if ($futureSessions->isEmpty()) {
            return;
        }

        $toArray = fn (ClassSession $session) => [
            'date' => $session->date->toDateString(),
            'start' => substr((string) $session->getRawOriginal('start_time'), 0, 5),
            'end' => substr((string) $session->getRawOriginal('end_time'), 0, 5),
            'room' => $session->room,
        ];
        $sessions = $futureSessions->map($toArray)->all();

        $checks = [];
        if ($new['teacher'] && (int) $new['teacher'] !== (int) $class->teacher_id) {
            $checks[$new['teacher_field']] = [$sessions, [$new['teacher']]];
        }
        // GVNN được lưu riêng trên từng buổi nên phải kiểm tra trùng cả khi lớp đã có GV chính
        // (chỉ trên các buổi sẽ nhận GVNN mới — buổi đã gán GVNN riêng giữ nguyên).
        if (($new['foreign'] ?? null) && (int) $new['foreign'] !== (int) $class->foreign_teacher_id) {
            $checks['giao_vien_nn'] = [($new['foreign_sessions'] ?? $futureSessions)->map($toArray)->all(), [$new['foreign']]];
        }
        if ($new['assistant'] && (int) $new['assistant'] !== (int) $class->assistant_id) {
            $checks['tro_giang'] = [$sessions, [$new['assistant']]];
        }
        if ($new['room'] && $new['room'] !== $new['previous_room']) {
            $roomSessions = $futureSessions
                ->filter(fn (ClassSession $session) => $session->room === null || $session->room === $new['previous_room'])
                ->map(fn (ClassSession $session) => ['room' => $new['room']] + $toArray($session))
                ->values()->all();
            $checks['phong_hoc'] = [$roomSessions, []];
        }

        foreach ($checks as $field => [$candidateSessions, $resourceIds]) {
            $conflict = $this->schedule->findConflict($candidateSessions, $branchId, $resourceIds, null, $class->id);
            if ($conflict) {
                [$session, $existing] = $conflict;
                throw ValidationException::withMessages([
                    $field => "Xung đột lịch {$session['date']} {$session['start']}-{$session['end']} với lớp {$existing->classModel?->name} ({$existing->classModel?->code}).",
                ]);
            }
        }
    }

    /**
     * Danh sách lớp — màn chính của menu Lớp học (gộp "Sơ đồ khối" + "Danh sách lớp chi tiết" + danh sách cũ).
     * Sơ đồ khối trở thành hàng chip đếm lớp theo chương trình / cấp độ; trạng thái lớp là chip lọc nhanh.
     * Mặc định ẩn lớp đã hủy (chỉ hiện khi chọn chip "Đã hủy").
     */
    public function index(Request $request)
    {
        $this->ensureCanBrowseClasses();
        $viewer = auth()->user();
        $branches = Branch::where('is_active', true)->get();
        $search = trim((string) $request->query('search', ''));
        $branchFilter = $request->query('branch_id');
        $statusFilter = array_key_exists((string) $request->query('status'), ClassLifecycle::STATUSES) ? $request->query('status') : null;
        $programFilter = $request->query('program');
        $levelFilter = $request->query('level');

        // Phạm vi gốc (người xem + chi nhánh + tìm kiếm) — chip đếm tính trên phạm vi này.
        $scope = ClassModel::query()->visibleTo($viewer)
            ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter))
            // Phải bọc closure: orWhere viết thẳng sẽ thoát cả filter status lẫn chi nhánh.
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'LIKE', "%{$search}%")->orWhere('code', 'LIKE', "%{$search}%")));

        $statusCounts = (clone $scope)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $filtered = (clone $scope)
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter), fn ($q) => $q->where('status', '!=', 'cancelled'));

        // Sơ đồ khối: số lớp thật theo chương trình và theo cấp độ (trong trạng thái đang lọc).
        $programCounts = (clone $filtered)->whereNotNull('program')->where('program', '!=', '')
            ->selectRaw('program as label, COUNT(*) as total')->groupBy('program')->orderByDesc('total')->pluck('total', 'label');
        $levelCounts = (clone $filtered)->whereNotNull('level')->where('level', '!=', '')
            ->when($programFilter, fn ($q) => $q->where('program', $programFilter))
            ->selectRaw('level as label, COUNT(*) as total')->groupBy('level')->orderByDesc('total')->pluck('total', 'label');

        $classes = (clone $filtered)
            ->with(['branch', 'teacher', 'foreignTeacher'])
            ->when($programFilter, fn ($q) => $q->where('program', $programFilter))
            ->when($levelFilter, fn ($q) => $q->where('level', $levelFilter))
            // Tiến độ thật: số buổi (không tính buổi hủy) và số buổi đã diễn ra.
            ->withCount([
                // Buổi phụ đạo 1-1 không tính vào tiến độ lớp.
                'sessions as total_sessions_count' => fn ($q) => $q->where('status', '!=', 'cancelled')->where('type', '!=', ClassSession::TYPE_SUPPORT),
                'sessions as done_sessions_count' => fn ($q) => $q->where('status', '!=', 'cancelled')->where('type', '!=', ClassSession::TYPE_SUPPORT)->whereDate('date', '<=', today()),
            ])
            ->orderByRaw("CASE status WHEN 'pending_schedule' THEN 0 WHEN 'upcoming' THEN 1 WHEN 'active' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END")
            ->orderBy('code')
            ->paginate(20)->withQueryString();
        ClassModel::loadRosterCounts($classes->getCollection());

        $classIds = $classes->pluck('id');
        $bigTests = \App\Models\BigTest::whereIn('class_id', $classIds)->orderBy('scheduled_at')
            ->get(['id', 'class_id', 'title', 'scheduled_at', 'status'])->groupBy('class_id');
        $nextSessions = ClassSession::whereIn('class_id', $classIds)->where('status', '!=', 'cancelled')->where('type', '!=', ClassSession::TYPE_SUPPORT)
            ->whereDate('date', '>=', today())->orderBy('date')->orderBy('start_time')
            ->get(['id', 'class_id', 'date', 'start_time'])->unique('class_id')->keyBy('class_id');

        $rows = $classes->getCollection()->mapWithKeys(function (ClassModel $c) use ($bigTests, $nextSessions) {
            $seat = $c->seatSummary();
            $nextBigTest = $bigTests->get($c->id, collect())->first(fn ($bt) => $bt->scheduled_at && $bt->scheduled_at->isFuture());

            return [$c->id => [
                'seat' => $seat,
                'next' => ClassLifecycle::nextAction($c, $seat, $nextSessions->get($c->id), $nextBigTest),
            ]];
        });

        $stats = [
            'active' => (int) ($statusCounts['active'] ?? 0),
            'pending_schedule' => (int) ($statusCounts['pending_schedule'] ?? 0),
            'short' => (clone $scope)->whereIn('status', ['pending_schedule', 'upcoming'])->get()
                ->filter(fn (ClassModel $c) => $c->seatSummary()['needed'] > 0)->count(),
            // Cùng định nghĩa với bộ lọc "Chưa điểm danh" của màn Lịch học & điểm danh (link từ thẻ này).
            'missing_attendance' => (function () use ($viewer, $branchFilter) {
                $dashboard = app(ClassDashboardService::class);
                $today = \Carbon\CarbonImmutable::today();

                return $dashboard->sessionsQuery($viewer, $branchFilter ? (int) $branchFilter : null)
                    ->whereDate('date', $today->toDateString())->get()
                    ->filter(fn (ClassSession $s) => $dashboard->attendanceState($s, $today)['key'] === 'missing')
                    ->count();
            })(),
        ];

        return view('classes.index', compact(
            'classes', 'rows', 'branches', 'search', 'branchFilter', 'statusFilter', 'programFilter', 'levelFilter',
            'statusCounts', 'programCounts', 'levelCounts', 'bigTests', 'stats'
        ));
    }

    /**
     * Trang lớp — một trang cho một lớp, tab con theo việc (gộp Hồ sơ lớp + Chi tiết lớp học thuật và các việc
     * trước nằm rải ở menu khác: cấu hình lịch, điểm danh, báo cáo buổi, Big Test, sự vụ).
     */
    public function show(Request $request, int $id)
    {
        $this->ensureCanBrowseClasses();
        $viewer = auth()->user();
        $class = ClassModel::with(['course', 'branch', 'teacher', 'assistant', 'foreignTeacher', 'scheduleConfig'])
            ->visibleTo($viewer)->findOrFail($id);
        $tab = array_key_exists((string) $request->query('tab'), ClassLifecycle::TABS) ? $request->query('tab') : 'overview';

        $seat = $class->seatSummary();
        $steps = ClassLifecycle::steps($class, $seat);
        $canManage = $viewer->can('class.update') && $class->userCan($viewer, 'update');

        $activeSessions = $class->sessions()->where('status', '!=', 'cancelled')->where('type', '!=', ClassSession::TYPE_SUPPORT);
        $sessionProgress = [
            'total' => (clone $activeSessions)->count(),
            'done' => (clone $activeSessions)->whereDate('date', '<=', today())->count(),
        ];
        $nextSession = (clone $activeSessions)->with(['teacher:id,name', 'foreignTeacher:id,name'])
            ->whereDate('date', '>=', today())->orderBy('date')->orderBy('start_time')->first();
        $bigTests = \App\Models\BigTest::where('class_id', $class->id)->orderBy('scheduled_at')->get();
        $nextBigTest = $bigTests->first(fn ($bt) => $bt->scheduled_at && $bt->scheduled_at->isFuture());
        $nextAction = ClassLifecycle::nextAction($class, $seat, $nextSession, $nextBigTest);
        $currentStage = \App\Models\SyllabusAssignment::where('class_id', $class->id)->where('status', 'in_progress')->latest()->first();
        $openIncidents = StaffReport::where('type', 'journal')->where('class_id', $class->id)->where('status', '!=', 'resolved')->count();

        $data = compact('class', 'tab', 'seat', 'steps', 'canManage', 'sessionProgress', 'nextSession', 'bigTests',
            'nextBigTest', 'nextAction', 'currentStage', 'openIncidents');

        // Dữ liệu riêng của từng tab chỉ nạp khi mở tab đó.
        if ($tab === 'overview') {
            // GVNN & trợ giảng không cố định theo lớp: hiển thị người thực tế của các buổi / ca sắp tới.
            $data['upcomingForeignTeachers'] = (clone $activeSessions)->whereDate('date', '>=', today())
                ->whereNotNull('foreign_teacher_id')->with('foreignTeacher:id,name')->get(['id', 'foreign_teacher_id'])
                ->pluck('foreignTeacher.name')->filter()->unique()->values();
            $data['upcomingAssistants'] = WorkTask::with('assignee:id,name')->where('class_id', $class->id)
                ->whereDate('due_date', '>=', today())->whereDate('due_date', '<=', today()->addDays(7))
                ->get(['id', 'assignee_id'])->pluck('assignee.name')->filter()->unique()->values();
        }
        if (in_array($tab, ['overview', 'students'], true)) {
            // Danh sách lớp thật: học viên có lớp chính là lớp này + học viên liên kết lớp khác,
            // bỏ Thôi học / Hoàn thành / Bảo lưu (audit A4 #7).
            $data['students'] = $class->rosterStudents();
        }
        if (in_array($tab, ['schedule', 'attendance'], true)) {
            $dashboard = app(ClassDashboardService::class);
            $sessions = $class->sessions()
                ->with(['teacher:id,name', 'foreignTeacher:id,name', 'assistant:id,name', 'holiday:id,name'])
                ->withCount('attendances')
                ->orderBy('date')->orderBy('start_time')->get();
            $today = \Carbon\CarbonImmutable::today();
            $data['sessions'] = $sessions;
            $data['attendanceStates'] = $sessions->mapWithKeys(fn (ClassSession $s) => [$s->id => $dashboard->attendanceState($s, $today)]);
            $data['classReports'] = \App\Models\ClassReport::with('reporter:id,name')->where('class_id', $class->id)
                // Báo cáo mới nhất của mỗi buổi (keyBy giữ bản cuối cùng = bản cũ nhất); báo cáo không gắn buổi giữ riêng.
                ->latest('session_date')->latest('id')->get()
                ->groupBy(fn ($report) => $report->class_session_id ?? 'none-'.$report->id)->map->first();
            $data['canRecordAttendance'] = $viewer->can('attendance_student.record') || $viewer->can('attendance_student.record_any');
        }
        if ($tab === 'schedule') {
            // Trợ giảng của buổi = người được giao việc (theo ca) gắn lớp này trong ngày; dữ liệu cũ vẫn đọc assistant_id của buổi.
            $data['assistantsByDate'] = WorkTask::with('assignee:id,name')->where('class_id', $class->id)
                ->whereNotNull('due_date')->get(['id', 'assignee_id', 'due_date'])
                ->groupBy(fn (WorkTask $task) => $task->due_date->toDateString())
                ->map(fn ($tasks) => $tasks->pluck('assignee.name')->filter()->unique()->values());
            if ($canManage) {
                $data['foreignTeacherOptions'] = $this->teachingStaffOptions()[0];
                $data['foreignEditableIds'] = ClassSession::where('class_id', $class->id)->staffSyncable()->pluck('id')->all();
            }
        }
        if ($tab === 'incidents') {
            $data['incidents'] = StaffReport::with(['user:id,name', 'followups.user:id,name'])
                ->where('type', 'journal')->where('class_id', $class->id)
                ->latest('report_date')->latest('id')->get();
        }

        return view('classes.show', $data);
    }

    /** Link cũ "Hồ sơ lớp" → Trang lớp (không có id → Danh sách lớp). */
    public function profile(Request $request, $id = null)
    {
        return $id
            ? redirect()->route('classes.show', ['id' => $id] + $request->query())
            : redirect()->route('classes.index');
    }

    /** Link cũ "Sơ đồ khối" → Danh sách lớp (chip chương trình / cấp độ). */
    public function academicOverview(Request $request)
    {
        $branch = $request->query('branch');

        return redirect()->route('classes.index', is_numeric($branch) ? ['branch_id' => $branch] : []);
    }

    /** Link cũ "Danh sách lớp chi tiết" → Danh sách lớp, giữ nguyên bộ lọc. */
    public function academicList(Request $request)
    {
        return redirect()->route('classes.index', $request->only(['search', 'branch_id', 'program', 'level', 'page']));
    }

    /** Link cũ "Chi tiết lớp học thuật" → tab Học thuật của Trang lớp. */
    public function academicDetail(Request $request, $id = null)
    {
        return $id
            ? redirect()->route('classes.show', ['id' => $id, 'tab' => 'academic'])
            : redirect()->route('classes.index');
    }

    /**
     * Show edit form for a class
     */
    public function edit(Request $request, $id)
    {
        abort_if(! auth()->user()->can('class.update'), 403, 'Bạn không có quyền chỉnh sửa lớp học.');

        $class = ClassModel::with(['branch', 'teacher', 'assistant', 'foreignTeacher', 'course'])->findOrFail($id);
        abort_unless($class->userCan(auth()->user(), 'update'), 403, 'Lớp học này nằm ngoài phạm vi bạn được quản lý.');
        $branches = Branch::where('is_active', true)->get();
        $courses = Course::where('is_active', true)->get();
        $levels = CourseLevel::where('is_active', true)->get();
        [$teachers, $assistants] = $this->teachingStaffOptions();

        return view('classes.edit', compact('class', 'branches', 'courses', 'levels', 'teachers', 'assistants'));
    }

    /**
     * Handle edit form submission
     */
    public function update(Request $request, $id)
    {
        abort_if(! auth()->user()->can('class.update'), 403, 'Bạn không có quyền chỉnh sửa lớp học.');

        $class = ClassModel::findOrFail($id);
        abort_unless($class->userCan(auth()->user(), 'update'), 403, 'Lớp học này nằm ngoài phạm vi bạn được quản lý.');

        $validated = $request->validate([
            'ten_lop' => 'required|string|max:255',
            'ma_lop' => 'nullable|string|max:50',
            'chi_nhanh' => 'required',
            'chuong_trinh' => 'required|string|max:100',
            'cap_do' => 'required|string|max:100',
            'si_so_toi_da' => 'required|integer|min:1|max:100',
            // Ngưỡng khai giảng (số học viên tối thiểu để mở lớp) không được vượt sĩ số tối đa.
            'min_students' => 'nullable|integer|min:1|max:100|lte:si_so_toi_da',
            'phong_hoc' => 'nullable|string|max:50',
            'giao_vien_chinh' => 'nullable|integer|exists:users,id',
            'tro_giang' => 'nullable|integer|exists:users,id',
            'giao_vien_nn' => 'nullable|integer|exists:users,id',
            'hoc_phi' => 'nullable|numeric|min:0',
            'ghi_chu' => 'nullable|string',
            'status' => ['nullable', 'string', 'in:pending_schedule,active,upcoming,completed,cancelled'],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'schedule_text' => 'nullable|string|max:500',
        ], self::CAPACITY_MESSAGES);

        $branchId = is_numeric($validated['chi_nhanh'])
            ? (int) $validated['chi_nhanh']
            : Branch::where('code', $validated['chi_nhanh'])->orWhere('name', 'LIKE', "%{$validated['chi_nhanh']}%")->value('id');
        if (! $branchId || ! Branch::whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['chi_nhanh' => 'Chi nhánh không tồn tại, vui lòng chọn lại.']);
        }
        $branchId = (int) $branchId;
        abort_unless(DataScope::coversBranch(auth()->user(), 'class', $branchId), 403, 'Bạn chỉ được quản lý lớp thuộc chi nhánh của mình.');

        $this->assertValidTeachingStaff($validated);

        $course = Course::where('name', $validated['chuong_trinh'])->first();

        $code = ! empty($validated['ma_lop'])
            ? strtoupper(trim($validated['ma_lop']))
            : $class->code;

        // Check code uniqueness (excluding self)
        $originalCode = $code;
        $counter = 1;
        while (ClassModel::where('code', $code)->where('id', '!=', $id)->exists()) {
            $code = $originalCode.'-'.$counter;
            $counter++;
        }

        // Field có trong request thì áp dụng cả khi rỗng — chọn "-- Chưa gán --" trên
        // form phải gỡ được GV/TA/phòng; field không được gửi thì giữ giá trị hiện tại.
        $resolveId = fn (string $field, ?int $current) => array_key_exists($field, $validated)
            ? (filled($validated[$field]) ? (int) $validated[$field] : null)
            : $current;
        $previousRoom = $class->room;
        $newTeacherId = $resolveId('giao_vien_chinh', $class->teacher_id);
        $newAssistantId = $resolveId('tro_giang', $class->assistant_id);
        $newForeignTeacherId = $resolveId('giao_vien_nn', $class->foreign_teacher_id);
        $newRoom = array_key_exists('phong_hoc', $validated) ? $validated['phong_hoc'] : $class->room;

        // Chỉ buổi chưa diễn ra, chưa điểm danh/check-in mới được đồng bộ nhân sự/phòng;
        // buổi quá khứ là dữ liệu lịch sử (bảng công, điểm danh khớp theo buổi).
        // Gồm cả buổi học bù (type makeup) xếp khi thêm ngày nghỉ.
        $futureSessions = ClassSession::where('class_id', $class->id)->staffSyncable()->get();
        // GVNN không cố định: buổi đã được gán GVNN riêng (khác GVNN mặc định cũ của lớp) giữ nguyên khi đổi GVNN của lớp.
        $foreignSyncSessions = $futureSessions->filter(fn (ClassSession $s) => $s->foreign_teacher_id === null
            || (int) $s->foreign_teacher_id === (int) $class->foreign_teacher_id)->values();
        $this->assertStaffChangeHasNoConflicts($class, $futureSessions, $branchId, [
            'foreign_sessions' => $foreignSyncSessions,
            // Lớp không có GV chính: người đứng lớp của buổi là GVNN của buổi, đã kiểm tra ở nhánh 'foreign'.
            'teacher' => $newTeacherId,
            'teacher_field' => 'giao_vien_chinh',
            'assistant' => $newAssistantId,
            'foreign' => $newForeignTeacherId,
            'room' => $newRoom,
            'previous_room' => $previousRoom,
        ]);

        DB::transaction(function () use ($class, $validated, $code, $branchId, $course, $newTeacherId, $newAssistantId, $newForeignTeacherId, $newRoom, $previousRoom, $futureSessions, $foreignSyncSessions) {
            $class->update([
                'code' => $code,
                'name' => $validated['ten_lop'],
                'branch_id' => $branchId,
                'course_id' => $course?->id,
                'program' => $validated['chuong_trinh'],
                'level' => $validated['cap_do'],
                'max_capacity' => $validated['si_so_toi_da'],
                'min_students' => $validated['min_students'] ?? min((int) ($class->min_students ?: ClassModel::DEFAULT_MIN_STUDENTS), (int) $validated['si_so_toi_da']),
                'room' => $newRoom,
                'teacher_id' => $newTeacherId,
                'assistant_id' => $newAssistantId,
                'foreign_teacher_id' => $newForeignTeacherId,
                'tuition_fee' => $validated['hoc_phi'] ?? $class->tuition_fee,
                'notes' => $validated['ghi_chu'] ?? $class->notes,
                'status' => $validated['status'] ?? $class->status,
                'start_date' => $validated['start_date'] ?? $class->start_date,
                'end_date' => $validated['end_date'] ?? $class->end_date,
                'schedule_text' => $validated['schedule_text'] ?? $class->schedule_text,
            ]);

            $futureQuery = fn () => ClassSession::whereKey($futureSessions->modelKeys());
            if ($class->wasChanged('foreign_teacher_id') && $foreignSyncSessions->isNotEmpty()) {
                ClassSession::whereKey($foreignSyncSessions->modelKeys())->update(['foreign_teacher_id' => $class->foreign_teacher_id]);
            }
            if ($class->wasChanged('teacher_id') || $class->wasChanged('foreign_teacher_id')) {
                // Lớp không có GV chính thì người đứng lớp của buổi là GVNN của chính buổi đó.
                foreach (ClassSession::whereKey($futureSessions->modelKeys())->get() as $session) {
                    $session->update(['teacher_id' => $class->teacher_id ?? $session->foreign_teacher_id]);
                }
            }
            if ($class->wasChanged('assistant_id')) {
                $futureQuery()->update(['assistant_id' => $class->assistant_id]);
            }
            if ($class->wasChanged('branch_id')) {
                // Buổi sắp tới theo chi nhánh mới: lịch/nghỉ lễ/chấm công lọc buổi theo branch_id của buổi.
                $futureQuery()->update(['branch_id' => $class->branch_id]);
            }
            if ($class->wasChanged('room')) {
                // Chỉ đụng phòng của buổi chưa có phòng riêng hoặc đang dùng phòng cũ của lớp.
                $futureQuery()
                    ->where(fn ($query) => $query->whereNull('room')->orWhere('room', $previousRoom))
                    ->update(['room' => $class->room]);
            }
        });

        return redirect()->route('classes.show', ['id' => $class->id])
            ->with('success', "Đã cập nhật lớp học '{$class->name}' ({$class->code}) thành công!");
    }

    /**
     * Gán / đổi / gỡ GVNN theo từng buổi của lớp đã tồn tại (GVNN không cố định theo lớp).
     * Chỉ áp lên buổi chưa diễn ra, chưa điểm danh/chấm công (staffSyncable); không đụng GV chính.
     * Lớp không có GV chính thì người đứng lớp của buổi (teacher_id) đi theo GVNN của buổi.
     */
    public function assignForeignTeacher(Request $request, int $id)
    {
        abort_if(! auth()->user()->can('class.update'), 403, 'Bạn không có quyền chỉnh sửa lớp học.');
        $class = ClassModel::findOrFail($id);
        abort_unless($class->userCan(auth()->user(), 'update'), 403, 'Lớp học này nằm ngoài phạm vi bạn được quản lý.');

        // "none" = gỡ GVNN khỏi các buổi đã chọn (phải chọn rõ, tránh lưu nhầm ô trống thành gỡ GVNN hàng loạt).
        $request->validate(['giao_vien_nn' => 'required'], ['giao_vien_nn.required' => 'Chọn GVNN, hoặc chọn "Không có GVNN" để gỡ.']);
        if ($request->input('giao_vien_nn') === 'none') {
            $request->merge(['giao_vien_nn' => null]);
        }
        $validated = $request->validate([
            'giao_vien_nn' => 'nullable|integer|exists:users,id',
            'session_ids' => 'required|array|min:1|max:300',
            'session_ids.*' => 'integer',
            'set_default' => 'nullable|boolean',
        ], [
            'session_ids.required' => 'Chọn ít nhất một buổi học để gán GVNN.',
            'session_ids.min' => 'Chọn ít nhất một buổi học để gán GVNN.',
        ]);
        $this->assertValidTeachingStaff($validated);
        $foreignId = filled($validated['giao_vien_nn'] ?? null) ? (int) $validated['giao_vien_nn'] : null;

        $sessions = ClassSession::where('class_id', $class->id)->staffSyncable()
            ->whereKey(array_map('intval', $validated['session_ids']))->get();
        if ($sessions->count() !== count(array_unique(array_map('intval', $validated['session_ids'])))) {
            throw ValidationException::withMessages(['session_ids' => 'Chỉ đổi GVNN được cho buổi sắp tới của lớp này, chưa điểm danh/chấm công.']);
        }

        if ($foreignId) {
            $conflict = $this->schedule->findConflict($sessions->map(fn (ClassSession $s) => [
                'date' => $s->date->toDateString(),
                'start' => substr((string) $s->getRawOriginal('start_time'), 0, 5),
                'end' => substr((string) $s->getRawOriginal('end_time'), 0, 5),
            ])->all(), (int) $class->branch_id, [$foreignId], null, $class->id);
            if ($conflict) {
                [$session, $existing] = $conflict;
                throw ValidationException::withMessages([
                    'giao_vien_nn' => "GVNN bị trùng lịch {$session['date']} {$session['start']}-{$session['end']} với lớp {$existing->classModel?->name} ({$existing->classModel?->code}).",
                ]);
            }
        }

        DB::transaction(function () use ($class, $sessions, $foreignId, $validated) {
            foreach ($sessions as $session) {
                $session->update([
                    'foreign_teacher_id' => $foreignId,
                    'teacher_id' => $class->teacher_id ?? $foreignId,
                ]);
            }
            if (! empty($validated['set_default'])) {
                $class->update(['foreign_teacher_id' => $foreignId]);
            }
        });

        if ($foreignId && $foreignId !== (int) auth()->id()) {
            $dates = $sessions->sortBy('date')->map(fn (ClassSession $s) => $s->date->format('d/m'))->unique()->take(6)->implode(', ');
            AdminNotification::create([
                'user_id' => $foreignId,
                'type' => 'class_assigned',
                'title' => "Bạn được xếp dạy GVNN lớp {$class->code}",
                'message' => "{$sessions->count()} buổi lớp {$class->name}: {$dates}".($sessions->count() > 6 ? '…' : '').'.',
                'data' => ['link' => route('classes.show', ['id' => $class->id, 'tab' => 'schedule'])],
                'is_read' => false,
            ]);
        }

        $name = $foreignId ? User::find($foreignId)?->name : null;

        return redirect()->route('classes.show', ['id' => $class->id, 'tab' => 'schedule'])
            ->with('success', $name
                ? "Đã gán GVNN {$name} cho {$sessions->count()} buổi."
                : "Đã gỡ GVNN khỏi {$sessions->count()} buổi.");
    }

    /**
     * Soft delete a class. Chặn xóa khi lớp còn học viên đang xếp lớp
     * hoặc còn buổi học chưa diễn ra — thay vào đó hãy hủy lớp.
     */
    public function destroy(Request $request, $id)
    {
        abort_if(! auth()->user()->can('class.delete'), 403, 'Bạn không có quyền xóa lớp học.');

        $class = ClassModel::findOrFail($id);
        abort_unless($class->userCan(auth()->user(), 'delete'), 403, 'Lớp học này nằm ngoài phạm vi bạn được quản lý.');
        $className = $class->name;
        $classCode = $class->code;

        $activeEnrollments = $class->enrollments()->whereIn('status', ['pending', 'completed'])->count();
        $scheduledSessions = ClassSession::where('class_id', $class->id)->where('status', 'scheduled')
            ->whereDate('date', '>=', today()->toDateString())->count();
        if ($activeEnrollments > 0 || $scheduledSessions > 0) {
            // Báo lỗi ngay trên màn đang đứng thay vì trang lỗi 422 trắng.
            return redirect()->back()->with('error',
                "Không thể xóa lớp '{$className}' ({$classCode}) vì còn {$activeEnrollments} học viên đang xếp lớp và {$scheduledSessions} buổi học chưa diễn ra. Hãy chuyển lớp sang trạng thái Đã hủy thay vì xóa.");
        }

        $class->delete(); // SoftDelete

        return redirect()->route('classes.index')
            ->with('success', "Đã xóa lớp học '{$className}' ({$classCode}) thành công.");
    }

    // Stubs for remaining observation and evaluation features
    public function qaObservation(Request $request)
    {
        return view('classes.qa-observation');
    }

    public function checklist(Request $request)
    {
        return view('classes.checklist');
    }

    public function evaluateObservation(Request $request)
    {
        return view('classes.evaluate-observation');
    }

    public function checkAvailability(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'exclude_class_id' => ['nullable', 'integer'],
        ]);
        // Cột time lưu dạng H:i:s — so sánh chuỗi với H:i sẽ coi hai ca nối tiếp (kết thúc 18:00, bắt đầu 18:00) là trùng.
        $startTime = $validated['start_time'].':00';
        $endTime = $validated['end_time'].':00';

        // Buổi trùng giờ trong ngày (bỏ qua buổi đã hủy — phòng/nhân sự của buổi hủy có thể tái sử dụng).
        // Phòng chỉ tính trong cùng chi nhánh; nhân sự (GV chính, GVNN, trợ giảng) tính trên mọi chi nhánh.
        $overlapping = ClassSession::query()
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', $validated['date'])
            ->when($validated['exclude_class_id'] ?? null, fn ($query, $classId) => $query->where('class_id', '!=', $classId))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->get(['id', 'class_id', 'branch_id', 'room', 'teacher_id', 'foreign_teacher_id', 'assistant_id']);

        $occupiedRooms = $overlapping->where('branch_id', (int) ($validated['branch_id'] ?? 0))
            ->pluck('room')->filter()->unique()->values()->all();
        $occupiedStaff = $overlapping
            ->flatMap(fn (ClassSession $session) => [$session->teacher_id, $session->foreign_teacher_id, $session->assistant_id])
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return response()->json([
            'occupied_rooms' => $occupiedRooms,
            'occupied_teachers' => $occupiedStaff,
            'occupied_assistants' => $overlapping->pluck('assistant_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'occupied_foreign_teachers' => $overlapping->pluck('foreign_teacher_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ]);
    }

    /**
     * Tài khoản cổng học viên (không quản lý lớp) không được mở màn quản lý lớp;
     * họ xem lớp của mình qua cổng học viên.
     */
    private function ensureCanBrowseClasses(): void
    {
        $user = auth()->user();
        abort_if(! $user || (! ClassModel::userManagesAll($user) && $user->can('portal.student')), 403);
    }
}
