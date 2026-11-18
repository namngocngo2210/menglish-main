<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TK-2026-0138: ma trận chuyển trạng thái học viên thủ công (9 đường cho phép). */
class StudentStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'CN Trạng thái', 'code' => 'TT', 'is_active' => true]);
        $this->user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->user->assignRole('academic_staff');
    }

    private function attempt(string $from, string $to): array
    {
        static $n = 0;
        $student = Student::create([
            'code' => 'HV-TT'.++$n, 'name' => 'HV '.$n, 'phone' => '09000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id, 'status' => $from,
        ]);
        $response = $this->actingAs($this->user)->put(route('students.status.update', $student->id), ['status' => $to]);

        return [$response, $student->fresh()->status];
    }

    public function test_every_allowed_transition_passes(): void
    {
        $this->assertSame(9, collect(Student::STATUS_TRANSITIONS)->flatten()->count());
        foreach (Student::STATUS_TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                [$response, $status] = $this->attempt($from, $to);
                $response->assertRedirect();
                $this->assertSame($to, $status, "$from -> $to");
            }
        }
    }

    public function test_forbidden_transitions_return_400_and_keep_status(): void
    {
        foreach ([
            ['summer_break', 'deferred'], ['summer_break', 'dropped'], ['deferred', 'summer_break'],
            ['waiting_start', 'deferred'], ['waiting_start', 'summer_break'],
            ['dropped', 'studying'], ['completed', 'studying'], ['studying', 'waiting_start'],
        ] as [$from, $to]) {
            [$response, $status] = $this->attempt($from, $to);
            $response->assertStatus(400);
            $this->assertSame($from, $status, "$from -> $to");
        }
    }

    /** Profile edit ignores `status` entirely (changes only via students.status.update), allowed or forbidden. */
    public function test_profile_update_ignores_status_field(): void
    {
        $this->user->givePermissionTo('student.change_status');
        foreach ([['dropped', 'studying'], ['studying', 'completed']] as $i => [$from, $to]) {
            $student = Student::create([
                'code' => 'HV-PU'.$i, 'name' => 'HV PU'.$i, 'phone' => '09100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'branch_id' => $this->branch->id, 'status' => $from,
            ]);
            $this->actingAs($this->user)->put(route('students.update', $student->id), [
                'name' => 'Tên mới '.$i, 'phone' => $student->phone, 'status' => $to,
            ])->assertSessionHasNoErrors();
            $student->refresh();
            $this->assertSame($from, $student->status, "$from -> $to via profile edit");
            $this->assertSame('HV PU'.$i, $student->name); // TK-2026-0139: name ignored
        }
    }
}
