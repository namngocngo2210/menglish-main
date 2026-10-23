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
 * được giao trong khoảng ngày (bài tập giao — ngày tạo — trong khoảng; bài tập không có giỏ nào không tính).
 */
class HomeworkStats
{
    /**
     * @return array{homeworks: int, students: list<array{id: int, name: string, baskets: array<string, array{submitted: int, assigned: int, rate: ?float}>, total: array{submitted: int, assigned: int, rate: ?float}}>, class: array<string, array{submitted: int, assigned: int, rate: ?float}>}
     */
    public function forClass(ClassModel $class, Carbon $from, Carbon $to): array
    {
        $homeworks = $this->homeworks($class, $from, $to);
        $students = $class->rosterStudents();
        $assigned = collect(array_keys(HomeworkSubmission::BASKETS))
            ->mapWithKeys(fn (string $b) => [$b => $homeworks->filter(fn (Homework $hw) => in_array($b, $hw->baskets(), true))->count()]);
        $submitted = HomeworkSubmission::whereIn('homework_id', $homeworks->modelKeys())->whereIn('student_id', $students->modelKeys())
            ->get(['student_id', 'basket'])->groupBy('student_id');

        $rows = $students->map(function (Student $student) use ($assigned, $submitted) {
            $mine = $submitted->get($student->id, collect())->countBy('basket');
            $baskets = $assigned->map(fn (int $count, string $b) => self::cell((int) ($mine[$b] ?? 0), $count))->all();

            return ['id' => $student->id, 'name' => $student->name, 'baskets' => $baskets, 'total' => self::sum($baskets)];
        })->values();

        $classCells = $assigned->map(fn (int $count, string $b) => self::cell(
            (int) $rows->sum(fn (array $r) => $r['baskets'][$b]['submitted']), $count * $students->count()))->all();

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
