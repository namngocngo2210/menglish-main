<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DocumentCodeGenerator;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function generator(): DocumentCodeGenerator
    {
        return app(DocumentCodeGenerator::class);
    }

    public function test_sequences_are_independent_per_key_and_period(): void
    {
        $gen = $this->generator();

        $this->assertSame(1, $gen->next('demo'));
        $this->assertSame(2, $gen->next('demo'));
        $this->assertSame(1, $gen->next('demo', '2026'));
        $this->assertSame(1, $gen->next('other'));
        $this->assertSame(3, $gen->next('demo'));
        $this->assertSame(2, $gen->next('demo', '2026'));

        $this->assertDatabaseHas('document_sequences', ['key' => 'demo', 'period' => '', 'last_value' => 3]);
    }

    public function test_seed_is_used_only_when_sequence_is_created(): void
    {
        $gen = $this->generator();
        $calls = 0;
        $seed = function () use (&$calls) {
            $calls++;

            return 41;
        };

        $this->assertSame(42, $gen->next('seeded', '', $seed));
        $this->assertSame(43, $gen->next('seeded', '', $seed));
        $this->assertSame(1, $calls);
    }

    public function test_student_code_continues_after_existing_max_including_soft_deleted(): void
    {
        Student::create(['code' => 'HV-00007', 'name' => 'A', 'phone' => '0900000001']);
        $deleted = Student::create(['code' => 'HV-00012', 'name' => 'B', 'phone' => '0900000002']);
        $deleted->delete();
        // Mã không theo định dạng số (mã test / mã ULID khi chốt khách) bị bỏ qua.
        Student::create(['code' => 'HV-P0-001', 'name' => 'C', 'phone' => '0900000003']);
        Student::create(['code' => 'HV-01JABCDEF', 'name' => 'D', 'phone' => '0900000004']);

        $this->assertSame('HV-00013', $this->generator()->studentCode());
        $this->assertSame('HV-00014', $this->generator()->studentCode());
    }

    public function test_student_code_never_collides_after_deletes_old_count_bug(): void
    {
        // Lỗi cũ: count()+1 => xóa 1 học viên rồi tạo mới sẽ trùng mã với học viên cuối.
        $gen = $this->generator();
        $a = Student::create(['code' => $gen->studentCode(), 'name' => 'A', 'phone' => '0900000001']);
        $b = Student::create(['code' => $gen->studentCode(), 'name' => 'B', 'phone' => '0900000002']);
        $a->delete();

        $c = $gen->studentCode();
        $this->assertNotSame($b->code, $c);
        $this->assertSame('HV-00003', $c);
    }

    public function test_generated_code_skips_values_already_taken(): void
    {
        $gen = $this->generator();
        $this->assertSame('HV-00001', $gen->studentCode());
        // Ai đó nhập tay mã kế tiếp => bộ sinh bỏ qua, không lỗi unique.
        Student::create(['code' => 'HV-00002', 'name' => 'Tay', 'phone' => '0900000009']);

        $this->assertSame('HV-00003', $gen->studentCode());
    }

    public function test_big_test_and_ticket_codes_are_per_year(): void
    {
        $branch = Branch::create(['name' => 'CN', 'code' => 'CN', 'is_active' => true]);
        $course = Course::create(['code' => 'C1', 'name' => 'Khóa', 'is_active' => true]);
        $class = ClassModel::create(['code' => 'L1', 'name' => 'Lớp 1', 'course_id' => $course->id, 'branch_id' => $branch->id, 'status' => 'active']);
        BigTest::create([
            'code' => 'BT-2026-08', 'title' => 'Cũ', 'class_id' => $class->id, 'test_type' => 'midterm',
            'scheduled_at' => now(), 'room' => 'P1', 'status' => 'draft',
        ]);

        $gen = $this->generator();
        $this->assertSame('BT-2026-0009', $gen->bigTestCode(2026));
        $this->assertSame('BT-2026-0010', $gen->bigTestCode(2026));
        $this->assertSame('BT-2027-0001', $gen->bigTestCode(2027));

        $this->assertSame('TK-2026-0001', $gen->supportTicketCode(2026));
        $this->assertSame('TK-2026-0002', $gen->supportTicketCode(2026));
        $this->assertSame('TK-2027-0001', $gen->supportTicketCode(2027));
    }

    public function test_many_codes_are_unique(): void
    {
        $gen = $this->generator();
        $codes = collect(range(1, 30))->map(fn () => $gen->supportTicketCode());

        $this->assertCount(30, $codes->unique());
        $this->assertSame(30, (int) DB::table('document_sequences')->where('key', 'support_ticket')->value('last_value'));
    }

    public function test_store_big_test_uses_generator(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $branch = Branch::create(['name' => 'CN', 'code' => 'CN', 'is_active' => true]);
        $course = Course::create(['code' => 'C1', 'name' => 'Khóa', 'is_active' => true]);
        $class = ClassModel::create(['code' => 'L1', 'name' => 'Lớp 1', 'course_id' => $course->id, 'branch_id' => $branch->id, 'status' => 'active']);
        $admin = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $admin->assignRole('admin');

        foreach ([1, 2] as $i) {
            $this->actingAs($admin)->post(route('syllabus.big-tests.store'), [
                'title' => "Big Test {$i}", 'class_id' => $class->id, 'test_type' => 'midterm',
                'scheduled_at' => now()->addWeek()->toDateTimeString(), 'room' => 'P1',
            ])->assertRedirect();
        }

        $year = now()->year;
        $this->assertSame(["BT-{$year}-0001", "BT-{$year}-0002"], BigTest::orderBy('id')->pluck('code')->all());
    }

    public function test_support_ticket_generate_code_uses_tk_format(): void
    {
        $this->assertMatchesRegularExpression('/^TK-'.now()->year.'-\d{4}$/', SupportTicket::generateCode());
    }
}
