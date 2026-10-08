<?php

namespace App\Services\Kpi;

use App\Models\AcademicProjectMilestone;
use App\Models\AdminNotification;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CrmCustomer;
use App\Models\MaterialOrder;
use App\Models\MerchandiseStockMovement;
use App\Models\MiniTestScore;
use App\Models\Penalty;
use App\Models\SlaEvent;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\StaffReport;
use App\Models\StudentTuition;
use App\Models\TeacherTimesheet;
use App\Models\TuitionContactLog;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\FirstMonthCareService;
use App\Support\Dashboard\TeachingQuality;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Số liệu KPI tự động (KpiCriterion::AUTO_SOURCES) của một nhân sự trong một kỳ (tháng / quý), từ dữ liệu sẵn có của hệ thống.
 * Nguồn đếm số lần: mỗi bản ghi đếm được là một dòng bằng chứng
 * ['date' => 'd/m', 'month' => 'Y-m', 'text' => …, 'counted' => bool, 'note' => ?string].
 * Nguồn tỉ lệ % (KpiCriterion::RATE_SOURCES): measure() trả tỉ lệ (null = kỳ chưa có dữ liệu → người chấm điền tay)
 * kèm các dòng diễn giải theo lớp / tháng.
 * Một sự việc chỉ trừ một lần: bản ghi đã có phiếu phạt tiền (đã chốt phạt / đã nộp / đã trừ lương) vẫn hiện để đối chiếu
 * nhưng không đếm vào KPI.
 */
class KpiAutoCounter
{
    /** Báo cáo ngày nộp sau giờ này của sáng hôm sau là trễ (theo file KPI Học vụ). */
    public const DAILY_REPORT_DEADLINE = '09:00';

    /** Nhắc học phí được tính nếu có lần liên hệ từ chừng này ngày trước hạn trở đi. */
    public const TUITION_REMIND_DAYS_BEFORE = 3;

    /** Hạn SLA CRM thuộc tiêu chí xử lý data / test tuyển sinh. */
    public const CRM_RULES = ['crm.first_contact', 'crm.follow_up', 'crm.test_result', 'crm.trial_feedback'];

    /** Trạng thái phiếu phạt coi là "đã phạt tiền". */
    private const FINED_STATUSES = ['fined', 'paid', 'deducted'];

    /** Điểm test đạt chuẩn (thang 10) — cùng ngưỡng đưa học sinh vào danh sách hỗ trợ. */
    public const PASS_SCORE = 7.0;

    /** Báo cáo tháng nộp trước giờ này của ngày mùng 2 tháng sau là đúng hạn (file KPI GV part-time). */
    public const MONTHLY_REPORT_DAY = 2;

    public const MONTHLY_REPORT_DEADLINE = '12:00';

    /** Học thuật: tháng có ít hơn chừng này đơn vị đánh giá thì gộp thêm tháng trước (tránh 1 việc = cả mục). */
    public const MIN_UNITS = 3;

    /** Học thuật: đầu ra học sinh tính cuốn chiếu chừng này tháng (gồm tháng chấm). */
    public const PASS_RATE_MONTHS = 3;

    /** Đi muộn của nhân sự văn phòng tính khi quá chừng này phút (file KPI Học thuật). */
    public const STAFF_LATE_MINUTES = 10;

    /** Giờ dùng mặc định của order khi lớp không có buổi học trong ngày sử dụng. */
    public const ORDER_DEFAULT_USE_TIME = '08:00';

    /**
     * Số liệu của nguồn: đếm số lần → value = số bản ghi được tính; tỉ lệ → value = % (null nếu chưa có dữ liệu).
     *
     * @return array{value: int|float|null, evidence: list<array>}
     */
    public function measure(string $source, User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $rate = match ($source) {
            'test_result' => $this->testResult($user, $from, $to),
            'test_progress' => $this->testProgress($user, $from, $to),
            'class_attendance_rate' => $this->classRate('attendance', $user, $from, $to),
            'homework_rate' => $this->classRate('homework', $user, $from, $to),
            'monthly_report_on_time' => $this->monthlyReportOnTime($user, $from, $to),
            'academic_deliverable_on_time' => $this->factorRate(fn (CarbonInterface $f) => $this->deliverableRows($user, $f, $to), $from),
            'task_on_time' => $this->factorRate(fn (CarbonInterface $f) => $this->taskRows($user, $f, $to), $from),
            'academic_order_on_time' => $this->factorRate(fn (CarbonInterface $f) => $this->orderRows($f, $to), $from),
            'student_pass_rate' => $this->studentPassRate($user, $to),
            default => null,
        };
        if ($rate !== null) {
            return $rate;
        }
        $evidence = $this->evidence($source, $user, $from, $to);

        return ['value' => self::countOf($evidence), 'evidence' => $evidence];
    }

