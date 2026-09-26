<?php

namespace Tests\Concerns;

use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;

/**
 * BA chốt: còn nhân sự chưa chốt KPI thì không được chốt bảng lương.
 * Test đánh dấu KPI của mọi phiếu lương đang chờ là đã chốt (không đổi số tiền đã tính) trước khi duyệt kỳ.
 */
trait FinalizesPayrollKpi
{
    protected function finalizeKpi(PayrollPeriod $period): void
    {
        foreach ($period->records()->get() as $record) {
            if ($record->kpi_state[0] !== 'pending') {
                continue;
            }
            match ($record->kpi_source) {
                PayrollRecord::KPI_RETENTION => $record->forceFill(['retention_tier' => 0])->saveQuietly(),
                PayrollRecord::KPI_ACADEMIC => $record->forceFill(['kpi_score' => 0])->saveQuietly(),
                default => $record->forceFill(['kpi_manual_amount' => 0])->saveQuietly(),
            };
        }
    }
}
