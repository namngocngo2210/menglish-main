<?php

namespace App\Services\Reports;

use App\Models\AcademicObservation;
use App\Models\AcademicProjectMilestone;
use App\Models\BigTestOrder;
use App\Models\ClassModel;
use App\Models\ClassSessionFeedback;
use App\Models\MaterialOrder;
use App\Models\MiniTestScore;
use App\Models\QaObservation;
use App\Models\SlaEvent;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusChangeProposal;
use App\Models\TeacherMeetingReport;
use App\Models\User;
use App\Services\Reports\Concerns\BuildsReportItems;
use App\Support\Dashboard\TeachingQuality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Số liệu tự động của báo cáo tháng / quý Học thuật (chủ dự án 17/10/2026): thay các ô tường thuật bằng khối số liệu tính từ dữ liệu
 * gốc của cả kỳ, người viết chỉ ghi chú từng khối, tạo task follow-up trong khối và viết "Đánh giá chung". Cùng khung mục với báo cáo
 * ngày (BuildsReportItems, hiển thị bằng DailyMetric), phạm vi là lớp người viết được xem trong chi nhánh của mình.
 */
class AcademicPeriodMetrics
{
    use BuildsReportItems;

    public const NOTE_LABELS = [
        'quality' => 'Ghi chú chất lượng giảng dạy',
        'projects' => 'Ghi chú dự án học thuật',
        'parents' => 'Ghi chú feedback phụ huynh',
        'materials' => 'Ghi chú học liệu & Big Test',
        'syllabus' => 'Ghi chú giáo trình',
        'meetings' => 'Ghi chú dự giờ & họp giáo viên',
    ];

    private Carbon $from;

    private Carbon $to;

    /** Mốc so sánh "kỳ trước" (cùng độ dài, ngay trước kỳ này). */
    private Carbon $prevFrom;

    private Carbon $prevTo;

    public function __construct(private readonly User $user, Carbon $from, Carbon $to, private readonly string $prevLabel)
    {
        $this->from = $from->copy()->startOfDay();
        // Kỳ đang chạy chỉ tính tới hôm nay.
        $this->to = $to->copy()->endOfDay()->min(now()->endOfDay());
        $months = max(1, (int) round($from->diffInMonths($to->copy()->addDay())));
        $this->prevFrom = $from->copy()->subMonthsNoOverflow($months)->startOfDay();
        $this->prevTo = $from->copy()->subDay()->endOfDay();
    }

    /** @return array{sections: array<string, array>, computed_at: string} */
    public static function compute(User $user, Carbon $from, Carbon $to, string $prevLabel): array
    {
        $metrics = new self($user, $from, $to, $prevLabel);

        return [
            'sections' => [
                'quality' => $metrics->quality(),
                'projects' => $metrics->projects(),
                'parents' => $metrics->parents(),
                'materials' => $metrics->materials(),
                'syllabus' => $metrics->syllabus(),
                'meetings' => $metrics->meetings(),
            ],
            'computed_at' => now()->toIso8601String(),
        ];
    }

