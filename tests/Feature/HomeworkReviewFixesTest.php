<?php

namespace Tests\Feature;

use App\Models\ClassEnrollment;
use App\Models\ClassSession;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use App\Models\User;
use App\Services\Homework\HomeworkStats;
use App\Services\KpiBoardService;
use App\Support\Dashboard\TeachingQuality;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Sửa sau review giai đoạn 5 (docs/kho-syllabus.md §10): mỗi test tái hiện một lỗi review tìm ra. */
class HomeworkReviewFixesTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private Student $a;

    private Student $b;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildSyllabusWorkflow();
        $this->parent = $this->userWithRole(Roles::STUDENT, 'Phụ huynh An');
        $this->a = $this->makeStudent('An', 1, ['user_id' => $this->parent->id]);
        $this->b = $this->makeStudent('Bình', 2);
    }

    public function test_kpi_and_teaching_quality_count_basket_submissions(): void
    {
        $hw = $this->homework(['workbook' => 'Trang 4', 'video' => 'Đọc'], ['due_date' => today()]);
        $this->submit($hw, $this->a, 'paper');
        $this->submit($hw, $this->a, 'video');
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        $board = app(KpiBoardService::class)->metricsForMany([$this->teacher], $from, $to)[$this->teacher->id];
        $this->assertSame(50.0, $board['homework'], '1 / 2 học viên đã làm bài (nộp theo giỏ).');
        $quality = (new TeachingQuality(null, $from, $to))->classMetrics(collect([$this->class]));
        $this->assertSame(50.0, $quality[0]['homework']);
    }

    public function test_edit_keeps_submitted_basket_and_delete_is_blocked(): void
    {
        $session = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => 'regular', 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
        $hw = $this->homework(['workbook' => 'Trang 4', 'video' => 'Đọc'], ['class_session_id' => $session->id]);
        $this->submit($hw, $this->a, 'video');

        $this->actingAs($this->teacher)->put(route('teacher.homework.update', [$this->class->id, $hw->id]), [
            'class_session_id' => $session->id, 'categories' => ['workbook'], 'items' => ['workbook' => 'Trang 5'],
            'due_at' => today()->addDays(2)->setTime(20, 0)->toDateTimeString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(['workbook' => 'Trang 5', 'video' => 'Đọc'], $hw->fresh()->items, 'Hạng mục đã có bài nộp theo giỏ không bỏ được.');

        $this->actingAs($this->teacher)->delete(route('teacher.homework.destroy', [$this->class->id, $hw->id]))->assertSessionHasErrors('homework');
        $this->assertNotNull($hw->fresh());
    }

    public function test_stats_ignore_submissions_of_baskets_no_longer_assigned(): void
    {
        $hw = $this->homework(['workbook' => 'Trang 4']);
        $this->submit($hw, $this->a, 'video');

        $stats = app(HomeworkStats::class)->forClass($this->class, today()->subDays(30), today());

        $this->assertSame(['submitted' => 0, 'assigned' => 1, 'rate' => 0.0], collect($stats['students'])->firstWhere('id', $this->a->id)['total']);
    }

    public function test_stats_count_only_homework_after_student_joined(): void
    {
        $old = $this->homework(['workbook' => 'Trang 1']);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->homework(['workbook' => 'Trang 2']);
        $late = $this->makeStudent('Cường', 3, ['current_class_id' => null]);
        ClassEnrollment::create(['student_id' => $late->id, 'class_id' => $this->class->id, 'enrolled_at' => today()->subDays(2), 'status' => 'pending']);

        $stats = app(HomeworkStats::class)->forClass($this->class, today()->subDays(30), today());

        $this->assertSame(1, collect($stats['students'])->firstWhere('id', $late->id)['total']['assigned'], 'Bài giao trước khi vào lớp không tính.');
        $this->assertSame(2, collect($stats['students'])->firstWhere('id', $this->a->id)['total']['assigned']);
    }

    public function test_date_filter_has_submit_button_and_invalid_date_is_rejected(): void
    {
        $this->actingAs($this->teacher)->get(route('teacher.homework-submissions', [$this->class->id, 'to' => 'abc']))->assertSessionHasErrors('to');
        $this->actingAs($this->teacher)->get(route('teacher.homework-submissions', $this->class->id))->assertOk()->assertSee('data-testid="homework-filter-submit"', false);
    }

    public function test_file_is_served_with_range_support_and_missing_file_is_404(): void
    {
        $hw = $this->homework(['video' => 'Đọc']);
        Storage::disk('local')->put('homework_submissions/clip.mp4', str_repeat('x', 2048));
        $row = $this->submit($hw, $this->a, 'video', ['file_path' => 'homework_submissions/clip.mp4', 'original_name' => 'clip.mp4']);

        $response = $this->actingAs($this->teacher)->get(route('portal.student.homework-file', $row->id));
        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse, 'Video tua / phát được trên iPhone cần Range (206).');

        Storage::disk('local')->delete('homework_submissions/clip.mp4');
        $this->actingAs($this->teacher)->get(route('portal.student.homework-file', $row->id))->assertNotFound();
    }

    public function test_legacy_submissions_screen_links_basket_screen(): void
    {
        $this->actingAs($this->teacher)->get(route('portal.teacher.submissions', $this->class->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('basketUrl', route('teacher.homework-submissions', $this->class->id))->etc());
    }

    public function test_portal_lists_every_assigned_homework_in_window(): void
    {
        foreach (range(1, 12) as $i) {
            $this->homework(['workbook' => "Trang {$i}"]);
        }

        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->a->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('assignments', 12)->etc());
    }

    public function test_resubmit_keeps_old_file_until_saved(): void
    {
        $hw = $this->homework(['workbook' => 'Trang 4']);
        $url = route('portal.student.homework.basket', [$hw->id, 'paper']);
        $this->actingAs($this->parent)->post($url, ['student_id' => $this->a->id, 'file' => UploadedFile::fake()->image('lan1.jpg')]);
        $first = HomeworkSubmission::sole()->file_path;

        $this->actingAs($this->parent)->post($url, ['student_id' => $this->a->id, 'file' => UploadedFile::fake()->image('lan2.jpg')])->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists(HomeworkSubmission::sole()->file_path);
    }

    private function homework(array $items, array $attributes = []): Homework
    {
        return Homework::create($attributes + ['class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'title' => 'Bài tập', 'items' => $items]);
    }

    private function submit(Homework $homework, Student $student, string $basket, array $attributes = []): HomeworkSubmission
    {
        return HomeworkSubmission::create($attributes + ['homework_id' => $homework->id, 'student_id' => $student->id, 'basket' => $basket, 'status' => 'submitted', 'submitted_at' => now()]);
    }

    private function makeStudent(string $name, int $n, array $attributes = []): Student
    {
        return Student::create($attributes + ['name' => $name, 'code' => "HV-RF{$n}", 'phone' => '092000000'.$n, 'status' => 'studying',
            'current_class_id' => $this->class->id, 'branch_id' => $this->branch->id]);
    }
}
