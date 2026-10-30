<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Jobs\CalculatePayrollPeriod;
use App\Models\BackgroundTask;
use App\Models\ClassModel;
use App\Models\CommissionAdjustment;
use App\Models\CommissionItem;
use App\Models\CommissionTier;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherRate;
use App\Models\TeacherTimesheet;
use App\Models\TimesheetSyncLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Kpi\KpiSheetService;
use App\Services\Payroll\PayrollPeriodLifecycle;
use App\Services\PayrollFormulaService;
use App\Services\SalesCommissionService;
use App\Services\TeachingShifts\ScheduleConfirmation;
use App\Support\DataScope;
use App\Support\Money;
use App\Support\PayrollAdjustScope;
use App\Support\ReportPeriod;
use App\Support\Roles;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PayrollController extends Controller
{
    public function periods(Request $request)
    {
        // Truy cập theo quyền payroll.view (route middleware) — không chặn cứng theo vai trò (BA 26/09/2026).
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        // Người xem theo chi nhánh / của tôi: số người, giờ, tổng tiền chỉ tính trên phiếu lương trong phạm vi.
        $scoped = ! DataScope::isAll($request->user(), 'payroll');
        $periods = PayrollPeriod::withCount(['records' => fn ($q) => $scoped ? $this->scopeRecords($q) : $q])
            ->when($scoped, fn ($q) => $q
                ->withSum(['records as scoped_hours' => fn ($r) => $this->scopeRecords($r)], 'actual_hours')
                ->withSum(['records as scoped_amount' => fn ($r) => $this->scopeRecords($r)], 'net_salary'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhereHas('records.user', fn ($u) => $u->where('name', 'like', "%{$search}%"))))
            ->when(in_array($status, array_keys(PayrollPeriod::STATUS_LABELS), true), fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(12)
            ->withQueryString();
        $allPeriods = PayrollPeriod::latest()->get(['id', 'code', 'title']);

        return Inertia::render('Payroll/Periods', [
            'periods' => $periods->through(fn (PayrollPeriod $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'title' => $p->title,
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
                'staff' => $p->records_count > 0 || $scoped ? (int) $p->records_count : (int) $p->total_staff,
                // Giữ nguyên cách in số giờ của bản Blade: phạm vi hẹp in số thực, toàn hệ thống in cột decimal:2.
                'hours' => $scoped ? (string) (float) $p->scoped_hours : (string) $p->total_hours,
                'amount' => $scoped ? (float) $p->scoped_amount : (float) $p->total_amount,
                'status' => $p->status,
                'status_label' => $p->status_label,
            ]),
            'allPeriods' => Ui::options($allPeriods, fn (PayrollPeriod $p) => $p->title.' ('.$p->code.')'),
            'defaultMonth' => (int) date('n'),
            'defaultYear' => (int) date('Y'),
        ]);
    }

    public function storePeriod(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2025|max:2100',
        ]);

        $code = 'PR-'.$validated['year'].'-'.str_pad($validated['month'], 2, '0', STR_PAD_LEFT);
        $title = "Bảng lương Tháng {$validated['month']}/{$validated['year']}";

        if (PayrollPeriod::where('year', $validated['year'])->where('month', $validated['month'])->exists()) {
            throw ValidationException::withMessages([
                'month' => "Đã tồn tại bảng lương cho tháng {$validated['month']}/{$validated['year']}.",
            ]);
        }

        $period = PayrollPeriod::create([
            'code' => $code,
            'title' => $title,
            'month' => $validated['month'],
            'year' => $validated['year'],
            'start_date' => "{$validated['year']}-{$validated['month']}-01",
            'end_date' => date('Y-m-t', strtotime("{$validated['year']}-{$validated['month']}-01")),
            'status' => PayrollPeriod::STATUS_DRAFT,
            'total_staff' => 0,
            'total_hours' => 0,
            'total_amount' => 0,
        ]);

        $this->queueCalculation($period);

        return redirect()->route('payroll.periods.show', $period->id)
            ->with('status', "Đã khởi tạo bảng lương {$period->title}, đang tính từ dữ liệu chấm công & hoa hồng ở nền.");
    }

    /**
     * Danh sách bảng lương của một kỳ (mockup epic-7/danh-sach-bang-luong-theo-ky): chọn kỳ, tìm nhân sự,
     * lọc loại nhân sự, trạng thái KPI từng người + cảnh báo còn người chưa chốt KPI trước khi chốt bảng lương.
     */
    /**
     * Phiếu lương người xem được xem theo phạm vi "payroll.scope_*": Toàn hệ thống (mặc định người có payroll.view) →
     * mọi phiếu; Chi nhánh → phiếu của nhân sự thuộc chi nhánh mình; Của tôi → chỉ phiếu của mình.
     */
    private function scopeRecords($query, ?User $viewer = null)
    {
        $viewer ??= Auth::user();

        if (! DataScope::isAll($viewer, 'payroll')) {
            // Phiếu của chính người xem chỉ công khai từ lúc gửi GV xem, kể cả phạm vi chi nhánh.
            $query->where(fn ($q) => $q->where('user_id', '!=', $viewer->id)
                ->orWhereHas('period', fn ($p) => $p->whereIn('status', PayrollPeriod::LOCKED_STATUSES)));
        }

        return DataScope::apply(
            $query, $viewer, 'payroll',
            fn ($q) => $q->where('user_id', $viewer->id),
            fn ($q, array $branchIds) => $q->whereHas('user', fn ($u) => $u->whereIn('branch_id', $branchIds)
                ->orWhereHas('branches', fn ($b) => $b->whereIn('branches.id', $branchIds))),
            branchIncludesOwn: true,
        );
    }

    /** Nạp phiếu lương của kỳ trong phạm vi người xem. */
    private function loadScopedRecords(PayrollPeriod $period): PayrollPeriod
    {
        if (! DataScope::isAll(Auth::user(), 'payroll')) {
            $period->setRelation('records', $this->scopeRecords($period->records()->with('user'))->get());
        }

        return $period;
    }

    public function showPeriod(Request $request, $id)
    {
        $period = $this->loadScopedRecords(PayrollPeriod::with(['records.user'])->where('id', $id)->orWhere('code', $id)->firstOrFail());
        $search = trim((string) $request->query('search', ''));
        $type = in_array($request->query('type'), array_keys(PayrollRecord::SALARY_ROLE_LABELS), true) ? $request->query('type') : null;
        $kpi = in_array($request->query('kpi'), ['done', 'pending'], true) ? $request->query('kpi') : null;

        $filtered = $period->records
            ->when($search !== '', fn ($rows) => $rows->filter(fn (PayrollRecord $r) => str_contains(
                mb_strtolower(($r->user?->name ?? '').' '.($r->user?->email ?? '').' '.($r->user?->employee_code ?? '')),
                mb_strtolower($search)
            )))
            ->when($type, fn ($rows) => $rows->where('salary_role', $type))
            ->when($kpi, fn ($rows) => $rows->filter(fn (PayrollRecord $r) => $r->kpi_state[0] === $kpi))
            ->sortBy(fn (PayrollRecord $r) => $r->user?->name)
            ->values();

        $perPage = $request->perPage(20);
        $page = max(1, $request->integer('page', 1));
        $records = new \Illuminate\Pagination\LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(), $filtered->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $kpiPending = $period->records->filter(fn (PayrollRecord $r) => $r->kpi_state[0] === 'pending')->values();
        $negativeRecords = $period->records->filter(fn (PayrollRecord $r) => $r->netShortfall() > 0);
        $adjustableStaffIds = PayrollAdjustScope::adjustableStaffIds($request->user(), $negativeRecords->pluck('user_id'));
        // Chỉ trả biên bản của các phiếu người xem được thấy; quyền xử lý vẫn kiểm tra ở route.
        $negativePenalties = Penalty::withoutViewerScope()->whereIn('payroll_record_id', $negativeRecords->modelKeys())
            ->where('status', 'fined')->get(['id', 'code', 'amount', 'status', 'violation_date', 'payroll_record_id'])->groupBy('payroll_record_id');
        // Bước tiếp của kỳ phải xét mọi phiếu, kể cả phiếu ngoài phạm vi người xem hoặc bộ lọc hiện tại.
        $negativeCount = $period->records()->get()->filter(fn (PayrollRecord $r) => $r->netShortfall() > 0)->count();
        $allPeriods = PayrollPeriod::orderByDesc('year')->orderByDesc('month')->get(['id', 'code', 'title', 'month', 'year']);

        // Bước tiếp theo của kỳ và ai đang giữ: chỉ Admin có quyền chốt / đánh dấu đã trả nên Kế toán, Quản lý cần biết kỳ đang chờ ai.
        $user = $request->user();
        [$nextStep, $nextColor] = match (true) {
            $period->status === PayrollPeriod::STATUS_PAID => ['Hoàn tất: đã trả lương', 'neutral'],
            $period->status === PayrollPeriod::STATUS_PUBLISHED => [$user->can('payroll.mark_paid') ? 'Bước tiếp: bạn đánh dấu đã trả' : 'Chờ Admin đánh dấu đã trả', 'info'],
            $period->status === PayrollPeriod::STATUS_CLOSED && $period->hasChangesSinceCalculation() => [$user->can('payroll.approve') ? 'Bước tiếp: Mở lại để tính lại, Đồng bộ & Tính lại, rồi chốt lại (dữ liệu đã đổi)' : 'Chờ Admin mở lại và tính lại bảng lương', 'warning'],
            $period->status === PayrollPeriod::STATUS_CLOSED => [$user->can('payroll.approve') ? 'Bước tiếp: bạn bấm Gửi GV xem' : 'Chờ Admin gửi GV xem', 'info'],
            $kpiPending->isNotEmpty() => ['Còn '.$kpiPending->count().' nhân sự chưa chốt KPI', 'error'],
            $period->hasChangesSinceCalculation() => [$user->can('payroll.calculate') ? 'Bước tiếp: bạn bấm Đồng bộ & Tính lại (dữ liệu đã đổi)' : 'Chờ Kế toán Đồng bộ & Tính lại', 'warning'],
            $period->status === PayrollPeriod::STATUS_DRAFT && $negativeCount > 0 => [$user->can('payroll.edit')
                ? 'Còn '.$negativeCount.' nhân sự thực lĩnh âm — chuyển phạt sang kỳ sau hoặc điều chỉnh phiếu trước khi chốt'
                : 'Chờ Kế toán xử lý '.$negativeCount.' nhân sự thực lĩnh âm', 'error'],
            default => [$user->can('payroll.approve') ? 'Bước tiếp: bạn chốt bảng lương' : 'Chờ Admin chốt bảng lương', 'warning'],
        };
        // Đang xem một nhóm (VD tab GV Part-time / Trợ giảng): thẻ tổng tính riêng cho nhóm đó.
        $all = $type ? $period->records->where('salary_role', $type)->values() : $period->records;

        return Inertia::render('Payroll/Show', [
            'period' => [
                ...$this->periodData($period),
                'next_step' => $nextStep,
                'next_color' => $nextColor,
                'calendar' => $period->calendar(),
            ],
            'stats' => [
                'total_amount' => (float) (DataScope::isAll($user, 'payroll') && ! $type ? $period->total_amount : $all->sum('net_salary')),
                'records' => $all->count(),
                'parttime_sessions' => (int) $all->where('employee_type', 'parttime')->sum('teaching_sessions'),
                'hours' => (string) $all->sum('actual_hours'),
                'kpi_bonus' => (float) $all->sum('kpi_bonus'),
                'deductions' => (float) $all->sum('total_deductions'),
            ],
            'kpiPending' => [
                'count' => $kpiPending->count(),
                'names' => $kpiPending->take(8)->map(fn ($r) => $r->user?->name)->filter()->implode(', '),
            ],
            // Trợ giảng chưa có đơn giá giờ (TK-0010): lương giờ dạy 0đ → cần nhập lại đơn giá ở Đơn giá giáo viên.
            'assistantRateMissing' => $period->records->filter(fn (PayrollRecord $r) => $r->assistantRateMissing())
                ->map(fn (PayrollRecord $r) => $r->user?->name)->filter()->values()->all(),
            'negativeNet' => $negativeRecords
                ->sortBy(fn (PayrollRecord $r) => $r->user?->name)
                ->map(fn (PayrollRecord $r) => [
                    'id' => $r->id,
                    'name' => $r->user?->name ?? 'Chưa cập nhật',
                    'shortfall' => $r->netShortfall(),
                    'raw_net' => $r->rawNetSalary(),
                    'penalties' => $negativePenalties->get($r->id, collect())->map(fn (Penalty $pen) => [
                        'id' => $pen->id,
                        'code' => $pen->code,
                        'amount' => (float) $pen->amount,
                        'violation_date' => $pen->violation_date?->toDateString(),
                        'can_defer' => $pen->status === 'fined' && $period->status === PayrollPeriod::STATUS_DRAFT && $user->can('payroll.edit')
                            && in_array((int) $r->user_id, $adjustableStaffIds, true),
                    ])->values()->all(),
                ])->values()->all(),
            'records' => $records->through(fn (PayrollRecord $r) => $this->recordRow($r)),
            'periodOptions' => Ui::options($allPeriods, fn (PayrollPeriod $p) => 'Tháng '.str_pad($p->month, 2, '0', STR_PAD_LEFT).'/'.$p->year.' ('.$p->code.')'),
            'typeOptions' => Ui::options(PayrollRecord::SALARY_ROLE_LABELS),
            'filtered' => $search !== '' || $type || $kpi,
            'missingStaff' => DataScope::isAll($user, 'payroll') && ! $period->isFrozen() ? $this->staffWithoutPayslip($period) : null,
            // Lần tính lương gần nhất của kỳ (chạy nền): người có quyền tính lương thấy trạng thái, trang tự tải lại khi đang tính.
            'backgroundTasks' => $user->can('payroll.calculate')
                ? BackgroundTask::recentFor(BackgroundTask::PAYROLL_CALCULATE, limit: 1, where: fn ($q) => $q->where('payload->period_id', $period->id))
                : [],
        ]);
    }

    /**
     * Nhân sự đang làm việc mà kỳ chưa có phiếu lương: lần tính bỏ qua người không có khoản nào phát sinh (lương cơ bản 0,
     * không buổi dạy hợp lệ, không hoa hồng) — Admin cần thấy ai đang thiếu và vì sao, thay vì tưởng bảng lương thiếu người.
     *
     * @return array{count: int, items: list<array{id: int, name: string, email: ?string, reason: string}>}
     */
    private function staffWithoutPayslip(PayrollPeriod $period): array
    {
        $formula = app(PayrollFormulaService::class);
        $missing = User::with('roles')->where('is_active', true)->staffAccounts()
            ->whereDoesntHave('roles', fn ($r) => $r->where('name', Roles::ADMIN))
            ->whereNotIn('id', $period->records()->select('user_id'))
            ->where('created_at', '<=', $period->end_date->copy()->endOfDay())
            // Hợp đồng bắt đầu sau kỳ: kỳ này chưa phải trả lương
            ->where(fn ($q) => $q->whereNull('contract_start_date')->orWhereDate('contract_start_date', '<=', $period->end_date->toDateString()))
            ->orderBy('name')
            ->get()
            ->map(fn (User $staff) => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'reason' => match (true) {
                    $formula->profile($staff)['employee_type'] === PayrollRecord::TYPE_PARTTIME => 'Part-time: chưa có buổi dạy hợp lệ trong kỳ',
                    (float) $staff->base_salary <= 0 => 'Chưa nhập lương cơ bản',
                    default => 'Đã có lương cơ bản: bấm Đồng bộ & Tính lại',
                },
            ]);

        return ['count' => $missing->count(), 'items' => $missing->take(50)->values()->all()];
    }

    /**
     * Xuất bảng lương của kỳ ra Excel (.xlsx) hoặc CSV; lọc theo khối (department) nếu có.
     */
    public function exportPeriod(Request $request, $id)
    {
        $period = PayrollPeriod::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $department = $request->query('department');
        $departments = PayrollFormulaService::DEPARTMENT_LABELS;
        // Lọc theo vai trò lương (tab GV Part-time / Trợ giảng của bảng lương kỳ).
        $type = in_array($request->query('type'), array_keys(PayrollRecord::SALARY_ROLE_LABELS), true) ? $request->query('type') : null;

        $records = $this->scopeRecords($period->records()->with('user'))
            ->when(is_string($department) && $department !== '', fn ($q) => $q->where('department', $department))
            ->when($type, fn ($q) => $q->where('salary_role', $type))
            ->orderBy('department')->orderBy('id')
            ->get();

        $kpiSources = [PayrollRecord::KPI_RETENTION => 'Giữ học sinh', PayrollRecord::KPI_ACADEMIC => 'KPI Học vụ (tự động)', PayrollRecord::KPI_MANUAL => 'Nhập tự do', PayrollRecord::KPI_SELF => 'Admin: chỉ xem % KPI'];
        $lineText = fn (PayrollRecord $r, string $kind) => collect($r->manualLines($kind))
            ->map(fn ($l) => $l['label'].': '.Money::format($l['amount'], ''))->implode('; ');

        // Đủ mọi dòng của phiếu lương Q3 (cùng căn cứ với màn phiếu lương) để Kế toán đối chiếu Excel đang dùng.
        $rows = $records->map(fn (PayrollRecord $r) => [
            $r->user?->name ?? 'Chưa cập nhật',
            $r->user?->employee_code,
            $r->user?->email,
            $departments[$r->department] ?? $r->department,
            $r->employee_type_label,
            $r->salary_role_label,
            (float) $r->base_salary,
            $r->hasWorkdays() ? (int) data_get($r->calculation_details, 'workdays.standard_days', 0) : '',
            $r->hasWorkdays() ? (float) data_get($r->calculation_details, 'workdays.paid_days', 0) : '',
            $r->hasWorkdays() ? (int) data_get($r->calculation_details, 'workdays.half_days', 0) : '',
            $r->base_salary_earned,
            (float) $r->contract_allowance,
            (int) $r->teaching_sessions,
            (float) $r->actual_hours,
            (float) $r->teaching_salary,
            $kpiSources[$r->kpi_source] ?? '',
            $r->kpi_state[1],
            $r->kpi_source === PayrollRecord::KPI_RETENTION || $r->teaching_concurrent ? (int) $r->retention_students.'/'.(int) $r->retention_base_students : '',
            $r->retention_tier !== null ? (float) $r->retention_tier : '',
            $r->kpi_score !== null ? (float) $r->kpi_score : '',
            (float) $r->kpi_bonus,
            (float) $r->teaching_kpi_bonus,
            (int) $r->foreign_teacher_sessions_count,
            (float) $r->foreign_session_pay,
            (int) $r->commission_closed_count,
            $r->commission_percent !== null ? (float) $r->commission_percent : '',
            (float) $r->commission_base,
            (float) $r->commission_bonus,
            (float) $r->commission_deferred,
            (float) $r->renew_bonus,
            $lineText($r, 'earning'),
            (float) $r->allowance + (float) $r->other_bonus,
            (float) $r->gross_income,
            $r->insuranceSalaryBase() ?? '',
            (float) $r->insurance_deduction,
            (float) $r->union_deduction,
            (float) $r->tax_deduction,
            (int) data_get($r->calculation_details, 'daily_late.minutes', 0),
            (float) $r->late_deduction,
            (int) data_get($r->calculation_details, 'missing_punch.count', 0),
            (float) $r->missing_punch_deduction,
            (float) $r->penalty_deduction,
            (float) $r->commission_clawback,
            $lineText($r, 'deduction'),
            (float) $r->other_deduction + (float) $r->foreign_teacher_deduction,
            (float) $r->total_deductions,
            (float) $r->net_salary,
            (string) $r->adjustment_notes,
        ])->all();

        return \App\Exports\ArrayExport::download(
            'bang-luong-'.\Illuminate\Support\Str::slug($period->code ?: $period->id).($department ? '-'.$department : '').($type ? '-'.$type : ''),
            [
                'Nhân sự', 'Mã NV', 'Email', 'Khối', 'Loại', 'Vai trò lương', 'Lương cơ bản (hợp đồng)', 'Công chuẩn', 'Công hưởng lương', 'Ngày quên chấm ra (0,5 công)',
                'Lương cơ bản theo công', 'Phụ cấp hợp đồng theo công', 'Số buổi', 'Số giờ', 'Lương buổi / giờ dạy',
                'Nguồn KPI', 'Trạng thái KPI', 'HS giữ được / đầu kỳ', 'Bậc KPI giữ HS (đ/HS)', 'Điểm KPI Học vụ (%)', 'KPI', 'KPI kiêm nhiệm giảng dạy',
                'Số buổi có GVNN', 'Buổi có GVNN (chờ BA)', 'Số HS chốt (hoa hồng)', '% hoa hồng', 'Căn cứ thực thu', 'Hoa hồng', 'Hoa hồng hoãn',
                'Thưởng tái tục', 'Chi tiết cộng tự do', 'Phụ cấp / cộng khác', 'Tổng thu nhập',
                'Mức lương đóng BHXH', 'BHXH', 'Công đoàn', 'Thuế TNCN', 'Đi muộn (phút)', 'Trừ đi muộn', 'Quên chấm công (lần)', 'Trừ quên chấm công', 'Phạt', 'Thu hồi hoa hồng', 'Chi tiết trừ tự do', 'Khấu trừ khác', 'Tổng khấu trừ', 'Thực lĩnh', 'Ghi chú',
            ],
            $rows,
            $request->query('format', 'xlsx')
        );
    }

    public function approvePeriod($id)
    {
        return $this->transitionPeriod($id, PayrollPeriod::STATUS_CLOSED, 'Đã chốt bảng lương');
    }

    public function reopenPeriod($id)
    {
        return $this->transitionPeriod($id, PayrollPeriod::STATUS_DRAFT, 'Đã mở lại bảng lương để tính lại');
    }

    public function publishPeriod($id)
    {
        return $this->transitionPeriod($id, PayrollPeriod::STATUS_PUBLISHED, 'Đã gửi phiếu lương cho nhân sự');
    }

    public function markPaid($id)
    {
        return $this->transitionPeriod($id, PayrollPeriod::STATUS_PAID, 'Đã ghi nhận chi trả bảng lương');
    }

    private function transitionPeriod($id, string $toStatus, string $message): RedirectResponse
    {
        $period = PayrollPeriod::where('id', $id)->orWhere('code', $id)->firstOrFail();
        try {
            $period = app(PayrollPeriodLifecycle::class)->transition($period, $toStatus);
        } catch (ValidationException $exception) {
            return redirect()->back()->withErrors($exception->errors())->with('error', $exception->errors()['period'][0]);
        }
        $redirect = redirect()->back()->with('status', "{$message} {$period->title}!");
        if ($toStatus === PayrollPeriod::STATUS_PAID && ! $period->isInPayWindow()) {
            [$from, $to] = $period->payWindow();
            $redirect->with('warning', 'Lưu ý: lương thường trả từ ngày '.$from->format('d/m').' đến '.$to->format('d/m').' — hôm nay nằm ngoài khung trả lương.');
        }

        return $redirect;
    }

    public function calculatePeriod($id)
    {
        $period = PayrollPeriod::where('id', $id)->orWhere('code', $id)->firstOrFail();
        abort_if($period->isFrozen(), 422, $period->recalculationLockedMessage());

        if (! $this->queueCalculation($period)) {
            return redirect()->back()->with('error', 'Kỳ lương đang được tính — đợi lần tính hiện tại xong rồi bấm lại.');
        }

        return redirect()->back()->with('status', "Đang đồng bộ và tính lại bảng lương {$period->title} ở nền. Trang tự cập nhật khi xong.");
    }

    /** Đưa việc tính lương kỳ vào queue (job CalculatePayrollPeriod); false khi kỳ này đang có lần tính chờ / đang chạy. */
    private function queueCalculation(PayrollPeriod $period): bool
    {
        $samePeriod = fn ($q) => $q->where('payload->period_id', $period->id);
        if (BackgroundTask::pendingExists(BackgroundTask::PAYROLL_CALCULATE, $samePeriod)) {
            return false;
        }
        $task = BackgroundTask::query()->create([
            'type' => BackgroundTask::PAYROLL_CALCULATE,
            'user_id' => Auth::id(),
            'status' => BackgroundTask::QUEUED,
            'title' => "Tính lương {$period->title}",
            'payload' => ['period_id' => $period->id],
        ]);
        CalculatePayrollPeriod::dispatch($task);

        return true;
    }

    /**
     * Dòng "Buổi có GVNN" (Q3 Part-time — chờ BA chốt cách tính): Kế toán nhập tay số tiền cộng cho GV,
     * hệ thống chỉ gợi ý số buổi có GVNN cùng lớp. Thay cho quy tắc cũ "trừ 50.000đ/buổi có GVNN".
     */
    public function updateRecord(Request $request, $id)
    {
        $record = $this->scopeRecords(PayrollRecord::with('period'))->findOrFail($id);
        abort_if($record->isLocked(), 422, 'Không thể sửa kỳ lương đã khóa.');

        $validated = $request->validate([
            'foreign_session_pay' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($record, $validated, $request) {
            $period = PayrollPeriod::whereKey($record->payroll_period_id)->lockForUpdate()->firstOrFail();
            abort_if($period->isFrozen(), 422, 'Không thể sửa phiếu lương của kỳ đã chốt / gửi GV xem / trả.');
            $record->refresh()->setRelation('period', $period);

            $before = $record->only(['foreign_session_pay', 'net_salary']);
            $record->foreign_session_pay = $validated['foreign_session_pay'];
            // Ô ghi chú luôn hiển thị giá trị hiện tại → để trống nghĩa là xóa ghi chú.
            $record->adjustment_notes = $validated['notes'] ?? null;
            $record->applyManualInputs();
            $record->save();
            $record->period->refreshTotals();

            activity('payroll_record')->causedBy($request->user())->performedOn($record)
                ->withProperties(['before' => $before, 'after' => $record->only(array_keys($before))])
                ->log('Nhập lương buổi có GVNN cho '.$record->user?->name.' — '.$record->period->title);
        });

        return redirect()->back()->with('status', 'Đã lưu lương buổi có GVNN và tính lại thực lĩnh.');
    }

    /**
     * Phiếu lương một người: toàn bộ khoản cộng / khoản trừ của một bản ghi lương theo loại nhân sự,
     * kèm căn cứ (buổi dạy, HS giữ được, KPI, hoa hồng trả / hoãn, thưởng tái tục, phạt, thu hồi).
     */
    public function showRecord(Request $request, int $id): InertiaResponse
    {
        $record = $this->scopeRecords(PayrollRecord::with(['user.roles', 'period']))->findOrFail($id);
        $period = $record->period;

        $timesheets = TeacherTimesheet::with('classModel')
            ->where('user_id', $record->user_id)
            ->whereBetween('teaching_date', [$period->start_date, $period->end_date])
            ->where('status', 'valid')
            ->orderBy('teaching_date')
            ->get();
        // Phiếu lương đã qua kiểm tra phạm vi bảng lương: hiện đủ các biên bản đã trừ trên phiếu.
        $penalties = Penalty::withoutViewerScope()->where('payroll_record_id', $record->id)->orderBy('due_date')->get();
        $canDefer = $period->status === PayrollPeriod::STATUS_DRAFT && $request->user()->can('payroll.edit')
            && PayrollAdjustScope::canAdjust($request->user(), $record->user_id);
        $clawbacks = CommissionAdjustment::with('student')->where('payroll_record_id', $record->id)->get();
        $paidCommission = CommissionItem::with(['student', 'receipt'])->where('payroll_record_id', $record->id)->orderBy('id')->get();
        $deferredCommission = CommissionItem::with(['student', 'receipt'])
            ->whereIn('id', collect(data_get($record->calculation_details, 'commission.deferred', []))->pluck('id')->all())
            ->orderBy('id')->get();
        // Phiếu trước Q3: vẫn liệt kê phiếu thu làm căn cứ như trước.
        $commissionReceipts = $record->usesQ3Formula()
            ? collect()
            : app(SalesCommissionService::class)->commissionableReceipts($period->start_date, $period->end_date, $record->user_id)->load('student');
        $lostStudents = app(PayrollFormulaService::class)->studentNames((array) data_get($record->calculation_details, 'retention.lost_ids', []));
        $settings = PayrollPeriod::payrollSettings();

        // Mẫu phiếu theo loại nhân sự (4 mockup chi tiết lương): GV Part-time, GV Full-time, Học vụ, Học thuật; Sale / khác dùng mẫu Full-time.
        $variant = self::payslipVariant($record);
        $currentRate = TeacherHourlyRate::effectiveFor((int) $record->user_id, $period->end_date);
        // Mốc hoa hồng hiệu lực tại ngày cuối kỳ ("Chi tiết mốc áp dụng").
        $commissionTiers = ($record->salary_role === 'sales' || (float) $record->commission_bonus > 0 || (float) $record->commission_deferred > 0)
            ? CommissionTier::byStudents()->effectiveAt($period->end_date)->orderBy('min_students')->get()
            : collect();

        $canEdit = ! $period->isFrozen() && $request->user()->can('payroll.edit');
        $lines = $record->manualLines();
        if ($canEdit && $record->isPartTime() && ! $record->isAssistant() && empty($lines)) {
            // Gợi ý các khoản phụ cấp của mockup GV Part-time (khoản 0đ không được lưu).
            $lines = [
                ['kind' => 'earning', 'label' => 'Hỗ trợ thỏa thuận', 'amount' => ''],
                ['kind' => 'earning', 'label' => 'Phụ cấp gửi xe', 'amount' => ''],
                ['kind' => 'earning', 'label' => 'Thưởng khác', 'amount' => ''],
            ];
        } elseif ($canEdit && $variant['key'] === 'academic_lead' && ! $record->teaching_concurrent && empty($lines)) {
            $lines = [
                ['kind' => 'earning', 'label' => 'Lương giảng dạy', 'amount' => ''],
                ['kind' => 'earning', 'label' => 'Hỗ trợ', 'amount' => ''],
            ];
        }
        $kpiGroups = collect(data_get($record->calculation_details, 'kpi.items', []))->groupBy('group');

        return Inertia::render('Payroll/Record', [
            'record' => [
                ...$this->recordRow($record),
                'uses_q3' => $record->usesQ3Formula(),
                'is_full_time' => $record->isFullTime(),
                'salary_role' => $record->salary_role,
                'user_id' => $record->user_id,
                'retention_base_students' => (int) $record->retention_base_students,
                'retention_lost' => (int) data_get($record->calculation_details, 'retention.lost', 0),
                'commission_closed_count' => (int) $record->commission_closed_count,
                'commission_percent' => $record->commission_percent !== null ? (float) $record->commission_percent : null,
                'gross_income' => (float) $record->gross_income,
                'total_deductions' => (float) $record->total_deductions,
                'kpi_fund' => (float) data_get($record->calculation_details, 'kpi.fund', $settings['academic_kpi_fund']),
                'rate_insurance' => (float) data_get($record->calculation_details, 'rates.insurance', $settings['insurance_rate_percent']),
                'rate_union' => (float) data_get($record->calculation_details, 'rates.union', $settings['union_rate_percent']),
                // Mức lương đóng BHXH lưu lúc tính (null = phiếu cũ, BHXH trên lương cơ bản).
                'insurance_base' => $record->insuranceSalaryBase(),
                'is_assistant' => $record->isAssistant(),
                'base_salary_earned' => $record->base_salary_earned,
                'late_deduction' => (float) $record->late_deduction,
                'late_label' => $record->lateLabel(),
                'insured' => (bool) data_get($record->calculation_details, 'rates.insured', true),
                'contract_allowance' => (float) $record->contract_allowance,
                'missing_punch_deduction' => (float) $record->missing_punch_deduction,
                // Trợ giảng chưa có đơn giá giờ (đơn giá cũ theo buổi không quy đổi) → cảnh báo nhập lại.
                'assistant_rate_missing' => $record->assistantRateMissing(),
                'notes' => $record->notes,
                'manual_lines' => $record->manualLines(),
                'earning_lines' => $record->earningLines(),
                'deduction_lines' => $record->deductionLines(),
            ],
            'period' => $this->periodData($period),
            'variant' => $variant,
            'canEdit' => $canEdit,
            'lines' => array_values($lines),
            'settings' => [
                'insurance_salary_base' => (float) $settings['insurance_salary_base'],
                'insurance_rate_percent' => (float) $settings['insurance_rate_percent'],
                'union_rate_percent' => (float) $settings['union_rate_percent'],
                'retention_tiers' => array_values(array_map('floatval', $settings['retention_tiers'])),
            ],
            'currentRate' => $currentRate ? [
                'rate' => (float) $currentRate->hourly_rate,
                'unit_label' => $currentRate->unit_label,
                'effective_from' => $currentRate->effective_from?->toDateString(),
            ] : null,
            'lostStudents' => $lostStudents->pluck('name')->implode(', '),
            // Học thuật kiêm nhiệm giảng dạy: bảng lương đứng lớp theo lớp (bảng mẫu "Lớp theo tỷ lệ 40/60").
            'teachingShare' => $record->teaching_concurrent ? [
                'classes' => array_values((array) data_get($record->calculation_details, 'teaching_share.classes', [])),
                'default_percent' => (float) data_get($record->calculation_details, 'teaching_share.default_percent', $settings['teaching_share_percent']),
                'skipped' => (int) data_get($record->calculation_details, 'teaching_share.skipped', 0),
                'rate_percent' => $currentRate?->isTuitionShare() ? (float) $currentRate->hourly_rate : null,
                'kpi_sheet' => $this->teachingKpiSheet($record, $period),
            ] : null,
            'timesheets' => $timesheets->map(function (TeacherTimesheet $ts) use ($record) {
                // Trợ giảng: số giờ × đơn giá giờ (TK-0010), cùng cách tính với bảng lương.
                $pay = $record->isAssistant() && $record->isPartTime() ? $ts->assistantPayOutcome() : $ts->sessionPay($record->user);

                return [
                    'id' => $ts->id,
                    'date' => $ts->teaching_date?->toDateString(),
                    'scheduled_time' => $ts->scheduled_time,
                    'checkin_time' => $ts->checkin_time,
                    'checkout_time' => $ts->checkout_time,
                    'class_code' => $ts->classModel?->code ?? $ts->classModel?->name,
                    'class_name' => $ts->classModel?->name,
                    'type' => $ts->type,
                    'type_label' => $ts->type_label,
                    'source' => $ts->source,
                    'hours' => (float) $ts->hours,
                    'rate' => $pay['rate'],
                    'unit' => $pay['unit'],
                    'amount' => $pay['amount'],
                ];
            })->values(),
            'kpiGroups' => $kpiGroups->map(fn ($items, $group) => [
                'group' => $group,
                'weight' => (float) $items->sum('weight'),
                'amount' => (float) $items->sum('amount'),
                'items' => $items->map(fn ($item) => [
                    'code' => $item['code'] ?? null,
                    'name' => $item['name'] ?? null,
                    'score' => (float) ($item['score'] ?? 0),
                    'weight' => (float) ($item['weight'] ?? 0),
                    'amount' => (float) ($item['amount'] ?? 0),
                ])->values(),
            ])->values(),
            'renewalClasses' => array_values(data_get($record->calculation_details, 'renewal.classes', [])),
            // Khoản trừ GV đi muộn / về sớm (calculation_details.late) để Kế toán thấy vì sao tiền công ca bị giảm.
            'lateLines' => array_values((array) data_get($record->calculation_details, 'late.lines', [])),
            'lateInfo' => ['threshold' => (int) data_get($record->calculation_details, 'late.threshold', 15), 'per_minute' => (float) data_get($record->calculation_details, 'late.per_minute', 5000), 'total' => (float) data_get($record->calculation_details, 'late.total_deduction', 0)],
            // Chấm công hằng ngày (điện thoại) trong kỳ — căn cứ đối soát.
            'dailyAttendance' => data_get($record->calculation_details, 'attendance'),
            // Ngày công tính lương cơ bản Full-time (công chuẩn / công hưởng lương) và đi muộn trừ theo phút.
            'workdays' => $record->hasWorkdays() ? data_get($record->calculation_details, 'workdays') : null,
            'dailyLate' => data_get($record->calculation_details, 'daily_late'),
            'missingPunch' => data_get($record->calculation_details, 'missing_punch'),
            'commissionTiers' => $commissionTiers->map(fn (CommissionTier $tier) => [
                'id' => $tier->id,
                'name' => $tier->tier_name,
                'range' => $tier->student_range_label,
                'percent' => (float) $tier->new_sale_percent,
                // Mốc hiện tại = mốc chứa HS chốt gần nhất trong kỳ (từng khoản bên dưới ghi rõ HS thứ mấy, % nào).
                'applied' => $record->commission_closed_count > 0 && (int) $tier->min_students <= (int) $record->commission_closed_count
                    && ($tier->max_students === null || (int) $tier->max_students >= (int) $record->commission_closed_count),
            ])->values(),
            'penalties' => $penalties->map(fn (Penalty $pen) => [
                'id' => $pen->id,
                'code' => $pen->code,
                'violation_type' => $pen->violation_type,
                'violation_date' => $pen->violation_date?->toDateString(),
                'due_date' => $pen->due_date?->toDateString(),
                'amount' => (float) $pen->amount,
                'can_defer' => $pen->status === 'fined' && $canDefer,
            ])->values(),
            'clawbacks' => $clawbacks->map(fn (CommissionAdjustment $adj) => ['id' => $adj->id, 'reason' => $adj->reason, 'amount' => (float) $adj->amount])->values(),
            'paidCommission' => $paidCommission->map(fn (CommissionItem $item) => $this->commissionItemRow($item))->values(),
            'deferredCommission' => $deferredCommission->map(fn (CommissionItem $item) => $this->commissionItemRow($item))->values(),
            'commissionReceipts' => $commissionReceipts->map(fn ($receipt) => [
                'id' => $receipt->id,
                'approved_at' => $receipt->approved_at?->toIso8601String(),
                'student' => $receipt->student?->name,
                'amount' => (float) $receipt->amount,
            ])->values(),
            // Bản in "In phiếu lương / Xuất PDF" vẫn là Blade (ẩn trên màn hình, chỉ hiện khi in).
            'printHtml' => view('payroll.partials.payslip-print', ['record' => $record, 'period' => $period, 'variant' => $variant])->render(),
        ]);
    }

    /** Một khoản hoa hồng (trả / hoãn) trên phiếu lương. */
    private function commissionItemRow(CommissionItem $item): array
    {
        return [
            'id' => $item->id,
            'student' => $item->student?->name,
            'receipt_number' => $item->receipt?->receipt_number,
            'earned' => $item->earned_period_start?->format('m/Y'),
            'closing_rank' => $item->closing_rank,
            'closed_month' => $item->closed_at?->format('m/Y'),
            'deferred_reason' => $item->deferred_reason,
            'base_amount' => (float) $item->base_amount,
            'percent' => (float) $item->percent,
            'amount' => (float) $item->amount,
        ];
    }

    /** @return array{key: string, title: string, type: string} */
    public static function payslipVariant(PayrollRecord $record): array
    {
        return match (true) {
            ! $record->usesQ3Formula() => ['key' => 'legacy', 'title' => 'Chi tiết bảng lương', 'type' => 'Phiếu trước Q3'],
            $record->isAssistant() && $record->isPartTime() => ['key' => 'parttime', 'title' => 'Chi tiết bảng lương Trợ giảng', 'type' => 'Trợ giảng (Part-time)'],
            $record->isAssistant() => ['key' => 'fulltime', 'title' => 'Chi tiết bảng lương Trợ giảng', 'type' => 'Trợ giảng (Full-time)'],
            $record->isPartTime() => ['key' => 'parttime', 'title' => 'Chi tiết bảng lương GV Part-time', 'type' => 'Giáo viên (Part-time)'],
            $record->salary_role === Roles::ACADEMIC_STAFF => ['key' => 'academic_staff', 'title' => 'Chi tiết bảng lương Học vụ', 'type' => 'Học vụ (Full-time)'],
            $record->salary_role === Roles::ACADEMIC_LEAD => ['key' => 'academic_lead', 'title' => 'Chi tiết bảng lương Học thuật', 'type' => 'Học thuật (Full-time)'],
            $record->salary_role === Roles::TEACHER_FULLTIME => ['key' => 'fulltime', 'title' => 'Chi tiết bảng lương GV Full-time', 'type' => 'Giáo viên (Full-time)'],
            default => ['key' => 'fulltime', 'title' => 'Chi tiết bảng lương '.$record->salary_role_label, 'type' => $record->salary_role_label.' (Full-time)'],
        };
    }

    /**
     * Kế toán / Admin nhập các khoản tay trên phiếu lương khi kỳ đang tính — được giữ khi "Đồng bộ & Tính lại":
     * bậc KPI giữ HS (Part-time), KPI tự do (GV Full-time, Học thuật, Sale, khác), buổi có GVNN (Part-time, chờ BA),
     * thuế TNCN (Full-time), các dòng phụ cấp / thưởng / khấu trừ tự do có tên, ghi chú.
     * Hoa hồng và KPI Học vụ tự tính, không sửa tay.
     */
    public function adjustRecord(Request $request, int $id)
    {
        $record = $this->scopeRecords(PayrollRecord::with('period'))->findOrFail($id);
        abort_if($record->isLocked(), 422, 'Không thể sửa phiếu lương của kỳ đã chốt / gửi GV xem / trả.');

        $tiers = PayrollPeriod::payrollSettings()['retention_tiers'];
        $validated = $request->validate([
            'retention_tier' => ['nullable', 'numeric', function ($attribute, $value, $fail) use ($tiers) {
                if ($value !== null && $value !== '' && ! in_array((float) $value, $tiers, true)) {
                    $fail('Bậc KPI giữ học sinh phải là một trong: '.implode(' / ', array_map(fn ($t) => Money::format($t), $tiers)).'.');
                }
            }],
            'kpi_manual_amount' => ['nullable', 'numeric', 'min:0'],
            'foreign_session_pay' => ['nullable', 'numeric', 'min:0'],
            'tax_deduction' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array', 'max:30'],
            'lines.*.kind' => ['required_with:lines.*.label', 'nullable', 'in:earning,deduction'],
            'lines.*.label' => ['nullable', 'string', 'max:150'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'adjustment_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $lines = collect($validated['lines'] ?? [])
            ->filter(fn ($line) => filled($line['label'] ?? null) && (float) ($line['amount'] ?? 0) > 0)
            ->map(fn ($line) => ['kind' => $line['kind'] ?? 'earning', 'label' => trim($line['label']), 'amount' => round((float) $line['amount'], 2)])
            ->values()->all();
        $unnamed = collect($validated['lines'] ?? [])->contains(fn ($line) => blank($line['label'] ?? null) && (float) ($line['amount'] ?? 0) > 0);
        if ($unnamed) {
            throw ValidationException::withMessages(['lines' => 'Mỗi khoản cộng / trừ tự do cần có tên (VD: Hỗ trợ thỏa thuận, Gửi xe, Thưởng khác).']);
        }

        DB::transaction(function () use ($record, $validated, $lines, $request) {
            $period = PayrollPeriod::whereKey($record->payroll_period_id)->lockForUpdate()->firstOrFail();
            abort_if($period->isFrozen(), 422, 'Không thể sửa phiếu lương của kỳ đã chốt / gửi GV xem / trả.');
            $record->refresh()->setRelation('period', $period);

            $before = $record->only(['retention_tier', 'kpi_manual_amount', 'foreign_session_pay', 'tax_deduction', 'manual_lines', 'adjustment_notes', 'net_salary']);

            if ($record->usesQ3Formula()) {
                if ($record->kpi_source === PayrollRecord::KPI_RETENTION) {
                    $record->retention_tier = filled($validated['retention_tier'] ?? null) ? (float) $validated['retention_tier'] : null;
                    $record->foreign_session_pay = (float) ($validated['foreign_session_pay'] ?? 0);
                } elseif ($record->teaching_concurrent) {
                    // Học thuật kiêm nhiệm giảng dạy: bậc KPI kiêm nhiệm (giữ HS) như GV part-time.
                    $record->retention_tier = filled($validated['retention_tier'] ?? null) ? (float) $validated['retention_tier'] : null;
                }
                if ($record->kpi_source === PayrollRecord::KPI_MANUAL) {
                    $record->kpi_manual_amount = filled($validated['kpi_manual_amount'] ?? null) ? (float) $validated['kpi_manual_amount'] : null;
                }
                if ($record->isFullTime()) {
                    $record->tax_deduction = (float) ($validated['tax_deduction'] ?? 0);
                }
                $record->manual_lines = $lines;
            } else {
                // Phiếu trước Q3 (chưa tính lại): chỉ ghi chú + tổng khoản tự do vào cột cũ.
                $record->other_bonus = collect($lines)->where('kind', 'earning')->sum('amount');
                $record->other_deduction = collect($lines)->where('kind', 'deduction')->sum('amount');
                $record->manual_lines = $lines;
            }
            $record->adjustment_notes = $validated['adjustment_notes'] ?? null;
            $record->applyManualInputs();
            $record->save();
            $record->period->refreshTotals();

            activity('payroll_record')->causedBy($request->user())->performedOn($record)
                ->withProperties(['before' => $before, 'after' => $record->only(array_keys($before))])
                ->log('Điều chỉnh phiếu lương '.$record->user?->name.' — '.$record->period->title);
        });

        return redirect()->route('payroll.records.show', $record->id)
            ->with('status', 'Đã lưu các khoản nhập tay — thực lĩnh mới '.Money::format((float) $record->net_salary).'.');
    }

    public function fulltimePeriod($id): InertiaResponse
    {
        $period = PayrollPeriod::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $records = $this->scopeRecords($period->records())->with('user')->where('department', 'fulltime')->get();

        return $this->departmentPage($period, $records, 'fulltime');
    }

    public function academicPeriod($id): InertiaResponse
    {
        $period = PayrollPeriod::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $records = $this->scopeRecords($period->records())->with('user')->where('department', 'academic')->get();

        return $this->departmentPage($period, $records, 'academic');
    }

    public function operationsPeriod($id): InertiaResponse
    {
        $period = PayrollPeriod::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $records = $this->scopeRecords($period->records())->with('user')->where('department', 'operations')->get();

        return $this->departmentPage($period, $records, 'operations');
    }

    /** Bảng lương theo khối (GV Full-time / Học thuật / Học vụ & Vận hành) — một trang Vue, nội dung theo $department. */
    private function departmentPage(PayrollPeriod $period, Collection $records, string $department): InertiaResponse
    {
        return Inertia::render('Payroll/Department', [
            'department' => $department,
            'period' => $this->periodData($period),
            'records' => $records->map(fn (PayrollRecord $r) => $this->recordRow($r))->values(),
            'totals' => [
                'net_salary' => (float) $records->sum('net_salary'),
                'renew_bonus' => (float) $records->sum('renew_bonus'),
                'kpi_bonus' => (float) $records->sum('kpi_bonus'),
                // Lương cơ bản thực trả theo ngày công.
                'base_salary' => (float) $records->sum(fn (PayrollRecord $r) => $r->base_salary_earned),
                'teaching_salary' => (float) $records->sum('teaching_salary'),
                'teaching_kpi_bonus' => (float) $records->sum('teaching_kpi_bonus'),
                'allowance' => (float) $records->sum('allowance'),
                'commission_bonus' => (float) $records->sum('commission_bonus'),
            ],
        ]);
    }

    /**
     * Lớp người dùng được chấm công tay / chỉnh ca / xác nhận theo lịch / lọc ca — phạm vi "attendance_staff.scope_*":
     * - Toàn hệ thống (Admin, Học vụ; kế toán tổng được cấp): mọi lớp.
     * - Chi nhánh (Quản lý cơ sở, Kế toán): lớp thuộc chi nhánh của mình + lớp mình được xem (ClassModel::visibleTo).
     * - Của tôi: lớp mình được xem (ClassModel::visibleTo).
     */
    /** Ca dạy thuộc lớp trong phạm vi chấm công của người thao tác (ca không gắn lớp: chỉ phạm vi toàn hệ thống). */
    private function canReachTimesheet(User $user, TeacherTimesheet $timesheet): bool
    {
        return $timesheet->class_id
            ? $this->timesheetClasses($user)->whereKey($timesheet->class_id)->exists()
            : DataScope::level($user, 'attendance_staff') === DataScope::ALL;
    }

    private function timesheetClasses(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return match (DataScope::level($user, 'attendance_staff')) {
            DataScope::ALL => ClassModel::query(),
            DataScope::BRANCH => ClassModel::query()->where(fn ($q) => $q->whereIn('branch_id', $user->branchIds())
                ->orWhereIn('id', ClassModel::query()->visibleTo($user)->select('id'))),
            default => ClassModel::query()->visibleTo($user),
        };
    }

    public function manualTimesheet(Request $request): InertiaResponse
    {
        // Chỉ liệt kê lớp đang/opening trong phạm vi người chấm và nhân sự giảng dạy — User::all() trước đây
        // đưa cả học viên vào dropdown chấm công.
        $classes = $this->timesheetClasses($request->user())->with('branch')
            ->whereIn('status', ['active', 'upcoming', 'pending_schedule'])->orderBy('name')->get();
        $teachers = $this->teachingStaff();
        $branches = $classes->pluck('branch')->filter()->unique('id')->sortBy('name')->values();

        // Khoảng ngày của các kỳ lương đã gửi GV xem/đã trả: màn hình cảnh báo và khóa nút lưu ngay khi chọn ngày.
        $lockedRanges = PayrollPeriod::whereIn('status', PayrollPeriod::LOCKED_STATUSES)
            ->get(['start_date', 'end_date', 'title'])
            ->map(fn (PayrollPeriod $p) => [
                'from' => Carbon::parse($p->start_date)->toDateString(),
                'to' => Carbon::parse($p->end_date)->toDateString(),
                'title' => $p->title,
            ])->values();

        $user = $request->user();
        $defaultBranch = $request->query('branch_id') ?: ($branches->count() === 1
            ? $branches->first()->id
            : ($user->branch_id && $branches->contains('id', $user->branch_id) ? $user->branch_id : ''));

        return Inertia::render('Payroll/Timesheets/Manual', [
            'teachers' => $teachers->map(fn (User $tc) => [
                'id' => $tc->id,
                'label' => $tc->name.($tc->employee_code ? ' — '.$tc->employee_code : '').' ('.($tc->getRoleNames()->map(fn ($r) => AclHelper::roleLabel($r))->implode(', ') ?: 'GV/TA').')',
                'search' => Str::lower(Str::ascii($tc->name.' '.$tc->employee_code.' '.$tc->email)),
            ])->values(),
            'classes' => $classes->map(fn (ClassModel $cl) => ['id' => $cl->id, 'label' => $cl->name.' ('.$cl->code.')', 'branch' => $cl->branch_id])->values(),
            'branches' => Ui::options($branches, 'name'),
            'lockedRanges' => $lockedRanges,
            'defaults' => [
                'user_id' => (string) $request->query('user_id', ''),
                'branch_id' => (string) $defaultBranch,
                'class_id' => (string) $request->query('class_id', ''),
                'teaching_date' => (string) $request->query('teaching_date', date('Y-m-d')),
                'time_in' => $request->query('time_in'),
                'time_out' => $request->query('time_out'),
            ],
        ]);
    }

    /**
     * Chấm công tay: bắt buộc lý do + giờ vào/ra (số giờ tính từ giờ vào/ra),
     * tự gắn buổi học thật nếu có, và chặn chấm trùng với check-in/chấm tay khác.
     */
    public function storeTimesheet(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'branch_id' => 'nullable|exists:branches,id',
            'class_id' => 'required|exists:classes,id',
            // Chỉ chấm công cho ca đã diễn ra (không chấm trước cho ngày tương lai).
            'teaching_date' => 'required|date|before_or_equal:today',
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i', 'after:time_in'],
            // Bỏ trống = dùng đơn giá riêng của GV (theo ngày hiệu lực) → users.hourly_rate → mặc định
            'hourly_rate' => 'nullable|numeric|min:1000',
            // Đi muộn / về sớm (phút) và "có báo trước" — quy tắc ngưỡng 15 phút xem TeacherTimesheet::payOutcome().
            'late_minutes' => 'nullable|integer|min:0|max:600',
            'early_leave_minutes' => 'nullable|integer|min:0|max:600',
            'late_notified' => 'nullable|boolean',
            'type' => ['required', 'in:regular,sub,1on1,grading,workshop'],
            'notes' => 'required|string|min:5|max:500',
        ], [
            'time_in.required' => 'Vui lòng nhập giờ vào.',
            'time_out.required' => 'Vui lòng nhập giờ ra.',
            'time_out.after' => 'Giờ ra phải sau giờ vào.',
            'notes.required' => 'Chấm công tay bắt buộc ghi lý do.',
            'notes.min' => 'Lý do chấm công tay cần ít nhất 5 ký tự.',
            'teaching_date.before_or_equal' => 'Không chấm công tay trước cho ngày chưa diễn ra.',
        ]);

        // A3: chỉ chấm công tay cho lớp trong phạm vi mình (chi nhánh), không phải lớp bất kỳ — xem timesheetClasses().
        abort_unless(
            $this->timesheetClasses($request->user())->whereKey($validated['class_id'])->exists(),
            403,
            'Lớp này nằm ngoài phạm vi bạn được chấm công.'
        );

        if (filled($validated['branch_id'] ?? null)
            && (int) ClassModel::whereKey($validated['class_id'])->value('branch_id') !== (int) $validated['branch_id']) {
            throw ValidationException::withMessages(['class_id' => 'Lớp đã chọn không thuộc chi nhánh đã chọn.']);
        }

        if (PayrollPeriod::isLockedFor($validated['teaching_date'])) {
            return $this->rejectLockedDate('teaching_date', $validated['teaching_date']);
        }

        $hours = round(
            abs(Carbon::createFromFormat('H:i', $validated['time_in'])
                ->diffInMinutes(Carbon::createFromFormat('H:i', $validated['time_out']))) / 60,
            2
        );
        if ($hours < 0.5) {
            throw ValidationException::withMessages(['time_out' => 'Ca dạy phải kéo dài ít nhất 30 phút.']);
        }

        $date = Carbon::parse($validated['teaching_date'])->toDateString();
        $session = TeacherTimesheet::matchSession(
            (int) $validated['user_id'], (int) $validated['class_id'], $date, $validated['time_in'], $validated['time_out']
        );

        $duplicate = TeacherTimesheet::findDuplicate((int) $validated['user_id'], (int) $validated['class_id'], $date, $session?->id);
        if ($duplicate) {
            $how = $duplicate->source === TeacherTimesheet::SOURCE_MANUAL ? 'chấm tay' : 'check-in';
            $message = "Nhân sự đã được chấm công ({$how}) cho lớp này ngày ".Carbon::parse($date)->format('d/m/Y')
                .' — không thể tính công 2 lần. Nếu bản ghi cũ sai, hãy từ chối bản ghi đó trước.';

            return redirect()->back()->withInput()->withErrors(['teaching_date' => $message])->with('error', $message);
        }

        $attributes = [
            'user_id' => $validated['user_id'],
            'class_id' => $validated['class_id'],
            'class_session_id' => $session?->id,
            'teaching_date' => $date,
            'scheduled_time' => $session?->start_time && $session?->end_time
                ? $session->start_time->format('H:i').'-'.$session->end_time->format('H:i')
                : null,
            'checkin_time' => $validated['time_in'],
            'checkout_time' => $validated['time_out'],
            'hours' => $hours,
            'hourly_rate' => $validated['hourly_rate'] ?? null,
            'late_minutes' => (int) ($validated['late_minutes'] ?? 0),
            'early_leave_minutes' => (int) ($validated['early_leave_minutes'] ?? 0),
            'late_notified' => (bool) ($validated['late_notified'] ?? false),
            'type' => $validated['type'],
            'source' => TeacherTimesheet::SOURCE_MANUAL,
            'status' => 'pending_review',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'notes' => $validated['notes'],
        ];

        // Buổi học đã có bản ghi bị từ chối (unique user + buổi): ghi đè bản ghi đó thay vì tạo mới.
        $rejected = $session
            ? TeacherTimesheet::where('user_id', $validated['user_id'])->where('class_session_id', $session->id)->first()
            : null;
        $rejected ? $rejected->update($attributes) : TeacherTimesheet::create($attributes);

        return redirect()->route('payroll.timesheets.teachers')
            ->with('status', 'Đã ghi nhận chấm công tay ('.rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').'h'
                .($session ? ', gắn buổi học ngày '.Carbon::parse($date)->format('d/m/Y') : ', không có buổi học trên lịch')
                .') — chờ duyệt.');
    }

    /**
     * Chi tiết chấm công GV + đối soát (mockup epic-7/chi-tiet-cham-cong-theo-gv, 01_Web_Admin/10_doi_soat_chot_bang_cong):
     * lọc theo kỳ lương (tháng), chi nhánh, lớp, giáo viên, trạng thái; chọn một giáo viên → thẻ thông tin GV,
     * trạng thái khóa kỳ, số buổi thiếu chấm công (buổi được phân công đã qua mà chưa có ca chấm công hợp lệ).
     */
    public function teacherTimesheets(Request $request)
    {
        $user = $request->user();
        $canViewAll = $user->can('attendance_staff.view');
        abort_unless($canViewAll || $user->can('payroll.view_own'), 403);

        $monthStart = ReportPeriod::parseMonth($request->query('month'))->startOfDay();
        $month = $monthStart->format('Y-m');
        $monthEnd = $monthStart->copy()->endOfMonth();
        $status = in_array($request->query('status'), ['pending_review', 'valid', 'invalid'], true) ? $request->query('status') : null;
        // Loại ca (VD "sub" = danh sách buổi dạy thay chờ xác nhận — mockup 01_Web_Admin/11).
        $type = in_array($request->query('type'), ['regular', 'sub', '1on1', 'grading', 'workshop'], true) ? $request->query('type') : null;
        $search = trim((string) $request->query('search', ''));
        $branchId = $canViewAll ? ($request->integer('branch_id') ?: null) : null;
        $classId = $request->integer('class_id') ?: null;
        $teacherId = $canViewAll ? ($request->integer('user_id') ?: null) : $user->id;

        $visibleClassIds = $canViewAll ? $this->timesheetClasses($user)->pluck('id') : null;
        $limitToVisibleClasses = $canViewAll && ! DataScope::isAll($user, 'attendance_staff');

        $query = TeacherTimesheet::with(['teacher.branch', 'classModel', 'reviewer', 'adjuster', 'classSession'])
            ->whereBetween('teaching_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->when(! $canViewAll, fn ($q) => $q->where('user_id', $user->id))
            // Quản lý cơ sở / Học vụ chỉ thấy ca của lớp trong phạm vi mình (Admin thấy tất cả).
            ->when($limitToVisibleClasses, fn ($q) => $q->whereIn('class_id', $visibleClassIds))
            ->when($teacherId, fn ($q) => $q->where('user_id', $teacherId))
            ->when($branchId, fn ($q) => $q->whereHas('classModel', fn ($c) => $c->where('branch_id', $branchId)))
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($search !== '', fn ($q) => $q->whereHas('teacher', fn ($t) => $t->where('name', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%")));

        $summary = (clone $query)->reorder()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $timesheets = $query->orderByDesc('teaching_date')->orderByDesc('id')
            ->paginate($request->perPage(20))
            ->withQueryString();

        $period = PayrollPeriod::where('year', $monthStart->year)->where('month', $monthStart->month)->first();
        $periodLocked = $period?->isLocked() || PayrollPeriod::isLockedFor($monthStart);

        // Thẻ giáo viên: số buổi được phân công đã diễn ra trong tháng mà chưa có ca chấm công (không tính ca bị từ chối).
        // Chỉ hiện thẻ GV trong phạm vi (Quản lý cơ sở: nhân sự chi nhánh mình) — ?user_id= của chi nhánh khác không lộ tên / mã.
        $teacher = $teacherId ? $this->staffInScope(User::with('branch'), $user)->find($teacherId) : null;
        $missingSessions = collect();
        if ($teacher) {
            $missingSessions = \App\Models\ClassSession::with('classModel')
                ->forStaff($teacher->id)
                ->when($canViewAll, fn ($q) => $q->whereIn('class_id', $this->timesheetClasses($user)->select('id')))
                ->where('status', '!=', 'cancelled')
                ->whereBetween('date', [$monthStart->toDateString(), min($monthEnd, today())->toDateString()])
                ->whereDoesntHave('timesheets', fn ($t) => $t->where('user_id', $teacher->id)->where('status', '!=', 'invalid'))
                ->when($classId, fn ($q) => $q->where('class_id', $classId))
                ->orderBy('date')->orderBy('start_time')
                ->get()
                // Ca chấm tay không gắn buổi (cùng lớp + ngày) cũng coi là đã chấm.
                ->reject(fn ($s) => TeacherTimesheet::findDuplicate($teacher->id, (int) $s->class_id, $s->date->toDateString(), $s->id) !== null)
                ->values();
        }

        // Chấm công theo lịch (mockup 01_Web_Admin/09): buổi học của ngày chọn + trạng thái chấm công của GV dự kiến.
        $scheduleDay = $canViewAll
            ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('day')) ? Carbon::parse($request->query('day')) : today())
            : null;
        $scheduleSessions = collect();
        if ($scheduleDay) {
            $scheduleSessions = \App\Models\ClassSession::with(['classModel', 'teacher', 'timesheets.reviewer'])
                ->whereDate('date', $scheduleDay->toDateString())
                ->where('status', '!=', 'cancelled')
                ->whereNotNull('teacher_id')
                ->whereIn('class_id', $this->timesheetClasses($user)->select('id'))
                ->when($branchId, fn ($q) => $q->whereHas('classModel', fn ($c) => $c->where('branch_id', $branchId)))
                ->orderBy('start_time')->get()
                ->map(function ($session) {
                    $ts = $session->timesheets->first(fn ($t) => (int) $t->user_id === (int) $session->teacher_id && $t->status !== 'invalid')
                        ?? TeacherTimesheet::findDuplicate((int) $session->teacher_id, (int) $session->class_id, $session->date->toDateString(), $session->id);
                    $session->setAttribute('schedule_timesheet', $ts);

                    return $session;
                });
        }
        $subPendingCount = $canViewAll
            ? TeacherTimesheet::where('type', 'sub')->where('status', 'pending_review')
                ->when($limitToVisibleClasses, fn ($q) => $q->whereIn('class_id', $visibleClassIds))->count()
            : 0;

        $filterClasses = $canViewAll ? $this->timesheetClasses($user)->orderBy('name')->get(['id', 'name', 'code', 'branch_id']) : collect();
        $filterBranches = $canViewAll ? \App\Models\Branch::whereIn('id', $filterClasses->pluck('branch_id')->filter()->unique())->orderBy('name')->get(['id', 'name']) : collect();
        $filterTeachers = $canViewAll ? $this->teachingStaff() : collect();

        $canReview = $canViewAll && $user->can('attendance_staff.view');

        return Inertia::render('Payroll/Timesheets/Teachers', [
            'canViewAll' => $canViewAll,
            'month' => $month,
            'monthLabel' => $monthStart->format('m/Y'),
            'period' => $period ? ['status_label' => $period->status_label] : null,
            'periodLocked' => (bool) $periodLocked,
            'summary' => [
                'pending_review' => (int) ($summary['pending_review'] ?? 0),
                'valid' => (int) ($summary['valid'] ?? 0),
                'invalid' => (int) ($summary['invalid'] ?? 0),
            ],
            'timesheets' => $timesheets->through(fn (TeacherTimesheet $ts) => [
                'id' => $ts->id,
                'user_id' => $ts->user_id,
                'teaching_date' => $ts->teaching_date->toDateString(),
                'scheduled_time' => $ts->scheduled_time,
                'shift_name' => $ts->classSession?->shiftLabel(),
                'type_label' => $ts->type_label,
                'source' => $ts->source,
                'source_label' => $ts->source_label,
                'class_label' => $ts->classModel?->code ?? $ts->classModel?->name ?? '—',
                'teacher_name' => $ts->teacher?->name,
                'checkin_time' => $ts->checkin_time,
                'checkout_time' => $ts->checkout_time,
                'display_checkout' => $ts->display_checkout,
                'adjusted' => $ts->adjusted_at !== null,
                'hours' => rtrim(rtrim(number_format((float) $ts->hours, 2, '.', ''), '0'), '.'),
                'punch_state' => $ts->punch_state,
                'punch_state_label' => $ts->punch_state_label,
                'adjustment_reason' => $ts->adjustment_reason,
                'adjustment_short' => $ts->adjusted_at ? Str::limit((string) $ts->adjustment_reason, 50) : null,
                'adjuster_name' => $ts->adjuster?->name,
                'notes_short' => $ts->source === TeacherTimesheet::SOURCE_MANUAL && $ts->notes ? Str::limit($ts->notes, 50) : null,
                'status' => $ts->status,
                'status_label' => $ts->status_label,
                'rejection_short' => $ts->status === 'invalid' && $ts->rejection_reason ? Str::limit($ts->rejection_reason, 60) : null,
                'reviewer_name' => $ts->reviewer?->name,
                'locked' => $periodLocked || PayrollPeriod::isLockedFor($ts->teaching_date),
                'reject_label' => ($ts->teacher?->name ?? '').' — '.$ts->teaching_date->format('d/m/Y'),
                'late_minutes' => (int) $ts->late_minutes,
                'early_leave_minutes' => (int) $ts->early_leave_minutes,
                'late_notified' => (bool) $ts->late_notified,
                'late_label' => $ts->late_total_minutes > 0
                    ? ($ts->late_minutes ? "Muộn {$ts->late_minutes}p" : '').($ts->late_minutes && $ts->early_leave_minutes ? ' · ' : '').($ts->early_leave_minutes ? "Về sớm {$ts->early_leave_minutes}p" : '').($ts->late_notified ? ' (có báo trước)' : ' (không báo trước)')
                    : null,
                'edit_label' => ($ts->teacher?->name ?? '').' — '.($ts->classModel?->code ?? '').' — '.$ts->teaching_date->format('d/m/Y'),
            ]),
            'teacher' => $teacher ? [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'employee_code' => $teacher->employee_code,
                'branch_name' => $teacher->branch?->name,
            ] : null,
            'missingSessions' => $missingSessions->map(fn ($session) => [
                'id' => $session->id,
                'date' => $session->date->toDateString(),
                'start_time' => $session->start_time?->format('H:i'),
                'end_time' => $session->end_time?->format('H:i'),
                'class_label' => $session->classModel?->code ?? $session->classModel?->name,
                'manual_url' => route('payroll.timesheets.manual', [
                    'user_id' => $teacher->id, 'class_id' => $session->class_id, 'branch_id' => $session->classModel?->branch_id,
                    'teaching_date' => $session->date->toDateString(), 'time_in' => $session->start_time?->format('H:i'), 'time_out' => $session->end_time?->format('H:i'),
                ], false),
            ])->values(),
            'scheduleDay' => $scheduleDay ? [
                'date' => $scheduleDay->toDateString(),
                'weekday' => ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'][$scheduleDay->dayOfWeek],
            ] : null,
            'scheduleSessions' => $scheduleSessions->map(function ($session) use ($canReview) {
                $sts = $session->schedule_timesheet;

                return [
                    'id' => $session->id,
                    'class_name' => $session->classModel?->name,
                    'class_code' => $session->classModel?->code,
                    'start_time' => $session->start_time?->format('H:i'),
                    'end_time' => $session->end_time?->format('H:i'),
                    'teacher_name' => $session->teacher?->name,
                    'state' => match (true) {
                        $sts && $sts->status === 'valid' => 'valid',
                        $sts && $sts->source === 'checkin' => 'checkin',
                        (bool) $sts => 'pending',
                        default => 'none',
                    },
                    'state_note' => $sts ? ($sts->reviewer ? 'Đã duyệt bởi '.$sts->reviewer->name : $sts->source_label) : null,
                    'source_label' => $sts?->source_label,
                    'can_confirm' => ! ($sts && $sts->status === 'valid') && $canReview && ! $session->date->isFuture() && ! PayrollPeriod::isLockedFor($session->date),
                ];
            })->values(),
            'subPendingCount' => $subPendingCount,
            'filterBranches' => Ui::options($filterBranches, 'name'),
            'filterClasses' => Ui::options($filterClasses, fn ($c) => $c->name.' ('.$c->code.')'),
            'filterTeachers' => Ui::options($filterTeachers, 'name'),
        ]);
    }

    /**
     * "Xác nhận" một buổi học trên lịch (mockup Chấm công theo lịch): ghi / duyệt ca chấm công của GV dự kiến
     * với giờ theo lịch. Ca GV đã check-in hoặc đã chấm tay → duyệt ca đó; chưa có → tạo ca "Xác nhận theo lịch" hợp lệ.
     * Chỉ buổi thật, đã tới ngày, chưa hủy, ngoài kỳ lương đã khóa.
     */
    public function confirmScheduledSession(Request $request, int $sessionId, ScheduleConfirmation $confirmation)
    {
        abort_unless($request->user()->can('attendance_staff.view'), 403);
        $session = \App\Models\ClassSession::with('classModel')->findOrFail($sessionId);
        abort_unless($this->timesheetClasses($request->user())->whereKey($session->class_id)->exists(), 403);
        abort_if($session->status === 'cancelled' || ! $session->teacher_id, 422, 'Buổi học đã hủy hoặc chưa phân công giáo viên.');
        if ($session->date->isAfter(today())) {
            throw ValidationException::withMessages(['session' => 'Chưa tới ngày học — không thể xác nhận chấm công trước.']);
        }
        if (PayrollPeriod::isLockedFor($session->date)) {
            return $this->rejectLockedDate('session', $session->date);
        }

        $confirmation->confirm($session, $session->teacher, $request->user());

        return redirect()->back()->with('status', 'Đã xác nhận chấm công buổi '.($session->classModel?->code ?? '').' ngày '.$session->date->format('d/m/Y').'.');
    }

    /**
     * Xác nhận theo lịch hàng loạt (chủ dự án 22/10/2026): Học vụ chọn các buổi thiếu công của một nhân sự trên Bảng giờ dạy
     * → mỗi buổi đi qua đúng quy tắc xác nhận từng buổi, ca hợp lệ được tính lương. Buổi không xác nhận được thì bỏ qua và báo lại.
     */
    public function bulkConfirmSessions(Request $request, ScheduleConfirmation $confirmation)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'session_ids' => 'required|array|min:1|max:100',
            'session_ids.*' => 'integer|distinct',
        ], ['session_ids.required' => 'Chọn ít nhất 1 buổi cần xác nhận.']);
        $staff = User::findOrFail($validated['user_id']);
        $sessions = \App\Models\ClassSession::with('classModel')->whereIn('id', $validated['session_ids'])->orderBy('date')->orderBy('start_time')->get();
        abort_if($sessions->count() !== count($validated['session_ids']), 404);
        $allowedClasses = $this->timesheetClasses($request->user())->whereIn('id', $sessions->pluck('class_id'))->pluck('id');
        abort_unless($sessions->every(fn ($s) => $allowedClasses->contains($s->class_id)), 403, 'Có buổi học nằm ngoài phạm vi bạn được chấm công.');

        $label = fn ($s) => $s->date->format('d/m/Y').' '.($s->classModel?->code ?? '');
        $skipped = [];
        $confirmed = DB::transaction(function () use ($sessions, $staff, $request, $confirmation, $label, &$skipped) {
            $count = 0;
            foreach ($sessions as $session) {
                if ($reason = $confirmation->skipReason($session, $staff)) {
                    $skipped[] = $label($session).' — '.$reason;

                    continue;
                }
                $confirmation->confirm($session, $staff, $request->user());
                $count++;
            }

            return $count;
        });

        if ($confirmed === 0) {
            return back()->withErrors(['session_ids' => 'Không xác nhận được buổi nào: '.implode('; ', $skipped).'.']);
        }
        activity('teacher_timesheet')->causedBy($request->user())->performedOn($staff)
            ->withProperties(['session_ids' => $sessions->pluck('id')->all(), 'confirmed' => $confirmed])
            ->log("Xác nhận theo lịch hàng loạt {$confirmed} buổi cho {$staff->name}");

        return back()->with('status', "Đã xác nhận {$confirmed} buổi theo lịch cho {$staff->name}."
            .($skipped ? ' Bỏ qua: '.implode('; ', $skipped).'.' : ''));
    }

    public function reviewTimesheet(Request $request, int $id)
    {
        abort_unless($request->user()->can('attendance_staff.view'), 403);
        $validated = $request->validate([
            'decision' => ['required', 'in:valid,invalid'],
            'rejection_reason' => ['nullable', 'required_if:decision,invalid', 'string', 'max:1000'],
        ], ['rejection_reason.required_if' => 'Vui lòng nhập lý do từ chối ca dạy.']);
        $timesheet = TeacherTimesheet::findOrFail($id);
        abort_unless($this->canReachTimesheet($request->user(), $timesheet), 403);
        if (PayrollPeriod::isLockedFor($timesheet->teaching_date)) {
            return $this->rejectLockedDate('teaching_date', $timesheet->teaching_date);
        }
        $timesheet->update([
            'status' => $validated['decision'],
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'rejection_reason' => $validated['decision'] === 'invalid' ? $validated['rejection_reason'] : null,
        ]);

        return redirect()->back()->with('status', $validated['decision'] === 'valid' ? 'Đã xác nhận ca dạy hợp lệ.' : 'Đã từ chối ca dạy.');
    }

    /**
     * "Chốt bảng công (N)" — duyệt hàng loạt các ca đang chờ đối soát đã chọn (mockup 10_doi_soat_chot_bang_cong).
     * Ca thuộc kỳ lương đã khóa hoặc không còn "Chờ duyệt" được bỏ qua.
     */
    public function bulkReviewTimesheets(Request $request)
    {
        abort_unless($request->user()->can('attendance_staff.view'), 403);
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ], ['ids.required' => 'Chọn ít nhất một ca dạy để chốt.']);

        $approved = 0;
        $skipped = 0;
        $user = $request->user();
        TeacherTimesheet::whereIn('id', $validated['ids'])->get()
            ->filter(fn (TeacherTimesheet $ts) => $this->canReachTimesheet($user, $ts))->each(function (TeacherTimesheet $ts) use (&$approved, &$skipped) {
            if ($ts->status !== 'pending_review' || PayrollPeriod::isLockedFor($ts->teaching_date)) {
                $skipped++;

                return;
            }
            $ts->update(['status' => 'valid', 'reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'rejection_reason' => null]);
            $approved++;
        });

        return redirect()->back()->with('status', "Đã chốt {$approved} ca dạy vào bảng công".($skipped ? " — bỏ qua {$skipped} ca (không còn chờ duyệt hoặc thuộc kỳ lương đã khóa)." : '.'));
    }

    /**
     * "Chỉnh tay bổ sung": sửa giờ vào / ra của một ca đã ghi nhận, bắt buộc lý do; ca quay về "Chờ duyệt"
     * để đối soát lại. Không sửa được ca thuộc kỳ lương đã gửi GV xem/đã trả.
     */
    public function adjustTimesheet(Request $request, int $id)
    {
        $timesheet = TeacherTimesheet::findOrFail($id);
        abort_unless($this->timesheetClasses($request->user())->whereKey($timesheet->class_id)->exists(), 403);
        if (PayrollPeriod::isLockedFor($timesheet->teaching_date)) {
            return $this->rejectLockedDate('time_in', $timesheet->teaching_date);
        }

        $validated = $request->validate([
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i', 'after:time_in'],
            'adjustment_reason' => ['required', 'string', 'min:5', 'max:500'],
            'late_minutes' => 'nullable|integer|min:0|max:600',
            'early_leave_minutes' => 'nullable|integer|min:0|max:600',
            'late_notified' => 'nullable|boolean',
        ], [
            'time_in.required' => 'Vui lòng nhập giờ vào.',
            'time_out.required' => 'Vui lòng nhập giờ ra.',
            'time_out.after' => 'Giờ ra phải sau giờ vào.',
            'adjustment_reason.required' => 'Chỉnh tay bắt buộc ghi lý do.',
            'adjustment_reason.min' => 'Lý do điều chỉnh cần ít nhất 5 ký tự.',
        ]);

        $hours = round(abs(Carbon::createFromFormat('H:i', $validated['time_in'])->diffInMinutes(Carbon::createFromFormat('H:i', $validated['time_out']))) / 60, 2);
        if ($hours < 0.5) {
            throw ValidationException::withMessages(['time_out' => 'Ca dạy phải kéo dài ít nhất 30 phút.']);
        }

        $before = $timesheet->only(['checkin_time', 'checkout_time', 'hours', 'late_minutes', 'early_leave_minutes', 'late_notified', 'status']);
        $timesheet->update([
            'checkin_time' => $validated['time_in'],
            'checkout_time' => $validated['time_out'],
            'hours' => $hours,
            // Không gửi trường đi muộn / về sớm → giữ nguyên giá trị cũ (vd. phút muộn do check-in tự ghi).
            'late_minutes' => (int) ($validated['late_minutes'] ?? $timesheet->late_minutes),
            'early_leave_minutes' => (int) ($validated['early_leave_minutes'] ?? $timesheet->early_leave_minutes),
            // Ô tick không gửi giá trị khi bỏ chọn: form có trường phút muộn thì coi "không tick" = chưa báo trước.
            'late_notified' => $request->has('late_minutes') ? $request->boolean('late_notified') : (bool) $timesheet->late_notified,
            'status' => 'pending_review',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'adjusted_at' => now(),
            'adjusted_by' => $request->user()->id,
            'adjustment_reason' => $validated['adjustment_reason'],
        ]);

        activity('teacher_timesheet')->causedBy($request->user())->performedOn($timesheet)
            ->withProperties(['before' => $before, 'after' => $timesheet->only(array_keys($before)), 'reason' => $validated['adjustment_reason']])
            ->log('Chỉnh tay giờ chấm công của '.$timesheet->teacher?->name.' ngày '.$timesheet->teaching_date->format('d/m/Y'));

        return redirect()->back()->with('status', 'Đã chỉnh tay giờ vào/ra ('.rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').'h) — ca chuyển về Chờ duyệt để đối soát lại.');
    }

    /**
     * Lịch sử đồng bộ máy chấm công. Hiện chưa có tích hợp thiết bị nào ghi
     * TimesheetSyncLog → trang hiển thị trạng thái trống trung thực thay vì giả lập.
     */
    public function syncHistory(Request $request)
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;
        $status = array_key_exists((string) $request->query('status'), TimesheetSyncLog::STATUS_LABELS) ? $request->query('status') : null;

        $syncLogs = TimesheetSyncLog::with('branch')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($status === 'failed', fn ($q) => $q->whereIn('status', ['failed', 'error']))
            ->when($status && $status !== 'failed', fn ($q) => $q->where('status', $status))
            ->latest()->latest('id')
            ->paginate($request->perPage(10))
            ->withQueryString();
        $hasAnyLog = TimesheetSyncLog::exists();

        return Inertia::render('Payroll/Timesheets/Sync', [
            'syncLogs' => $syncLogs->through(fn (TimesheetSyncLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at->format('H:i d/m/Y'),
                'device_name' => $log->device_name,
                'branch' => $log->branch?->name ?? 'Toàn hệ thống',
                'records_count' => (int) $log->records_count,
                'matched_count' => (int) $log->matched_count,
                'failed_count' => (int) $log->failed_count,
                'skipped_count' => (int) $log->skipped_count,
                'status' => $log->normalized_status,
                'status_label' => $log->status_label,
                'error_code' => $log->error_code,
                'error_message' => $log->error_message,
                'error_rows' => collect($log->error_rows ?? [])->map(fn ($row) => [
                    'employee_code' => $row['employee_code'] ?? null,
                    'employee_name' => $row['employee_name'] ?? null,
                    'code' => $row['code'] ?? null,
                    'message' => $row['message'] ?? '',
                ])->values()->all(),
                'has_errors' => $log->hasErrorDetails(),
            ]),
            'hasAnyLog' => $hasAnyLog,
            'statusOptions' => Ui::options(TimesheetSyncLog::STATUS_LABELS),
        ]);
    }

    /** "Xuất file Excel lỗi" của một đợt đồng bộ: các dòng lỗi (Mã NV, Tên, Mã lỗi, Nội dung). */
    public function exportSyncErrors(Request $request, int $id)
    {
        $log = TimesheetSyncLog::findOrFail($id);
        $rows = collect($log->error_rows ?? [])->map(fn ($row) => [
            $row['employee_code'] ?? '', $row['employee_name'] ?? '', $row['code'] ?? '', $row['message'] ?? '',
        ])->all();
        if ($rows === [] && filled($log->error_message)) {
            $rows[] = ['', '', $log->error_code, $log->error_message];
        }

        return \App\Exports\ArrayExport::download(
            'loi-dong-bo-cham-cong-'.$log->id,
            ['Mã NV', 'Tên nhân viên', 'Mã lỗi', 'Nội dung chi tiết'],
            $rows,
            $request->query('format', 'xlsx')
        );
    }

    /**
     * BXH KPI & hoa hồng (mockup epic-7/bang-kpi-cong-khai) — dữ liệu công khai, không có lương cơ bản / khấu trừ / thực nhận:
     * 1. KPI giữ học sinh của GV: lấy từ phiếu lương của kỳ (Số HS giữ × đơn giá bậc = KPI) — cùng số với bảng lương.
     * 2. Hoa hồng tuyển sinh của người phụ trách: học phí thu của khách mới trong tháng (không gồm sách / Thu khác)
     *    × % mốc theo thứ tự chốt của từng HS (SalesCommissionService).
     * Lọc kỳ lương (tháng) + chi nhánh, phân trang.
     * Phạm vi: dòng của nhân sự trong phạm vi "kpi" (của tôi / chi nhánh của tôi / mọi nhân sự, như KpiController);
     * số tiền (KPI đ, đơn giá bậc, doanh thu, hoa hồng) chỉ người xem bảng lương (payroll.view — Admin, quy tắc 06/10)
     * mới thấy, người khác chỉ thấy số HS / mốc / thứ hạng.
     */
    public function kpiLeaderboard(Request $request)
    {
        $viewer = $request->user();
        $showMoney = $viewer->can('payroll.view');
        $staffIds = DataScope::isAll($viewer, 'kpi') ? null : $this->kpiScopedStaff(User::withTrashed(), $viewer)->pluck('id');

        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $request->query('period'), $m)) {
            [$year, $month] = [(int) $m[1], (int) $m[2]];
        } else {
            $month = $request->integer('month') ?: now()->month;
            $year = $request->integer('year') ?: now()->year;
        }
        $month = min(12, max(1, $month));
        $year = min(2100, max(2020, $year));
        $branchId = $request->integer('branch_id') ?: null;
        // Chỉ nhận chi nhánh trong phạm vi KPI của người xem (chi nhánh khác → bỏ lọc, vẫn chỉ thấy dữ liệu trong phạm vi).
        if ($branchId && ! DataScope::coversBranch($viewer, 'kpi', $branchId)) {
            $branchId = null;
        }
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // 1. KPI giữ học sinh từ phiếu lương của kỳ.
        $period = PayrollPeriod::where('year', $year)->where('month', $month)->first();
        $retention = $period
            ? PayrollRecord::with('user.branch')->where('payroll_period_id', $period->id)
                ->where('kpi_source', PayrollRecord::KPI_RETENTION)
                ->when($staffIds !== null, fn ($q) => $q->whereIn('user_id', $staffIds))
                ->when($branchId, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('branch_id', $branchId)))
                ->get()
                ->sortByDesc(fn (PayrollRecord $r) => [(float) $r->kpi_bonus, (int) $r->retention_students])
                ->values()
            : collect();

        // 2. Hoa hồng tuyển sinh: người phụ trách khách (Học vụ, Admin, sale cũ) có HS chốt / học phí thu trong tháng,
        // cùng Sales & Học vụ đang hoạt động (hiện 0đ) để thấy đủ người.
        $service = app(SalesCommissionService::class);
        $summary = $service->summaryByOwner($start, $end);
        $salesUsers = User::withTrashed()->with('branch')
            ->where(fn ($q) => $q->whereIn('id', $summary->keys())
                ->orWhere(fn ($active) => $active->whereNull('deleted_at')->where('is_active', true)
                    ->whereHas('roles', fn ($r) => $r->whereIn('name', [Roles::SALES_CONSULTANT, Roles::ACADEMIC_STAFF]))))
            ->when($staffIds !== null, fn ($q) => $q->whereIn('id', $staffIds))
            ->get()
            ->when($branchId, fn ($users) => $users->where('branch_id', $branchId));
        $emptyRow = ['collected' => 0.0, 'base' => 0.0, 'amount' => 0.0, 'students' => 0, 'closed' => 0, ...$service->milestoneFor(0, $end)];

        $usersWithSales = $salesUsers->map(function (User $user) use ($summary, $emptyRow) {
            // Hoa hồng phát sinh (trước gate kép) — trả thực tế theo phiếu lương.
            $row = $summary->get($user->id, $emptyRow);

            return [
                'user' => $user,
                'revenue' => $row['collected'],
                'base' => $row['base'],
                'deals' => $row['students'],
                'tier_name' => $row['tier_name'],
                'closed' => $row['closed'],
                'percent' => $row['percent'],
                'next_percent' => $row['next_percent'],
                'to_next' => $row['to_next'],
                'commission' => $row['amount'],
                'branch_name' => $user->branch?->name ?? 'Hệ thống MEnglish',
            ];
        })->sortByDesc(fn ($row) => [$row['commission'], $row['revenue']])->values();

        $perPage = $request->perPage(20);
        $paginate = fn ($items, string $pageName) => new \Illuminate\Pagination\LengthAwarePaginator(
            $items->forPage($request->integer($pageName, 1) ?: 1, $perPage)->values(), $items->count(), $perPage,
            $request->integer($pageName, 1) ?: 1, ['path' => $request->url(), 'query' => $request->query(), 'pageName' => $pageName]
        );
        $retentionPage = $paginate($retention, 'kpi_page');
        $salesPage = $paginate($usersWithSales, 'sales_page');

        $periodOptions = PayrollPeriod::orderByDesc('year')->orderByDesc('month')->get(['month', 'year', 'status'])
            ->mapWithKeys(fn ($p) => [sprintf('%04d-%02d', $p->year, $p->month) => 'Tháng '.sprintf('%02d/%04d', $p->month, $p->year)])
            ->prepend('Tháng '.now()->format('m/Y'), now()->format('Y-m'))
            ->put(sprintf('%04d-%02d', $year, $month), 'Tháng '.sprintf('%02d/%04d', $month, $year))
            ->sortKeysDesc();
        $scopeBranchIds = DataScope::branchIds($viewer, 'kpi');
        $branches = \App\Models\Branch::when($scopeBranchIds !== null, fn ($q) => $q->whereIn('id', $scopeBranchIds))
            ->orderBy('name')->get(['id', 'name']);
        $money = fn ($value) => $showMoney ? (float) $value : null;

        return Inertia::render('Payroll/KpiLeaderboard', [
            'retentionPage' => $retentionPage->through(fn (PayrollRecord $r) => [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'name' => $r->user?->name,
                'branch' => $r->user?->branch?->name ?? 'Hệ thống MEnglish',
                'retention_students' => (int) $r->retention_students,
                'retention_base_students' => (int) $r->retention_base_students,
                // Đơn giá bậc × số HS = KPI (đ) → cùng là số tiền phiếu lương, ẩn với người không xem bảng lương.
                'retention_tier' => $showMoney ? $r->retention_tier : null,
                'kpi_bonus' => $money($r->kpi_bonus),
            ]),
            'salesPage' => $salesPage->through(fn (array $item) => [
                'id' => $item['user']->id,
                'name' => $item['user']->name,
                'tier_name' => $item['tier_name'],
                'deals' => $item['deals'],
                'branch' => $item['branch_name'],
                'closed' => $item['closed'],
                'percent' => (float) $item['percent'],
                'next_percent' => $item['next_percent'],
                'to_next' => $item['to_next'],
                'revenue' => $money($item['revenue']),
                'base' => $money($item['base']),
                'commission' => $money($item['commission']),
            ]),
            'period' => $period ? ['locked' => $period->isLocked()] : null,
            'month' => $month,
            'year' => $year,
            'selectedPeriod' => sprintf('%04d-%02d', $year, $month),
            'branchId' => $branchId,
            'periodOptions' => Ui::options($periodOptions),
            'branches' => Ui::options($branches, 'name'),
        ]);
    }

    /** Nhân sự trong phạm vi "kpi" của người xem — cùng quy tắc KpiController::scopedStaff (của tôi / chi nhánh + của tôi / tất cả). */
    private function kpiScopedStaff(\Illuminate\Database\Eloquent\Builder $query, User $viewer): \Illuminate\Database\Eloquent\Builder
    {
        return DataScope::apply(
            $query, $viewer, 'kpi',
            fn ($q) => $q->whereKey($viewer->id),
            fn ($q, array $branchIds) => $q->whereIn('branch_id', $branchIds)
                ->orWhereHas('branches', fn ($b) => $b->whereIn('branches.id', $branchIds)),
            branchIncludesOwn: true,
        );
    }

    public function configSettings()
    {
        $settings = PayrollPeriod::payrollSettings();
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        return Inertia::render('Payroll/Config/Settings', [
            'settings' => [
                'insurance_salary_base' => (int) $settings['insurance_salary_base'],
                'missing_punch_fine' => (int) $settings['missing_punch_fine'],
                'assistant_hourly_tiers' => collect($settings['assistant_hourly_tiers'])->map(fn ($t) => Money::format($t, ''))->implode(' / '),
                'insurance_rate_percent' => $fmt($settings['insurance_rate_percent']),
                'union_rate_percent' => $fmt($settings['union_rate_percent']),
                'academic_kpi_fund' => (int) $settings['academic_kpi_fund'],
                'renewal_beyond_percent' => $fmt($settings['renewal_beyond_percent']),
                'late_threshold_minutes' => (int) $settings['late_threshold_minutes'],
                'late_deduction_per_minute' => (int) $settings['late_deduction_per_minute'],
                'teaching_share_percent' => $fmt($settings['teaching_share_percent']),
                'retention_tiers' => collect($settings['retention_tiers'])->map(fn ($t) => Money::format($t, ''))->implode(' / '),
            ],
            'renewalRows' => collect($settings['renewal_table'])
                ->map(fn ($row, $quits) => ['quits' => $quits, 'percent' => $row['percent'], 'pending' => (bool) $row['pending']])
                ->values()->all(),
        ]);
    }

    /**
     * Tham số công thức lương Q3: mức lương đóng BHXH và tỉ lệ BHXH / Công đoàn (Full-time), quỹ KPI Học vụ,
     * bảng % thưởng tái tục theo số HS nghỉ (các mốc chưa được BA chốt đánh dấu "chờ BA").
     */
    public function storeSettings(Request $request)
    {
        $validated = $request->validate([
            // Mức lương đóng BHXH (đ): BHXH / Công đoàn của Full-time tính trên mức này. Bỏ trống = giữ mức đang dùng.
            'insurance_salary_base' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            // Phạt mỗi lần quên chấm vào / chấm ra (TK-0013). Bỏ trống = giữ mức đang dùng.
            'missing_punch_fine' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'insurance_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'union_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'academic_kpi_fund' => ['required', 'numeric', 'min:0'],
            'renewal' => ['nullable', 'array', 'max:20'],
            'renewal.*.quits' => ['required', 'integer', 'min:0', 'max:50', 'distinct'],
            'renewal.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'renewal.*.pending' => ['nullable', 'boolean'],
            'renewal_beyond_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'late_threshold_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
            // Đơn giá trừ mỗi phút đi muộn / về sớm (không báo trước, dưới ngưỡng): BA chốt trong khoảng 4.000–5.000đ.
            'late_deduction_per_minute' => ['nullable', 'numeric', 'min:4000', 'max:5000'],
            'teaching_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'late_deduction_per_minute.min' => 'Đơn giá trừ mỗi phút đi muộn phải từ 4.000đ đến 5.000đ.',
            'late_deduction_per_minute.max' => 'Đơn giá trừ mỗi phút đi muộn phải từ 4.000đ đến 5.000đ.',
            '*.required' => 'Vui lòng nhập đầy đủ các tham số.',
            'insurance_rate_percent.max' => 'Tỉ lệ BHXH không được vượt quá 100%.',
            'renewal.*.quits.distinct' => 'Mỗi mức số HS nghỉ chỉ được khai báo một lần.',
        ]);

        if (filled($validated['insurance_salary_base'] ?? null)) {
            SystemSetting::set('payroll_insurance_salary_base', $validated['insurance_salary_base'], 'Mức lương đóng BHXH của Full-time (đ)');
        }
        if (filled($validated['missing_punch_fine'] ?? null)) {
            SystemSetting::set('payroll_missing_punch_fine', $validated['missing_punch_fine'], 'Phạt mỗi lần quên chấm vào / chấm ra (đ)');
        }
        SystemSetting::set('payroll_insurance_rate_percent', $validated['insurance_rate_percent'], 'BHXH trừ trên mức lương đóng BHXH Full-time (%)');
        SystemSetting::set('payroll_union_rate_percent', $validated['union_rate_percent'], 'Công đoàn trừ trên mức lương đóng BHXH Full-time (%)');
        SystemSetting::set('payroll_academic_kpi_fund', $validated['academic_kpi_fund'], 'Quỹ KPI Học vụ mỗi tháng (đ)');
        if (array_key_exists('renewal', $validated)) {
            $table = collect($validated['renewal'] ?? [])
                ->mapWithKeys(fn ($row) => [(int) $row['quits'] => ['percent' => (float) $row['percent'], 'pending' => (bool) ($row['pending'] ?? false)]])
                ->sortKeys()->all();
            SystemSetting::set('payroll_renewal_table', $table, 'Thưởng tái tục: % doanh thu lớp theo số HS nghỉ trong kỳ');
        }
        if (array_key_exists('renewal_beyond_percent', $validated) && $validated['renewal_beyond_percent'] !== null) {
            SystemSetting::set('payroll_renewal_beyond_percent', $validated['renewal_beyond_percent'], 'Thưởng tái tục khi số HS nghỉ vượt bảng (%)');
        }

        if (filled($validated['late_threshold_minutes'] ?? null)) {
            SystemSetting::set('payroll_late_threshold_minutes', $validated['late_threshold_minutes'], 'GV đi muộn / về sớm: từ số phút này không báo trước thì không tính buổi');
        }
        if (filled($validated['late_deduction_per_minute'] ?? null)) {
            SystemSetting::set('payroll_late_deduction_per_minute', $validated['late_deduction_per_minute'], 'GV đi muộn / về sớm dưới ngưỡng không báo trước: trừ mỗi phút (đ)');
        }
        if (filled($validated['teaching_share_percent'] ?? null)) {
            SystemSetting::set('payroll_teaching_share_percent', $validated['teaching_share_percent'], 'Học thuật kiêm nhiệm giảng dạy: % học phí theo buổi mặc định');
        }

        activity('payroll_settings')->causedBy($request->user())
            ->withProperties(['after' => $validated])
            ->log('Cập nhật tham số tính lương');

        return redirect()->back()->with('status', 'Đã lưu tham số tính lương — áp dụng cho các lần tính/tính lại kỳ lương sau.');
    }

    public function teacherRates(Request $request)
    {
        $rates = TeacherRate::all();
        $teachers = $this->teachingStaff();
        $teacherId = $request->integer('teacher_id') ?: null;
        // Quản lý cơ sở chỉ thấy đơn giá riêng của nhân sự thuộc chi nhánh mình (xem staffInScope()).
        $viewer = $request->user();
        $scoped = ! DataScope::isAll($viewer, 'attendance_staff');
        $scopedIds = $scoped ? $this->staffInScope(User::withTrashed(), $viewer)->select('id') : null;
        abort_if($teacherId && $scoped && ! $this->staffInScope(User::withTrashed(), $viewer)->whereKey($teacherId)->exists(), 404);

        // Lịch sử chung của mọi GV (danh sách trên trang); lịch sử riêng GV đang xem nằm trong modal chi tiết (?teacher_id=).
        $history = TeacherHourlyRate::with(['user', 'creator'])
            ->when($scoped, fn ($q) => $q->whereIn('user_id', $scopedIds))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate($request->perPage(15))
            ->withQueryString();
        $teacherHistory = $teacherId
            ? TeacherHourlyRate::with('creator')->where('user_id', $teacherId)->orderByDesc('effective_from')->orderByDesc('id')->get()
            : collect();

        // "Đến ngày" của từng phiên bản = ngày trước phiên bản kế tiếp của cùng GV (null = hiện tại).
        $versionsByUser = TeacherHourlyRate::whereIn('user_id', $history->getCollection()->pluck('user_id')->push($teacherId)->filter()->unique())
            ->orderBy('effective_from')->orderBy('id')->get(['id', 'user_id', 'effective_from'])->groupBy('user_id');
        $endDates = [];
        foreach ($versionsByUser as $versions) {
            $versions = $versions->values();
            foreach ($versions as $i => $version) {
                $next = $versions->slice($i + 1)->first(fn ($v) => $v->effective_from->gt($version->effective_from));
                $endDates[$version->id] = $next?->effective_from->copy()->subDay();
            }
        }

        // Đơn giá đang hiệu lực hôm nay của từng GV (dòng effective_from gần nhất ≤ hôm nay).
        $currentRates = TeacherHourlyRate::whereDate('effective_from', '<=', now()->toDateString())
            ->when($scoped, fn ($q) => $q->whereIn('user_id', $scopedIds))
            ->orderBy('effective_from')
            ->get()
            ->keyBy('user_id');

        $selectedTeacher = $teacherId ? $teachers->firstWhere('id', $teacherId) : null;
        $selectedType = $selectedTeacher
            ? ($currentRates->get($selectedTeacher->id)?->teacher_type ?? TeacherHourlyRate::defaultTeacherType($selectedTeacher))
            : null;

        $unitSuffix = ['session' => 'VNĐ / buổi', 'hour' => 'VNĐ / giờ', TeacherHourlyRate::UNIT_TUITION => '% học phí / buổi'];
        $historyRow = function (TeacherHourlyRate $row) use ($endDates, $unitSuffix) {
            $end = $endDates[$row->id] ?? null;
            [$stateLabel, $stateColor] = $row->effective_from->isFuture()
                ? ['Chưa hiệu lực', 'info']
                : ($end && $end->lt(today()) ? ['Đã hết hạn', 'neutral'] : ['Đang áp dụng', 'success']);

            return [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'user_name' => $row->relationLoaded('user') ? ($row->user?->name ?? '—') : null,
                'teacher_type_label' => $row->teacher_type_label,
                'hourly_rate' => (float) $row->hourly_rate,
                'unit' => $unitSuffix[$row->rate_unit] ?? 'VNĐ / giờ',
                'unit_label' => $row->unit_label,
                'effective_from' => $row->effective_from->format('d/m/Y'),
                'end' => $end?->format('d/m/Y'),
                'state_label' => $stateLabel,
                'state_color' => $stateColor,
                'note' => $row->note,
                'creator' => $row->creator?->name ?? 'Hệ thống',
                'created_at' => $row->created_at?->format('d/m/Y H:i'),
            ];
        };
        $selectedCurrent = $selectedTeacher ? $currentRates->get($selectedTeacher->id) : null;

        return Inertia::render('Payroll/Config/Rates', [
            'rates' => $rates->map(fn (TeacherRate $r) => [
                'id' => $r->id,
                'rank_title' => $r->rank_title,
                'criteria' => $r->criteria,
                'communication_rate' => (float) $r->communication_rate,
                'ielts_rate' => (float) $r->ielts_rate,
            ])->values(),
            'teachers' => $teachers->map(function (User $t) use ($currentRates) {
                $current = $currentRates->get($t->id);

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'employee_code' => $t->employee_code,
                    'is_active' => (bool) $t->is_active,
                    'search' => Str::lower(Str::ascii($t->name.' '.$t->employee_code.' '.$t->email)),
                    'current' => $current ? [
                        'rate' => (float) $current->hourly_rate,
                        'unit_label' => $current->unit_label,
                        'effective_from' => $current->effective_from?->format('d/m/Y'),
                    ] : null,
                    'profile_rate' => (float) $t->hourly_rate,
                ];
            })->values(),
            'history' => $history->through($historyRow),
            'teacherHistory' => $teacherHistory->map($historyRow)->values(),
            'selectedTeacher' => $selectedTeacher ? [
                'id' => $selectedTeacher->id,
                'name' => $selectedTeacher->name,
                'employee_code' => $selectedTeacher->employee_code,
                'type_label' => TeacherHourlyRate::TEACHER_TYPES[$selectedType] ?? '—',
                'current' => $selectedCurrent ? [
                    'rate' => Money::format($selectedCurrent->hourly_rate, '').' '.($unitSuffix[$selectedCurrent->rate_unit] ?? 'VNĐ / giờ'),
                    'effective_from' => $selectedCurrent->effective_from->format('d/m/Y'),
                ] : null,
                'profile_rate' => (float) $selectedTeacher->hourly_rate > 0 ? Money::format((float) $selectedTeacher->hourly_rate) : null,
            ] : null,
            'selectedType' => $selectedType,
            'teacherTypes' => Ui::options(TeacherHourlyRate::TEACHER_TYPES),
            // Bậc đơn giá giờ Trợ giảng (TK-0010): TA chỉ chọn một trong các bậc, đơn vị luôn là giờ.
            'assistantTiers' => collect(PayrollPeriod::payrollSettings()['assistant_hourly_tiers'])
                ->map(fn ($t) => ['value' => (string) (int) $t, 'label' => Money::format($t, '').' đ/giờ'])->values()->all(),
            'defaultRate' => Money::format(TeacherTimesheet::DEFAULT_HOURLY_RATE),
            'today' => now()->format('d/m/Y'),
            'todayDate' => now()->toDateString(),
        ]);
    }

    /**
     * Thêm đơn giá riêng cho một GV từ một ngày hiệu lực. Không sửa dòng cũ:
     * mỗi lần đổi giá là một phiên bản mới để giữ lịch sử và tính đúng ca dạy cũ.
     */
    public function storePersonalTeacherRate(Request $request)
    {
        // Chỉ tạo đơn giá cho nhân sự trong phạm vi (Quản lý cơ sở: chi nhánh của mình) — xem staffInScope().
        abort_if(
            filled($request->input('user_id'))
                && ! DataScope::isAll($request->user(), 'attendance_staff')
                && ! $this->staffInScope(User::query(), $request->user())->whereKey((int) $request->input('user_id'))->exists(),
            404
        );
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            // Tiền (đ/buổi, đ/giờ) từ 1.000đ; "% học phí" (Học thuật kiêm nhiệm giảng dạy) từ 0,1 tới 100.
            'hourly_rate' => ['required', 'numeric', $request->input('rate_unit') === TeacherHourlyRate::UNIT_TUITION ? 'between:0.1,100' : 'min:1000'],
            // Q3 Part-time: mặc định đơn giá theo BUỔI; 'hour' giữ cho trường hợp cũ; 'tuition' = % học phí theo buổi đã dạy.
            'rate_unit' => ['nullable', 'in:session,hour,'.TeacherHourlyRate::UNIT_TUITION],
            'teacher_type' => ['nullable', 'in:'.implode(',', array_keys(TeacherHourlyRate::TEACHER_TYPES))],
            'effective_from' => [
                'required', 'date',
                function ($attribute, $value, $fail) use ($request) {
                    $exists = TeacherHourlyRate::where('user_id', $request->input('user_id'))
                        ->whereDate('effective_from', Carbon::parse($value)->toDateString())->exists();
                    if ($exists) {
                        $fail('Giáo viên đã có đơn giá hiệu lực từ ngày này — chọn ngày hiệu lực khác để tạo phiên bản mới.');
                    } elseif (PayrollPeriod::isLockedFor(Carbon::parse($value))) {
                        // Kỳ đã khóa không tính lại → đơn giá mới sẽ không khớp phiếu lương đã chi.
                        $fail(PayrollPeriod::lockedMessage($value));
                    }
                },
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'hourly_rate.between' => '% học phí phải từ 0,1 đến 100.',
        ]);

        $validated['rate_unit'] ??= TeacherHourlyRate::UNIT_HOUR;
        $rateUser = User::findOrFail($validated['user_id']);
        $validated['teacher_type'] ??= TeacherHourlyRate::defaultTeacherType($rateUser);
        // Trợ giảng (TK-0010): luôn tính theo GIỜ, mức đơn giá là một trong các bậc ở Tham số tính lương. Nhân sự lên bảng
        // lương là Trợ giảng thì đơn giá luôn theo luật TA, dù chọn loại giáo viên nào.
        if (app(PayrollFormulaService::class)->profile($rateUser)['salary_role'] === Roles::ASSISTANT) {
            $validated['teacher_type'] = 'assistant';
        }
        if ($validated['teacher_type'] === 'assistant') {
            $tiers = PayrollPeriod::payrollSettings()['assistant_hourly_tiers'];
            if (! in_array((float) $validated['hourly_rate'], $tiers, true)) {
                throw ValidationException::withMessages(['hourly_rate' => 'Đơn giá Trợ giảng phải là một trong các bậc '
                    .collect($tiers)->map(fn ($t) => Money::format($t, ''))->implode(' / ').' đ/giờ.']);
            }
            $validated['rate_unit'] = TeacherHourlyRate::UNIT_HOUR;
        }
        $rate = TeacherHourlyRate::create($validated + ['created_by' => $request->user()->id]);

        activity('teacher_rate')->causedBy($request->user())->performedOn($rate)
            ->withProperties(['new' => $validated])
            ->log('Thêm đơn giá riêng ('.$rate->unit_label.') cho GV #'.$validated['user_id']);

        return redirect()->route('payroll.config.teacher-rates', ['teacher_id' => $validated['user_id']])
            ->with('status', 'Đã thêm đơn giá '.($rate->isTuitionShare() ? rtrim(rtrim(number_format((float) $validated['hourly_rate'], 2, ',', '.'), '0'), ',') : Money::format($validated['hourly_rate'], '')).' '.$rate->unit_label.' hiệu lực từ '
                .Carbon::parse($validated['effective_from'])->format('d/m/Y').'.');
    }

    /** Nhân sự giảng dạy đang hoạt động trong phạm vi người xem (dùng cho chấm công tay, lọc chấm công và đơn giá GV). */
    private function teachingStaff()
    {
        return $this->staffInScope(User::query(), Auth::user())
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [...Roles::TEACHING, Roles::ACADEMIC_LEAD, Roles::MANAGER]))
            ->orderBy('name')->get();
    }

    /**
     * Nhân sự người xem được thấy trên chấm công GV / đơn giá GV — theo phạm vi "attendance_staff" (Chấm công nhân sự:
     * đơn giá là giá của chính các ca chấm công đó, nên dùng chung phạm vi để màn đơn giá và màn chấm công khớp nhau):
     * toàn hệ thống → mọi người; mức khác → nhân sự có chi nhánh chính / chi nhánh được cấp thêm thuộc chi nhánh của tôi + chính tôi.
     */
    private function staffInScope(\Illuminate\Database\Eloquent\Builder $query, User $viewer): \Illuminate\Database\Eloquent\Builder
    {
        $branchIds = DataScope::branchIds($viewer, 'attendance_staff');

        return $query->when($branchIds !== null, fn ($q) => $q->where(fn ($s) => $s->whereKey($viewer->id)
            ->orWhereIn('branch_id', $branchIds)
            ->orWhereHas('branches', fn ($b) => $b->whereIn('branches.id', $branchIds))));
    }

    public function storeTeacherRate(Request $request)
    {
        $validated = $request->validate([
            'rank_title' => 'required|string',
            'criteria' => 'nullable|string',
            'communication_rate' => 'required|numeric',
            'ielts_rate' => 'required|numeric',
        ]);

        TeacherRate::create($validated);

        return redirect()->back()->with('status', 'Đã lưu đơn giá giờ dạy mới!');
    }

    public function commissionTiers(Request $request)
    {
        $asOf = $request->filled('as_of') ? Carbon::parse($request->query('as_of')) : today();
        $tiers = CommissionTier::byStudents()->effectiveAt($asOf)->orderBy('min_students')->get();
        $history = CommissionTier::with(['creator', 'replaces'])
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->paginate($request->perPage(15))
            ->withQueryString();

        $tab = $request->query('tab') === 'renewal' ? 'renewal' : 'commission';
        $settings = PayrollPeriod::payrollSettings();
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',').'%';

        return Inertia::render('Payroll/Config/Commissions', [
            'tab' => $tab,
            'asOf' => ['label' => $asOf->format('d/m/Y'), 'date' => $asOf->toDateString()],
            'tiers' => $tiers->map(fn (CommissionTier $tier) => [
                'id' => $tier->id,
                'tier_name' => $tier->tier_name,
                'min_students' => (int) $tier->min_students,
                'max_students' => $tier->max_students !== null ? (int) $tier->max_students : null,
                'new_sale_percent' => $tier->new_sale_percent,
                'percent_label' => $pct($tier->new_sale_percent),
                'effective_from' => $tier->effective_from?->format('d/m/Y') ?? 'Từ đầu',
                'current' => $tier->effective_to === null,
            ])->values(),
            'history' => $history->through(fn (CommissionTier $version) => [
                'id' => $version->id,
                'tier_name' => $version->tier_name,
                'replaces' => $version->replaces?->tier_name,
                'range' => $version->student_range_label,
                'percent_label' => $pct($version->new_sale_percent),
                'effective_from' => $version->effective_from?->format('d/m/Y') ?? 'Từ đầu',
                'effective_to' => $version->effective_to?->format('d/m/Y') ?? 'nay',
                'current' => $version->effective_to === null,
                'creator' => $version->creator?->name ?? '—',
            ]),
            'gateDays' => config('payroll.commission.gate_days'),
            'gateMilestones' => config('payroll.commission.gate_milestones'),
            'today' => now()->toDateString(),
            'tomorrow' => now()->addDay()->toDateString(),
            'renewalRows' => collect($settings['renewal_table'])
                ->map(fn ($row, $quits) => ['quits' => $quits, 'percent' => $row['percent'], 'pending' => (bool) $row['pending']])
                ->values()->all(),
            'renewalBeyond' => rtrim(rtrim(number_format((float) $settings['renewal_beyond_percent'], 2, '.', ''), '0'), '.'),
        ]);
    }

    /**
     * Luật validate chung cho bậc hoa hồng (A6 bản sửa): bậc theo SỐ HS CHỐT trong kỳ.
     * min_revenue / renew_percent / bonus_amount không còn dùng trong tính lương.
     */
    private function commissionTierRules(): array
    {
        return [
            // Mockup không có ô tên bậc: bỏ trống thì tự đặt theo ngưỡng số HS.
            'tier_name' => 'nullable|string|max:255',
            'min_students' => 'required|integer|min:0',
            'max_students' => 'nullable|integer|gte:min_students',
            'min_revenue' => 'nullable|numeric|min:0',
            'max_revenue' => 'nullable|numeric|gt:min_revenue',
            'new_sale_percent' => 'required|numeric|min:0|max:100',
            'renew_percent' => 'nullable|numeric|min:0|max:100',
            'bonus_amount' => 'nullable|numeric|min:0',
            'effective_from' => 'nullable|date',
        ];
    }

    private function tierName(array $validated): string
    {
        if (filled($validated['tier_name'] ?? null)) {
            return trim($validated['tier_name']);
        }
        $max = $validated['max_students'] ?? null;

        $from = max(1, (int) $validated['min_students']);

        return $max !== null && $max !== '' ? 'Mốc HS thứ '.$from.'–'.$max : 'Mốc từ HS thứ '.$from;
    }

    /**
     * Tab "Thưởng tái tục" của màn Mốc hoa hồng & thưởng tái tục: bảng % doanh thu lớp theo số HS nghỉ trong kỳ
     * (A6) + mức khi nghỉ nhiều hơn bảng; các mốc chưa được BA chốt gắn cờ "chờ BA". Áp dụng cho lần tính / tính lại sau.
     */
    public function storeRenewalTable(Request $request)
    {
        $validated = $request->validate([
            'renewal' => ['required', 'array', 'min:1', 'max:20'],
            'renewal.*.quits' => ['required', 'integer', 'min:0', 'max:50', 'distinct'],
            'renewal.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'renewal.*.pending' => ['nullable', 'boolean'],
            'renewal_beyond_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'renewal.required' => 'Cần ít nhất một mốc thưởng tái tục.',
            'renewal.*.quits.distinct' => 'Mỗi mức số HS nghỉ chỉ được khai báo một lần.',
        ]);

        $before = PayrollPeriod::payrollSettings()['renewal_table'];
        $table = collect($validated['renewal'])
            ->mapWithKeys(fn ($row) => [(int) $row['quits'] => ['percent' => (float) $row['percent'], 'pending' => (bool) ($row['pending'] ?? false)]])
            ->sortKeys()->all();
        SystemSetting::set('payroll_renewal_table', $table, 'Thưởng tái tục: % doanh thu lớp theo số HS nghỉ trong kỳ');
        if (($validated['renewal_beyond_percent'] ?? null) !== null) {
            SystemSetting::set('payroll_renewal_beyond_percent', $validated['renewal_beyond_percent'], 'Thưởng tái tục khi số HS nghỉ vượt bảng (%)');
        }

        activity('payroll_settings')->causedBy($request->user())
            ->withProperties(['before' => $before, 'after' => $table])
            ->log('Cập nhật bảng thưởng tái tục');

        return redirect()->route('payroll.config.commission-tiers', ['tab' => 'renewal'])
            ->with('status', 'Đã lưu bảng thưởng tái tục — áp dụng cho các lần tính / tính lại kỳ lương sau.');
    }

    public function storeCommissionTier(Request $request)
    {
        $validated = $request->validate($this->commissionTierRules());
        $validated['tier_name'] = $this->tierName($validated);
        $validated['effective_from'] ??= today()->toDateString();
        $validated['min_revenue'] ??= 0;
        $validated['renew_percent'] ??= 0;
        $validated['bonus_amount'] ??= 0;

        $tier = CommissionTier::create($validated + ['created_by' => $request->user()->id]);

        activity('commission_tier')->causedBy($request->user())->performedOn($tier)
            ->withProperties(['new' => $validated])
            ->log('Tạo mới bậc hoa hồng: '.$tier->tier_name);

        return redirect()->back()->with('status', 'Đã lưu mốc hoa hồng mới, hiệu lực từ '.Carbon::parse($validated['effective_from'])->format('d/m/Y').'!');
    }

    /**
     * Sửa bậc = tạo PHIÊN BẢN MỚI có hiệu lực từ ngày chọn và đóng phiên bản cũ vào hôm trước,
     * không ghi đè — kỳ lương cũ tính lại vẫn ra đúng mốc đã áp dụng.
     */
    public function updateCommissionTier(Request $request, CommissionTier $commissionTier)
    {
        $validated = $request->validate($this->commissionTierRules());
        $validated['tier_name'] = $this->tierName($validated);
        abort_if($commissionTier->effective_to !== null, 422, 'Phiên bản này đã hết hiệu lực — hãy sửa phiên bản đang hiệu lực.');

        $effectiveFrom = Carbon::parse($validated['effective_from'] ?? today())->startOfDay();
        $validated['effective_from'] = $effectiveFrom->toDateString();
        $validated['min_revenue'] ??= 0;
        $validated['renew_percent'] ??= (float) $commissionTier->renew_percent;
        $validated['bonus_amount'] ??= 0;
        $before = $commissionTier->only(['tier_name', 'min_students', 'max_students', 'new_sale_percent', 'effective_from']);

        // Phiên bản chưa tới ngày hiệu lực (chưa từng áp dụng) thì được sửa trực tiếp.
        if ($commissionTier->effective_from !== null && $commissionTier->effective_from->isAfter(today())
            && $effectiveFrom->equalTo($commissionTier->effective_from)) {
            $commissionTier->update($validated);
            $version = $commissionTier;
        } else {
            if ($commissionTier->effective_from !== null && ! $effectiveFrom->isAfter($commissionTier->effective_from)) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Ngày hiệu lực mới phải sau ngày hiệu lực của phiên bản hiện tại ('.$commissionTier->effective_from->format('d/m/Y').').',
                ]);
            }

            $version = DB::transaction(function () use ($commissionTier, $validated, $effectiveFrom, $request) {
                $commissionTier->update(['effective_to' => $effectiveFrom->copy()->subDay()->toDateString()]);

                return CommissionTier::create($validated + [
                    'replaces_id' => $commissionTier->id,
                    'created_by' => $request->user()->id,
                ]);
            });
        }

        activity('commission_tier')->causedBy($request->user())->performedOn($version)
            ->withProperties(['before' => $before, 'after' => $validated])
            ->log('Cập nhật bậc hoa hồng: '.$version->tier_name.' (phiên bản mới hiệu lực từ '.$effectiveFrom->format('d/m/Y').')');

        return redirect()->back()->with('status', 'Đã tạo phiên bản mới của mốc hoa hồng, hiệu lực từ '.$effectiveFrom->format('d/m/Y').' — các kỳ trước giữ nguyên mốc cũ.');
    }

    /**
     * Ngừng áp dụng một bậc: đóng hiệu lực (giữ lịch sử). Phiên bản chưa từng có hiệu lực thì xoá hẳn.
     */
    public function destroyCommissionTier(CommissionTier $commissionTier, Request $request)
    {
        $name = $commissionTier->tier_name;

        if ($commissionTier->effective_from !== null && $commissionTier->effective_from->isAfter(today())) {
            $commissionTier->delete();
            $message = 'Đã xoá mốc hoa hồng chưa có hiệu lực: '.$name;
        } else {
            $commissionTier->update(['effective_to' => today()->subDay()->toDateString()]);
            $message = 'Đã ngừng áp dụng mốc hoa hồng '.$name.' từ hôm nay (giữ lại lịch sử).';
        }

        activity('commission_tier')->causedBy($request->user())
            ->withProperties(['retired_tier' => $name])
            ->log('Ngừng áp dụng bậc hoa hồng: '.$name);

        return redirect()->back()->with('status', $message);
    }

    /**
     * Phiếu lương cá nhân: chỉ các kỳ đã gửi GV xem/đã trả (bản nháp có thể còn thay đổi).
     */
    public function mySalary(Request $request)
    {
        $user = Auth::user();
        $records = PayrollRecord::with('period')
            ->where('user_id', $user->id)
            ->whereHas('period', fn ($query) => $query->whereIn('status', PayrollPeriod::LOCKED_STATUSES))
            ->get()
            ->sortByDesc(fn (PayrollRecord $record) => $record->period->start_date)
            ->values();

        $record = $request->filled('period_id')
            ? $records->firstWhere('payroll_period_id', (int) $request->query('period_id'))
            : $records->first();
        abort_if($request->filled('period_id') && $record === null, 404);

        // Căn cứ hiển thị theo mockup "Lương của tôi": buổi dạy hợp lệ của kỳ, biên bản phạt đã trừ, so sánh tháng trước.
        $timesheets = collect();
        $penalties = collect();
        $previous = null;
        if ($record) {
            $period = $record->period;
            $timesheets = TeacherTimesheet::with('classModel')
                ->where('user_id', $user->id)
                ->whereBetween('teaching_date', [$period->start_date, $period->end_date])
                ->where('status', 'valid')
                ->orderBy('teaching_date')->get();
            $penalties = Penalty::withoutViewerScope()->with('classModel')->where('payroll_record_id', $record->id)->orderBy('violation_date')->get();
            $previous = $records->first(fn (PayrollRecord $r) => $r->period->start_date->lt($period->start_date));
        }
        $variant = $record ? self::payslipVariant($record) : null;

        return Inertia::render('Payroll/MySalary', [
            'record' => $record ? $this->mySalaryRecord($record, $previous) : null,
            'periodOptions' => $records->map(fn (PayrollRecord $option) => [
                'value' => $option->payroll_period_id,
                'label' => 'Tháng '.str_pad($option->period->month, 2, '0', STR_PAD_LEFT).'/'.$option->period->year,
            ])->values(),
            'penalties' => $penalties->map(fn (Penalty $pen) => [
                'id' => $pen->id,
                'code' => $pen->code,
                'violation_type' => $pen->violation_type,
                'violation_date' => $pen->violation_date->format('d/m'),
                'class' => $pen->classModel ? ($pen->classModel->code ?? $pen->classModel->name) : null,
                'amount' => (float) $pen->amount,
            ])->values(),
            'timesheets' => $timesheets->map(function (TeacherTimesheet $ts) use ($user) {
                $pay = $ts->sessionPay($user);

                return [
                    'id' => $ts->id,
                    'date' => $ts->teaching_date->format('d/m/Y'),
                    'time' => $ts->scheduled_time ? str_replace('-', ' - ', $ts->scheduled_time) : trim(($ts->checkin_time ?? '').($ts->checkout_time ? ' - '.$ts->checkout_time : '')),
                    'class' => $ts->classModel?->code ?? $ts->classModel?->name,
                    'type' => $ts->type,
                    'type_label' => $ts->type_label,
                    'rate' => (float) $pay['rate'],
                    'unit' => $pay['unit'],
                    'amount' => (float) $pay['amount'],
                ];
            })->values(),
            'printHtml' => $record ? view('payroll.partials.payslip-print', ['record' => $record, 'period' => $record->period, 'variant' => $variant])->render() : null,
        ]);
    }

    private function rejectLockedDate(string $field, $date): RedirectResponse
    {
        $message = PayrollPeriod::lockedMessage($date);

        return redirect()->back()->withInput()->withErrors([$field => $message])->with('error', $message);
    }

    public function appsheetTimesheet(): InertiaResponse
    {
        return Inertia::render('Payroll/Timesheets/AppSheet');
    }

    /** Thông tin kỳ lương dùng chung cho các trang Vue (bảng lương, khối, phiếu lương). */
    private function periodData(PayrollPeriod $period): array
    {
        return [
            'id' => $period->id,
            'code' => $period->code,
            'title' => $period->title,
            'month' => (int) $period->month,
            'year' => (int) $period->year,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'status' => $period->status,
            'status_label' => $period->status_label,
            'status_badge' => $period->status_badge,
            'locked' => $period->isLocked(),
            'frozen' => $period->isFrozen(),
            'transitions' => $period->loadMissing('transitions.user')->transitions->sortBy('id')->values()
                ->map(fn ($transition) => [
                    'from_status' => $transition->from_status,
                    'to_status' => $transition->to_status,
                    'status_label' => PayrollPeriod::STATUS_LABELS[$transition->to_status] ?? $transition->to_status,
                    'user_name' => $transition->user?->name,
                    'created_at' => $transition->created_at?->toIso8601String(),
                ])->all(),
            'lifecycle_steps' => collect(PayrollPeriod::STATUS_LABELS)->map(function ($label, $status) use ($period) {
                $last = $period->transitions->where('to_status', $status)->sortByDesc('id')->first();

                return ['key' => $status, 'label' => $label, 'created_at' => $last?->created_at?->toIso8601String(), 'user_name' => $last?->user?->name];
            })->values()->all(),
            'actions' => app(PayrollPeriodLifecycle::class)->actions($period, Auth::user()),
        ];
    }

    /** Dữ liệu phiếu lương cho màn "Lương của tôi": tổng thu nhập / khoản trừ / thực nhận, so sánh kỳ trước, các dòng kèm số lượng. */
    private function mySalaryRecord(PayrollRecord $record, ?PayrollRecord $previous): array
    {
        $incomeTotal = (float) $record->gross_income;
        $change = $previous && (float) $previous->gross_income > 0
            ? round(($incomeTotal - (float) $previous->gross_income) / (float) $previous->gross_income * 100)
            : null;
        $quantity = fn (array $line) => match ($line['key']) {
            'teaching_salary' => $record->usesQ3Formula() ? (int) $record->teaching_sessions.' buổi' : rtrim(rtrim(number_format((float) $record->actual_hours, 2, '.', ''), '0'), '.').' giờ',
            'kpi_bonus' => $record->kpi_source === 'retention'
                ? (int) $record->retention_students.'/'.(int) $record->retention_base_students.' HS'
                : ($record->kpi_score !== null ? rtrim(rtrim(number_format((float) $record->kpi_score, 2), '0'), '.').'%' : '-'),
            'foreign_session_pay' => (int) $record->foreign_teacher_sessions_count.' buổi',
            'commission_bonus' => (int) $record->commission_closed_count.' HS chốt',
            'base_salary' => $record->hasWorkdays()
                ? PayrollRecord::number(data_get($record->calculation_details, 'workdays.paid_days', 0)).'/'.(int) data_get($record->calculation_details, 'workdays.standard_days', 0).' công'
                : '-',
            default => '-',
        };

        return [
            'id' => $record->id,
            'payroll_period_id' => $record->payroll_period_id,
            'period_status' => $record->period->status,
            'period_status_label' => $record->period->status_label,
            'period_title' => $record->period->title,
            'gross_income' => $incomeTotal,
            'total_deductions' => (float) $record->total_deductions,
            'net_salary' => (float) $record->net_salary,
            'change' => $change,
            'is_full_time' => $record->isFullTime(),
            'adjustment_notes' => $record->adjustment_notes,
            'earning_lines' => collect($record->earningLines())->map(fn (array $line) => [
                'key' => $line['key'],
                'label' => $line['label'],
                'hint' => $line['hint'] ?? null,
                'quantity' => $quantity($line),
                'amount' => (float) $line['amount'],
            ])->values()->all(),
            'deduction_lines' => collect($record->deductionLines())->map(fn (array $line) => [
                'key' => $line['key'],
                'label' => $line['label'],
                'hint' => $line['hint'] ?? null,
                'amount' => (float) $line['amount'],
            ])->values()->all(),
        ];
    }

    /** Một dòng bảng lương (bảng lương của kỳ, bảng theo khối): chỉ các cột hiển thị. */
    /**
     * Phiếu KPI giảng dạy (bộ GV part-time) của Học thuật kiêm nhiệm cho kỳ lương: xếp loại, tỉ lệ đạt, trạng thái, link mở
     * phiếu. Tham chiếu cho người chấm khi chọn bậc KPI kiêm nhiệm.
     *
     * @return array<string, mixed>|null
     */
    private function teachingKpiSheet(PayrollRecord $record, PayrollPeriod $period): ?array
    {
        $user = $record->user;
        if (! $user || ! KpiSheetService::hasTeachingSheet($user)) {
            return null;
        }
        $track = KpiEvaluation::TRACK_TEACHING;
        $evaluation = KpiSheetService::evaluationFor($user, (int) $period->month, (int) $period->year, $track);
        $sheet = app(KpiSheetService::class)->sheet($user, (int) $period->month, (int) $period->year, $evaluation, $track);
        $status = $evaluation?->status ?? KpiEvaluation::STATUS_PENDING;

        return [
            'period_label' => $sheet['period']['label'],
            'rate' => (float) $sheet['total'],
            'grade' => $sheet['grade'] ? $sheet['grade']['grade'].' · '.$sheet['grade']['label'] : null,
            'status_label' => KpiEvaluation::STATUS_LABELS[$status] ?? $status,
            'status_color' => KpiEvaluation::STATUS_COLORS[$status] ?? 'neutral',
            'url' => Auth::user()?->can('kpi.view')
                ? route('kpi.evaluate', ['userId' => $user->id, 'period' => sprintf('%04d-%02d', $period->year, $period->month), 'track' => $track], false)
                : null,
        ];
    }

    private function recordRow(PayrollRecord $r): array
    {
        return [
            'id' => $r->id,
            'user_id' => $r->user_id,
            'name' => $r->user?->name,
            'email' => $r->user?->email,
            'employee_code' => $r->user?->employee_code,
            'is_part_time' => $r->isPartTime(),
            'employee_type_label' => $r->employee_type_label,
            'salary_role_label' => $r->salary_role_label,
            'kpi_state' => $r->kpi_state,
            'kpi_source' => $r->kpi_source,
            'kpi_score' => $r->kpi_score !== null ? (float) $r->kpi_score : null,
            'kpi_manual_amount' => $r->kpi_manual_amount !== null ? (float) $r->kpi_manual_amount : null,
            'kpi_bonus' => (float) $r->kpi_bonus,
            'retention_students' => (int) $r->retention_students,
            'retention_tier' => $r->retention_tier !== null ? (float) $r->retention_tier : null,
            'base_salary' => (float) $r->base_salary,
            // Lương cơ bản thực trả theo ngày công (phiếu cũ: = lương cơ bản).
            'base_salary_earned' => $r->base_salary_earned,
            'is_assistant' => $r->isAssistant(),
            'late_deduction' => (float) $r->late_deduction,
            'teaching_salary' => (float) $r->teaching_salary,
            'teaching_sessions' => (int) $r->teaching_sessions,
            'teaching_concurrent' => (bool) $r->teaching_concurrent,
            'teaching_kpi_bonus' => (float) $r->teaching_kpi_bonus,
            'foreign_session_pay' => (float) $r->foreign_session_pay,
            'foreign_teacher_sessions_count' => (int) $r->foreign_teacher_sessions_count,
            'adjustment_notes' => $r->adjustment_notes,
            'commission_bonus' => (float) $r->commission_bonus,
            'commission_deferred' => (float) $r->commission_deferred,
            'renew_bonus' => (float) $r->renew_bonus,
            'free_allowance' => (float) $r->allowance + (float) $r->other_bonus,
            'insurance_deduction' => (float) $r->insurance_deduction,
            'union_deduction' => (float) $r->union_deduction,
            'tax_deduction' => (float) $r->tax_deduction,
            'assistant_rate_missing' => $r->assistantRateMissing(),
            'other_deductions' => (float) $r->penalty_deduction + (float) $r->late_deduction + (float) $r->missing_punch_deduction + (float) $r->commission_clawback + (float) $r->other_deduction + (float) $r->foreign_teacher_deduction,
            'net_salary' => (float) $r->net_salary,
            'shortfall' => $r->netShortfall(),
            'raw_net' => $r->rawNetSalary(),
        ];
    }
}