    /** @return list<array{date: string, month: string, text: string, counted: bool, note: ?string}> */
    public function evidence(string $source, User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = match ($source) {
            'tuition_no_reminder' => $this->tuitionNoReminder($user, $from, $to),
            'receipt_rejected' => $this->receiptRejected($user, $from, $to),
            'care_overdue' => $this->careOverdue($user, $from, $to),
            'crm_sla_late' => $this->crmSlaLate($user, $from, $to),
            'daily_report_late' => $this->dailyReportLate($user, $from, $to),
            'material_stock' => $this->materialStock($user, $from, $to),
            'teacher_absent_unexcused' => $this->teacherAbsences($user, $from, $to, excused: false),
            'teacher_leave' => $this->teacherAbsences($user, $from, $to, excused: true),
            'teacher_late' => $this->teacherLate($user, $from, $to),
            'staff_late' => $this->staffLate($user, $from, $to),
            default => collect(),
        };

        return $rows->sortBy('sort')->map(fn (array $r) => [
            'date' => $r['sort']->format('d/m'),
            'month' => $r['sort']->format('Y-m'),
            'text' => $r['text'],
            'counted' => $r['counted'] ?? true,
            'note' => $r['note'] ?? null,
        ])->values()->all();
    }

    public static function countOf(array $evidence): int
    {
        return count(array_filter($evidence, fn (array $e) => $e['counted']));
    }

    /** Phiếu phạt tiền đã chốt của bản ghi → ghi chú "không tính" (null = chưa phạt tiền, vẫn đếm). */
    private function finedNote(?Penalty $penalty): ?string
    {
        if (! $penalty || (float) $penalty->amount < 1000 || ! in_array($penalty->status, self::FINED_STATUSES, true)) {
            return null;
        }

        return 'Đã phạt tiền ('.$penalty->code.'), không tính KPI';
    }

    private function crmSlaLate(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $events = SlaEvent::with('penalty')
            ->where('user_id', $user->id)->whereIn('rule_key', self::CRM_RULES)
            ->whereBetween('breached_at', [$from, $to])->get();
        $customers = CrmCustomer::withTrashed()->whereIn('id', $events->where('subject_type', 'crm_customer')->pluck('subject_id'))->get()->keyBy('id');

        return $events->map(function (SlaEvent $e) use ($customers) {
            $customer = $customers->get($e->subject_id);
            $note = $this->finedNote($e->penalty);

            return [
                'sort' => $e->breached_at,
                'text' => (config('sla.rules')[$e->rule_key]['label'] ?? $e->rule_key).' trễ hạn'.($customer ? ': '.$customer->name : ''),
                'counted' => $note === null,
                'note' => $note,
            ];
        });
    }

    private function careOverdue(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $tasks = WorkTask::with('student')->whereNotNull('care_milestone')
            ->where('assignee_id', $user->id)->whereBetween('sla_breached_at', [$from, $to])->get();
        $penalties = Penalty::whereIn('work_task_id', $tasks->pluck('id'))->where('status', '!=', 'cancelled')->get()->keyBy('work_task_id');

        return $tasks->map(function (WorkTask $t) use ($penalties) {
            $note = $this->finedNote($penalties->get($t->id));

            return [
                'sort' => $t->sla_breached_at,
                'text' => 'Quá hạn chăm sóc '.FirstMonthCareService::milestoneShortLabel((int) $t->care_milestone).': '.($t->student?->name ?? $t->title),
                'counted' => $note === null,
                'note' => $note,
            ];
        });
    }

    /** Phiếu thu bị trả về: lấy theo thông báo "Phiếu thu bị trả về" gửi người lập (phiếu sửa và gửi lại vẫn còn dấu vết). */
    private function receiptRejected(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return AdminNotification::where('user_id', $user->id)->where('type', 'receipt_rejected')
            ->whereBetween('created_at', [$from, $to])->orderBy('created_at')->get()
            ->unique(fn (AdminNotification $n) => ($n->data['receipt_id'] ?? 'n'.$n->id).'@'.$n->created_at->toDateString())
            ->map(fn (AdminNotification $n) => ['sort' => $n->created_at, 'text' => $n->message ?: $n->title]);
    }