    private function quality(): array
    {
        $classes = ClassModel::whereIn('id', $this->classIds())->whereNotNull('teacher_id')->with('teacher:id,name')
            ->orderBy('name')->get(['id', 'code', 'name', 'branch_id', 'teacher_id', 'status']);
        $active = $classes->where('status', 'active');
        $quality = new TeachingQuality(null, $this->from, $this->to);
        $rows = collect($quality->classMetrics($active));
        $totals = $quality->totals($rows->all());
        $prev = new TeachingQuality(null, $this->prevFrom, $this->prevTo);
        $prevTotals = $prev->totals($prev->classMetrics($active));

        $scores = MiniTestScore::with(['student:id,name', 'classModel:id,name'])->whereIn('class_id', $this->classIds())
            ->whereBetween('test_date', [$this->from->toDateString(), $this->to->toDateString()])->where('max_score', '>', 0)->get();
        $low = $scores->filter(fn (MiniTestScore $s) => (float) $s->score / (float) $s->max_score * 10 < 7);
        $late = SlaEvent::where('rule_key', 'gv.remarks_late')->whereNotNull('breached_at')
            ->whereBetween('breached_at', [$this->from, $this->to])->get(['user_id', 'subject_id']);
        $names = User::whereIn('id', $late->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        $percent = fn (?float $v) => $v === null ? '—' : self::num($v).'%';
        $compare = fn (?float $v, string $unit = '%') => $v === null ? [] : [['text' => $this->prevLabel.': '.self::num($v).$unit]];
        $teacherRows = $rows->groupBy('teacher_id')->map(function (Collection $own, $teacherId) use ($quality, $classes) {
            $t = $quality->totals($own->all());
            $name = $classes->firstWhere('teacher_id', $teacherId)?->teacher?->name ?? 'GV #'.$teacherId;

            return [
                'text' => "{$name} · {$t['classes']} lớp · chuyên cần ".($t['attendance'] === null ? '—' : self::num($t['attendance']).'%')
                    .' · BTVN '.($t['homework'] === null ? '—' : self::num($t['homework']).'%')
                    .' · điểm TB '.($t['score'] === null ? '—' : self::num($t['score'])),
                ...(($t['attendance'] !== null && $t['attendance'] < 85) || ($t['homework'] !== null && $t['homework'] < 60) ? ['tag' => 'Cần chú ý', 'tone' => 'warning'] : []),
            ];
        })->sortBy('text')->values()->all();

        return $this->section('Chất lượng giảng dạy', 'fact_check', [
            $this->item('attendance', 'Chuyên cần', $percent($totals['attendance']),
                'Tỷ lệ lượt điểm danh có mặt / đi muộn trên tổng lượt điểm danh của các lớp đang học trong kỳ (trung bình theo sĩ số lớp).',
                $compare($prevTotals['attendance'])),
            $this->item('homework', 'Hoàn thành BTVN', $percent($totals['homework']),
                'Tỷ lệ học sinh nộp bài về nhà được giao trong kỳ (như Tổng quan chất lượng giảng dạy).', $compare($prevTotals['homework'])),
            $this->item('mini_test', 'Điểm TB mini test', $totals['score'] === null ? '—' : self::num($totals['score']),
                'Điểm mini test trung bình trong kỳ, quy về thang 10. Danh sách là bài dưới 7 điểm.',
                $low->sortBy('test_date')->take(30)->map(fn (MiniTestScore $s) => [
                    'text' => ($s->student?->name ?? 'Học sinh').' · '.($s->classModel?->name ?? '').' · '.$s->name.' · '.self::num((float) $s->score / (float) $s->max_score * 10),
                    'tag' => 'Dưới 7', 'tone' => 'warning',
                ])->values()->all()),
            $this->item('remarks_late', 'Nhận xét buổi trễ hạn', $late->count(),
                'Biên bản SLA "Nhận xét sau buổi học" lập trong kỳ: giáo viên gửi nhận xét quá hạn.',
                $late->groupBy('user_id')->sortByDesc(fn (Collection $rows) => $rows->count())
                    ->map(fn (Collection $rows, $id) => ['text' => ($names[$id] ?? 'Giáo viên').': '.$rows->count().' buổi', 'tag' => 'Trễ hạn', 'tone' => 'error'])->values()->all()),
            $this->item('teachers', 'Theo từng giáo viên', null,
                'Số liệu các lớp đang học gom theo giáo viên chính. "Cần chú ý": chuyên cần dưới 85% hoặc BTVN dưới 60%.', $teacherRows),
        ], 'Chưa có lớp nào trong phạm vi của bạn.');
    }

    private function projects(): array
    {
        $milestones = AcademicProjectMilestone::with(['project:id,name', 'assignee:id,name'])
            ->whereHas('project', fn (Builder $p) => $p->visibleTo($this->user))
            ->where(fn (Builder $q) => $q->whereBetween('due_date', [$this->from->toDateString(), $this->to->toDateString()])
                ->orWhereBetween('completed_at', [$this->from, $this->to])
                ->orWhere(fn (Builder $open) => $open->where('status', '!=', 'done')->whereDate('due_date', '<', $this->from)))
            ->orderBy('due_date')->get();
        $due = $milestones->filter(fn ($m) => $m->due_date?->between($this->from, $this->to));
        $done = $milestones->filter(fn ($m) => $m->completed_at?->between($this->from, $this->to));
        $overdue = $milestones->filter(fn ($m) => $m->status !== 'done' && $m->due_date?->lt(today()));
        $row = fn (AcademicProjectMilestone $m) => [
            'text' => ($m->project?->name ?? 'Dự án').' · '.$m->title.($m->assignee ? ' · '.$m->assignee->name : '').($m->due_date ? ' · hạn '.$m->due_date->format('d/m') : ''),
            'url' => $m->academic_project_id ? $this->link('academic-projects.show', $m->academic_project_id) : null,
        ];

        return $this->section('Dự án học thuật', 'rocket_launch', [
            $this->item('milestones_due', 'Mốc đến hạn trong kỳ', $due->count(), 'Mốc của dự án học thuật bạn được xem có hạn trong kỳ.', []),
            $this->item('milestones_done', 'Mốc hoàn thành', $done->count(), 'Mốc được đánh dấu hoàn thành trong kỳ.',
                $done->map(fn ($m) => [...$row($m), 'tone' => 'success'])->values()->all()),
            $this->item('milestones_overdue', 'Mốc quá hạn', $overdue->count(), 'Mốc đã qua hạn mà chưa hoàn thành (tính tới hôm nay).',
                $overdue->map(fn ($m) => [...$row($m), 'tag' => 'Quá hạn '.(int) $m->due_date->diffInDays(today()).' ngày', 'tone' => 'error'])->values()->all()),
        ]);
    }

    private function parents(): array
    {
        $ratings = ClassSessionFeedback::with(['student:id,name', 'classModel:id,name', 'session:id,date,start_time'])
            ->where('source', ClassSessionFeedback::SOURCE_PARENT)->whereNotNull('rating')
            ->whereIn('class_id', $this->classIds())
            ->whereHas('session', fn (Builder $s) => $s->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()]))
            ->get();
        $low = $ratings->filter(fn (ClassSessionFeedback $f) => $f->rating <= ClassSessionFeedback::LOW_RATING)->sortBy('rating');
        $aboutTeacher = $ratings->filter(fn (ClassSessionFeedback $f) => in_array('teacher', $f->categories ?? [], true));
        $row = fn (ClassSessionFeedback $f) => [
            'text' => 'PH bé '.($f->student?->name ?? '—').' · '.($f->classModel?->name ?? '').($f->session?->date ? ' · buổi '.$f->session->date->format('d/m') : '')
                .($f->categoryLabels() ? ' · '.implode(', ', $f->categoryLabels()) : '').($f->content ? ' · "'.Str::limit($f->content, 120).'"' : ''),
            'tag' => $f->rating.' sao',
            'tone' => $f->rating <= ClassSessionFeedback::LOW_RATING ? 'error' : null,
        ];

