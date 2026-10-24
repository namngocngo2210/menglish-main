<?php

namespace App\Services\Reports;

use App\Models\AcademicObservation;
use App\Models\AcademicProjectMilestone;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\KpiCriterion;
use App\Models\MaterialOrder;
use App\Models\MiniTestScore;
use App\Models\QaObservation;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportTicket;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Kpi\KpiAutoCounter;
use App\Services\Reports\Concerns\BuildsReportItems;
use App\Support\DailyReportDue;
use App\Support\ReportPeriod;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Số liệu tự động của báo cáo tuần (chủ dự án 15/10/2026, đề xuất /mnt/project-files/bao-cao-ngay-mapping/mapping-bao-cao-tuan.md):
 * tính lại từ dữ liệu gốc cho cả tuần (thứ 2 → chủ nhật) theo cùng cách đếm của báo cáo ngày — không cộng các báo cáo ngày
 * đã nộp (ngày quên nộp / số gốc sửa sau khi nộp vẫn đúng). Từ báo cáo ngày chỉ lấy ghi chú tay và ngày nào đã nộp.
 *
 * - Học vụ (báo cáo tuần KPI): 9 chỉ số quy mô + số lần phát sinh từng tiêu chí KPI Học vụ (tiêu chí có nguồn tự động đếm
 *   bằng KpiAutoCounter như phiếu KPI tháng, còn lại đếm Nhật ký Học vụ có gắn mã KPI).
 * - Học thuật (báo cáo tuần Học thuật): tóm tắt số của tuần + các dòng gợi ý điền sẵn vào 3 bảng.
 */
class WeeklyReportMetrics
{
    use BuildsReportItems;

    /** Mỗi bảng báo cáo tuần Học thuật nhận tối đa 50 dòng; gợi ý giữ dưới mức đó. */
    private const MAX_SUGGESTIONS = 30;

    private Carbon $from;

    private Carbon $to;

    public function __construct(private readonly User $user, string $week)
    {
        [$this->from, $this->to] = ReportPeriod::weekRange($week);
    }

    // ───────────────────────── HỌC VỤ ─────────────────────────

