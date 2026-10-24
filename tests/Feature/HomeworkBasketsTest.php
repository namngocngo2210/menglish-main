<?php

namespace Tests\Feature;

use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Homework 3 giỏ — HS nộp theo bài tập & giỏ: giấy = ảnh, video = video, Quizizz = tự bấm "Đã làm" (docs/kho-syllabus.md §10). */
class HomeworkBasketsTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private User $parent;

    private Student $student;

    private Homework $homework;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildSyllabusWorkflow();
        $this->parent = $this->userWithRole(Roles::STUDENT, 'Phụ huynh A');
        $this->student = $this->makeStudent('HS A', $this->class->id, $this->parent->id);
        $this->homework = Homework::create(['class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'title' => 'Bài tập Unit 1',
            'items' => ['workbook' => 'Workbook trang 4–5', 'quiz' => 'Quizizz Vocab U1', 'video' => 'Quay video đọc đoạn 1'],
            'quizizz_url' => 'https://quizizz.com/join?gc=123', 'due_date' => today()->addDays(2)]);
    }

    public function test_basket_mapping_from_categories(): void
    {
        $this->assertSame(['video', 'quizizz', 'paper', 'paper'], array_map(Homework::basketOf(...), ['video', 'quiz', 'workbook', 'vocabulary']));
        $this->assertSame(['paper', 'quizizz', 'video'], $this->homework->baskets());
        $this->assertSame(['quizizz'], (new Homework(['items' => [], 'quizizz_url' => 'https://quizizz.com/x']))->baskets());
        $this->assertSame([], (new Homework(['items' => [], 'class_note' => 'Ôn bài']))->baskets(), 'Bài tập chỉ có ghi chú: không có giỏ.');
    }

    public function test_student_submits_photo_paper_video_and_quizizz_done(): void
    {
        $this->actingAs($this->parent)->post($this->url('paper'), ['student_id' => $this->student->id, 'file' => UploadedFile::fake()->image('bai.jpg'), 'note' => 'Con làm xong'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->parent)->post($this->url('video'), ['student_id' => $this->student->id, 'file' => $this->video()])->assertSessionHasNoErrors();
        $this->actingAs($this->parent)->post($this->url('quizizz'), ['student_id' => $this->student->id])->assertSessionHasNoErrors();

        $rows = HomeworkSubmission::orderBy('basket')->get()->keyBy('basket');
        $this->assertSame(['paper', 'quizizz', 'video'], $rows->keys()->all());
        $this->assertSame(['submitted', 'bai.jpg', 'Con làm xong'], [$rows['paper']->status, $rows['paper']->original_name, $rows['paper']->note]);
        Storage::disk('local')->assertExists($rows['paper']->file_path);
        $this->assertNull($rows['quizizz']->file_path, 'Quizizz: HS tự khai "Đã làm", không tải file.');

        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assignments.0.id', $this->homework->id)
                ->where('assignments.0.baskets.0.key', 'paper')
                ->where('assignments.0.baskets.0.submission.status', 'submitted')
                ->where('assignments.0.baskets.1.key', 'quizizz')
                ->where('assignments.0.baskets.1.link', 'https://quizizz.com/join?gc=123')
                ->etc());
        $this->actingAs($this->parent)->get(route('portal.student.homework-file', $rows['paper']->id))->assertOk();
        $this->actingAs($this->userWithRole(Roles::STUDENT, 'Phụ huynh khác'))->get(route('portal.student.homework-file', $rows['paper']->id))->assertNotFound();
    }

    public function test_resubmit_updates_same_row(): void
    {
        $this->actingAs($this->parent)->post($this->url('paper'), ['student_id' => $this->student->id, 'file' => UploadedFile::fake()->image('lan1.jpg')]);
        $first = HomeworkSubmission::sole();
        $first->update(['status' => 'reviewed', 'reviewed_by' => $this->teacher->id, 'reviewed_at' => now()]);

        $this->actingAs($this->parent)->post($this->url('paper'), ['student_id' => $this->student->id, 'file' => UploadedFile::fake()->image('lan2.jpg')])
            ->assertSessionHasNoErrors();

        $row = HomeworkSubmission::sole();
        $this->assertSame([$first->id, 'lan2.jpg', 'submitted', null], [$row->id, $row->original_name, $row->status, $row->reviewed_at]);
        Storage::disk('local')->assertMissing($first->file_path);
    }

    public function test_wrong_file_type_rejected(): void
    {
        $this->actingAs($this->parent)->post($this->url('video'), ['student_id' => $this->student->id, 'file' => $this->pdf()])->assertSessionHasErrors('file');
        $this->actingAs($this->parent)->post($this->url('paper'), ['student_id' => $this->student->id, 'file' => $this->video()])->assertSessionHasErrors('file');
        $this->actingAs($this->parent)->post($this->url('paper'), ['student_id' => $this->student->id])->assertSessionHasErrors('file');

        $this->assertSame(0, HomeworkSubmission::count());
    }

    public function test_cannot_submit_other_class_homework_or_missing_basket(): void
    {
        // Con thứ hai của cùng phụ huynh (gắn qua email), không học lớp có bài tập.
        $other = $this->makeStudent('HS lớp khác', null, null, $this->parent->email);
        $this->actingAs($this->parent)->post($this->url('quizizz'), ['student_id' => $other->id])->assertNotFound();

        $noVideo = Homework::create(['class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'title' => 'Chỉ workbook', 'items' => ['workbook' => 'Trang 6']]);
        $this->actingAs($this->parent)->post(route('portal.student.homework.basket', [$noVideo->id, 'video']), ['student_id' => $this->student->id, 'file' => $this->video()])
            ->assertNotFound();

        $stranger = $this->userWithRole(Roles::STUDENT, 'Người lạ');
        $this->actingAs($stranger)->post($this->url('quizizz'), ['student_id' => $this->student->id])->assertForbidden();

        $this->homework->delete();
        $this->actingAs($this->parent)->post($this->url('quizizz'), ['student_id' => $this->student->id])->assertNotFound();
        $this->assertSame(0, HomeworkSubmission::count());
    }

    private function url(string $basket): string
    {
        return route('portal.student.homework.basket', [$this->homework->id, $basket]);
    }

    private function makeStudent(string $name, ?int $classId, ?int $userId, ?string $email = null): Student
    {
        static $n = 0;
        $n++;

        return Student::create(['name' => $name, 'code' => "HV-QT{$n}", 'phone' => '090000000'.$n, 'status' => 'active',
            'current_class_id' => $classId, 'branch_id' => $this->branch->id, 'user_id' => $userId, 'email' => $email]);
    }

    private function video(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('bai.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }
}
