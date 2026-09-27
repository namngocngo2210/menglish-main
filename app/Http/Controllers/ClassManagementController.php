<?php

namespace App\Http\Controllers;

use App\Support\DataScope;
use App\Support\Rbac;
use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\User;
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
     * Flow 1 - Bước #1: Đặt lịch khách học thử vào buổi popup
     * Khớp 100% UI: 01_Web_Admin/12_dat_lich_hoc_thu_popup
     */
    public function trialBooking(Request $request)
    {
        $branches = Branch::where('is_active', true)->get();
        $selectedBranchId = $request->query('branch_id', $branches->first()?->id);

        $this->ensureCanBrowseClasses();
        $classesQuery = ClassModel::with(['course', 'branch', 'teacher'])->visibleTo(auth()->user())->where('status', '!=', 'cancelled');
        if ($selectedBranchId) {
            $classesQuery->where('branch_id', $selectedBranchId);
        }
        $classes = $classesQuery->get();

        // Thông tin khách truyền từ CRM (không có thì để trống cho người dùng nhập)
        $customerName = (string) $request->query('customer_name', '');
        $customerLevel = (string) $request->query('customer_level', '');
        $customerBranch = $branches->firstWhere('id', $selectedBranchId)?->name ?? '';

        // Lấy lịch sử đặt học thử đã lưu
        $bookings = AcademicRecord::where('screen_key', '01_Web_Admin/12_dat_lich_hoc_thu_popup')
            ->latest()
            ->take(10)
            ->get();

        // Buổi học thực tế đã lên lịch (sắp diễn ra) của chi nhánh đang chọn —
        // thay cho 3 buổi mockup cứng tháng 10/2023 trước đây.
        $upcomingSessions = ClassSession::query()
            ->with(['classModel:id,name,code,course_id,branch_id', 'classModel.course:id,name'])
            ->where('status', 'scheduled')
            ->whereDate('date', '>=', today())
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
            ->orderBy('date')->orderBy('start_time')
            ->take(24)
            ->get();

        return view('classes.trial-booking', compact(
            'classes',
            'branches',
            'selectedBranchId',
            'customerName',
            'customerLevel',
            'customerBranch',
            'bookings',
            'upcomingSessions'
        ));
    }

    /**
     * Xử lý xác nhận đặt lịch học thử
     */
    public function trialBookingStore(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'nullable|integer',
            'class_name' => 'required|string',
            'session_time' => 'required|string',
            'customer_name' => 'required|string',
            'customer_level' => 'nullable|string',
            'branch_name' => 'nullable|string',
        ]);

        $record = AcademicRecord::create([
            'screen_key' => '01_Web_Admin/12_dat_lich_hoc_thu_popup',
            'module' => 'classes',
            'record_code' => 'TRIAL-'.strtoupper(Str::random(6)),
            'title' => 'Lịch học thử: '.$validated['customer_name'].' ('.$validated['class_name'].')',
            'status' => 'confirmed',
            'data' => [
                'customer_name' => $validated['customer_name'],
                'customer_level' => $validated['customer_level'] ?? null,
                'class_name' => $validated['class_name'],
                'class_id' => $validated['class_id'] ?? null,
                'session_time' => $validated['session_time'],
                'branch_name' => $validated['branch_name'] ?? null,
                'booked_at' => now()->toDateTimeString(),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('classes.trial-booking')
            ->with('success', 'Đã đặt lịch học thử thành công cho học viên '.$validated['customer_name'].' vào buổi '.$validated['session_time']);
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
            'tro_giang' => 'nullable|integer|exists:users,id',
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
        $assistantId = ! empty($validated['tro_giang']) && is_numeric($validated['tro_giang']) ? (int) $validated['tro_giang'] : null;
        $foreignTeacherId = ! empty($validated['giao_vien_nn']) && is_numeric($validated['giao_vien_nn']) ? (int) $validated['giao_vien_nn'] : null;
        $defaultRoom = $validated['phong_hoc'] ?? null;

        // Chặn trùng phòng / trùng nhân sự với các buổi đã có của lớp khác
        $this->assertNoScheduleConflicts($scheduleSessions, (int) $branchId, array_filter([$teacherId, $assistantId, $foreignTeacherId]), $defaultRoom);

        // Tạo lớp học + các buổi học trong một transaction để không sót lớp rỗng khi lịch lỗi
        $class = DB::transaction(function () use ($validated, $branchId, $code, $course, $teacherId, $assistantId, $foreignTeacherId, $defaultRoom, $scheduleSessions) {
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
                'assistant_id' => $assistantId,
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
                    'assistant_id' => $class->assistant_id,
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
        if ($new['teacher'] && (int) $new['teacher'] !== (int) ($class->teacher_id ?? $class->foreign_teacher_id)) {
            $checks[$new['teacher_field']] = [$sessions, [$new['teacher']]];
        }
        // GVNN được lưu riêng trên từng buổi nên phải kiểm tra trùng cả khi lớp đã có GV chính.
        if (($new['foreign'] ?? null) && (int) $new['foreign'] !== (int) $class->foreign_teacher_id) {
            $checks['giao_vien_nn'] = [$sessions, [$new['foreign']]];
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
            ->with(['branch', 'teacher', 'assistant'])
            ->when($programFilter, fn ($q) => $q->where('program', $programFilter))
            ->when($levelFilter, fn ($q) => $q->where('level', $levelFilter))
            // Tiến độ thật: số buổi (không tính buổi hủy) và số buổi đã diễn ra.
            ->withCount([
                'sessions as total_sessions_count' => fn ($q) => $q->where('status', '!=', 'cancelled'),
                'sessions as done_sessions_count' => fn ($q) => $q->where('status', '!=', 'cancelled')->whereDate('date', '<=', today()),
            ])
            ->orderByRaw("CASE status WHEN 'pending_schedule' THEN 0 WHEN 'upcoming' THEN 1 WHEN 'active' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END")
            ->orderBy('code')
            ->paginate(20)->withQueryString();
        ClassModel::loadRosterCounts($classes->getCollection());

        $classIds = $classes->pluck('id');
        $bigTests = \App\Models\BigTest::whereIn('class_id', $classIds)->orderBy('scheduled_at')
            ->get(['id', 'class_id', 'title', 'scheduled_at', 'status'])->groupBy('class_id');
        $nextSessions = ClassSession::whereIn('class_id', $classIds)->where('status', '!=', 'cancelled')
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

        $activeSessions = $class->sessions()->where('status', '!=', 'cancelled');
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
                ->latest('session_date')->latest('id')->get()->keyBy('class_session_id');
            $data['canRecordAttendance'] = $viewer->can('attendance_student.record') || $viewer->can('attendance_student.record_any');
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
        $this->assertStaffChangeHasNoConflicts($class, $futureSessions, $branchId, [
            'teacher' => $newTeacherId ?? $newForeignTeacherId,
            'teacher_field' => $newTeacherId ? 'giao_vien_chinh' : 'giao_vien_nn',
            'assistant' => $newAssistantId,
            'foreign' => $newForeignTeacherId,
            'room' => $newRoom,
            'previous_room' => $previousRoom,
        ]);

        DB::transaction(function () use ($class, $validated, $code, $branchId, $course, $newTeacherId, $newAssistantId, $newForeignTeacherId, $newRoom, $previousRoom, $futureSessions) {
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
            if ($class->wasChanged('teacher_id') || $class->wasChanged('foreign_teacher_id')) {
                $futureQuery()->update([
                    'teacher_id' => $class->teacher_id ?? $class->foreign_teacher_id,
                    'foreign_teacher_id' => $class->foreign_teacher_id,
                ]);
            }
            if ($class->wasChanged('assistant_id')) {
                $futureQuery()->update(['assistant_id' => $class->assistant_id]);
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
        $scheduledSessions = ClassSession::where('class_id', $class->id)->where('status', 'scheduled')->count();
        abort_if($activeEnrollments > 0 || $scheduledSessions > 0, 422,
            "Không thể xóa lớp '{$className}' ({$classCode}) vì còn {$activeEnrollments} học viên đang xếp lớp và {$scheduledSessions} buổi học chưa diễn ra. Hãy chuyển lớp sang trạng thái Đã hủy thay vì xóa.");

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
