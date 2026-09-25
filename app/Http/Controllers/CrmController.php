<?php

namespace App\Http\Controllers;

use App\Exceptions\CrmStageTransitionException;
use App\Exports\ArrayExport;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CommissionTier;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\MerchandiseItem;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Promotion;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SystemCategory;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\CrmStageService;
use App\Services\NotificationService;
use App\Services\PlacementPortalLinkService;
use App\Services\PlacementRubricService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

class CrmController extends Controller
{
    /**
     * Scope dữ liệu CRM (BA chốt):
     * - Admin: toàn bộ.
     * - Quản lý cơ sở / Học vụ: chỉ lead thuộc chi nhánh của mình (branch_id + user_branches).
     * - Sales và các vai trò CRM còn lại: chỉ lead được gán phụ trách.
     */
    protected function scopeCustomerQuery(?User $user = null): Builder
    {
        return CrmCustomer::query()->visibleTo($user ?? Auth::user());
    }

    protected function findScopedCustomer(int|string $id): CrmCustomer
    {
        return $this->scopeCustomerQuery()
            ->where(fn (Builder $query) => $query->where('id', $id)->orWhere('code', $id))
            ->firstOrFail();
    }

    protected function normalizePhone(string $phone): string
    {
        return CrmCustomer::normalizePhone($phone);
    }

    /**
     * Kiểm tra SĐT (định dạng Việt Nam) + trùng SĐT / email với khách đang hoạt động.
     * Khách đã xóa không chặn tạo mới (SĐT đã được nhả khi xóa) — Admin / Quản lý có thể khôi phục.
     */
    protected function assertUniqueLead(string $phone, ?string $email = null, ?int $ignoreId = null): string
    {
        if (! CrmCustomer::isValidVietnamesePhone($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'Số điện thoại không hợp lệ. Nhập số Việt Nam 10 số bắt đầu bằng 0 (hoặc +84), ví dụ 0912 345 678.',
            ]);
        }
        $phoneNormalized = $this->normalizePhone($phone);
        $duplicate = CrmCustomer::query()
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->where('phone_normalized', $phoneNormalized)
            ->first(['id', 'code']);

