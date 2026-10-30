<?php

namespace App\Console\Commands;

use App\Models\ClassModel;
use App\Models\Student;
use App\Services\Tuition\TuitionClassLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Bù lớp học phí theo sổ buổi và lịch sử xếp lớp; chạy lại không tạo thay đổi trùng. */
class LinkTuitionClassesCommand extends Command
{
    protected $signature = 'tuitions:link-classes {--dry-run}';

    protected $description = 'Gắn lớp cho học phí theo buổi còn lại và lịch sử xếp lớp học viên';

    public function handle(TuitionClassLinker $linker): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $planned = 0;
        $changed = 0;
        $skipped = 0;

        // Console không bật DataScopeGuard: giống các lệnh định kỳ, đọc toàn bộ dữ liệu.
        Student::query()->whereNotNull('current_class_id')->with('enrollments')->chunkById(
            100,
            function (Collection $students) use ($linker, $dryRun, &$planned, &$changed, &$skipped) {
                $process = function () use ($students, $linker, $dryRun, &$planned, &$changed, &$skipped) {
                    $plan = [];
                    foreach ($students as $student) {
                        array_push($plan, ...$linker->planBackfill($student));
                    }
                    if ($plan === []) {
                        return;
                    }

                    $classIds = collect($plan)->pluck('from_class_id')->merge(array_column($plan, 'to_class_id'))->filter()->unique();
                    $classNames = ClassModel::withTrashed()->whereIn('id', $classIds)->pluck('name', 'id');
                    $studentCodes = $students->pluck('code', 'id');
                    $rows = [];
                    foreach ($plan as $change) {
                        if ($change['to_class_id'] === null) {
                            $skipped++;
                        } else {
                            $planned++;
                        }
                        $rows[] = [
                            $change['tuition_id'], $studentCodes[$change['student_id']],
                            $change['from_class_id'] === null ? 'NULL' : ($classNames[$change['from_class_id']] ?? '#'.$change['from_class_id']),
                            $change['to_class_id'] === null ? 'NULL' : ($classNames[$change['to_class_id']] ?? '#'.$change['to_class_id']),
                            $change['reason'],
                        ];
                    }
                    $this->table(['Khoản học phí', 'Mã học viên', 'Từ lớp', 'Đến lớp', 'Lý do'], $rows);
                    if (! $dryRun) {
                        $changed += count($linker->apply($plan));
                    }
                };

                if ($dryRun) {
                    $process();
                } else {
                    DB::transaction($process);
                }
            },
        );

        if ($dryRun) {
            $this->info('Chạy thử: không ghi dữ liệu.');
        }
        $this->info("Dự kiến thay đổi: {$planned}. Đã thay đổi: {$changed}. Bỏ qua: {$skipped}.");

        return self::SUCCESS;
    }
}
