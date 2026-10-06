<?php

namespace App\Http\Controllers;

use App\Exceptions\CrmStageTransitionException;
use App\Exceptions\InvoiceRangeExhaustedException;
use App\Exceptions\PaperInvoiceNumberChangedException;
use App\Exports\ArrayExport;
use App\Http\Concerns\RendersModals;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\InvoiceConfiguration;
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
use App\Services\Crm\AppointmentConfirmation;
use App\Services\Crm\CrmBranchTransferService;
use App\Services\Crm\LeadOwners;
use App\Services\Crm\PlacementLevelMatcher;
use App\Services\Crm\TrialSlotFinder;
use App\Services\Crm\WaitingLeadPlacement;
use App\Services\CrmStageService;
use App\Services\FirstMonthCareService;
use App\Services\Merchandise\StockService;
use App\Services\NotificationService;
use App\Services\PlacementPortalLinkService;
use App\Services\PlacementRubricService;
use App\Services\PlacementSubmissionLinker;
use App\Services\SafeUploadService;
use App\Services\SalesCommissionService;
use App\Services\Students\ClassStartActivation;
use App\Services\Tuition\SessionLedger;
use App\Support\Approvals\ApprovableSource;
use App\Support\Approvals\ApprovalInboxService;
use App\Support\CenterInfo;
use App\Support\DataScope;
use App\Support\Money;
use App\Support\Rbac;
use App\Support\Roles;
use App\Support\TemporaryPassword;
use App\Support\TransferMemo;
use App\Support\TuitionBranchScope;
use App\Support\Ui;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

