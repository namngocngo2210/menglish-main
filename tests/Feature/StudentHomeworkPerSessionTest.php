<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/**
 * Cổng học viên — nộp bài gắn với BTVN GV giao theo từng buổi (chủ dự án): thẻ hiện lớp / buổi / ngày học / hạn nộp,
 * không còn 6 hạng mục nộp tự do, bộ đếm theo giỏ của BTVN được giao (docs/kho-syllabus.md §10).
 */
class StudentHomeworkPerSessionTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private User $parent;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildSyllabusWorkflow();
        $this->parent = $this->userWithRole(Roles::STUDENT, 'Phụ huynh A');
        $this->student = Student::create(['name' => 'HS A', 'code' => 'HV-PS1', 'phone' => '0930000001', 'status' => 'studying',
            'current_class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'user_id' => $this->parent->id]);
    }

    public function test_cards_show_session_info_sorted_by_session_date_and_window(): void
    {
        $oldest = $this->classSession(-50);   // Buổi 1
        $middle = $this->classSession(-10);   // Buổi 2
        $latest = $this->classSession(-3);    // Buổi 3

        $done = $this->homework(['workbook' => 'Trang 1'], ['class_session_id' => $oldest->id]);
        $this->submit($done, 'paper');
        $oldPending = $this->homework(['workbook' => 'Trang 2'], ['class_session_id' => $oldest->id, 'title' => 'Bài cũ chưa nộp']);
        $hwMiddle = $this->homework(['workbook' => 'Workbook trang 4–5', 'quiz' => 'Vocab U1'], [
            'class_session_id' => $middle->id, 'quizizz_url' => 'https://quizizz.com/join?gc=1', 'class_note' => 'Nhớ ghi tên vào vở',
            'due_at' => today()->addDays(1)->setTime(20, 0), 'due_date' => today()->addDays(1),
        ]);
        $hwLatest = $this->homework(['video' => 'Quay video đọc đoạn 1'], ['class_session_id' => $latest->id]);
        $legacy = $this->homework(['workbook' => 'Trang 9'], ['title' => 'Bài cũ không gắn buổi']);
        $legacy->forceFill(['created_at' => now()->subDays(20)])->save();

        $response = $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Portal/Homework')
            // Bài của buổi > 30 ngày đã nộp đủ thì ẩn; bài chưa nộp đủ vẫn hiện bất kể ngày. Buổi mới nhất trước.
            ->where('assignments', fn ($cards) => collect($cards)->pluck('id')->all() === [$hwLatest->id, $hwMiddle->id, $legacy->id, $oldPending->id])
            ->where('assignments.0.session.label', 'Buổi 3')
            ->where('assignments.1.session.label', 'Buổi 2')
            ->where('assignments.1.session.date', $middle->date->toDateString())
            ->where('assignments.1.class_name', 'Lớp QT 01')
            ->where('assignments.1.note', 'Nhớ ghi tên vào vở')
            ->where('assignments.1.baskets.0.key', 'paper')
            ->where('assignments.1.baskets.0.text', 'Workbook trang 4–5')
            ->where('assignments.1.baskets.1.link', 'https://quizizz.com/join?gc=1')
            ->where('assignments.2.session', null)
            ->where('assignments.3.session.label', 'Buổi 1')
            ->missing('submissionsByType')
            ->missing('completedCount')
            ->etc());

        $response->assertSee('Buổi 2')->assertSee($middle->date->format('d/m/Y'))->assertSee('Không gắn buổi')
            ->assertSee('Workbook trang 4–5')->assertSee('Nhớ ghi tên vào vở')
            ->assertDontSee('Làm Workbook bài tập')->assertDontSee('Sách Bộ Giáo dục')->assertDontSee('Quay video bài học');
    }

    public function test_empty_state_when_no_homework_or_no_class(): void
    {
        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))->assertOk()
            ->assertSee('Chưa có bài tập về nhà được giao')
            ->assertDontSee('Làm Workbook bài tập')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assignments', [])
                ->where('basketProgress', ['submitted' => 0, 'total' => 0])->etc());

        // Học viên chưa xếp lớp: cũng chỉ thấy empty state, không có form nộp tự do.
        $this->student->update(['current_class_id' => null]);
        $this->homework(['workbook' => 'Trang 1']);
        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))->assertOk()
            ->assertSee('Chưa có bài tập về nhà được giao')
            ->assertDontSee('homework-upload-form')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assignments', [])->etc());
    }

    public function test_counters_follow_baskets_of_assigned_homework(): void
    {
        $session = $this->classSession(-2);
        $hw = $this->homework(['workbook' => 'Trang 4', 'vocabulary' => 'Chép từ'], ['class_session_id' => $session->id, 'quizizz_url' => 'https://quizizz.com/x']);
        $this->homework(['video' => 'Đọc'], ['class_session_id' => $session->id]);
        $this->submit($hw, 'paper');
        // Nộp lại cùng giỏ không cộng thêm (unique homework × học viên × giỏ); bài của học viên khác không tính.
        $other = Student::create(['name' => 'HS B', 'code' => 'HV-PS2', 'phone' => '0930000002', 'status' => 'studying',
            'current_class_id' => $this->class->id, 'branch_id' => $this->branch->id]);
        HomeworkSubmission::create(['homework_id' => $hw->id, 'student_id' => $other->id, 'basket' => 'quizizz', 'status' => 'submitted', 'submitted_at' => now()]);

        // Giỏ: [giấy, Quizizz] + [video] = 3; đã nộp 1.
        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))
            ->assertSee('Đã hoàn thành: 1/3 phần bài tập')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('basketProgress', ['submitted' => 1, 'total' => 3])->etc());
        $this->actingAs($this->parent)->get(route('portal.app-shell', ['student_id' => $this->student->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('submittedHomeworksCount', 1)->where('pendingHomeworksCount', 2)->etc());
        $this->actingAs($this->parent)->get(route('portal.student.home', $this->student->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('learningProgress.homework_submitted', 1)
                ->where('learningProgress.homework_total', 3)->etc());
    }

    public function test_cannot_submit_into_basket_of_other_class_homework(): void
    {
        $otherClass = ClassModel::create(['code' => 'QT-02', 'name' => 'Lớp QT 02', 'branch_id' => $this->branch->id, 'status' => 'active', 'teacher_id' => $this->teacher->id]);
        $foreign = Homework::create(['class_id' => $otherClass->id, 'user_id' => $this->teacher->id, 'title' => 'Bài lớp khác', 'items' => ['quiz' => 'Quiz']]);

        $this->actingAs($this->parent)->post(route('portal.student.homework.basket', [$foreign->id, 'quizizz']), ['student_id' => $this->student->id])
            ->assertNotFound();
        $this->assertSame(0, HomeworkSubmission::count());

        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))
            ->assertDontSee('Bài lớp khác')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assignments', [])->etc());
    }

    public function test_homework_list_has_no_n_plus_one(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        foreach ([-1, -2] as $offset) {
            $this->homework(['workbook' => 'Trang'], ['class_session_id' => $this->classSession($offset)->id]);
        }
        $count(); // lượt đầu nạp cache quyền / vai trò
        $few = $count();
        foreach ([-3, -4, -5, -6] as $offset) {
            $this->homework(['workbook' => 'Trang', 'video' => 'Đọc'], ['class_session_id' => $this->classSession($offset)->id]);
        }

        $this->assertSame($few, $count(), 'Số truy vấn không tăng theo số BTVN.');
    }

    private function classSession(int $dayOffset): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays($dayOffset)->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed', 'teacher_id' => $this->teacher->id]);
    }

    private function homework(array $items, array $attributes = []): Homework
    {
        return Homework::create($attributes + ['class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'title' => 'Bài tập', 'items' => $items]);
    }

    private function submit(Homework $homework, string $basket): HomeworkSubmission
    {
        return HomeworkSubmission::create(['homework_id' => $homework->id, 'student_id' => $this->student->id, 'basket' => $basket,
            'status' => 'submitted', 'submitted_at' => now()]);
    }
}
