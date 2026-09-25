<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\CommissionAdjustment;
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
    public function periods()
    {
        $currentUser = auth()->user();
        abort_if($currentUser && ($currentUser->hasRole('student') || $currentUser->hasRole('teacher') || $currentUser->hasRole('academic_lead')), 403);
        $periods = PayrollPeriod::withCount('records')->latest()->get();

        return view('payroll.periods', compact('periods'));
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
     * Cập nhật các điều chỉnh thủ công của một bản ghi lương rồi tính lại
     * thực lĩnh từ toàn bộ thành phần lương và khoản khấu trừ.
     */
    public function updateRecord(Request $request, $id)
    {
        $record = PayrollRecord::findOrFail($id);
        abort_if(in_array($record->period?->status, ['approved', 'paid'], true), 422, 'Không thể sửa kỳ lương đã khóa.');

        $validated = $request->validate([
            'foreign_teacher_sessions_count' => ['required', 'integer', 'min:0'],
            'foreign_teacher_deduction_rate' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $record->fill($validated);
        $record->foreign_teacher_deduction =
            $validated['foreign_teacher_sessions_count'] * $validated['foreign_teacher_deduction_rate'];
        $record->calculateNetSalary();
        $record->save();
        $record->period->refreshTotals();

        return redirect()->back()->with('status', 'Đã cập nhật điều chỉnh và tính lại lương thực lĩnh.');
    }

    /**
     * Phiếu lương một người: toàn bộ khoản cộng / khoản trừ của một bản ghi lương,
     * kèm căn cứ (ca dạy, biên bản phạt, phiếu thu tính hoa hồng, thu hồi hoa hồng).
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
        $commissionReceipts = app(SalesCommissionService::class)
            ->commissionableReceipts($period->start_date, $period->end_date, $record->user_id)
            ->load('student');

        return view('payroll.record-show', compact('record', 'period', 'timesheets', 'penalties', 'clawbacks', 'commissionReceipts'));
    }

    /**
     * Kế toán điều chỉnh tay các khoản trên phiếu lương khi kỳ chưa duyệt. Các khoản này
     * được giữ lại khi "Đồng bộ & Tính lại".
     */
    public function adjustRecord(Request $request, int $id)
    {
        $record = PayrollRecord::with('period')->findOrFail($id);
        abort_if($record->isLocked(), 422, 'Không thể sửa phiếu lương của kỳ đã duyệt/đã chi trả.');

        $validated = $request->validate([
            'allowance_override' => ['nullable', 'numeric', 'min:0'],
            'other_bonus' => ['nullable', 'numeric', 'min:0'],
            'other_deduction' => ['nullable', 'numeric', 'min:0'],
            'adjustment_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if (((float) ($validated['other_bonus'] ?? 0) > 0 || (float) ($validated['other_deduction'] ?? 0) > 0)
            && blank($validated['adjustment_notes'] ?? null)) {
            throw ValidationException::withMessages(['adjustment_notes' => 'Vui lòng ghi rõ lý do khi cộng/trừ khoản khác.']);
        }

        $before = $record->only(['allowance', 'allowance_override', 'other_bonus', 'other_deduction', 'adjustment_notes', 'net_salary']);

        $record->allowance_override = $validated['allowance_override'] ?? null;
        if ($record->allowance_override !== null) {
            $record->allowance = $record->allowance_override;
        } elseif ($before['allowance_override'] !== null) {
            // Bỏ điều chỉnh tay → trở về phụ cấp theo cấu hình
            $record->allowance = (float) $record->base_salary > 0 ? PayrollPeriod::payrollSettings()['allowance_amount'] : 0;
        }
        $record->other_bonus = $validated['other_bonus'] ?? 0;
        $record->other_deduction = $validated['other_deduction'] ?? 0;
        $record->adjustment_notes = $validated['adjustment_notes'] ?? null;
        $record->calculateNetSalary();
        $record->save();
        $record->period->refreshTotals();

        activity('payroll_record')->causedBy($request->user())->performedOn($record)
            ->withProperties(['before' => $before, 'after' => $record->only(array_keys($before))])
            ->log('Điều chỉnh phiếu lương '.$record->user?->name.' — '.$record->period->title);

        return redirect()->route('payroll.records.show', $record->id)
            ->with('status', 'Đã lưu điều chỉnh phiếu lương — thực lĩnh mới '.number_format((float) $record->net_salary, 0, ',', '.').'đ.');
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

    public function manualTimesheet()
    {
        // Chỉ liệt kê lớp đang/opening và nhân sự giảng dạy — User::all() trước đây
        // đưa cả học viên vào dropdown chấm công.
        $classes = ClassModel::whereIn('status', ['active', 'upcoming', 'pending_schedule'])->orderBy('name')->get();
        $teachers = $this->teachingStaff();

        return view('payroll.timesheets-manual', compact('classes', 'teachers'));
    }

    /**
     * Chấm công tay: bắt buộc lý do + giờ vào/ra (số giờ tính từ giờ vào/ra),
     * tự gắn buổi học thật nếu có, và chặn chấm trùng với check-in/chấm tay khác.
     */
    public function storeTimesheet(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
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

    public function teacherTimesheets(Request $request)
    {
        $user = $request->user();
        $canViewAll = $user->can('attendance_staff.view');
        abort_unless($canViewAll || $user->can('payroll.view_own'), 403);

        $timesheets = TeacherTimesheet::with(['teacher', 'classModel', 'reviewer'])
            ->when(! $canViewAll, fn ($query) => $query->where('user_id', $user->id))
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        return view('payroll.timesheets-teachers', compact('timesheets'));
    }

    public function reviewTimesheet(Request $request, int $id)
    {
        abort_unless($request->user()->can('attendance_staff.view'), 403);
        $validated = $request->validate([
            'decision' => ['required', 'in:valid,invalid'],
            'rejection_reason' => ['nullable', 'required_if:decision,invalid', 'string', 'max:1000'],
        ]);
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
     * Lịch sử đồng bộ máy chấm công. Hiện chưa có tích hợp thiết bị nào ghi
     * TimesheetSyncLog → trang hiển thị trạng thái trống trung thực thay vì giả lập.
     */
    public function syncHistory()
    {
        $syncLogs = TimesheetSyncLog::with('branch')->latest()->get();

        return view('payroll.timesheets-sync', compact('syncLogs'));
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

        $usersWithSales = $salesUsers->map(function (User $user) use ($receiptsBySales, $service, $end) {
            $receipts = $receiptsBySales->get($user->id, collect());
            $revenue = (float) $receipts->sum('amount');
            $commission = $service->commissionFor($revenue, $end);
            $studentsRetained = $user->branch_id
                ? Student::where('branch_id', $user->branch_id)->where('status', 'studying')->count()
                : 0;

            return [
                'user' => $user,
                'revenue' => $revenue,
                'deals' => $receipts->pluck('student_id')->unique()->count(),
                'retained_students' => $studentsRetained,
                'tier_name' => $commission['tier']?->tier_name ?? 'Mức cơ bản',
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

    public function storeSettings(Request $request)
    {
        $validated = $request->validate([
            'allowance_amount' => ['required', 'numeric', 'min:0'],
            'kpi_bonus_amount' => ['required', 'numeric', 'min:0'],
            'kpi_bonus_hours_threshold' => ['required', 'numeric', 'min:1'],
            'insurance_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'foreign_teacher_deduction_rate' => ['required', 'numeric', 'min:0'],
        ], [
            '*.required' => 'Vui lòng nhập đầy đủ các tham số.',
            'insurance_rate_percent.max' => 'Tỉ lệ BHXH không được vượt quá 100%.',
        ]);

        SystemSetting::set('payroll_allowance_amount', $validated['allowance_amount'], 'Phụ cấp hàng tháng áp dụng khi có lương cứng (đ)');
        SystemSetting::set('payroll_kpi_bonus_amount', $validated['kpi_bonus_amount'], 'Thưởng KPI khi đạt ngưỡng giờ dạy (đ)');
        SystemSetting::set('payroll_kpi_bonus_hours_threshold', $validated['kpi_bonus_hours_threshold'], 'Ngưỡng giờ dạy trong kỳ để nhận thưởng KPI (giờ)');
        SystemSetting::set('payroll_insurance_rate_percent', $validated['insurance_rate_percent'], 'Tỉ lệ bảo hiểm trừ trên lương cứng (%)');
        SystemSetting::set('payroll_foreign_teacher_deduction_rate', $validated['foreign_teacher_deduction_rate'], 'Mức trừ mỗi buổi có GVNN đồng dạy (đ)');

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

        // Đơn giá đang hiệu lực hôm nay của từng GV (dòng effective_from gần nhất ≤ hôm nay).
        $currentRates = TeacherHourlyRate::whereDate('effective_from', '<=', now()->toDateString())
            ->orderBy('effective_from')
            ->get()
            ->keyBy('user_id');

        $selectedTeacher = $teacherId ? $teachers->firstWhere('id', $teacherId) : null;

        return view('payroll.config-rates', compact('rates', 'teachers', 'history', 'currentRates', 'selectedTeacher'));
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

        $rate = TeacherHourlyRate::create($validated + ['created_by' => $request->user()->id]);

        activity('teacher_rate')->causedBy($request->user())->performedOn($rate)
            ->withProperties(['new' => $validated])
            ->log('Thêm đơn giá giờ dạy riêng cho GV #'.$validated['user_id']);

        return redirect()->route('payroll.config.teacher-rates', ['teacher_id' => $validated['user_id']])
            ->with('status', 'Đã thêm đơn giá '.number_format((float) $validated['hourly_rate'], 0, ',', '.').'đ/h hiệu lực từ '
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
        $tiers = CommissionTier::effectiveAt($asOf)->orderBy('min_revenue')->get();
        $history = CommissionTier::with(['creator', 'replaces'])
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->paginate($request->perPage(15))
            ->withQueryString();

        return view('payroll.config-commissions', compact('tiers', 'history', 'asOf'));
    }

    /** Luật validate chung cho bậc hoa hồng. renew_percent không còn dùng (A6: không tính tái tục). */
    private function commissionTierRules(): array
    {
        return [
            'tier_name' => 'required|string|max:255',
            'min_revenue' => 'required|numeric|min:0',
            'max_revenue' => 'nullable|numeric|gt:min_revenue',
            'new_sale_percent' => 'required|numeric|min:0|max:100',
            'renew_percent' => 'nullable|numeric|min:0|max:100',
            'bonus_amount' => 'nullable|numeric|min:0',
            'effective_from' => 'nullable|date',
        ];
    }

    public function storeCommissionTier(Request $request)
    {
        $validated = $request->validate($this->commissionTierRules());
        $validated['effective_from'] ??= today()->toDateString();
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
        abort_if($commissionTier->effective_to !== null, 422, 'Phiên bản này đã hết hiệu lực — hãy sửa phiên bản đang hiệu lực.');

        $effectiveFrom = Carbon::parse($validated['effective_from'] ?? today())->startOfDay();
        $validated['effective_from'] = $effectiveFrom->toDateString();
        $validated['renew_percent'] ??= (float) $commissionTier->renew_percent;
        $validated['bonus_amount'] ??= 0;
        $before = $commissionTier->only(['tier_name', 'min_revenue', 'max_revenue', 'new_sale_percent', 'bonus_amount', 'effective_from']);

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