        if ($duplicate) {
            throw ValidationException::withMessages([
                'phone' => "Số điện thoại này đã tồn tại trong CRM (khách {$duplicate->code}).",
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

    /** SĐT phụ huynh (không bắt buộc) cũng phải đúng định dạng Việt Nam. */
    protected function assertValidParentPhone(?string $phone): void
    {
        if (filled($phone) && ! CrmCustomer::isValidVietnamesePhone($phone)) {
            throw ValidationException::withMessages(['parent_phone' => 'SĐT phụ huynh không hợp lệ (10 số bắt đầu bằng 0 hoặc +84).']);
        }
    }

    public function pipeline(Request $request, CrmStageService $stages)
    {
        $query = $this->scopeCustomerQuery()
            ->with(['branch', 'assignedUser', 'convertedStudent.tuition'])
            ->whereIn('stage', array_keys(CrmCustomer::PIPELINE_STAGES));
        $this->applyListFilters($query, $request, 'created_at');

        $allCustomers = $query->latest()->get()->groupBy('stage');
        // "Đã hoàn tất hồ sơ" (mockup cột Đã chốt) = ghi danh từ CRM đã Xác nhận chính thức.
        $confirmedIds = ClassEnrollment::query()
            ->whereIn('customer_id', $allCustomers->get('won', collect())->pluck('id'))
            ->whereNotNull('confirmed_at')
            ->pluck('customer_id')->flip();

        $stageColumns = [];
        foreach (CrmCustomer::PIPELINE_STAGES as $key => $label) {
            $group = $allCustomers->get($key, collect());
            $style = CrmCustomer::stageStyle($key);

            $stageColumns[] = [
                'id' => $key,
                'name' => $label,
                'next' => $stages->nextStage($key),
                'count' => $group->count(),
                'amount_raw' => (float) $group->sum('deal_value'),
                'amount' => number_format($group->sum('deal_value')).'đ',
                'color' => $style['border'],
                'bg_badge' => $style['badge'],
                'dot' => $style['bar'],
                'text' => $style['text'],
                'header_border' => $style['header_border'],
                'source_badge' => $style['source_badge'],
                'leads' => $group->map(fn (CrmCustomer $c) => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'parent_name' => $c->parent_name,
                    'phone' => $c->phone,
                    'source' => $c->source ?? 'Trực tiếp',
                    'course' => $c->course_interest ?? 'Chưa chọn khóa',
                    'tuition' => number_format($c->deal_value).'đ',
                    'agent' => $c->assignedUser?->name ?? 'Chưa phân công',
                    'days' => $c->created_at->diffForHumans(),
                    'score' => $c->test_score ?? 'Chưa test',
                    'has_test_result' => filled($c->test_score),
                    'confirmed' => $confirmedIds->has($c->id),
                    'status' => $c->converted_student_id ? ($c->convertedStudent?->tuition?->status_label ?? 'Chưa có học phí') : null,
                    'payment_status' => $c->convertedStudent?->tuition?->status,
                    'follow_up_status' => $c->followUpStatus(),
                    'follow_up_at' => $c->next_follow_up_at?->format('d/m/Y H:i'),
                    // Mockup: Quá hạn / Sắp hết hạn / Còn hạn + "10:30 Hôm nay", "09:00 Mai".
                    'follow_up_state' => $c->followUpStatus()
                        ?? ($c->next_follow_up_at && in_array($c->stage, CrmCustomer::ACTIVE_STAGES, true) ? 'on_time' : null),
                    'follow_up_label' => $this->relativeDeadlineLabel($c->next_follow_up_at),
                ])->values()->all(),
            ];
        }

        $user = $request->user();
        $stagePermissions = [
            'canForward' => $stages->canMoveForward($user),
            'canBackward' => $stages->canMoveBackward($user),
            'canConvert' => $user->can('lead.convert'),
            'order' => array_keys(CrmCustomer::PIPELINE_STAGES),
            'closed' => CrmCustomer::CLOSED_STAGES,
            'labels' => CrmCustomer::PIPELINE_STAGES,
        ];

        return view('crm.pipeline', ['stages' => $stageColumns, 'stagePermissions' => $stagePermissions] + $this->listFilterOptions());
    }

    /** "10:30 Hôm nay" / "09:00 Mai" / "15:00 Hôm qua" / "09:00 28/09" như mockup Pipeline. */
    protected function relativeDeadlineLabel(?Carbon $at): ?string
    {
        if (! $at) {
            return null;
        }
        $day = match (true) {
            $at->isToday() => 'Hôm nay',
            $at->isTomorrow() => 'Mai',
            $at->isYesterday() => 'Hôm qua',
            default => $at->format('d/m'.($at->year === now()->year ? '' : '/Y')),
        };

        return $at->format('H:i').' '.$day;
    }

    /**
     * Bộ lọc dùng chung cho Pipeline / Khách chốt / Khách không chốt:
     * search (tên, SĐT, mã, email, phụ huynh), branch_id (Admin), assigned_user_id, source, from/to theo $dateColumn.
     */
    protected function applyListFilters(Builder $query, Request $request, string $dateColumn, array $extraSearchColumns = []): Builder
    {
        if ($search = trim((string) $request->input('search'))) {
            $digits = $this->normalizePhone($search);
            $query->where(function (Builder $q) use ($search, $digits, $extraSearchColumns) {
                foreach ($extraSearchColumns as $column) {
                    $q->orWhere($column, 'like', "%{$search}%");
                }
                $q->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('parent_name', 'like', "%{$search}%")
                    ->when(strlen($digits) >= 3, fn (Builder $phoneQuery) => $phoneQuery
                        ->orWhere('phone_normalized', 'like', "%{$digits}%")
                        ->orWhere('parent_phone', 'like', "%{$search}%"));
            });
        }
        // Admin lọc mọi chi nhánh; Quản lý / Học vụ nhiều chi nhánh chỉ lọc trong chi nhánh của mình (ngoài phạm vi → bỏ qua).
        if ($request->filled('branch_id') && ($request->user()->hasRole('admin')
            || in_array($request->integer('branch_id'), CrmCustomer::branchIdsOf($request->user()), true)
                && $request->user()->hasAnyRole(['manager', 'academic_staff', 'academic_lead']))) {
            $query->where('branch_id', $request->integer('branch_id'));
        }
        if ($request->filled('assigned_user_id')) {
            $query->where('assigned_user_id', $request->integer('assigned_user_id'));
        }
        if ($request->filled('source')) {
            $query->where('source', (string) $request->input('source'));
        }
        if ($from = $this->parseReportDate($request->input('from'))) {
            $query->where($dateColumn, '>=', $from->startOfDay());
        }
        if ($to = $this->parseReportDate($request->input('to'))) {
            $query->where($dateColumn, '<=', $to->endOfDay());
        }

        return $query;
    }

    /** @return array{filterBranches: Collection, filterSales: Collection, filterSources: Collection} */
    protected function listFilterOptions(): array
    {
        $user = Auth::user();
        $scopedIds = $this->scopeCustomerQuery()->select('id');

        return [
            'filterBranches' => match (true) {
                ! $user => collect(),
                $user->hasRole('admin') => Branch::orderBy('name')->get(['id', 'name']),
                // Quản lý / Học vụ phụ trách nhiều chi nhánh: chỉ lọc trong các chi nhánh của mình.
                $user->hasAnyRole(['manager', 'academic_staff', 'academic_lead']) && count(CrmCustomer::branchIdsOf($user)) > 1
                    => Branch::whereIn('id', CrmCustomer::branchIdsOf($user))->orderBy('name')->get(['id', 'name']),
                default => collect(),
            },
            'filterSales' => User::query()
                ->whereIn('id', CrmCustomer::query()->whereIn('id', $scopedIds)->whereNotNull('assigned_user_id')->select('assigned_user_id'))
                ->orderBy('name')->get(['id', 'name']),
            'filterSources' => CrmCustomer::query()->whereIn('id', $scopedIds)->whereNotNull('source')->distinct()->orderBy('source')->pluck('source'),
        ];
    }

    public function customers(Request $request)
    {
        // Mockup danh-sach-khach: lọc Từ khóa (tên / SĐT / phụ huynh), Nguồn, Người phụ trách, Giai đoạn, Chi nhánh.
        $query = $this->scopeCustomerQuery()->with(['branch', 'assignedUser'])->latest('updated_at')->latest('id');
        $this->applyListFilters($query, $request, 'created_at');

        if ($stage = $request->input('stage')) {
            $query->where('stage', $stage);
        }

        $dbCustomers = $query->paginate($request->perPage(15))->withQueryString();

        return view('crm.customers', ['customers' => $dbCustomers] + $this->listFilterOptions());
    }

    /** Danh sách học viên đã chốt nhưng chưa có lớp (Chờ xếp lớp) để Học vụ gán lớp. */
    public function waitingList()
    {
        return view('crm.waiting-list', $this->waitingClassData());
    }

    /**
     * Lead ở Chờ xếp lớp (đã có hồ sơ học viên) + lớp gợi ý: đúng khóa, đúng chi nhánh, còn chỗ.
     *
     * @return array{waitingLeads: Collection, matchingClassesByLead: Collection}
     */
    protected function waitingClassData(): array
    {
        $waitingLeads = $this->scopeCustomerQuery()
            ->with(['assignedUser', 'branch', 'waitingCourse', 'convertedStudent'])
            ->where('stage', 'waiting_class')
            ->orderBy('converted_at')
            ->get();

        $classes = $waitingLeads->isEmpty() ? collect() : ClassModel::query()
            ->with(['course', 'branch'])
            ->withCount(['enrollments as active_enrollments_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'completed'])])
            ->whereIn('status', ['active', 'upcoming'])
            ->whereIn('branch_id', $waitingLeads->map(fn (CrmCustomer $lead) => $lead->convertedStudent?->branch_id ?? $lead->branch_id)->filter()->unique())
            ->get();

        $matchingClassesByLead = $waitingLeads->mapWithKeys(function (CrmCustomer $lead) use ($classes) {
            $branchId = $lead->convertedStudent?->branch_id ?? $lead->branch_id;
            $matches = $classes->filter(fn (ClassModel $class) => $class->branch_id === $branchId
                && (! $lead->waiting_course_id || $class->course_id === $lead->waiting_course_id)
                && ($class->max_capacity <= 0 || $class->active_enrollments_count < $class->max_capacity)
            )->values();

            return [$lead->id => $matches];
        });

        return compact('waitingLeads', 'matchingClassesByLead');
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
            $leadSources = collect(CrmCustomer::DEFAULT_SOURCES);
        }

        return view('crm.create', compact('branches', 'salesUsers', 'leadSources'));
    }

    public function storeCustomer(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'parent_name' => 'nullable|string|max:255',
            'parent_phone' => 'nullable|string|max:20',
            'next_follow_up_at' => 'nullable|date',
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
        $this->assertValidParentPhone($validated['parent_phone'] ?? null);
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
                'parent_phone' => $validated['parent_phone'] ?? null,
                'next_follow_up_at' => $validated['next_follow_up_at'] ?? null,
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

    public function showCustomer(Request $request, CrmStageService $stages, $id)
    {
        $customer = $this->scopeCustomerQuery()
            ->with(['branch', 'assignedUser', 'assignedTest', 'examiner', 'waitingCourse', 'waitingBranch', 'histories.user', 'submissions.test', 'submissions.grader', 'latestSubmission',
                'trialBookings' => fn ($query) => $query->with(['session', 'classModel.course', 'feedbackBy'])->latest()])
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('code', $id);
            })
            ->firstOrFail();

        $latestSubmission = $customer->latestSubmission ?? $customer->submissions->first();

        $placementTests = PlacementTest::where('is_active', true)->get();
        $examiners = User::where('is_active', true)
            ->role(['admin', 'manager', 'academic_staff', 'academic_lead', 'teacher', 'teacher_fulltime', 'teacher_parttime'])
            ->get();
        $courses = Course::where('is_active', true)->orderBy('name')->get();
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        $user = $request->user();
        $canBookTrial = $stages->canMoveForward($user) && in_array($customer->stage, CrmCustomer::TRIAL_BOOKABLE_STAGES, true);
        $trialSessions = $canBookTrial ? $this->upcomingTrialSessions($customer, $latestSubmission) : collect();
        $trialRemaining = max(0, CrmTrialBooking::MAX_ACTIVE_PER_LEAD - $customer->trialBookings->where('status', '!=', 'cancelled')->count());
        $stageControls = [
            'next' => $stages->manualNextStage($customer, $user),
            'backward' => $stages->backwardTargets($customer, $user),
            'canLose' => $stages->canMoveForward($user) && ! $customer->isClosed() && $customer->stage !== CrmCustomer::STAGE_LOST,
        ];

        // Link test riêng của lead: có chữ ký + hạn 7 ngày, chỉ khi đã gán đề đang hoạt động.
        $portalTestLink = $customer->assignedTest?->is_active
            ? app(PlacementPortalLinkService::class)->signedLinkForLead($customer->assignedTest, $customer)
            : null;

        // Bộ lọc nhật ký theo loại (server-side, giữ nguyên tổng số để hiển thị).
        $logType = $request->input('log_type');
        $histories = $customer->histories;
        if ($logType && array_key_exists($logType, CrmCustomerHistory::FILTER_TYPES)) {
            $histories = $histories->where('type', $logType)->values();
        }

        $rubric = $this->rubricSummary($latestSubmission);
        $statusCard = $this->statusCardData($customer);
        $canReassign = $user->can('lead.assign') && $user->hasAnyRole(['admin', 'manager']);
        $reassignUsers = $canReassign ? $this->assignableUsers($customer->branch_id) : collect();

        return view('crm.show', compact('customer', 'placementTests', 'examiners', 'latestSubmission', 'courses', 'branches', 'portalTestLink', 'canBookTrial', 'trialSessions', 'stageControls',
            'histories', 'logType', 'rubric', 'statusCard', 'canReassign', 'reassignUsers', 'trialRemaining'));
    }

    /**
     * Khối kết quả test theo thang điểm khối lớp (BA Q2) cho hồ sơ khách / bản in.
     *
     * @return array<string, mixed>|null
     */
    protected function rubricSummary(?PlacementTestSubmission $submission): ?array
    {
        if (! $submission || ($submission->total_score === null && $submission->overall_score === null)) {
            return null;
        }
        $group = $submission->grade_group;

        return [
            'legacy' => ! $submission->hasRubricGrade(),
            'grade_group' => $group,
            'grade_group_label' => PlacementRubricService::groupLabel($group),
            'has_rubric' => PlacementRubricService::hasRubric($group),
            'max' => PlacementRubricService::maxScores($group),
            'max_total' => PlacementRubricService::maxTotal($group),
            'total' => $submission->total_score !== null ? (float) $submission->total_score : (float) $submission->overall_score,
            'suggested_class' => $submission->suggested_class,
            'chosen_class' => $submission->finalClass(),
            'overridden' => $submission->classWasOverridden(),
            'comments' => [
                'listening' => $submission->listening_comment,
                'reading_writing' => $submission->reading_writing_comment,
                'speaking' => $submission->speaking_comment,
            ],
            'note' => $submission->teacher_comments,
        ];
    }

    /**
     * Thẻ "Trạng thái & Hạn xử lý": số ngày ở giai đoạn hiện tại, lần liên hệ gần nhất, hạn liên hệ tiếp theo.
     *
     * @return array<string, mixed>
     */
    protected function statusCardData(CrmCustomer $customer): array
    {
        $stageSince = $customer->histories->firstWhere('type', 'stage_change')?->created_at ?? $customer->created_at;
        $lastContact = $customer->histories->whereIn('type', ['call', 'message', 'meet'])->first()?->created_at;
        $neglectDays = app(NotificationService::class)->neglectThresholdDays();
        $lastActivity = $customer->histories->first()?->created_at ?? $customer->created_at;

        return [
            'stage_since' => $stageSince,
            'days_in_stage' => (int) $stageSince->diffInDays(now()),
            'last_contact' => $lastContact,
            'follow_up_status' => $customer->followUpStatus(),
            'follow_up_remaining' => $this->remainingLabel($customer->next_follow_up_at),
            'neglected' => in_array($customer->stage, CrmCustomer::ACTIVE_STAGES, true) && $lastActivity->lt(now()->subDays($neglectDays)),
            'neglect_days' => $neglectDays,
        ];
    }

    /** Đồng hồ "Hạn liên hệ tiếp theo" (mockup 02:14:55): "còn 2 giờ 14 phút" / "quá hạn 1 ngày 3 giờ". */
    protected function remainingLabel(?Carbon $at): ?string
    {
        if (! $at) {
            return null;
        }
        $diff = now()->diff($at);
        $parts = array_filter([
            $diff->days ? $diff->days.' ngày' : null,
            $diff->h ? $diff->h.' giờ' : null,
            ! $diff->days && $diff->i ? $diff->i.' phút' : null,
        ]);
        $text = $parts ? implode(' ', $parts) : 'dưới 1 phút';

        return $diff->invert ? 'Quá hạn '.$text : 'Còn '.$text;
    }

    /** Sale / quản lý đang hoạt động có thể nhận phụ trách khách (ưu tiên cùng chi nhánh). */
    protected function assignableUsers(?int $branchId = null): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->role(['sales_consultant', 'manager', 'admin'])
            ->when($branchId && ! Auth::user()?->hasRole('admin'), fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('branch_id', $branchId)
                ->orWhereHas('branches', fn (Builder $b) => $b->where('branches.id', $branchId))))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Buổi học sắp tới của lớp đang mở tại chi nhánh của lead (ứng viên cho học thử).
     * Buổi của lớp khớp trình độ (theo lớp xếp sau test / khóa quan tâm) được đánh dấu `matches_level` và xếp lên đầu.
     */
    protected function upcomingTrialSessions(CrmCustomer $customer, ?PlacementTestSubmission $submission = null)
    {
        $keywords = $this->trialLevelKeywords($customer, $submission);

        return ClassSession::query()
            ->with(['classModel.course.level', 'teacher'])
            ->where('status', 'scheduled')
            ->whereDate('date', '>=', today())
            ->when($customer->branch_id, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->whereHas('classModel', fn (Builder $query) => $query->whereIn('status', ['active', 'upcoming']))
            ->orderBy('date')->orderBy('start_time')
            ->limit(60)
            ->get()
            ->each(function (ClassSession $session) use ($keywords) {
                $haystack = Str::upper(implode(' ', array_filter([
                    $session->classModel?->name, $session->classModel?->level,
                    $session->classModel?->course?->name, $session->classModel?->course?->level?->name,
                ])));
                $session->setAttribute('matches_level', $keywords !== [] && collect($keywords)->contains(fn (string $k) => str_contains($haystack, $k)));
            })
            ->sortByDesc('matches_level')
            ->values();
    }

    /**
     * Từ khóa trình độ của khách để gợi ý lớp học thử cùng trình độ: lớp xếp sau test (vd "STARTERS (FAM 1 …)")
     * hoặc khóa quan tâm.
     *
     * @return array<int, string>
     */
    protected function trialLevelKeywords(CrmCustomer $customer, ?PlacementTestSubmission $submission): array
    {
        $source = Str::upper(trim(($submission?->finalClass() ?? '').' '.($customer->course_interest ?? '')));
        if ($source === '') {
            return [];
        }

        return collect(['PRE STARTERS', 'STARTERS', 'MOVERS', 'FLYERS', 'FAM 0', 'FAM 1', 'FAM 2', 'KET', 'PET', 'IELTS'])
            ->filter(fn (string $keyword) => str_contains($source, $keyword))
            ->values()->all();
    }

    /**
     * Nhập điểm test đầu vào từ hồ sơ khách (Học vụ / Quản lý cơ sở / Admin — quyền entrance_test.grade),
     * cùng thang điểm khối lớp với màn chấm bài (PlacementRubricService).
     */
    public function saveTestScore(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        if (! in_array($customer->stage, ['consulting', 'test_scheduled', 'testing', 'tested', 'result_sent'], true)) {
            throw ValidationException::withMessages(['placement_test_id' => 'Lead phải ở bước tư vấn hoặc luồng test để nhập điểm.']);
        }

        $group = (string) $request->input('grade_group');
        $validated = $request->validate(PlacementRubricService::scoreRules($group) + [
            'placement_test_id' => 'nullable|exists:placement_tests,id',
            'submission_id' => 'nullable|integer|exists:placement_test_submissions,id',
        ], PlacementRubricService::scoreMessages($group));

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
            ?? $customer->assigned_test_id;
        if (! $testId) {
            throw ValidationException::withMessages(['placement_test_id' => 'Vui lòng chọn đề kiểm tra đầu vào đã dùng để chấm điểm.']);
        }

        if (! $submission) {
            $submission = PlacementTestSubmission::query()
                ->where('customer_id', $customer->id)
                ->where('placement_test_id', $testId)
                ->where('status', 'pending')
                ->latest()
                ->first();
        }
        $submission ??= new PlacementTestSubmission;
        $submission->fill([
            'placement_test_id' => $testId,
            'customer_id' => $customer->id,
            'candidate_name' => $customer->name,
            'candidate_phone' => $customer->phone,
            'candidate_email' => $customer->email,
        ]);
        $submission->applyRubricGrade($validated);
        $submission->grader_id = Auth::id() ?? $customer->assigned_user_id;

        // "Lưu bản nháp" (mockup): lưu điểm đang chấm, bài vẫn Chờ chấm, khách chưa sang "Đã test".
        if ($request->input('action') === 'draft' && (! $submission->exists || $submission->isPending())) {
            $submission->status = PlacementTestSubmission::STATUS_PENDING;
            $submission->save();
            CrmCustomerHistory::create([
                'customer_id' => $customer->id,
                'user_id' => Auth::id(),
                'type' => 'test',
                'content' => 'Lưu nháp điểm test đầu vào ('.PlacementRubricService::groupLabel($submission->grade_group).'), chưa xác nhận kết quả.',
            ]);

            return redirect()->back()->with('status', 'Đã lưu bản nháp điểm test — bấm "Xác nhận kết quả" để chốt.');
        }

        $submission->status = PlacementTestSubmission::STATUS_GRADED;
        $submission->save();

        $customer->update([
            'test_score' => $submission->scoreSummary(),
            'test_decision' => 'test',
        ]);
        app(CrmStageService::class)->advanceTo($customer, 'tested', $request->user(), "Học vụ nhập điểm test đầu vào (bài #{$submission->id}).");

        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => 'test',
            'content' => 'Đã ghi nhận kết quả test đầu vào ('.PlacementRubricService::groupLabel($submission->grade_group).'): '
                ."Nghe {$this->trimScore($submission->listening_score)} · Đọc & Viết {$this->trimScore($submission->reading_writing_score)} · Nói {$this->trimScore($submission->speaking_score)}"
                .' → Tổng '.$submission->scoreSummary()
                .($submission->classWasOverridden() ? " (lớp đề xuất theo thang điểm: {$submission->suggested_class})" : ''),
        ]);

        return redirect()->back()->with('status', 'Đã ghi nhận và cập nhật điểm test đầu vào thành công!');
    }