    /**
     * Hồ sơ học phí đến hạn trong tháng mà quá hạn (còn nợ, hoặc thu đủ sau hạn) nhưng không có lần nhắc nào trong
     * nhật ký liên hệ học phí, từ 3 ngày trước hạn đến hết tháng. Tính cho người phụ trách khách đã chốt ra học viên đó.
     */
    private function tuitionNoReminder(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $studentIds = CrmCustomer::withTrashed()->where('assigned_user_id', $user->id)->whereNotNull('converted_student_id')->pluck('converted_student_id');
        if ($studentIds->isEmpty()) {
            return collect();
        }
        $end = Carbon::parse($to)->min(now());
        $tuitions = StudentTuition::with(['student', 'receipts' => fn ($q) => $q->where('status', 'approved')])
            ->whereIn('student_id', $studentIds)->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $from->toDateString())->whereDate('due_date', '<', $end->toDateString())
            ->get()
            ->filter(function (StudentTuition $t) {
                if ($t->reminder_paused_until && $t->reminder_paused_until->gt($t->due_date)) {
                    return false;
                }
                $paidLate = $t->receipts->contains(fn ($r) => $r->approved_at && $r->approved_at->toDateString() > $t->due_date->toDateString());

                return (float) $t->debt_amount > 0 || $paidLate;
            });

