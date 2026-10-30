<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TK-0019: GVNN tính tiền theo phút lịch và đơn giá phút cá nhân, không trừ đi muộn / về sớm. */
class ForeignTeacherMinuteOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create(['hourly_rate' => 900000]);
        $this->teacher->assignRole(Roles::FOREIGN_TEACHER);
    }

    public function test_don_gia_phut_co_nhan_rieng_va_khong_duoc_doc_thanh_don_gia_gio_hoac_hoc_phi(): void
    {
        $rate = $this->rate(TeacherHourlyRate::UNIT_MINUTE, 4200);

        $this->assertTrue($rate->isPerMinute());
        $this->assertFalse($rate->isPerSession());
        $this->assertFalse($rate->isTuitionShare());
        $this->assertSame('đ/phút', $rate->unit_label);
        $this->assertSame('foreign', TeacherHourlyRate::defaultTeacherType($this->teacher));
        $this->assertNull(TeacherHourlyRate::rateFor($this->teacher->id, '2026-10-10'));
        $this->assertNull(TeacherHourlyRate::tuitionPercentFor($this->teacher->id, '2026-10-10'));
    }

    public function test_tinh_phut_theo_lich_va_don_gia_hieu_luc_ngay_day_ke_ca_khi_co_don_gia_ca(): void
    {
        $this->rate(TeacherHourlyRate::UNIT_MINUTE, 4000, '2026-10-01');
        $this->rate(TeacherHourlyRate::UNIT_MINUTE, 5000, '2026-10-15');
        $this->rate(TeacherHourlyRate::UNIT_MINUTE, 8000, '2026-11-01');
        $sheet = $this->sheet(['hours' => 0.5, 'hourly_rate' => 1200000, 'checkin_time' => '18:30', 'checkout_time' => '19:00']);

        $before = $sheet->foreignTeacherPayOutcome();
        $this->assertSame(90, $before['minutes']);
        $this->assertSame(4000.0, $before['rate']);
        $this->assertSame(360000.0, $before['amount']);
        $this->assertSame($before['amount'], $before['base_amount']);
        $this->assertSame(TeacherHourlyRate::UNIT_MINUTE, $before['unit']);
        $this->assertFalse($before['missing_rate']);

        $sheet->teaching_date = '2026-10-15';
        $after = $sheet->foreignTeacherPayOutcome();
        $this->assertSame(5000.0, $after['rate']);
        $this->assertSame(450000.0, $after['amount']);
    }

    public function test_ca_tay_khong_co_lich_suy_phut_tu_so_gio_va_lam_tron_tien(): void
    {
        $this->rate(TeacherHourlyRate::UNIT_MINUTE, 3333.33);
        $result = $this->sheet(['scheduled_time' => null, 'hours' => 1.25, 'source' => TeacherTimesheet::SOURCE_MANUAL])
            ->foreignTeacherPayOutcome();

        $this->assertSame(75, $result['minutes']);
        $this->assertSame(249999.75, $result['amount']);
    }

    public function test_di_muon_ve_som_co_hoac_khong_bao_truoc_deu_khong_tru_tien_hay_huy_cong(): void
    {
        $this->rate(TeacherHourlyRate::UNIT_MINUTE, 4000);
        foreach ([false, true] as $notified) {
            foreach ([5, 15, 100] as $late) {
                $result = $this->sheet(['late_minutes' => $late, 'early_leave_minutes' => 10, 'late_notified' => $notified])
                    ->foreignTeacherPayOutcome();

                $this->assertSame(360000.0, $result['amount']);
                $this->assertTrue($result['counted']);
                $this->assertSame($late + 10, $result['late_minutes']);
                $this->assertNull($result['late_rule']);
                $this->assertSame(0.0, $result['late_deduction']);
            }
        }
    }

    public function test_thieu_don_gia_phut_khong_roi_ve_don_gia_ca_ho_so_mac_dinh_hoac_phien_ban_cu(): void
    {
        $sheet = $this->sheet(['hourly_rate' => 1200000, 'late_minutes' => 30]);
        $this->assertMissingRate($sheet->foreignTeacherPayOutcome());

        // Phiên bản tương lai chưa có hiệu lực tại ngày dạy.
        $this->rate(TeacherHourlyRate::UNIT_MINUTE, 5000, '2026-11-01');
        $this->assertMissingRate($sheet->foreignTeacherPayOutcome());

        $this->rate(TeacherHourlyRate::UNIT_MINUTE, 4000, '2026-09-01');
        foreach ([TeacherHourlyRate::UNIT_HOUR, TeacherHourlyRate::UNIT_SESSION, TeacherHourlyRate::UNIT_TUITION] as $unit) {
            $current = $this->rate($unit, 500000);
            $this->assertMissingRate($sheet->foreignTeacherPayOutcome());
            $current->delete();
        }

        $zero = $this->rate(TeacherHourlyRate::UNIT_MINUTE, 0);
        $this->assertMissingRate($sheet->foreignTeacherPayOutcome());
        $zero->update(['hourly_rate' => -1]);
        $this->assertMissingRate($sheet->foreignTeacherPayOutcome());
    }

    public function test_ghep_buoi_uu_tien_gvnn_duoc_phan_cong_truoc_buoi_trung_gio(): void
    {
        $branch = Branch::create(['name' => 'Cơ sở GVNN', 'code' => 'NN-M', 'is_active' => true]);
        $course = Course::create(['code' => 'NN-M', 'name' => 'Khóa GVNN', 'is_active' => true]);
        $class = ClassModel::create(['code' => 'NN-M', 'name' => 'Lớp GVNN', 'course_id' => $course->id,
            'branch_id' => $branch->id, 'max_capacity' => 10, 'status' => 'active']);
        $morning = ClassSession::create(['class_id' => $class->id, 'branch_id' => $branch->id, 'date' => '2026-10-10',
            'start_time' => '08:00', 'end_time' => '09:00', 'status' => 'scheduled']);
        $assigned = ClassSession::create(['class_id' => $class->id, 'branch_id' => $branch->id, 'date' => '2026-10-10',
            'start_time' => '18:00', 'end_time' => '19:30', 'foreign_teacher_id' => $this->teacher->id, 'status' => 'scheduled']);

        $this->assertSame($assigned->id, TeacherTimesheet::matchSession($this->teacher->id, $class->id, '2026-10-10')?->id);
        $this->assertSame($assigned->id, TeacherTimesheet::matchSession($this->teacher->id, $class->id, '2026-10-10', '08:00', '09:00')?->id);
        $this->assertNotSame($morning->id, $assigned->id);
    }

    private function rate(string $unit, float $amount, string $date = '2026-10-01'): TeacherHourlyRate
    {
        return TeacherHourlyRate::create(['user_id' => $this->teacher->id, 'hourly_rate' => $amount,
            'rate_unit' => $unit, 'teacher_type' => 'foreign', 'effective_from' => $date]);
    }

    private function sheet(array $overrides = []): TeacherTimesheet
    {
        return new TeacherTimesheet($overrides + ['user_id' => $this->teacher->id, 'teaching_date' => '2026-10-10',
            'scheduled_time' => '18:00-19:30', 'hours' => 1.5, 'source' => TeacherTimesheet::SOURCE_CHECKIN,
            'status' => 'valid', 'type' => 'regular']);
    }

    private function assertMissingRate(array $outcome): void
    {
        $this->assertSame(0.0, $outcome['amount']);
        $this->assertSame(0.0, $outcome['rate']);
        $this->assertTrue($outcome['missing_rate']);
        $this->assertTrue($outcome['counted']);
        $this->assertNull($outcome['late_rule']);
        $this->assertSame(0.0, $outcome['late_deduction']);
    }
}