    private function trimScore(mixed $score): string
    {
        return $score === null ? '—' : rtrim(rtrim(number_format((float) $score, 1, '.', ''), '0'), '.');
    }

    public function schedulePlacementTest(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        if (! in_array($customer->stage, ['consulting', 'test_scheduled', 'testing', 'tested'], true)) {
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
                ->where('examiner_id', $examiner->id)
                ->whereBetween('appointment_at', [$conflictStart, $conflictEnd])
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
        ]);
        app(CrmStageService::class)->advanceTo($customer, 'test_scheduled', $request->user(), 'Đặt lịch hẹn test đầu vào.');

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

    /**
     * CM đặt 1–2 buổi học thử cho khách vào buổi học thật của lớp. Học thử là hoạt động
     * trong giai đoạn tư vấn — không đổi stage của lead.
     */
    public function storeTrialBooking(Request $request, CrmStageService $stages, $id)
    {
        $customer = $this->findScopedCustomer($id);
        abort_unless($stages->canMoveForward($request->user()), 403, 'Chỉ Học vụ / Quản lý cơ sở được đặt lịch học thử.');

        $validated = $request->validate([
            'class_session_ids' => 'required|array|min:1|max:'.CrmTrialBooking::MAX_ACTIVE_PER_LEAD,
            'class_session_ids.*' => 'integer|distinct|exists:class_sessions,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if (! in_array($customer->stage, CrmCustomer::TRIAL_BOOKABLE_STAGES, true)) {
            throw ValidationException::withMessages(['class_session_ids' => 'Chỉ đặt học thử cho khách đang tư vấn (chưa chốt, chưa thất bại).']);
        }

        $bookings = DB::transaction(function () use ($customer, $validated, $request) {
            CrmCustomer::whereKey($customer->id)->lockForUpdate()->first();
            $active = $customer->trialBookings()->where('status', '!=', 'cancelled')->get();
            if ($active->count() + count($validated['class_session_ids']) > CrmTrialBooking::MAX_ACTIVE_PER_LEAD) {
                throw ValidationException::withMessages(['class_session_ids' => 'Mỗi khách chỉ học thử tối đa '.CrmTrialBooking::MAX_ACTIVE_PER_LEAD.' buổi.']);
            }

            $sessions = ClassSession::with('classModel')->whereIn('id', $validated['class_session_ids'])->get();
            foreach ($sessions as $session) {
                if ($session->status !== 'scheduled' || $session->date->lt(today())
                    || ! in_array($session->classModel?->status, ['active', 'upcoming'], true)) {
                    throw ValidationException::withMessages(['class_session_ids' => 'Buổi học đã chọn không còn khả dụng.']);
                }
                if ($customer->branch_id && $session->branch_id && $session->branch_id !== $customer->branch_id) {
                    throw ValidationException::withMessages(['class_session_ids' => 'Buổi học thử phải thuộc chi nhánh của khách.']);
                }
                if ($active->contains('class_session_id', $session->id)) {
                    throw ValidationException::withMessages(['class_session_ids' => 'Khách đã được đặt học thử buổi này.']);
                }
            }

            return $sessions->map(fn (ClassSession $session) => CrmTrialBooking::create([
                'customer_id' => $customer->id,
                'class_id' => $session->class_id,
                'class_session_id' => $session->id,
                'booked_by' => $request->user()->id,
                'status' => 'scheduled',
                'notes' => $validated['notes'] ?? null,
            ])->setRelation('session', $session));
        });

        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $request->user()->id,
            'type' => 'trial',
            'content' => 'Đặt lịch học thử: '.$bookings->map(fn (CrmTrialBooking $booking) => $booking->session->classModel?->name.' ('.$booking->session->date->format('d/m/Y').' '.$booking->session->start_time?->format('H:i').')')->implode(', ')
                .(! empty($validated['notes']) ? '. Ghi chú: '.$validated['notes'] : '.'),
        ]);