        return $tuitions->reject(fn (StudentTuition $t) => TuitionContactLog::where('student_tuition_id', $t->id)
            ->whereBetween('contacted_at', [$t->due_date->copy()->subDays(self::TUITION_REMIND_DAYS_BEFORE)->startOfDay(), $end])->exists())
            ->map(fn (StudentTuition $t) => [
                'sort' => $t->due_date,
                'text' => ($t->student?->name ?? 'Học viên').': hạn '.$t->due_date->format('d/m').', chưa có lần nhắc học phí',
            ]);
    }

    private function dailyReportLate(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        [$h, $m] = array_map('intval', explode(':', self::DAILY_REPORT_DEADLINE));

        return StaffReport::where('user_id', $user->id)->where('type', 'daily')
            ->whereDate('report_date', '>=', $from->toDateString())->whereDate('report_date', '<=', $to->toDateString())->get()
            ->filter(fn (StaffReport $r) => $r->created_at->gt($r->report_date->copy()->addDay()->setTime($h, $m)))
            ->map(fn (StaffReport $r) => [
                'sort' => $r->report_date,
                'text' => 'Báo cáo ngày '.$r->report_date->format('d/m').' nộp lúc '.$r->created_at->format('H:i d/m'),
            ]);
    }

    private function materialStock(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $orders = MaterialOrder::where('processed_by', $user->id)->where('processed_late', true)
            ->whereBetween('processed_at', [$from, $to])->get()
            ->map(fn (MaterialOrder $o) => ['sort' => $o->processed_at, 'text' => "Học liệu {$o->code} xử lý trễ hạn ({$o->title})"]);
        $counts = MerchandiseStockMovement::with('item')->where('user_id', $user->id)
            ->where('type', MerchandiseStockMovement::TYPE_COUNT)->where('quantity_change', '!=', 0)
            ->whereBetween('created_at', [$from, $to])->get()
            ->map(fn (MerchandiseStockMovement $mv) => [
                'sort' => $mv->created_at,
                'text' => 'Kiểm kê '.($mv->item?->name ?? 'sách').' lệch '.($mv->quantity_change > 0 ? '+' : '').$mv->quantity_change,
            ]);

        return $orders->concat($counts);
    }

    // ───────────────────── Giáo viên ─────────────────────

    /**
     * Buổi dạy đã qua trong kỳ (GV chính / GVNN / trợ giảng, trừ buổi hủy) mà người này không có giờ dạy (check-in hoặc
     * xác nhận theo lịch, chưa bị từ chối). Có đơn nghỉ được duyệt trùng ngày → nghỉ có phép (đếm theo ngày);
     * không có đơn → nghỉ không phép (đếm theo buổi).
     */
    private function teacherAbsences(User $user, CarbonInterface $from, CarbonInterface $to, bool $excused): Collection
    {
        $until = min($to->toDateString(), now()->subDay()->toDateString());
        if ($until < $from->toDateString()) {
            return collect();
        }
        $sessions = ClassSession::with('classModel:id,code,name')->forStaff($user->id)
            ->whereBetween('date', [$from->toDateString(), $until])
            ->where('status', '!=', 'cancelled')
            ->orderBy('date')->orderBy('start_time')->get();
        if ($sessions->isEmpty()) {
            return collect();
        }
        $timesheets = TeacherTimesheet::where('user_id', $user->id)->where('status', '!=', 'invalid')
            ->whereBetween('teaching_date', [$from->toDateString(), $until])->get(['class_session_id', 'class_id', 'teaching_date']);
        $taughtSessions = $timesheets->pluck('class_session_id')->filter()->map(fn ($id) => (int) $id)->all();
        $taughtDays = $timesheets->whereNull('class_session_id')->map(fn ($t) => $t->class_id.'|'.$t->teaching_date->toDateString())->all();
        $leaves = StaffAttendanceRequest::approved()->where('type', StaffAttendanceRequest::TYPE_LEAVE)->where('user_id', $user->id)
            ->overlapping($from->toDateString(), $until)->get(['date_from', 'date_to']);
        $onLeave = fn (string $day) => $leaves->contains(fn ($l) => Carbon::parse($l->date_from)->toDateString() <= $day && Carbon::parse($l->date_to)->toDateString() >= $day);

        $missed = $sessions->filter(function (ClassSession $s) use ($taughtSessions, $taughtDays) {
            $day = Carbon::parse($s->date)->toDateString();

            return ! in_array((int) $s->id, $taughtSessions, true) && ! in_array($s->class_id.'|'.$day, $taughtDays, true);
        });
        $label = fn (ClassSession $s) => $s->classModel?->code ?: $s->classModel?->name ?: 'Lớp #'.$s->class_id;

        if ($excused) {
            return $missed->filter(fn (ClassSession $s) => $onLeave(Carbon::parse($s->date)->toDateString()))
                ->groupBy(fn (ClassSession $s) => Carbon::parse($s->date)->toDateString())
                ->map(fn (Collection $day, string $date) => [
                    'sort' => Carbon::parse($date),
                    'text' => 'Nghỉ có phép: '.$day->map($label)->unique()->join(', '),
                ])->values();
        }

        return $missed->reject(fn (ClassSession $s) => $onLeave(Carbon::parse($s->date)->toDateString()))
            ->map(fn (ClassSession $s) => [
                'sort' => Carbon::parse($s->date),
                'text' => 'Buổi '.$label($s).($s->start_time ? ' '.$s->start_time->format('H:i') : '').': không có giờ dạy, không có đơn nghỉ được duyệt',
            ])->values();
    }

    /** Buổi dạy check-in muộn (mọi số phút; phạt tiền đi muộn tính riêng ngoài KPI). */
    private function teacherLate(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return TeacherTimesheet::with('classModel:id,code,name')->where('user_id', $user->id)->where('status', '!=', 'invalid')
            ->whereBetween('teaching_date', [$from->toDateString(), $to->toDateString()])
            ->where('late_minutes', '>', 0)->orderBy('teaching_date')->get()
            ->map(fn (TeacherTimesheet $t) => [
                'sort' => $t->teaching_date,
                'text' => 'Lớp '.($t->classModel?->code ?: $t->classModel?->name ?: '#'.$t->class_id).': muộn '.(int) $t->late_minutes.' phút'.($t->late_notified ? ' (có báo trước)' : ''),
            ]);
    }

    /** Lớp người này dạy trong kỳ (lớp đang giữ + lớp có buổi người này dạy). */
    private function teacherClasses(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $quality = new TeachingQuality(null, $from, $to);

        return [$quality, $quality->classesOf($user)];
    }

    private static function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',').'%';
    }

    /** Tỷ lệ chuyên cần / làm BTVN của các lớp (trung bình theo sĩ số, cách tính của Tổng quan chất lượng giảng dạy). */
    private function classRate(string $key, User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        [$quality, $classes] = $this->teacherClasses($user, $from, $to);
        $metrics = $quality->classMetrics($classes);
        $total = $quality->totals($metrics)[$key];
        $evidence = collect($metrics)->filter(fn ($m) => $m[$key] !== null)->map(fn ($m) => [
            'date' => '',
            'month' => '',
            'text' => 'Lớp '.($m['code'] ?: $m['name']).': '.self::pct((float) $m[$key]).' ('.$m['students'].' học sinh)',
            'counted' => true,
            'note' => null,
        ])->values()->all();

        return ['value' => $total, 'evidence' => $evidence];
    }

    /**
     * Điểm test của học sinh các lớp trong kỳ, quy về thang 10: Big Test (đã duyệt / đã gửi, không vắng) và mini test.
     *
     * @return Collection<int, array{class_id: int, date: Carbon, score: float}>
     */
    private function testScores(Collection $classes, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $ids = $classes->pluck('id')->all();
        if ($ids === []) {
            return collect();
        }
        $big = BigTestResult::with('bigTest:id,class_id,scheduled_at')
            ->whereHas('bigTest', fn ($q) => $q->whereIn('class_id', $ids)->whereBetween('scheduled_at', [$from, $to]))
            ->where('is_absent', false)->whereNotNull('overall_score')->whereIn('status', ['approved', 'sent'])->get()
            ->map(fn (BigTestResult $r) => ['class_id' => (int) $r->bigTest->class_id, 'date' => $r->bigTest->scheduled_at->copy()->startOfDay(), 'score' => min(10.0, (float) $r->overall_score)]);
        $mini = MiniTestScore::whereIn('class_id', $ids)->whereBetween('test_date', [$from->toDateString(), $to->toDateString()])
            ->where('max_score', '>', 0)->get(['class_id', 'test_date', 'score', 'max_score'])
            ->map(fn (MiniTestScore $s) => ['class_id' => (int) $s->class_id, 'date' => Carbon::parse($s->test_date)->startOfDay(), 'score' => min(10.0, (float) $s->score / (float) $s->max_score * 10)]);

        return $big->concat($mini)->values();
    }

    /** Kết quả test = điểm TB (thang 10) ÷ 10 × 50% + tỷ lệ bài đạt chuẩn (≥ 7) × 50%. */
    private function testResult(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        [, $classes] = $this->teacherClasses($user, $from, $to);
        $scores = $this->testScores($classes, $from, $to);
        if ($scores->isEmpty()) {
            return ['value' => null, 'evidence' => []];
        }
        $average = (float) $scores->avg('score');
        $passShare = $scores->filter(fn ($s) => $s['score'] >= self::PASS_SCORE)->count() / $scores->count();
        $evidence = $scores->groupBy('class_id')->map(function (Collection $rows, int $classId) use ($classes) {
            $class = $classes->firstWhere('id', $classId);

            return [
                'date' => '',
                'month' => '',
                'text' => 'Lớp '.($class?->code ?: $class?->name ?: '#'.$classId).': '.$rows->count().' bài, điểm TB '
                    .rtrim(rtrim(number_format((float) $rows->avg('score'), 1, ',', '.'), '0'), ',')
                    .', đạt chuẩn '.self::pct($rows->filter(fn ($s) => $s['score'] >= self::PASS_SCORE)->count() / $rows->count() * 100),
                'counted' => true,
                'note' => null,
            ];
        })->values()->all();

        return ['value' => round(($average / 10 * 50) + ($passShare * 50), 1), 'evidence' => $evidence];
    }

    /** % điểm test tăng: mỗi lớp so điểm TB ngày test cuối với ngày test đầu trong kỳ; trung bình các lớp có ≥ 2 lần test. */
    private function testProgress(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        [, $classes] = $this->teacherClasses($user, $from, $to);
        $rows = $this->testScores($classes, $from, $to)->groupBy('class_id')->map(function (Collection $scores, int $classId) use ($classes) {
            $byDay = $scores->groupBy(fn ($s) => $s['date']->toDateString())->sortKeys();
            if ($byDay->count() < 2) {
                return null;
            }
            $first = (float) $byDay->first()->avg('score');
            $last = (float) $byDay->last()->avg('score');
            if ($first <= 0) {
                return null;
            }
            $growth = ($last - $first) / $first * 100;
            $class = $classes->firstWhere('id', $classId);
            $fmt = fn (float $v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');

            return [
                'growth' => $growth,
                'text' => 'Lớp '.($class?->code ?: $class?->name ?: '#'.$classId).': '.$fmt($first).' → '.$fmt($last).' ('.($growth >= 0 ? '+' : '').self::pct($growth).')',
            ];
        })->filter()->values();
        if ($rows->isEmpty()) {
            return ['value' => null, 'evidence' => []];
        }

        return [
            'value' => round((float) $rows->avg('growth'), 1),
            'evidence' => $rows->map(fn ($r) => ['date' => '', 'month' => '', 'text' => $r['text'], 'counted' => true, 'note' => null])->all(),
        ];
    }

    /** Tỷ lệ tháng trong kỳ nộp báo cáo tháng trước 12h ngày mùng 2 tháng sau (tháng chưa tới hạn mà chưa nộp thì chưa tính). */
    private function monthlyReportOnTime(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $evidence = [];
        $onTime = 0;
        $counted = 0;
        for ($month = Carbon::instance($from)->startOfMonth(); $month->lte($to); $month->addMonth()) {
            $key = $month->format('Y-m');
            $deadline = $month->copy()->addMonth()->setDay(self::MONTHLY_REPORT_DAY)->setTimeFromTimeString(self::MONTHLY_REPORT_DEADLINE);
            $report = StaffReport::where('user_id', $user->id)->where('type', 'monthly')->where('period_key', $key)->first(['created_at']);
            if (! $report && now()->lt($deadline)) {
                continue;
            }
            $counted++;
            $ok = $report && $report->created_at->lte($deadline);
            $onTime += $ok ? 1 : 0;
            $evidence[] = [
                'date' => $month->format('m/Y'),
                'month' => $key,
                'text' => $report ? 'Báo cáo tháng nộp lúc '.$report->created_at->format('H:i d/m').($ok ? '' : ' (trễ hạn)') : 'Chưa nộp báo cáo tháng',
                'counted' => ! $ok,
                'note' => null,
            ];
        }

        return ['value' => $counted ? round($onTime / $counted * 100, 1) : null, 'evidence' => $evidence];
    }

    // ───────────────────── Học thuật ─────────────────────

    /**
     * Tỉ lệ theo hệ số từng bản ghi (đúng hạn = 1, trễ nhẹ 0,5 / vừa 0,25 / nặng 0): % = trung bình hệ số. Ít hơn MIN_UNITS bản
     * ghi trong kỳ thì gộp thêm tháng trước. Không có bản ghi → null (Không phát sinh / điền tay). Dòng có cap = điều kiện chặn.
     *
     * @param  callable(CarbonInterface): Collection  $rows  bản ghi từ một ngày đến cuối kỳ: ['sort', 'text', 'factor' (null = không tính), 'note', 'cap']
     */
    private function factorRate(callable $rows, CarbonInterface $from): array
    {
        $list = $rows($from);
        $note = null;
        if ($list->whereNotNull('factor')->count() < self::MIN_UNITS) {
            $list = $rows(Carbon::instance($from)->startOfMonth()->subMonth());
            $note = 'Tháng có ít hơn '.self::MIN_UNITS.' bản ghi: gộp thêm tháng trước';
        }
        $counted = $list->whereNotNull('factor');
        if ($counted->isEmpty()) {
            return ['value' => null, 'evidence' => []];
        }
        $fmt = fn (float $f) => rtrim(rtrim(number_format($f, 2, ',', '.'), '0'), ',');
        $evidence = $list->sortBy('sort')->map(fn (array $r) => [
            'date' => $r['sort']->format('d/m'),
            'month' => $r['sort']->format('Y-m'),
            'text' => $r['text'].($r['factor'] !== null ? ' · hệ số '.$fmt($r['factor']) : ''),
            'counted' => $r['factor'] !== null,
            'note' => $r['note'] ?? null,
            'cap' => (bool) ($r['cap'] ?? false),
        ])->values()->all();
        if ($note) {
            array_unshift($evidence, ['date' => '', 'month' => '', 'text' => $note, 'counted' => true, 'note' => null, 'cap' => false]);
        }

        return ['value' => round((float) $counted->avg('factor') * 100, 1), 'evidence' => $evidence];
    }

    /** Hệ số trễ theo ngày của deliverable: đúng hạn 1; trễ 1–3 ngày 0,5; 4–7 ngày 0,25; từ 8 ngày 0. */
    private static function dayFactor(int $daysLate): float
    {
        return match (true) {
            $daysLate <= 0 => 1.0,
            $daysLate <= 3 => 0.5,
            $daysLate <= 7 => 0.25,
            default => 0.0,
        };
    }

    /**
     * Mốc dự án học thuật (deliverable theo master plan) đến hạn trong kỳ: mốc người này phụ trách, hoặc mốc chưa giao người
     * của dự án người này làm chủ. Mốc chưa xong mà chưa tới hạn thì chưa tính; quá hạn chưa xong tính trễ tới hôm nay.
     */
    private function deliverableRows(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $today = now()->startOfDay();

        return AcademicProjectMilestone::with('project:id,name,owner_id')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('project')
            ->where(fn ($q) => $q->where('assignee_id', $user->id)
                ->orWhere(fn ($w) => $w->whereNull('assignee_id')->whereHas('project', fn ($p) => $p->where('owner_id', $user->id))))
            ->get()
            ->map(function (AcademicProjectMilestone $m) use ($today) {
                $due = $m->due_date->copy()->startOfDay();
                $doneOn = $m->completed_at?->copy()->startOfDay();
                if (! $doneOn && $due->gte($today)) {
                    return null;
                }
                $late = max(0, (int) $due->diffInDays($doneOn ?? $today, false));
                $state = match (true) {
                    ! $doneOn => 'chưa xong, đã trễ '.$late.' ngày',
                    $late > 0 => 'xong trễ '.$late.' ngày',
                    default => 'xong đúng hạn',
                };

                return [
                    'sort' => $due,
                    'text' => 'Mốc "'.$m->title.'" ('.($m->project?->name ?? 'dự án').'): hạn '.$due->format('d/m').', '.$state,
                    'factor' => self::dayFactor($late),
                ];
            })->filter()->values();
    }

    /** Hệ số trễ theo giờ của task / phân bổ: đúng hạn 1; trễ ≤ 4 giờ 0,5; 4–24 giờ 0,25; quá 24 giờ 0. */
    private static function hourFactor(float $hoursLate): float
    {
        return match (true) {
            $hoursLate <= 0 => 1.0,
            $hoursLate <= 4 => 0.5,
            $hoursLate <= 24 => 0.25,
            default => 0.0,
        };
    }

    /** Việc được giao (trừ nhiệm vụ trực ca của TA) có hạn trong kỳ: hoàn thành so với hạn; chưa xong mà chưa tới hạn thì chưa tính. */
    private function taskRows(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $now = now();

        return WorkTask::withDeadline()->where('assignee_id', $user->id)->whereNotNull('due_date')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', '!=', 'canceled')
            ->get()
            ->map(function (WorkTask $t) use ($now) {
                $due = $t->dueAt();
                $done = $t->completed_at ?? (in_array($t->status, ['completed', 'pending_confirmation'], true) ? $t->updated_at : null);
                if (! $done && $now->lte($due)) {
                    return null;
                }
                $hours = $done && $done->lte($due) ? 0.0 : $due->diffInMinutes($done ?? $now) / 60;
                $late = $hours <= 0 ? 'đúng hạn' : 'trễ '.($hours < 1 ? (int) ceil($hours * 60).' phút' : rtrim(rtrim(number_format($hours, 1, ',', '.'), '0'), ',').' giờ');

                return [
                    'sort' => $due,
                    'text' => '"'.$t->title.'": hạn '.$due->format('H:i d/m').', '.($done ? 'xong '.$late : 'chưa xong, '.$late),
                    'factor' => self::hourFactor($hours),
                ];
            })->filter()->values();
    }

    /** Giờ dùng của order: giờ bắt đầu buổi học đầu tiên của lớp trong ngày sử dụng, không có buổi thì ORDER_DEFAULT_USE_TIME. */
    private static function orderUseAt(MaterialOrder $order): Carbon
    {
        $start = $order->class_id ? ClassSession::where('class_id', $order->class_id)->whereDate('date', $order->use_date->toDateString())
            ->where('status', '!=', 'cancelled')->orderBy('start_time')->value('start_time') : null;

        return $order->use_date->copy()->setTimeFromTimeString($start ? Carbon::parse($start)->format('H:i') : self::ORDER_DEFAULT_USE_TIME);
    }

    /**
     * Order "Học liệu học thuật" (Trưởng Học thuật xử lý) có ngày sử dụng trong kỳ, theo thời gian còn lại trước giờ dùng lúc
     * giao xong: ≥ 24 giờ 1; 12–24 giờ 0,5; 6–12 giờ 0,25; dưới 6 giờ 0; xong sau giờ dùng / quá giờ dùng chưa xong = 0 và là điều
     * kiện chặn. Order tạo khi còn dưới 24 giờ tới giờ dùng là lỗi bên yêu cầu: hiện để đối chiếu, không tính.
     */
    private function orderRows(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $now = now();

        return MaterialOrder::with('classRoom:id,code,name')->where('category', MaterialOrder::CATEGORY_ACADEMIC)
            ->whereBetween('use_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', '!=', MaterialOrder::STATUS_REJECTED)
            ->get()
            ->map(function (MaterialOrder $o) use ($now) {
                $use = self::orderUseAt($o);
                $done = $o->status === MaterialOrder::STATUS_DONE ? ($o->processed_at ?? $o->updated_at) : null;
                $label = 'Order '.$o->code.' ('.$o->title.($o->classRoom ? ', lớp '.($o->classRoom->code ?: $o->classRoom->name) : '').'): dùng '.$use->format('H:i d/m');
                if ($o->created_at->gt($use->copy()->subDay())) {
                    return ['sort' => $use, 'text' => $label, 'factor' => null, 'note' => 'Tạo sát giờ dùng dưới 24 giờ, lỗi bên yêu cầu, không tính'];
                }
                if (! $done && $now->lt($use)) {
                    return null;
                }
                if (! $done || $done->gte($use)) {
                    return ['sort' => $use, 'text' => $label.', '.($done ? 'giao sau giờ dùng' : 'quá giờ dùng chưa giao'), 'factor' => 0.0, 'cap' => true];
                }
                $hours = $done->diffInMinutes($use) / 60;
                $factor = match (true) {
                    $hours >= 24 => 1.0,
                    $hours >= 12 => 0.5,
                    $hours >= 6 => 0.25,
                    default => 0.0,
                };

                return ['sort' => $use, 'text' => $label.', giao xong '.$done->format('H:i d/m').' (trước '.(int) floor($hours).' giờ)', 'factor' => $factor];
            })->filter()->values();
    }

    /** % bài test (Big Test + mini test, thang 10) đạt chuẩn ≥ 7 của các lớp trong phạm vi Lớp học của người này, cuốn chiếu 3 tháng. */
    private function studentPassRate(User $user, CarbonInterface $to): array
    {
        $from = Carbon::instance($to)->startOfMonth()->subMonths(self::PASS_RATE_MONTHS - 1);
        $classes = ClassModel::query()->visibleTo($user)->get(['id', 'code', 'name']);
        $scores = $this->testScores($classes, $from, $to);
        if ($scores->isEmpty()) {
            return ['value' => null, 'evidence' => []];
        }
        $pass = fn (Collection $rows) => $rows->filter(fn ($s) => $s['score'] >= self::PASS_SCORE)->count();
        $evidence = [['date' => '', 'month' => '', 'text' => 'Từ '.$from->format('d/m/Y').' đến '.Carbon::instance($to)->format('d/m/Y').': '
            .$pass($scores).'/'.$scores->count().' bài đạt chuẩn', 'counted' => true, 'note' => null]];
        foreach ($scores->groupBy('class_id') as $classId => $rows) {
            $class = $classes->firstWhere('id', $classId);
            $evidence[] = [
                'date' => '',
                'month' => '',
                'text' => 'Lớp '.($class?->code ?: $class?->name ?: '#'.$classId).': '.$pass($rows).'/'.$rows->count().' bài đạt chuẩn ('.self::pct($pass($rows) / $rows->count() * 100).')',
                'counted' => true,
                'note' => null,
            ];
        }

        return ['value' => round($pass($scores) / $scores->count() * 100, 1), 'evidence' => $evidence];
    }

    /** Ngày chấm công đi muộn quá STAFF_LATE_MINUTES phút, không có đơn đi muộn được duyệt. */
    private function staffLate(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return StaffAttendance::where('user_id', $user->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->where('late_minutes', '>', self::STAFF_LATE_MINUTES)->where('late_excused', false)
            ->orderBy('work_date')->get()
            ->map(fn (StaffAttendance $a) => [
                'sort' => $a->work_date,
                'text' => 'Đi muộn '.(int) $a->late_minutes.' phút'.($a->check_in_at ? ' (check-in '.$a->check_in_at->format('H:i').')' : ''),
            ]);
    }
}
