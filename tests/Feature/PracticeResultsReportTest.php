<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptItem;
use App\Models\Student;
use App\Models\User;
use App\Services\Practice\PracticeReport;
use App\Support\Navigation\SidebarMenu;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Báo cáo Kết quả luyện tập (docs/luyen-tap-ngan-hang-cau-hoi.md §7). */
class PracticeResultsReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Course $course;

    private User $teacherA;

    private User $teacherB;

    private ClassModel $classA;

    private ClassModel $classB;

    private Student $an;

    private Student $binh;

    private Student $cuong;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-23 09:00:00'));

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-KQ', 'is_active' => true]);
        $this->course = Course::create(['name' => 'THCS', 'code' => 'THCS-KQ', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->teacherA = $this->staff(Roles::TEACHER_FULLTIME);
        $this->teacherB = $this->staff(Roles::TEACHER_PARTTIME);
        $this->classA = $this->makeClass('Lớp 7A', $this->teacherA);
        $this->classB = $this->makeClass('Lớp 8B', $this->teacherB);
        $this->an = $this->makeStudent('Nguyễn An', $this->classA);
        $this->binh = $this->makeStudent('Trần Bình', $this->classA);
        $this->cuong = $this->makeStudent('Lê Cường', $this->classB);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(string $name, User $teacher): ClassModel
    {
        return ClassModel::create([
            'name' => $name, 'code' => 'KQ-'.$teacher->id, 'course_id' => $this->course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $teacher->id, 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(3),
            'max_capacity' => 12, 'status' => 'active',
        ]);
    }

    private function makeStudent(string $name, ClassModel $class): Student
    {
        static $n = 0;
        $n++;

        return Student::create([
            'name' => $name, 'code' => "HV-KQ-$n", 'phone' => '09220000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'status' => 'studying', 'branch_id' => $this->branch->id, 'current_class_id' => $class->id, 'dob' => '2014-03-10',
        ]);
    }

    /**
     * Lượt luyện tập kèm câu: $skills = [kỹ năng => [đúng, tổng]].
     *
     * @param  array<string, array{0: int, 1: int}>  $skills
     */
    private function attempt(Student $student, array $skills, string $submittedAt, string $term = 'ck1', int $violations = 0, string $status = PracticeAttempt::SUBMITTED): PracticeAttempt
    {
        $correct = array_sum(array_column($skills, 0));
        $total = array_sum(array_column($skills, 1));
        $at = Carbon::parse($submittedAt);
        $attempt = PracticeAttempt::create([
            'student_id' => $student->id, 'grade' => 7, 'term' => $term, 'status' => $status,
            'started_at' => $at->copy()->subMinutes(30), 'deadline_at' => $at->copy()->addMinutes(15),
            'submitted_at' => $status === PracticeAttempt::SUBMITTED ? $at : null,
            'total' => $total, 'correct' => $correct,
            'score' => $status === PracticeAttempt::SUBMITTED && $total > 0 ? round($correct / $total * 10, 2) : null,
            'skill_scores' => collect($skills)->map(fn (array $s) => ['correct' => $s[0], 'total' => $s[1]])->all(),
            'violation_count' => $violations,
            'violation_log' => $violations ? array_fill(0, $violations, ['type' => 'hidden', 'at' => $at->toIso8601String()]) : null,
        ]);
        $position = 0;
        foreach ($skills as $skill => [$right, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $position++;
                $ok = $i < $right;
                PracticeAttemptItem::create([
                    'attempt_id' => $attempt->id, 'position' => $position, 'skill' => $skill, 'type' => 'Chọn đáp án',
                    'snapshot' => ['stem' => "Question $position", 'options' => ['A' => 'apple', 'B' => 'banana', 'C' => 'cherry', 'D' => 'durian'], 'answer' => 'B', 'knowledge' => 'Thì hiện tại'],
                    'chosen' => $ok ? 'B' : 'C', 'is_correct' => $ok,
                ]);
            }
        }

        return $attempt;
    }

    /** @return list<int> */
    private function rowStudentIds(AssertableInertia $page): array
    {
        return array_column($page->toArray()['props']['rows']['data'], 'student_id');
    }

    public function test_teacher_sees_only_students_of_own_classes(): void
    {
        $this->attempt($this->an, ['Ngữ âm' => [3, 4]], '2026-10-20 10:00');
        $this->attempt($this->binh, ['Ngữ âm' => [2, 4]], '2026-10-21 10:00');
        $this->attempt($this->cuong, ['Ngữ âm' => [4, 4]], '2026-10-22 10:00');

        $this->actingAs($this->teacherA)
            ->get(route('practice-results.index'))
            ->assertOk()
            ->assertSee('Kết quả luyện tập')
            ->assertSee('Nguyễn An')
            ->assertDontSee('Lê Cường')
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('PracticeResults/Index')
                    ->where('summary.attempts', 2)
                    ->where('summary.students', 2)
                    ->where('rows.total', 2);
                $this->assertEqualsCanonicalizing([$this->an->id, $this->binh->id], $this->rowStudentIds($page));
                // lọc lớp: chỉ lớp trong phạm vi
                $this->assertSame([$this->classA->id], array_column($page->toArray()['props']['filterOptions']['classes'], 'value'));
            });
    }

    public function test_academic_lead_and_admin_see_every_student_in_scope(): void
    {
        $this->attempt($this->an, ['Ngữ âm' => [3, 4]], '2026-10-20 10:00');
        $this->attempt($this->cuong, ['Ngữ âm' => [4, 4]], '2026-10-22 10:00');

        foreach ([$this->staff(Roles::ACADEMIC_LEAD), $this->staff(Roles::ADMIN)] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('practice-results.index'))
                ->assertOk()
                ->assertInertia(function (AssertableInertia $page) {
                    $page->where('summary.attempts', 2)->where('rows.total', 2);
                    // gần nhất trước
                    $this->assertSame([$this->cuong->id, $this->an->id], $this->rowStudentIds($page));
                });
        }
    }

    public function test_summary_averages_scores_and_skills(): void
    {
        // 6.00 và 8.00 → TB 7.00
        $this->attempt($this->an, ['Ngữ âm' => [3, 5], 'Đọc hiểu' => [3, 5]], '2026-10-20 10:00');
        $this->attempt($this->an, ['Ngữ âm' => [5, 5], 'Đọc hiểu' => [3, 5]], '2026-10-21 10:00');

        $summary = app(PracticeReport::class)->summary($this->staff(Roles::ACADEMIC_LEAD), []);

        $this->assertSame(2, $summary['attempts']);
        $this->assertSame(7.0, $summary['avg_score']);
        $this->assertSame(1, $summary['students']);
        $this->assertSame(['Ngữ âm', 'Từ vựng & Ngữ pháp', 'Giao tiếp & Biển báo', 'Đọc hiểu', 'Viết'], array_keys($summary['skills']));
        $this->assertSame(8.0, $summary['skills']['Ngữ âm']);   // 8/10
        $this->assertSame(6.0, $summary['skills']['Đọc hiểu']); // 6/10
        $this->assertNull($summary['skills']['Viết']);
    }

    public function test_summary_without_attempts_has_null_averages(): void
    {
        $summary = app(PracticeReport::class)->summary($this->staff(Roles::ACADEMIC_LEAD), []);

        $this->assertSame(0, $summary['attempts']);
        $this->assertNull($summary['avg_score']);
        $this->assertSame(0, $summary['students']);
        $this->assertNull($summary['skills']['Ngữ âm']);
    }

    public function test_student_rows_aggregate_per_student(): void
    {
        $this->attempt($this->an, ['Ngữ âm' => [3, 5], 'Đọc hiểu' => [1, 5]], '2026-10-20 10:00', violations: 2);
        $this->attempt($this->an, ['Ngữ âm' => [5, 5], 'Đọc hiểu' => [3, 5]], '2026-10-21 10:00');
        $this->attempt($this->binh, ['Ngữ âm' => [1, 2], 'Viết' => [1, 2]], '2026-10-19 10:00');

        $rows = app(PracticeReport::class)->studentRows($this->staff(Roles::ACADEMIC_LEAD), []);

        $this->assertSame(2, $rows->total());
        [$an, $binh] = $rows->items();
        $this->assertSame([
            'student_id' => $this->an->id, 'name' => 'Nguyễn An', 'code' => $this->an->code, 'class_name' => 'Lớp 7A',
            'attempts' => 2, 'avg_score' => 6.0, 'last_score' => 8.0,
            'last_submitted_at' => Carbon::parse('2026-10-21 10:00')->toIso8601String(),
            'weakest_skill' => 'Đọc hiểu', 'violation_attempts' => 1,
        ], $an);
        // Ngữ âm và Viết cùng 50% → kỹ năng đứng trước trong đề
        $this->assertSame('Ngữ âm', $binh['weakest_skill']);
        $this->assertSame(0, $binh['violation_attempts']);
    }

    public function test_weakest_skill_is_null_when_attempt_has_no_items(): void
    {
        $this->attempt($this->an, [], '2026-10-20 10:00');

        $rows = app(PracticeReport::class)->studentRows($this->staff(Roles::ACADEMIC_LEAD), []);

        $this->assertNull($rows->items()[0]['weakest_skill']);
        $this->assertNull($rows->items()[0]['avg_score']);
    }

    public function test_filters_by_term_class_and_dates(): void
    {
        $this->attempt($this->an, ['Ngữ âm' => [3, 5]], '2026-10-01 08:00', 'gk1');
        $this->attempt($this->an, ['Ngữ âm' => [5, 5]], '2026-10-10 23:30', 'ck1');
        $this->attempt($this->cuong, ['Ngữ âm' => [4, 5]], '2026-10-11 00:30', 'ck1');
        $lead = $this->staff(Roles::ACADEMIC_LEAD);
        $report = app(PracticeReport::class);
        $count = fn (array $input) => $report->summary($lead, $report->filters($lead, $input))['attempts'];

        $this->assertSame(3, $count([]));
        $this->assertSame(2, $count(['term' => 'ck1']));
        $this->assertSame(3, $count(['term' => 'xx', 'grade' => '5'])); // ngoài danh sách → bỏ qua
        $this->assertSame(0, $count(['grade' => '8']));
        $this->assertSame(2, $count(['class_id' => (string) $this->classA->id]));
        $this->assertSame(1, $count(['from' => '2026-10-10', 'to' => '2026-10-10'])); // cả ngày "đến"
        $this->assertSame(2, $count(['from' => '2026-10-10']));
        $this->assertSame(3, $count(['from' => '10/10/2026', 'to' => '2026-02-30'])); // sai định dạng → bỏ qua

        // Lớp ngoài phạm vi của giáo viên bị bỏ qua
        $filters = $report->filters($this->teacherA, ['class_id' => (string) $this->classB->id]);
        $this->assertNull($filters['class_id']);
        $this->assertSame(2, $report->summary($this->teacherA, $filters)['attempts']); // chỉ lượt của học viên lớp A

        // "Chỉ lớp tôi dạy": người xem vừa là Học thuật vừa dạy lớp C
        $classC = $this->makeClass('Lớp 9C', $lead);
        $this->attempt($this->makeStudent('Phạm Dũng', $classC), ['Ngữ âm' => [1, 5]], '2026-10-12 08:00');
        $this->assertSame(4, $count([]));
        $this->assertSame(1, $count(['mine' => '1']));
        $this->assertFalse($report->filters($lead, ['mine' => 'abc'])['mine']);
        $this->assertSame(2, $report->summary($this->teacherA, $report->filters($this->teacherA, ['mine' => '1']))['attempts']);

        $this->actingAs($lead)
            ->get(route('practice-results.index', ['term' => 'ck1']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.term', 'ck1')
                ->where('filters.mine', false)
                ->where('summary.attempts', 3)
                ->where('rows.total', 3));
    }

    public function test_search_by_name_or_code_narrows_rows_and_summary_within_scope(): void
    {
        $this->attempt($this->an, ['Ngữ âm' => [3, 5]], '2026-10-20 10:00');
        $this->attempt($this->binh, ['Ngữ âm' => [4, 5]], '2026-10-21 10:00');
        $this->attempt($this->binh, ['Ngữ âm' => [2, 5]], '2026-10-21 11:00');
        $this->attempt($this->cuong, ['Ngữ âm' => [5, 5]], '2026-10-22 10:00');
        $lead = $this->staff(Roles::ACADEMIC_LEAD);

        $this->actingAs($lead)
            ->get(route('practice-results.index', ['q' => 'Trần Bì']))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->where('filters.q', 'Trần Bì')->where('summary.attempts', 2)->where('summary.students', 1)->where('rows.total', 1);
                $this->assertSame([$this->binh->id], $this->rowStudentIds($page));
            });
        // theo mã học viên
        $this->actingAs($lead)
            ->get(route('practice-results.index', ['q' => $this->cuong->code]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.attempts', 1)->where('rows.data.0.student_id', $this->cuong->id));
        // khoảng trắng → không lọc
        $this->actingAs($lead)
            ->get(route('practice-results.index', ['q' => '  ']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.q', null)->where('rows.total', 3));

        // tìm học viên ngoài phạm vi (lớp B) không nới phạm vi của giáo viên lớp A
        $this->actingAs($this->teacherA)
            ->get(route('practice-results.index', ['q' => 'Lê Cường']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.attempts', 0)->where('summary.students', 0)->where('rows.total', 0));
        $this->actingAs($this->teacherA)
            ->get(route('practice-results.index', ['q' => $this->cuong->code]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.attempts', 0)->where('rows.total', 0));
    }

    public function test_student_page_back_link_keeps_index_filters(): void
    {
        $this->attempt($this->an, ['Ngữ âm' => [3, 5]], '2026-10-20 10:00');

        $this->actingAs($this->teacherA)
            ->get(route('practice-results.student', [
                'student' => $this->an->id, 'term' => 'ck1', 'class_id' => $this->classA->id, 'q' => 'An', 'mine' => 1, 'grade' => 'x',
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('backUrl', route('practice-results.index', ['term' => 'ck1', 'class_id' => $this->classA->id, 'q' => 'An', 'mine' => 1]))
                ->where('summary.attempts', 1));

        $this->actingAs($this->teacherA)
            ->get(route('practice-results.student', $this->an->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('backUrl', route('practice-results.index')));
    }

    public function test_attempt_page_back_link_keeps_student_page_filters(): void
    {
        $attempt = $this->attempt($this->an, ['Ngữ âm' => [3, 5]], '2026-10-20 10:00');
        $query = ['term' => 'ck1', 'class_id' => $this->classA->id, 'q' => 'An', 'mine' => 1, 'page' => 2];

        // Trang học viên gửi bộ lọc (đã kiểm tra lại) để link xem lại bài mang theo.
        $this->actingAs($this->teacherA)
            ->get(route('practice-results.student', ['student' => $this->an->id, ...$query, 'grade' => 'x', 'evil' => '1']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('returnQuery', $query));

        $this->actingAs($this->teacherA)
            ->get(route('practice-results.attempt', ['attempt' => $attempt->id, ...$query, 'grade' => 'x', 'evil' => '1']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('backUrl', route('practice-results.student', ['student' => $this->an->id, ...$query])));

        $this->actingAs($this->teacherA)
            ->get(route('practice-results.attempt', $attempt->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('backUrl', route('practice-results.student', $this->an->id)));
    }

    public function test_in_progress_attempts_are_excluded(): void
    {
        $this->attempt($this->an, ['Ngữ âm' => [3, 5]], '2026-10-20 10:00');
        $draft = $this->attempt($this->binh, ['Ngữ âm' => [5, 5]], '2026-10-21 10:00', status: PracticeAttempt::IN_PROGRESS);
        $lead = $this->staff(Roles::ACADEMIC_LEAD);

        $this->actingAs($lead)
            ->get(route('practice-results.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.attempts', 1)->where('rows.total', 1));

        // xem lại lượt đang làm (kể cả đã quá hạn) → 404, không chốt bài
        $this->travelTo(Carbon::parse('2026-10-22 09:00'));
        $this->actingAs($lead)->get(route('practice-results.attempt', $draft->id))->assertNotFound();
        $this->assertSame(PracticeAttempt::IN_PROGRESS, $draft->fresh()->status);
    }

    public function test_teacher_cannot_open_student_or_attempt_outside_own_classes(): void
    {
        $attempt = $this->attempt($this->cuong, ['Ngữ âm' => [3, 5]], '2026-10-20 10:00');

        $this->actingAs($this->teacherA)->get(route('practice-results.student', $this->cuong->id))->assertNotFound();
        $this->actingAs($this->teacherA)->get(route('practice-results.attempt', $attempt->id))->assertNotFound();
        $this->actingAs($this->teacherA)->get(route('practice-results.attempt', 999999))->assertNotFound();
        $this->actingAs($this->teacherB)->get(route('practice-results.student', $this->cuong->id))->assertOk();
        $this->actingAs($this->teacherB)->get(route('practice-results.attempt', $attempt->id))->assertOk();
    }

    public function test_academic_staff_without_permission_is_forbidden(): void
    {
        $attempt = $this->attempt($this->an, ['Ngữ âm' => [3, 5]], '2026-10-20 10:00');
        $staff = $this->staff(Roles::ACADEMIC_STAFF);

        $this->actingAs($staff)->get(route('practice-results.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('practice-results.student', $this->an->id))->assertForbidden();
        $this->actingAs($staff)->get(route('practice-results.attempt', $attempt->id))->assertForbidden();
    }

    public function test_student_page_lists_submitted_attempts_with_summary(): void
    {
        $this->attempt($this->an, ['Ngữ âm' => [3, 5]], '2026-10-20 10:00', 'gk1', violations: 1);
        $last = $this->attempt($this->an, ['Ngữ âm' => [4, 5]], '2026-10-21 10:00');
        $this->attempt($this->an, ['Ngữ âm' => [5, 5]], '2026-10-22 10:00', status: PracticeAttempt::IN_PROGRESS);
        $this->attempt($this->binh, ['Ngữ âm' => [5, 5]], '2026-10-22 10:00');

        $this->actingAs($this->teacherA)
            ->get(route('practice-results.student', ['student' => $this->an->id, 'term' => 'ck1']))
            ->assertOk()
            ->assertSee('Nguyễn An')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('PracticeResults/Student')
                ->where('student.id', $this->an->id)
                ->where('student.class_name', 'Lớp 7A')
                ->where('filters.term', 'ck1')
                ->where('summary.attempts', 1)
                ->where('summary.avg_score', 8)
                ->where('attempts.total', 1)
                ->where('attempts.data.0.id', $last->id)
                ->where('attempts.data.0.term_label', 'Cuối kì 1')
                ->where('attempts.data.0.score', 8)
                ->where('attempts.data.0.duration_seconds', 30 * 60));

        $this->actingAs($this->teacherA)
            ->get(route('practice-results.student', $this->an->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('attempts.total', 2)
                ->where('attempts.data.1.violation_count', 1));
    }

    public function test_attempt_page_shows_read_only_review_with_answers_and_violations(): void
    {
        $attempt = $this->attempt($this->an, ['Ngữ âm' => [1, 2]], '2026-10-20 10:00', violations: 1);
        $updatedAt = $attempt->fresh()->updated_at->toIso8601String();

        $this->actingAs($this->teacherA)
            ->get(route('practice-results.attempt', $attempt->id))
            ->assertOk()
            ->assertSee('Xem lại bài làm')
            ->assertSee('Chuyển sang tab / ứng dụng khác')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('PracticeResults/Attempt')
                ->where('student.id', $this->an->id)
                ->where('attempt.id', $attempt->id)
                ->where('attempt.score', 5)
                ->where('attempt.violation_count', 1)
                ->where('attempt.violation_log.0.label', 'Chuyển sang tab / ứng dụng khác')
                ->where('attempt.skill_scores.0.skill', 'Ngữ âm')
                ->has('items', 2)
                ->where('items.0.answer', 'B')
                ->where('items.0.is_correct', true)
                ->where('items.1.chosen', 'C')
                ->where('items.1.is_correct', false));

        $this->assertSame($updatedAt, $attempt->fresh()->updated_at->toIso8601String());
    }

    public function test_aggregations_use_a_constant_number_of_queries(): void
    {
        $lead = $this->staff(Roles::ACADEMIC_LEAD);
        $report = app(PracticeReport::class);
        // nạp sẵn quyền (Spatie cache, vai trò của người xem)
        $report->summary($lead, []);
        $report->summary($this->teacherA, []);

        $measure = function (callable $fn): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $fn();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->attempt($this->an, ['Ngữ âm' => [3, 5]], '2026-10-20 10:00');
        $this->assertLessThanOrEqual(3, $measure(fn () => $report->summary($lead, [])));
        $this->assertLessThanOrEqual(3, $measure(fn () => $report->summary($this->teacherA, [])));
        $few = $measure(fn () => $report->studentRows($lead, [])->items());

        foreach (range(1, 6) as $i) {
            $student = $this->makeStudent("Học viên $i", $this->classA);
            $this->attempt($student, ['Ngữ âm' => [2, 5], 'Viết' => [1, 2]], "2026-10-1{$i} 10:00");
            $this->attempt($student, ['Đọc hiểu' => [2, 5]], "2026-10-1{$i} 11:00");
        }
        $many = $measure(fn () => $report->studentRows($lead, [])->items());

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(8, $many);
    }

    public function test_menu_shows_results_to_teachers_and_academic_lead_only(): void
    {
        $menu = app(SidebarMenu::class);
        $routes = fn (User $user) => collect($menu->groupsFor($user->fresh()))
            ->flatMap(fn (array $g) => collect($g['items'])->pluck('route'))->all();

        $this->assertContains('practice-results.index', $routes($this->teacherA));
        $this->assertContains('practice-results.index', $routes($this->staff(Roles::ACADEMIC_LEAD)));
        $this->assertContains('practice-results.index', $routes($this->staff(Roles::ADMIN)));
        $this->assertNotContains('practice-results.index', $routes($this->staff(Roles::ACADEMIC_STAFF)));

        // Giáo viên có mục ở Cổng Giáo viên; trang báo cáo hiện trong workspace Giáo trình
        $teacherPortal = collect($menu->groupsFor($this->teacherA->fresh()))->firstWhere('id', 'teacher_portal');
        $item = collect($teacherPortal['items'])->firstWhere('route', 'practice-results.index');
        $this->assertSame(route('practice-results.index', ['mine' => 1]), $item['url']);
        $this->actingAs($this->teacherA)
            ->get(route('practice-results.index'))
            ->assertOk()
            ->assertSee('data-workspace-tabs="syllabus"', false);
    }
}
