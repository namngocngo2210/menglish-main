<?php

namespace Tests\Unit;

use App\Models\Student;
use App\Support\Practice\SchoolGrade;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SchoolGradeTest extends TestCase
{
    private function student(?string $dob, int $adjust = 0): Student
    {
        $s = new Student;
        $s->dob = $dob ? Carbon::parse($dob) : null;
        $s->grade_adjust = $adjust;

        return $s;
    }

    public function test_school_year_starts_on_first_of_august(): void
    {
        $this->assertSame(2025, SchoolGrade::schoolYearStart(Carbon::parse('2026-07-31')));
        $this->assertSame(2026, SchoolGrade::schoolYearStart(Carbon::parse('2026-08-01')));
    }

    public function test_grade_from_dob_and_adjust(): void
    {
        $on = Carbon::parse('2026-10-23');
        $this->assertSame(7, SchoolGrade::forStudent($this->student('2014-03-10'), $on));
        $this->assertSame(6, SchoolGrade::forStudent($this->student('2014-03-10', -1), $on));
        $this->assertSame(7, SchoolGrade::baseGrade(Carbon::parse('2014-03-10'), $on));
    }

    public function test_null_when_no_dob_or_out_of_range(): void
    {
        $on = Carbon::parse('2026-10-23');
        $this->assertNull(SchoolGrade::forStudent($this->student(null), $on));
        $this->assertNull(SchoolGrade::baseGrade(null, $on));
        $this->assertNull(SchoolGrade::forStudent($this->student('2024-01-01'), $on));
        $this->assertNull(SchoolGrade::forStudent($this->student('2000-01-01'), $on));
    }

    public function test_adjust_for_returns_stored_offset(): void
    {
        $on = Carbon::parse('2026-10-23');
        $this->assertSame(-1, SchoolGrade::adjustFor($this->student('2014-03-10'), 6, $on));
        $this->assertSame(0, SchoolGrade::adjustFor($this->student('2014-03-10', -1), 7, $on));
    }

    public function test_school_year_label(): void
    {
        $this->assertSame('2026–2027', SchoolGrade::schoolYearLabel(Carbon::parse('2026-10-23')));
        $this->assertSame('2025–2026', SchoolGrade::schoolYearLabel(Carbon::parse('2026-03-01')));
    }
}