        return redirect()->back()->with('status', 'Đã đặt lịch học thử cho khách.');
    }

    public function cancelTrialBooking(Request $request, CrmStageService $stages, $id, CrmTrialBooking $booking)
    {
        $customer = $this->findScopedCustomer($id);
        abort_unless($stages->canMoveForward($request->user()), 403);
        abort_unless($booking->customer_id === $customer->id, 404);

        $validated = $request->validate(['reason' => 'required|string|max:1000']);
        if ($booking->status !== 'scheduled') {
            throw ValidationException::withMessages(['reason' => 'Chỉ hủy được buổi học thử đang chờ.']);
        }

        $booking->update(['status' => 'cancelled', 'notes' => trim(($booking->notes ? $booking->notes."\n" : '').'Hủy: '.$validated['reason'])]);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $request->user()->id,
            'type' => 'trial',
            'content' => 'Hủy buổi học thử #'.$booking->id.'. Lý do: '.$validated['reason'],
        ]);

        return redirect()->back()->with('status', 'Đã hủy buổi học thử.');
    }

    public function editCustomer($id)
    {
        $customer = $this->findScopedCustomer($id);
        $branches = Branch::all();
        $salesUsers = User::role('sales_consultant')->where('is_active', true)->get();
        $leadSources = SystemCategory::where('type', 'lead_source')->orderBy('sort_order')->pluck('name');
        if ($leadSources->isEmpty()) {
            $leadSources = collect(CrmCustomer::DEFAULT_SOURCES);
        }

        return view('crm.edit', compact('customer', 'branches', 'salesUsers', 'leadSources'));
    }

    /** Trường theo dõi khi sửa thông tin khách: cột => nhãn (ghi lịch sử trước / sau). */
    private const TRACKED_FIELDS = [
        'name' => 'Họ tên',
        'phone' => 'Số điện thoại',
        'parent_name' => 'Tên phụ huynh',
        'parent_phone' => 'SĐT phụ huynh',
        'email' => 'Email',
        'dob' => 'Ngày sinh',
        'gender' => 'Giới tính',
        'address' => 'Địa chỉ',
        'branch_id' => 'Cơ sở',
        'course_interest' => 'Khóa học quan tâm',
        'source' => 'Nguồn',
        'assigned_user_id' => 'Sales phụ trách',
        'deal_value' => 'Giá trị hợp đồng',
        'next_follow_up_at' => 'Hạn liên hệ tiếp theo',
        'notes' => 'Ghi chú',
    ];

    public function updateCustomer(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        $locked = $customer->isContractLocked();

        // Khách đã Chờ xếp lớp / Đã chốt: trường hợp đồng bị khóa (form gửi readonly/disabled → giữ giá trị cũ).
        if ($locked) {
            foreach (array_keys(CrmCustomer::CONTRACT_LOCKED_FIELDS) as $field) {
                if (! $request->has($field)) {
                    $request->merge([$field => $customer->getRawOriginal($field)]);
                }
            }
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'parent_name' => 'nullable|string|max:255',
            'parent_phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'dob' => 'nullable|date',
            'gender' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'branch_id' => 'required|exists:branches,id',
            'course_interest' => 'nullable|string|max:255',
            'source' => 'required|string|max:255',
            'assigned_user_id' => 'nullable|exists:users,id',
            'deal_value' => 'nullable|numeric|min:0',
            'next_follow_up_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($locked) {
            $changedLocked = collect(CrmCustomer::CONTRACT_LOCKED_FIELDS)
                ->filter(fn (string $label, string $field) => $this->fieldChanged($customer, $field, $validated[$field] ?? null));
            if ($changedLocked->isNotEmpty()) {
                throw ValidationException::withMessages(
                    $changedLocked->mapWithKeys(fn (string $label, string $field) => [
                        $field => "{$label} đã khóa vì khách đã chốt ({$customer->stage_label}). Liên hệ Kế toán / Admin nếu cần điều chỉnh hợp đồng.",
                    ])->all()
                );
            }
        }

        $validated['phone_normalized'] = $this->assertUniqueLead($validated['phone'], $validated['email'] ?? null, $customer->id);
        $this->assertValidParentPhone($validated['parent_phone'] ?? null);
        if ($request->user()->can('lead.assign') && ! empty($validated['assigned_user_id'])) {
            $assignee = User::find($validated['assigned_user_id']);
            if (! $assignee?->is_active || ! $assignee->hasAnyRole(['admin', 'sales_consultant', 'manager'])) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Người phụ trách phải là Sales hoặc quản lý đang hoạt động.']);
            }
        }
        if (! $request->user()->can('lead.assign')) {
            unset($validated['assigned_user_id']);
        }

        $changes = $this->diffCustomer($customer, $validated);
        try {
            DB::transaction(function () use ($customer, $validated, $changes, $request) {
                $customer->update($validated);
                if ($changes !== []) {
                    $this->logCustomerChanges($customer, $changes, $request->user(), 'update', 'Sửa thông tin khách hàng');
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => 'Số điện thoại hoặc email này vừa được phiên khác sử dụng. Vui lòng kiểm tra lại.']);
        }

        return redirect()->route('crm.customers.show', $customer->id)
            ->with('status', 'Cập nhật thông tin khách hàng thành công!');
    }

    protected function fieldChanged(CrmCustomer $customer, string $field, mixed $new): bool
    {
        return $this->displayValue($field, $customer->getAttribute($field)) !== $this->displayValue($field, $new);
    }

    /**
     * So sánh dữ liệu trước / sau theo TRACKED_FIELDS.
     *
     * @return array<string, array{label: string, old: ?string, new: ?string}>
     */
    protected function diffCustomer(CrmCustomer $customer, array $validated): array
    {
        $changes = [];
        foreach (self::TRACKED_FIELDS as $field => $label) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $old = $this->displayValue($field, $customer->getAttribute($field));
            $new = $this->displayValue($field, $validated[$field]);
            if ($old !== $new) {
                $changes[$field] = ['label' => $label, 'old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /** Giá trị hiển thị (chuẩn hoá để so sánh): tên chi nhánh / người dùng, ngày, số tiền. */
    protected function displayValue(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'branch_id' => Branch::find($value)?->name ?? (string) $value,
            'assigned_user_id' => User::find($value)?->name ?? (string) $value,
            'deal_value' => number_format((float) $value, 0, ',', '.').'đ',
            'dob' => Carbon::parse($value)->format('d/m/Y'),
            'next_follow_up_at' => Carbon::parse($value)->format('d/m/Y H:i'),
            default => trim((string) $value),
        };
    }

    /** @param  array<string, array{label: string, old: ?string, new: ?string}>  $changes */
    protected function logCustomerChanges(CrmCustomer $customer, array $changes, ?User $actor, string $type, string $title, ?string $reason = null): CrmCustomerHistory
    {
        $lines = collect($changes)->map(fn (array $change) => "• {$change['label']}: ".($change['old'] ?? '(trống)').' → '.($change['new'] ?? '(trống)'));

        return CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'reason' => $reason,
            'changes' => $changes,
            'content' => $title.($reason ? ". Lý do: {$reason}" : '').":\n".$lines->implode("\n"),
        ]);
    }

    /**
     * Chuyển giai đoạn bằng tay (Kanban / hồ sơ). Luật nằm ở CrmStageService:
     * CM tiến 1 bước, Admin lùi bước kèm lý do, Sales không đổi giai đoạn.
     */
    public function updateStage(Request $request, CrmStageService $stages, $id)
    {
        $customer = $this->findScopedCustomer($id);

        $validated = $request->validate([
            'stage' => ['required', 'string', 'in:'.implode(',', [...array_keys(CrmCustomer::PIPELINE_STAGES), CrmCustomer::STAGE_LOST])],
            'lost_reason' => 'required_if:stage,lost|nullable|string|max:1000',
            'reason' => 'nullable|string|max:1000',
        ]);

        $reason = $validated['stage'] === CrmCustomer::STAGE_LOST ? $validated['lost_reason'] : ($validated['reason'] ?? null);

        try {
            $stages->move($customer, $validated['stage'], $request->user(), $reason);
        } catch (CrmStageTransitionException $e) {
            return $this->stageError($request, $e->getMessage(), $e->status());
        }

        $message = "Đã chuyển khách hàng {$customer->name} sang giai đoạn {$customer->stage_label}!";
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'stage' => $customer->stage,
                'stage_label' => $customer->stage_label,
            ]);
        }

        return redirect()->back()->with('status', $message);
    }

    protected function stageError(Request $request, string $message, int $status = 422)
    {
        if ($status === 403 && ! ($request->wantsJson() || $request->ajax())) {
            abort(403, $message);
        }
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        return redirect()->back()->withErrors(['stage' => $message]);
    }

    /** Nút "Tiếp theo": chuyển lead sang đúng bước kế tiếp (CM). */
    public function nextStage(Request $request, CrmStageService $stages, $id)
    {
        $customer = $this->findScopedCustomer($id);
        $next = $stages->nextStage($customer->stage);
        if (! $next) {
            return $this->stageError($request, 'Lead đã ở giai đoạn cuối hoặc đã thất bại.');
        }

        $request->merge(['stage' => $next]);

        return $this->updateStage($request, $stages, $id);
    }

    public function destroyCustomer($id)
    {
        $customer = $this->findScopedCustomer($id);
        if ($customer->stage === 'won' || $customer->converted_student_id) {
            throw ValidationException::withMessages([
                'customer' => 'Lead đã chốt phải được lưu để bảo toàn lịch sử tuyển sinh, học phí và hoa hồng.',
            ]);
        }
        if ($customer->stage === CrmCustomer::STAGE_LOST) {
            // BA Q1 (bản sửa): khách Thất bại không mở lại và được giữ nguyên để đối soát / audit.
            throw ValidationException::withMessages([
                'customer' => 'Khách Thất bại được giữ để đối soát, không xóa được.',
            ]);
        }
        $name = $customer->name;
        $customer->delete();

        return redirect()->route('crm.customers.index')
            ->with('status', "Đã xóa khách hàng {$name} khỏi danh sách!");
    }

    /** Khách đã xóa (Admin / Quản lý cơ sở): tìm kiếm + khôi phục. */
    public function deletedCustomers(Request $request)
    {
        $this->authorizeDeletedCustomers($request);
        $query = $this->scopeCustomerQuery()->onlyTrashed()->with(['branch', 'assignedUser']);
        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }
        $deletedCustomers = $query->latest('deleted_at')->paginate($request->perPage(20))->withQueryString();

        return view('crm.deleted', compact('deletedCustomers'));
    }

    public function restoreCustomer(Request $request, $id)
    {
        $this->authorizeDeletedCustomers($request);
        $customer = $this->scopeCustomerQuery()->onlyTrashed()->whereKey($id)->firstOrFail();

        $phoneNormalized = $this->normalizePhone($customer->phone);
        $conflict = CrmCustomer::query()->where('phone_normalized', $phoneNormalized)->first(['id', 'code', 'name']);
        if ($conflict) {
            return back()->withErrors(['restore' => "Không thể khôi phục {$customer->name}: SĐT đang thuộc khách {$conflict->code} ({$conflict->name}). Hãy xử lý trùng trước."]);
        }
        $oldEmail = $customer->deleted_email;
        $email = $oldEmail;
        if ($email && CrmCustomer::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->exists()) {
            $email = null; // email đã được khách khác dùng: khôi phục không kèm email.
        }

        DB::transaction(function () use ($customer, $phoneNormalized, $email, $oldEmail, $request) {
            $customer->restore();
            $customer->forceFill([
                'phone_normalized' => $phoneNormalized,
                'email' => $email,
                'deleted_email' => null,
            ])->save();
            CrmCustomerHistory::create([
                'customer_id' => $customer->id,
                'user_id' => $request->user()->id,
                'type' => 'system',
                'content' => 'Khôi phục khách hàng đã xóa.'.($oldEmail && ! $email ? ' Email cũ đã thuộc khách khác nên không khôi phục email.' : ''),
            ]);
        });

        return redirect()->route('crm.customers.show', $customer->id)->with('status', "Đã khôi phục khách hàng {$customer->name}.");
    }

    protected function authorizeDeletedCustomers(Request $request): void
    {
        abort_unless($request->user()->can('lead.delete') && $request->user()->hasAnyRole(['admin', 'manager']), 403, 'Chỉ Admin / Quản lý cơ sở được xem và khôi phục khách đã xóa.');
    }

    /** Phân công lại Sales phụ trách (Admin / Quản lý cơ sở), bắt buộc lý do, ghi lịch sử. */
    public function reassignCustomer(Request $request, $id)
    {
        abort_unless($request->user()->can('lead.assign') && $request->user()->hasAnyRole(['admin', 'manager']), 403, 'Chỉ Admin / Quản lý cơ sở được phân công lại khách.');
        $customer = $this->findScopedCustomer($id);
        $validated = $request->validate([
            'assigned_user_id' => 'required|exists:users,id',
            'reason' => 'required|string|max:1000',
        ], ['reason.required' => 'Vui lòng nhập lý do phân công lại.']);

        $assignee = User::findOrFail($validated['assigned_user_id']);
        if (! $assignee->is_active || ! $assignee->hasAnyRole(['admin', 'sales_consultant', 'manager'])) {
            throw ValidationException::withMessages(['assigned_user_id' => 'Người phụ trách phải là Sales hoặc quản lý đang hoạt động.']);
        }
        if ($assignee->id === $customer->assigned_user_id) {
            throw ValidationException::withMessages(['assigned_user_id' => 'Khách đang do người này phụ trách.']);
        }

        $changes = $this->diffCustomer($customer, ['assigned_user_id' => $assignee->id]);
        DB::transaction(function () use ($customer, $assignee, $changes, $validated, $request) {
            $customer->update(['assigned_user_id' => $assignee->id]);
            $this->logCustomerChanges($customer, $changes, $request->user(), 'assign', 'Phân công lại Sales phụ trách', $validated['reason']);
        });

        return redirect()->route('crm.customers.show', $customer->id)->with('status', "Đã phân công lại khách cho {$assignee->name}.");
    }

    /** Checklist chăm sóc tháng đầu cho khách đã chốt. */
    public function updateCareChecklist(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);
        if (! $customer->converted_student_id) {
            throw ValidationException::withMessages(['care' => 'Checklist chăm sóc tháng đầu chỉ áp dụng cho khách đã chốt.']);
        }
        $validated = $request->validate([
            'items' => 'nullable|array',
            'items.*' => 'string|in:'.implode(',', array_keys(CrmCustomer::CARE_CHECKLIST_ITEMS)),
            'note' => 'nullable|string|max:1000',
        ]);

        $previous = $customer->care_checklist ?? [];
        $checked = array_values(array_unique($validated['items'] ?? []));
        $state = [];
        foreach (array_keys(CrmCustomer::CARE_CHECKLIST_ITEMS) as $key) {
            $state[$key] = in_array($key, $checked, true)
                ? (($previous[$key] ?? null) ?: ['done_at' => now()->toDateTimeString(), 'by' => $request->user()->name])
                : null;
        }
        $label = fn (string $key) => CrmCustomer::CARE_CHECKLIST_ITEMS[$key] ?? $key;
        $newlyDone = collect($state)->filter(fn ($value, $key) => $value && empty($previous[$key]))->keys();
        $undone = collect($previous)->filter(fn ($value, $key) => $value && empty($state[$key]))->keys();

        $customer->update(['care_checklist' => $state]);
        if ($newlyDone->isNotEmpty() || $undone->isNotEmpty() || filled($validated['note'] ?? null)) {
            CrmCustomerHistory::create([
                'customer_id' => $customer->id,
                'user_id' => $request->user()->id,
                'type' => 'care',
                'content' => 'Cập nhật chăm sóc tháng đầu'
                    .($newlyDone->isNotEmpty() ? '. Hoàn thành: '.$newlyDone->map($label)->implode('; ') : '')
                    .($undone->isNotEmpty() ? '. Bỏ đánh dấu: '.$undone->map($label)->implode('; ') : '')
                    .(filled($validated['note'] ?? null) ? '. Ghi chú: '.$validated['note'] : '.'),
            ]);
        }

        return redirect()->route('crm.customers.show', $customer->id)->with('status', 'Đã lưu checklist chăm sóc tháng đầu.');
    }

    /** Bản in hồ sơ khách (khổ A4, tự mở hộp thoại in). */
    public function printCustomer($id)
    {
        $customer = $this->scopeCustomerQuery()
            ->with(['branch', 'assignedUser', 'histories.user', 'submissions.test', 'convertedStudent.currentClass', 'waitingCourse'])
            ->where(fn (Builder $q) => $q->where('id', $id)->orWhere('code', $id))
            ->firstOrFail();
        $latestSubmission = $customer->submissions->first();
        $rubric = $this->rubricSummary($latestSubmission);

        return view('crm.print', compact('customer', 'latestSubmission', 'rubric'));
    }

    /**
     * "Khách chốt — Xác nhận chính thức": ghi danh (từ CRM) chờ Học vụ / Quản lý xác nhận hồ sơ nhập học
     * (đã gửi tài khoản, đã vào nhóm Zalo, đã nhận giáo trình).
     */
    public function confirmations(Request $request)
    {
        $status = $request->input('status') === 'confirmed' ? 'confirmed' : 'pending';
        $scoped = fn () => ClassEnrollment::query()
            ->whereIn('customer_id', $this->scopeCustomerQuery()->select('id'))
            ->whereIn('status', ['pending', 'completed']);
        $query = $scoped()
            ->with(['student', 'classModel.branch', 'classModel.course', 'customer.assignedUser', 'confirmedBy'])
            ->when($status === 'confirmed', fn (Builder $q) => $q->whereNotNull('confirmed_at'), fn (Builder $q) => $q->whereNull('confirmed_at'));
        if ($search = trim((string) $request->input('search'))) {
            $query->whereHas('student', fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }
        // Mockup epic-6 khach-hang-chot-thanh-cong-xac-nhan: lọc Chi nhánh / Lớp học (trong phạm vi được xem).
        $scopedClassIds = $scoped()->select('class_id');
        $filterClasses = ClassModel::whereIn('id', $scopedClassIds)->orderBy('name')->get(['id', 'name', 'code', 'branch_id']);
        $filterBranches = Branch::whereIn('id', $filterClasses->pluck('branch_id')->filter()->unique())->orderBy('name')->get(['id', 'name']);
        if ($request->filled('branch_id')) {
            $query->whereHas('classModel', fn (Builder $q) => $q->where('branch_id', $request->integer('branch_id')));
        }
        if ($request->filled('class_id')) {
            $query->where('class_id', $request->integer('class_id'));
        }
        $enrollments = $query->latest('enrolled_at')->latest('id')->paginate($request->perPage(20))->withQueryString();
        $pendingCount = $scoped()->whereNull('confirmed_at')->count();
        $totalCount = $scoped()->count();

        return view('crm.confirmations', compact('enrollments', 'status', 'pendingCount', 'totalCount', 'filterClasses', 'filterBranches')
            + $this->waitingClassData());
    }

    public function confirmEnrollment(Request $request, ClassEnrollment $enrollment)
    {
        abort_unless($enrollment->customer_id && $this->scopeCustomerQuery()->whereKey($enrollment->customer_id)->exists(), 404);
        $validated = $request->validate([
            'account_sent' => 'nullable|boolean',
            'zalo_group_added' => 'nullable|boolean',
            'curriculum_delivered' => 'nullable|boolean',
            'action' => 'required|in:save,confirm',
        ]);
        if ($enrollment->isConfirmed()) {
            throw ValidationException::withMessages(['enrollment' => 'Học viên này đã được xác nhận chính thức.']);
        }

        $checklist = collect(array_keys(ClassEnrollment::CONFIRMATION_CHECKLIST))
            ->mapWithKeys(fn (string $field) => [$field => (bool) ($validated[$field] ?? false)])->all();
        $confirming = $validated['action'] === 'confirm';
        if ($confirming && in_array(false, $checklist, true)) {
            $missing = collect($checklist)->reject()->keys()->map(fn (string $field) => ClassEnrollment::CONFIRMATION_CHECKLIST[$field]);
            throw ValidationException::withMessages(['enrollment' => 'Chưa đủ hồ sơ để xác nhận chính thức: '.$missing->implode(', ').'.']);
        }

        DB::transaction(function () use ($enrollment, $checklist, $confirming, $request) {
            $enrollment->fill($checklist);
            if ($confirming) {
                $enrollment->fill(['status' => 'completed', 'confirmed_at' => now(), 'confirmed_by' => $request->user()->id]);
                $class = $enrollment->classModel;
                $student = $enrollment->student;
                // Lớp đã khai giảng → học viên chính thức "Đang học"; lớp sắp mở giữ "Chờ khai giảng".
                if ($student && $class && $class->status === 'active' && (! $class->start_date || $class->start_date->lte(today()))
                    && $student->status === Student::INITIAL_STATUS) {
                    $student->update(['status' => 'studying']);
                }
                CrmCustomerHistory::create([
                    'customer_id' => $enrollment->customer_id,
                    'user_id' => $request->user()->id,
                    'type' => 'system',
                    'content' => 'Xác nhận chính thức nhập học lớp '.($class?->name ?? '#'.$enrollment->class_id)
                        .': đã gửi tài khoản, đã vào nhóm Zalo, đã nhận giáo trình.',
                ]);
            }
            $enrollment->save();
        });

        return back()->with('status', $confirming
            ? 'Đã xác nhận chính thức học viên '.($enrollment->student?->name ?? '').'.'
            : 'Đã lưu tiến độ hồ sơ nhập học.');
    }

    public function addNote(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);

        $validated = $request->validate([
            'content' => 'required_unless:type,result|nullable|string|max:1000',
            'type' => 'required|string|in:call,message,meet,test,note,result',
            // Mockup Chi tiết khách — "Gửi kết quả & Phản hồi": ngày gửi KQ cho phụ huynh + phản hồi của phụ huynh.
            'sent_at' => 'required_if:type,result|nullable|date|before_or_equal:now',
        ], [
            'content.required_unless' => 'Vui lòng nhập nội dung ghi chú.',
            'sent_at.required_if' => 'Vui lòng chọn ngày gửi kết quả cho phụ huynh.',
            'sent_at.before_or_equal' => 'Ngày gửi kết quả không được ở tương lai.',
        ]);

        $content = $validated['content'] ?? '';
        if ($validated['type'] === 'result') {
            $content = 'Đã gửi kết quả test cho phụ huynh lúc '.Carbon::parse($validated['sent_at'])->format('H:i d/m/Y').'.'
                .(filled($content) ? "\nPhản hồi của phụ huynh: ".$content : '');
        }

        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => $validated['type'],
            'content' => $content,
        ]);

        return redirect()->route('crm.customers.show', $customer->id)
            ->with('status', 'Đã lưu nhật ký chăm sóc thành công!');
    }

    public function wonCustomers(Request $request)
    {
        $query = $this->scopeCustomerQuery()->where('stage', 'won');
        // Lọc lớp chỉ trong các lớp của khách đã chốt mình được xem (mockup: Chi nhánh / Lớp học / Tìm kiếm).
        $filterClasses = ClassModel::query()
            ->whereIn('id', Student::query()->whereIn('id', (clone $query)->whereNotNull('converted_student_id')->select('converted_student_id'))
                ->whereNotNull('current_class_id')->select('current_class_id'))
            ->orderBy('name')->get(['id', 'name', 'code']);
        $this->applyListFilters($query, $request, 'converted_at');
        if ($request->filled('class_id')) {
            $query->whereHas('convertedStudent', fn (Builder $student) => $student->where('current_class_id', $request->integer('class_id')));
        }

        if (in_array($request->input('export'), ['xlsx', 'csv'], true)) {
            return $this->exportWon((clone $query)->with(['branch', 'assignedUser', 'convertedStudent.tuition', 'convertedStudent.currentClass'])->latest('converted_at')->get(), $request->input('export'));
        }

        $studentIds = (clone $query)->whereNotNull('converted_student_id')->select('converted_student_id');
        $totalCount = (clone $query)->count();
        $totalContractAmount = (float) (clone $query)->sum('deal_value');
        $totalCollectedAmount = (float) StudentTuition::whereIn('student_id', $studentIds)->sum('paid_amount');
        $totalDebtAmount = (float) StudentTuition::whereIn('student_id', $studentIds)->sum('debt_amount');

        $wonCustomers = $query
            ->with(['branch', 'assignedUser', 'convertedStudent.tuition.receipts', 'convertedStudent.currentClass', 'convertedStudent.enrollments'])
            ->latest('converted_at')->latest()
            ->paginate($request->perPage(20))->withQueryString();

        return view('crm.won', compact('wonCustomers', 'totalCount', 'totalContractAmount', 'totalCollectedAmount', 'totalDebtAmount', 'filterClasses')
            + $this->waitingClassData() + $this->listFilterOptions());
    }

    protected function exportWon(Collection $customers, string $format)
    {
        $rows = $customers->map(fn (CrmCustomer $c) => [
            $c->code,
            $c->name,
            $c->phone,
            $c->parent_name,
            $c->branch?->name,
            $c->source,
            $c->course_interest,
            $c->convertedStudent?->currentClass?->name ?? 'Chưa xếp lớp',
            (float) $c->deal_value,
            (float) ($c->convertedStudent?->tuition?->paid_amount ?? 0),
            (float) ($c->convertedStudent?->tuition?->debt_amount ?? 0),
            $c->assignedUser?->name,
            $c->converted_at?->format('d/m/Y H:i'),
        ])->all();

        return $this->downloadTable('khach-chot-thanh-cong', ['Mã KH', 'Họ tên', 'SĐT', 'Phụ huynh', 'Cơ sở', 'Nguồn', 'Khóa đăng ký', 'Lớp', 'Giá trị HĐ', 'Đã thu (duyệt)', 'Còn nợ', 'Sales phụ trách', 'Ngày chốt'], $rows, $format);
    }

    /** Xuất bảng ra Excel (.xlsx) hoặc CSV (UTF-8 BOM) qua maatwebsite/excel. */
    protected function downloadTable(string $name, array $headings, array $rows, string $format = 'xlsx')
    {
        $format = $format === 'csv' ? 'csv' : 'xlsx';

        return Excel::download(
            new ArrayExport($headings, $rows),
            $name.'-'.now()->format('Ymd-His').'.'.$format,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX
        );
    }

    public function closingWizard(Request $request)
    {
        $selectedCustomerId = $request->integer('customer_id');
        $selectedCustomer = null;
        if ($selectedCustomerId) {
            $selectedCustomer = $this->findScopedCustomer($selectedCustomerId);
            if (! in_array($selectedCustomer->stage, CrmCustomer::CLOSABLE_STAGES, true)) {
                return redirect()->route('crm.pipeline')
                    ->withErrors(['stage' => 'Lead chưa sẵn sàng để chốt.']);
            }
        }
        $customers = $this->scopeCustomerQuery()
            ->with(['branch', 'latestSubmission'])
            ->whereIn('stage', CrmCustomer::CLOSABLE_STAGES)
            ->when($selectedCustomerId, fn (Builder $query, int $customerId) => $query->orderByRaw('id = ? desc', [$customerId]))
            ->latest()
            ->get()
            // Mockup quy-trinh-chot-xep-lop: "Trình độ" của khách (lớp xếp sau test) + từ khóa để gợi ý lớp phù hợp.
            ->each(function (CrmCustomer $customer) {
                $customer->setAttribute('level_label', $customer->latestSubmission?->finalClass() ?? $customer->course_interest);
                $customer->setAttribute('level_keys', $this->trialLevelKeywords($customer, $customer->latestSubmission));
            });
        $branches = Branch::all();
        $courses = Course::where('is_active', true)->get();
        // Lớp đang học + lớp sắp khai giảng (chưa bắt đầu), còn chỗ.
        $classes = $this->enrollableClassesQuery()
            ->when($selectedCustomer?->branch_id, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->with(['course.level', 'branch', 'teacher'])
            ->withCount(['enrollments as active_enrollments_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'completed'])])
            ->orderByRaw("CASE WHEN status = 'upcoming' THEN 0 ELSE 1 END")
            ->orderBy('start_date')
            ->get()
            ->filter(fn (ClassModel $class) => $class->max_capacity <= 0 || $class->active_enrollments_count < $class->max_capacity)
            ->each(function (ClassModel $class) {
                $class->setAttribute('remaining_seats', $class->max_capacity > 0 ? max(0, $class->max_capacity - $class->active_enrollments_count) : null);
                $class->setAttribute('needed_to_open', $class->status === 'upcoming' ? max(0, (int) $class->min_students - $class->active_enrollments_count) : 0);
                $class->setAttribute('level_haystack', Str::upper(implode(' ', array_filter([
                    $class->name, $class->level, $class->course?->name, $class->course?->level?->name,
                ]))));
            })
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

    /**
     * Chốt & Xếp lớp (BA chốt 2026-09-25):
     * - Cho phép từ Đang tư vấn (nhánh không test), Đã test, Gửi kết quả.
     * - Luôn tạo hồ sơ học viên (HV-), tài khoản portal và học phí.
     * - Có lớp → ghi danh + Đã chốt. "Xếp lớp sau" → không ghi danh, Chờ xếp lớp; học phí tính theo khóa.
     * - Không bắt buộc thu tiền: chưa đóng học phí đăng ký → tạo task "Nhắc thu học phí" cho người phụ trách.
     */
    public function processClosingWizard(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:crm_customers,id',
            'class_id' => 'nullable|exists:classes,id',
            'course_id' => 'nullable|required_without:class_id|exists:courses,id',
            'fee_paid_at_closing' => 'nullable|boolean',
            'promotion_id' => 'nullable|exists:promotions,id',
            'fee_items' => 'nullable',
            'prepaid_amount' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|in:cash,transfer,pos,split',
            'split_cash_amount' => 'nullable|numeric|min:0',
            'split_transfer_amount' => 'nullable|numeric|min:0',
            'split_pos_amount' => 'nullable|numeric|min:0',
            'transfer_memo' => 'nullable|string|max:255',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'bill_notes' => 'nullable|string|max:2000',
        ], [
            'course_id.required_without' => 'Chọn khóa học khi xếp lớp sau để tính học phí.',
        ]);

        $paidAmount = (float) ($validated['paid_amount'] ?? 0);
        $prepaidAmount = (float) ($validated['prepaid_amount'] ?? 0);
        // Không gửi cờ → suy ra từ số tiền thu (tương thích form cũ).
        $feePaid = array_key_exists('fee_paid_at_closing', $validated) && $validated['fee_paid_at_closing'] !== null
            ? (bool) $validated['fee_paid_at_closing']
            : ($paidAmount + $prepaidAmount) > 0;
        if ($feePaid && ($paidAmount + $prepaidAmount) <= 0) {
            throw ValidationException::withMessages(['paid_amount' => 'Đã tích "Đã đóng học phí đăng ký" thì phải nhập số tiền đã thu.']);
        }
        if (! $feePaid && $paidAmount > 0) {
            throw ValidationException::withMessages(['paid_amount' => 'Chưa đóng học phí thì không ghi nhận khoản thu; hệ thống sẽ tạo task nhắc thu.']);
        }
        if (($paidAmount > 0 || $prepaidAmount > 0) && empty($validated['payment_method'])) {
            throw ValidationException::withMessages(['payment_method' => 'Vui lòng chọn phương thức thanh toán.']);
        }
        $paymentMethod = $validated['payment_method'] ?? 'cash';

        $result = DB::transaction(function () use ($request, $validated, $paidAmount, $prepaidAmount, $feePaid, $paymentMethod): array {
            $customer = $this->scopeCustomerQuery()->lockForUpdate()->findOrFail($validated['customer_id']);

            if ($customer->converted_student_id) {
                $existingTuition = StudentTuition::where('student_id', $customer->converted_student_id)->latest()->firstOrFail();

                return [$customer->convertedStudent, $existingTuition, null, true];
            }
            if (! in_array($customer->stage, CrmCustomer::CLOSABLE_STAGES, true)) {
                throw ValidationException::withMessages(['customer_id' => 'Chỉ chốt được Lead ở bước Đang tư vấn, Đã test hoặc Gửi kết quả.']);
            }

            $class = null;
            if (! empty($validated['class_id'])) {
                $class = ClassModel::with(['course', 'branch'])->lockForUpdate()->findOrFail($validated['class_id']);
                if (! $this->isEnrollableClass($class) || ! $class->course || ! $class->course->is_active) {
                    throw ValidationException::withMessages(['class_id' => 'Lớp hoặc khóa học không còn hoạt động.']);
                }
                if ($customer->branch_id && $class->branch_id && $customer->branch_id !== $class->branch_id) {
                    throw ValidationException::withMessages(['class_id' => 'Lớp được chọn phải thuộc cùng chi nhánh với Lead.']);
                }
                if ($class->max_capacity > 0 && $class->enrollments()->whereIn('status', ['pending', 'completed'])->count() >= $class->max_capacity) {
                    throw ValidationException::withMessages(['class_id' => 'Lớp đã đủ sĩ số, vui lòng chọn lớp khác.']);
                }
                $course = $class->course;
                $baseTuition = (float) ($class->tuition_fee > 0 ? $class->tuition_fee : $course->tuition_fee);
            } else {
                $course = Course::whereKey($validated['course_id'])->where('is_active', true)->first();
                if (! $course) {
                    throw ValidationException::withMessages(['course_id' => 'Khóa học không còn hoạt động.']);
                }
                // Chưa có lớp: học phí theo giá niêm yết của khóa (trừ ưu đãi), không phụ thuộc lớp.
                $baseTuition = (float) $course->tuition_fee;
            }
            if ($baseTuition <= 0) {
                throw ValidationException::withMessages([$class ? 'class_id' : 'course_id' => 'Lớp / khóa học chưa được cấu hình học phí.']);
            }
            $branchId = $class?->branch_id ?? $customer->branch_id;
            $branch = $class?->branch ?? ($branchId ? Branch::find($branchId) : null);

            $needsBankAccount = $paidAmount > 0 && ($paymentMethod === 'transfer'
                || ($paymentMethod === 'split' && (float) ($validated['split_transfer_amount'] ?? 0) > 0));
            $bankAccount = ! empty($validated['bank_account_id'])
                ? BankAccount::whereKey($validated['bank_account_id'])->where('is_active', true)->first()
                : null;
            if ($needsBankAccount && ! $bankAccount) {
                throw ValidationException::withMessages(['bank_account_id' => 'Chuyển khoản cần một tài khoản ngân hàng đang hoạt động.']);
            }
            if ($bankAccount?->branch_id && $branchId && $bankAccount->branch_id !== $branchId) {
                throw ValidationException::withMessages(['bank_account_id' => 'Tài khoản thu tiền không thuộc chi nhánh của lớp.']);
            }

            $promotion = null;
            $discount = 0.0;
            if (! empty($validated['promotion_id'])) {
                $promotion = Promotion::whereKey($validated['promotion_id'])->lockForUpdate()->first();
                if (! $promotion?->isApplicable($branchId, $course->id)) {
                    throw ValidationException::withMessages(['promotion_id' => 'Ưu đãi không còn hiệu lực hoặc không áp dụng cho khóa / lớp đã chọn.']);
                }
                $discount = $promotion->calculateDiscount($baseTuition);
            }

            $feeItems = $this->resolveFeeItems($request->input('fee_items'));
            $otherFees = array_sum(array_column($feeItems, 'amount'));
            $contractTotal = max(0, $baseTuition - $discount + $otherFees);
            if ($prepaidAmount > 0 && ! $request->user()->hasAnyRole(['admin', 'manager'])) {
                throw ValidationException::withMessages(['prepaid_amount' => 'Khoản thu trước phải được quản lý xác nhận.']);
            }
            if ($prepaidAmount > $contractTotal) {
                throw ValidationException::withMessages(['prepaid_amount' => 'Khoản thu trước vượt quá giá trị hợp đồng.']);
            }

            $netDue = $contractTotal - $prepaidAmount;
            if ($paidAmount > $netDue) {
                throw ValidationException::withMessages(['paid_amount' => 'Số tiền thu vượt quá số tiền còn phải nộp.']);
            }

            $splitDetails = null;
            if ($paymentMethod === 'split' && $paidAmount > 0) {
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
                    'branch_id' => $branchId,
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
                'branch_id' => $branchId,
                'current_class_id' => $class?->id,
                'target' => $customer->course_interest ?? $course->name,
                'entrance_score' => $customer->test_score ?? 'Chưa test',
                'status' => Student::INITIAL_STATUS,
                'total_lessons' => $course->total_lessons,
            ]);

            if ($class) {
                $this->enrollStudent($student, $class, $customer);
            }

            // Memo phải sinh từ mã học viên thật sau khi tạo hồ sơ — giá trị preview phía client
            // chỉ mang tính minh hoạ (không biết trước mã HV) nên luôn bị ghi đè.
            $transferMemo = self::buildTransferMemo($student->code, $student->name, $class?->name ?? $course->code ?? $course->name, $branch?->code);
            $tuition = StudentTuition::create([
                'student_id' => $student->id,
                'class_id' => $class?->id,
                'branch_id' => $branchId,
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
                    'payment_method' => $paymentMethod,
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
                    'payment_method' => $paymentMethod,
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
                'deal_value' => $contractTotal,
                'converted_student_id' => $student->id,
                'converted_by' => Auth::id(),
                'commission_user_id' => $customer->assigned_user_id ?? Auth::id(),
                'converted_at' => now(),
                'fee_paid_at_closing' => $feePaid,
                'waiting_course_id' => $class ? $customer->waiting_course_id : $course->id,
                'waiting_branch_id' => $class ? $customer->waiting_branch_id : $branchId,
                'waiting_since' => $class ? $customer->waiting_since : today(),
            ]);
            if ($promotion) {
                $promotion->increment('used_count');
            }
            foreach ($feeItems as $feeItem) {
                MerchandiseItem::whereKey($feeItem['id'])->decrement('stock_quantity');
            }
            app(CrmStageService::class)->advanceTo(
                $customer,
                $class ? 'won' : 'waiting_class',
                $request->user(),
                'Chốt hợp đồng qua Chốt & Xếp lớp (Tổng giá trị: '.number_format($contractTotal).'đ, '
                    .($class ? "lớp {$class->name}" : "khóa {$course->name}, xếp lớp sau")
                    .($feePaid ? ', đã đóng học phí đăng ký' : ', chưa đóng học phí').').'
            );
            if (! $feePaid) {
                $this->createFeeReminderTask($customer, $student, $tuition, $request->user());
            }

            return [$student, $tuition, $temporaryPassword, false];
        }, 3);

        [$student, $tuition, $temporaryPassword, $alreadyConverted] = $result;
        $message = $alreadyConverted
            ? 'Lead này đã được chốt trước đó; hệ thống không tạo dữ liệu trùng.'
            : "Đã chốt deal và tạo hồ sơ {$student->code}."
                .($student->current_class_id ? '' : ' Học viên đang ở danh sách Chờ xếp lớp.')
                .($tuition->receipts()->exists() ? ' Khoản thu đang chờ Kế toán/Admin duyệt.' : ' Đã tạo task nhắc thu học phí cho người phụ trách.');

        return redirect()->route('crm.customers.won')
            ->with('status', $message)
            ->with('bill_url', route('crm.tuition-bill', ['id' => $tuition->id]))
            ->with('student_account_email', $student->email)
            ->with('temporary_password', $temporaryPassword);
    }

    /** Lớp nhận ghi danh khi chốt: đang học, hoặc sắp khai giảng (chưa tới ngày bắt đầu). */
    protected function enrollableClassesQuery(): Builder
    {
        return ClassModel::query()->where(fn (Builder $query) => $query
            ->where('status', 'active')
            ->orWhere(fn (Builder $upcoming) => $upcoming->where('status', 'upcoming')
                ->where(fn (Builder $date) => $date->whereNull('start_date')->orWhereDate('start_date', '>=', today()))));
    }

    protected function isEnrollableClass(ClassModel $class): bool
    {
        return $class->status === 'active'
            || ($class->status === 'upcoming' && (! $class->start_date || $class->start_date->gte(today())));
    }

    /** Ghi danh học viên vào lớp + cập nhật lớp hiện tại (lớp đã được lock & kiểm tra sĩ số). */
    protected function enrollStudent(Student $student, ClassModel $class, CrmCustomer $customer): void
    {
        ClassEnrollment::create([
            'student_id' => $student->id,
            'class_id' => $class->id,
            'customer_id' => $customer->id,
            'enrolled_at' => now(),
            'curriculum_delivered' => false,
            'zalo_group_added' => false,
            'status' => 'pending',
        ]);
        $student->forceFill(['current_class_id' => $class->id])->save();
    }

    /** Chốt khi chưa đóng học phí → task "Nhắc thu học phí" cho người phụ trách lead. */
    protected function createFeeReminderTask(CrmCustomer $customer, Student $student, StudentTuition $tuition, User $actor): WorkTask
    {
        return WorkTask::create([
            'title' => "Nhắc thu học phí: {$student->name} ({$student->code})",
            'description' => "Học viên {$student->name} ({$student->code}) đã chốt từ Lead {$customer->code} nhưng chưa đóng học phí đăng ký. "
                .'Số tiền cần thu: '.number_format((float) $tuition->final_amount).'đ. '
                .'Hồ sơ Lead: '.route('crm.customers.show', $customer->id).' · Phiếu học phí: '.route('crm.tuition-bill', ['id' => $tuition->id]),
            'creator_id' => $actor->id,
            'assignee_id' => $customer->assigned_user_id ?? $actor->id,
            'branch_id' => $student->branch_id,
            'task_type' => 'one_time',
            'time_slot_category' => 'during',
            'due_date' => today()->addDays(3),
            'due_time' => '17:00',
            'status' => 'new',
        ]);
    }

    /**
     * Học vụ gán lớp cho học viên đang Chờ xếp lớp: lock lớp, kiểm tra sĩ số / chi nhánh / khóa,
     * ghi danh, cập nhật lớp hiện tại + lớp của học phí, lead Chờ xếp lớp → Đã chốt.
     */
    public function assignClass(Request $request, CrmStageService $stages, $id)
    {
        $validated = $request->validate(['class_id' => 'required|exists:classes,id']);

        DB::transaction(function () use ($request, $stages, $validated, $id) {
            $customer = $this->scopeCustomerQuery()
                ->where(fn (Builder $query) => $query->where('id', $id)->orWhere('code', $id))
                ->lockForUpdate()
                ->firstOrFail();
            $student = $customer->converted_student_id ? Student::lockForUpdate()->find($customer->converted_student_id) : null;
            if ($customer->stage !== 'waiting_class' || ! $student) {
                throw ValidationException::withMessages(['class_id' => 'Chỉ gán lớp cho học viên đang Chờ xếp lớp.']);
            }

            $class = ClassModel::with('course')->lockForUpdate()->findOrFail($validated['class_id']);
            if (! in_array($class->status, ['active', 'upcoming'], true) || ! $class->course?->is_active) {
                throw ValidationException::withMessages(['class_id' => 'Lớp hoặc khóa học không còn hoạt động.']);
            }
            $branchId = $student->branch_id ?? $customer->branch_id;
            if ($branchId && $class->branch_id !== $branchId) {
                throw ValidationException::withMessages(['class_id' => 'Lớp phải thuộc chi nhánh của học viên.']);
            }
            if ($customer->waiting_course_id && $class->course_id !== $customer->waiting_course_id) {
                throw ValidationException::withMessages(['class_id' => 'Lớp phải thuộc khóa học đã chốt ('.($customer->waitingCourse?->name ?? 'khóa đã chọn').').']);
            }
            if ($class->max_capacity > 0 && $class->enrollments()->whereIn('status', ['pending', 'completed'])->count() >= $class->max_capacity) {
                throw ValidationException::withMessages(['class_id' => 'Lớp đã đủ sĩ số, vui lòng chọn lớp khác.']);
            }

            $this->enrollStudent($student, $class, $customer);
            StudentTuition::where('student_id', $student->id)->whereNull('class_id')->update(['class_id' => $class->id]);
            $customer->update(['waiting_since' => null]);
            $stages->advanceTo($customer, 'won', $request->user(), "Học vụ gán lớp {$class->name} cho học viên {$student->code}.");
        }, 3);

        return redirect()->back()->with('status', 'Đã gán lớp cho học viên và chuyển Lead sang Đã chốt.');
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

    public function lostDeals(Request $request)
    {
        $query = $this->scopeCustomerQuery()->with(['branch', 'assignedUser'])->where('stage', 'lost');
        // Mockup khach-khong-chot: tìm cả theo lý do không chốt.
        $this->applyListFilters($query, $request, 'lost_at', ['lost_reason']);

        if (in_array($request->input('export'), ['xlsx', 'csv'], true)) {
            $rows = (clone $query)->latest('lost_at')->get()->map(fn (CrmCustomer $c) => [
                $c->code,
                $c->name,
                $c->phone,
                $c->branch?->name,
                $c->source,
                $c->course_interest,
                (float) $c->deal_value,
                $c->lost_reason,
                $c->assignedUser?->name,
                $c->lost_at?->format('d/m/Y H:i'),
                $c->notes,
            ])->all();

            return $this->downloadTable('khach-khong-chot', ['Mã KH', 'Họ tên', 'SĐT', 'Cơ sở', 'Nguồn', 'Khóa quan tâm', 'Giá trị dự kiến', 'Lý do thất bại', 'Sales phụ trách', 'Ngày thất bại', 'Ghi chú'], $rows, $request->input('export'));
        }

        $lostTotal = $this->scopeCustomerQuery()->where('stage', 'lost')->count();
        $lostCustomers = $query->latest('lost_at')->latest()->paginate($request->perPage(20))->withQueryString();

        return view('crm.lost-deals', compact('lostCustomers', 'lostTotal') + $this->listFilterOptions());
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

        // Admin chọn mọi chi nhánh; vai trò khác chỉ chi nhánh của mình (dữ liệu vẫn giới hạn bởi scopeVisibleTo).
        $reportUser = $request->user();
        $branches = Branch::where('is_active', true)
            ->when(! $reportUser->hasRole('admin'), fn (Builder $q) => $q->whereIn('id', CrmCustomer::branchIdsOf($reportUser)))
            ->orderBy('name')->get();

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

        // Mockup bao-cao-doanh-so — "Lý do khách không chốt" (log chi tiết theo khách trong kỳ).
        $lostReasons = $lostCurrent->load('assignedUser')->sortByDesc(fn (CrmCustomer $c) => $c->lost_at ?? $c->created_at)->take(10)->values();

        $metricTotalLeads = $totalLeads;
        $metricWonDeals = $wonDeals;
        $metricConversionRate = $conversionRate;
        $metricLostDeals = $lostDeals;

        // Phân bố trạng thái của cohort, giữ nguyên hai nhánh test/không-test.
        $stageDescriptions = [
            'new' => 'Chưa bắt đầu tư vấn',
            'consulting' => 'Đang xác định lộ trình',
            'test_scheduled' => 'Đã đặt lịch kiểm tra',
            'testing' => 'Đang làm bài test',
            'tested' => 'Đã có kết quả đầu vào',
            'result_sent' => 'Đã gửi kết quả cho khách',
            'waiting_class' => 'Đã chốt, chờ Học vụ xếp lớp',
            'won' => 'Đã chốt và xếp lớp',
        ];
        $stageIcons = [
            'new' => 'person_add', 'consulting' => 'forum', 'test_scheduled' => 'calendar_today', 'testing' => 'edit_note',
            'tested' => 'task_alt', 'result_sent' => 'mail', 'waiting_class' => 'pending_actions', 'won' => 'verified',
        ];
        $cohortByStage = $allCurrent->countBy('stage');
        $funnelStages = collect(CrmCustomer::PIPELINE_STAGES)->map(function (string $label, string $stage) use ($cohortByStage, $totalLeads, $stageDescriptions, $stageIcons) {
            $count = (int) $cohortByStage->get($stage, 0);
            $style = CrmCustomer::stageStyle($stage);

            return [
                'name' => $label, 'count' => $count,
                'percent' => $totalLeads > 0 ? round(($count / $totalLeads) * 100, 1) : 0,
                'bar_color' => $style['bar'], 'text_color' => $style['text'], 'desc' => $stageDescriptions[$stage],
                'icon' => $stageIcons[$stage],
            ];
        })->values()->all();

        // 3. Lấy cấu hình Hoa hồng từ bảng commission_tiers (cùng luật chọn bậc với tính lương)
        $commissionTiers = CommissionTier::orderByDesc('min_revenue')->get();

        // Cùng căn cứ với bảng lương (SalesCommissionService): bậc hiệu lực tại cuối kỳ báo cáo,
        // chưa đạt mốc nào thì không có hoa hồng.
        $commissionService = app(\App\Services\SalesCommissionService::class);
        $calculateCommission = fn (float $revenue) => $commissionService->commissionFor($revenue, $endDate);

        // 4. Bảng hiệu suất theo nhân viên tư vấn tuyển sinh (100% Real from Users in Database)
        try {
            $salesUsers = User::role('sales_consultant')->get();
        } catch (\Throwable $e) {
            $salesUsers = collect();
        }

        if ($salesUsers->isEmpty()) {
            $salesUsers = User::whereHas('crmCustomers')->get();
        }
        $salesUsers = $this->scopeReportReps($salesUsers, $request->user());

        // Doanh số = tiền thực thu của khách mới (phiếu duyệt trong kỳ, gồm giáo trình/đồ dùng) — A6.
        $collectedBySales = $commissionService->collectedBySales($startDate, $endDate, null, $query);

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

        if (in_array($request->input('export'), ['xlsx', 'csv'], true)) {
            return $this->exportReps($repsData, $startDate, $endDate, $request->input('export'));
        }

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
            'lostReasons',
            'repsData',
            'commissionTiers'
        ));
    }

    /**
     * Bảng hiệu suất theo người phụ trách: Sales chỉ thấy dòng của mình; Quản lý cơ sở / Học vụ
     * chỉ thấy nhân sự thuộc chi nhánh mình; Admin thấy tất cả.
     */
    protected function scopeReportReps(Collection $users, User $viewer): Collection
    {
        if ($viewer->hasRole('admin')) {
            return $users;
        }
        if ($viewer->hasAnyRole(['manager', 'academic_staff', 'academic_lead'])) {
            $branchIds = CrmCustomer::branchIdsOf($viewer);

            return $users->filter(fn (User $user) => in_array((int) $user->branch_id, $branchIds, true)
                || $user->branches()->whereIn('branches.id', $branchIds)->exists())->values();
        }

        return $users->where('id', $viewer->id)->values();
    }

    protected function exportReps(array $repsData, Carbon $startDate, Carbon $endDate, string $format)
    {
        $rows = array_map(fn (array $rep) => [
            $rep['name'],
            $rep['leads'],
            $rep['won'],
            $rep['rate'],
            (float) $rep['revenue'],
            (float) $rep['commission_amount'],
            $rep['tier_name'],
            $rep['rating'],
        ], $repsData);

        return $this->downloadTable(
            'bao-cao-doanh-so-'.$startDate->format('Ymd').'-'.$endDate->format('Ymd'),
            ['Người phụ trách', 'Số lead', 'Chốt thành công', '% Chốt', 'Doanh thu thực thu', 'Hoa hồng', 'Bậc hoa hồng', 'Đánh giá'],
            $rows,
            $format
        );
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
