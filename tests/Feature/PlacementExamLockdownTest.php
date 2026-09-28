<?php

namespace Tests\Feature;

use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chế độ làm bài khoá màn hình trên link test đầu vào: trang có quy chế + nút bắt đầu,
 * bài nộp lưu số lần rời bài / nhật ký / tự nộp, người chấm thấy cảnh báo.
 */
class PlacementExamLockdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function makeTest(): PlacementTest
    {
        return PlacementTest::create([
            'code' => 'LOCK-01',
            'title' => 'Đề khoá màn hình',
            'target_level' => 'A2',
            'duration_minutes' => 30,
            'questions_count' => 1,
            'is_active' => true,
            'questions' => [
                ['id' => 1, 'skill' => 'reading', 'type' => 'multiple_choice', 'title' => 'R1', 'options' => [['key' => 'A', 'text' => 'a'], ['key' => 'B', 'text' => 'b']], 'correct_answer' => 'A'],
            ],
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'candidate_name' => 'Thí Sinh Khoá',
            'candidate_phone' => '0911 222 333',
            'answers' => ['1' => 'A'],
        ], $overrides);
    }

    public function test_take_page_shows_rules_and_start_gate(): void
    {
        $test = $this->makeTest();

        $this->get(route('portal.test.take', $test->code))
            ->assertOk()
            ->assertSee('Quy chế làm bài')
            ->assertSee('id="exam-start-btn"', false)
            ->assertSee('id="exam-body" class="hidden', false)
            ->assertSee('name="violation_log"', false);
    }

    public function test_submission_without_lockdown_fields_defaults_to_clean(): void
    {
        $test = $this->makeTest();

        $this->post(route('portal.test.submit', $test->code), $this->payload())->assertRedirect();

        $submission = PlacementTestSubmission::firstOrFail();
        $this->assertSame(0, $submission->violation_count);
        $this->assertNull($submission->violation_log);
        $this->assertFalse($submission->auto_submitted);
    }

    public function test_submission_stores_sanitized_violation_log_and_auto_submit_flag(): void
    {
        $test = $this->makeTest();
        $log = [
            ['type' => 'hidden', 'at' => '2026-09-28T03:00:00.000Z'],
            ['type' => '<script>', 'at' => 'x'],
            'garbage',
            ['type' => 'fullscreen_exit', 'at' => '2026-09-28T03:01:00.000Z', 'extra' => 'drop me'],
        ];

        $this->post(route('portal.test.submit', $test->code), $this->payload([
            'violation_count' => 3,
            'violation_log' => json_encode($log),
            'auto_submitted' => '1',
        ]))->assertRedirect();

        $submission = PlacementTestSubmission::firstOrFail();
        $this->assertSame(3, $submission->violation_count);
        $this->assertTrue($submission->auto_submitted);
        $this->assertSame([
            ['type' => 'hidden', 'at' => '2026-09-28T03:00:00.000Z'],
            ['type' => 'fullscreen_exit', 'at' => '2026-09-28T03:01:00.000Z'],
        ], $submission->violation_log);
    }

    public function test_invalid_violation_log_is_ignored(): void
    {
        $test = $this->makeTest();

        $this->post(route('portal.test.submit', $test->code), $this->payload([
            'violation_count' => 1,
            'violation_log' => 'not json',
        ]))->assertRedirect();

        $this->assertNull(PlacementTestSubmission::firstOrFail()->violation_log);
    }

    public function test_grader_sees_violation_warning_on_result_and_list(): void
    {
        $test = $this->makeTest();
        $this->post(route('portal.test.submit', $test->code), $this->payload([
            'violation_count' => 2,
            'violation_log' => json_encode([['type' => 'blur', 'at' => '2026-09-28T03:00:00Z']]),
            'auto_submitted' => '1',
        ]))->assertRedirect();
        $submission = PlacementTestSubmission::firstOrFail();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('academic_lead');

        $this->actingAs($user)->get(route('placement-tests.results.show', $submission->id))
            ->assertOk()
            ->assertSee('Thí sinh rời khỏi bài thi 2 lần · bài bị tự động nộp')
            ->assertSee('Rời khỏi cửa sổ bài thi');

        $this->actingAs($user)->get(route('placement-tests.index'))
            ->assertOk()
            ->assertSee('Rời bài 2 lần · tự nộp');
    }
}
