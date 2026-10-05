<?php

namespace App\Services\Sla;

use App\Models\AcademicProjectMilestone;
use App\Models\AdminNotification;
use App\Models\SlaEvent;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SLA dự án học thuật: mốc của dự án đã chốt tiến độ (Đang thực hiện) chưa hoàn thành khi qua hạn + ân hạn → biên bản
 * "chờ giải trình" cho người nhận mốc (SlaBreachService, loại lỗi chuyên môn → Học thuật / Admin chốt). Hoàn thành trễ
 * mà lần quét chưa kịp bắt cũng lập biên bản. Trước hạn 24 giờ nhắc người nhận mốc một lần.
 * Chỉ tính mốc có hạn sau thời điểm bật (không phạt hồi tố).
 */
class AcademicProjectSlaService
{
    private const SINCE_KEY = 'sla_academic_project_live_since';


    public function __construct(private SlaBreachService $breaches) {}

    /** @return array<string, int> */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $since = $this->liveSince($now);

        return [
            AcademicProjectMilestone::SLA_RULE => Sla::enabled(AcademicProjectMilestone::SLA_RULE) ? $this->breachLate($since, $now) : 0,
            'academic.milestone_reminder' => $this->remind($now),
        ];
    }

    private function liveSince(Carbon $now): Carbon
    {
        $value = SystemSetting::get(self::SINCE_KEY);
        if (! $value) {
            SystemSetting::set(self::SINCE_KEY, $now->toIso8601String(), 'Thời điểm bắt đầu áp dụng SLA trễ mốc dự án học thuật (mốc hạn trước đó không bị phạt hồi tố)');

            return $now->copy();
        }

        return Carbon::parse($value);
    }

    /** Mốc của dự án đang thực hiện (dự án / mốc chưa xóa). */
    private function activeMilestones(): Builder
    {
        return AcademicProjectMilestone::query()
            ->whereHas('project', fn (Builder $p) => $p->where('status', 'active'));
    }

    private function breachLate(Carbon $since, Carbon $now): int
    {
        $grace = Sla::value(AcademicProjectMilestone::SLA_RULE);
        $breached = SlaEvent::query()->where('rule_key', AcademicProjectMilestone::SLA_RULE)
            ->where('subject_type', AcademicProjectMilestone::SLA_SUBJECT)->whereNotNull('breached_at')->select('subject_id');
        $count = 0;

        $this->activeMilestones()->with(['project.owner', 'assignee'])
            // Ngày hạn ≤ hôm nay (lọc thô); giờ chính xác so ở dưới.
            ->whereDate('due_date', '<=', $now->toDateString())
            ->where(fn (Builder $q) => $q->where('status', '!=', 'done')->orWhereColumn('completed_at', '>', 'due_date'))
            ->whereNotIn('id', $breached)
            ->orderBy('id')
            ->each(function (AcademicProjectMilestone $milestone) use ($grace, $since, $now, &$count) {
                $dueAt = $milestone->dueAt();
                $breachAt = $dueAt->copy()->addHours($grace);
                if ($dueAt->lt($since) || $breachAt->gt($now)) {
                    return;
                }
                if ($milestone->isDone() && ! $milestone->completed_at?->gt($breachAt)) {
                    return;
                }

                $project = $milestone->project;
                $owner = $milestone->assignee ?? $project->owner;
                $event = $this->breaches->open(AcademicProjectMilestone::SLA_RULE, AcademicProjectMilestone::SLA_SUBJECT, $milestone->id, $owner, $dueAt, $breachAt);
                // Tên biên bản lưu cột 255 ký tự: rút gọn tên dự án / mốc dài.
                $label = $project->code.' '.Str::limit($project->name, 60).' — mốc "'.Str::limit($milestone->title, 80).'"';
                $this->breaches->breach($event, $label, [
                    'Dự án' => "{$project->code} · {$project->name}",
                    'Mốc' => $milestone->title,
                    'Người nhận mốc' => $milestone->assignee?->name ?? 'Chưa giao (tính cho người phụ trách dự án)',
                    'Deadline mốc' => $milestone->due_date->format('d/m/Y'),
                    'Khối lượng' => AcademicProjectMilestone::formatQuantity($milestone->done_quantity).' / '
                        .AcademicProjectMilestone::formatQuantity($milestone->target_quantity).' '.$milestone->unit,
                    'Hoàn thành lúc' => $milestone->completed_at ? $milestone->completed_at->format('H:i d/m/Y').' (trễ hạn)' : 'Chưa hoàn thành',
                ], route('academic-projects.show', $project->id), $now);
                if ($milestone->completed_at) {
                    $this->breaches->resolve($event, $milestone->completed_at);
                }
                $count++;
            });

        return $count;
    }

    /** Nhắc người nhận mốc trước hạn N giờ (SLA academic.milestone_remind, mặc định 24h; một lần; đổi hạn thì nhắc lại). */
    private function remind(Carbon $now): int
    {
        if (! Sla::enabled('academic.milestone_remind')) {
            return 0;
        }
        $count = 0;
        $hours = Sla::value('academic.milestone_remind');

        $this->activeMilestones()->with('project')
            ->where('status', '!=', 'done')->whereNull('reminded_at')->whereNotNull('assignee_id')
            ->whereDate('due_date', '>=', $now->toDateString())
            ->whereDate('due_date', '<=', $now->copy()->addHours($hours)->toDateString())
            ->each(function (AcademicProjectMilestone $milestone) use ($now, $hours, &$count) {
                if ($milestone->dueAt()->copy()->subHours($hours)->gt($now) || $milestone->dueAt()->lt($now)) {
                    return;
                }
                AdminNotification::create([
                    'user_id' => $milestone->assignee_id,
                    'type' => 'academic_project_due',
                    'title' => "Sắp đến hạn mốc \"{$milestone->title}\"",
                    'message' => "Dự án {$milestone->project->name}: hạn 23:59 {$milestone->due_date->format('d/m/Y')}. Cập nhật tiến độ và đánh dấu hoàn thành trước hạn để không bị lập biên bản trễ deadline.",
                    'data' => ['link' => route('academic-projects.show', $milestone->academic_project_id)],
                    'is_read' => false,
                ]);
                $milestone->forceFill(['reminded_at' => $now])->save();
                $count++;
            });

        return $count;
    }
}
