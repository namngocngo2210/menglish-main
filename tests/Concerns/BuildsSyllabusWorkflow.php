<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Support\Roles;

/**
 * Dữ liệu chung cho test quy trình syllabus (docs/kho-syllabus.md §7–10): chi nhánh, Học thuật, Quản lý, Học vụ, GV, GVNN,
 * giáo trình 2 chặng (6 + 4 buổi), lớp đang mở chặng 1.
 */
trait BuildsSyllabusWorkflow
{
    protected Branch $branch;

    protected User $lead;

    protected User $manager;

    protected User $staff;

    protected User $teacher;

    protected User $foreignTeacher;

    protected SyllabusCurriculum $curriculum;

    protected SyllabusStage $stage1;

    protected SyllabusStage $stage2;

    protected ClassModel $class;

    protected function buildSyllabusWorkflow(): void
    {
        $this->branch = Branch::create(['name' => 'Cơ sở Quy trình', 'code' => 'QT', 'is_active' => true]);
        $this->lead = $this->userWithRole(Roles::ACADEMIC_LEAD, 'Học thuật');
        $this->manager = $this->userWithRole(Roles::MANAGER, 'Quản lý');
        $this->staff = $this->userWithRole(Roles::ACADEMIC_STAFF, 'Học vụ');
        $this->teacher = $this->userWithRole(Roles::TEACHER_FULLTIME, 'GV Chính');
        $this->foreignTeacher = $this->userWithRole(Roles::TEACHER_PARTTIME, 'GV Nước Ngoài');

        $this->curriculum = SyllabusCurriculum::create(['code' => 'QT-FAM', 'title' => 'Family and Friends', 'version' => 'v1', 'stage_name' => 'Làm quen']);
        $this->stage1 = $this->curriculum->stages()->firstOrFail();
        $this->stage2 = SyllabusStage::create(['curriculum_id' => $this->curriculum->id, 'position' => 2, 'name' => 'Mở rộng']);
        $this->addLessons($this->stage1, 1, 6);
        $this->addLessons($this->stage2, 2, 4, 7);

        $this->class = ClassModel::create([
            'code' => 'QT-01', 'name' => 'Lớp QT 01', 'branch_id' => $this->branch->id, 'status' => 'active',
            'teacher_id' => $this->teacher->id, 'foreign_teacher_id' => $this->foreignTeacher->id,
        ]);
    }

    protected function openStage(SyllabusStage $stage, array $attributes = []): SyllabusAssignment
    {
        return SyllabusAssignment::create($attributes + [
            'class_id' => $this->class->id, 'curriculum_id' => $stage->curriculum_id, 'stage_id' => $stage->id,
            'stage_name' => $stage->label, 'user_id' => $this->teacher->id, 'assigned_chapters' => $stage->label,
            'status' => SyllabusAssignment::STATUS_OPEN, 'opened_at' => now(), 'opened_by' => $this->lead->id,
        ]);
    }

    protected function userWithRole(string $role, string $name = 'Nhân sự'): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function addLessons(SyllabusStage $stage, int $unitNumber, int $count, int $firstSession = 1): void
    {
        $unit = SyllabusUnit::create(['curriculum_id' => $stage->curriculum_id, 'stage_id' => $stage->id, 'unit_number' => $unitNumber, 'title' => "Unit {$unitNumber}"]);
        for ($i = 0; $i < $count; $i++) {
            SyllabusLesson::create(['unit_id' => $unit->id, 'session_no' => $firstSession + $i, 'title' => 'Bài '.($firstSession + $i)]);
        }
    }
}
