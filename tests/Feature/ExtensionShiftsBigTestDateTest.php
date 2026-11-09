<?php

namespace Tests\Feature;

use App\Models\SyllabusAdjustmentRequest;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** TK-2026-0059 (TC GV-GD-03): duyệt giãn N buổi lùi ngày dự kiến Big Test của chặng thêm N ngày. */
class ExtensionShiftsBigTestDateTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
    }

    private function request(string $kind, int $n, $assignment): SyllabusAdjustmentRequest
    {
        return SyllabusAdjustmentRequest::create([
            'class_id' => $this->class->id, 'syllabus_assignment_id' => $assignment->id, 'user_id' => $this->teacher->id,
            'request_type' => 'Giãn', 'kind' => $kind, 'reason' => 'Chậm', 'extra_sessions' => $n, 'status' => 'pending',
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);

        return $admin;
    }

    public function test_approval_shifts_expected_date_by_n_days_and_teacher_portal_shows_it(): void
    {
        $as = $this->openStage($this->stage1, ['expected_big_test_date' => '2026-10-30']);
        $req = $this->request('postpone', 3, $as);

        $this->actingAs($this->admin())->post(route('syllabus.adjustment-requests.approve', $req->id), ['extra_sessions' => 3])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-11-02', $as->fresh()->expected_big_test_date->toDateString());
        $this->actingAs($this->teacher)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request())])->get(route('syllabus.teaching-stages'))->assertOk()
            ->assertJsonPath('props.assignments.0.expected_date', '2026-11-02');
    }

    public function test_no_expected_date_stays_empty(): void
    {
        $as = $this->openStage($this->stage1);
        $req = $this->request('postpone', 2, $as);

        $this->actingAs($this->admin())->post(route('syllabus.adjustment-requests.approve', $req->id), ['extra_sessions' => 2])
            ->assertSessionHasNoErrors();

        $this->assertNull($as->fresh()->expected_big_test_date);
        $this->assertSame(2, (int) $as->fresh()->extra_sessions);
    }

    public function test_reapproval_does_not_shift_twice(): void
    {
        $as = $this->openStage($this->stage1, ['expected_big_test_date' => '2026-10-30']);
        $req = $this->request('postpone', 3, $as);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('syllabus.adjustment-requests.approve', $req->id), ['extra_sessions' => 3]);
        $this->actingAs($admin)->post(route('syllabus.adjustment-requests.approve', $req->id), ['extra_sessions' => 3])
            ->assertSessionHasErrors('status');

        $this->assertSame('2026-11-02', $as->fresh()->expected_big_test_date->toDateString());
    }

    public function test_zero_sessions_does_not_shift(): void
    {
        $as = $this->openStage($this->stage1, ['expected_big_test_date' => '2026-10-30']);
        $req = $this->request('postpone', 0, $as);

        $this->actingAs($this->admin())->post(route('syllabus.adjustment-requests.approve', $req->id), ['extra_sessions' => 0]);

        $this->assertSame('2026-10-30', $as->fresh()->expected_big_test_date->toDateString());
    }
}
