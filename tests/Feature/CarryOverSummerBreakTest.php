<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Services\Tuition\SessionLedger;
use App\Services\TuitionImportService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CarryOverSummerBreakTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Course $course;

    private ClassModel $classA;

    private User $academic;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-04 10:00:00');
        $this->branch = Branch::create(['name' => 'Cơ sở đầu kỳ', 'code' => 'COS', 'is_active' => true]);
        $this->academic = $this->makeUser(Roles::ACADEMIC_STAFF);
        $this->course = Course::create(['code' => 'COS24', 'name' => 'Khóa 24 buổi', 'tuition_fee' => 4800000, 'total_lessons' => 24, 'is_active' => true]);
        $this->classA = $this->makeClass('COS-A');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hp_lp_03_first_opening_survives_transfer_to_an_unstarted_class(): void
    {
        $student = $this->makeStudent($this->classA, 3);
        $next = $this->makeClass('COS-NEXT');
        $next->update(['status' => 'upcoming', 'start_date' => '2026-10-10']);
        $this->sessions($next, ['2026-10-10', '2026-10-12']);

        $this->actingAs($this->academic)->post(route('students.transfer-class', $student->id), [
            'class_id' => $next->id, 'transferred_on' => '2026-10-04',
        ])->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame($next->id, $student->current_class_id);
        $this->assertSame(['opening' => 3, 'paid' => 0, 'used' => 0, 'balance' => 3], app(SessionLedger::class)->forStudent($student));
        $quote = app(SessionLedger::class)->quote($student);
        $this->assertSame(24, $quote['course_sessions']);
        $this->assertSame(21, $quote['needed']);
        $this->assertSame(21, $quote['max_sessions']);
        $this->assertEquals(200000, $quote['unit_price']);
        $this->assertEquals(4200000, SessionLedger::calculate($quote, 21, 0, 0, 0, 0, 0)['session_value']);
    }

    public function test_hp_lp_04_and_hp_dh_10_count_summer_with_and_without_opening(): void
    {
        $this->sessions($this->classA, ['2026-10-05', '2026-10-07', '2026-10-09', '2026-10-11']);
        foreach ([0 => 20, 1 => 19] as $opening => $needed) {
            $student = $this->makeStudent($this->classA, $opening);
            $student->update(['summer_break_from' => '2026-10-05', 'summer_break_to' => '2026-10-11']);
            $quote = app(SessionLedger::class)->quote($student);
            $this->assertSame(4, $quote['summer_sessions']);
            $this->assertSame($opening, $quote['carry']);
            $this->assertSame($needed, $quote['needed']);
            $this->assertSame(0, $quote['carry_over']);
            $this->assertEquals($needed * 200000, SessionLedger::calculate($quote, $needed, 0, 0, 0, 0, 0)['session_value']);
        }
    }

    public function test_summer_deduction_survives_the_dates_passing_unpaid_and_paid(): void
    {
        $this->sessions($this->classA, ['2026-10-05', '2026-10-07', '2026-10-09', '2026-10-11']);
        $unpaid = $this->makeStudent($this->classA);
        $paid = $this->makeStudent($this->classA);
        $this->tuition($paid, 4000000); // 20 sessions at 200,000.
        foreach ([$unpaid, $paid] as $student) {
            $this->actingAs($this->academic)->post(route('students.summer-break', $student->id), ['from' => '2026-10-05', 'to' => '2026-10-11'])->assertSessionHasNoErrors();
        }
        $ledger = app(SessionLedger::class);
        $before = [$ledger->quote($unpaid->fresh()), $ledger->quote($paid->fresh())];
        $this->assertSame([20, 4, 0], [$before[0]['needed'], $before[0]['summer_sessions'], $before[0]['used']]);
        $this->assertSame([0, 4, 0], [$before[1]['needed'], $before[1]['summer_sessions'], $before[1]['used']]);

        Carbon::setTestNow('2026-10-12 10:00:00');
        $after = [$ledger->quote($unpaid->fresh()), $ledger->quote($paid->fresh())];
        $this->assertSame([20, 0, 0, 0], [$after[0]['needed'], $after[0]['summer_sessions'], $after[0]['used'], $after[0]['balance']]);
        $this->assertSame([0, 0, 0, 20], [$after[1]['needed'], $after[1]['summer_sessions'], $after[1]['used'], $after[1]['balance']]);
        $this->assertEquals(20 * 200000, SessionLedger::calculate($after[0], 20, 0, 0, 0, 0, 0)['session_value']);

        // Class transfer after the dates pass keeps the already-excluded sessions out of the ledger.
        $next = $this->makeClass('COS-AFTER');
        $this->actingAs($this->academic)->post(route('students.transfer-class', $unpaid->id), ['class_id' => $next->id, 'transferred_on' => '2026-10-12'])->assertSessionHasNoErrors();
        $this->assertSame(0, $ledger->forStudent($unpaid->fresh())['used']);
    }

    public function test_summer_range_saved_after_sessions_were_held_does_not_refund_them(): void
    {
        $this->sessions($this->classA, ['2026-10-01', '2026-10-05']);
        $student = $this->makeStudent($this->classA);
        $this->actingAs($this->academic)->post(route('students.summer-break', $student->id), ['from' => '2026-10-01', 'to' => '2026-10-11'])->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->assertSame(1, app(SessionLedger::class)->forStudent($student->fresh())['used']);
    }

    public function test_no_carry_and_surplus_carry_never_create_negative_charges(): void
    {
        foreach ([0 => [24, 0], 30 => [0, 6]] as $opening => [$needed, $surplus]) {
            $quote = app(SessionLedger::class)->quote($this->makeStudent($this->classA, $opening));
            $this->assertSame($needed, $quote['needed']);
            $this->assertSame($surplus, $quote['carry_over']);
            $this->assertSame(0, $quote['summer_sessions']);
        }
    }

    public function test_redeclaring_or_overlapping_ranges_replaces_instead_of_adding_and_clear_removes_it(): void
    {
        $student = $this->makeStudent($this->classA);
        $this->sessions($this->classA, ['2026-10-05', '2026-10-07', '2026-10-09']);
        $this->sessions($this->classA, ['2026-10-06'], 'cancelled'); // Holiday.
        $this->sessions($this->classA, ['2026-10-08'], 'scheduled', '08:00', ClassSession::TYPE_SUPPORT);
        $this->sessions($this->classA, ['2026-10-10'], 'scheduled', '08:00', ClassSession::TYPE_MAKEUP);
        $url = route('students.summer-break', $student->id);
        foreach (range(1, 2) as $repeat) {
            $this->actingAs($this->academic)->post($url, ['from' => '2026-10-05', 'to' => '2026-10-10'])->assertSessionHasNoErrors();
            $this->assertSame(4, app(SessionLedger::class)->quote($student->fresh())['summer_sessions']);
        }
        $this->actingAs($this->academic)->post($url, ['from' => '2026-10-07', 'to' => '2026-10-09'])->assertSessionHasNoErrors();
        $this->assertSame(2, app(SessionLedger::class)->quote($student->fresh())['summer_sessions']);
        $this->actingAs($this->academic)->postJson($url, ['clear' => true])->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->summer_break_from);
        $this->assertNull($student->fresh()->summer_break_to);
        $this->assertSame(0, app(SessionLedger::class)->quote($student->fresh())['summer_sessions']);
    }

    public function test_mid_course_transfer_excludes_held_sessions_and_caps_summer_at_remaining(): void
    {
        $student = $this->makeStudent($this->classA, 10);
        $this->sessions($this->classA, ['2026-09-20', '2026-09-21']);
        $next = $this->makeClass('COS-MID');
        $this->sessions($next, ['2026-09-22', '2026-09-23', '2026-10-01']);
        $this->sessions($next, ['2026-10-04'], 'scheduled', '10:00'); // Held exactly at now.
        $this->sessions($next, ['2026-10-04'], 'scheduled', '18:00');
        $this->sessions($next, array_map(fn ($d) => sprintf('2026-10-%02d', $d), range(5, 25)));
        $this->actingAs($this->academic)->post(route('students.transfer-class', $student->id), [
            'class_id' => $next->id, 'transferred_on' => '2026-10-01',
        ])->assertSessionHasNoErrors();
        $student->refresh()->update(['summer_break_from' => '2026-09-01', 'summer_break_to' => '2026-10-31']);
        $ledger = app(SessionLedger::class)->forStudent($student);
        $this->assertSame(['opening' => 10, 'paid' => 0, 'used' => 4, 'balance' => 6], $ledger);
        $quote = app(SessionLedger::class)->quote($student);
        $this->assertSame(4, $quote['held']);
        $this->assertSame(20, $quote['remaining']);
        $this->assertSame(20, $quote['summer_sessions']);
        $this->assertSame(0, $quote['needed']);
        $this->assertSame(0, $quote['carry_over']);
    }

    public function test_quote_many_matches_single_quotes_at_the_same_time_without_per_student_queries(): void
    {
        $students = collect(range(1, 5))->map(function () {
            $student = $this->makeStudent($this->classA, 1);
            $student->update(['summer_break_from' => '2026-10-04', 'summer_break_to' => '2026-10-10']);

            return $student->load('currentClass.course');
        });
        $this->sessions($this->classA, ['2026-10-04'], 'scheduled', '18:00');
        $at = Carbon::parse('2026-10-04 19:00:00');
        $pairs = $students->mapWithKeys(fn ($s) => [$s->id => [$s, null]])->all();
        $service = app(SessionLedger::class);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $service->quoteMany(array_slice($pairs, 0, 1, true), $at);
            $singleQueries = count(DB::getQueryLog());
            DB::flushQueryLog();
            $quotes = $service->quoteMany($pairs, $at);
            $this->assertSame($singleQueries, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
        foreach ($students as $student) {
            $this->assertSame($service->quote($student, now: $at), $quotes[$student->id]);
            $this->assertSame(0, $quotes[$student->id]['summer_sessions']);
            $this->assertSame(1, $quotes[$student->id]['held']);
        }
    }

    public function test_open_contract_summer_limit_does_not_turn_partial_sessions_into_the_full_contract_price(): void
    {
        $student = $this->makeStudent($this->classA, 1);
        $tuition = $this->tuition($student);
        $this->sessions($this->classA, ['2026-10-05', '2026-10-07', '2026-10-09', '2026-10-11']);
        $student->update(['summer_break_from' => '2026-10-05', 'summer_break_to' => '2026-10-11']);
        $quote = app(SessionLedger::class)->quote($student, $tuition);
        $this->assertSame(19, $quote['needed']);
        $this->assertSame(19, $quote['suggested']);
        $this->assertSame(24, $quote['contract_remaining_sessions']);
        $this->assertEquals(3800000, SessionLedger::calculate($quote, 19, 0, 0, 0, 0, 0)['session_value']);
    }

    public function test_summer_break_permissions_and_branch_scope(): void
    {
        $student = $this->makeStudent($this->classA);
        foreach ([Roles::ACADEMIC_STAFF, Roles::ADMIN] as $role) {
            $this->actingAs($this->makeUser($role))->post(route('students.summer-break', $student->id), [
                'from' => '2026-10-05', 'to' => '2026-10-05',
            ])->assertSessionHasNoErrors()->assertRedirect();
        }
        foreach (array_diff(Roles::ALL, [Roles::ACADEMIC_STAFF, Roles::ADMIN]) as $role) {
            $this->actingAs($this->makeUser($role))->post(route('students.summer-break', $student->id), [
                'from' => '2026-10-05', 'to' => '2026-10-06',
            ])->assertForbidden();
        }
        $other = Branch::create(['name' => 'Cơ sở khác', 'code' => 'COS-OTHER', 'is_active' => true]);
        $student->update(['branch_id' => $other->id]);
        $this->actingAs($this->academic)->post(route('students.summer-break', $student->id), [
            'from' => '2026-10-05', 'to' => '2026-10-06',
        ])->assertNotFound();
    }

    public function test_permission_migration_grants_only_academic_staff_and_can_be_reversed(): void
    {
        $migration = require database_path('migrations/2026_12_20_090100_add_student_summer_break_permission.php');
        $migration->down();
        $this->assertDatabaseMissing('permissions', ['name' => 'student.summer_break', 'guard_name' => 'web']);
        $migration->up();
        $migration->up();
        $permission = Permission::findByName('student.summer_break', 'web');
        $this->assertSame([Roles::ACADEMIC_STAFF], $permission->roles()->pluck('name')->all());
    }

    public function test_summer_break_validates_both_dates_and_keeps_existing_range_on_errors(): void
    {
        $student = $this->makeStudent($this->classA);
        $student->update(['summer_break_from' => '2026-10-05', 'summer_break_to' => '2026-10-06']);
        $url = route('students.summer-break', $student->id);
        $this->actingAs($this->academic)->post($url, [])->assertSessionHasErrors(['from', 'to']);
        $this->post($url, ['from' => 'invalid', 'to' => '2026-10-06'])->assertSessionHasErrors('from');
        $this->post($url, ['from' => '2026-10-07', 'to' => '2026-10-06'])->assertSessionHasErrors('to');
        $this->assertSame('2026-10-05', $student->fresh()->summer_break_from->toDateString());
    }

    public function test_import_sets_opening_once_and_rechecks_a_stale_preview_inside_the_transaction(): void
    {
        $student = $this->makeStudent($this->classA);
        $service = app(TuitionImportService::class);
        $preview = $service->parse($this->csv([
            ['Mã học viên', 'Số buổi', 'Đã học'], [$student->code, '24', '21'], [$student->code, '24', '20'],
        ]), $this->branch->id);
        $this->assertSame([], $preview['rows'][0]['errors']);
        $this->assertContains('Học viên đã có số buổi dư đầu kỳ', $preview['rows'][1]['errors']);
        $row = $preview['rows'][0];
        $this->assertSame([], $service->import([$row], $this->branch->id, $this->academic)['failed']);
        $this->assertSame(3, $student->fresh()->opening_sessions);
        $this->assertNotNull($student->fresh()->opening_sessions_set_at);
        $this->assertSame('Học viên đã có số buổi dư đầu kỳ', $service->import([$row], $this->branch->id, $this->academic)['failed'][0]['error']);
        $preview = $service->parse($this->csv([['Mã học viên', 'tong_so_buoi', 'so_buoi_da_hoc'], [$student->code, 24, 21]]), $this->branch->id);
        $this->assertContains('Học viên đã có số buổi dư đầu kỳ', $preview['rows'][0]['errors']);
    }

    public function test_import_accepts_zero_and_aliases_and_ignores_absent_or_blank_optional_cells(): void
    {
        $student = $this->makeStudent($this->classA);
        $service = app(TuitionImportService::class);
        $rows = $service->parse($this->csv([['Mã học viên', 'tong_so_buoi', 'so_buoi_da_hoc'], [$student->code, 0, 0]]), $this->branch->id)['rows'];
        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame([], $service->import($rows, $this->branch->id, $this->academic)['failed']);
        $this->assertNotNull($student->fresh()->opening_sessions_set_at);
        $this->assertSame(0, $student->fresh()->opening_sessions);
        $untouched = $this->makeStudent($this->classA);
        $this->tuition($untouched);
        $legacy = $service->parse($this->csv([['Mã học viên'], [$untouched->code]]), $this->branch->id)['rows'];
        $this->assertSame([], $service->import($legacy, $this->branch->id, $this->academic)['failed']);
        $this->assertNull($untouched->fresh()->opening_sessions_set_at);
        $this->assertSame(0, $untouched->fresh()->opening_sessions);
        $this->tuition($student);
        foreach ([['Mã học viên'], ['Mã học viên', 'Số buổi', 'Đã học']] as $headers) {
            $stamp = $student->fresh()->opening_sessions_set_at->toDateTimeString();
            $rows = $service->parse($this->csv([$headers, [$student->code]]), $this->branch->id)['rows'];
            $this->assertSame([], $rows[0]['errors']);
            $this->assertSame([], $service->import($rows, $this->branch->id, $this->academic)['failed']);
            $this->assertSame($stamp, $student->fresh()->opening_sessions_set_at->toDateTimeString());
        }
    }

    public function test_import_rejects_invalid_session_counts_and_rolls_opening_back_when_the_row_fails(): void
    {
        $student = $this->makeStudent($this->classA);
        $this->tuition($student);
        $service = app(TuitionImportService::class);
        foreach ([[-1, 0], [24, -1], ['1.5', 0], [24, '1.5'], [2, 3], [24, ''], ['', 1], ['1e2', 0], ['4294967296', 0]] as [$total, $used]) {
            $rows = $service->parse($this->csv([['Mã học viên', 'so_buoi', 'da_hoc'], [$student->code, $total, $used]]), $this->branch->id)['rows'];
            $this->assertNotEmpty($rows[0]['errors']);
            $service->import($rows, $this->branch->id, $this->academic);
            $this->assertNull($student->fresh()->opening_sessions_set_at);
        }
        $rows = $service->parse($this->csv([['Mã học viên', 'Số buổi', 'Đã học'], [$student->code, 24, 21]]), $this->branch->id)['rows'];
        // Simulate a conflicting contract created after preview; the opening write must roll back too.
        $rows[0]['creates_tuition'] = true;
        $this->assertCount(1, $service->import($rows, $this->branch->id, $this->academic)['failed']);
        $this->assertNull($student->fresh()->opening_sessions_set_at);
        $this->assertSame(0, $student->fresh()->opening_sessions);
    }

    public function test_hp_ht_02_and_04_transfer_cannot_exceed_the_actual_ledger_balance(): void
    {
        $source = $this->makeStudent($this->classA);
        $tuition = $this->tuition($source, 2000000); // Ten paid sessions.
        $target = $this->makeStudent($this->classA);
        $this->tuition($target);
        $this->sessions($this->classA, ['2026-09-05', '2026-09-07', '2026-09-09', '2026-09-11']);
        $payload = ['student_id' => $source->id, 'type' => 'transfer', 'target_student_id' => $target->id, 'reason' => 'Chuyển cho bạn cùng lớp'];
        $this->assertSame(20, $tuition->fresh()->transferValue()['sessions']);
        $this->actingAs($this->academic)->postJson(route('tuition.refunds.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('student_id');
        $this->assertSame(0, TuitionRefundRequest::count());
        $source->update(['opening_sessions' => 14, 'opening_sessions_set_at' => now()]);
        $this->assertSame(20, app(SessionLedger::class)->forStudent($source)['balance']);
        $this->post(route('tuition.refunds.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, TuitionRefundRequest::count());
    }

    public function test_negative_balance_is_preserved_but_display_and_transfer_availability_are_clamped(): void
    {
        $source = $this->makeStudent($this->classA);
        $this->tuition($source, 200000);
        $target = $this->makeStudent($this->classA);
        $this->tuition($target);
        $this->sessions($this->classA, ['2026-09-05', '2026-09-07']);
        $quote = app(SessionLedger::class)->quote($source);
        $this->assertSame(-1, $quote['balance']);
        $this->assertSame(0, $quote['carry']);
        $this->assertSame(23, $quote['needed']);
        $this->actingAs($this->academic)->post(route('tuition.refunds.store'), [
            'student_id' => $source->id, 'target_student_id' => $target->id, 'type' => 'transfer', 'reason' => 'Chuyển buổi',
        ])->assertSessionHasErrors(['student_id' => 'Số buổi chuyển nhượng vượt số buổi còn lại thực tế của học viên (0 buổi).']);
        $this->assertSame(0, TuitionRefundRequest::count());
    }

    public function test_classmate_suggestions_are_limited_to_ten_eligible_scoped_students(): void
    {
        $source = $this->makeStudent($this->classA);
        $this->tuition($source);
        $eligible = [];
        foreach (range(1, 12) as $i) {
            $mate = $this->makeStudent($this->classA);
            $mate->update(['status' => $i % 2 ? 'studying' : 'waiting_start']);
            $this->tuition($mate);
            $eligible[] = $mate->id;
        }
        $this->makeStudent($this->classA); // No tuition.
        $this->tuition($this->makeStudent($this->classA), 4800000); // No debt.
        $finished = $this->makeStudent($this->classA);
        $finished->update(['status' => 'completed']);
        $this->tuition($finished);
        $this->tuition($this->makeStudent($this->makeClass('COS-OTHER-CLASS')));
        $other = $this->makeStudent($this->classA);
        $branch = Branch::create(['name' => 'Ngoài phạm vi', 'code' => 'COS-OUT', 'is_active' => true]);
        $other->update(['branch_id' => $branch->id]);
        $this->tuition($other);
        $this->actingAs($this->academic)->get(route('tuition.refunds'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('studentFinance.'.$source->id.'.classmate_suggestions', 10)
                ->where('studentFinance.'.$source->id.'.classmate_suggestions', fn ($rows) => collect($rows)->every(fn ($row) => in_array($row['id'], $eligible, true))));
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(string $code): ClassModel
    {
        return ClassModel::create(['code' => $code, 'name' => 'Lớp '.$code, 'course_id' => $this->course->id,
            'branch_id' => $this->branch->id, 'max_capacity' => 50, 'status' => 'active', 'start_date' => '2026-09-01']);
    }

    private function makeStudent(ClassModel $class, int $opening = 0): Student
    {
        $this->sequence++;
        $student = Student::create(['code' => 'HV-COS-'.$this->sequence, 'name' => 'Học viên '.$this->sequence,
            'phone' => '0900'.str_pad((string) $this->sequence, 6, '0', STR_PAD_LEFT), 'branch_id' => $this->branch->id,
            'current_class_id' => $class->id, 'status' => 'studying', 'opening_sessions' => $opening,
            'opening_sessions_set_at' => $opening > 0 ? now() : null]);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $class->id, 'enrolled_at' => '2026-09-01', 'status' => 'completed']);

        return $student;
    }

    private function sessions(ClassModel $class, array $dates, string $status = 'scheduled', string $start = '08:00', string $type = ClassSession::TYPE_REGULAR): void
    {
        foreach ($dates as $date) {
            ClassSession::create(['class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => $date,
                'start_time' => $start, 'end_time' => '19:30', 'status' => $status, 'type' => $type]);
        }
    }

    private function tuition(Student $student, int $paid = 0): StudentTuition
    {
        $tuition = StudentTuition::create(['student_id' => $student->id, 'class_id' => $student->current_class_id,
            'branch_id' => $student->branch_id, 'session_count' => 24, 'total_amount' => 4800000,
            'final_amount' => 4800000, 'paid_amount' => 0, 'debt_amount' => 4800000, 'status' => 'unpaid']);
        if ($paid > 0) {
            TuitionReceipt::create(['receipt_number' => TuitionReceipt::generateReceiptNumber(), 'student_tuition_id' => $tuition->id,
                'student_id' => $student->id, 'amount' => $paid, 'tuition_amount' => $paid, 'payment_method' => 'cash',
                'payment_date' => now(), 'status' => TuitionReceipt::STATUS_APPROVED, 'creator_id' => $this->academic->id]);
            $tuition->recalculateDebt();
        }

        return $tuition;
    }

    private function csv(array $lines): UploadedFile
    {
        $content = "\xEF\xBB\xBF".implode("\n", array_map(fn ($cells) => implode(',', array_map(fn ($cell) => '"'.str_replace('"', '""', (string) $cell).'"', $cells)), $lines));

        return UploadedFile::fake()->createWithContent('so-buoi.csv', $content);
    }
}