    /** @return array{metrics: array, groups: list<array>, daily: array, computed_at: string} */
    public static function operations(User $user, string $week): array
    {
        $self = new self($user, $week);

        return [
            'metrics' => $self->scaleMetrics(),
            'groups' => $self->kpiGroups(),
            'daily' => $self->dailyReports(),
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /** Bản chữ (cột `content`) của báo cáo tuần Học vụ. */
    public static function operationsText(array $snapshot, ?string $note): string
    {
        $kpi = collect($snapshot['groups'] ?? [])->flatMap(fn (array $g) => collect($g['items'])
            ->filter(fn (array $i) => $i['count'] > 0)
            ->map(fn (array $i) => "{$g['name']} — {$i['name']}: {$i['count']} lần"));
        $metrics = collect($snapshot['metrics']['items'] ?? [])->map(fn (array $i) => $i['label'].': '.$i['value']);
        $daily = collect($snapshot['daily']['notes'] ?? [])->map(fn (array $n) => $n['label'].":\n".collect($n['lines'])->map(fn ($l) => '    · '.$l['text'])->implode("\n"));

        return collect([
            $kpi->isEmpty() ? 'Số lần phát sinh theo mục KPI: không có phát sinh.' : "Số lần phát sinh theo mục KPI:\n".$kpi->implode("\n"),
            "Chỉ số quy mô:\n".$metrics->implode("\n"),
            $daily->isEmpty() ? null : "Ghi chú báo cáo ngày:\n".$daily->implode("\n"),
            filled($note) ? 'Ghi chú tuần: '.$note : null,
        ])->filter()->implode("\n\n");
    }

    private function scaleMetrics(): array
    {
        $classIds = $this->classIds();
        $sessions = ClassSession::with('classModel:id,name')
            ->whereDate('date', '>=', $this->from)->whereDate('date', '<=', $this->to)
            ->where('type', '!=', ClassSession::TYPE_SUPPORT)
            ->where('status', '!=', 'cancelled')
            ->whereIn('class_id', $classIds)
            ->get(['id', 'class_id', 'date', 'type', 'status', 'teacher_id', 'foreign_teacher_id']);
        $runningIds = $sessions->pluck('class_id')->unique()->values();
        $classNames = $sessions->mapWithKeys(fn (ClassSession $s) => [$s->class_id => $s->classModel?->name ?? 'Lớp #'.$s->class_id]);

        $enrollments = ClassEnrollment::with('student:id,name')
            ->whereIn('class_id', $runningIds)
            // Lượt đã chuyển lớp vẫn thuộc lớp tới trước ngày rời lớp (tuần đã qua).
            ->whereIn('status', [...Student::ACTIVE_ENROLLMENT_STATUSES, Student::ENROLLMENT_TRANSFERRED])
            ->whereDate('enrolled_at', '<=', $this->to)
            ->where(fn (Builder $q) => $q->whereNull('left_at')->orWhereDate('left_at', '>', $this->to))
            ->get(['id', 'class_id', 'student_id']);

        $teachers = User::whereIn('id', $sessions->flatMap(fn (ClassSession $s) => [$s->teacher_id, $s->foreign_teacher_id])->filter()->unique())
            ->orderBy('name')->get(['id', 'name']);

        $leads = CrmCustomer::query()->visibleTo($this->user)->whereBetween('created_at', [$this->from, $this->to])
            ->when($this->reportBranchIds() !== [], fn (Builder $q) => $q->whereIn('branch_id', $this->reportBranchIds()))
            ->get(['id', 'source']);

        $closed = CrmCustomerHistory::with('customer:id,name')
            ->where('type', 'stage_change')->whereIn('to_stage', CrmCustomer::CLOSED_STAGES)
            ->whereBetween('created_at', [$this->from, $this->to])
            ->whereHas('customer', fn (Builder $c) => $c->visibleTo($this->user)
                ->when($this->reportBranchIds() !== [], fn (Builder $b) => $b->whereIn('branch_id', $this->reportBranchIds())))
            ->get(['id', 'customer_id', 'to_stage', 'created_at'])
            ->unique('customer_id');

        $transfers = $this->transfers($classIds);

        $makeups = StudentAttendance::with(['student:id,name', 'classSession.classModel:id,name'])
            ->whereIn('status', ['present', 'late'])
            ->whereHas('classSession', fn (Builder $s) => $s->where('type', ClassSession::TYPE_MAKEUP)->whereIn('class_id', $classIds)
                ->whereDate('date', '>=', $this->from)->whereDate('date', '<=', $this->to))
            ->get()
            ->unique('student_id');

        $care = WorkTask::with(['student:id,name,current_class_id', 'classModel:id,name'])
            ->whereNotNull('care_milestone')
            ->whereIn('status', WorkTask::OPEN_STATUSES)
            ->whereDate('due_date', '<=', $this->to)
            ->get(['id', 'class_id', 'student_id', 'care_milestone', 'due_date', 'title'])
            ->map(fn (WorkTask $t) => [$t, (int) ($t->class_id ?: $t->student?->current_class_id)])
            ->filter(fn (array $pair) => in_array($pair[1], $classIds, true));
        $careClasses = $care->groupBy(fn (array $pair) => $pair[1]);
        $careNames = ClassModel::withTrashed()->whereIn('id', $careClasses->keys())->pluck('name', 'id');

        $nextWeekEnd = $this->to->copy()->addWeek();
        $bigTests = BigTest::with('classModel:id,name')
            ->whereIn('class_id', $classIds)
            ->whereBetween('scheduled_at', [$this->from, $nextWeekEnd])
            ->orderBy('scheduled_at')
            ->get(['id', 'class_id', 'scheduled_at']);

        return $this->section('Chỉ số quy mô', 'monitoring', [
            $this->item('classes_running', 'Số lớp đang chạy', $runningIds->count(),
                'Số lớp có ít nhất một buổi học trong tuần (không tính buổi hủy / nghỉ và buổi bổ trợ).',
                $runningIds->map(fn ($id) => ['text' => $classNames[$id], 'url' => $this->link('classes.show', $id)])->sortBy('text')->values()->all()),
            $this->item('students', 'Số học sinh', $enrollments->pluck('student_id')->unique()->count(),
                'Học sinh đang học (chưa rời lớp tính đến cuối tuần) của các lớp đang chạy.'),
            $this->item('teachers', 'Số GV phụ trách', $teachers->count(),
                'Số giáo viên (gồm GVNN) đứng lớp trong tuần theo lịch buổi học.',
                $teachers->map(fn (User $u) => ['text' => $u->name])->all()),
            $this->item('new_leads', 'Data mới nhận', $leads->count(),
                'Khách mới tạo trong CRM trong tuần, chia theo nguồn (bằng tổng "Data mới nhận" các báo cáo ngày).',
                $leads->groupBy(fn ($c) => $c->source ?: 'Chưa rõ nguồn')->map(fn ($g, $source) => ['text' => "{$source}: {$g->count()}"])->values()->all()),
            $this->item('new_closed', 'HV mới chốt', $closed->count(),
                'Khách CRM chuyển sang "Chờ xếp lớp" hoặc "Đã chốt" trong tuần.',
                $closed->map(fn (CrmCustomerHistory $h) => [
                    'text' => ($h->customer?->name ?? 'Khách').' · '.$h->created_at->format('d/m'),
                    'url' => $this->link('crm.customers.show', $h->customer_id),
                ])->values()->all()),
            $this->item('transfers', 'HV chuyển lớp', $transfers->count(),
                'Học sinh rời một lớp và vào lớp khác trong tuần (suy ra từ ghi danh, hệ thống chưa có bản ghi "chuyển lớp" riêng).',
                $transfers->all()),
            $this->item('makeups', 'HV học bù', $makeups->count(),
                'Học sinh có mặt ở buổi học bù trong tuần (theo điểm danh).',
                $makeups->map(fn (StudentAttendance $a) => ['text' => ($a->student?->name ?? 'Học sinh').' · '.($a->classSession?->classModel?->name ?? '')])->values()->all()),
            $this->item('care_due_classes', 'Lớp đến hạn chăm sóc', $careClasses->count(),
                'Lớp có học sinh đến mốc chăm sóc tháng đầu trong tuần (việc chăm sóc đến hạn hoặc quá hạn, chưa hoàn thành).',
                $careClasses->map(fn (Collection $pairs, $classId) => [
                    'text' => ($careNames[$classId] ?? 'Lớp #'.$classId).': '.self::names($pairs->map(fn ($p) => $p[0]->student?->name ?? $p[0]->title)),
                    'tone' => $pairs->contains(fn ($p) => $p[0]->due_date?->lt($this->from)) ? 'warning' : null,
                    'url' => $this->link('classes.show', $classId),
                ])->values()->all()),
            $this->item('upcoming_test_classes', 'Số lớp sắp lịch test', $bigTests->pluck('class_id')->unique()->count(),
                'Lớp có Big Test lên lịch trong tuần này và tuần kế tiếp.',
                $bigTests->map(fn (BigTest $t) => ['text' => ($t->classModel?->name ?? 'Lớp').' · '.$t->scheduled_at->format('d/m'), 'url' => $this->link('classes.show', $t->class_id)])->values()->all()),
        ]);
    }

    /** Học sinh rời lớp (không phải thôi học) trong tuần và có ghi danh vào lớp khác trong tuần. */
    private function transfers(array $classIds): Collection
    {
        $left = ClassEnrollment::with(['student:id,name', 'classModel:id,name'])
            ->whereIn('class_id', $classIds)
            ->whereDate('left_at', '>=', $this->from)->whereDate('left_at', '<=', $this->to)
            ->where('status', '!=', Student::ENROLLMENT_DROPPED)
            ->get(['id', 'class_id', 'student_id', 'left_at']);
        if ($left->isEmpty()) {
            return collect();
        }
        $joined = ClassEnrollment::with('classModel:id,name')
            ->whereIn('student_id', $left->pluck('student_id'))
            ->whereDate('enrolled_at', '>=', $this->from)->whereDate('enrolled_at', '<=', $this->to)
            ->get(['id', 'class_id', 'student_id', 'enrolled_at']);

        return $left->map(function (ClassEnrollment $out) use ($joined) {
            $in = $joined->first(fn (ClassEnrollment $e) => (int) $e->student_id === (int) $out->student_id && (int) $e->class_id !== (int) $out->class_id);

            return $in ? ['text' => ($out->student?->name ?? 'Học sinh').': '.($out->classModel?->name ?? '').' → '.($in->classModel?->name ?? ''),
                'url' => $this->link('students.show', $out->student_id)] : null;
        })->filter()->unique('text')->values();
    }

    /** Tiêu chí KPI Học vụ đang áp dụng, nhóm theo cấu hình; mỗi mục: số lần + nguồn + các bản ghi đã đếm. */
    private function kpiGroups(): array
    {
        $counter = app(KpiAutoCounter::class);
        $journal = StaffReport::where('type', 'journal')->where('user_id', $this->user->id)
            ->whereNotNull('kpi_code')
            ->whereDate('report_date', '>=', $this->from)->whereDate('report_date', '<=', $this->to)
            ->orderBy('report_date')->get(['id', 'kpi_code', 'title', 'content', 'report_date'])
            ->groupBy('kpi_code');

        return KpiCriterion::forRole(Roles::ACADEMIC_STAFF)->active()->ordered()->get()
            ->groupBy('group_name')
            ->map(fn (Collection $items, $group) => [
                'name' => $group,
                'items' => $items->map(function (KpiCriterion $c) use ($counter, $journal) {
                    if ($c->isAuto() && ! $c->isRateSource()) {
                        $evidence = $counter->evidence($c->auto_source, $this->user, $this->from, $this->to);
                        $source = 'auto';
                        $info = 'Hệ thống tự đếm: '.KpiCriterion::AUTO_SOURCES[$c->auto_source].' (cùng cách đếm phiếu KPI tháng).';
                        $count = KpiAutoCounter::countOf($evidence);
                        $details = array_map(fn (array $e) => [
                            'text' => $e['date'].' · '.$e['text'].($e['note'] ? ' ('.$e['note'].')' : ''),
                            'tone' => $e['counted'] ? null : 'muted',
                        ], $evidence);
                    } else {
                        $rows = $c->code ? $journal->get((string) $c->code, collect()) : collect();
                        $source = 'journal';
                        $info = 'Số sự vụ trong Nhật ký Học vụ của bạn trong tuần có gắn mã KPI '.($c->code ?: '—').'. Ghi thêm sự vụ ở Nhật ký Học vụ nếu còn thiếu.';
                        $count = $rows->count();
                        $details = $rows->map(fn (StaffReport $r) => [
                            'text' => $r->report_date->format('d/m').' · '.Str::limit(trim((string) ($r->content ?: $r->title)), 140),
                            'url' => $this->link('reports.journal'),
                        ])->values()->all();
                    }

                    return [
                        'id' => $c->id,
                        'code' => $c->code,
                        'name' => $c->name,
                        'unit' => KpiCriterion::unitLabel($c->unit),
                        'source' => $source,
                        'count' => $count,
                        'info' => $info,
                        'details' => [...$details, ...$this->kpiHints($c)],
                    ];
                })->values()->all(),
            ])->values()->all();
    }

    /** Dòng tham khảo (không đếm) kèm tiêu chí: báo cáo ngày đã nộp, buổi chưa điểm danh, ticket cơ sở vật chất. */
    private function kpiHints(KpiCriterion $c): array
    {
        $hint = fn (string $text, ?string $url = null) => ['text' => $text, 'tag' => 'Tham khảo', 'url' => $url];

        if ($c->auto_source === 'daily_report_late') {
            $daily = $this->dailyReports();

            return [$hint("Báo cáo ngày đã nộp {$daily['submitted']}/{$daily['expected']} ngày"
                .($daily['missing'] ? ' · còn thiếu '.implode(', ', $daily['missing']) : ''), $this->link('reports.periodic.daily'))];
        }
        if ($c->auto_source === 'material_stock') {
            return $this->facilityTickets()->map(fn (SupportTicket $t) => $hint("Ticket CSVC {$t->code} · {$t->title}", $this->link('tickets.show', $t->id)))->all();
        }
        if ((string) $c->code === '5.1') {
            return ClassSession::with('classModel:id,name')
                ->whereIn('class_id', $this->classIds())
                ->whereDate('date', '>=', $this->from)->whereDate('date', '<', min($this->to->copy()->addDay()->startOfDay(), today()))
                ->where('type', '!=', ClassSession::TYPE_SUPPORT)
                ->whereNotIn('status', ['cancelled', 'completed'])
                ->orderBy('date')->get(['id', 'class_id', 'date'])
                ->map(fn (ClassSession $s) => $hint('Buổi chưa điểm danh · '.($s->classModel?->name ?? 'Lớp').' · '.$s->date->format('d/m'), $this->link('classes.show', $s->class_id)))
                ->all();
        }

        return [];
    }

    private function facilityTickets(): Collection
    {
        // Báo cáo liệt kê sự cố cơ sở vật chất của chi nhánh người lập báo cáo (reportBranchIds), không theo phạm vi ticket của người xem.
        return SupportTicket::withoutViewerScope()->where('category', 'facilities')
            ->whereBetween('created_at', [$this->from, $this->to])
            ->when($this->reportBranchIds() !== [], fn (Builder $q) => $q->whereHas('creator', fn (Builder $u) => $u->whereIn('branch_id', $this->reportBranchIds())))
            ->orderBy('created_at')->get(['id', 'code', 'title']);
    }

    private ?array $daily = null;

    /** Báo cáo ngày của người viết trong tuần: số ngày đã nộp / phải nộp (đến hôm qua) và ghi chú tay gộp theo khối. */
    private function dailyReports(): array
    {
        if ($this->daily !== null) {
            return $this->daily;
        }
        $reports = StaffReport::where('kind', 'daily_ops')->where('user_id', $this->user->id)
            ->whereBetween('period_key', [$this->from->toDateString(), $this->to->toDateString()])
            ->orderBy('period_key')->get(['id', 'period_key', 'data']);

        // Ngày phải nộp: thứ Hai → thứ Bảy đã qua, trừ ngày lễ và nghỉ phép đã duyệt (DailyReportDue).
        $due = DailyReportDue::requiredDays($this->user, $this->from, $this->to);
        $missing = DailyReportDue::missingDays($this->user, $this->from, $this->to)->map(fn (Carbon $d) => $d->format('d/m'))->values()->all();

        $notes = collect(DailyReportMetrics::NOTE_LABELS)->map(fn (string $label, string $key) => [
            'key' => $key,
            'label' => $label,
            'lines' => $reports->map(fn (StaffReport $r) => filled($r->data['notes'][$key] ?? null)
                ? ['text' => Carbon::parse($r->period_key)->format('d/m').': '.$r->data['notes'][$key]] : null)->filter()->values()->all(),
        ])->filter(fn (array $n) => $n['lines'] !== [])->values()->all();

        return $this->daily = [
            'submitted' => $due->count() - count($missing),
            'expected' => $due->count(),
            'missing' => $missing,
            'notes' => $notes,
        ];
    }

    // ───────────────────────── HỌC THUẬT ─────────────────────────

    /** @return array{summary: array, suggestions: array<string, list<array>>, computed_at: string} */
    public static function academic(User $user, string $week): array
    {
        $self = new self($user, $week);

        return ['summary' => $self->academicSummary(), 'suggestions' => $self->academicSuggestions(), 'computed_at' => now()->toIso8601String()];
    }

    /** Mốc dự án "đang mở" so với mốc thời gian: tuần đã qua thì cuối tuần, tuần hiện tại thì hôm nay. */
    private function reference(): Carbon
    {
        return $this->to->isPast() ? $this->to->copy()->startOfDay() : today();
    }

    private function milestones(): Builder
    {
        return AcademicProjectMilestone::with(['project:id,name,code', 'assignee:id,name'])
            ->whereHas('project', fn (Builder $p) => $p->visibleTo($this->user));
    }

    private function academicSummary(): array
    {
        $dueWeek = $this->milestones()->whereDate('due_date', '>=', $this->from)->whereDate('due_date', '<=', $this->to)->orderBy('due_date')->get();
        $overdue = $this->milestones()->where('status', '!=', 'done')->whereDate('due_date', '<', $this->reference())->orderBy('due_date')->get();
        $done = $this->milestones()->whereBetween('completed_at', [$this->from, $this->to])->orderBy('completed_at')->get();
        $row = fn (AcademicProjectMilestone $m) => [
            'text' => ($m->project?->name ?? 'Dự án').' · '.$m->title.($m->due_date ? ' · hạn '.$m->due_date->format('d/m') : ''),
            'url' => $this->link('academic-projects.show', $m->academic_project_id),
        ];

        $orders = MaterialOrder::query()->visibleTo($this->user)->where('category', MaterialOrder::CATEGORY_ACADEMIC)
            ->whereBetween('created_at', [$this->from, $this->to])->get(['id', 'code', 'title', 'status']);
        $observations = QaObservation::with(['classModel:id,name', 'teacher:id,name'])->whereIn('class_id', $this->classIds())
            ->whereDate('observed_on', '>=', $this->from)->whereDate('observed_on', '<=', $this->to)->get();
        $classObservations = AcademicObservation::with('classModel:id,name')->whereIn('class_id', $this->classIds())
            ->whereDate('observed_on', '>=', $this->from)->whereDate('observed_on', '<=', $this->to)->get();
        $scores = MiniTestScore::with(['classModel:id,name', 'student:id,name'])->whereIn('class_id', $this->classIds())
            ->whereDate('test_date', '>=', $this->from)->whereDate('test_date', '<=', $this->to)->get();
        $low = $scores->filter(fn (MiniTestScore $s) => $s->score !== null && (float) $s->score / max((float) ($s->max_score ?: 10), 1) * 10 < KpiAutoCounter::PASS_SCORE);

        // Quy trình syllabus (Sheet 4 câu 9): việc cuối chặng hoàn thành / quá hạn, lớp đã giao syllabus trong tuần.
        $stageTasks = WorkTask::query()->where('assignee_id', $this->user->id)->where('kind', WorkTask::KIND_SYLLABUS_STAGE_END);
        $tasksDone = (clone $stageTasks)->where('status', 'completed')->whereBetween('completed_at', [$this->from, $this->to])->get();
        $tasksOverdue = (clone $stageTasks)->whereIn('status', [...WorkTask::OPEN_STATUSES, 'overdue'])->whereDate('due_date', '<', $this->reference())->get();
        $assigned = SyllabusAssignment::with(['classModel:id,name', 'stage'])->where('opened_by', $this->user->id)
            ->whereBetween('created_at', [$this->from, $this->to])->get();
        $taskRow = fn (WorkTask $t, ?string $tone) => ['text' => $t->title.($t->due_date ? ' · hạn '.$t->due_date->format('d/m') : ''), 'tone' => $tone, 'url' => $this->link('tasks.show', $t->id)];

        return $this->section('Số liệu tuần', 'monitoring', [
            $this->item('syllabus_tasks_done', 'Việc chuẩn bị chặng hoàn thành', $tasksDone->count(), 'Việc "Chuẩn bị test chặng…" bấm HOÀN THÀNH trong tuần.',
                $tasksDone->map(fn (WorkTask $t) => $taskRow($t, 'success'))->values()->all()),
            $this->item('syllabus_tasks_overdue', 'Việc chuẩn bị chặng quá hạn', $tasksOverdue->count(), 'Việc "Chuẩn bị test chặng…" đã qua hạn chót mà chưa hoàn thành.',
                $tasksOverdue->map(fn (WorkTask $t) => $taskRow($t, 'error'))->values()->all()),
            $this->item('classes_assigned', 'Lớp đã giao syllabus', $assigned->count(), 'Chặng bạn giao cho lớp trong tuần.',
                $assigned->map(fn (SyllabusAssignment $a) => ['text' => ($a->classModel?->name ?? 'Lớp').' · '.($a->stage?->label ?? $a->stage_name)])->values()->all()),
            $this->item('milestones_due', 'Mốc đến hạn trong tuần', $dueWeek->count(), 'Mốc dự án học thuật có hạn trong tuần (mọi trạng thái).',
                $dueWeek->map(fn ($m) => [...$row($m), 'tag' => AcademicProjectMilestone::STATUSES[$m->status] ?? $m->status, 'tone' => $m->status === 'done' ? 'success' : null])->values()->all()),
            $this->item('milestones_done', 'Mốc hoàn thành', $done->count(), 'Mốc được đánh dấu hoàn thành trong tuần.',
                $done->map(fn ($m) => [...$row($m), 'tone' => 'success'])->values()->all()),
            $this->item('milestones_overdue', 'Mốc quá hạn', $overdue->count(), 'Mốc đã qua hạn mà chưa hoàn thành (tính đến hôm nay, hoặc cuối tuần với tuần đã qua).',
                $overdue->map(fn ($m) => [...$row($m), 'tone' => 'error'])->values()->all()),
            $this->item('academic_orders', 'Order học liệu', $orders->count(), 'Order "Học liệu học thuật" tạo trong tuần.',
                $orders->map(fn (MaterialOrder $o) => ['text' => "{$o->code} · {$o->title}", 'tag' => MaterialOrder::STATUSES[$o->status] ?? $o->status,
                    'tone' => $o->status === MaterialOrder::STATUS_DONE ? 'success' : null, 'url' => $this->link('material-orders.show', $o->id)])->values()->all()),
            $this->item('observations', 'Buổi dự giờ', $observations->count() + $classObservations->count(), 'Phiếu dự giờ có ngày dự trong tuần.', [
                ...$observations->map(fn (QaObservation $o) => ['text' => ($o->classModel?->name ?? 'Lớp').($o->teacher ? ' · GV '.$o->teacher->name : '').' · '.$o->observed_on->format('d/m')])->all(),
                ...$classObservations->map(fn (AcademicObservation $o) => ['text' => ($o->classModel?->name ?? 'Lớp').($o->observed_on ? ' · '.$o->observed_on->format('d/m') : '')])->all(),
            ]),
            $this->item('mini_tests', 'Kết quả mini test', $scores->isEmpty() ? '0' : $scores->count().' bài · '.$low->count().' dưới 7',
                'Điểm mini test có ngày test trong tuần. "Dưới 7" quy về thang 10.',
                $low->map(fn (MiniTestScore $s) => [
                    'text' => ($s->student?->name ?? 'Học sinh').' · '.($s->classModel?->name ?? '').' · '.$s->name.' · '.self::num($s->score).'/'.self::num($s->max_score ?: 10),
                    'tone' => 'warning',
                ])->values()->all(),
                $scores->count()),
        ]);
    }

    /** Dòng điền sẵn cho 3 bảng (done / planned / requests); `source` để không thêm trùng một việc. */
    private function academicSuggestions(): array
    {
        $nextEnd = $this->to->copy()->addWeek();
        $done = collect();
        $planned = collect();
        $requests = collect();

        $this->milestones()->whereBetween('completed_at', [$this->from, $this->to])->orderBy('completed_at')->get()
            ->each(fn (AcademicProjectMilestone $m) => $done->push($this->milestoneRow($m, 100)));
        $this->milestones()->where('status', '!=', 'done')->whereDate('due_date', '<=', $nextEnd)->orderBy('due_date')->get()
            ->each(fn (AcademicProjectMilestone $m) => $planned->push($this->milestoneRow($m, self::progressOf($m))));

        $tasks = WorkTask::query()->where('assignee_id', $this->user->id)->whereNull('care_milestone');
        (clone $tasks)->where('status', 'completed')->whereBetween('completed_at', [$this->from, $this->to])->orderBy('completed_at')->get()
            ->each(fn (WorkTask $t) => $done->push($this->row("task:{$t->id}", $t->title, $t->completion_note, $t->due_date, 100, $this->link('tasks.show', $t->id))));
        (clone $tasks)->whereIn('status', WorkTask::OPEN_STATUSES)->whereNotNull('due_date')->whereDate('due_date', '<=', $nextEnd)->orderBy('due_date')->get()
            ->each(fn (WorkTask $t) => $planned->push($this->row("task:{$t->id}", $t->title, Str::limit((string) $t->description, 300) ?: null, $t->due_date,
                $t->status === 'in_progress' ? 50 : 0, $this->link('tasks.show', $t->id))));

        MaterialOrder::query()->visibleTo($this->user)->where('category', MaterialOrder::CATEGORY_ACADEMIC)
            ->where('status', MaterialOrder::STATUS_DONE)->whereBetween('processed_at', [$this->from, $this->to])->get()
            ->each(fn (MaterialOrder $o) => $done->push($this->row("order:{$o->id}", "Order học liệu {$o->code}: {$o->title}", null, $o->due_at, 100, $this->link('material-orders.show', $o->id))));
        MaterialOrder::query()->visibleTo($this->user)->where('category', MaterialOrder::CATEGORY_ACADEMIC)
            ->whereIn('status', MaterialOrder::OPEN_STATUSES)->orderBy('due_at')->get()
            ->each(fn (MaterialOrder $o) => $requests->push($this->row("order:{$o->id}", "Order học liệu {$o->code}: {$o->title}",
                MaterialOrder::STATUSES[$o->status] ?? $o->status, $o->due_at, 0, $this->link('material-orders.show', $o->id))));

        $bigTests = BigTestOrder::query()->visibleTo($this->user)->with('classModel:id,name');
        (clone $bigTests)->where('status', 'approved')->whereBetween('reviewed_at', [$this->from, $this->to])->get()
            ->each(fn (BigTestOrder $o) => $done->push($this->row("bto:{$o->id}", 'Duyệt đề '.(BigTestOrder::TYPE_LABELS[$o->test_type] ?? $o->test_type).' · '.($o->classModel?->name ?? ''),
                null, $o->due_date, 100, $this->link('syllabus.big-tests.distribution'))));
        (clone $bigTests)->where('status', 'pending')->orderBy('due_date')->get()
            ->each(fn (BigTestOrder $o) => $requests->push($this->row("bto:{$o->id}", 'Order đề '.(BigTestOrder::TYPE_LABELS[$o->test_type] ?? $o->test_type).' · '.($o->classModel?->name ?? ''),
                'Chờ duyệt', $o->due_date, 0, $this->link('syllabus.big-tests.distribution'))));

        SyllabusChangeProposal::query()->visibleTo($this->user)->with('curriculum:id,title')
            ->where(fn (Builder $q) => $q->where('status', 'pending')->orWhereBetween('reviewed_at', [$this->from, $this->to]))->latest()->get()
            ->each(function (SyllabusChangeProposal $p) use ($done, $requests) {
                $row = $this->row("proposal:{$p->id}", 'Đề xuất sửa giáo trình · '.($p->curriculum?->title ?? ''), Str::limit((string) $p->reason, 300) ?: null,
                    null, $p->status === 'pending' ? 0 : 100, null);
                $p->status === 'pending' ? $requests->push($row) : $done->push($row);
            });
        SyllabusAdjustmentRequest::with('classModel:id,name')->whereIn('class_id', $this->classIds())
            ->where(fn (Builder $q) => $q->where('status', 'pending')->orWhereBetween('reviewed_at', [$this->from, $this->to]))->latest()->get()
            ->each(function (SyllabusAdjustmentRequest $r) use ($done, $requests) {
                $row = $this->row("adjust:{$r->id}", 'Điều chỉnh syllabus · '.($r->classModel?->name ?? ''), Str::limit((string) $r->reason, 300) ?: null,
                    null, $r->status === 'pending' ? 0 : 100, $this->link('syllabus.adjustment-requests'));
                $r->status === 'pending' ? $requests->push($row) : $done->push($row);
            });

        QaObservation::with(['classModel:id,name', 'teacher:id,name'])->where('observer_id', $this->user->id)
            ->whereDate('observed_on', '>=', $this->from)->whereDate('observed_on', '<=', $this->to)->get()
            ->each(fn (QaObservation $o) => $done->push($this->row("qa:{$o->id}", 'Dự giờ '.($o->classModel?->name ?? 'lớp').($o->teacher ? ' · GV '.$o->teacher->name : ''),
                $o->rating ? 'Đánh giá: '.$o->rating : null, $o->observed_on, 100, $this->link('classes.show', $o->class_id))));
        AcademicObservation::with('classModel:id,name')->where('observer_id', $this->user->id)
            ->whereDate('observed_on', '>=', $this->from)->whereDate('observed_on', '<=', $this->to)->get()
            ->each(fn (AcademicObservation $o) => $done->push($this->row("obs:{$o->id}", 'Dự giờ đánh giá '.($o->classModel?->name ?? 'lớp'),
                $o->overall ? Str::limit((string) $o->overall, 300) : null, $o->observed_on, 100, $this->link('classes.show', $o->class_id))));

        return collect(['done' => $done, 'planned' => $planned, 'requests' => $requests])
            ->map(fn (Collection $rows) => $rows->unique('source')->take(self::MAX_SUGGESTIONS)->values()->all())
            ->all();
    }

    private function milestoneRow(AcademicProjectMilestone $m, int $progress): array
    {
        $qty = $m->target_quantity ? 'Đã làm '.self::num($m->done_quantity ?? 0).'/'.self::num($m->target_quantity).($m->unit ? ' '.$m->unit : '') : null;

        return $this->row("milestone:{$m->id}", ($m->project?->name ?? 'Dự án').': '.$m->title,
            collect([$qty, $m->assignee ? 'Phụ trách: '.$m->assignee->name : null, $m->status !== 'done' && $m->due_date?->lt($this->reference()) ? 'Quá hạn' : null])->filter()->implode(' · ') ?: null,
            $m->due_date, $progress, $this->link('academic-projects.show', $m->academic_project_id));
    }

    private function row(string $source, string $content, ?string $detail, mixed $deadline, int $progress, ?string $link): array
    {
        return [
            'source' => $source,
            'content' => Str::limit($content, 480),
            'detail' => $detail,
            'deadline' => $deadline ? Carbon::parse($deadline)->toDateString() : null,
            'progress' => $progress,
            'link' => $link,
        ];
    }

    /** Tiến độ mốc làm tròn xuống mức gần nhất 0 / 25 / 50 / 75 / 100. */
    private static function progressOf(AcademicProjectMilestone $m): int
    {
        if ($m->status === 'done') {
            return 100;
        }
        if (! $m->target_quantity) {
            return $m->status === 'in_progress' ? 50 : 0;
        }

        return (int) min(75, floor(((float) $m->done_quantity / (float) $m->target_quantity) * 4) * 25);
    }

    /** "A, B, C +2" — danh sách tên rút gọn để thẻ số liệu không quá dài. */
    private static function names(Collection $names, int $limit = 3): string
    {
        $names = $names->filter()->unique()->values();

        return $names->take($limit)->implode(', ').($names->count() > $limit ? ' +'.($names->count() - $limit) : '');
    }

    private static function num(mixed $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    }
}
