<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\CommissionTier;
use App\Models\CrmCustomer;
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

    public function kpiLeaderboard()
    {
        $salesUsers = User::role('sales_consultant')->get();
        if ($salesUsers->isEmpty()) {
            $salesUsers = User::whereHas('crmCustomers')->get();
        }

        // Doanh số chốt trọn đời theo người nhận hoa hồng (commission_user_id,
        // fallback assigned_user_id cho lead cũ) — khớp luật tính lương PayrollPeriod.
        $wonByUser = CrmCustomer::query()
            ->where('stage', 'won')
            ->whereNotNull('assigned_user_id')
            ->get(['commission_user_id', 'assigned_user_id', 'deal_value'])
            ->groupBy(fn (CrmCustomer $lead) => $lead->commission_user_id ?? $lead->assigned_user_id)
            ->map(fn ($group) => ['revenue' => (float) $group->sum('deal_value'), 'deals' => $group->count()]);

        $usersWithSales = $salesUsers->map(function (User $user) use ($wonByUser) {
            $wonRevenue = (float) ($wonByUser[$user->id]['revenue'] ?? 0);
            $dealsCount = $wonByUser[$user->id]['deals'] ?? 0;
            $tier = CommissionTier::matchForRevenue($wonRevenue);
            $percent = $tier ? (float) $tier->new_sale_percent : 0;
            $commission = ($wonRevenue * $percent) / 100 + (float) ($tier->bonus_amount ?? 0);
            $studentsRetained = $user->branch_id
                ? Student::where('branch_id', $user->branch_id)->where('status', 'studying')->count()
                : 0;

            return [
                'user' => $user,
                'revenue' => $wonRevenue,
                'deals' => $dealsCount,
                'retained_students' => $studentsRetained,
                'tier_name' => $tier?->tier_name ?? 'Mức cơ bản',
                'commission' => $commission,
                'branch_name' => $user->branch?->name ?? 'Hệ thống MEnglish',
            ];
        })->sortByDesc('revenue')->values();

        return view('payroll.kpi-leaderboard', compact('usersWithSales'));
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

    public function commissionTiers()
    {
        $tiers = CommissionTier::all();

        return view('payroll.config-commissions', compact('tiers'));
    }

    public function storeCommissionTier(Request $request)
    {
        $validated = $request->validate([
            'tier_name' => 'required|string',
            'min_revenue' => 'required|numeric|min:0',
            'new_sale_percent' => 'required|numeric|min:0|max:100',
            'renew_percent' => 'required|numeric|min:0|max:100',
            'bonus_amount' => 'nullable|numeric|min:0',
        ]);

        $tier = CommissionTier::create($validated);

        activity('commission_tier')->causedBy($request->user())->performedOn($tier)
            ->withProperties(['new' => $validated])
            ->log('Tạo mới bậc hoa hồng: '.$tier->tier_name);

        return redirect()->back()->with('status', 'Đã lưu mức cấu hình hoa hồng mới!');
    }

    public function updateCommissionTier(Request $request, CommissionTier $commissionTier)
    {
        $validated = $request->validate([
            'tier_name' => 'required|string',
            'min_revenue' => 'required|numeric|min:0',
            'new_sale_percent' => 'required|numeric|min:0|max:100',
            'renew_percent' => 'required|numeric|min:0|max:100',
            'bonus_amount' => 'nullable|numeric|min:0',
        ]);

        $before = $commissionTier->only(['tier_name', 'min_revenue', 'new_sale_percent', 'renew_percent', 'bonus_amount']);

        $commissionTier->update($validated);

        activity('commission_tier')->causedBy($request->user())->performedOn($commissionTier)
            ->withProperties(['before' => $before, 'after' => $validated])
            ->log('Cập nhật bậc hoa hồng: '.$commissionTier->tier_name.' (Chỉ áp dụng cho các kỳ và giao dịch phát sinh từ thời điểm này trở đi)');

        return redirect()->back()->with('status', 'Đã cập nhật mức cấu hình hoa hồng thành công! (Mức % mới chỉ áp dụng cho các phát sinh sau thời điểm sửa)');
    }

    public function destroyCommissionTier(CommissionTier $commissionTier, Request $request)
    {
        $name = $commissionTier->tier_name;
        $commissionTier->delete();

        activity('commission_tier')->causedBy($request->user())
            ->withProperties(['deleted_tier' => $name])
            ->log('Xóa bậc hoa hồng: '.$name);

        return redirect()->back()->with('status', 'Đã xóa bậc cấu hình hoa hồng: '.$name);
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