        return $this->section('Feedback phụ huynh', 'reviews', [
            $this->item('ratings', 'Phiếu phụ huynh chấm buổi', $ratings->count(), 'Phiếu phụ huynh chấm sao trên cổng học viên cho các buổi trong kỳ.', []),
            $this->item('rating_avg', 'Điểm hài lòng TB', $ratings->isEmpty() ? '—' : self::num($ratings->avg('rating')).' / 5', 'Số sao trung bình của các phiếu trong kỳ.', []),
            $this->item('about_teacher', 'Góp ý về giáo viên', $aboutTeacher->count(), 'Phiếu phụ huynh chọn "Giáo viên" ở mục góp ý về.',
                $aboutTeacher->filter(fn ($f) => $f->content)->take(20)->map($row)->values()->all()),
            $this->item('low_ratings', 'Phiếu từ '.ClassSessionFeedback::LOW_RATING.' sao trở xuống', $low->count(), 'Phiếu chấm thấp cần xem lại.',
                $low->map($row)->values()->all()),
        ]);
    }

    private function materials(): array
    {
        $orders = MaterialOrder::query()->visibleTo($this->user)->with('classRoom:id,name')
            ->where('category', MaterialOrder::CATEGORY_ACADEMIC)
            ->where(fn (Builder $q) => $q->whereBetween('created_at', [$this->from, $this->to])
                ->orWhere(fn (Builder $o) => $o->whereIn('status', MaterialOrder::OPEN_STATUSES)->where('created_at', '<', $this->from)))
            ->orderBy('due_at')->get();
        $overdue = $orders->filter(fn (MaterialOrder $o) => $o->status === MaterialOrder::STATUS_OVERDUE
            || (in_array($o->status, MaterialOrder::OPEN_STATUSES, true) && $o->due_at?->isPast()));
        $bigTests = BigTestOrder::query()->visibleTo($this->user)->with(['classModel:id,name', 'teacher:id,name'])
            ->where('status', 'pending')->orderBy('due_date')->get();

        return $this->section('Học liệu & Big Test', 'inventory_2', [
            $this->item('academic_orders', 'Order học liệu', $orders->whereBetween('created_at', [$this->from, $this->to])->count(),
                'Order "Học liệu học thuật" tạo trong kỳ. Danh sách là order còn mở đã quá hạn.',
                $overdue->map(fn (MaterialOrder $o) => [
                    'text' => "{$o->code} · {$o->title}".($o->classRoom ? ' · '.$o->classRoom->name : ''),
                    'tag' => 'Quá hạn', 'tone' => 'error',
                    'url' => $this->link('material-orders.show', $o->id),
                ])->values()->all()),
            $this->item('big_test_orders', 'Đề Big Test chờ duyệt', $bigTests->count(), 'Order đề Mini / Big Test đang chờ duyệt.',
                $bigTests->map(fn (BigTestOrder $o) => [
                    'text' => ($o->code ? $o->code.' · ' : '').($o->classModel?->name ?? '').' · '.(BigTestOrder::TYPE_LABELS[$o->test_type] ?? $o->test_type)
                        .($o->due_date ? ' · hạn '.$o->due_date->format('d/m') : ''),
                    'tone' => $o->due_date?->isPast() ? 'warning' : null,
                ])->values()->all()),
        ]);
    }

    private function syllabus(): array
    {
        $proposals = SyllabusChangeProposal::query()->visibleTo($this->user)->with(['proposer:id,name', 'curriculum:id,title'])
            ->where(fn (Builder $q) => $q->whereBetween('created_at', [$this->from, $this->to])->orWhere('status', 'pending'))->latest()->get();
        $adjustments = SyllabusAdjustmentRequest::with(['classModel:id,name', 'teacher:id,name'])->whereIn('class_id', $this->classIds())
            ->where(fn (Builder $q) => $q->whereBetween('created_at', [$this->from, $this->to])->orWhere('status', 'pending'))->latest()->get();

        return $this->section('Giáo trình', 'menu_book', [
            $this->item('syllabus_proposals', 'Đề xuất sửa giáo trình', $proposals->whereBetween('created_at', [$this->from, $this->to])->count()
                .' · '.$proposals->where('status', 'pending')->count().' chờ duyệt',
                'Đề xuất sửa giáo trình gửi trong kỳ, kèm số đề xuất đang chờ duyệt.',
                $proposals->map(fn (SyllabusChangeProposal $p) => [
                    'text' => ($p->curriculum?->title ?? 'Giáo trình').' · '.Str::limit((string) $p->reason, 100).($p->proposer ? ' — '.$p->proposer->name : ''),
                    'tag' => SyllabusChangeProposal::STATUS_LABELS[$p->status] ?? $p->status,
                ])->values()->all(), $proposals->count()),
            $this->item('syllabus_adjustments', 'Yêu cầu điều chỉnh syllabus', $adjustments->whereBetween('created_at', [$this->from, $this->to])->count()
                .' · '.$adjustments->where('status', 'pending')->count().' chờ duyệt',
                'Yêu cầu giãn / điều chỉnh syllabus của lớp gửi trong kỳ, kèm số yêu cầu đang chờ duyệt.',
                $adjustments->map(fn (SyllabusAdjustmentRequest $r) => [
                    'text' => ($r->classModel?->name ?? 'Lớp').' · '.Str::limit((string) $r->reason, 100).($r->teacher ? ' — '.$r->teacher->name : ''),
                    'tag' => SyllabusAdjustmentRequest::STATUS_LABELS[$r->status] ?? $r->status,
                ])->values()->all(), $adjustments->count()),
        ]);
    }

    private function meetings(): array
    {
        $range = [$this->from->toDateString(), $this->to->toDateString()];
        $observations = QaObservation::with(['classModel:id,name', 'teacher:id,name'])->whereIn('class_id', $this->classIds())
            ->whereBetween('observed_on', $range)->orderBy('observed_on')->get();
        $classObservations = AcademicObservation::with('classModel:id,name')->whereIn('class_id', $this->classIds())
            ->whereBetween('observed_on', $range)->orderBy('observed_on')->get();
        // Báo cáo họp do chính người lập báo cáo ghi, không theo phạm vi của người đang xem báo cáo.
        $meetings = TeacherMeetingReport::withoutViewerScope()->with('teacher:id,name')->where('author_id', $this->user->id)
            ->whereBetween('week_start', [$this->from->copy()->startOfWeek()->toDateString(), $this->to->toDateString()])->get();

        return $this->section('Dự giờ & họp giáo viên', 'groups', [
            $this->item('observations', 'Buổi dự giờ', $observations->count() + $classObservations->count(), 'Phiếu dự giờ có ngày dự trong kỳ.', [
                ...$observations->map(fn (QaObservation $o) => ['text' => $o->observed_on?->format('d/m').' · '.($o->classModel?->name ?? 'Lớp').($o->teacher ? ' · GV '.$o->teacher->name : '').($o->rating ? ' · '.$o->rating : '')])->all(),
                ...$classObservations->map(fn (AcademicObservation $o) => ['text' => $o->observed_on?->format('d/m').' · '.($o->classModel?->name ?? 'Lớp').($o->overall ? ' · '.Str::limit((string) $o->overall, 80) : '')])->all(),
            ]),
            $this->item('teacher_meetings', 'Biên bản họp giáo viên', $meetings->count(), 'Biên bản họp giáo viên bạn ghi trong kỳ (danh sách chi tiết ở bên phải).', []),
        ]);
    }

    private static function num(float|int|null $n): string
    {
        return $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 1, ',', '.'), '0'), ',');
    }
}
