<?php

namespace App\Services\Reports;

use App\Http\Controllers\TeacherPortalController;
use App\Models\AcademicRecord;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\MiniTestScore;
use App\Models\SlaEvent;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Services\Reports\Concerns\BuildsReportItems;
use App\Support\Dashboard\TeachingQuality;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Số liệu tự động của báo cáo tháng giáo viên (chủ dự án 17/10/2026): khối chấm công của người viết và một khối cho mỗi lớp
 * (buổi đã dạy, chuyên cần, BTVN, điểm TB, nhận xét buổi) kèm "Học sinh cần chú ý" do hệ thống gợi ý (mini test dưới 7, vắng từ
 * 2 buổi) để giáo viên tạo task ngay. Cùng khung mục với báo cáo ngày (BuildsReportItems, hiển thị bằng DailyMetric).
 */
class TeacherMonthlyMetrics
{
    use BuildsReportItems;

    /** Vắng từ chừng này buổi trong tháng thì gợi ý "cần chú ý". */
    private const ABSENT_ALERT = 2;

    private TeachingQuality $quality;

    /** Hết tháng, hoặc hôm nay nếu tháng đang chạy. */
    private Carbon $until;

    public function __construct(private readonly User $user, string $month)
    {
        $this->quality = new TeachingQuality($month);
        $this->until = $this->quality->to->copy()->min(now()->endOfDay());
    }

    /** Khóa khối của một lớp — dùng làm `report_section` của task follow-up để việc tồn hiện lại đúng lớp tháng sau. */
    public static function classKey(int $classId): string
    {
        return 'class_'.$classId;
    }

