<?php

namespace App\Services\Homework;

use App\Models\ClassModel;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Thống kê tỉ lệ nộp homework 3 giỏ (docs/kho-syllabus.md §10.2, câu 7): theo giỏ × học sinh và theo lớp = số bài nộp / số bài
 * được giao trong khoảng ngày (bài tập giao — ngày tạo — trong khoảng; bài tập không có giỏ nào không tính). Học viên vào lớp giữa
 * kỳ chỉ tính bài giao từ ngày vào lớp; bài nộp của giỏ GV đã bỏ khỏi bài tập không tính.
 */
class HomeworkStats
{
    /**
     * @return array{homeworks: int, students: list<array{id: int, name: string, baskets: array<string, array{submitted: int, assigned: int, rate: ?float}>, total: array{submitted: int, assigned: int, rate: ?float}}>, class: array<string, array{submitted: int, assigned: int, rate: ?float}>}
     */
    public function forClass(ClassModel $class, Carbon $from, Carbon $to): array
    {
        $homeworks = $this->homeworks($class, $from, $to)->map(fn (Homework $hw) => ['hw' => $hw, 'baskets' => $hw->baskets()]);
        $students = ClassModel::rosterStudentsFor([$class])->get($class->id, collect());
        $done = HomeworkSubmission::whereIn('homework_id', $homeworks->pluck('hw.id'))->whereIn('student_id', $students->modelKeys())
            ->get(['homework_id', 'student_id', 'basket'])->map(fn (HomeworkSubmission $s) => "{$s->homework_id}|{$s->student_id}|{$s->basket}")->flip();
        $keys = array_keys(HomeworkSubmission::BASKETS);

        $rows = $students->map(function (Student $student) use ($homeworks, $done, $keys) {
            // Vào lớp giữa kỳ (lượt xếp lớp còn hiệu lực): chỉ tính bài giao từ ngày vào lớp.
            $joined = $student->enrollments->max('enrolled_at');
            $mine = $joined ? $homeworks->filter(fn (array $h) => $h['hw']->created_at->gte(Carbon::parse($joined)->startOfDay())) : $homeworks;
            $baskets = collect($keys)->mapWithKeys(fn (string $b) => [$b => self::cell(
                $mine->filter(fn (array $h) => in_array($b, $h['baskets'], true) && $done->has("{$h['hw']->id}|{$student->id}|{$b}"))->count(),
                $mine->filter(fn (array $h) => in_array($b, $h['baskets'], true))->count(),
            )])->all();

            return ['id' => $student->id, 'name' => $student->name, 'baskets' => $baskets, 'total' => self::sum($baskets)];
        })->values();

        $classCells = collect($keys)->mapWithKeys(fn (string $b) => [$b => self::cell(
            (int) $rows->sum(fn (array $r) => $r['baskets'][$b]['submitted']), (int) $rows->sum(fn (array $r) => $r['baskets'][$b]['assigned']))])->all();

        return [
            'homeworks' => $homeworks->count(),
            'students' => $rows->all(),
            'class' => $classCells + ['total' => self::sum($classCells)],
        ];
    }

    /** @return Collection<int, Homework> bài tập có giỏ, giao trong khoảng ngày */
    public function homeworks(ClassModel $class, Carbon $from, Carbon $to): Collection
    {
        return Homework::where('class_id', $class->id)->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->latest()->get()->filter(fn (Homework $hw) => $hw->baskets() !== [])->values();
    }

    /** @return array{submitted: int, assigned: int, rate: ?float} */
    private static function cell(int $submitted, int $assigned): array
    {
        return ['submitted' => $submitted, 'assigned' => $assigned, 'rate' => $assigned > 0 ? round($submitted / $assigned * 100, 1) : null];
    }

    /** @param array<string, array{submitted: int, assigned: int, rate: ?float}> $cells */
    private static function sum(array $cells): array
    {
        return self::cell(array_sum(array_column($cells, 'submitted')), array_sum(array_column($cells, 'assigned')));
    }
}
