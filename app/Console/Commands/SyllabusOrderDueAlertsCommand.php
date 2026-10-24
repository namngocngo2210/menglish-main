<?php

namespace App\Console\Commands;

use App\Models\SyllabusAssignment;
use App\Services\Syllabus\SyllabusNotifier;
use App\Services\Syllabus\SyllabusOrders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Mốc order (in ấn / mini test / Big Test) đến hạn chưa order → báo GV 1 lần / mốc, nút ORDER (docs/kho-syllabus.md §9.3). */
class SyllabusOrderDueAlertsCommand extends Command
{
    protected $signature = 'syllabus:order-due-alerts';

    protected $description = 'Báo GV mốc order theo syllabus đã đến hạn';

    public function handle(SyllabusOrders $orders, SyllabusNotifier $notifier): int
    {
        $count = 0;
        SyllabusAssignment::open()->with(['classModel.branch', 'stage'])->whereNotNull('stage_id')->whereNotNull('class_id')
            ->lazyById()
            ->each(function (SyllabusAssignment $assignment) use ($orders, $notifier, &$count) {
                foreach ($orders->milestones($assignment)->filter(fn ($m) => $m['due'] && ! $m['ordered']) as $m) {
                    $inserted = DB::table('syllabus_milestone_notices')->insertOrIgnore([
                        'syllabus_assignment_id' => $assignment->id, 'syllabus_lesson_id' => $m['lesson']->id, 'kind' => $m['kind'],
                        'notified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($inserted) {
                        $notifier->orderDue($assignment, $m['label'], $m['lesson']->session_no);
                        $count++;
                    }
                }
            });
        $this->info("Đã báo {$count} mốc order đến hạn.");

        return self::SUCCESS;
    }
}
