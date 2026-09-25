<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\CommissionAdjustment;
use App\Models\CommissionItem;
use App\Models\CommissionTier;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\Student;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherRate;
use App\Models\TeacherTimesheet;
use App\Models\TimesheetSyncLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PayrollFormulaService;
use App\Services\SalesCommissionService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller
{
    public function periods(Request $request)
    {
        $currentUser = auth()->user();
        abort_if($currentUser && ($currentUser->hasRole('student') || $currentUser->hasRole('teacher') || $currentUser->hasRole('academic_lead')), 403);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        $periods = PayrollPeriod::withCount('records')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhereHas('records.user', fn ($u) => $u->where('name', 'like', "%{$search}%"))))
            ->when(in_array($status, ['draft', 'reviewing', 'approved', 'paid'], true), fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(12)
            ->withQueryString();
        $allPeriods = PayrollPeriod::latest()->get(['id', 'code', 'title']);

        return view('payroll.periods', compact('periods', 'allPeriods', 'search', 'status'));
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
            'status' => 'draft',
            'total_staff' => 0,
            'total_hours' => 0,
            'total_amount' => 0,
        ]);

        $period->calculatePayrollForPeriod();

        return redirect()->route('payroll.periods.show', $period->id)
            ->with('status', "Đã khởi tạo và tự động tính toán bảng lương {$period->title} từ dữ liệu chấm công & hoa hồng!");
    }

    public function showPeriod($id)
    {
        $period = PayrollPeriod::with(['records.user'])->where('id', $id)->orWhere('code', $id)->firstOrFail();

        return view('payroll.show', compact('period'));
    }

    /**
     * Xuất bảng lương của kỳ ra Excel (.xlsx) hoặc CSV; lọc theo khối (department) nếu có.
     */
    public function exportPeriod(Request $request, $id)
    {
        $period = PayrollPeriod::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $department = $request->query('department');
        $departments = ['teacher' => 'Giáo viên', 'fulltime' => 'GV Full-time', 'academic' => 'Học thuật', 'operations' => 'Vận hành'];

        $records = $period->records()->with('user')
            ->when(is_string($department) && $department !== '', fn ($q) => $q->where('department', $department))
            ->orderBy('department')->orderBy('id')
            ->get();

        $rows = $records->map(fn (PayrollRecord $r) => [
            $r->user?->name ?? 'Chưa cập nhật',
            $r->user?->email,
            $departments[$r->department] ?? $r->department,
            $r->employee_type_label,
            (float) $r->base_salary,
            (int) $r->teaching_sessions,
            (float) $r->teaching_salary,
            (float) $r->kpi_bonus,
            (float) $r->foreign_session_pay,
            (float) $r->commission_bonus,
            (float) $r->commission_deferred,
            (float) $r->renew_bonus,
            (float) $r->allowance + (float) $r->other_bonus,
            (float) $r->insurance_deduction,
            (float) $r->union_deduction,
            (float) $r->tax_deduction,
            (float) $r->penalty_deduction,
            (float) $r->commission_clawback,
            (float) $r->other_deduction + (float) $r->foreign_teacher_deduction,
            (float) $r->net_salary,
        ])->all();

        return \App\Exports\ArrayExport::download(
            'bang-luong-'.\Illuminate\Support\Str::slug($period->code ?: $period->id).($department ? '-'.$department : ''),
            ['Nhân sự', 'Email', 'Khối', 'Loại', 'Lương cơ bản', 'Số buổi', 'Lương buổi dạy', 'KPI', 'Buổi có GVNN (chờ BA)', 'Hoa hồng', 'Hoa hồng hoãn', 'Thưởng tái tục', 'Phụ cấp / cộng khác', 'BHXH', 'Công đoàn', 'Thuế TNCN', 'Phạt', 'Thu hồi hoa hồng', 'Khấu trừ khác', 'Thực lĩnh'],
            $rows,
            $request->query('format', 'xlsx')
        );
    }

    public function approvePeriod($id)
    {
        $period = PayrollPeriod::where('id', $id)->orWhere('code', $id)->firstOrFail();
        abort_if($period->isLocked(), 422, 'Kỳ lương đã khóa.');

        if ($period->hasChangesSinceCalculation()) {
            $message = 'Chấm công hoặc biên bản phạt của kỳ đã thay đổi sau lần tính gần nhất — vui lòng bấm "Đồng bộ & Tính lại" trước khi duyệt.';

            return redirect()->back()->withErrors(['period' => $message])->with('error', $message);
        }

        DB::transaction(function () use ($period) {
            $period->update(['status' => 'approved']);
            $period->records()->update(['status' => 'confirmed']);

            // Chỉ đóng dấu "deducted" các biên bản thực sự đã trừ vào bản ghi lương của kỳ này
            Penalty::whereIn('payroll_record_id', $period->records()->select('id'))
                ->whereIn('status', Penalty::payableStatuses())
                ->update(['status' => 'deducted']);

            // Thu hồi hoa hồng đã trừ trong kỳ → tất toán, không trừ lại ở kỳ sau
            CommissionAdjustment::whereIn('payroll_record_id', $period->records()->select('id'))
                ->whereNull('settled_at')
                ->update(['settled_at' => now()]);

            // Khoản hoa hồng đạt gate kép được trả trong kỳ → đã trả (khoản còn hoãn chờ kỳ sau)
            CommissionItem::whereIn('payroll_record_id', $period->records()->select('id'))
                ->whereNull('settled_at')
                ->update(['settled_at' => now(), 'status' => CommissionItem::STATUS_PAID]);
        });

        return redirect()->back()->with('status', "Đã phê duyệt bảng lương {$period->title}!");
    }

    /**
     * Đánh dấu kỳ lương đã chi trả (chốt sổ sau khi duyệt và chuyển tiền).
     */
    public function markPaid($id)
    {
        $period = PayrollPeriod::where('id', $id)->orWhere('code', $id)->firstOrFail();
        abort_if($period->status !== 'approved', 422, 'Chỉ kỳ lương đã duyệt mới được đánh dấu đã chi trả.');
        $period->update(['status' => 'paid']);
        $period->records()->update(['status' => 'paid']);

        return redirect()->back()->with('status', "Đã ghi nhận chi trả bảng lương {$period->title}!");
    }

    public function calculatePeriod($id)
    {
        $period = PayrollPeriod::where('id', $id)->orWhere('code', $id)->firstOrFail();
        abort_if($period->isLocked(), 422, 'Không thể tính lại kỳ lương đã duyệt hoặc đã chi trả.');

        try {
            $period->calculatePayrollForPeriod();
        } catch (LockTimeoutException) {
            return redirect()->back()->with('error', 'Kỳ lương đang được tính bởi phiên khác — vui lòng thử lại sau ít phút.');
        }

        return redirect()->back()->with('status', "Đã đồng bộ và tính toán lại bảng lương {$period->title} từ Chấm công, KPI và Phạt!");
    }

    /**
     * Dòng "Buổi có GVNN" (Q3 Part-time — chờ BA chốt cách tính): Kế toán nhập tay số tiền cộng cho GV,
     * hệ thống chỉ gợi ý số buổi có GVNN cùng lớp. Thay cho quy tắc cũ "trừ 50.000đ/buổi có GVNN".
     */
    public function updateRecord(Request $request, $id)
    {
        $record = PayrollRecord::with('period')->findOrFail($id);
        abort_if($record->isLocked(), 422, 'Không thể sửa kỳ lương đã khóa.');

        $validated = $request->validate([
            'foreign_session_pay' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $before = $record->only(['foreign_session_pay', 'net_salary']);
        $record->foreign_session_pay = $validated['foreign_session_pay'];
        if (filled($validated['notes'] ?? null)) {
            $record->adjustment_notes = $validated['notes'];
        }
        $record->applyManualInputs();
        $record->save();
        $record->period->refreshTotals();

        activity('payroll_record')->causedBy($request->user())->performedOn($record)
            ->withProperties(['before' => $before, 'after' => $record->only(array_keys($before))])
            ->log('Nhập lương buổi có GVNN cho '.$record->user?->name.' — '.$record->period->title);

        return redirect()->back()->with('status', 'Đã lưu lương buổi có GVNN và tính lại thực lĩnh.');
    }

    /**
     * Phiếu lương một người: toàn bộ khoản cộng / khoản trừ của một bản ghi lương theo loại nhân sự,
     * kèm căn cứ (buổi dạy, HS giữ được, KPI, hoa hồng trả / hoãn, thưởng tái tục, phạt, thu hồi).
     */
    public function showRecord(int $id)
    {
        $record = PayrollRecord::with(['user.roles', 'period'])->findOrFail($id);
        $period = $record->period;

        $timesheets = TeacherTimesheet::with('classModel')
            ->where('user_id', $record->user_id)
            ->whereBetween('teaching_date', [$period->start_date, $period->end_date])
            ->where('status', 'valid')
            ->orderBy('teaching_date')
            ->get();
        $penalties = Penalty::where('payroll_record_id', $record->id)->orderBy('due_date')->get();
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

        return view('payroll.record-show', compact(
            'record', 'period', 'timesheets', 'penalties', 'clawbacks', 'commissionReceipts',
            'paidCommission', 'deferredCommission', 'lostStudents', 'settings'
        ));
    }

    /**
     * Kế toán / Admin nhập các khoản tay trên phiếu lương khi kỳ chưa duyệt — được giữ khi "Đồng bộ & Tính lại":
     * bậc KPI giữ HS (Part-time), KPI tự do (GV Full-time, Học thuật, Sale, khác), buổi có GVNN (Part-time, chờ BA),
     * thuế TNCN (Full-time), các dòng phụ cấp / thưởng / khấu trừ tự do có tên, ghi chú.
     * Hoa hồng và KPI Học vụ tự tính, không sửa tay.
     */
    public function adjustRecord(Request $request, int $id)
    {
        $record = PayrollRecord::with('period')->findOrFail($id);
        abort_if($record->isLocked(), 422, 'Không thể sửa phiếu lương của kỳ đã duyệt/đã chi trả.');

        $tiers = PayrollPeriod::payrollSettings()['retention_tiers'];
        $validated = $request->validate([
            'retention_tier' => ['nullable', 'numeric', function ($attribute, $value, $fail) use ($tiers) {
                if ($value !== null && $value !== '' && ! in_array((float) $value, $tiers, true)) {
                    $fail('Bậc KPI giữ học sinh phải là một trong: '.implode(' / ', array_map(fn ($t) => number_format($t, 0, ',', '.').'đ', $tiers)).'.');
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

        $before = $record->only(['retention_tier', 'kpi_manual_amount', 'foreign_session_pay', 'tax_deduction', 'manual_lines', 'adjustment_notes', 'net_salary']);

        if ($record->usesQ3Formula()) {
            if ($record->kpi_source === PayrollRecord::KPI_RETENTION) {
                $record->retention_tier = filled($validated['retention_tier'] ?? null) ? (float) $validated['retention_tier'] : null;
                $record->foreign_session_pay = (float) ($validated['foreign_session_pay'] ?? 0);
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

        return redirect()->route('payroll.records.show', $record->id)
            ->with('status', 'Đã lưu các khoản nhập tay — thực lĩnh mới '.number_format((float) $record->net_salary, 0, ',', '.').'đ.');
    }

    public function fulltimePeriod($id)
    {
        $period = PayrollPeriod::with(['records.user'])->where('id', $id)->orWhere('code', $id)->firstOrFail();
        $records = $period->records()->where('department', 'fulltime')->get();

        return view('payroll.fulltime', compact('period', 'records'));
    }

    public function academicPeriod($id)
    {
        $period = PayrollPeriod::with(['records.user'])->where('id', $id)->orWhere('code', $id)->firstOrFail();
        $records = $period->records()->where('department', 'academic')->get();

        return view('payroll.academic', compact('period', 'records'));
    }

    public function operationsPeriod($id)
    {
        $period = PayrollPeriod::with(['records.user'])->where('id', $id)->orWhere('code', $id)->firstOrFail();
        $records = $period->records()->where('department', 'operations')->get();

        return view('payroll.operations', compact('period', 'records'));
    }

    public function manualTimesheet(Request $request)
    {
        // Chỉ liệt kê lớp đang/opening trong phạm vi người chấm và nhân sự giảng dạy — User::all() trước đây
        // đưa cả học viên vào dropdown chấm công.
        $classes = ClassModel::with('branch')->visibleTo($request->user())
            ->whereIn('status', ['active', 'upcoming', 'pending_schedule'])->orderBy('name')->get();
        $teachers = $this->teachingStaff();
        $branches = $classes->pluck('branch')->filter()->unique('id')->sortBy('name')->values();

        // Khoảng ngày của các kỳ lương đã duyệt/đã chi trả: màn hình cảnh báo và khóa nút lưu ngay khi chọn ngày.
        $lockedRanges = PayrollPeriod::whereIn('status', PayrollPeriod::LOCKED_STATUSES)
            ->get(['start_date', 'end_date', 'title'])
            ->map(fn (PayrollPeriod $p) => [
                'from' => Carbon::parse($p->start_date)->toDateString(),
                'to' => Carbon::parse($p->end_date)->toDateString(),
                'title' => $p->title,
            ])->values();

        return view('payroll.timesheets-manual', compact('classes', 'teachers', 'branches', 'lockedRanges'));
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
            'teaching_date' => 'required|date',
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i', 'after:time_in'],
            // Bỏ trống = dùng đơn giá riêng của GV (theo ngày hiệu lực) → users.hourly_rate → mặc định
            'hourly_rate' => 'nullable|numeric|min:1000',
            'type' => ['required', 'in:regular,sub,1on1,grading,workshop'],
            'notes' => 'required|string|min:5|max:500',
        ], [
            'time_in.required' => 'Vui lòng nhập giờ vào.',
            'time_out.required' => 'Vui lòng nhập giờ ra.',
            'time_out.after' => 'Giờ ra phải sau giờ vào.',
            'notes.required' => 'Chấm công tay bắt buộc ghi lý do.',
            'notes.min' => 'Lý do chấm công tay cần ít nhất 5 ký tự.',
        ]);

        // A3: Học vụ / Quản lý cơ sở chỉ chấm công tay cho lớp trong phạm vi mình quản lý (chi nhánh), không phải lớp bất kỳ.
        abort_unless(
            ClassModel::query()->visibleTo($request->user())->whereKey($validated['class_id'])->exists(),
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

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : now()->format('Y-m');
        $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $status = in_array($request->query('status'), ['pending_review', 'valid', 'invalid'], true) ? $request->query('status') : null;
        $search = trim((string) $request->query('search', ''));
        $branchId = $canViewAll ? ($request->integer('branch_id') ?: null) : null;
        $classId = $request->integer('class_id') ?: null;
        $teacherId = $canViewAll ? ($request->integer('user_id') ?: null) : $user->id;

        $visibleClassIds = $canViewAll ? ClassModel::query()->visibleTo($user)->pluck('id') : null;

        $query = TeacherTimesheet::with(['teacher.branch', 'classModel', 'reviewer', 'adjuster', 'classSession'])
            ->whereBetween('teaching_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->when(! $canViewAll, fn ($q) => $q->where('user_id', $user->id))
            // Quản lý cơ sở / Học vụ chỉ thấy ca của lớp trong phạm vi mình (Admin thấy tất cả).
            ->when($canViewAll && $user->managedBranchIds() !== null, fn ($q) => $q->whereIn('class_id', $visibleClassIds))
            ->when($teacherId, fn ($q) => $q->where('user_id', $teacherId))
            ->when($branchId, fn ($q) => $q->whereHas('classModel', fn ($c) => $c->where('branch_id', $branchId)))
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->whereHas('teacher', fn ($t) => $t->where('name', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%")));

        $summary = (clone $query)->reorder()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $timesheets = $query->orderByDesc('teaching_date')->orderByDesc('id')
            ->paginate($request->perPage(20))
            ->withQueryString();

        $period = PayrollPeriod::where('year', $monthStart->year)->where('month', $monthStart->month)->first();
        $periodLocked = $period?->isLocked() || PayrollPeriod::isLockedFor($monthStart);

        // Thẻ giáo viên: số buổi được phân công đã diễn ra trong tháng mà chưa có ca chấm công (không tính ca bị từ chối).
        $teacher = $teacherId ? User::with('branch')->find($teacherId) : null;
        $missingSessions = collect();
        if ($teacher) {
            $missingSessions = \App\Models\ClassSession::with('classModel')
                ->forStaff($teacher->id)
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

        $filterClasses = $canViewAll ? ClassModel::query()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'code', 'branch_id']) : collect();
        $filterBranches = $canViewAll ? \App\Models\Branch::whereIn('id', $filterClasses->pluck('branch_id')->filter()->unique())->orderBy('name')->get(['id', 'name']) : collect();
        $filterTeachers = $canViewAll ? $this->teachingStaff() : collect();

        return view('payroll.timesheets-teachers', compact(
            'timesheets', 'summary', 'month', 'monthStart', 'status', 'period', 'periodLocked',
            'teacher', 'missingSessions', 'filterClasses', 'filterBranches', 'filterTeachers', 'canViewAll'
        ));
    }

    public function reviewTimesheet(Request $request, int $id)
    {
        abort_unless($request->user()->can('attendance_staff.view'), 403);
        $validated = $request->validate([
            'decision' => ['required', 'in:valid,invalid'],
            'rejection_reason' => ['nullable', 'required_if:decision,invalid', 'string', 'max:1000'],
        ], ['rejection_reason.required_if' => 'Vui lòng nhập lý do từ chối ca dạy.']);
        $timesheet = TeacherTimesheet::findOrFail($id);
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
        TeacherTimesheet::whereIn('id', $validated['ids'])->get()->each(function (TeacherTimesheet $ts) use (&$approved, &$skipped) {
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
     * để đối soát lại. Không sửa được ca thuộc kỳ lương đã duyệt/đã chi trả.
     */
    public function adjustTimesheet(Request $request, int $id)
    {
        $timesheet = TeacherTimesheet::findOrFail($id);
        abort_unless(ClassModel::query()->visibleTo($request->user())->whereKey($timesheet->class_id)->exists(), 403);
        if (PayrollPeriod::isLockedFor($timesheet->teaching_date)) {
            return $this->rejectLockedDate('time_in', $timesheet->teaching_date);
        }

        $validated = $request->validate([
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i', 'after:time_in'],
            'adjustment_reason' => ['required', 'string', 'min:5', 'max:500'],
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

        $before = $timesheet->only(['checkin_time', 'checkout_time', 'hours', 'status']);
        $timesheet->update([
            'checkin_time' => $validated['time_in'],
            'checkout_time' => $validated['time_out'],
            'hours' => $hours,
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

        return view('payroll.timesheets-sync', compact('syncLogs', 'hasAnyLog'));
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
     * BXH KPI & hoa hồng tuyển sinh theo tháng: cùng căn cứ với bảng lương
     * (tiền thực thu của khách mới, phiếu duyệt trong tháng — SalesCommissionService).
     */
    public function kpiLeaderboard(Request $request)
    {
        $month = min(12, max(1, $request->integer('month') ?: now()->month));
        $year = min(2100, max(2020, $request->integer('year') ?: now()->year));
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $salesUsers = User::role('sales_consultant')->get();
        if ($salesUsers->isEmpty()) {
            $salesUsers = User::whereHas('crmCustomers')->get();
        }

        $service = app(SalesCommissionService::class);
        $receiptsBySales = $service->commissionableReceipts($start, $end)->groupBy('commission_owner_id');
        $closedBySales = $service->closedCountsBySales($start, $end);

        $usersWithSales = $salesUsers->map(function (User $user) use ($receiptsBySales, $closedBySales, $service, $end) {
            $receipts = $receiptsBySales->get($user->id, collect());
            $revenue = (float) $receipts->sum('amount');
            // Hoa hồng phát sinh (trước gate kép) — trả thực tế theo phiếu lương.
            $commission = $service->commissionFor($revenue, (int) ($closedBySales->get($user->id) ?? 0), $end);
            $studentsRetained = $user->branch_id
                ? Student::where('branch_id', $user->branch_id)->where('status', 'studying')->count()
                : 0;

            return [
                'user' => $user,
                'revenue' => $revenue,
                'deals' => $receipts->pluck('student_id')->unique()->count(),
                'retained_students' => $studentsRetained,
                'tier_name' => $commission['tier']?->tier_name ?? 'Chưa cấu hình bậc',
                'closed' => $commission['closed'],
                'percent' => $commission['percent'],
                'commission' => $commission['amount'],
                'branch_name' => $user->branch?->name ?? 'Hệ thống MEnglish',
            ];
        })->sortByDesc('revenue')->values();

        return view('payroll.kpi-leaderboard', compact('usersWithSales', 'month', 'year'));
    }

    public function configSettings()
    {
        $settings = PayrollPeriod::payrollSettings();

        return view('payroll.config-settings', compact('settings'));
    }

    /**
     * Tham số công thức lương Q3: tỉ lệ BHXH / Công đoàn (trên lương cơ bản Full-time), quỹ KPI Học vụ,
     * bảng % thưởng tái tục theo số HS nghỉ (các mốc chưa được BA chốt đánh dấu "chờ BA").
     */
    public function storeSettings(Request $request)
    {
        $validated = $request->validate([
            'insurance_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'union_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'academic_kpi_fund' => ['required', 'numeric', 'min:0'],
            'renewal' => ['nullable', 'array', 'max:20'],
            'renewal.*.quits' => ['required', 'integer', 'min:0', 'max:50', 'distinct'],
            'renewal.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'renewal.*.pending' => ['nullable', 'boolean'],
            'renewal_beyond_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            '*.required' => 'Vui lòng nhập đầy đủ các tham số.',
            'insurance_rate_percent.max' => 'Tỉ lệ BHXH không được vượt quá 100%.',
            'renewal.*.quits.distinct' => 'Mỗi mức số HS nghỉ chỉ được khai báo một lần.',
        ]);

        SystemSetting::set('payroll_insurance_rate_percent', $validated['insurance_rate_percent'], 'BHXH trừ trên lương cơ bản Full-time (%)');
        SystemSetting::set('payroll_union_rate_percent', $validated['union_rate_percent'], 'Công đoàn trừ trên lương cơ bản Full-time (%)');
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

        $history = TeacherHourlyRate::with(['user', 'creator'])
            ->when($teacherId, fn ($query) => $query->where('user_id', $teacherId))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate($request->perPage(15))
            ->withQueryString();

        // "Đến ngày" của từng phiên bản = ngày trước phiên bản kế tiếp của cùng GV (null = hiện tại).
        $versionsByUser = TeacherHourlyRate::whereIn('user_id', $history->getCollection()->pluck('user_id')->unique())
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
            ->orderBy('effective_from')
            ->get()
            ->keyBy('user_id');

        $selectedTeacher = $teacherId ? $teachers->firstWhere('id', $teacherId) : null;
        $selectedType = $selectedTeacher
            ? ($currentRates->get($selectedTeacher->id)?->teacher_type ?? TeacherHourlyRate::defaultTeacherType($selectedTeacher))
            : null;

        return view('payroll.config-rates', compact('rates', 'teachers', 'history', 'currentRates', 'selectedTeacher', 'selectedType', 'endDates'));
    }

    /**
     * Thêm đơn giá riêng cho một GV từ một ngày hiệu lực. Không sửa dòng cũ:
     * mỗi lần đổi giá là một phiên bản mới để giữ lịch sử và tính đúng ca dạy cũ.
     */
    public function storePersonalTeacherRate(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'hourly_rate' => ['required', 'numeric', 'min:1000'],
            // Q3 Part-time: mặc định đơn giá theo BUỔI; 'hour' giữ cho trường hợp cũ.
            'rate_unit' => ['nullable', 'in:session,hour'],
            'teacher_type' => ['nullable', 'in:'.implode(',', array_keys(TeacherHourlyRate::TEACHER_TYPES))],
            'effective_from' => [
                'required', 'date',
                function ($attribute, $value, $fail) use ($request) {
                    $exists = TeacherHourlyRate::where('user_id', $request->input('user_id'))
                        ->whereDate('effective_from', Carbon::parse($value)->toDateString())->exists();
                    if ($exists) {
                        $fail('Giáo viên đã có đơn giá hiệu lực từ ngày này — chọn ngày hiệu lực khác để tạo phiên bản mới.');
                    }
                },
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['rate_unit'] ??= TeacherHourlyRate::UNIT_HOUR;
        $validated['teacher_type'] ??= TeacherHourlyRate::defaultTeacherType(User::findOrFail($validated['user_id']));
        $rate = TeacherHourlyRate::create($validated + ['created_by' => $request->user()->id]);

        activity('teacher_rate')->causedBy($request->user())->performedOn($rate)
            ->withProperties(['new' => $validated])
            ->log('Thêm đơn giá riêng ('.$rate->unit_label.') cho GV #'.$validated['user_id']);

        return redirect()->route('payroll.config.teacher-rates', ['teacher_id' => $validated['user_id']])
            ->with('status', 'Đã thêm đơn giá '.number_format((float) $validated['hourly_rate'], 0, ',', '.').' '.$rate->unit_label.' hiệu lực từ '
                .Carbon::parse($validated['effective_from'])->format('d/m/Y').'.');
    }

    /** Nhân sự giảng dạy đang hoạt động (dùng cho chấm công tay và đơn giá GV). */
    private function teachingStaff()
    {
        return User::where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant', 'academic_lead', 'manager']))
            ->orderBy('name')->get();
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

        return view('payroll.config-commissions', compact('tiers', 'history', 'asOf', 'tab', 'settings'));
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

        return 'Bậc '.$validated['min_students'].($max !== null && $max !== '' ? '–'.$max : '+').' HS';
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
     * Phiếu lương cá nhân: chỉ các kỳ đã duyệt/đã chi trả (bản nháp có thể còn thay đổi).
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

        return view('payroll.my-salary', compact('user', 'record', 'records'));
    }

    private function rejectLockedDate(string $field, $date): RedirectResponse
    {
        $message = PayrollPeriod::lockedMessage($date);

        return redirect()->back()->withInput()->withErrors([$field => $message])->with('error', $message);
    }

    public function appsheetTimesheet()
    {
        return view('payroll.timesheets-appsheet');
    }
}
