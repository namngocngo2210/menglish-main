<?php

namespace App\Services\Kpi;

use App\Models\AdminNotification;
use App\Models\CrmCustomer;
use App\Models\MaterialOrder;
use App\Models\MerchandiseStockMovement;
use App\Models\Penalty;
use App\Models\SlaEvent;
use App\Models\StaffReport;
use App\Models\StudentTuition;
use App\Models\TuitionContactLog;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\FirstMonthCareService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Đếm số liệu KPI tự động (KpiCriterion::AUTO_SOURCES) của một nhân sự trong một tháng, từ dữ liệu sẵn có của hệ thống.
 * Mỗi bản ghi đếm được là một dòng bằng chứng: ['date' => 'd/m', 'text' => …, 'counted' => bool, 'note' => ?string].
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

    /** @return list<array{date: string, text: string, counted: bool, note: ?string}> */
    public function evidence(string $source, User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = match ($source) {
            'tuition_no_reminder' => $this->tuitionNoReminder($user, $from, $to),
            'receipt_rejected' => $this->receiptRejected($user, $from, $to),
            'care_overdue' => $this->careOverdue($user, $from, $to),
            'crm_sla_late' => $this->crmSlaLate($user, $from, $to),
            'daily_report_late' => $this->dailyReportLate($user, $from, $to),
            'material_stock' => $this->materialStock($user, $from, $to),
            default => collect(),
        };

        return $rows->sortBy('sort')->map(fn (array $r) => [
            'date' => $r['sort']->format('d/m'),
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
}