    /** @return array{work: array, classes: array<string, array>, computed_at: string} */
    public static function compute(User $user, string $month): array
    {
        $metrics = new self($user, $month);
        $classes = $metrics->quality->classesOf($user);
        $sessions = ClassSession::query()->forStaff($user->id)->whereIn('class_id', $classes->pluck('id'))
            ->whereBetween('date', [$metrics->quality->from->toDateString(), $metrics->quality->to->toDateString()])
            ->where('status', '!=', 'cancelled')->orderBy('date')->orderBy('start_time')->get();

        return [
            'work' => $metrics->work($sessions),
            'classes' => $metrics->classes($classes, $sessions),
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /** @param Collection<int, ClassSession> $sessions */
    private function work(Collection $sessions): array
    {
        $stats = $this->quality->workStats([(int) $this->user->id])[(int) $this->user->id];
        $done = $sessions->filter(fn (ClassSession $s) => $this->taught($s))->count();

        return $this->section('Chấm công tháng', 'schedule', [
            $this->item('work_days', 'Ngày công', $stats['work_days'], 'Số ngày có giờ dạy (bảng giờ dạy) hoặc có chấm công vào trong tháng.'),
            $this->item('sessions', 'Buổi đã dạy', $done, 'Buổi bạn là GV / GVNN / trợ giảng, không tính buổi nghỉ / hủy, tính tới hôm nay.'),
            $this->item('late', 'Đi muộn', $stats['late'], 'Buổi dạy check-in muộn cộng ngày chấm công muộn không được duyệt.'),
            $this->item('leave_days', 'Nghỉ phép', $stats['leave_days'] ? $stats['leave_days'].' ngày' : '0', 'Số ngày trong tháng của đơn xin nghỉ đã duyệt.', [], $stats['leave_days']),
            $this->item('violations', 'Biên bản', $stats['violations'], 'Biên bản lỗi trong tháng, trừ biên bản đã hủy.'),
        ]);
    }

    /**
     * @param  Collection<int, ClassModel>  $classes
     * @param  Collection<int, ClassSession>  $sessions
     * @return array<string, array>
     */
    private function classes(Collection $classes, Collection $sessions): array
    {
        if ($classes->isEmpty()) {
            return [];
        }
        $ids = $classes->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rows = collect($this->quality->classMetrics($classes))->keyBy('id');
        $from = $this->quality->from->toDateString();
        $to = $this->until->toDateString();

        $ended = $sessions->filter(fn (ClassSession $s) => $this->taught($s));
        $remarked = $this->remarkedSessionIds($ended);
        $late = SlaEvent::where('rule_key', 'gv.remarks_late')->where('user_id', $this->user->id)->whereNotNull('breached_at')
            ->whereIn('subject_id', $ended->pluck('id'))->pluck('subject_id')->map(fn ($id) => (int) $id)->flip();
        $scores = MiniTestScore::with('student:id,name')->whereIn('class_id', $ids)->whereBetween('test_date', [$from, $to])
            ->where('max_score', '>', 0)->orderBy('test_date')->get();
        $absences = StudentAttendance::with('student:id,name')->whereIn('class_id', $ids)->whereBetween('session_date', [$from, $to])
            ->where('status', 'absent')->orderBy('session_date')->get();

        return $classes->mapWithKeys(function (ClassModel $class) use ($rows, $sessions, $ended, $remarked, $late, $scores, $absences) {
            $row = $rows->get((int) $class->id, []);
            $planned = $sessions->where('class_id', $class->id);
            $taught = $ended->where('class_id', $class->id);
            $missing = $taught->reject(fn (ClassSession $s) => $remarked->contains((int) $s->id));
            $lateHere = $taught->filter(fn (ClassSession $s) => $late->has((int) $s->id))->count();
            $percent = fn ($v) => $v === null ? '—' : self::num((float) $v).'%';

            $section = $this->section($class->name, 'groups', [
                $this->item('sessions', 'Buổi đã dạy', $taught->count().' / '.$planned->count(),
                    'Buổi đã dạy tới hôm nay / tổng buổi của bạn ở lớp này trong tháng (không tính buổi nghỉ / hủy).', [], $taught->count()),
                $this->item('attendance', 'Chuyên cần', $percent($row['attendance'] ?? null), 'Lượt có mặt / đi muộn trên tổng lượt điểm danh của lớp trong tháng.'),
                $this->item('homework', 'BTVN', $percent($row['homework'] ?? null), 'Tỷ lệ học sinh nộp bài về nhà giao trong tháng, trong hạn nộp.'),
                $this->item('score', 'Điểm TB', isset($row['score']) ? self::num((float) $row['score']) : '—', 'Điểm mini test trung bình trong tháng, quy về thang 10.'),
                $this->item('remarks', 'Nhận xét buổi', ($taught->count() - $missing->count()).' / '.$taught->count(),
                    'Buổi đã dạy có nhận xét học sinh (bản đã gửi). Trễ hạn: biên bản SLA "Nhận xét sau buổi học".',
                    [
                        ...($lateHere ? [['text' => "{$lateHere} buổi gửi trễ hạn", 'tag' => 'Trễ hạn', 'tone' => 'error']] : []),
                        ...$missing->take(10)->map(fn (ClassSession $s) => [
                            'text' => 'Buổi '.$s->date->format('d/m').' chưa có nhận xét', 'tag' => 'Thiếu', 'tone' => 'warning',
                            'url' => $this->link('teacher.remarks', ['classId' => $s->class_id, 'session' => $s->id]),
                        ])->values()->all(),
                    ], $taught->count() - $missing->count()),
            ]);
            $section['attention'] = $this->attention($scores->where('class_id', $class->id), $absences->where('class_id', $class->id));

            return [self::classKey((int) $class->id) => $section];
        })->all();
    }

    /**
     * Học sinh cần chú ý do hệ thống gợi ý: bài mini test dưới 7 điểm, vắng từ 2 buổi trong tháng.
     *
     * @return list<array{tag: string, text: string, task: string}>
     */
    private function attention(Collection $scores, Collection $absences): array
    {
        $low = $scores->filter(fn (MiniTestScore $s) => (float) $s->score / (float) $s->max_score * 10 < 7)
            ->map(function (MiniTestScore $s) {
                $name = $s->student?->name ?? 'Học sinh';
                $score = self::num((float) $s->score / (float) $s->max_score * 10);

                return ['tag' => "Mini test {$score}", 'text' => "{$name} · {$s->name} · ".$s->test_date?->format('d/m'), 'task' => "Kèm {$name}: mini test {$s->name} được {$score}"];
            });
        $absent = $absences->groupBy('student_id')->filter(fn (Collection $rows) => $rows->count() >= self::ABSENT_ALERT)
            ->map(function (Collection $rows) {
                $name = $rows->first()->student?->name ?? 'Học sinh';
                $days = $rows->map(fn (StudentAttendance $a) => Carbon::parse($a->session_date)->format('d/m'))->unique()->implode(', ');

                return ['tag' => 'Vắng '.$rows->count().' buổi', 'text' => "{$name} · {$days}", 'task' => "Liên hệ phụ huynh {$name}: vắng ".$rows->count().' buổi trong tháng'];
            });

        return $low->values()->concat($absent->values())->take(20)->values()->all();
    }

    /** Buổi đã dạy: ngày đã qua, hoặc hôm nay mà đã hết giờ. */
    private function taught(ClassSession $s): bool
    {
        if ($s->date->toDateString() !== today()->toDateString()) {
            return $s->date->lt(today());
        }

        return $s->end_time === null || now()->format('H:i') >= substr((string) $s->end_time, 0, 5);
    }

    /** @return Collection<int, int> buổi GV đã gửi nhận xét (bản hoàn tất; mã theo buổi, hoặc mã cũ theo lớp + ngày) */
    private function remarkedSessionIds(Collection $sessions): Collection
    {
        if ($sessions->isEmpty()) {
            return collect();
        }
        $legacy = fn (ClassSession $s) => $s->class_id.'-'.$s->date->toDateString();
        $codes = AcademicRecord::where('module', 'teacher_remarks')->where('status', 'completed')
            ->whereIn('record_code', $sessions->flatMap(fn (ClassSession $s) => [TeacherPortalController::remarkRecordCode($s), $legacy($s)])->all())
            ->pluck('record_code')->flip();

        return $sessions->filter(fn (ClassSession $s) => $codes->has(TeacherPortalController::remarkRecordCode($s)) || $codes->has($legacy($s)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values();
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
    }
}
