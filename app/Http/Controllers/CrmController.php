<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CommissionTier;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\MerchandiseItem;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Promotion;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SystemCategory;
use App\Models\TuitionReceipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class CrmController extends Controller
{
    /**
     * Scope query cho CRM:
     * - Admin / Manager / Học vụ: xem toàn bộ dữ liệu để điều phối và chấm test.
     * - Các vai trò CRM còn lại: chỉ xem Lead được gán phụ trách.
     */
    protected function scopeCustomerQuery(?User $user = null): Builder
    {
        $user = $user ?? Auth::user();
        $query = CrmCustomer::query();

        if ($user) {
            $isGlobalViewer = $user->hasAnyRole(['admin', 'manager', 'academic_staff', 'academic_lead']);
            if (! $isGlobalViewer) {
                $query->where('assigned_user_id', $user->id);
            }
        }

        return $query;
    }

    protected function findScopedCustomer(int|string $id): CrmCustomer
    {
        return $this->scopeCustomerQuery()
            ->where(fn (Builder $query) => $query->where('id', $id)->orWhere('code', $id))
            ->firstOrFail();
    }

    protected function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    protected function assertUniqueLead(string $phone, ?string $email = null, ?int $ignoreId = null): string
    {
        $phoneNormalized = $this->normalizePhone($phone);
        $duplicatePhone = CrmCustomer::query()
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->where('phone_normalized', $phoneNormalized)
            ->exists();

        if ($phoneNormalized === '' || $duplicatePhone) {
            throw ValidationException::withMessages([
                'phone' => $phoneNormalized === ''
                    ? 'Số điện thoại không hợp lệ.'
                    : 'Số điện thoại này đã tồn tại trong CRM.',
            ]);
        }

        if ($email) {
            $duplicateEmail = CrmCustomer::query()
                ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
                ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
                ->exists();
            if ($duplicateEmail) {
                throw ValidationException::withMessages(['email' => 'Email này đã tồn tại trong CRM.']);
            }
        }

        return $phoneNormalized;
    }

    public function pipeline(Request $request)
    {
        $allCustomers = $this->scopeCustomerQuery()
            ->with(['branch', 'assignedUser', 'convertedStudent.tuition'])
            ->latest()
            ->get();

        $stageDefinitions = [
            'new' => ['name' => 'Mới tiếp nhận', 'color' => 'border-sky-500', 'bg_badge' => 'bg-sky-50 text-sky-700'],
            'consulting' => ['name' => 'Tư vấn lộ trình', 'color' => 'border-amber-500', 'bg_badge' => 'bg-amber-50 text-amber-700'],
            'test_scheduled' => ['name' => 'Hẹn Test', 'color' => 'border-indigo-500', 'bg_badge' => 'bg-indigo-50 text-indigo-700'],
            'tested' => ['name' => 'Đã Test đầu vào', 'color' => 'border-purple-500', 'bg_badge' => 'bg-purple-50 text-purple-700'],
            'trial_scheduled' => ['name' => 'Hẹn học thử', 'color' => 'border-fuchsia-500', 'bg_badge' => 'bg-fuchsia-50 text-fuchsia-700'],
            'trial_completed' => ['name' => 'Phản hồi học thử', 'color' => 'border-teal-500', 'bg_badge' => 'bg-teal-50 text-teal-700'],
            'waiting_class' => ['name' => 'Chờ xếp lớp', 'color' => 'border-yellow-500', 'bg_badge' => 'bg-yellow-50 text-yellow-700'],
            'closing' => ['name' => 'Chờ thanh toán', 'color' => 'border-cyan-500', 'bg_badge' => 'bg-cyan-50 text-cyan-700'],
            'won' => ['name' => 'Đã chốt (Won)', 'color' => 'border-emerald-500', 'bg_badge' => 'bg-emerald-50 text-emerald-700'],
        ];

        $stages = [];
        foreach ($stageDefinitions as $key => $meta) {
            $group = $allCustomers->where('stage', $key);
            $totalAmount = $group->sum('deal_value');

            $stages[] = [
                'id' => $key,
                'name' => $meta['name'],
                'count' => $group->count(),
                'amount' => number_format($totalAmount).'đ',
                'color' => $meta['color'],
                'bg_badge' => $meta['bg_badge'],
                'leads' => $group->map(fn (CrmCustomer $c) => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'phone' => $c->phone,
                    'source' => $c->source ?? 'Trực tiếp',
                    'course' => $c->course_interest ?? 'Chưa chọn khóa',
                    'tuition' => number_format($c->deal_value).'đ',
                    'agent' => $c->assignedUser?->name ?? 'Chưa phân công',
                    'days' => $c->created_at->diffForHumans(),
                    'score' => $c->test_score ?? 'Chưa test',
                    'status' => $c->stage === 'won' ? ($c->convertedStudent?->tuition?->status_label ?? 'Chưa có học phí') : null,
                    'payment_status' => $c->convertedStudent?->tuition?->status,
                ])->values()->all(),
            ];
        }

        return view('crm.pipeline', compact('stages'));
    }

    public function customers(Request $request)
    {
        $query = $this->scopeCustomerQuery()->with(['branch', 'assignedUser'])->latest();

        if ($search = $request->input('search')) {
            // Tìm thêm trên phone_normalized để gõ SĐT có khoảng trắng/gạch vẫn khớp.
            $digits = $this->normalizePhone($search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->when($digits !== '', fn (Builder $phoneQuery) => $phoneQuery
                        ->orWhere('phone_normalized', 'like', "%{$digits}%"));
            });
        }

        if ($stage = $request->input('stage')) {
            $query->where('stage', $stage);
        }

        if ($branchId = $request->input('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        $dbCustomers = $query->paginate($request->perPage(15))->withQueryString();
        $branches = Branch::all();

        return view('crm.customers', [
            'customers' => $dbCustomers,
            'branches' => $branches,
        ]);
    }

    public function waitingList()
    {
        $waitingLeads = $this->scopeCustomerQuery()
            ->with(['assignedUser', 'waitingCourse', 'waitingBranch'])
            ->where('stage', 'waiting_class')
            ->orderByDesc('waiting_priority')
            ->orderBy('waiting_since')
            ->get();

        $classes = ClassModel::query()
            ->with(['course', 'branch'])
            ->withCount(['enrollments as active_enrollments_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'completed'])])
            ->whereIn('status', ['active', 'upcoming'])
            ->get();

        $matchingClassesByLead = $waitingLeads->mapWithKeys(function (CrmCustomer $lead) use ($classes) {
            $matches = $classes->filter(fn (ClassModel $class) => $class->course_id === $lead->waiting_course_id
                && $class->branch_id === $lead->waiting_branch_id
                && ($class->max_capacity <= 0 || $class->active_enrollments_count < $class->max_capacity)
            )->values();

            return [$lead->id => $matches];
        });

        return view('crm.waiting-list', compact('waitingLeads', 'matchingClassesByLead'));
    }

    public function createCustomer()
    {
        $branches = Branch::where('is_active', true)->get();
        if ($branches->isEmpty()) {
            $branches = Branch::all();
        }
        $salesUsers = User::role('sales_consultant')->get();
        if ($salesUsers->isEmpty()) {
            $salesUsers = User::where('is_active', true)->get();
        }
        $leadSources = SystemCategory::where('type', 'lead_source')->orderBy('sort_order')->pluck('name');
        if ($leadSources->isEmpty()) {
            $leadSources = collect(['Facebook Ads', 'Tiktok Organic', 'Google Ads', 'Bạn bè giới thiệu', 'Sự kiện Offline', 'Website / Hotline', 'Khác']);
        }

        return view('crm.create', compact('branches', 'salesUsers', 'leadSources'));
    }

    public function storeCustomer(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'parent_name' => 'nullable|string|max:255',
            'source' => 'required|string|max:255',
            'branch_id' => 'required|exists:branches,id',
            'assigned_user_id' => 'nullable|exists:users,id',
            'email' => 'nullable|email|max:255',
            'dob' => 'nullable|date',
            'gender' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'course_interest' => 'nullable|string|max:255',
            'deal_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ], [
            'name.required' => 'Vui lòng nhập họ và tên khách hàng.',
            'phone.required' => 'Vui lòng nhập số điện thoại.',
            'email.email' => 'Địa chỉ email không đúng định dạng.',
        ]);
        $validated['phone_normalized'] = $this->assertUniqueLead($validated['phone'], $validated['email'] ?? null);
        $canAssign = $request->user()->can('lead.assign');
        if ($canAssign && ! empty($validated['assigned_user_id'])) {
            $assignee = User::find($validated['assigned_user_id']);
            if (! $assignee?->is_active || ! $assignee->hasAnyRole(['admin', 'sales_consultant', 'manager'])) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Người phụ trách phải là Sales hoặc quản lý đang hoạt động.']);
            }
        }

        $code = CrmCustomer::generateCode();

        try {
            $customer = CrmCustomer::create([
                'code' => $code,
                'name' => $validated['name'],
                'parent_name' => $validated['parent_name'] ?? null,
                'phone' => $validated['phone'],
                'phone_normalized' => $validated['phone_normalized'],
                'email' => $validated['email'] ?? null,
                'dob' => $validated['dob'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'address' => $validated['address'] ?? null,
                'branch_id' => $validated['branch_id'],
                'course_interest' => $validated['course_interest'] ?? null,
                'source' => $validated['source'],
                'assigned_user_id' => $canAssign ? ($validated['assigned_user_id'] ?? Auth::id()) : Auth::id(),
                'stage' => 'new',
                'deal_value' => $validated['deal_value'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Hai phiên tạo lead cùng lúc: ràng buộc UNIQUE ở DB là chốt chặn cuối.
            throw ValidationException::withMessages(['phone' => 'Số điện thoại hoặc email này vừa được tạo bởi phiên khác. Vui lòng kiểm tra lại.']);
        }

        // Tạo log nhật ký đầu tiên
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => 'system',
            'content' => 'Thêm mới khách hàng vào hệ thống CRM qua form nhập liệu (Nguồn: '.($customer->source ?? 'Trực tiếp').').',
        ]);

        return redirect()->route('crm.customers.show', $customer->id)
            ->with('status', "Đã thêm khách hàng {$customer->name} ({$customer->code}) thành công vào Cơ sở dữ liệu!");
    }

    public function showCustomer($id)
    {
        $customer = $this->scopeCustomerQuery()
            ->with(['branch', 'assignedUser', 'assignedTest', 'examiner', 'trialTeacher', 'trialClass', 'waitingCourse', 'waitingBranch', 'histories.user', 'submissions.test', 'submissions.grader', 'latestSubmission'])
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('code', $id);
            })
            ->firstOrFail();

        $latestSubmission = $customer->latestSubmission ?? $customer->submissions->first();

        $placementTests = PlacementTest::where('is_active', true)->get();
        $examiners = User::where('is_active', true)
            ->role(['admin', 'manager', 'academic_staff', 'academic_lead', 'teacher', 'teacher_fulltime', 'teacher_parttime'])
            ->get();
        $trialClasses = ClassModel::whereIn('status', ['active', 'upcoming'])->with(['course', 'branch'])->get();
        $courses = Course::where('is_active', true)->orderBy('name')->get();
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        return view('crm.show', compact('customer', 'placementTests', 'examiners', 'latestSubmission', 'trialClasses', 'courses', 'branches'));
    }

    public function saveTestScore(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        if (! in_array($customer->stage, ['consulting', 'test_scheduled', 'tested'], true)) {
            throw ValidationException::withMessages(['placement_test_id' => 'Lead phải ở bước tư vấn hoặc luồng test để nhập điểm.']);
        }

        $validated = $request->validate([
            'listening_score' => 'required|numeric|min:0|max:100',
            'reading_score' => 'required|numeric|min:0|max:100',
            'speaking_score' => 'required|numeric|min:0|max:100',
            'writing_score' => 'nullable|numeric|min:0|max:100',
            'cefr_level' => 'nullable|string|max:50',
            'recommended_course' => 'nullable|string|max:255',
            'teacher_comments' => 'nullable|string|max:1000',
            'placement_test_id' => 'nullable|exists:placement_tests,id',
            'submission_id' => 'nullable|integer|exists:placement_test_submissions,id',
        ]);

        $listening = (float) $validated['listening_score'];
        $reading = (float) $validated['reading_score'];
        $speaking = (float) $validated['speaking_score'];
        $writing = isset($validated['writing_score']) && $validated['writing_score'] !== '' ? (float) $validated['writing_score'] : $reading;
        $overall = round(($listening + $reading + $speaking + $writing) / 4, 1);

        $submission = null;
        if (! empty($validated['submission_id'])) {
            $submission = PlacementTestSubmission::query()
                ->where('customer_id', $customer->id)
                ->find($validated['submission_id']);
            if (! $submission) {
                throw ValidationException::withMessages(['submission_id' => 'Lần thi cần sửa không thuộc Lead này.']);
            }
        }

        $testId = $submission?->placement_test_id
            ?? $validated['placement_test_id']
            ?? $customer->assigned_test_id
            ?? PlacementTest::first()?->id;
        if (! $testId) {
            throw ValidationException::withMessages(['placement_test_id' => 'Chưa có đề kiểm tra đầu vào để chấm điểm.']);
        }

        if (! $submission) {
            $submission = PlacementTestSubmission::query()
                ->where('customer_id', $customer->id)
                ->where('placement_test_id', $testId)
                ->where('status', 'pending')
                ->latest()
                ->first();
        }
        $submissionData = [
            'placement_test_id' => $testId,
            'customer_id' => $customer->id,
            'candidate_name' => $customer->name,
            'candidate_phone' => $customer->phone,
            'candidate_email' => $customer->email,
            'listening_score' => $listening,
            'reading_score' => $reading,
            'writing_score' => $writing,
            'speaking_score' => $speaking,
            'overall_score' => $overall,
            'cefr_level' => $validated['cefr_level'] ?? 'B1',
            'recommended_course' => $validated['recommended_course'] ?? ($customer->course_interest ?? null),
            'teacher_comments' => $validated['teacher_comments'] ?? null,
            'grader_id' => Auth::id() ?? $customer->assigned_user_id,
            'status' => 'graded',
        ];
        $submission = $submission
            ? tap($submission)->update($submissionData)
            : PlacementTestSubmission::create($submissionData);

        $cefr = $validated['cefr_level'] ?? null;
        $customer->update([
            'test_score' => $cefr ? "{$overall} ({$cefr})" : (string) $overall,
            'test_decision' => 'test',
            'stage' => in_array($customer->stage, ['consulting', 'test_scheduled', 'tested'], true) ? 'tested' : $customer->stage,
        ]);

        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => 'test',
            'content' => 'Đã ghi nhận kết quả điểm test đầu vào: '.$overall.' Band'.($cefr ? " ({$cefr})" : '').' · Khóa đề xuất: '.($validated['recommended_course'] ?? ($customer->course_interest ?? 'Chưa đề xuất')),
        ]);

        return redirect()->back()->with('status', 'Đã ghi nhận và cập nhật điểm test đầu vào thành công!');
    }

    public function schedulePlacementTest(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        if (! in_array($customer->stage, ['consulting', 'test_scheduled', 'tested'], true)) {
            throw ValidationException::withMessages(['appointment_date' => 'Lead phải ở bước tư vấn hoặc luồng test để đặt lịch.']);
        }

        $validated = $request->validate([
            'appointment_date' => 'required|date|after_or_equal:today',
            'appointment_time' => 'required|date_format:H:i',
            'appointment_type' => 'required|string|in:online,offline',
            'assigned_test_id' => 'nullable|exists:placement_tests,id',
            'examiner_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string|max:500',
        ]);

        if (! empty($validated['examiner_id'])) {
            $examiner = User::find($validated['examiner_id']);
            if (! $examiner?->is_active || ! $examiner->hasAnyRole(['admin', 'manager', 'academic_staff', 'academic_lead', 'teacher', 'teacher_fulltime', 'teacher_parttime'])) {
                throw ValidationException::withMessages(['examiner_id' => 'Người chấm phải thuộc bộ phận học vụ hoặc giáo viên đang hoạt động.']);
            }
        }

        $appointmentAt = Carbon::parse("{$validated['appointment_date']} {$validated['appointment_time']}:00");
        if (! $appointmentAt->isFuture()) {
            throw ValidationException::withMessages(['appointment_time' => 'Lịch test phải ở thời điểm trong tương lai.']);
        }

        if (! empty($validated['examiner_id'])) {
            $examiner = User::findOrFail($validated['examiner_id']);
            if ($customer->branch_id && $examiner->branch_id && $customer->branch_id !== $examiner->branch_id) {
                throw ValidationException::withMessages(['examiner_id' => 'Người chấm phải thuộc cùng chi nhánh với Lead.']);
            }

            $conflictStart = $appointmentAt->copy()->subMinutes(59);
            $conflictEnd = $appointmentAt->copy()->addMinutes(59);
            $sessionConflict = ClassSession::query()
                ->where('teacher_id', $examiner->id)
                ->whereDate('date', $appointmentAt->toDateString())
                ->where('status', '!=', 'cancelled')
                ->where('start_time', '<', $appointmentAt->copy()->addHour()->format('H:i:s'))
                ->where('end_time', '>', $appointmentAt->format('H:i:s'))
                ->exists();
            $hasConflict = CrmCustomer::query()
                ->whereKeyNot($customer->id)
                ->whereNotIn('stage', ['won', 'lost'])
                ->where(function (Builder $query) use ($examiner, $conflictStart, $conflictEnd) {
                    $query->where(fn (Builder $testQuery) => $testQuery
                        ->where('examiner_id', $examiner->id)
                        ->whereBetween('appointment_at', [$conflictStart, $conflictEnd]))
                        ->orWhere(fn (Builder $trialQuery) => $trialQuery
                            ->where('trial_teacher_id', $examiner->id)
                            ->whereBetween('trial_at', [$conflictStart, $conflictEnd]));
                })
                ->exists();
            if ($hasConflict || $sessionConflict) {
                throw ValidationException::withMessages(['appointment_time' => 'Người chấm đã có lịch khác trong khung giờ này.']);
            }
        }

        $appointmentDateTime = $appointmentAt->format('Y-m-d H:i:s');

        $customer->update([
            'appointment_at' => $appointmentDateTime,
            'appointment_type' => $validated['appointment_type'],
            'assigned_test_id' => $validated['assigned_test_id'] ?? null,
            'examiner_id' => $validated['examiner_id'] ?? null,
            'test_decision' => 'test',
            'stage' => 'test_scheduled',
        ]);

        $test = $customer->assignedTest;
        $testTitle = $test ? $test->title : 'Bài Test Chuẩn Hóa MEnglish';
        $typeLabel = $validated['appointment_type'] === 'online' ? 'Trực tuyến (Online)' : 'Tại cơ sở (Offline)';

        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => 'test',
            'content' => "Đã đặt lịch hẹn test đầu vào: {$typeLabel} lúc ".date('d/m/Y H:i', strtotime($appointmentDateTime))." [{$testTitle}]. Ghi chú: ".($validated['notes'] ?? 'Không có'),
        ]);

        return redirect()->back()
            ->with('status', "Đã đặt lịch hẹn test thành công cho khách hàng {$customer->name} vào lúc ".date('d/m/Y H:i', strtotime($appointmentDateTime)).'!');
    }

    public function scheduleTrial(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        if (! in_array($customer->stage, ['tested', 'trial_scheduled'], true)) {
            throw ValidationException::withMessages(['trial_date' => 'Lead phải hoàn tất bài test trước khi hẹn học thử.']);
        }
        $validated = $request->validate([
            'trial_date' => 'required|date|after_or_equal:today',
            'trial_time' => 'required|date_format:H:i',
            'trial_teacher_id' => 'required|exists:users,id',
            'trial_class_id' => 'nullable|exists:classes,id',
            'trial_mode' => 'required|in:online,offline',
            'notes' => 'nullable|string|max:1000',
        ]);

        $teacher = User::findOrFail($validated['trial_teacher_id']);
        if (! $teacher->is_active || ! $teacher->hasAnyRole(['admin', 'manager', 'academic_staff', 'academic_lead', 'teacher', 'teacher_fulltime', 'teacher_parttime'])) {
            throw ValidationException::withMessages(['trial_teacher_id' => 'Giáo viên học thử không hợp lệ hoặc đã ngừng hoạt động.']);
        }

        $trialAt = Carbon::parse($validated['trial_date'].' '.$validated['trial_time']);
        if (! $trialAt->isFuture()) {
            throw ValidationException::withMessages(['trial_time' => 'Lịch học thử phải ở thời điểm trong tương lai.']);
        }
        $trialClass = ! empty($validated['trial_class_id'])
            ? ClassModel::whereIn('status', ['active', 'upcoming'])->find($validated['trial_class_id'])
            : null;
        if (! empty($validated['trial_class_id']) && ! $trialClass) {
            throw ValidationException::withMessages(['trial_class_id' => 'Lớp học thử không còn khả dụng.']);
        }
        if ($customer->branch_id && $teacher->branch_id && $customer->branch_id !== $teacher->branch_id) {
            throw ValidationException::withMessages(['trial_teacher_id' => 'Giáo viên học thử phải thuộc cùng chi nhánh với Lead.']);
        }
        if ($trialClass && $customer->branch_id && $trialClass->branch_id !== $customer->branch_id) {
            throw ValidationException::withMessages(['trial_class_id' => 'Lớp học thử phải thuộc cùng chi nhánh với Lead.']);
        }

        $conflictStart = $trialAt->copy()->subMinutes(59);
        $conflictEnd = $trialAt->copy()->addMinutes(59);
        $teacherConflict = CrmCustomer::query()
            ->whereKeyNot($customer->id)
            ->whereNotIn('stage', ['won', 'lost'])
            ->where(function (Builder $query) use ($teacher, $conflictStart, $conflictEnd) {
                $query->where(fn (Builder $trialQuery) => $trialQuery
                    ->where('trial_teacher_id', $teacher->id)
                    ->whereBetween('trial_at', [$conflictStart, $conflictEnd]))
                    ->orWhere(fn (Builder $testQuery) => $testQuery
                        ->where('examiner_id', $teacher->id)
                        ->whereBetween('appointment_at', [$conflictStart, $conflictEnd]));
            })
            ->exists();
        $sessionConflict = ClassSession::query()
            ->where('teacher_id', $teacher->id)
            ->whereDate('date', $trialAt->toDateString())
            ->where('status', '!=', 'cancelled')
            ->where('start_time', '<', $trialAt->copy()->addHour()->format('H:i:s'))
            ->where('end_time', '>', $trialAt->format('H:i:s'))
            ->exists();
        if ($teacherConflict || $sessionConflict) {
            throw ValidationException::withMessages(['trial_time' => 'Giáo viên đã có lịch khác trong khung giờ này.']);
        }
        if ($trialClass) {
            $classConflict = ClassSession::query()
                ->where('class_id', $trialClass->id)
                ->whereDate('date', $trialAt->toDateString())
                ->where('status', '!=', 'cancelled')
                ->where('start_time', '<', $trialAt->copy()->addHour()->format('H:i:s'))
                ->where('end_time', '>', $trialAt->format('H:i:s'))
                ->exists();
            if ($classConflict) {
                throw ValidationException::withMessages(['trial_class_id' => 'Lớp đã có buổi học trong khung giờ này.']);
            }
        }
        $customer->update([
            'trial_at' => $trialAt,
            'trial_teacher_id' => $teacher->id,
            'trial_class_id' => $trialClass?->id,
            'trial_mode' => $validated['trial_mode'],
            'trial_status' => 'scheduled',
            'trial_notes' => $validated['notes'] ?? null,
            'test_decision' => 'test',
            'stage' => 'trial_scheduled',
        ]);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => 'meet',
            'content' => 'Đã hẹn học thử '.($validated['trial_mode'] === 'online' ? 'online' : 'tại trung tâm').' lúc '.$trialAt->format('d/m/Y H:i').' với '.$teacher->name.($trialClass ? ' · Lớp '.$trialClass->name : '').'. '.($validated['notes'] ?? ''),
        ]);

        return redirect()->back()->with('status', 'Đã lưu lịch học thử.');
    }

    public function saveTrialFeedback(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        if (! in_array($customer->stage, ['trial_scheduled', 'trial_completed'], true)) {
            throw ValidationException::withMessages(['trial_feedback' => 'Lead chưa có lịch học thử để ghi nhận phản hồi.']);
        }

        $validated = $request->validate([
            'trial_rating' => 'required|integer|min:1|max:5',
            'trial_feedback' => 'required|string|max:3000',
        ]);
        $customer->update($validated + ['stage' => 'trial_completed', 'trial_status' => 'attended']);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => 'meet',
            'content' => "Phản hồi học thử ({$validated['trial_rating']}/5): {$validated['trial_feedback']}",
        ]);

        return redirect()->back()->with('status', 'Đã ghi nhận phản hồi buổi học thử.');
    }

    public function updateTrialStatus(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        if ($customer->stage !== 'trial_scheduled') {
            throw ValidationException::withMessages(['trial_status' => 'Lead không có lịch học thử đang chờ xử lý.']);
        }

        $validated = $request->validate([
            'trial_status' => 'required|in:cancelled,no_show',
            'reason' => 'required|string|max:1000',
        ]);
        $label = $validated['trial_status'] === 'cancelled' ? 'đã hủy' : 'vắng mặt';
        $customer->update([
            'trial_status' => $validated['trial_status'],
            'stage' => $customer->test_decision === 'test' && $customer->test_score ? 'tested' : 'consulting',
            'trial_notes' => trim(($customer->trial_notes ? $customer->trial_notes."\n" : '')."{$label}: {$validated['reason']}"),
        ]);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => 'meet',
            'content' => "Buổi học thử {$label}. Lý do: {$validated['reason']}",
        ]);

        return redirect()->back()->with('status', "Đã ghi nhận buổi học thử {$label}; Lead quay lại bước tư vấn để hẹn lại.");
    }

    public function addToWaitingList(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        if (! in_array($customer->stage, ['consulting', 'waiting_class'], true)) {
            throw ValidationException::withMessages(['preferred_schedule' => 'Không thể đưa Lead đã kết thúc vào danh sách chờ.']);
        }
        $validated = $request->validate([
            'preferred_schedule' => 'required|string|max:255',
            'waiting_course_id' => 'required|exists:courses,id',
            'waiting_branch_id' => 'required|exists:branches,id',
            'desired_start_date' => 'nullable|date|after_or_equal:today',
            'waiting_priority' => 'required|integer|min:1|max:5',
            'waiting_notes' => 'nullable|string|max:1000',
        ]);
        if (! Course::whereKey($validated['waiting_course_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['waiting_course_id' => 'Khóa học chờ không còn hoạt động.']);
        }
        if (! Branch::whereKey($validated['waiting_branch_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['waiting_branch_id' => 'Cơ sở chờ không còn hoạt động.']);
        }
        $customer->update([
            'stage' => 'waiting_class',
            'test_decision' => 'no_test',
            'waiting_since' => today(),
            'preferred_schedule' => $validated['preferred_schedule'],
            'waiting_course_id' => $validated['waiting_course_id'],
            'waiting_branch_id' => $validated['waiting_branch_id'],
            'desired_start_date' => $validated['desired_start_date'] ?? null,
            'waiting_priority' => $validated['waiting_priority'],
            'waiting_notes' => $validated['waiting_notes'] ?? null,
        ]);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => 'system',
            'content' => 'Đưa vào danh sách chờ lớp: '.$validated['preferred_schedule'].' · Ưu tiên '.$validated['waiting_priority'].'/5.',
        ]);

        return redirect()->back()->with('status', 'Đã đưa Lead vào danh sách chờ lớp.');
    }

    public function editCustomer($id)
    {
        $customer = $this->findScopedCustomer($id);
        $branches = Branch::all();
        $salesUsers = User::role('sales_consultant')->where('is_active', true)->get();
        $leadSources = SystemCategory::where('type', 'lead_source')->orderBy('sort_order')->pluck('name');
        if ($leadSources->isEmpty()) {
            $leadSources = collect(['Facebook Ads', 'Tiktok Organic', 'Google Ads', 'Bạn bè giới thiệu', 'Sự kiện Offline', 'Website / Hotline', 'Khác']);
        }

        return view('crm.edit', compact('customer', 'branches', 'salesUsers', 'leadSources'));
    }

    public function updateCustomer(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'parent_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'dob' => 'nullable|date',
            'gender' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'branch_id' => 'required|exists:branches,id',
            'course_interest' => 'nullable|string|max:255',
            'source' => 'required|string|max:255',
            'assigned_user_id' => 'nullable|exists:users,id',
            'deal_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);
        $validated['phone_normalized'] = $this->assertUniqueLead($validated['phone'], $validated['email'] ?? null, $customer->id);
        if ($request->user()->can('lead.assign') && ! empty($validated['assigned_user_id'])) {
            $assignee = User::find($validated['assigned_user_id']);
            if (! $assignee?->is_active || ! $assignee->hasAnyRole(['admin', 'sales_consultant', 'manager'])) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Người phụ trách phải là Sales hoặc quản lý đang hoạt động.']);
            }
        }
        if (! $request->user()->can('lead.assign')) {
            unset($validated['assigned_user_id']);
        }
        try {
            $customer->update($validated);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => 'Số điện thoại hoặc email này vừa được phiên khác sử dụng. Vui lòng kiểm tra lại.']);
        }

        return redirect()->route('crm.customers.show', $customer->id)
            ->with('status', 'Cập nhật thông tin khách hàng thành công!');
    }

    public function updateStage(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);

        $validated = $request->validate([
            'stage' => 'required|string|in:new,consulting,test_scheduled,tested,trial_scheduled,trial_completed,waiting_class,closing,won,lost',
            'lost_reason' => 'required_if:stage,lost|nullable|string|max:1000',
        ]);

        $newStage = $validated['stage'];
        $currentStage = $customer->stage;

        if (in_array($currentStage, ['won', 'lost'], true) && $newStage !== $currentStage) {
            return $this->stageError($request, 'Lead đã kết thúc; không thể thay đổi giai đoạn.');
        }

        if ($newStage === 'won') {
            return $this->stageError($request, 'Phải hoàn tất Closing Wizard để chốt Lead thành công.');
        }

        if ($newStage === 'lost' && ! $request->user()->can('lead.mark_lost')) {
            abort(403);
        }

        $allowedTransitions = [
            'new' => ['consulting'],
            'consulting' => [],
            'test_scheduled' => [],
            'tested' => [],
            'trial_scheduled' => [],
            'trial_completed' => ['closing'],
            'waiting_class' => ['closing'],
            'closing' => [],
        ];

        if ($newStage !== $currentStage
            && $newStage !== 'lost'
            && ! in_array($newStage, $allowedTransitions[$currentStage] ?? [], true)) {
            return $this->stageError(
                $request,
                'Chuyển giai đoạn không hợp lệ. Hãy dùng đúng thao tác nghiệp vụ: hẹn test, nhập điểm, hẹn học thử, phản hồi hoặc đưa vào danh sách chờ.'
            );
        }

        if ($currentStage !== $newStage) {
            $customer->update([
                'stage' => $newStage,
                'lost_reason' => $newStage === 'lost' ? $validated['lost_reason'] : null,
                'lost_at' => $newStage === 'lost' ? now() : null,
            ]);

            CrmCustomerHistory::create([
                'customer_id' => $customer->id,
                'user_id' => Auth::id(),
                'type' => 'stage_change',
                'content' => "Chuyển giai đoạn Pipeline từ '{$currentStage}' sang '{$customer->stage_label}'",
            ]);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Đã chuyển khách hàng {$customer->name} sang giai đoạn {$customer->stage_label}!",
                'customer' => $customer,
                'stage' => $newStage,
                'stage_label' => $customer->stage_label,
            ]);
        }

        return redirect()->back()->with('status', "Đã chuyển khách hàng {$customer->name} sang giai đoạn {$customer->stage_label}!");
    }

    protected function stageError(Request $request, string $message)
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return redirect()->back()->withErrors(['stage' => $message]);
    }

    public function nextStage(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        $automaticTransitions = [
            'new' => 'consulting',
            'trial_completed' => 'closing',
            'waiting_class' => 'closing',
        ];

        if (isset($automaticTransitions[$customer->stage])) {
            $nextStageKey = $automaticTransitions[$customer->stage];
            $request->merge(['stage' => $nextStageKey]);

            return $this->updateStage($request, $id);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => 'Giai đoạn này cần một thao tác nghiệp vụ cụ thể; vui lòng mở hồ sơ Lead để tiếp tục.',
            ], 422);
        }

        return redirect()->back()->withErrors(['stage' => 'Giai đoạn này cần một thao tác nghiệp vụ cụ thể; vui lòng mở hồ sơ Lead để tiếp tục.']);
    }

    public function destroyCustomer($id)
    {
        $customer = $this->findScopedCustomer($id);
        if ($customer->stage === 'won' || $customer->converted_student_id) {
            throw ValidationException::withMessages([
                'customer' => 'Lead đã chốt phải được lưu để bảo toàn lịch sử tuyển sinh, học phí và hoa hồng.',
            ]);
        }
        $name = $customer->name;
        $customer->delete();

        return redirect()->route('crm.customers.index')
            ->with('status', "Đã xóa khách hàng {$name} khỏi danh sách!");
    }

    public function addNote(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);

        $validated = $request->validate([
            'content' => 'required|string|max:1000',
            'type' => 'required|string|in:call,message,meet,test,note',
        ]);

        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => $validated['type'],
            'content' => $validated['content'],
        ]);

        return redirect()->route('crm.customers.show', $customer->id)
            ->with('status', 'Đã lưu nhật ký chăm sóc thành công!');
    }

    public function wonCustomers()
    {
        $wonCustomers = $this->scopeCustomerQuery()
            ->with(['branch', 'assignedUser', 'convertedStudent.tuition.receipts'])
            ->where('stage', 'won')
            ->latest()
            ->get();

        $totalContractAmount = $wonCustomers->sum('deal_value');
        $totalCollectedAmount = $wonCustomers->sum(fn (CrmCustomer $customer) => (float) ($customer->convertedStudent?->tuition?->paid_amount ?? 0));
        $totalDebtAmount = $wonCustomers->sum(fn (CrmCustomer $customer) => (float) ($customer->convertedStudent?->tuition?->debt_amount ?? 0));

        return view('crm.won', compact('wonCustomers', 'totalContractAmount', 'totalCollectedAmount', 'totalDebtAmount'));
    }

    public function closingWizard(Request $request)
    {
        $selectedCustomerId = $request->integer('customer_id');
        $selectedCustomer = null;
        if ($selectedCustomerId) {
            $selectedCustomer = $this->findScopedCustomer($selectedCustomerId);
            if (! in_array($selectedCustomer->stage, ['trial_completed', 'waiting_class', 'closing'], true)) {
                return redirect()->route('crm.pipeline')
                    ->withErrors(['stage' => 'Lead chưa sẵn sàng để chốt.']);
            }
        }
        $customers = $this->scopeCustomerQuery()
            ->whereIn('stage', ['trial_completed', 'waiting_class', 'closing'])
            ->when($selectedCustomerId, fn (Builder $query, int $customerId) => $query->orderByRaw('id = ? desc', [$customerId]))
            ->latest()
            ->get();
        $branches = Branch::all();
        $courses = Course::where('is_active', true)->get();
        $classes = ClassModel::where('status', 'active')
            ->when($selectedCustomer?->branch_id, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->with(['course', 'branch'])
            ->withCount(['enrollments as active_enrollments_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'completed'])])
            ->get()
            ->filter(fn (ClassModel $class) => $class->max_capacity <= 0 || $class->active_enrollments_count < $class->max_capacity)
            ->values();
        $bankAccounts = BankAccount::where('is_active', true)
            ->when($selectedCustomer?->branch_id, fn (Builder $query, int $branchId) => $query
                ->where(fn (Builder $accountQuery) => $accountQuery->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->get();
        $promotions = Promotion::where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->where(fn (Builder $query) => $query->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->get();
        $merchandiseItems = MerchandiseItem::active()->orderBy('category')->orderBy('name')->get();

        return view('crm.closing-wizard', compact('customers', 'branches', 'courses', 'classes', 'bankAccounts', 'promotions', 'merchandiseItems'));
    }

    public function storePromotion(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:fixed,percent',
            'value' => 'required|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:1000',
            'branch_id' => 'nullable|exists:branches,id',
            'course_id' => 'nullable|exists:courses,id',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'usage_limit' => 'nullable|integer|min:1',
        ]);
        if ($validated['type'] === 'percent' && (float) $validated['value'] > 100) {
            throw ValidationException::withMessages(['value' => 'Ưu đãi phần trăm không được vượt quá 100%.']);
        }
        if (! empty($validated['starts_at']) && ! empty($validated['ends_at'])
            && Carbon::parse($validated['ends_at'])->lte(Carbon::parse($validated['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'Ngày kết thúc ưu đãi phải sau ngày bắt đầu.']);
        }

        // Mã ưu đãi: prefix theo tên + hậu tố ngẫu nhiên; promotions.code là UNIQUE nên phải kiểm tra trùng.
        $namePrefix = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', Str::ascii($validated['name'])), 0, 4));
        do {
            $code = 'UD'.$namePrefix.strtoupper(Str::random(3));
        } while (Promotion::where('code', $code)->exists());

        try {
            $promotion = Promotion::create([
                'code' => $code,
                'name' => $validated['name'],
                'type' => $validated['type'],
                'value' => $validated['value'],
                'max_discount_amount' => $validated['max_discount_amount'] ?? null,
                'description' => $validated['description'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'course_id' => $validated['course_id'] ?? null,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'usage_limit' => $validated['usage_limit'] ?? null,
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Hai phiên tạo ưu đãi trùng mã cùng lúc: ràng buộc UNIQUE ở DB là chốt chặn cuối.
            throw ValidationException::withMessages(['name' => 'Không tạo được ưu đãi do trùng mã, vui lòng thử lại.']);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Đã tạo mới ưu đãi '{$promotion->name}' thành công!",
                'promotion' => $promotion,
            ]);
        }

        return redirect()->back()->with('status', "Đã tạo mới ưu đãi '{$promotion->name}' thành công!");
    }

    public function processClosingWizard(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:crm_customers,id',
            'class_id' => 'required|exists:classes,id',
            'promotion_id' => 'nullable|exists:promotions,id',
            'fee_items' => 'nullable',
            'prepaid_amount' => 'nullable|numeric|min:0',
            'paid_amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,transfer,pos,split',
            'split_cash_amount' => 'nullable|numeric|min:0',
            'split_transfer_amount' => 'nullable|numeric|min:0',
            'split_pos_amount' => 'nullable|numeric|min:0',
            'transfer_memo' => 'nullable|string|max:255',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'bill_notes' => 'nullable|string|max:2000',
        ]);

        $result = DB::transaction(function () use ($request, $validated): array {
            $customerQuery = CrmCustomer::query()->lockForUpdate();
            if (! $request->user()->hasAnyRole(['admin', 'manager', 'academic_staff', 'academic_lead'])) {
                $customerQuery->where('assigned_user_id', $request->user()->id);
            }
            $customer = $customerQuery->findOrFail($validated['customer_id']);

            if ($customer->converted_student_id) {
                $existingTuition = StudentTuition::where('student_id', $customer->converted_student_id)->latest()->firstOrFail();

                return [$customer->convertedStudent, $existingTuition, null, true];
            }
            if ($customer->stage !== 'closing') {
                throw ValidationException::withMessages(['customer_id' => 'Lead phải ở giai đoạn Chờ thanh toán trước khi chốt.']);
            }

            $class = ClassModel::with(['course', 'branch'])->lockForUpdate()->findOrFail($validated['class_id']);
            if ($class->status !== 'active' || ! $class->course || ! $class->course->is_active) {
                throw ValidationException::withMessages(['class_id' => 'Lớp hoặc khóa học không còn hoạt động.']);
            }
            if ($customer->branch_id && $class->branch_id && $customer->branch_id !== $class->branch_id) {
                throw ValidationException::withMessages(['class_id' => 'Lớp được chọn phải thuộc cùng chi nhánh với Lead.']);
            }

            if ($class->max_capacity > 0 && $class->enrollments()->whereIn('status', ['pending', 'completed'])->count() >= $class->max_capacity) {
                throw ValidationException::withMessages(['class_id' => 'Lớp đã đủ sĩ số, vui lòng chọn lớp khác.']);
            }

            $needsBankAccount = $validated['payment_method'] === 'transfer'
                || ($validated['payment_method'] === 'split' && (float) ($validated['split_transfer_amount'] ?? 0) > 0);
            $bankAccount = ! empty($validated['bank_account_id'])
                ? BankAccount::whereKey($validated['bank_account_id'])->where('is_active', true)->first()
                : null;
            if ($needsBankAccount && ! $bankAccount) {
                throw ValidationException::withMessages(['bank_account_id' => 'Chuyển khoản cần một tài khoản ngân hàng đang hoạt động.']);
            }
            if ($bankAccount?->branch_id && $class->branch_id && $bankAccount->branch_id !== $class->branch_id) {
                throw ValidationException::withMessages(['bank_account_id' => 'Tài khoản thu tiền không thuộc chi nhánh của lớp.']);
            }

            $baseTuition = (float) ($class->tuition_fee > 0 ? $class->tuition_fee : $class->course->tuition_fee);
            if ($baseTuition <= 0) {
                throw ValidationException::withMessages(['class_id' => 'Lớp chưa được cấu hình học phí.']);
            }

            $promotion = null;
            $discount = 0.0;
            if (! empty($validated['promotion_id'])) {
                $promotion = Promotion::whereKey($validated['promotion_id'])->lockForUpdate()->first();
                if (! $promotion?->isApplicable($class->branch_id, $class->course_id)) {
                    throw ValidationException::withMessages(['promotion_id' => 'Ưu đãi không còn hiệu lực hoặc không áp dụng cho lớp đã chọn.']);
                }
                $discount = $promotion->calculateDiscount($baseTuition);
            }

            $feeItems = $this->resolveFeeItems($request->input('fee_items'));
            $otherFees = array_sum(array_column($feeItems, 'amount'));
            $contractTotal = max(0, $baseTuition - $discount + $otherFees);
            $prepaidAmount = (float) ($validated['prepaid_amount'] ?? 0);
            if ($prepaidAmount > 0 && ! $request->user()->hasAnyRole(['admin', 'manager'])) {
                throw ValidationException::withMessages(['prepaid_amount' => 'Khoản thu trước phải được quản lý xác nhận.']);
            }
            if ($prepaidAmount > $contractTotal) {
                throw ValidationException::withMessages(['prepaid_amount' => 'Khoản thu trước vượt quá giá trị hợp đồng.']);
            }

            $paidAmount = (float) $validated['paid_amount'];
            $netDue = $contractTotal - $prepaidAmount;
            if ($paidAmount > $netDue) {
                throw ValidationException::withMessages(['paid_amount' => 'Số tiền thu vượt quá số tiền còn phải nộp.']);
            }

            $splitDetails = null;
            if ($validated['payment_method'] === 'split') {
                $splitDetails = [
                    'cash' => (float) ($validated['split_cash_amount'] ?? 0),
                    'transfer' => (float) ($validated['split_transfer_amount'] ?? 0),
                    'pos' => (float) ($validated['split_pos_amount'] ?? 0),
                ];
                if (abs(array_sum($splitDetails) - $paidAmount) > 0.01) {
                    throw ValidationException::withMessages(['payment_method' => 'Tổng các phương thức tách phải bằng số tiền thực thu.']);
                }
            }

            $studentCode = 'HV-'.Str::upper((string) Str::ulid());
            $studentEmail = $customer->email ?: Str::lower($studentCode).'@student.menglish.edu.vn';
            $studentUser = User::withTrashed()->whereRaw('LOWER(email) = ?', [Str::lower($studentEmail)])->first();
            $temporaryPassword = null;

            if ($studentUser) {
                $alreadyLinked = Student::where('user_id', $studentUser->id)->exists();
                if ($alreadyLinked || ! $studentUser->hasRole('student')) {
                    throw ValidationException::withMessages(['customer_id' => 'Email của Lead đã thuộc một tài khoản khác. Vui lòng cập nhật email riêng cho học viên.']);
                }
                if ($studentUser->trashed()) {
                    $studentUser->restore();
                }
            } else {
                $temporaryPassword = Str::password(20);
                $studentUser = User::create([
                    'employee_code' => $studentCode,
                    'name' => $customer->name,
                    'email' => $studentEmail,
                    'phone' => $customer->phone,
                    'branch_id' => $class->branch_id,
                    'password' => Hash::make($temporaryPassword),
                    'must_change_password' => true,
                    'is_active' => true,
                    'email_verified_at' => null,
                ]);
                Role::findOrCreate('student', 'web');
                $studentUser->assignRole('student');
            }

            $student = Student::create([
                'code' => $studentCode,
                'user_id' => $studentUser->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $studentEmail,
                'dob' => $customer->dob,
                'gender' => $customer->gender,
                'address' => $customer->address,
                'branch_id' => $class->branch_id,
                'current_class_id' => $class->id,
                'target' => $customer->course_interest ?? $class->course->name,
                'entrance_score' => $customer->test_score ?? 'Chưa test',
                'status' => 'studying',
                'total_lessons' => $class->course->total_lessons,
            ]);

            ClassEnrollment::create([
                'student_id' => $student->id,
                'class_id' => $class->id,
                'customer_id' => $customer->id,
                'enrolled_at' => now(),
                'curriculum_delivered' => false,
                'zalo_group_added' => false,
                'status' => 'pending',
            ]);

            // Memo phải sinh từ mã học viên thật sau khi tạo hồ sơ — giá trị preview phía client
            // chỉ mang tính minh hoạ (không biết trước mã HV) nên luôn bị ghi đè.
            $transferMemo = self::buildTransferMemo($student->code, $student->name, $class->name, $class->branch?->code);
            $tuition = StudentTuition::create([
                'student_id' => $student->id,
                'class_id' => $class->id,
                'branch_id' => $class->branch_id,
                'bank_account_id' => $bankAccount?->id,
                'promotion_id' => $promotion?->id,
                'total_amount' => $baseTuition,
                'discount_amount' => $discount,
                'other_fees' => $otherFees,
                'fee_items' => $feeItems ?: null,
                'prepaid_amount' => $prepaidAmount,
                'final_amount' => $contractTotal,
                'paid_amount' => 0,
                'debt_amount' => $contractTotal,
                'due_date' => now()->addDays(7),
                'status' => 'unpaid',
                'notes' => $validated['bill_notes'] ?? "Thu qua Closing Wizard (Khách hàng {$customer->name})",
                'transfer_memo' => $transferMemo,
            ]);

            if ($prepaidAmount > 0) {
                TuitionReceipt::create([
                    'receipt_number' => TuitionReceipt::generateReceiptNumber(),
                    'student_tuition_id' => $tuition->id,
                    'student_id' => $student->id,
                    'amount' => $prepaidAmount,
                    'tuition_amount' => $prepaidAmount,
                    'payment_method' => $validated['payment_method'],
                    'transaction_code' => 'CWD-'.Str::upper((string) Str::ulid()),
                    'payment_date' => now(),
                    'creator_id' => Auth::id(),
                    'approver_id' => null,
                    'status' => 'pending',
                    'notes' => 'Khoản đặt cọc/thu trước từ Closing Wizard, chờ Kế toán/Admin đối soát.',
                ]);
            }

            if ($paidAmount > 0) {
                TuitionReceipt::create([
                    'receipt_number' => TuitionReceipt::generateReceiptNumber(),
                    'invoice_number' => null,
                    'student_tuition_id' => $tuition->id,
                    'student_id' => $student->id,
                    'amount' => $paidAmount,
                    'tuition_amount' => min($paidAmount, max(0, $baseTuition - $discount)),
                    'payment_method' => $validated['payment_method'],
                    'split_details' => $splitDetails,
                    'collected_items' => $feeItems ?: null,
                    'transaction_code' => 'CW-'.Str::upper((string) Str::ulid()),
                    'payment_date' => now(),
                    'creator_id' => Auth::id(),
                    'approver_id' => null,
                    'status' => 'pending',
                    'notes' => "Khoản thu từ Closing Wizard, chờ Kế toán/Admin đối soát. ND: {$transferMemo}",
                ]);
            }

            $customer->update([
                'stage' => 'won',
                'deal_value' => $contractTotal,
                'converted_student_id' => $student->id,
                'converted_by' => Auth::id(),
                'commission_user_id' => $customer->assigned_user_id ?? Auth::id(),
                'converted_at' => now(),
            ]);
            if ($promotion) {
                $promotion->increment('used_count');
            }
            foreach ($feeItems as $feeItem) {
                MerchandiseItem::whereKey($feeItem['id'])->decrement('stock_quantity');
            }
            CrmCustomerHistory::create([
                'customer_id' => $customer->id,
                'user_id' => Auth::id(),
                'type' => 'stage_change',
                'content' => 'Chốt hợp đồng thành công qua Closing Wizard (Tổng giá trị: '.number_format($contractTotal).'đ).',
            ]);

            return [$student, $tuition, $temporaryPassword, false];
        }, 3);

        [$student, $tuition, $temporaryPassword, $alreadyConverted] = $result;
        $message = $alreadyConverted
            ? 'Lead này đã được chốt trước đó; hệ thống không tạo dữ liệu trùng.'
            : "Đã chốt deal và tạo hồ sơ {$student->code}. Khoản thu đang chờ Kế toán/Admin duyệt.";

        return redirect()->route('crm.customers.won')
            ->with('status', $message)
            ->with('bill_url', route('crm.tuition-bill', ['id' => $tuition->id]))
            ->with('student_account_email', $student->email)
            ->with('temporary_password', $temporaryPassword);
    }

    protected function resolveFeeItems(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }

        $ids = collect($raw)->pluck('id')->filter()->unique()->values();
        if ($ids->count() !== count($raw)) {
            throw ValidationException::withMessages(['fee_items' => 'Khoản thu khác phải chọn từ danh mục hàng hóa.']);
        }

        $items = MerchandiseItem::active()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
        if ($items->count() !== $ids->count()) {
            throw ValidationException::withMessages(['fee_items' => 'Có hàng hóa không tồn tại hoặc đã ngừng bán.']);
        }
        $outOfStock = $items->first(fn (MerchandiseItem $item) => $item->stock_quantity < 1);
        if ($outOfStock) {
            throw ValidationException::withMessages(['fee_items' => "{$outOfStock->name} đã hết tồn kho."]);
        }

        return $ids->map(fn ($id) => [
            'id' => (int) $id,
            'name' => $items[$id]->name,
            'amount' => (float) $items[$id]->price,
        ])->all();
    }

    /**
     * Helper sinh nội dung chuyển khoản theo cấu trúc chuẩn:
     * Mã hs + ten học sinh + tenlop + CN + xxx
     */
    public static function buildTransferMemo(string $studentCode, string $studentName, string $className, ?string $branchCode = 'BD', ?string $suffix = null): string
    {
        $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $studentCode));
        $name = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', Str::ascii($studentName)));
        $class = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', Str::ascii($className)));
        $class = substr($class, 0, 8);
        $branch = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', Str::ascii($branchCode ?: 'BD')));
        if (! str_starts_with($branch, 'CN')) {
            $branch = 'CN'.$branch;
        }
        $tail = $suffix ? preg_replace('/[^a-zA-Z0-9]/', '', $suffix) : rand(100, 999);

        return "{$code} {$name} {$class} {$branch} {$tail}";
    }

    /**
     * Mẫu Bill giao dịch: Thông báo nộp học phí
     */
    public function tuitionBill(Request $request, $id)
    {
        abort_unless($request->user()->can('lead.view') || $request->user()->can('tuition.view'), 403);

        // Find by Tuition, Student, Customer or Receipt
        $tuition = StudentTuition::with(['student', 'classModel.branch', 'classModel.course', 'branch', 'receipts'])->find($id);

        if (! $tuition) {
            // Try find by student id
            $student = Student::find($id);
            if ($student) {
                $tuition = StudentTuition::where('student_id', $student->id)->latest()->first();
            }
        }

        if (! $tuition) {
            abort(404, 'Không tìm thấy thông tin công nợ / học phí của học viên.');
        }

        $student = $tuition->student;
        $class = $tuition->classModel;
        if ($request->user()->can('tuition.view')) {
            $customer = CrmCustomer::where('converted_student_id', $student?->id)->first();
        } else {
            $customer = $this->scopeCustomerQuery()->where('converted_student_id', $student?->id)->first();
        }
        if (! $customer && ! $request->user()->can('tuition.view')) {
            abort(404);
        }
        $promotion = $tuition->promotion_id ? Promotion::find($tuition->promotion_id) : null;

        $remainingDebt = max(0, (float) ($tuition->debt_amount ?? 0));
        $pendingAmount = min(
            $remainingDebt,
            (float) $tuition->receipts->where('status', 'pending')->sum('amount')
        );
        $bankAccount = $tuition->bank_account_id
            ? BankAccount::whereKey($tuition->bank_account_id)->where('is_active', true)->first()
            : null;
        if ($bankAccount && $tuition->branch_id && $bankAccount->branch_id && $bankAccount->branch_id !== $tuition->branch_id) {
            $bankAccount = null;
        }
        if (! $bankAccount && $tuition->branch_id) {
            $bankAccount = BankAccount::query()
                ->where('is_active', true)
                ->where('is_default_vietqr', true)
                ->where('branch_id', $tuition->branch_id)
                ->first();
        }
        $bankAccount ??= BankAccount::query()
            ->where('is_active', true)
            ->where('is_default_vietqr', true)
            ->whereNull('branch_id')
            ->first();

        $branchCode = $class?->branch?->code ?: 'BD';
        $transferMemo = $tuition->transfer_memo;
        if (! $transferMemo) {
            $transferMemo = self::buildTransferMemo($student->code, $student->name, $class?->name ?? '4M2', $branchCode);
            $tuition->forceFill(['transfer_memo' => $transferMemo])->save();
        }
        $amountToPay = max(0, $remainingDebt - $pendingAmount);
        $qrWarning = (! $bankAccount && $amountToPay > 0)
            ? 'Chưa cấu hình tài khoản ngân hàng hoạt động để tạo mã VietQR. Vui lòng liên hệ Kế toán/Admin bổ sung trước khi thu tiền.'
            : null;

        $bankCodeParam = $bankAccount ? urlencode($bankAccount->bank_code) : '';
        $accNumParam = $bankAccount ? urlencode(preg_replace('/\s+/', '', $bankAccount->account_number)) : '';
        $memoParam = urlencode($transferMemo);
        $accNameParam = $bankAccount ? urlencode($bankAccount->account_holder) : '';
        $vietQrUrl = $amountToPay > 0 && $bankAccount
            ? "https://img.vietqr.io/image/{$bankCodeParam}-{$accNumParam}-compact2.png?amount={$amountToPay}&addInfo={$memoParam}&accountName={$accNameParam}"
            : null;

        $totalSessions = $class?->course?->total_lessons ?? $student?->total_lessons ?? 0;

        return view('crm.tuition-bill', compact(
            'tuition',
            'student',
            'class',
            'customer',
            'promotion',
            'bankAccount',
            'transferMemo',
            'amountToPay',
            'pendingAmount',
            'vietQrUrl',
            'qrWarning',
            'totalSessions'
        ));
    }

    public function lostDeals()
    {
        $lostCustomers = $this->scopeCustomerQuery()
            ->with(['branch', 'assignedUser'])
            ->where('stage', 'lost')
            ->latest()
            ->get();

        return view('crm.lost-deals', compact('lostCustomers'));
    }

    public function reports(Request $request)
    {
        $preset = $request->get('preset', 'last_30_days');
        $branchId = $request->get('branch_id');

        // Xác định khoảng thời gian lọc (Start date & End date)
        $now = Carbon::now();
        switch ($preset) {
            case 'today':
                $startDate = $now->copy()->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $prevStartDate = $now->copy()->subDay()->startOfDay();
                $prevEndDate = $now->copy()->subDay()->endOfDay();
                $presetLabel = 'Hôm nay';
                break;
            case 'yesterday':
                $startDate = $now->copy()->subDay()->startOfDay();
                $endDate = $now->copy()->subDay()->endOfDay();
                $prevStartDate = $now->copy()->subDays(2)->startOfDay();
                $prevEndDate = $now->copy()->subDays(2)->endOfDay();
                $presetLabel = 'Hôm qua';
                break;
            case 'last_7_days':
                $startDate = $now->copy()->subDays(7)->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $prevStartDate = $now->copy()->subDays(14)->startOfDay();
                $prevEndDate = $now->copy()->subDays(7)->endOfDay();
                $presetLabel = '7 ngày trước';
                break;
            case 'last_week':
                $startDate = $now->copy()->subWeek()->startOfWeek();
                $endDate = $now->copy()->subWeek()->endOfWeek();
                $prevStartDate = $now->copy()->subWeeks(2)->startOfWeek();
                $prevEndDate = $now->copy()->subWeeks(2)->endOfWeek();
                $presetLabel = 'Tuần trước';
                break;
            case 'last_60_days':
                $startDate = $now->copy()->subDays(60)->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $prevStartDate = $now->copy()->subDays(120)->startOfDay();
                $prevEndDate = $now->copy()->subDays(60)->endOfDay();
                $presetLabel = '60 ngày';
                break;
            case 'last_90_days':
                $startDate = $now->copy()->subDays(90)->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $prevStartDate = $now->copy()->subDays(180)->startOfDay();
                $prevEndDate = $now->copy()->subDays(90)->endOfDay();
                $presetLabel = '90 ngày';
                break;
            case 'last_6_months':
                $startDate = $now->copy()->subMonths(6)->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $prevStartDate = $now->copy()->subMonths(12)->startOfDay();
                $prevEndDate = $now->copy()->subMonths(6)->endOfDay();
                $presetLabel = '6 tháng';
                break;
            case 'last_year':
                $startDate = $now->copy()->subYear()->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $prevStartDate = $now->copy()->subYears(2)->startOfDay();
                $prevEndDate = $now->copy()->subYear()->endOfDay();
                $presetLabel = '1 năm';
                break;
            case 'custom':
                $startDate = ($this->parseReportDate($request->get('start_date')) ?? $now->copy()->subDays(30))->startOfDay();
                $endDate = ($this->parseReportDate($request->get('end_date')) ?? $now->copy())->endOfDay();
                $diffDays = max(1, $startDate->diffInDays($endDate));
                $prevStartDate = $startDate->copy()->subDays($diffDays);
                $prevEndDate = $startDate->copy();
                $presetLabel = 'Tùy chỉnh';
                break;
            case 'last_30_days':
            default:
                $preset = 'last_30_days';
                $startDate = $now->copy()->subDays(30)->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $prevStartDate = $now->copy()->subDays(60)->startOfDay();
                $prevEndDate = $now->copy()->subDays(30)->endOfDay();
                $presetLabel = '30 ngày trước';
                break;
        }

        $branches = Branch::where('is_active', true)->get();

        // Query Base CRM Customers (scoped by user role)
        $query = $this->scopeCustomerQuery();
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        // Cohort Lead dùng ngày tiếp nhận; Won/Lost dùng đúng ngày phát sinh trạng thái.
        $periodQuery = (clone $query)->whereBetween('created_at', [$startDate, $endDate]);
        $prevPeriodQuery = (clone $query)->whereBetween('created_at', [$prevStartDate, $prevEndDate]);

        $allCurrent = $periodQuery->get();
        $allPrev = $prevPeriodQuery->get();
        $wonCurrent = (clone $query)->where('stage', 'won')
            ->where(fn (Builder $q) => $q->whereBetween('converted_at', [$startDate, $endDate])
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('converted_at')->whereBetween('created_at', [$startDate, $endDate])))
            ->get();
        $wonPrev = (clone $query)->where('stage', 'won')
            ->where(fn (Builder $q) => $q->whereBetween('converted_at', [$prevStartDate, $prevEndDate])
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('converted_at')->whereBetween('created_at', [$prevStartDate, $prevEndDate])))
            ->get();
        $lostCurrent = (clone $query)->where('stage', 'lost')
            ->where(fn (Builder $q) => $q->whereBetween('lost_at', [$startDate, $endDate])
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('lost_at')->whereBetween('created_at', [$startDate, $endDate])))
            ->get();
        $lostPrev = (clone $query)->where('stage', 'lost')
            ->where(fn (Builder $q) => $q->whereBetween('lost_at', [$prevStartDate, $prevEndDate])
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('lost_at')->whereBetween('created_at', [$prevStartDate, $prevEndDate])))
            ->get();

        // 1. Thống kê kỳ hiện tại (100% Thực tế từ CSDL)
        $totalLeads = $allCurrent->count();
        $wonDeals = $wonCurrent->count();
        $lostDeals = $lostCurrent->count();
        $cohortWonDeals = $allCurrent->where('stage', 'won')->count();
        $conversionRate = $totalLeads > 0 ? round(($cohortWonDeals / $totalLeads) * 100, 1) : 0;

        // Thống kê so sánh với kỳ trước (100% Thực tế)
        $prevTotalLeads = $allPrev->count();
        $prevWonDeals = $wonPrev->count();
        $prevLostDeals = $lostPrev->count();
        $prevCohortWonDeals = $allPrev->where('stage', 'won')->count();
        $prevConversionRate = $prevTotalLeads > 0 ? round(($prevCohortWonDeals / $prevTotalLeads) * 100, 1) : 0;

        $leadDiff = $totalLeads - $prevTotalLeads;
        $leadDeltaPercent = $prevTotalLeads > 0 ? round(($leadDiff / $prevTotalLeads) * 100, 1) : ($totalLeads > 0 ? 100 : 0);

        $wonDiff = $wonDeals - $prevWonDeals;
        $wonDeltaPercent = $prevWonDeals > 0 ? round(($wonDiff / $prevWonDeals) * 100, 1) : ($wonDeals > 0 ? 100 : 0);

        $conversionDeltaPercent = round($conversionRate - $prevConversionRate, 1);

        $lostDiff = $lostDeals - $prevLostDeals;
        $lostDeltaPercent = $prevLostDeals > 0 ? round(($lostDiff / $prevLostDeals) * 100, 1) : ($lostDeals > 0 ? 100 : 0);

        $metricTotalLeads = $totalLeads;
        $metricWonDeals = $wonDeals;
        $metricConversionRate = $conversionRate;
        $metricLostDeals = $lostDeals;

        // Phân bố trạng thái của cohort, giữ nguyên hai nhánh test/không-test.
        $stageReportDefinitions = [
            'new' => ['Mới nhận', 'bg-blue-500', 'text-blue-600', 'Chưa bắt đầu tư vấn'],
            'consulting' => ['Đang tư vấn', 'bg-amber-500', 'text-amber-600', 'Đang xác định lộ trình'],
            'test_scheduled' => ['Hẹn test', 'bg-indigo-500', 'text-indigo-600', 'Đã đặt lịch kiểm tra'],
            'tested' => ['Đã test', 'bg-purple-500', 'text-purple-600', 'Đã có kết quả đầu vào'],
            'trial_scheduled' => ['Hẹn học thử', 'bg-fuchsia-500', 'text-fuchsia-600', 'Đã đặt lịch học thử'],
            'trial_completed' => ['Đã học thử', 'bg-teal-500', 'text-teal-600', 'Đã có phản hồi học thử'],
            'waiting_class' => ['Chờ xếp lớp', 'bg-yellow-500', 'text-yellow-600', 'Nhánh chờ lớp phù hợp'],
            'closing' => ['Chờ thanh toán', 'bg-cyan-500', 'text-cyan-600', 'Đang hoàn tất hợp đồng'],
            'won' => ['Đã chốt', 'bg-emerald-500', 'text-emerald-600', 'Đã tạo hồ sơ và xếp lớp'],
        ];
        $funnelStages = collect($stageReportDefinitions)->map(function (array $meta, string $stage) use ($allCurrent, $totalLeads) {
            $count = $allCurrent->where('stage', $stage)->count();

            return [
                'name' => $meta[0], 'count' => $count,
                'percent' => $totalLeads > 0 ? round(($count / $totalLeads) * 100, 1) : 0,
                'bar_color' => $meta[1], 'text_color' => $meta[2], 'desc' => $meta[3],
            ];
        })->values()->all();

        // 3. Lấy cấu hình Hoa hồng từ bảng commission_tiers (cùng luật chọn bậc với tính lương)
        $commissionTiers = CommissionTier::orderByDesc('min_revenue')->get();

        $calculateCommission = function (float $revenue) {
            $matchedTier = CommissionTier::matchForRevenue($revenue);

            if ($matchedTier) {
                $percent = (float) $matchedTier->new_sale_percent;
                $bonus = (float) $matchedTier->bonus_amount;
                $commission = ($revenue * ($percent / 100)) + $bonus;

                return [
                    'tier_name' => $matchedTier->tier_name,
                    'percent' => $percent,
                    'bonus' => $bonus,
                    'amount' => $commission,
                ];
            }

            // Mức cơ bản nếu chưa đạt mốc tối thiểu (2%)
            return [
                'tier_name' => 'Chưa đạt định mức',
                'percent' => 2.0,
                'bonus' => 0,
                'amount' => $revenue * 0.02,
            ];
        };

        // 4. Bảng hiệu suất theo nhân viên tư vấn tuyển sinh (100% Real from Users in Database)
        try {
            $salesUsers = User::role('sales_consultant')->get();
        } catch (\Throwable $e) {
            $salesUsers = collect();
        }

        if ($salesUsers->isEmpty()) {
            $salesUsers = User::whereHas('crmCustomers')->get();
        }

        $studentOwners = (clone $query)->whereNotNull('converted_student_id')
            ->get(['converted_student_id', 'commission_user_id', 'assigned_user_id'])
            ->mapWithKeys(fn (CrmCustomer $customer) => [
                $customer->converted_student_id => $customer->commission_user_id ?? $customer->assigned_user_id,
            ]);
        $collectedBySales = TuitionReceipt::query()
            ->where('status', 'approved')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->whereIn('student_id', $studentOwners->keys())
            ->get()
            ->groupBy(fn (TuitionReceipt $receipt) => $studentOwners->get($receipt->student_id))
            ->map->sum('amount');

        $repsData = [];
        foreach ($salesUsers as $user) {
            $userLeads = $allCurrent->where('assigned_user_id', $user->id);
            $userLeadsCount = $userLeads->count();
            $userWonCount = $wonCurrent->filter(fn (CrmCustomer $lead) => ($lead->commission_user_id ?? $lead->assigned_user_id) === $user->id)->count();
            $userCohortWonCount = $userLeads->where('stage', 'won')->count();
            $userRevenue = (float) ($collectedBySales->get($user->id) ?? 0);
            $userRate = $userLeadsCount > 0 ? round(($userCohortWonCount / $userLeadsCount) * 100, 1) : 0;

            // Tỷ lệ chốt kỳ trước của cùng rep để tính delta thực (không dùng baseline cứng)
            $prevUserLeadsCount = $allPrev->where('assigned_user_id', $user->id)->count();
            $prevUserWonCount = $wonPrev->filter(fn (CrmCustomer $lead) => ($lead->commission_user_id ?? $lead->assigned_user_id) === $user->id)->count();
            $prevUserRate = $prevUserLeadsCount > 0 ? round(($prevUserWonCount / $prevUserLeadsCount) * 100, 1) : 0;

            $commissionResult = $calculateCommission($userRevenue);

            $rating = 'Cần cải thiện';
            $ratingBadge = 'bg-rose-100 text-rose-800 border-rose-200';
            if ($userRevenue >= 100000000 || $userRate >= 30) {
                $rating = 'Xuất sắc';
                $ratingBadge = 'bg-emerald-100 text-emerald-800 border-emerald-200';
            } elseif ($userRevenue >= 50000000 || $userRate >= 25) {
                $rating = 'Tốt';
                $ratingBadge = 'bg-blue-100 text-blue-800 border-blue-200';
            } elseif ($userRevenue >= 20000000 || $userRate >= 15) {
                $rating = 'Đạt yêu cầu';
                $ratingBadge = 'bg-amber-100 text-amber-800 border-amber-200';
            }

            $avatarLetter = mb_strtoupper(mb_substr($user->name, 0, 1));

            $rateDelta = round($userRate - $prevUserRate, 1);

            $repsData[] = [
                'name' => $user->name,
                'role' => $user->roles->first()?->name === 'sales_consultant' ? 'Chuyên viên Tư vấn Tuyển sinh' : ($user->roles->first()?->name ?? 'Tư vấn viên'),
                'avatar_letter' => $avatarLetter,
                'leads' => $userLeadsCount,
                'won' => $userWonCount,
                'rate' => $userRate,
                'revenue' => $userRevenue,
                'delta' => ($rateDelta >= 0 ? '+' : '').$rateDelta.'%',
                'rating' => $rating,
                'rating_badge' => $ratingBadge,
                'commission_amount' => $commissionResult['amount'],
                'commission_percent' => $commissionResult['percent'],
                'commission_bonus' => $commissionResult['bonus'],
                'tier_name' => $commissionResult['tier_name'],
            ];
        }

        // Sắp xếp người có doanh số cao nhất lên đầu
        usort($repsData, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return view('crm.reports', compact(
            'preset',
            'presetLabel',
            'startDate',
            'endDate',
            'branchId',
            'branches',
            'metricTotalLeads',
            'metricWonDeals',
            'metricConversionRate',
            'metricLostDeals',
            'leadDeltaPercent',
            'leadDiff',
            'wonDeltaPercent',
            'wonDiff',
            'conversionDeltaPercent',
            'lostDeltaPercent',
            'lostDiff',
            'funnelStages',
            'repsData',
            'commissionTiers'
        ));
    }

    /**
     * Parse ngày từ input người dùng cho báo cáo; trả về null nếu rỗng hoặc
     * không parse được để caller fallback về khoảng mặc định thay vì lỗi 500.
     */
    protected function parseReportDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
