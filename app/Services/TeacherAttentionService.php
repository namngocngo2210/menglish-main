<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\MiniTestScore;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Widget "Học sinh cần chú ý" (US #trang-chu-tong-quan R-11, TK-2026-0067, BA chốt 08/11/2026):
 * HS bị gắn cờ khi bài mini test TẠO MỚI GẦN NHẤT (kể cả ở lớp cũ) có điểm tổng HOẶC bất kỳ thành phần
 * nào dưới ngưỡng nguong_diem_can_chu_y_pct (mặc định 60%) tính trên mẫu số (max_score) GV đã nhập.
 * Chỉ GV/TG/GVNN của LỚP HIỆN TẠI của HS mới thấy HS đó.
 */
class TeacherAttentionService
{
    public const THRESHOLD_KEY = 'nguong_diem_can_chu_y_pct';

    public const DEFAULT_THRESHOLD_PCT = 60;

    public static function thresholdPct(): float
    {
        return (float) SystemSetting::get(self::THRESHOLD_KEY, self::DEFAULT_THRESHOLD_PCT);
    }

    /**
     * percent = mức thấp nhất trong điểm tổng và các thành phần (chỉ %, không điểm tuyệt đối).
     *
     * @return Collection<int, array{score: MiniTestScore, class_name: ?string, percent: int}>
     */
    public function forTeacher(User $teacher, int $limit = 5): Collection
    {
        $classes = ClassModel::query()->where(fn ($q) => $q->where('teacher_id', $teacher->id)
            ->orWhere('foreign_teacher_id', $teacher->id)->orWhere('assistant_id', $teacher->id))->pluck('name', 'id');
        if ($classes->isEmpty()) {
            return collect();
        }

        $students = Student::query()->whereIn('current_class_id', $classes->keys())->pluck('current_class_id', 'id');
        if ($students->isEmpty()) {
            return collect();
        }

        $threshold = self::thresholdPct();

        return MiniTestScore::with('student:id,name')
            ->whereIn('student_id', $students->keys())
            ->where('max_score', '>', 0)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->unique('student_id') // bài tạo mới gần nhất của mỗi HS, bất kể lớp
            ->map(function (MiniTestScore $s) use ($classes, $students) {
                $max = (float) $s->max_score;
                $min = collect([(float) $s->score])->merge($s->skill_scores ?? [])
                    ->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v / $max * 100)->min();

                return ['score' => $s, 'min' => $min, 'class_name' => $classes[$students[$s->student_id]] ?? null];
            })
            ->filter(fn (array $r) => $r['min'] < $threshold)
            ->take($limit)
            ->map(fn (array $r) => ['score' => $r['score'], 'class_name' => $r['class_name'], 'percent' => (int) round($r['min'])])
            ->values();
    }
}
