<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * TK-2026-0072: the teacher Trang chu salary widget must carry the "provisional" label (US R-12/AC-R19).
 */
class TeacherSalaryProvisionalLabelTest extends TestCase
{
    public function test_salary_widget_shows_provisional_label_next_to_amount(): void
    {
        $vue = file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Dashboard/TeachingSide.vue');

        $this->assertStringContainsString('Lương tạm tính tháng này', $vue);
        $this->assertStringContainsString('data-overview-salary-provisional>tạm tính, có thể thay đổi đến khi chốt lương</span>', $vue);
        // The label sits inside the salary widget, before the amount.
        $this->assertLessThan(strpos($vue, 'shifts.salary.amount'), strpos($vue, 'data-overview-salary-provisional'));
    }
}
