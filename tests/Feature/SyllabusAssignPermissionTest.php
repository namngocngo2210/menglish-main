<?php

namespace Tests\Feature;

use App\Models\SyllabusAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Giao chặng chỉ Học thuật (docs/kho-syllabus.md §2.6, §7.2 — Bước 3 quy trình). */
class SyllabusAssignPermissionTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
    }

    public function test_lead_has_assign_permissions_staff_and_manager_do_not(): void
    {
        $this->assertTrue($this->lead->can('syllabus.assign'));
        $this->assertTrue($this->lead->can('syllabus.class_edit'));
        foreach ([$this->staff, $this->manager, $this->teacher] as $user) {
            $this->assertFalse($user->can('syllabus.assign'), $user->name);
            $this->assertFalse($user->can('syllabus.class_edit'), $user->name);
        }
    }

    public function test_academic_staff_cannot_assign_stage(): void
    {
        $this->actingAs($this->staff)->post(route('syllabus.assignments.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, SyllabusAssignment::count());
    }

    public function test_lead_can_assign_stage(): void
    {
        $this->actingAs($this->lead)->post(route('syllabus.assignments.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame($this->stage1->id, SyllabusAssignment::open()->where('class_id', $this->class->id)->value('stage_id'));
    }

    private function payload(): array
    {
        return ['class_id' => $this->class->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage1->id, 'start_date' => today()->toDateString()];
    }
}