class CrmController extends Controller
{
    use RendersModals;

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
                'phone' => "Số điện thoại này đã tồn tại trong CRM (khách {$duplicate->short_code}).",
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
            ->withLastCare()
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
                'amount' => Money::format($group->sum('deal_value')),
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
                    'tuition' => Money::format($c->deal_value),
                    'agent' => $c->assignedUser?->name ?? 'Chưa phân công',
                    'days' => $c->created_at->diffForHumans(),
                    'score' => $c->test_score ?? 'Chưa test',
                    'has_test_result' => filled($c->test_score),
                    'confirmed' => $confirmedIds->has($c->id),
                    'status' => $c->converted_student_id ? ($c->convertedStudent?->tuition?->status_label ?? 'Chưa có học phí') : null,
                    'payment_status' => $c->convertedStudent?->tuition?->status,
                    // Đồng hồ SLA liên hệ 24h / chăm sóc 72h (CrmCustomer::contactSla) — xanh / vàng / đỏ, không chặn thao tác.
                    'sla' => $sla = $c->contactSlaPayload(),
                    'follow_up_status' => $sla && $sla['state'] !== 'on_time' ? $sla['state'] : null,
                    'follow_up_at' => $sla ? Carbon::parse($sla['deadline'])->format('d/m/Y H:i') : null,
                    // Mockup: Quá hạn / Sắp hết hạn / Còn hạn + "10:30 Hôm nay", "09:00 Mai".
                    'follow_up_state' => $sla['state'] ?? null,
                    'follow_up_label' => $sla ? $this->relativeDeadlineLabel(Carbon::parse($sla['deadline'])) : null,
                ])->values()->all(),
            ];
        }

        $user = $request->user();
        $stagePermissions = [
            'canForward' => $stages->canMoveForward($user),
            'canBackward' => $stages->canMoveBackward($user),
            'canConvert' => $user->can('lead.convert'),
            // Sales không chuyển giai đoạn nhưng được đánh Thất bại (quyền riêng lead.mark_lost).
            'canMarkLost' => $user->can('lead.mark_lost'),
            'order' => array_keys(CrmCustomer::PIPELINE_STAGES),
            'closed' => CrmCustomer::CLOSED_STAGES,
            'closable' => CrmCustomer::CLOSABLE_STAGES,
            'labels' => CrmCustomer::PIPELINE_STAGES,
        ];

        return Inertia::render('Crm/Pipeline', ['stages' => $stageColumns, 'stagePermissions' => $stagePermissions] + $this->listFilterOptions());
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
                        ->orWhere('parent_phone', 'like', "%{$search}%")
                        // SĐT phụ huynh không có cột chuẩn hoá: bỏ khoảng trắng / dấu chấm / gạch trước khi so.
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(COALESCE(parent_phone, ''), ' ', ''), '.', ''), '-', '') LIKE ?", ["%{$digits}%"]));
            });
        }
        // Phạm vi "Toàn hệ thống" lọc mọi chi nhánh; phạm vi "Chi nhánh" chỉ lọc trong chi nhánh của mình (ngoài phạm vi → bỏ qua).
        $leadScope = DataScope::level($request->user(), 'lead');
        if ($request->filled('branch_id') && ($leadScope === DataScope::ALL
            || $leadScope === DataScope::BRANCH && in_array($request->integer('branch_id'), $request->user()->branchIds(), true))) {
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

    /**
     * Lựa chọn của bộ lọc chung (Pipeline / Danh sách / Khách chốt / Khách không chốt) — dạng [{value, label}] cho <UiSelect>.
     *
     * @return array{filterBranches: list<array{value: mixed, label: mixed}>, filterSales: list<array{value: mixed, label: mixed}>, filterSources: list<string>}
     */
    protected function listFilterOptions(): array
    {
        $user = Auth::user();
        $scopedIds = $this->scopeCustomerQuery()->select('id');

        $leadScope = $user ? DataScope::level($user, 'lead') : null;

        return [
            'filterBranches' => Ui::options(match (true) {
                ! $user => collect(),
                $leadScope === DataScope::ALL => Branch::orderBy('name')->get(['id', 'name']),
                // Phạm vi chi nhánh, phụ trách nhiều chi nhánh: chỉ lọc trong các chi nhánh của mình.
                $leadScope === DataScope::BRANCH && count($user->branchIds()) > 1 => Branch::whereIn('id', $user->branchIds())->orderBy('name')->get(['id', 'name']),
                default => collect(),
            }, 'name'),
            // Người phụ trách (chủ dự án 29/09/2026): Admin + Học vụ trong phạm vi, kèm người đang phụ trách khách trong
            // phạm vi (vd Sale phụ trách khách cũ) để vẫn lọc được.
            'filterSales' => Ui::options(LeadOwners::options($this->ownerFilterOptions($user, $leadScope, $scopedIds))),
            'filterSources' => CrmCustomer::query()->whereIn('id', $scopedIds)->whereNotNull('source')->distinct()->orderBy('source')->pluck('source')->all(),
        ];
    }

    /**
     * Số trên chip lọc nhanh của header CRM (theo phạm vi dữ liệu của user) — trước tính trong crm/partials/header-tabs.
     *
     * @return array{sla: int, test_today: int, follow_up: int, waiting_class: int, won: int, lost: int, deleted: int}
     */
    protected function chipCounts(): array
    {
        $stageCounts = $this->scopeCustomerQuery()->whereIn('stage', ['waiting_class', 'won', 'lost'])
            ->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');

        return [
            'sla' => $this->scopeCustomerQuery()->staleNew()->count(),
            'test_today' => $this->scopeCustomerQuery()->testToday()->count(),
            'follow_up' => $this->scopeCustomerQuery()->followUpDueToday()->count(),
            'waiting_class' => (int) ($stageCounts['waiting_class'] ?? 0),
            'won' => (int) ($stageCounts['won'] ?? 0),
            'lost' => (int) ($stageCounts['lost'] ?? 0),
            'deleted' => $this->scopeCustomerQuery()->onlyTrashed()->count(),
        ];
    }

    /** @return Collection<int, User> */
    protected function ownerFilterOptions(?User $user, ?string $leadScope, $scopedIds): Collection
    {
        if (! $user) {
            return collect();
        }
        $candidates = $leadScope === DataScope::OWN ? collect() : LeadOwners::candidates($leadScope === DataScope::ALL ? null : $user->branchIds());
        $current = User::withTrashed()->with('branch:id,name')
            ->whereIn('id', CrmCustomer::query()->whereIn('id', $scopedIds)->whereNotNull('assigned_user_id')->select('assigned_user_id'))
            ->whereNotIn('id', $candidates->pluck('id'))
            ->orderBy('name')->get(['id', 'name', 'email', 'branch_id', 'is_active']);

        return $candidates->concat($current)->values();
    }

    public function customers(Request $request)
    {
        // Mockup danh-sach-khach: lọc Từ khóa (tên / SĐT / phụ huynh), Nguồn, Người phụ trách, Giai đoạn, Chi nhánh.
        $query = $this->scopeCustomerQuery()->with(['branch', 'assignedUser'])->withLastCare()->latest('updated_at')->latest('id');
        $this->applyListFilters($query, $request, 'created_at');

        if ($stage = $request->input('stage')) {
            $query->where('stage', $stage);
        }
        // Lọc nhanh "Chưa liên hệ >24h" (chip trên header CRM).
        if ($request->boolean('sla')) {
            $query->staleNew();
        }
        // Lọc nhanh "Cần gọi lại": hạn gọi lại tới hết hôm nay (gồm quá hạn), xếp theo hạn.
        if ($request->boolean('follow_up')) {
            $query->followUpDueToday()->reorder('next_follow_up_at')->orderBy('id');
        }
        // Lọc nhanh "Hẹn test hôm nay": xếp theo giờ hẹn.
        if ($request->boolean('test_today')) {
            $query->testToday()->reorder('appointment_at')->orderBy('id');
        }

        $dbCustomers = $query->paginate($request->perPage(15))->withQueryString()
            ->through(fn (CrmCustomer $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'short_code' => $c->short_code,
                'name' => $c->name,
                'phone' => $c->phone,
                'parent_name' => $c->parent_name,
                'stage' => $c->stage,
                'stage_label' => $c->stage_label,
                'stage_badge' => $c->stage_badge,
                'assigned_user' => $c->assignedUser?->name,
                'branch' => $c->branch?->name,
                'updated_at' => $c->updated_at?->format('d/m/Y H:i'),
                'updated_label' => $this->updatedLabel($c->updated_at ?? $c->created_at),
                'sla' => $c->contactSlaPayload(),
                'test_today_at' => $c->appointment_at?->isToday() && $c->stage !== CrmCustomer::STAGE_LOST ? $c->appointment_at->format('H:i') : null,
                'test_today_type' => $c->appointment_at?->isToday() ? ($c->appointment_type === 'online' ? 'Online' : 'Tại cơ sở') : null,
                'follow_up_label' => $c->followUpStatus() === 'overdue' || ($c->next_follow_up_at?->isToday() && in_array($c->stage, CrmCustomer::ACTIVE_STAGES, true))
                    ? $this->relativeDeadlineLabel($c->next_follow_up_at) : null,
                'follow_up_overdue' => $c->followUpStatus() === 'overdue',
            ]);

        return Inertia::render('Crm/Customers/Index', [
            'customers' => $dbCustomers,
            'stageOptions' => Ui::options(CrmCustomer::PIPELINE_STAGES + ['lost' => CrmCustomer::stageLabel('lost')]),
            'importSkipped' => session('import_skipped'),
            'chipCounts' => $this->chipCounts(),
        ] + $this->listFilterOptions());
    }

    /** "10:30, hôm nay" / "Hôm qua" / "3 ngày trước" / "10:30, 01/09/2026" — cột Cập nhật gần nhất. */
    protected function updatedLabel(?Carbon $updated): ?string
    {
        if (! $updated) {
            return null;
        }

        return $updated->isToday() ? $updated->format('H:i').', hôm nay'
            : ($updated->isYesterday() ? 'Hôm qua' : ($updated->gt(now()->subDays(7)) ? $updated->diffForHumans() : $updated->format('H:i, d/m/Y')));
    }

    /** Danh sách học viên đã chốt nhưng chưa có lớp (Chờ xếp lớp) — nơi duy nhất Học vụ xếp lớp cho khách đã chốt. */
    public function waitingList(): InertiaResponse
    {
        $data = $this->waitingClassData();

        return Inertia::render('Crm/WaitingList', [
            'waitingLeads' => $data['waitingLeads']->map(fn (CrmCustomer $lead) => [
                'id' => $lead->id,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'student_id' => $lead->convertedStudent?->id,
                'student_code' => $lead->convertedStudent?->code,
                'course' => $lead->waitingCourse?->name,
                'branch' => $lead->waitingBranch?->name ?? $lead->branch?->name,
                'converted_at' => $lead->converted_at?->format('H:i d/m/Y'),
                'wait_days' => $lead->converted_at ? (int) $lead->converted_at->diffInDays(now()) : null,
                'levels' => $data['levels']->targetLevelNames($lead),
                'matches' => $data['matchingClassesByLead']->get($lead->id, collect())->map(fn (ClassModel $class) => [
                    'value' => $class->id,
                    'label' => $class->name.($class->status === 'upcoming' ? ' (sắp khai giảng)' : '')
                        .(($levelName = $data['levels']->classLevelName($class)) ? ' · '.$levelName : '')
                        .' · còn '.($class->max_capacity > 0 ? max(0, $class->max_capacity - $class->active_enrollments_count) : '∞').' chỗ'
                        .($class->status === 'upcoming' && $class->active_enrollments_count < (int) $class->min_students
                            ? ' · cần thêm '.((int) $class->min_students - $class->active_enrollments_count).' HV để khai giảng' : ''),
                ])->values()->all(),
            ])->values()->all(),
            'chipCounts' => $this->chipCounts(),
        ]);
    }

    /**
     * Lead ở Chờ xếp lớp (đã có hồ sơ học viên) + lớp gợi ý: đúng chi nhánh, còn chỗ, đúng khóa đã chốt hoặc
     * cùng cấp độ (trình độ của khóa đã chốt / cấp độ test đầu vào — PlacementLevelMatcher); lớp đúng khóa lên đầu.
     *
     * @return array{waitingLeads: Collection, matchingClassesByLead: Collection, levels: PlacementLevelMatcher}
     */
    protected function waitingClassData(): array
    {
        $levels = app(PlacementLevelMatcher::class);
        $waitingLeads = $this->scopeCustomerQuery()
            ->with(['assignedUser', 'branch', 'waitingBranch', 'waitingCourse', 'convertedStudent', 'assignedTest', 'latestSubmission.test'])
            ->where('stage', 'waiting_class')
            ->orderBy('converted_at')
            ->get();

        // Cùng điều kiện "lớp nhận ghi danh" với Gán lớp (loại lớp Sắp khai giảng đã quá ngày khai giảng).
        $classes = $waitingLeads->isEmpty() ? collect() : $this->withRosterSeats($this->enrollableClassesQuery()
            ->with(['course', 'branch'])
            ->whereIn('branch_id', $waitingLeads->map(fn (CrmCustomer $lead) => $lead->convertedStudent?->branch_id ?? $lead->branch_id)->filter()->unique())
            ->get());

        $matchingClassesByLead = $waitingLeads->mapWithKeys(function (CrmCustomer $lead) use ($classes, $levels) {
            $branchId = $lead->convertedStudent?->branch_id ?? $lead->branch_id;
            $matches = $classes->filter(fn (ClassModel $class) => $class->branch_id === $branchId
                && $levels->matchesClosed($lead, $class)
                && ($class->max_capacity <= 0 || $class->active_enrollments_count < $class->max_capacity)
            )
                ->sortBy(fn (ClassModel $class) => $lead->waiting_course_id && $class->course_id === $lead->waiting_course_id ? 0 : 1)
                ->values();

            return [$lead->id => $matches];
        });

        return compact('waitingLeads', 'matchingClassesByLead', 'levels');
    }

    /**
     * Chỉ số khách Chờ xếp lớp — các màn phụ (Khách chốt, Xác nhận chính thức) hiện băng nhắc + link về
     * màn Chờ xếp lớp (nơi xếp lớp duy nhất), không lặp lại cả khối xếp lớp.
     *
     * @return array{waitingCount: int}
     */
    protected function waitingClassCount(): array
    {
        return ['waitingCount' => $this->scopeCustomerQuery()->where('stage', 'waiting_class')->count()];
    }

    /**
     * Chi nhánh được chọn khi thêm / sửa khách: người xem toàn hệ thống → mọi chi nhánh đang hoạt động;
     * người bị giới hạn chi nhánh → chỉ chi nhánh của mình (tránh tạo khách sang chi nhánh khác rồi "mất" khách).
     */
    protected function leadBranchOptions(User $user, ?int $keepBranchId = null): Collection
    {
        $branches = DataScope::isAll($user, 'lead')
            ? Branch::where('is_active', true)->orderBy('name')->get()
            : Branch::whereIn('id', $user->branchIds())->orderBy('name')->get();
        if ($branches->isEmpty()) {
            $branches = DataScope::isAll($user, 'lead') ? Branch::orderBy('name')->get() : $branches;
        }
        if ($keepBranchId && ! $branches->contains('id', $keepBranchId) && ($keep = Branch::find($keepBranchId))) {
            $branches->push($keep);
        }

        return $branches;
    }

    public function createCustomer(): InertiaResponse
    {
        $user = Auth::user();
        $branches = $this->leadBranchOptions($user);
        // Người phụ trách: Admin + Học vụ; phạm vi chi nhánh chỉ thấy Học vụ cơ sở mình (máy chủ kiểm tra theo cơ sở của khách).
        $salesUsers = LeadOwners::candidates(DataScope::isAll($user, 'lead') ? null : $user->branchIds());
        $leadSources = SystemCategory::where('type', 'lead_source')->orderBy('sort_order')->pluck('name');
        if ($leadSources->isEmpty()) {
            $leadSources = collect(CrmCustomer::DEFAULT_SOURCES);
        }

        // Mở từ Kanban / danh sách → modal; mở thẳng URL → trang đầy đủ.
        return $this->modalPage('Crm/Customers/Create', [
            'branches' => Ui::options($branches, 'name'),
            'salesUsers' => Ui::options(LeadOwners::options($salesUsers)),
            'leadSources' => $leadSources->values()->all(),
            'defaultBranchId' => $branches->count() === 1 ? $branches->first()->id : $user->branch_id,
            'defaultAssigneeId' => $user->id,
        ]);
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
            'branch_id' => ['required', Rule::in($this->leadBranchOptions($request->user())->pluck('id')->all())],
            'assigned_user_id' => ['nullable', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'email' => 'nullable|email|max:255',
            'dob' => 'nullable|date',
            'gender' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'course_interest' => 'nullable|string|max:255',
            'deal_value' => 'nullable|numeric|min:0|max:9999999999999',
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
            if (! LeadOwners::isCandidate($assignee)) {
                throw ValidationException::withMessages(['assigned_user_id' => self::OWNER_INVALID]);
            }
            // Khách mới: người phụ trách phải thuộc cơ sở đã chọn (chuyển cơ sở chỉ qua đổi người phụ trách sau khi tạo).
            if (! LeadOwners::belongsToBranch($assignee, (int) $validated['branch_id'])) {
                throw ValidationException::withMessages(['assigned_user_id' => "{$assignee->name} không thuộc cơ sở đã chọn. Chọn Học vụ cùng cơ sở hoặc Admin."]);
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

        return $this->modalSaved(
            "Đã thêm khách hàng {$customer->name} ({$customer->short_code}) thành công vào Cơ sở dữ liệu!",
            route('crm.customers.show', $customer->id),
        );
    }

    /** Hồ sơ khách: trang đầy đủ duy nhất (Kanban / danh sách / nút sửa đều mở đây); tab "Thông tin khách hàng" sửa trực tiếp. */
    public function showCustomer(Request $request, CrmStageService $stages, $id): InertiaResponse
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
        $examiners = Rbac::scopeUsersWithPermission(User::where('is_active', true), 'entrance_test.examine')->get();
        $courses = Course::where('is_active', true)->orderBy('name')->get();
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        $user = $request->user();
        $trialState = CrmTrialBooking::stateFor($customer->trialBookings);
        $trialRemaining = $trialState['remaining'];
        // Học vụ / QL xếp học thử khi khách đang tư vấn; đủ 2 lần học thử thì khóa (vẫn xem được lịch sử + nhận xét).
        $canBookTrial = $stages->canMoveForward($user) && in_array($customer->stage, CrmCustomer::TRIAL_BOOKABLE_STAGES, true)
            && ! $trialState['exhausted'];
        $trialSlots = $canBookTrial && ! $trialState['pending']
            ? app(TrialSlotFinder::class)->find($customer, $latestSubmission)
            : ['level' => TrialSlotFinder::levelLabel($customer, $latestSubmission), 'filtered' => false, 'classes' => collect()];
        $stageControls = [
            'next' => $stages->manualNextStage($customer, $user),
            'backward' => $stages->backwardTargets($customer, $user),
            'canLose' => $user->can('lead.mark_lost') && ! $customer->isClosed() && $customer->stage !== CrmCustomer::STAGE_LOST,
            // Hủy buổi học thử đang chờ được ở mọi giai đoạn (kể cả sau khi chốt / thất bại).
            'canCancelTrial' => $stages->canMoveForward($user),
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
        // Khách chưa có bài test: gợi ý bài nộp qua link công khai chưa gắn khách (trùng SĐT / tên) để Học vụ gắn tay.
        $unlinkedSubmissions = ! $latestSubmission && $user->can('entrance_test.grade')
            ? app(PlacementSubmissionLinker::class)->candidatesFor($customer)
            : collect();
        $statusCard = $this->statusCardData($customer);
        // Phân công lại theo quyền lead.assign (Admin, Quản lý cơ sở, Học vụ — BA 26/09/2026), không theo vai trò.
        $canReassign = $user->can('lead.assign');
        $reassignUsers = $canReassign ? $this->assignableUsers() : collect();
        // Phân công lại chọn được mọi cơ sở đang hoạt động (khác cơ sở hiện tại = chuyển cơ sở, chờ Admin duyệt).
        $reassignBranches = $canReassign
            ? Ui::options(Branch::query()->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $customer->branch_id))->orderBy('name')->get(['id', 'name']), 'name')
            : [];
        $pendingTransfer = $customer->pendingBranchTransfer()->with(['toUser:id,name', 'toBranch:id,name', 'fromBranch:id,name', 'requester:id,name'])->first();

        $editForm = $user->can('lead.update') ? $this->customerFormOptions($customer) : null;

        return Inertia::render('Crm/Customers/Show', $this->showProps($customer, $user, compact('placementTests', 'examiners', 'latestSubmission',
            'portalTestLink', 'canBookTrial', 'trialSlots', 'trialState', 'stageControls', 'histories', 'logType', 'rubric', 'statusCard',
            'canReassign', 'reassignUsers', 'reassignBranches', 'editForm', 'unlinkedSubmissions', 'pendingTransfer')));
    }

    /**
     * Props trang hồ sơ khách (Crm/Customers/Show) — chỉ các trường trang cần, ngày giờ định dạng sẵn như bản Blade cũ.
     *
     * @param  array<string, mixed>  $data  dữ liệu showCustomer đã tính
     * @return array<string, mixed>
     */
    protected function showProps(CrmCustomer $customer, User $user, array $data): array
    {
        /** @var PlacementTestSubmission|null $sub */
        $sub = $data['latestSubmission'];
        $placementTests = $data['placementTests'];
        $histories = $data['histories'];
        $trialState = $data['trialState'];
        $stageControls = $data['stageControls'];
        $resultLogs = $customer->histories->where('type', 'result');
        $hasResult = ! empty($sub) || ! empty($customer->test_score);
        $canClose = $user->can('lead.convert') && in_array($customer->stage, CrmCustomer::CLOSABLE_STAGES, true) && ! $customer->converted_student_id;
        $canPlace = $customer->stage === 'waiting_class' && $user->can('student.assign_class');
        // Chỉ điền sẵn form nhập điểm khi sửa đúng lần thi đang chọn; nhập lần mới thì để trống.
        $editSub = ($sub && $customer->stage !== 'test_scheduled') ? $sub : null;
        // Hẹn lại: giữ lịch / đề / người chấm đang có; lịch mới mặc định sáng mai 09:00.
        $prefillAt = $customer->appointment_at?->isFuture() ? $customer->appointment_at : today()->addDay()->setTime(9, 0);
        $careState = $customer->care_checklist ?? [];
        // Hạn từng mốc + "Quá hạn chăm sóc" (FirstMonthCareService::checklist) khi khách đã có hồ sơ học viên.
        $careMilestones = $customer->convertedStudent
            ? collect(app(FirstMonthCareService::class)->checklist($customer->convertedStudent)['items'])->keyBy('key')
            : collect();
        $pendingTrial = $trialState['pending'];
        $pendingTransfer = $data['pendingTransfer'];
        $editForm = $data['editForm'];
        $scorecardUrl = $sub ? URL::signedRoute('portal.test.scorecard', ['id' => $sub->id]) : null;
        $fmtScore = fn ($v) => $v === null ? null : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');

        return [
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'short_code' => $customer->short_code,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'parent_name' => $customer->parent_name,
                'parent_phone' => $customer->parent_phone,
                'source' => $customer->source,
                'email' => $customer->email,
                'dob' => $customer->dob?->format('Y-m-d'),
                'dob_label' => $customer->dob?->format('d/m/Y'),
                'gender' => $customer->gender,
                'address' => $customer->address,
                'course_interest' => $customer->course_interest,
                'deal_value' => $customer->deal_value !== null ? (float) $customer->deal_value : null,
                'notes' => $customer->notes,
                'stage' => $customer->stage,
                'stage_label' => $customer->stage_label,
                'stage_badge' => $customer->stage_badge,
                'branch_id' => $customer->branch_id,
                'branch' => $customer->branch?->name,
                'assigned_user_id' => $customer->assigned_user_id,
                'assigned_user' => $customer->assignedUser?->name,
                'converted_student_id' => $customer->converted_student_id,
                'created_at' => $customer->created_at->format('d/m/Y H:i'),
                'created_date' => $customer->created_at->format('d/m/Y'),
                'converted_at' => $customer->converted_at?->format('d/m/Y H:i'),
                'lost_at' => $customer->lost_at?->format('d/m/Y'),
                'lost_reason' => $customer->lost_reason,
                'waiting_since' => $customer->waiting_since?->format('d/m/Y'),
                'waiting_course' => $customer->waitingCourse?->name,
                'waiting_branch' => $customer->waitingBranch?->name,
                'fee_paid_at_closing' => (bool) $customer->fee_paid_at_closing,
                'next_follow_up_at' => $customer->next_follow_up_at?->format('H:i d/m/Y'),
                'next_follow_up_input' => $customer->next_follow_up_at?->format('Y-m-d\TH:i'),
                'appointment_at' => $customer->appointment_at?->format('H:i, d/m/Y'),
                'appointment_type' => $customer->appointment_type,
                'assigned_test_id' => $customer->assigned_test_id,
                'examiner_id' => $customer->examiner_id,
                'assigned_test' => $customer->assignedTest ? [
                    'title' => $customer->assignedTest->title,
                    'duration_minutes' => $customer->assignedTest->duration_minutes,
                ] : null,
                'assigned_test_level' => PlacementTest::gradeLevelLabel($customer->assignedTest?->grade_level ?? PlacementTest::detectGradeLevel($customer->assignedTest?->code)),
                'contract_locked' => $customer->isContractLocked(),
            ],
            'actions' => [
                'canClose' => $canClose,
                'canPlace' => $canPlace,
                'primary' => $stageControls['next'] ? 'next' : ($canClose ? 'close' : ($canPlace ? 'place' : null)),
            ],
            'stageControls' => [
                ...$stageControls,
                'nextLabel' => $stageControls['next'] ? CrmCustomer::stageLabel($stageControls['next']) : null,
                'backwardOptions' => collect(array_reverse($stageControls['backward']))
                    ->map(fn (string $target) => ['value' => $target, 'label' => CrmCustomer::stageLabel($target)])->values()->all(),
            ],
            'pendingTransfer' => $pendingTransfer ? [
                'requester' => $pendingTransfer->requester?->name,
                'to_user' => $pendingTransfer->toUser?->name,
                'from_branch' => $pendingTransfer->fromBranch?->name,
                'to_branch' => $pendingTransfer->toBranch?->name,
                'approvals_url' => route('approvals.index', ['group' => ApprovalInboxService::groupSlug(ApprovableSource::GROUP_ACADEMIC)]),
            ] : null,
            'canReassign' => $data['canReassign'],
            // Người phụ trách kèm cơ sở để modal lọc theo cơ sở đang chọn (Admin: mọi cơ sở). Người đang phụ trách vẫn có
            // trong danh sách: chọn cơ sở khác mà giữ nguyên người phụ trách = chỉ chuyển cơ sở.
            'reassignUsers' => $data['reassignUsers']->map(fn (User $u) => [
                'value' => $u->id,
                'label' => LeadOwners::label($u),
                'branch_ids' => $u->branchIds(),
                'all_branches' => LeadOwners::coversAll($u),
            ])->values()->all(),
            'reassignBranches' => $data['reassignBranches'],
            // Kết quả test
            'test' => [
                'hasTested' => $sub || filled($customer->test_score) || $customer->stage === 'tested',
                'summary' => $sub?->scoreSummary() ?? $customer->test_score,
                'pending' => (bool) $sub?->isPending(),
                'hasResult' => $hasResult,
                'hasScheduled' => ! empty($customer->appointment_at) || ! empty($customer->assigned_test_id),
                'submissionId' => $sub?->id,
                'submissionTest' => $sub?->test?->title,
                'scorecardUrl' => $scorecardUrl,
            ],
            'rubric' => $data['rubric'],
            'result' => $hasResult ? [
                'fallback_score' => $customer->test_score,
                'overall_score' => $sub?->overall_score !== null ? (string) $sub->overall_score : null,
                'scores' => [
                    'listening' => $fmtScore($sub?->listening_score),
                    'reading_writing' => $fmtScore($sub?->reading_writing_score),
                    'speaking' => $fmtScore($sub?->speaking_score),
                    'reading' => $fmtScore($sub?->reading_score),
                    'writing' => $fmtScore($sub?->writing_score),
                ],
                'recommended_course' => $sub?->recommended_course,
                'teacher_comments' => $sub?->teacher_comments,
                'grader' => $sub?->grader?->name,
            ] : null,
            'skills' => PlacementRubricService::SKILLS,
            'noRubricNotice' => PlacementRubricService::noRubricNotice(),
            'resultLogs' => $resultLogs->take(3)->map(fn (CrmCustomerHistory $log) => [
                'id' => $log->id,
                'content' => $log->content,
                'user' => $log->user?->name,
                'created_at' => $log->created_at->format('H:i d/m/Y'),
            ])->values()->all(),
            'hasResultLogs' => $resultLogs->isNotEmpty(),
            'nowInput' => now()->format('Y-m-d\TH:i'),
            'today' => now()->toDateString(),
            'tomorrow' => now()->addDay()->toDateString(),
            // Hẹn test (form trong khối + modal hẹn lại)
            'placementTests' => $placementTests->map(fn (PlacementTest $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'title' => $t->title,
                'duration_minutes' => $t->duration_minutes,
                'group' => $t->grade_level ?? PlacementTest::detectGradeLevel($t->code),
            ])->values()->all(),
            'gradeLevels' => Ui::options(PlacementTest::GRADE_LEVELS),
            'examiners' => Ui::options($data['examiners'], 'name'),
            'scheduleDefaults' => ['date' => $prefillAt->format('Y-m-d'), 'time' => $prefillAt->format('H:i')],
            'portalTestLink' => $data['portalTestLink'],
            'linkTtlDays' => PlacementPortalLinkService::LINK_TTL_DAYS,
            // Nhập / sửa điểm (thang điểm khối lớp)
            'scoreForm' => [
                'submissionId' => $editSub?->id,
                'canDraft' => ! $editSub || $editSub->isPending(),
                'testId' => $editSub?->placement_test_id ?? $customer->assigned_test_id,
                'rubric' => PlacementTestController::rubricFormState($editSub, PlacementRubricService::detectGradeGroup($editSub?->test?->code ?? $customer->assignedTest?->code)),
            ],
            'unlinkedSubmissions' => $data['unlinkedSubmissions']->map(fn (PlacementTestSubmission $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->candidate_name,
                'phone' => $candidate->candidate_phone,
                'test' => $candidate->test?->title,
                'created_at' => $candidate->created_at?->format('H:i d/m/Y'),
                'status' => $candidate->isPending() ? 'Chờ chấm' : ($candidate->scoreSummary() ?? 'Đã chấm'),
            ])->values()->all(),
            // Học thử
            'canBookTrial' => $data['canBookTrial'],
            'trial' => [
                'used' => $trialState['used'],
                'exhausted' => $trialState['exhausted'],
                'max' => CrmTrialBooking::MAX_ACTIVE_PER_LEAD,
                'bookable' => in_array($customer->stage, CrmCustomer::TRIAL_BOOKABLE_STAGES, true),
                'pending' => $pendingTrial ? [
                    'class' => $pendingTrial->classModel?->name,
                    'date' => $pendingTrial->session?->date?->format('d/m/Y'),
                    'time' => $pendingTrial->session?->start_time?->format('H:i'),
                ] : null,
            ],
            'trialSlots' => $this->trialSlotProps($data['trialSlots']),
            'trialBookings' => $customer->trialBookings->map(fn (CrmTrialBooking $booking) => [
                'id' => $booking->id,
                'class' => $booking->classModel?->name,
                'date' => $booking->session?->date?->format('d/m/Y'),
                'time' => $booking->session?->start_time?->format('H:i'),
                'status' => $booking->status,
                'status_label' => $booking->status_label,
                'has_feedback' => (bool) ($booking->feedback || $booking->remarks),
                'feedback' => ($booking->rating ? $booking->rating.'/5 · ' : '').($booking->remarksSummary() !== '' ? $booking->remarksSummary().' · ' : '').$booking->feedback,
                'feedback_by' => $booking->feedbackBy?->name,
                'feedback_at' => $booking->feedback_at?->format('d/m/Y H:i'),
            ])->values()->all(),
            // Trạng thái & hạn xử lý, chăm sóc tháng đầu
            'statusCard' => [
                ...$data['statusCard'],
                'stage_since' => $data['statusCard']['stage_since']->format('d/m/Y'),
                'last_contact' => $data['statusCard']['last_contact']?->format('d/m/Y H:i'),
            ],
            'careChecklist' => collect(CrmCustomer::CARE_CHECKLIST_ITEMS)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'done' => ! empty($careState[$key]),
                'done_at' => ! empty($careState[$key]['done_at']) ? \Illuminate\Support\Carbon::parse($careState[$key]['done_at'])->format('d/m/Y') : null,
                'by' => $careState[$key]['by'] ?? null,
                'due' => ($careMilestones->get($key)['due'] ?? null)?->format('d/m/Y'),
                'overdue' => (bool) ($careMilestones->get($key)['overdue'] ?? false),
            ])->values()->all(),
            // Lịch sử hoạt động
            'histories' => $histories->map(fn (CrmCustomerHistory $history) => [
                'id' => $history->id,
                'type' => $history->type,
                'failed' => $history->outcome === CrmCustomerHistory::OUTCOME_FAILED,
                'type_icon' => $history->type_icon,
                'type_label' => CrmCustomerHistory::FILTER_TYPES[$history->type] ?? null,
                'is_lost' => $history->type === 'stage_change' && $history->to_stage === CrmCustomer::STAGE_LOST,
                'user' => $history->user?->name,
                'content' => $history->content,
                'reason' => $history->reason,
                'created_at' => $history->created_at->format('H:i - d/m/Y'),
            ])->values()->all(),
            'historyTotal' => $customer->histories->count(),
            'logType' => $data['logType'],
            'logTypeOptions' => collect(CrmCustomerHistory::FILTER_TYPES)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'count' => $customer->histories->where('type', $key)->count()])
                ->filter(fn (array $row) => $row['count'] > 0 || $data['logType'] === $row['key'])
                ->map(fn (array $row) => ['value' => $row['key'], 'label' => "{$row['label']} ({$row['count']})"])
                ->values()->all(),
            'tab' => request('tab') === 'info' ? 'info' : 'ops',
            // Vừa xác nhận lịch hẹn mà chưa gửi được email: popup nội dung xác nhận để sao chép gửi Zalo.
            'appointmentConfirmation' => (fn ($c) => is_array($c) && empty($c['emailed']) ? [
                'text' => $c['text'],
                'phone' => $c['phone'] ?? null,
                'email_failed' => (bool) ($c['email_failed'] ?? false),
            ] : null)(session('appointment_confirmation')),
            'editForm' => $editForm ? [
                'branches' => Ui::options($editForm['branches'], 'name'),
                'salesUsers' => Ui::options(LeadOwners::options($editForm['salesUsers'])),
                'leadSources' => $editForm['leadSources']->mapWithKeys(fn ($s) => [$s => $s])
                    ->when($customer->source && ! $editForm['leadSources']->contains($customer->source), fn ($o) => $o->put($customer->source, $customer->source.' (hiện tại)'))
                    ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
                'courseNames' => $editForm['courseNames']->values()->all(),
                'lockedFields' => implode(', ', CrmCustomer::CONTRACT_LOCKED_FIELDS),
            ] : null,
        ];
    }

    /**
     * Lớp / buổi học thử cho modal Xếp học thử (TrialSlotFinder::find).
     *
     * @param  array{level: ?string, filtered: bool, classes: Collection}  $slots
     * @return array{level: ?string, filtered: bool, classes: list<array<string, mixed>>}
     */
    protected function trialSlotProps(array $slots): array
    {
        $weekdays = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];

        return [
            'level' => $slots['level'],
            'filtered' => $slots['filtered'],
            'classes' => collect($slots['classes'])->map(fn (array $row) => [
                'class' => [
                    'id' => $row['class']->id,
                    'name' => $row['class']->name,
                    'course' => $row['class']->course?->name,
                    'level' => $row['class']->level,
                ],
                'sessions' => $row['sessions']->map(fn (ClassSession $slot) => [
                    'id' => $slot->id,
                    'label' => $weekdays[$slot->date->dayOfWeek].' '.$slot->date->format('d/m').' · '.$slot->start_time?->format('H:i').'–'.$slot->end_time?->format('H:i'),
                    'teacher' => $slot->teacher?->name ?? $row['class']->teacher?->name ?? 'Chưa gán',
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Khối kết quả test theo thang điểm khối lớp (BA Q2) cho hồ sơ khách / bản in.
     *
     * @return array<string, mixed>|null
     */
    protected function rubricSummary(?PlacementTestSubmission $submission): ?array
    {
        if (! $submission) {
            return null;
        }
        $group = $submission->grade_group;
        // Bài nộp online chưa chấm xong: điểm Nghe / Đọc & Viết + nhận xét tự động là bản nháp, hiện ngay cho Học vụ
        // (tổng tạm tính, chưa có lớp đề xuất cho tới khi nhập điểm Nói và Xác nhận kết quả).
        $draft = $submission->total_score === null && $submission->overall_score === null && $group !== null
            && ($submission->listening_score !== null || $submission->reading_writing_score !== null);
        if (! $draft && $submission->total_score === null && $submission->overall_score === null) {
            return null;
        }

        return [
            'draft' => $draft,
            'legacy' => ! $draft && ! $submission->hasRubricGrade(),
            'grade_group' => $group,
            'grade_group_label' => PlacementRubricService::groupLabel($group),
            'has_rubric' => PlacementRubricService::hasRubric($group),
            'max' => PlacementRubricService::maxScores($group),
            'max_total' => PlacementRubricService::maxTotal($group),
            'total' => $draft
                ? round((float) $submission->listening_score + (float) $submission->reading_writing_score + (float) $submission->speaking_score, 1)
                : ($submission->total_score !== null ? (float) $submission->total_score : (float) $submission->overall_score),
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
        $lastActivity = $customer->lastCareAt() ?? $customer->created_at;

        return [
            'stage_since' => $stageSince,
            'days_in_stage' => (int) $stageSince->diffInDays(now()),
            'last_contact' => $lastContact,
            'follow_up_status' => $customer->followUpStatus(),
            'follow_up_remaining' => $this->remainingLabel($customer->next_follow_up_at),
            'sla' => $customer->contactSlaPayload(),
            'neglected' => in_array($customer->stage, CrmCustomer::ACTIVE_STAGES, true) && $lastActivity->lt(now()->subDays($neglectDays)),
            'neglect_days' => $neglectDays,
        ];
    }

    /** Đồng hồ "Hạn liên hệ tiếp theo" (mockup 02:14:55): "Còn 2 giờ 14 phút" / "Quá hạn 1 ngày 3 giờ". */
    protected function remainingLabel(?Carbon $at): ?string
    {
        return $at ? CrmCustomer::remainingLabel($at) : null;
    }

    private const OWNER_INVALID = 'Người phụ trách phải là Học vụ hoặc Admin đang hoạt động.';

    /**
     * Người phụ trách chọn được khi đổi người phụ trách: Admin + Học vụ mọi cơ sở. Chọn Học vụ cơ sở khác = chuyển cơ sở
     * cho khách (chờ Admin duyệt, CrmBranchTransferService).
     */
    protected function assignableUsers(): Collection
    {
        return LeadOwners::candidates();
    }

    /**
     * Đổi người phụ trách (form sửa / Phân công lại). Cùng cơ sở → đổi ngay. Khác cơ sở → Admin đổi ngay kèm chuyển cơ sở,
     * người khác gửi yêu cầu chờ Admin duyệt. $toBranchId: cơ sở chọn kèm khi Phân công lại (null = cơ sở của người phụ trách).
     * Trả thông báo khi đã gửi yêu cầu (null nếu đổi xong).
     */
    protected function changeOwner(CrmCustomer $customer, User $assignee, User $actor, ?string $reason, string $title, ?int $toBranchId = null): ?string
    {
        if (! LeadOwners::isCandidate($assignee)) {
            throw ValidationException::withMessages(['assigned_user_id' => self::OWNER_INVALID]);
        }
        $transfers = app(CrmBranchTransferService::class);
        if ($transfers->needsTransfer($customer, $assignee, $toBranchId)) {
            $transfer = $transfers->requestOrApply($customer, $assignee, $actor, $reason, $toBranchId);

            return $transfer
                ? "Đã gửi yêu cầu chuyển khách sang {$assignee->name} ({$transfer->toBranch?->name}). Chờ Admin duyệt chuyển cơ sở."
                : null;
        }

        $changes = $this->diffCustomer($customer, ['assigned_user_id' => $assignee->id]);
        DB::transaction(function () use ($customer, $assignee, $changes, $actor, $reason, $title) {
            $customer->update(['assigned_user_id' => $assignee->id]);
            if ($changes !== []) {
                $this->logCustomerChanges($customer, $changes, $actor, 'assign', $title, $reason);
            }
        });

        return null;
    }

    /**
     * Từ khóa trình độ của khách để gợi ý lớp học thử cùng trình độ: lớp xếp sau test (vd "STARTERS (FAM 1 …)")
     * hoặc khóa quan tâm.
     *
     * @return array<int, string>
     */
    protected function trialLevelKeywords(CrmCustomer $customer, ?PlacementTestSubmission $submission): array
    {
        return TrialSlotFinder::levelKeywords($customer, $submission);
    }

    /** Học vụ gắn bài test nộp qua link công khai (chưa tự khớp được khách) vào hồ sơ khách, ở mọi giai đoạn trừ Thất bại. */
    public function linkSubmission(Request $request, $id, PlacementSubmissionLinker $linker)
    {
        $customer = $this->findScopedCustomer($id);
        $validated = $request->validate(['submission_id' => 'required|integer']);
        $submission = PlacementTestSubmission::query()->whereNull('customer_id')->with('test')->find($validated['submission_id']);
        if (! $submission) {
            throw ValidationException::withMessages(['submission_id' => 'Bài test không tồn tại hoặc đã được gắn với khách khác.']);
        }
        if ($customer->stage === CrmCustomer::STAGE_LOST) {
            throw ValidationException::withMessages(['submission_id' => 'Khách Thất bại được lưu để đối soát, không gắn thêm bài test.']);
        }

        $linker->attach($submission, $customer, Auth::id(), 'Học vụ gắn tay từ hồ sơ khách');

        return redirect()->route('crm.customers.show', $customer->id)
            ->with('status', "Đã gắn bài test #{$submission->id} vào hồ sơ khách.");
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
            'placement_test_id' => 'nullable|exists:placement_tests,id,deleted_at,NULL',
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
            'assigned_test_id' => 'nullable|exists:placement_tests,id,deleted_at,NULL',
            'examiner_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string|max:500',
        ]);

        if (! empty($validated['examiner_id'])) {
            $examiner = User::find($validated['examiner_id']);
            if (! $examiner?->is_active || ! $examiner->can('entrance_test.examine')) {
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

        $confirmations = app(AppointmentConfirmation::class);
        $confirmation = $confirmations->deliver($customer->refresh(), $confirmations->forTest($customer), $request->user(), 'test');

        return redirect()->back()
            ->with('status', "Đã đặt lịch hẹn test thành công cho khách hàng {$customer->name} vào lúc ".date('d/m/Y H:i', strtotime($appointmentDateTime)).'!'
                .$this->confirmationNotice($confirmation))
            ->with('appointment_confirmation', $confirmation);
    }

    /** @param  array{emailed: string|null}  $confirmation */
    private function confirmationNotice(array $confirmation): string
    {
        return $confirmation['emailed'] ? " Đã gửi email xác nhận tới {$confirmation['emailed']}." : '';
    }

    /**
     * Học vụ / QL xếp khách học thử vào MỘT buổi học thật của lớp khớp trình độ (luồng trước Chốt).
     * Mỗi lần xếp một buổi; buổi 2 xếp lại như lần 1 sau khi buổi 1 kết thúc; đủ 2 lần thì khóa.
     * Học thử không đổi stage và không tạo ghi danh — khách chỉ gắn chính thức vào lớp khi xếp lớp sau Chốt.
     */
    public function storeTrialBooking(Request $request, CrmStageService $stages, NotificationService $notifications, $id)
    {
        $customer = $this->findScopedCustomer($id);
        abort_unless($stages->canMoveForward($request->user()), 403, 'Chỉ Học vụ / Quản lý cơ sở được đặt lịch học thử.');

        $validated = $request->validate([
            'class_session_id' => 'required|integer|exists:class_sessions,id',
            'notes' => 'nullable|string|max:1000',
        ], [
            'class_session_id.required' => 'Vui lòng chọn một buổi học thử.',
        ]);

        if (! in_array($customer->stage, CrmCustomer::TRIAL_BOOKABLE_STAGES, true)) {
            throw ValidationException::withMessages(['class_session_id' => 'Chỉ đặt học thử cho khách đang tư vấn (chưa chốt, chưa thất bại).']);
        }

        $booking = DB::transaction(function () use ($customer, $validated, $request) {
            CrmCustomer::whereKey($customer->id)->lockForUpdate()->first();
            $state = CrmTrialBooking::stateFor($customer->trialBookings()->with('session')->get());
            if ($state['exhausted']) {
                throw ValidationException::withMessages(['class_session_id' => 'Khách đã học thử đủ '.CrmTrialBooking::MAX_ACTIVE_PER_LEAD.' buổi — không xếp thêm học thử.']);
            }
            if ($state['pending']) {
                throw ValidationException::withMessages(['class_session_id' => 'Khách đang có buổi học thử chưa diễn ra ('.$state['pending']->session->date->format('d/m/Y').'). Xếp buổi tiếp theo sau khi buổi này kết thúc.']);
            }

            $session = ClassSession::with('classModel')->findOrFail($validated['class_session_id']);
            $end = $session->end_time ? $session->date->copy()->setTimeFrom($session->end_time) : $session->date->copy()->endOfDay();
            if ($session->status !== 'scheduled' || $end->isPast() || $session->date->gt(today()->addDays(TrialSlotFinder::WINDOW_DAYS))
                || ! in_array($session->classModel?->status, ['active', 'upcoming'], true)) {
                throw ValidationException::withMessages(['class_session_id' => 'Buổi học đã chọn không còn khả dụng.']);
            }
            $branchId = $session->classModel?->branch_id ?? $session->branch_id;
            if ($customer->branch_id && $branchId && (int) $branchId !== (int) $customer->branch_id) {
                throw ValidationException::withMessages(['class_session_id' => 'Buổi học thử phải thuộc chi nhánh của khách.']);
            }

            return CrmTrialBooking::create([
                'customer_id' => $customer->id,
                'class_id' => $session->class_id,
                'class_session_id' => $session->id,
                'booked_by' => $request->user()->id,
                'status' => 'scheduled',
                'notes' => $validated['notes'] ?? null,
            ])->setRelation('session', $session)->setRelation('classModel', $session->classModel)->setRelation('customer', $customer);
        });

        $session = $booking->session;
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $request->user()->id,
            'type' => 'trial',
            'content' => 'Đặt lịch học thử: '.$session->classModel?->name.' ('.$session->date->format('d/m/Y').' '.$session->start_time?->format('H:i').')'
                .(! empty($validated['notes']) ? '. Ghi chú cho giáo viên: '.$validated['notes'] : '.'),
        ]);
        $notifications->notifyTrialBooked($booking, $request->user());
        $confirmations = app(AppointmentConfirmation::class);
        $confirmation = $confirmations->deliver($customer, $confirmations->forTrial($booking), $request->user(), 'trial');

        return redirect()->back()
            ->with('status', 'Đã xếp lịch học thử cho khách.'.$this->confirmationNotice($confirmation))
            ->with('appointment_confirmation', $confirmation);
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

    /** URL sửa cũ: chuyển tới tab "Thông tin khách hàng" của trang đầy đủ (form sửa nằm trong trang). */
    public function editCustomer($id)
    {
        $customer = $this->findScopedCustomer($id);
        $url = route('crm.customers.show', ['id' => $customer->id, 'tab' => 'info']);

        return redirect($url);
    }

    /**
     * Dữ liệu cho form sửa khách (crm/customers/_edit-form) trong trang hồ sơ.
     *
     * @return array{branches: Collection, salesUsers: Collection, leadSources: Collection, courseNames: Collection}
     */
    protected function customerFormOptions(CrmCustomer $customer): array
    {
        $leadSources = SystemCategory::where('type', 'lead_source')->orderBy('sort_order')->pluck('name');
        $salesUsers = $this->assignableUsers();
        // Người phụ trách hiện tại (vd Học vụ tự tạo khách, hoặc tài khoản đã khóa) luôn có trong danh sách,
        // nếu không trình duyệt sẽ gửi lựa chọn đầu tiên → âm thầm đổi người phụ trách khi chỉ sửa SĐT.
        if ($customer->assigned_user_id && ! $salesUsers->contains('id', $customer->assigned_user_id) && ($current = User::withTrashed()->find($customer->assigned_user_id))) {
            $salesUsers->prepend($current);
        }

        return [
            'branches' => $this->leadBranchOptions(Auth::user(), (int) $customer->branch_id),
            'salesUsers' => $salesUsers,
            'leadSources' => $leadSources->isEmpty() ? collect(CrmCustomer::DEFAULT_SOURCES) : $leadSources,
            'courseNames' => Course::where('is_active', true)->orderBy('name')->pluck('name'),
        ];
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
        'assigned_user_id' => 'Người phụ trách',
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
            'address' => 'nullable|string|max:255',
            'branch_id' => ['required', Rule::in($this->leadBranchOptions($request->user(), (int) $customer->branch_id)->pluck('id')->all())],
            'course_interest' => 'nullable|string|max:255',
            'source' => 'required|string|max:255',
            'assigned_user_id' => ['nullable', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'deal_value' => 'nullable|numeric|min:0|max:9999999999999',
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
        // Đổi người phụ trách đi riêng (có thể phải chuyển cơ sở, chờ Admin duyệt) sau khi lưu các trường khác.
        $newOwner = null;
        if ($request->user()->can('lead.assign') && ! empty($validated['assigned_user_id']) && (int) $validated['assigned_user_id'] !== (int) $customer->assigned_user_id) {
            $newOwner = User::find($validated['assigned_user_id']);
            if (! LeadOwners::isCandidate($newOwner)) {
                throw ValidationException::withMessages(['assigned_user_id' => self::OWNER_INVALID]);
            }
        }
        // Ô người phụ trách để trống không được xoá người phụ trách hiện tại.
        unset($validated['assigned_user_id']);
        // Xoá trắng ô "Giá trị hợp đồng" = 0 (cột không nhận NULL).
        if (array_key_exists('deal_value', $validated) && $validated['deal_value'] === null) {
            $validated['deal_value'] = 0;
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
        $pendingMessage = $newOwner ? $this->changeOwner($customer->refresh(), $newOwner, $request->user(), null, 'Đổi người phụ trách') : null;

        return $this->modalSaved(
            'Cập nhật thông tin khách hàng thành công!'.($pendingMessage ? ' '.$pendingMessage : ''),
            route('crm.customers.show', ['id' => $customer->id, 'tab' => 'info']),
        );
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
            'deal_value' => Money::format((float) $value),
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
            'lost_reason' => 'required_if:stage,lost|nullable|string|max:255',
            'reason' => 'nullable|string|max:1000',
        ]);

        $reason = $validated['stage'] === CrmCustomer::STAGE_LOST ? $validated['lost_reason'] : ($validated['reason'] ?? null);

        try {
            $stages->move($customer, $validated['stage'], $request->user(), $reason);
        } catch (CrmStageTransitionException $e) {
            return $this->stageError($request, $e->getMessage(), $e->status());
        }

        $message = "Đã chuyển khách hàng {$customer->name} sang giai đoạn {$customer->stage_label}!";
        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'stage' => $customer->stage,
                'stage_label' => $customer->stage_label,
            ]);
        }

        return redirect()->back()->with('status', $message);
    }

    /**
     * Gọi bằng fetch JSON (Kanban, tạo ưu đãi trong Chốt & Xếp lớp) → trả JSON. Form Inertia cũng gửi X-Requested-With
     * nhưng cần redirect như form thường (lỗi hiện dưới trường / thông báo flash).
     */
    protected function wantsJsonResponse(Request $request): bool
    {
        return $request->wantsJson() || ($request->ajax() && ! $request->hasHeader('X-Inertia'));
    }

    protected function stageError(Request $request, string $message, int $status = 422)
    {
        if ($status === 403 && ! $this->wantsJsonResponse($request)) {
            abort(403, $message);
        }
        if ($this->wantsJsonResponse($request)) {
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

    /** Khách đã xóa trước khi bỏ chức năng xoá (Admin / Quản lý cơ sở): tìm kiếm + khôi phục. */
    public function deletedCustomers(Request $request)
    {
        $this->authorizeDeletedCustomers($request);
        $query = $this->scopeCustomerQuery()->onlyTrashed()->with(['branch', 'assignedUser']);
        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }
        $deletedCustomers = $query->latest('deleted_at')->paginate($request->perPage(20))->withQueryString()
            ->through(fn (CrmCustomer $dc) => [
                'id' => $dc->id,
                'name' => $dc->name,
                'short_code' => $dc->short_code,
                'phone' => $dc->phone,
                'branch' => $dc->branch?->name,
                'stage' => $dc->stage,
                'stage_label' => $dc->stage_label,
                'assigned_user' => $dc->assignedUser?->name,
                'deleted_at' => $dc->deleted_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Crm/Customers/Deleted', ['deletedCustomers' => $deletedCustomers, 'chipCounts' => $this->chipCounts()]);
    }

    public function restoreCustomer(Request $request, $id)
    {
        $this->authorizeDeletedCustomers($request);
        $customer = $this->scopeCustomerQuery()->onlyTrashed()->whereKey($id)->firstOrFail();

        $phoneNormalized = $this->normalizePhone($customer->phone);
        $conflict = CrmCustomer::query()->where('phone_normalized', $phoneNormalized)->first(['id', 'code', 'name']);
        if ($conflict) {
            return back()->withErrors(['restore' => "Không thể khôi phục {$customer->name}: SĐT đang thuộc khách {$conflict->short_code} ({$conflict->name}). Hãy xử lý trùng trước."]);
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
        abort_unless($request->user()->can('lead.delete'), 403, 'Bạn không có quyền xem và khôi phục khách đã xóa.');
    }

    /**
     * Phân công lại người phụ trách (quyền lead.assign: Admin / Quản lý cơ sở / Học vụ), bắt buộc lý do, ghi lịch sử.
     * Chọn kèm cơ sở (chủ dự án 03/10/2026): người phụ trách phải là Học vụ cơ sở đó hoặc Admin; khác cơ sở hiện tại của khách
     * → chuyển khách (và học viên) sang cơ sở đó, Admin áp dụng ngay, người khác chờ Admin duyệt. Được giữ người phụ trách,
     * chỉ đổi cơ sở (vd Admin phụ trách).
     */
    public function reassignCustomer(Request $request, $id)
    {
        abort_unless($request->user()->can('lead.assign'), 403, 'Bạn không có quyền phân công lại khách.');
        $customer = $this->findScopedCustomer($id);
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'assigned_user_id' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'reason' => 'required|string|max:1000',
        ], ['reason.required' => 'Vui lòng nhập lý do phân công lại.']);

        $assignee = User::findOrFail($validated['assigned_user_id']);
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $currentBranchId = $customer->branch_id ? (int) $customer->branch_id : null;
        if ($branchId !== null && $branchId !== $currentBranchId && ! Branch::whereKey($branchId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'Cơ sở này đã ngừng hoạt động.']);
        }
        if ($branchId !== null && LeadOwners::isCandidate($assignee) && ! LeadOwners::belongsToBranch($assignee, $branchId)) {
            throw ValidationException::withMessages(['assigned_user_id' => "{$assignee->name} không thuộc cơ sở đã chọn. Chọn Học vụ cùng cơ sở hoặc Admin."]);
        }
        if ($assignee->id === $customer->assigned_user_id && ($branchId === null || $branchId === $currentBranchId)) {
            throw ValidationException::withMessages(['assigned_user_id' => 'Khách đang do người này phụ trách.']);
        }
        $pendingMessage = $this->changeOwner($customer, $assignee, $request->user(), $validated['reason'], 'Phân công lại người phụ trách', $branchId);

        return redirect()->route('crm.customers.show', $customer->id)->with('status', $pendingMessage ?? "Đã phân công lại khách cho {$assignee->name}.");
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
            ->whereHas('student')
            ->whereIn('status', ['pending', 'completed']);
        $query = $scoped()
            ->with(['student.user:id,email', 'classModel.branch', 'classModel.course', 'customer.assignedUser', 'confirmedBy'])
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
        $enrollments = $query->latest('enrolled_at')->latest('id')->paginate($request->perPage(20))->withQueryString()
            ->through(fn (ClassEnrollment $enrollment) => [
                'id' => $enrollment->id,
                'customer_id' => $enrollment->customer_id,
                'student' => $enrollment->student ? [
                    'name' => $enrollment->student->name,
                    'phone' => $enrollment->student->phone,
                    'code' => $enrollment->student->code,
                    'status' => $enrollment->student->status,
                    'status_label' => $enrollment->student->status_label,
                    'login' => $enrollment->student->user?->loginIdentifier(),
                ] : null,
                'customer_phone' => $enrollment->customer?->phone,
                'class' => [
                    'name' => $enrollment->classModel?->name,
                    'branch' => $enrollment->classModel?->branch?->name,
                    'code' => $enrollment->classModel?->code,
                    'start_label' => ClassStartActivation::classHasStarted($enrollment->classModel) || $enrollment->classModel?->status === 'completed'
                        ? 'Đã khai giảng'
                        : 'Sắp khai giảng'.($enrollment->classModel?->start_date ? ' '.$enrollment->classModel->start_date->format('d/m/Y') : ''),
                ],
                'closed_at' => ($enrollment->customer?->converted_at ?? $enrollment->enrolled_at)?->format('d/m/Y'),
                'confirmed_at' => $enrollment->confirmed_at?->format('d/m/Y H:i'),
                'confirmed_by' => $enrollment->confirmedBy?->name,
                'checklist' => collect(array_keys(ClassEnrollment::CONFIRMATION_CHECKLIST))
                    ->mapWithKeys(fn (string $field) => [$field => (bool) $enrollment->{$field}])->all(),
            ]);
        $pendingCount = $scoped()->whereNull('confirmed_at')->count();
        $totalCount = $scoped()->count();

        return Inertia::render('Crm/Confirmations', [
            'enrollments' => $enrollments,
            'status' => $status,
            'pendingCount' => $pendingCount,
            'totalCount' => $totalCount,
            'filterClasses' => Ui::options($filterClasses, 'name'),
            'filterBranches' => Ui::options($filterBranches, 'name'),
            'checklistLabels' => ClassEnrollment::CONFIRMATION_CHECKLIST,
            // Mật khẩu tạm vừa cấp (Cấp mật khẩu tạm) — chỉ hiện một lần.
            'studentAccount' => session('temporary_password') ? [
                'login' => session('student_account_login', session('student_account_email')),
                'password' => session('temporary_password'),
            ] : null,
        ] + $this->waitingClassCount());
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
                // Lớp đã khai giảng → học viên chính thức "Đang học"; lớp sắp mở giữ "Chờ khai giảng" tới ngày khai giảng
                // (lệnh hằng ngày students:start-studying).
                app(ClassStartActivation::class)->forEnrollment($enrollment);
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

    /**
     * Cấp mật khẩu tạm cho tài khoản cổng học viên (màn Xác nhận chính thức): mật khẩu lúc chốt chỉ hiện một lần cho
     * người chốt, còn Học vụ là người gửi tài khoản cho phụ huynh. Chỉ áp dụng tài khoản thuần học viên, không đụng
     * tài khoản nhân sự.
     */
    public function resetStudentAccount(Request $request, ClassEnrollment $enrollment)
    {
        abort_unless($enrollment->customer_id && $this->scopeCustomerQuery()->whereKey($enrollment->customer_id)->exists(), 404);
        $account = $enrollment->student?->user;
        if (! $account || $account->getRoleNames()->all() !== [Roles::STUDENT]) {
            throw ValidationException::withMessages(['enrollment' => 'Học viên chưa có tài khoản cổng học viên riêng, liên hệ Admin để cấp tài khoản.']);
        }

        $temporaryPassword = TemporaryPassword::generate();
        $account->forceFill([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'is_active' => true,
        ])->saveQuietly();

        activity('Người dùng & Phân quyền')->causedBy($request->user())->performedOn($account)
            ->event('updated')
            ->log('Cấp mật khẩu tạm cho tài khoản học viên (bắt buộc đổi ở lần đăng nhập tới)');
        CrmCustomerHistory::create([
            'customer_id' => $enrollment->customer_id,
            'user_id' => $request->user()->id,
            'type' => 'system',
            'content' => "Cấp mật khẩu tạm cho tài khoản học viên {$account->email}.",
        ]);

        return back()
            ->with('status', 'Đã cấp mật khẩu tạm cho học viên '.($enrollment->student?->name ?? '').'. Gửi thông tin đăng nhập cho phụ huynh; học viên phải đổi mật khẩu ở lần đăng nhập đầu.')
            ->with('student_account_email', $account->email)
            ->with('student_account_login', $account->loginIdentifier())
            ->with('temporary_password', $temporaryPassword);
    }

    public function addNote(Request $request, $id)
    {
        $customer = $this->findScopedCustomer($id);

        $validated = $request->validate([
            'content' => 'required_unless:type,result|nullable|string|max:1000',
            'type' => 'required|string|in:call,message,meet,test,note,result',
            // Kết quả liên hệ (gọi / nhắn / gặp): liên hệ được hay thất bại — thất bại không tính là đã liên hệ (SLA).
            'outcome' => 'nullable|string|in:reached,failed',
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

        $isContact = in_array($validated['type'], CrmCustomerHistory::CONTACT_TYPES, true);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => $validated['type'],
            'outcome' => $isContact ? ($validated['outcome'] ?? CrmCustomerHistory::OUTCOME_REACHED) : null,
            'content' => $content,
        ]);
        if ($isContact) {
            app(\App\Services\Sla\CrmSlaService::class)->checkFailedContacts($customer);
        }

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
            ->paginate($request->perPage(20))->withQueryString()
            ->through(function (CrmCustomer $wc) {
                $class = $wc->convertedStudent?->currentClass;
                $tuition = $wc->convertedStudent?->tuition;
                $pendingAmount = (float) ($tuition?->receipts?->where('status', 'pending')->sum('amount') ?? 0);
                $enrollment = $wc->convertedStudent?->enrollments?->where('customer_id', $wc->id)->sortByDesc('id')->first();

                return [
                    'id' => $wc->id,
                    'name' => $wc->name,
                    'phone' => $wc->phone,
                    'course_interest' => $wc->course_interest,
                    'assigned_user' => $wc->assignedUser?->name,
                    'branch' => $wc->branch?->name,
                    'class' => $class ? ['name' => $class->name, 'code' => $class->code] : null,
                    'converted_at' => $wc->converted_at?->format('H:i d/m/Y'),
                    'tuition_badge' => $pendingAmount > 0 ? 'bg-warning-container text-on-warning-container border-warning/30'
                        : ($tuition?->status_badge ?? 'bg-surface-container-low text-on-surface-variant border-outline-variant'),
                    'tuition_label' => ($pendingAmount > 0 ? 'Chờ đối soát '.Money::format($pendingAmount) : ($tuition?->status_label ?? 'Chưa có học phí'))
                        .($tuition && $tuition->debt_amount > 0 ? ' · Còn '.Money::format(max(0, $tuition->debt_amount - $pendingAmount)) : ''),
                    'enrollment' => $enrollment ? ['confirmed' => (bool) $enrollment->confirmed_at] : null,
                    'student_code' => $wc->convertedStudent?->code,
                ];
            });

        return Inertia::render('Crm/Won', [
            'wonCustomers' => $wonCustomers,
            'totalCount' => $totalCount,
            'totalContractAmount' => $totalContractAmount,
            'totalCollectedAmount' => $totalCollectedAmount,
            'totalDebtAmount' => $totalDebtAmount,
            'filterClasses' => Ui::options($filterClasses, 'name'),
            'chipCounts' => $this->chipCounts(),
            // Kết quả Chốt & Xếp lớp (phiếu thu, tài khoản học viên vừa tạo — mật khẩu chỉ hiện một lần).
            'closing' => [
                'status' => session('status'),
                'bill_url' => session('bill_url'),
                'account' => session('temporary_password') ? [
                    'login' => session('student_account_login', session('student_account_email')),
                    'password' => session('temporary_password'),
                ] : null,
            ],
        ] + $this->waitingClassCount() + $this->listFilterOptions());
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

        return $this->downloadTable('khach-chot-thanh-cong', ['Mã KH', 'Họ tên', 'SĐT', 'Phụ huynh', 'Cơ sở', 'Nguồn', 'Khóa đăng ký', 'Lớp', 'Giá trị HĐ', 'Đã thu (duyệt)', 'Còn nợ', 'Người phụ trách', 'Ngày chốt'], $rows, $format);
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
        $levels = app(PlacementLevelMatcher::class);
        $customers = $this->scopeCustomerQuery()
            ->with(['branch', 'latestSubmission.test', 'assignedTest'])
            ->whereIn('stage', CrmCustomer::CLOSABLE_STAGES)
            ->when($selectedCustomerId, fn (Builder $query, int $customerId) => $query->orderByRaw('id = ? desc', [$customerId]))
            ->latest()
            ->get()
            // Mockup quy-trinh-chot-xep-lop: "Trình độ" của khách (lớp xếp sau test) + từ khóa để gợi ý lớp phù hợp.
            ->each(function (CrmCustomer $customer) use ($levels) {
                $customer->setAttribute('grade_label', PlacementTest::gradeLevelLabel($levels->gradeLevelOf($customer)));
                $customer->setAttribute('level_label', $customer->latestSubmission?->finalClass() ?? $customer->course_interest);
                $customer->setAttribute('level_keys', $this->trialLevelKeywords($customer, $customer->latestSubmission));
            });
        // Mở từ menu (không có customer_id): không tự chọn khách (dễ chốt nhầm) — trừ khi chỉ có đúng 1 khách.
        // Người dùng chọn khách → trang tải lại theo customer_id để lọc lớp / tài khoản theo chi nhánh và trình độ.
        if (! $selectedCustomer && $customers->count() === 1) {
            $selectedCustomer = $customers->first();
        }
        $pickedCustomer = $selectedCustomer ? $customers->firstWhere('id', $selectedCustomer->id) : null;
        $levelKeys = $pickedCustomer?->level_keys ?? [];
        $branches = Branch::all();
        $courses = Course::where('is_active', true)->get();
        // Lớp đang học + lớp sắp khai giảng (chưa bắt đầu), còn chỗ.
        $classes = $this->withRosterSeats($this->enrollableClassesQuery()
            ->when($selectedCustomer?->branch_id, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->with(['course.level', 'branch', 'teacher'])
            ->orderByRaw("CASE WHEN status = 'upcoming' THEN 0 ELSE 1 END")
            ->orderBy('start_date')
            ->get())
            ->filter(fn (ClassModel $class) => $class->max_capacity <= 0 || $class->active_enrollments_count < $class->max_capacity)
            ->each(function (ClassModel $class) use ($levels) {
                $class->setAttribute('level_name', $levels->classLevelName($class));
                $class->setAttribute('remaining_seats', $class->max_capacity > 0 ? max(0, $class->max_capacity - $class->active_enrollments_count) : null);
                $class->setAttribute('needed_to_open', $class->status === 'upcoming' ? max(0, (int) $class->min_students - $class->active_enrollments_count) : 0);
                $class->setAttribute('level_haystack', Str::upper(implode(' ', array_filter([
                    $class->name, $class->level, $class->course?->name, $class->course?->level?->name,
                ]))));
            })
            // Lớp khớp của khách lên đầu (sortBy giữ nguyên thứ tự khai giảng trong cùng nhóm): đúng cấp độ test
            // (trình độ ghép với cấp độ ở Cấu hình Trình độ), hoặc tên lớp / khóa / trình độ chứa trình độ xếp sau test.
            ->each(fn (ClassModel $class) => $class->setAttribute('level_match', ($pickedCustomer && $levels->matchesGrade($pickedCustomer, $class))
                || collect($levelKeys)->contains(fn (string $key) => str_contains($class->level_haystack, $key))))
            ->sortBy(fn (ClassModel $class) => $class->level_match ? 0 : 1)
            ->values();
        $heldByClass = app(SessionLedger::class)->heldCounts($classes->pluck('id')->all());
        // Chỉ chọn sẵn lớp khi khớp trình độ và lớp còn buổi của khóa (lớp đã học hết khóa không xếp thêm được);
        // không có thì để trống cho người dùng tự chọn.
        $defaultClass = $classes->first(fn (ClassModel $class) => $class->level_match
            && SessionLedger::joinTuition($class, (int) ($heldByClass[$class->id] ?? 0))['sessions'] > 0);
        $defaultCourseId = $defaultClass?->course_id
            ?? ($levelKeys ? Course::where('is_active', true)->get()->first(fn (Course $course) => collect($levelKeys)
                ->contains(fn (string $key) => str_contains(Str::upper($course->name.' '.$course->code), $key)))?->id : null);
        $bankAccounts = BankAccount::where('is_active', true)
            ->when($selectedCustomer?->branch_id, fn (Builder $query, int $branchId) => $query
                ->where(fn (Builder $accountQuery) => $accountQuery->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->get();
        // Chỉ ưu đãi dùng lại được (danh mục); ưu đãi riêng ca đặc biệt tạo ngay trong màn này.
        $promotions = Promotion::available()->catalog()->orderByDesc('is_default')->orderBy('name')->get();
        $merchandiseItems = MerchandiseItem::active()->orderBy('category')->orderBy('name')->get();

        // Mã học viên cấp sẵn khi mở màn chốt để nội dung CK / VietQR xem trước đúng với mã thật sau khi chốt.
        $studentCodePreview = self::newStudentCode();

        $defaultBank = $bankAccounts->firstWhere('is_default_vietqr', true) ?? $bankAccounts->first();

        return Inertia::render('Crm/ClosingWizard', [
            'customers' => $customers->map(fn (CrmCustomer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'short_code' => $c->short_code,
                'branch_code' => $c->branch?->code ?? 'BD',
                'branch_id' => $c->branch_id,
                'stage_label' => $c->stage_label,
                'level_label' => $c->level_label,
                'grade_label' => $c->grade_label,
                'level_keys' => $c->level_keys,
                'course_interest' => $c->course_interest,
            ])->values()->all(),
            'pickedCustomerId' => $pickedCustomer?->id,
            'branches' => Ui::options($branches, 'name'),
            'courses' => $courses->map(fn (Course $course) => [
                'id' => $course->id, 'name' => $course->name, 'tuition' => (float) $course->tuition_fee,
            ])->values()->all(),
            'classes' => $classes->map(fn (ClassModel $cl) => [
                'id' => $cl->id,
                'name' => $cl->name,
                'code' => $cl->code,
                // Lớp đã học được vài buổi: học phí chỉ tính số buổi còn lại của khóa (sổ buổi).
                'course_sessions' => ($join = SessionLedger::joinTuition($cl, (int) ($heldByClass[$cl->id] ?? 0)))['course_sessions'],
                'sessions_left' => $join['sessions'],
                'status' => $cl->status,
                'start_label' => $cl->start_date?->format('d/m'),
                'branch_code' => $cl->branch?->code ?? 'BD',
                'branch_id' => $cl->branch_id,
                'branch_name' => $cl->branch?->name,
                'course_id' => $cl->course_id,
                'course_name' => $cl->course?->name,
                'schedule_text' => $cl->schedule_text,
                'teacher' => $cl->teacher?->name,
                'tuition' => (float) $join['fee'],
                'active_enrollments_count' => (int) $cl->active_enrollments_count,
                'max_capacity' => (int) $cl->max_capacity,
                'min_students' => (int) $cl->min_students,
                'remaining_seats' => $cl->remaining_seats,
                'needed_to_open' => $cl->needed_to_open,
                'level_haystack' => $cl->level_haystack,
                'level_name' => $cl->level_name,
                'level_match' => (bool) $cl->level_match,
            ])->values()->all(),
            'bankAccounts' => $bankAccounts->map(fn (BankAccount $bank) => [
                'id' => $bank->id,
                'bank_code' => $bank->bank_code,
                'bank_name' => $bank->bank_name,
                'account_number' => $bank->account_number,
                'account_holder' => $bank->account_holder,
            ])->values()->all(),
            'defaultBankAccountId' => $defaultBank?->id,
            'promotions' => $promotions->map(fn (Promotion $promotion) => PromotionController::props($promotion))->values()->all(),
            'merchandiseItems' => $merchandiseItems->map(fn (MerchandiseItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'price' => (int) $item->price,
                'category_label' => $item->category_label,
                'formatted_price' => $item->formatted_price,
            ])->values()->all(),
            'defaultClassId' => $defaultClass?->id,
            'defaultCourseId' => $defaultCourseId,
            'studentCodePreview' => $studentCodePreview,
            'oldPaperInvoiceNumber' => (string) old('paper_invoice_number', ''),
            // Chi nhánh có dải hóa đơn giấy: số hóa đơn giấy kế tiếp (thu tiền mặt) + tồn kho sách theo chi nhánh.
            'paperInvoiceNext' => (object) $branches->mapWithKeys(fn (Branch $branch) => [
                $branch->id => InvoiceConfiguration::branchUsesPaperRange($branch->id) ? (InvoiceConfiguration::peekNextPaperNumber($branch->id) ?? '') : null,
            ])->filter(fn ($next) => $next !== null)->all(),
            'merchandiseStock' => (object) app(StockService::class)->quantitiesByBranch($branches->pluck('id')->map(fn ($id) => (int) $id)->all()),
            'center' => [
                'name' => CenterInfo::name(),
                'branches' => CenterInfo::branches()->map(fn (Branch $branch) => ['name' => $branch->name, 'address' => $branch->address])->values()->all(),
                'phone' => CenterInfo::phone(),
            ],
            'billDates' => [
                'day' => now()->format('d'),
                'month' => now()->format('m'),
                'year' => now()->format('Y'),
                'from' => now()->format('01/m/Y'),
                'to' => now()->addMonths(3)->format('d/m/Y'),
            ],
        ]);
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
            'course_id' => 'nullable|required_without:class_id|exists:courses,id,deleted_at,NULL',
            'fee_paid_at_closing' => 'nullable|boolean',
            'promotion_id' => 'nullable|exists:promotions,id',
            'fee_items' => 'nullable',
            'prepaid_amount' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            // Trung tâm chỉ thu chuyển khoản hoặc tiền mặt (không quẹt thẻ POS, không thanh toán kết hợp).
            'payment_method' => 'nullable|in:cash,transfer',
            'paper_invoice_number' => 'nullable|string|max:100',
            'expected_paper_invoice_number' => 'nullable|string|max:100',
            'paper_invoice_photo' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
            'student_code' => 'nullable|string|max:40',
            'transfer_memo' => 'nullable|string|max:255',
            'bank_account_id' => 'nullable|exists:bank_accounts,id,deleted_at,NULL',
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
        // Tiền mặt thu theo hóa đơn giấy: chi nhánh có dải hóa đơn giấy → hệ thống cấp số, Học vụ ghi đúng số đó lên
        // hóa đơn và tải ảnh; chi nhánh chưa cấu hình dải giấy → nhập tay số hóa đơn giấy như trước.
        $wizardBranchId = ! empty($validated['class_id'])
            ? ClassModel::whereKey($validated['class_id'])->value('branch_id')
            : CrmCustomer::whereKey($validated['customer_id'])->value('branch_id');
        $paperRange = $paidAmount > 0 && $paymentMethod === 'cash' && InvoiceConfiguration::branchUsesPaperRange($wizardBranchId ? (int) $wizardBranchId : null);
        $paperInvoiceNumber = trim((string) ($validated['paper_invoice_number'] ?? '')) ?: null;
        if ($paperRange && ! $request->hasFile('paper_invoice_photo')) {
            throw ValidationException::withMessages(['paper_invoice_photo' => 'Thu tiền mặt cần tải ảnh chụp hóa đơn giấy mang đúng số hệ thống cấp, đã ghi đúng nội dung thu.']);
        }
        if (! $paperRange && $paidAmount > 0 && $paymentMethod === 'cash' && ! $paperInvoiceNumber) {
            throw ValidationException::withMessages(['paper_invoice_number' => 'Thu tiền mặt cần nhập số hóa đơn giấy đã xuất cho khách.']);
        }
        $paperPhoto = $paperRange
            ? '/uploads/tuition/receipts/'.SafeUploadService::moveTo($request->file('paper_invoice_photo'), public_path('uploads/tuition/receipts'), ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 'paper_invoice_photo')
            : null;

        $result = DB::transaction(function () use ($request, $validated, $paidAmount, $prepaidAmount, $feePaid, $paymentMethod, $paperInvoiceNumber, $paperRange, $paperPhoto): array {
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
                if (! $class->hasSeatsFor()) {
                    throw ValidationException::withMessages(['class_id' => 'Lớp đã đủ sĩ số, vui lòng chọn lớp khác.']);
                }
                $course = $class->course;
                // Vào lớp giữa khóa: học phí = số buổi còn lại của khóa × đơn giá (Buổi cần thu khi sổ buổi chưa có buổi tồn).
                $join = SessionLedger::joinTuition($class, app(SessionLedger::class)->heldSessions($class));
                $baseTuition = (float) $join['fee'];
                $contractSessions = $join['sessions'];
            } else {
                $course = Course::whereKey($validated['course_id'])->where('is_active', true)->first();
                if (! $course) {
                    throw ValidationException::withMessages(['course_id' => 'Khóa học không còn hoạt động.']);
                }
                // Chưa có lớp: học phí theo giá niêm yết của khóa (trừ ưu đãi), không phụ thuộc lớp.
                $baseTuition = (float) $course->tuition_fee;
                $contractSessions = SessionLedger::courseSessions(null, $course);
            }
            if ($class && $contractSessions <= 0) {
                throw ValidationException::withMessages(['class_id' => 'Lớp đã học hết số buổi của khóa, vui lòng chọn lớp khác.']);
            }
            if ($baseTuition <= 0) {
                throw ValidationException::withMessages([$class ? 'class_id' : 'course_id' => 'Lớp / khóa học chưa được cấu hình học phí.']);
            }
            $branchId = $class?->branch_id ?? $customer->branch_id;
            $branch = $class?->branch ?? ($branchId ? Branch::find($branchId) : null);

            $needsBankAccount = $paidAmount > 0 && $paymentMethod === 'transfer';
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
                // Ưu đãi riêng (ca đặc biệt) chỉ người tạo dùng cho khách đang chốt, không lấy lại cho khách khác.
                if ($promotion->is_special && (int) $promotion->created_by !== (int) Auth::id()) {
                    throw ValidationException::withMessages(['promotion_id' => 'Ưu đãi riêng này do người khác tạo cho khách khác, hãy chọn ưu đãi trong danh mục.']);
                }
                $discount = $promotion->calculateDiscount($baseTuition);
            }

            $feeItems = $this->resolveFeeItems($request->input('fee_items'));
            $otherFees = array_sum(array_column($feeItems, 'amount'));
            $contractTotal = max(0, $baseTuition - $discount + $otherFees);
            // Khoản thu trước (đã thu ngoài phiếu) cần người có quyền duyệt phiếu thu xác nhận (tuition.approve), không theo vai trò.
            if ($prepaidAmount > 0 && ! $request->user()->can('tuition.approve')) {
                throw ValidationException::withMessages(['prepaid_amount' => 'Khoản thu trước phải được quản lý xác nhận.']);
            }
            if ($prepaidAmount > $contractTotal) {
                throw ValidationException::withMessages(['prepaid_amount' => 'Khoản thu trước vượt quá giá trị hợp đồng.']);
            }

            $netDue = $contractTotal - $prepaidAmount;
            if ($paidAmount > $netDue) {
                throw ValidationException::withMessages(['paid_amount' => 'Số tiền thu vượt quá số tiền còn phải nộp.']);
            }

            // Dùng mã đã cấp sẵn trên màn chốt (đúng định dạng, chưa ai dùng) để khớp VietQR đã hiển thị cho khách.
            $proposedCode = Str::upper((string) ($validated['student_code'] ?? ''));
            $studentCode = preg_match(self::STUDENT_CODE_PATTERN, $proposedCode)
                && ! Student::withTrashed()->where('code', $proposedCode)->exists()
                ? $proposedCode
                : self::newStudentCode();
            // Lead có email: tài khoản cổng học viên dùng email đó. Chưa có: email tự sinh "student<id>@..." theo id hồ sơ,
            // nên tài khoản được tạo sau hồ sơ học viên.
            $studentEmail = $customer->email ?: null;
            $studentUser = $studentEmail
                ? User::withTrashed()->whereRaw('LOWER(email) = ?', [Str::lower($studentEmail)])->first()
                : null;
            $temporaryPassword = null;

            if ($studentUser) {
                $alreadyLinked = Student::where('user_id', $studentUser->id)->exists();
                // Chỉ tái sử dụng tài khoản học viên (cổng học viên) chưa liên kết hồ sơ; tài khoản nhân sự → báo trùng email.
                if ($alreadyLinked || ! $studentUser->can('portal.student')) {
                    throw ValidationException::withMessages(['customer_id' => 'Email của Lead đã thuộc một tài khoản khác. Vui lòng cập nhật email riêng cho học viên.']);
                }
                if ($studentUser->trashed()) {
                    $studentUser->restore();
                }
            }

            $student = Student::create([
                'code' => $studentCode,
                'user_id' => $studentUser?->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                // Liên hệ phụ huynh chép sang hồ sơ học viên (gửi kết quả Big Test qua Zalo).
                'parent_name' => $customer->parent_name,
                'parent_phone' => $customer->parent_phone,
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

            if (! $studentUser) {
                $studentEmail ??= User::generatedStudentEmail($student->id);
                if (User::withTrashed()->whereRaw('LOWER(email) = ?', [Str::lower($studentEmail)])->exists()) {
                    throw ValidationException::withMessages(['customer_id' => "Email {$studentEmail} đã thuộc một tài khoản khác, liên hệ Admin để xử lý trước khi chốt."]);
                }
                $temporaryPassword = TemporaryPassword::generate();
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
                Role::findOrCreate(Roles::STUDENT, 'web');
                $studentUser->assignRole(Roles::STUDENT);
                $student->update(['user_id' => $studentUser->id, 'email' => $studentEmail]);
            }

            if ($class) {
                $this->enrollStudent($student, $class, $customer);
            }

            // Memo phải sinh từ mã học viên thật sau khi tạo hồ sơ — giá trị preview phía client
            // chỉ mang tính minh hoạ (không biết trước mã HV) nên luôn bị ghi đè.
            $transferMemo = self::buildTransferMemo($student->code, $student->name, $class?->name);
            $tuition = StudentTuition::create([
                'student_id' => $student->id,
                'class_id' => $class?->id,
                'branch_id' => $branchId,
                'bank_account_id' => $bankAccount?->id,
                'promotion_id' => $promotion?->id,
                'total_amount' => $baseTuition,
                'session_count' => max(1, $contractSessions),
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
                $issuedPaperNumber = null;
                if ($paperRange && $branchId) {
                    try {
                        $issuedPaperNumber = InvoiceConfiguration::consumeNextPaperNumber((int) $branchId, $validated['expected_paper_invoice_number'] ?? null);
                    } catch (PaperInvoiceNumberChangedException|InvoiceRangeExhaustedException $e) {
                        throw ValidationException::withMessages(['paper_invoice_photo' => $e->getMessage()]);
                    }
                }
                TuitionReceipt::create([
                    'receipt_number' => TuitionReceipt::generateReceiptNumber(),
                    'invoice_number' => $issuedPaperNumber,
                    'student_tuition_id' => $tuition->id,
                    'student_id' => $student->id,
                    'amount' => $paidAmount,
                    'tuition_amount' => min($paidAmount, max(0, $baseTuition - $discount)),
                    'payment_method' => $paymentMethod,
                    'paper_invoice_number' => $paymentMethod === 'cash' ? ($issuedPaperNumber ?? $paperInvoiceNumber) : null,
                    'proof_image' => $issuedPaperNumber ? $paperPhoto : null,
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
            app(CrmStageService::class)->advanceTo(
                $customer,
                $class ? 'won' : 'waiting_class',
                $request->user(),
                'Chốt hợp đồng qua Chốt & Xếp lớp (Tổng giá trị: '.Money::format($contractTotal).', '
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
            ->with('student_account_login', $student->user?->loginIdentifier() ?? $student->email)
            ->with('temporary_password', $temporaryPassword);
    }

    /**
     * Sĩ số giữ chỗ theo danh sách lớp thật (ClassModel::roster: lớp hiện tại + lượt xếp lớp còn hiệu lực,
     * bỏ học viên Thôi học / Hoàn thành / Bảo lưu) — cùng nguồn với màn Lớp học và kiểm tra hasSeatsFor().
     * Gán vào active_enrollments_count để các view đang dùng thuộc tính này hiện đúng.
     */
    protected function withRosterSeats(Collection $classes): Collection
    {
        ClassModel::loadRosterCounts($classes);

        return $classes->each(fn (ClassModel $class) => $class->setAttribute('active_enrollments_count', $class->roster_count));
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
    protected function enrollStudent(Student $student, ClassModel $class, CrmCustomer $customer): ClassEnrollment
    {
        $enrollment = ClassEnrollment::create([
            'student_id' => $student->id,
            'class_id' => $class->id,
            'customer_id' => $customer->id,
            'enrolled_at' => now(),
            'curriculum_delivered' => false,
            'zalo_group_added' => false,
            'status' => 'pending',
        ]);
        $student->forceFill(['current_class_id' => $class->id])->save();

        return $enrollment;
    }

    /** Chốt khi chưa đóng học phí → task "Nhắc thu học phí" cho người phụ trách lead. */
    protected function createFeeReminderTask(CrmCustomer $customer, Student $student, StudentTuition $tuition, User $actor): WorkTask
    {
        return WorkTask::create([
            'title' => "Nhắc thu học phí: {$student->name} ({$student->code})",
            'description' => "Học viên {$student->name} ({$student->code}) đã chốt từ Lead {$customer->short_code} nhưng chưa đóng học phí đăng ký. "
                .'Số tiền cần thu: '.Money::format((float) $tuition->final_amount).'. '
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
    public function assignClass(Request $request, WaitingLeadPlacement $placement, $id)
    {
        $validated = $request->validate(['class_id' => 'required|exists:classes,id']);

        DB::transaction(function () use ($request, $placement, $validated, $id) {
            $customer = $this->scopeCustomerQuery()
                ->where(fn (Builder $query) => $query->where('id', $id)->orWhere('code', $id))
                ->lockForUpdate()
                ->firstOrFail();
            $student = $customer->converted_student_id ? Student::lockForUpdate()->find($customer->converted_student_id) : null;
            if ($customer->stage !== 'waiting_class' || ! $student) {
                throw ValidationException::withMessages(['class_id' => 'Chỉ xếp lớp cho học viên đang Chờ xếp lớp.']);
            }

            $class = ClassModel::with('course')->lockForUpdate()->findOrFail($validated['class_id']);
            // Cùng điều kiện với Chốt & Xếp lớp: lớp "Sắp khai giảng" đã quá ngày khai giảng thì không nhận.
            if (! $this->isEnrollableClass($class) || ! $class->course?->is_active) {
                throw ValidationException::withMessages(['class_id' => 'Lớp hoặc khóa học không còn hoạt động.']);
            }
            $branchId = $student->branch_id ?? $customer->branch_id;
            if ($branchId && $class->branch_id !== $branchId) {
                throw ValidationException::withMessages(['class_id' => 'Lớp phải thuộc chi nhánh của học viên.']);
            }
            $placement->assertMatchesClosedCourse($customer, $class);
            $placement->assertMatchesClosedBranch($customer, $class);
            $placement->assertNotInClass($student, $class);
            if (! $class->hasSeatsFor()) {
                throw ValidationException::withMessages(['class_id' => 'Lớp đã đủ sĩ số, vui lòng chọn lớp khác.']);
            }

            $enrollment = $this->enrollStudent($student, $class, $customer);
            $placement->complete($customer, $student, $class, $enrollment, $request->user());
        }, 3);

        return redirect()->back()->with('status', 'Đã xếp lớp cho học viên và chuyển Lead sang Đã chốt.');
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

        $items = MerchandiseItem::active()->whereIn('id', $ids)->get()->keyBy('id');
        if ($items->count() !== $ids->count()) {
            throw ValidationException::withMessages(['fee_items' => 'Có hàng hóa không tồn tại hoặc đã ngừng bán.']);
        }

        // Kho theo chi nhánh: sách trong hợp đồng xuất kho khi phiếu thu đầu tiên của hợp đồng được duyệt
        // (App\Services\Merchandise\StockService); hết hàng chỉ cảnh báo trên màn chốt, không chặn chốt khách.
        return $ids->map(fn ($id) => [
            'id' => (int) $id,
            'name' => $items[$id]->name,
            'amount' => (float) $items[$id]->price,
            'quantity' => 1,
            'stock_tracked' => true,
        ])->all();
    }

    /** Mã học viên sinh khi chốt Lead: HV-<ULID>. */
    private const STUDENT_CODE_PATTERN = '/^HV-[0-9A-HJKMNP-TV-Z]{26}$/';

    private static function newStudentCode(): string
    {
        return 'HV-'.Str::upper((string) Str::ulid());
    }

    /** @see TransferMemo::build() — giữ lại để tương thích nơi gọi cũ. */
    public static function buildTransferMemo(string $studentCode, string $studentName, ?string $className = null): string
    {
        return TransferMemo::build($studentCode, $studentName, $className);
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
            // Phạm vi chi nhánh của học phí: Học vụ / Kế toán cơ sở A không xem bill của cơ sở B.
            abort_unless(TuitionBranchScope::allowsTuition($tuition, TuitionBranchScope::branchIds($request->user())), 404);
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

        // Luôn sinh theo mẫu hiện hành (tên + mã + lớp) để nội dung cũ / lớp vừa xếp được cập nhật.
        // Phụ huynh đã CK theo nội dung cũ vẫn được SePay khớp nhờ mã học sinh trong nội dung.
        $transferMemo = $tuition->syncTransferMemo();
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

            return $this->downloadTable('khach-khong-chot', ['Mã KH', 'Họ tên', 'SĐT', 'Cơ sở', 'Nguồn', 'Khóa quan tâm', 'Giá trị dự kiến', 'Lý do thất bại', 'Người phụ trách', 'Ngày thất bại', 'Ghi chú'], $rows, $request->input('export'));
        }

        $lostTotal = $this->scopeCustomerQuery()->where('stage', 'lost')->count();
        $lostCustomers = $query->latest('lost_at')->latest()->paginate($request->perPage(20))->withQueryString()
            ->through(fn (CrmCustomer $lc) => [
                'id' => $lc->id,
                'name' => $lc->name,
                'phone' => $lc->phone,
                'course_interest' => $lc->course_interest,
                'branch' => $lc->branch?->name,
                'lost_reason' => $lc->lost_reason,
                'assigned_user' => $lc->assignedUser?->name,
                'lost_at' => $lc->lost_at?->format('H:i - d/m/Y'),
            ]);

        return Inertia::render('Crm/LostDeals', [
            'lostCustomers' => $lostCustomers,
            'lostTotal' => $lostTotal,
            'chipCounts' => $this->chipCounts(),
        ] + $this->listFilterOptions());
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
                [$startDate, $endDate, $prevStartDate, $prevEndDate] = $this->rollingDays($now, 7);
                $presetLabel = '7 ngày';
                break;
            case 'last_week':
                $startDate = $now->copy()->subWeek()->startOfWeek();
                $endDate = $now->copy()->subWeek()->endOfWeek();
                $prevStartDate = $now->copy()->subWeeks(2)->startOfWeek();
                $prevEndDate = $now->copy()->subWeeks(2)->endOfWeek();
                $presetLabel = 'Tuần trước';
                break;
            case 'last_60_days':
                [$startDate, $endDate, $prevStartDate, $prevEndDate] = $this->rollingDays($now, 60);
                $presetLabel = '60 ngày';
                break;
            case 'last_90_days':
                [$startDate, $endDate, $prevStartDate, $prevEndDate] = $this->rollingDays($now, 90);
                $presetLabel = '90 ngày';
                break;
            case 'last_6_months':
                $startDate = $now->copy()->subMonths(6)->addDay()->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $prevStartDate = $startDate->copy()->subMonths(6);
                $prevEndDate = $startDate->copy()->subSecond();
                $presetLabel = '6 tháng';
                break;
            case 'last_year':
                $startDate = $now->copy()->subYear()->addDay()->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $prevStartDate = $startDate->copy()->subYear();
                $prevEndDate = $startDate->copy()->subSecond();
                $presetLabel = '1 năm';
                break;
            case 'custom':
                $startDate = ($this->parseReportDate($request->get('start_date')) ?? $now->copy()->subDays(29))->startOfDay();
                $endDate = ($this->parseReportDate($request->get('end_date')) ?? $now->copy())->endOfDay();
                if ($startDate->gt($endDate)) {
                    // Chọn ngược "từ" / "đến" → đảo lại thay vì trả báo cáo rỗng.
                    [$startDate, $endDate] = [$endDate->copy()->startOfDay(), $startDate->copy()->endOfDay()];
                }
                // Kỳ trước: cùng số ngày, kết thúc ngay trước ngày bắt đầu (không chồng ngày).
                $days = (int) $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1;
                $prevStartDate = $startDate->copy()->subDays($days);
                $prevEndDate = $startDate->copy()->subSecond();
                $presetLabel = 'Tùy chỉnh';
                break;
            case 'last_30_days':
            default:
                $preset = 'last_30_days';
                [$startDate, $endDate, $prevStartDate, $prevEndDate] = $this->rollingDays($now, 30);
                $presetLabel = '30 ngày';
                break;
        }

        // Phạm vi CRM toàn hệ thống chọn mọi chi nhánh; còn lại chỉ chi nhánh của mình (dữ liệu vẫn giới hạn bởi scopeVisibleTo).
        $reportUser = $request->user();
        $branches = Branch::where('is_active', true)
            ->when(! DataScope::isAll($reportUser, 'lead'), fn (Builder $q) => $q->whereIn('id', $reportUser->branchIds()))
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
        $wonCurrent = (clone $query)->whereIn('stage', CrmCustomer::CLOSED_STAGES)
            ->where(fn (Builder $q) => $q->whereBetween('converted_at', [$startDate, $endDate])
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('converted_at')->whereBetween('created_at', [$startDate, $endDate])))
            ->get();
        $wonPrev = (clone $query)->whereIn('stage', CrmCustomer::CLOSED_STAGES)
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
        $cohortWonDeals = $allCurrent->whereIn('stage', CrmCustomer::CLOSED_STAGES)->count();
        $conversionRate = $totalLeads > 0 ? round(($cohortWonDeals / $totalLeads) * 100, 1) : 0;

        // Thống kê so sánh với kỳ trước (100% Thực tế)
        $prevTotalLeads = $allPrev->count();
        $prevWonDeals = $wonPrev->count();
        $prevLostDeals = $lostPrev->count();
        $prevCohortWonDeals = $allPrev->whereIn('stage', CrmCustomer::CLOSED_STAGES)->count();
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

        // Cùng căn cứ với bảng lương (SalesCommissionService): học phí thực thu (không gồm sách / Thu khác) × % mốc
        // theo thứ tự chốt của từng HS. Đây là hoa hồng PHÁT SINH; trả thực tế theo gate kép trên phiếu lương.
        $commissionService = app(SalesCommissionService::class);
        // Doanh số / số chốt của từng sale ghi theo người nhận hoa hồng (commission_user_id), kể cả khi khách đã
        // được phân công lại cho người khác → không lọc theo người phụ trách hiện tại; chỉ giới hạn chi nhánh.
        $viewerLevel = DataScope::level($reportUser, 'lead');
        $creditQuery = CrmCustomer::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when($viewerLevel === DataScope::BRANCH, fn (Builder $q) => $q->whereIn('branch_id', $reportUser->branchIds()));
        $wonCredited = (clone $creditQuery)->whereIn('stage', CrmCustomer::CLOSED_STAGES)
            ->where(fn (Builder $q) => $q->whereBetween('converted_at', [$startDate, $endDate])
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('converted_at')->whereBetween('created_at', [$startDate, $endDate])))
            ->get(['id', 'commission_user_id', 'assigned_user_id']);

        // 4. Bảng hiệu suất theo nhân viên tư vấn tuyển sinh (100% Real from Users in Database)
        // Doanh số = tiền thực thu của khách mới (phiếu duyệt trong kỳ, gồm giáo trình/đồ dùng) — A6.
        // Mốc hoa hồng tính trên TOÀN BỘ HS chốt trong kỳ (mọi chi nhánh) như bảng lương — không theo bộ lọc chi nhánh.
        $commissionByOwner = $commissionService->summaryByOwner($startDate, $endDate, null, $creditQuery);
        $collectedBySales = $commissionByOwner->map(fn (array $row) => $row['collected'])->filter(fn (float $v) => $v > 0);

        // Mọi Sales + bất kỳ ai (Quản lý cơ sở, vai trò tự tạo...) có khách / doanh số / khách chốt trong kỳ,
        // để tổng các dòng khớp số liệu tổng phía trên.
        $repIds = $allCurrent->pluck('assigned_user_id')
            ->merge($collectedBySales->keys())
            ->merge($wonCredited->map(fn (CrmCustomer $lead) => $lead->commission_user_id ?? $lead->assigned_user_id))
            ->filter()->unique()->values();
        $salesUsers = User::query()
            ->where(fn (Builder $q) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', Roles::SALES_CONSULTANT))
                ->orWhereIn('id', $repIds))
            ->with(['roles', 'branches'])
            ->get();
        $salesUsers = $this->scopeReportReps($salesUsers, $request->user());

        $repsData = [];
        foreach ($salesUsers as $user) {
            $userLeads = $allCurrent->where('assigned_user_id', $user->id);
            $userLeadsCount = $userLeads->count();
            $userWonCount = $wonCredited->filter(fn (CrmCustomer $lead) => (int) ($lead->commission_user_id ?? $lead->assigned_user_id) === (int) $user->id)->count();
            $userCohortWonCount = $userLeads->whereIn('stage', CrmCustomer::CLOSED_STAGES)->count();
            $userRevenue = (float) ($collectedBySales->get($user->id) ?? 0);
            $userRate = $userLeadsCount > 0 ? round(($userCohortWonCount / $userLeadsCount) * 100, 1) : 0;

            // Tỷ lệ chốt kỳ trước của cùng rep để tính delta thực (không dùng baseline cứng)
            $prevUserLeadsCount = $allPrev->where('assigned_user_id', $user->id)->count();
            // Cùng công thức cohort với kỳ hiện tại (khách tạo trong kỳ đã chốt / khách tạo trong kỳ).
            $prevUserWonCount = $allPrev->where('assigned_user_id', $user->id)->whereIn('stage', CrmCustomer::CLOSED_STAGES)->count();
            $prevUserRate = $prevUserLeadsCount > 0 ? round(($prevUserWonCount / $prevUserLeadsCount) * 100, 1) : 0;

            $commissionResult = $commissionByOwner->get($user->id) ?? ['amount' => 0.0, 'base' => 0.0, 'closed' => 0, ...$commissionService->milestoneFor(0, $endDate)];

            $rating = 'Cần cải thiện';
            $ratingBadge = 'bg-error/10 text-error border-error/30';
            if ($userRevenue >= 100000000 || $userRate >= 30) {
                $rating = 'Xuất sắc';
                $ratingBadge = 'bg-tertiary/10 text-tertiary border-tertiary/30';
            } elseif ($userRevenue >= 50000000 || $userRate >= 25) {
                $rating = 'Tốt';
                $ratingBadge = 'bg-secondary/10 text-secondary border-secondary/30';
            } elseif ($userRevenue >= 20000000 || $userRate >= 15) {
                $rating = 'Đạt yêu cầu';
                $ratingBadge = 'bg-warning/10 text-warning border-warning/30';
            }

            $avatarLetter = mb_strtoupper(mb_substr($user->name, 0, 1));

            $rateDelta = round($userRate - $prevUserRate, 1);

            $repsData[] = [
                'name' => $user->name,
                'role' => $user->roles->first()?->name === Roles::SALES_CONSULTANT ? 'Chuyên viên Tư vấn Tuyển sinh' : ($user->roles->first()?->name ?? 'Tư vấn viên'),
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
                'commission_base' => $commissionResult['base'],
                'commission_bonus' => 0,
                'closed_students' => $commissionResult['closed'],
                'tier_name' => $commissionResult['tier_name'],
            ];
        }

        // Đang lọc 1 chi nhánh: bỏ các dòng Sales không có số liệu nào ở chi nhánh đó.
        if ($branchId) {
            $repsData = array_values(array_filter($repsData, fn (array $rep) => $rep['leads'] > 0 || $rep['won'] > 0 || $rep['revenue'] > 0));
        }

        // Sắp xếp người có doanh số cao nhất lên đầu
        usort($repsData, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        if (in_array($request->input('export'), ['xlsx', 'csv'], true)) {
            return $this->exportReps($repsData, $startDate, $endDate, $request->input('export'));
        }

        return Inertia::render('Crm/Reports', [
            'preset' => $preset,
            'presetLabel' => $presetLabel,
            'startDate' => $startDate->format('Y-m-d H:i:s'),
            'endDate' => $endDate->format('Y-m-d H:i:s'),
            'branchId' => $branchId ? (string) $branchId : null,
            'branches' => Ui::options($branches, 'name'),
            'updatedAt' => now()->format('H:i d/m/Y'),
            'metricTotalLeads' => $metricTotalLeads,
            'metricWonDeals' => $metricWonDeals,
            'metricConversionRate' => $metricConversionRate,
            'metricLostDeals' => $metricLostDeals,
            'leadDeltaPercent' => $leadDeltaPercent,
            'leadDiff' => $leadDiff,
            'wonDeltaPercent' => $wonDeltaPercent,
            'wonDiff' => $wonDiff,
            'conversionDeltaPercent' => $conversionDeltaPercent,
            'lostDeltaPercent' => $lostDeltaPercent,
            'lostDiff' => $lostDiff,
            'funnelStages' => $funnelStages,
            'lostReasons' => $lostReasons->map(fn (CrmCustomer $lost) => [
                'id' => $lost->id,
                'name' => $lost->name,
                'at' => ($lost->lost_at ?? $lost->created_at)?->format('d/m/Y H:i'),
                'reason' => $lost->lost_reason,
                'user' => $lost->assignedUser?->name,
            ])->all(),
            'lostDealsParams' => array_filter(['from' => $startDate->toDateString(), 'to' => $endDate->toDateString(), 'branch_id' => $branchId]),
            'repsData' => $repsData,
        ]);
    }

    /**
     * Bảng hiệu suất theo người phụ trách, theo phạm vi CRM: Của tôi → dòng của mình; Chi nhánh → nhân sự thuộc
     * chi nhánh mình; Toàn hệ thống → tất cả.
     */
    /**
     * N ngày gần nhất tính cả hôm nay, và N ngày liền trước đó (hai kỳ không chồng ngày).
     *
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon}
     */
    protected function rollingDays(Carbon $now, int $days): array
    {
        $start = $now->copy()->subDays($days - 1)->startOfDay();

        return [$start, $now->copy()->endOfDay(), $start->copy()->subDays($days), $start->copy()->subSecond()];
    }

    protected function scopeReportReps(Collection $users, User $viewer): Collection
    {
        $level = DataScope::level($viewer, 'lead');
        if ($level === DataScope::ALL) {
            return $users;
        }
        if ($level === DataScope::BRANCH) {
            $branchIds = $viewer->branchIds();

            return $users->filter(fn (User $user) => in_array((int) $user->branch_id, $branchIds, true)
                || $user->branches->whereIn('id', $branchIds)->isNotEmpty())->values();
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
