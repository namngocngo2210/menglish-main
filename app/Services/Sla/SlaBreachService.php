<?php

namespace App\Services\Sla;

use App\Models\AdminNotification;
use App\Models\Penalty;
use App\Models\SlaEvent;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\BranchStaff;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Máy chung của SLA: mở dòng sổ khi mốc được kích hoạt (kèm việc tự giao), và khi quá hạn thì tự lập biên bản phạt
 * (pending, người báo "Tự động", kèm file PDF chi tiết) + báo người phụ trách và Admin. Idempotent theo sổ sla_events.
 */
class SlaBreachService
{
    /**
     * Mở mốc SLA cho một đối tượng (idempotent). Trả dòng sổ; $created cho biết lần đầu mở.
     *
     * @param  array{title: string, description: string, branch_id?: int|null}|null  $task  có giá trị → tự giao việc cho $owner
     */
    public function open(string $rule, string $subjectType, int $subjectId, ?User $owner, Carbon $triggeredAt, Carbon $dueAt, ?array $task = null, ?bool &$created = null): SlaEvent
    {
        $event = SlaEvent::firstOrCreate(
            ['rule_key' => $rule, 'subject_type' => $subjectType, 'subject_id' => $subjectId],
            ['user_id' => $owner?->id, 'triggered_at' => $triggeredAt, 'due_at' => $dueAt],
        );
        $created = $event->wasRecentlyCreated;

        if ($created && $task && $owner && Sla::rule($rule)['task']) {
            $workTask = WorkTask::create([
                'title' => $task['title'],
                'description' => $task['description'],
                'creator_id' => $owner->id,
                'assignee_id' => $owner->id,
                'branch_id' => $task['branch_id'] ?? null,
                'task_type' => 'one_time',
                'due_date' => $dueAt->toDateString(),
                'due_time' => $dueAt->format('H:i'),
                'status' => 'new',
            ]);
            $event->update(['work_task_id' => $workTask->id]);
            AdminNotification::create([
                'user_id' => $owner->id,
                'type' => 'task_assigned',
                'title' => 'Việc mới: '.$task['title'],
                'message' => 'Hạn '.$dueAt->format('H:i d/m/Y').'.',
                'data' => ['task_id' => $workTask->id, 'link' => route('tasks.index', ['tab' => 'mine'])],
                'is_read' => false,
            ]);
        }

        return $event;
    }

    /** Đóng mốc khi người phụ trách đã làm xong (kể cả trễ — trễ thì biên bản đã lập từ lúc quá hạn); đóng luôn việc tự giao. */
    public function resolve(SlaEvent $event, Carbon $at): void
    {
        if ($event->resolved_at) {
            return;
        }
        $event->update(['resolved_at' => $at]);
        if ($event->work_task_id) {
            WorkTask::whereKey($event->work_task_id)->whereIn('status', [...WorkTask::OPEN_STATUSES, 'overdue'])
                ->update(['status' => 'completed', 'completed_at' => $at]);
        }
    }

    /**
     * Quá hạn → biên bản + thông báo (một lần). Chưa bật phạt cho SLA này thì chỉ báo Admin + người phụ trách.
     *
     * @param  array<string, string>  $facts  các dòng "nhãn => giá trị" in vào file chi tiết
     */
    public function breach(SlaEvent $event, string $subjectLabel, array $facts, string $link, ?Carbon $now = null): ?Penalty
    {
        if ($event->breached_at) {
            return null;
        }
        $now ??= now();
        $rule = Sla::rule($event->rule_key);
        $owner = $event->user_id ? User::find($event->user_id) : null;
        $penalty = null;

        DB::transaction(function () use ($event, $rule, $owner, $now, $subjectLabel, $facts, &$penalty) {
            if ($rule['penalty'] && $owner) {
                // Bậc phạt theo lần tái phạm cộng dồn trong N tháng (không tính biên bản đã hủy).
                $occurrence = Penalty::where('user_id', $owner->id)->where('auto_source', $event->rule_key)->where('status', '!=', 'cancelled')
                    ->where('created_at', '>=', $now->copy()->subMonths(Sla::ladderResetMonths()))->count() + 1;
                $amount = Sla::amountForOccurrence($rule, $occurrence);
                $ladderNote = ! empty($rule['ladder'])
                    ? " Lần thứ {$occurrence} trong ".Sla::ladderResetMonths().' tháng'.($amount > 0 ? '.' : ' (mức nhắc nhở, không phạt tiền).')
                    : '';
                $penalty = Penalty::create([
                    'code' => Penalty::generateCode(),
                    'user_id' => $owner->id,
                    'work_task_id' => $event->work_task_id,
                    'auto_source' => $event->rule_key,
                    'violation_type' => $rule['violation'].": {$subjectLabel}",
                    'error_category' => $rule['category'] ?? 'operations',
                    'violation_date' => $event->due_at->toDateString(),
                    'violation_at' => $event->due_at,
                    'amount' => $amount,
                    'reporter_id' => null,
                    'status' => 'pending',
                    'notes' => "{$rule['label']}: hạn {$event->due_at->format('H:i d/m/Y')}, quá hạn lúc phát hiện {$now->format('H:i d/m/Y')}.{$ladderNote} Xem file chi tiết đính kèm.",
                ]);
                $penalty->update(['evidence_path' => $this->detailFile($penalty, $rule, $event, $subjectLabel, $facts, $now)]);
            }
            $event->update(['breached_at' => $now, 'penalty_id' => $penalty?->id]);
            if ($event->work_task_id) {
                WorkTask::whereKey($event->work_task_id)->whereIn('status', WorkTask::OPEN_STATUSES)->update(['status' => 'overdue', 'sla_breached_at' => $now]);
            }
        });

        $title = $penalty
            ? "Biên bản {$penalty->code}: {$rule['violation']} — {$subjectLabel}"
            : "Quá hạn SLA: {$rule['label']} — {$subjectLabel}";
        $data = ['link' => $penalty ? route('penalties.index', ['search' => $penalty->code]) : $link, 'sla_rule' => $event->rule_key];
        $type = $penalty ? 'penalty_created' : 'sla_breach';
        $message = $penalty
            ? 'Hãy xử lý và gửi giải trình; biên bản chờ CM / Học thuật / Admin xác nhận trước khi tính vào lương.'
            : 'Hạn '.$event->due_at->format('H:i d/m/Y').' đã qua.';

        $recipients = collect([$owner])->merge(BranchStaff::admins())->filter()->unique('id');
        foreach ($recipients as $user) {
            AdminNotification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'message' => ($user->id === $owner?->id ? 'Bạn là người phụ trách. ' : ($owner ? "Người phụ trách: {$owner->name}. " : 'Chưa có người phụ trách. ')).$message,
                'data' => $data,
                'is_read' => false,
            ]);
        }

        return $penalty;
    }

    /** File PDF chi tiết đính kèm biên bản tự động (mở qua route penalties.evidence). */
    private function detailFile(Penalty $penalty, array $rule, SlaEvent $event, string $subjectLabel, array $facts, Carbon $now): string
    {
        $rows = ['Biên bản' => $penalty->code, 'Quy định SLA' => $rule['label'], 'Đối tượng' => $subjectLabel,
            'Người phụ trách' => $penalty->user?->name ?? '—',
            'Mốc kích hoạt' => $event->triggered_at->format('H:i d/m/Y'),
            'Hạn xử lý' => $event->due_at->format('H:i d/m/Y').' ('.$rule['value'].($rule['unit'] === 'hours' ? ' giờ' : ' lần').')',
            'Phát hiện quá hạn' => $now->format('H:i d/m/Y'),
            ...$facts,
            ...($penalty->amount > 0 ? ['Mức phạt gợi ý' => number_format((float) $penalty->amount, 0, ',', '.').' đ'] : ['Mức phạt gợi ý' => 'Nhắc nhở (0 đ)'])];
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px}td{padding:4px 8px;border-bottom:1px solid #ddd}td:first-child{font-weight:bold;width:35%}</style></head><body>'
            .'<h2>Chi tiết biên bản vi phạm SLA (tự động)</h2><table width="100%">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td>'.e($label).'</td><td>'.e($value).'</td></tr>';
        }
        $html .= '</table><p>Biên bản do hệ thống tự lập, đang chờ giải trình; chỉ tính vào lương sau khi được xác nhận.</p></body></html>';

        $path = Penalty::EVIDENCE_DIRECTORY.'/sla-'.$penalty->code.'.pdf';
        Storage::disk(Penalty::EVIDENCE_DISK)->put($path, Pdf::loadHTML($html)->output());

        return $path;
    }
}
