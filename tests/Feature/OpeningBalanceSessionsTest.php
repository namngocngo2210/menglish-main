<?php

namespace Tests\Feature;

use App\Http\Controllers\TuitionController;
use App\Jobs\ImportTuitions;
use App\Models\BackgroundTask;
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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OpeningBalanceSessionsTest extends TestCase
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
        $this->branch = Branch::create(['name' => 'Cơ sở đầu kỳ', 'code' => 'OBS', 'is_active' => true]);
        $this->academic = $this->makeUser(Roles::ACADEMIC_STAFF);
        $this->course = Course::create(['code' => 'OBS24', 'name' => 'Khóa 24 buổi', 'tuition_fee' => 4800000, 'total_lessons' => 24, 'is_active' => true]);
        $this->classA = $this->makeClass('OBS-A');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manual_opening_drives_the_quote_and_receipt_with_and_without_summer(): void
    {
        $this->sessions($this->classA, ['2026-10-05', '2026-10-07', '2026-10-09', '2026-10-11']);
        foreach ([[3, false, 21], [1, true, 19]] as [$opening, $summer, $needed]) {
            $student = $this->makeStudent();
            $this->setOpening($student, $opening);
            if ($summer) {
                $this->post(route('students.summer-break', $student->id), ['from' => '2026-10-05', 'to' => '2026-10-11'])
                    ->assertRedirect()->assertSessionHasNoErrors();
            }
            $quote = app(SessionLedger::class)->quote($student->fresh());
            $this->assertSame(24, $quote['course_sessions']);
            $this->assertSame($needed, $quote['needed']);
            $this->assertSame($opening, $quote['carry']);
            $this->assertSame($summer ? 4 : 0, $quote['summer_sessions']);
            $this->assertEquals($needed * 200000, SessionLedger::calculate($quote, $needed, 0, 0, 0, 0, 0)['session_value']);
            $this->submitReceipt($student, $needed);
            $receipt = TuitionReceipt::where('student_id', $student->id)->firstOrFail();
            $this->assertSame($needed, $receipt->session_count);
            $this->assertEquals($needed * 200000, $receipt->sessionBreakdown()['session_value']);
            $this->assertEquals($needed * 200000, $receipt->amount);
        }
    }

    public function test_only_academic_staff_accountant_and_admin_can_edit_by_default(): void
    {
        $student = $this->makeStudent();
        $allowed = [Roles::ACADEMIC_STAFF, Roles::ACCOUNTANT, Roles::ADMIN];
        foreach ($allowed as $role) {
            $this->actingAs($this->makeUser($role))->post(route('students.opening-sessions', $student->id), ['sessions' => 3])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(3, $student->fresh()->opening_sessions);
        }
        foreach (array_diff(Roles::ALL, $allowed) as $role) {
            $this->actingAs($this->makeUser($role))->post(route('students.opening-sessions', $student->id), ['sessions' => 9])
                ->assertForbidden();
        }
        $this->assertSame(3, $student->fresh()->opening_sessions);
    }

    public function test_branch_scope_is_enforced_for_both_editing_roles(): void
    {
        $student = $this->makeStudent();
        $other = Branch::create(['name' => 'Cơ sở khác', 'code' => 'OBS-OTHER', 'is_active' => true]);
        $student->update(['branch_id' => $other->id]);
        foreach ([Roles::ACADEMIC_STAFF, Roles::ACCOUNTANT] as $role) {
            $this->actingAs($this->makeUser($role))->post(route('students.opening-sessions', $student->id), ['sessions' => 3])
                ->assertNotFound();
        }
        $this->assertSame(0, $student->fresh()->opening_sessions);
        $this->assertNull($student->fresh()->opening_sessions_set_at);
    }

    public function test_validation_preserves_existing_opening_and_accepts_both_unsigned_boundaries(): void
    {
        $student = $this->makeStudent();
        $this->setOpening($student, 3);
        $stamp = $student->fresh()->opening_sessions_set_at->toDateTimeString();
        Carbon::setTestNow('2026-10-04 11:00:00');
        foreach ([-1, '1.5', '1e2', '4294967296', null, 'invalid'] as $value) {
            $this->post(route('students.opening-sessions', $student->id), ['sessions' => $value])->assertSessionHasErrors('sessions');
            $this->assertSame(3, $student->fresh()->opening_sessions);
            $this->assertSame($stamp, $student->fresh()->opening_sessions_set_at->toDateTimeString());
        }
        foreach ([4294967295, 0] as $value) {
            $this->setOpening($student, $value);
            $this->assertSame($value, $student->fresh()->opening_sessions);
            $this->assertTrue($student->fresh()->opening_sessions_set_at->equalTo(now()));
        }
    }

    public function test_manual_update_records_causer_time_and_old_and_new_values(): void
    {
        $student = $this->makeStudent();
        $this->setOpening($student, 3);
        $this->assertOpeningAudit($student, $this->academic, 0, 3);
        $this->assertTrue($student->fresh()->opening_sessions_set_at->equalTo(now()));
        Carbon::setTestNow('2026-10-04 11:00:00');
        $accountant = $this->makeUser(Roles::ACCOUNTANT);
        $this->actingAs($accountant)->post(route('students.opening-sessions', $student->id), ['sessions' => 5])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertOpeningAudit($student, $accountant, 3, 5);
        $this->assertTrue($student->fresh()->opening_sessions_set_at->equalTo(now()));
    }

    public function test_background_import_is_audited_and_manual_correction_after_import_is_allowed(): void
    {
        Storage::fake('local');
        $student = $this->makeStudent();
        $rows = $this->openingRows($student);
        $this->assertSame([], $rows[0]['errors']);
        $task = BackgroundTask::create([
            'type' => BackgroundTask::TUITION_IMPORT, 'user_id' => $this->academic->id,
            'status' => BackgroundTask::QUEUED, 'title' => 'Nhập buổi đầu kỳ',
            'payload' => ['rows' => $rows, 'branch_id' => $this->branch->id, 'file_name' => 'opening.csv', 'skipped' => 0],
        ]);
        Auth::forgetUser();
        (new ImportTuitions($task))->handle();
        $this->assertSame([], $task->fresh()->report['failed']);
        $this->assertSame(3, $student->fresh()->opening_sessions);
        $this->assertOpeningAudit($student, $this->academic, 0, 3);
        Carbon::setTestNow('2026-10-04 11:00:00');
        $this->setOpening($student, 5);
        $this->assertSame(5, $student->fresh()->opening_sessions);
        $this->assertOpeningAudit($student, $this->academic, 3, 5);
        $result = app(TuitionImportService::class)->import($rows, $this->branch->id, $this->academic);
        $this->assertSame('Học viên đã có số buổi dư đầu kỳ', $result['failed'][0]['error']);
        $this->assertSame(5, $student->fresh()->opening_sessions);
    }

    public function test_import_refuses_manual_entry_including_zero_and_stale_previews(): void
    {
        $student = $this->makeStudent();
        $rows = $this->openingRows($student);
        $this->setOpening($student, 0);
        $result = app(TuitionImportService::class)->import($rows, $this->branch->id, $this->academic);
        $this->assertSame('Học viên đã có số buổi dư đầu kỳ', $result['failed'][0]['error']);
        $this->assertContains('Học viên đã có số buổi dư đầu kỳ', $this->openingRows($student)[0]['errors']);
        $this->assertSame(0, $student->fresh()->opening_sessions);
    }

    public function test_permission_migration_is_idempotent_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_12_20_090200_add_student_opening_sessions_permission.php');
        $migration->down();
        $this->assertDatabaseMissing('permissions', ['name' => 'student.opening_sessions', 'guard_name' => 'web']);
        $migration->up();
        $migration->up();
        $permission = Permission::findByName('student.opening_sessions', 'web');
        $this->assertEqualsCanonicalizing([Roles::ACADEMIC_STAFF, Roles::ACCOUNTANT], $permission->roles()->pluck('name')->all());
        $migration->down();
        $this->assertDatabaseMissing('permissions', ['name' => 'student.opening_sessions', 'guard_name' => 'web']);
    }

    public function test_forms_are_available_on_profile_and_on_accountants_existing_tuition_modal(): void
    {
        $student = $this->makeStudent();
        $this->tuition($student);
        $this->setOpening($student, 3);
        $this->actingAs($this->academic)->get(route('students.show', $student->id))->assertOk()
            ->assertSee('Buổi dư đầu kỳ')->assertSee('Dư đầu kỳ 3')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('student.opening_sessions', 3)->where('student.opening_sessions_set', true));
        $this->actingAs($this->makeUser(Roles::ACCOUNTANT))->get(route('students.show', $student->id))->assertForbidden();
        $modalUrl = route('tuition.students.payments', $student->id);
        $this->get($modalUrl, ['X-Remote-Modal' => '1'])->assertOk()->assertSee('Buổi dư đầu kỳ')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tuition/StudentPayments')
                ->where('student.opening_sessions', 3)->where('student.opening_sessions_set', true));
        $this->from($modalUrl)->post(route('students.opening-sessions', $student->id), ['sessions' => 5])
            ->assertRedirect($modalUrl)->assertSessionHasNoErrors();
        $this->assertSame(5, $student->fresh()->opening_sessions);
        $manager = $this->makeUser(Roles::MANAGER);
        $this->actingAs($manager)->get($modalUrl)->assertOk()->assertDontSee('opening-sessions-heading');
    }

    public function test_due_fees_and_refund_basis_carry_match_receipt_quotes_including_negative_balances(): void
    {
        $positive = $this->makeStudent();
        $negative = $this->makeStudent();
        $this->setOpening($positive, 5);
        $this->tuition($positive)->update(['due_date' => '2026-09-01']);
        $this->tuition($negative)->update(['due_date' => '2026-09-01']);
        $this->sessions($this->classA, ['2026-10-01']);
        $quotes = app(SessionLedger::class)->quoteMany([$positive->id => [$positive->fresh(), null], $negative->id => [$negative->fresh(), null]]);
        $this->assertSame(4, $quotes[$positive->id]['carry']);
        $this->assertSame(0, $quotes[$negative->id]['carry']);
        $this->actingAs($this->academic)->get(route('tuition.overdue'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('seriousOverdue', fn ($rows) => collect($rows)->count() === 2
                && collect($rows)->every(fn ($row) => collect($row['session_lines'])->firstWhere('label', 'Buổi dư (carry)')['value'] === $quotes[$row['student']['id']]['carry'])));
        $this->get(route('tuition.refunds'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('studentFinance.'.$positive->id.'.carry', 4)->where('studentFinance.'.$negative->id.'.carry', 0));
        $this->get(route('tuition.receipts.create'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('students', fn ($rows) => collect($rows)->count() === 2
                && collect($rows)->every(fn ($row) => $row['quote']['carry'] === $quotes[$row['id']]['carry'])));
    }

    public function test_due_group_carry_queries_are_batched_across_collections_and_paginator(): void
    {
        $tuitions = collect(range(1, 5))->map(function () {
            $student = $this->makeStudent();
            $this->setOpening($student, 3);
            $tuition = $this->tuition($student);
            $tuition->setRelation('student', $student)->load('classModel.course', 'branch');
            $tuition->setAttribute('session_stats', ['remaining' => 24, 'attended' => 0, 'total' => 24]);

            return $tuition;
        });
        $groups = fn ($rows) => [
            'seriousOverdue' => $rows->take(1), 'newOverdue' => $rows->slice(1, 1)->values(),
            'upcoming' => new LengthAwarePaginator($rows->skip(3)->values(), max(0, $rows->count() - 3), 10),
            'paused' => $rows->slice(2, 1)->values(), 'overdueTuitions' => $rows->take(2), 'type' => 'all', 'seriousDays' => 7, 'upcomingDays' => 14,
        ];
        $method = new \ReflectionMethod(TuitionController::class, 'dueGroupProps');
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $method->invoke(app(TuitionController::class), $groups($tuitions->take(1)));
            $singleQueries = count(DB::getQueryLog());
            DB::flushQueryLog();
            $props = $method->invoke(app(TuitionController::class), $groups($tuitions));
            $this->assertSame($singleQueries, count(DB::getQueryLog()));
            foreach ([$props['seriousOverdue']->first(), $props['newOverdue']->first(), $props['paused']->first(), ...$props['upcoming']->items()] as $row) {
                $this->assertSame(3, collect($row['session_lines'])->firstWhere('label', 'Buổi dư (carry)')['value']);
            }
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_opening_sessions_do_not_change_refund_or_transfer_money(): void
    {
        $source = $this->makeStudent();
        $this->tuition($source, 4800000);
        $target = $this->makeStudent();
        $this->tuition($target);
        $amounts = [];
        foreach ([0, 5] as $opening) {
            $this->setOpening($source, $opening);
            $this->get(route('tuition.refunds'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('studentFinance.'.$source->id.'.carry', 24 + $opening)
                ->where('studentFinance.'.$source->id.'.suggested_refund', fn ($value) => (float) $value === 4800000.0)
                ->where('studentFinance.'.$source->id.'.transfer_amount', fn ($value) => (float) $value === 4800000.0));
            foreach (['refund', 'transfer'] as $type) {
                $this->post(route('tuition.refunds.store'), [
                    'student_id' => $source->id, 'type' => $type, 'target_student_id' => $target->id,
                    'refund_amount' => 4800000, 'reason' => 'Học viên chuyển nơi ở', 'no_transfer_reason' => 'Không có người nhận phù hợp',
                ])->assertRedirect()->assertSessionHasNoErrors();
                $request = TuitionRefundRequest::where('student_id', $source->id)->where('type', $type)->latest('id')->firstOrFail();
                $amounts[$opening][$type] = (float) $request->refund_amount;
                $request->update(['status' => 'rejected']);
            }
        }
        $this->assertSame(['refund' => 4800000.0, 'transfer' => 4800000.0], $amounts[0]);
        $this->assertSame($amounts[0], $amounts[5]);
    }

    public function test_renewal_approval_keeps_opening_and_adds_only_paid_sessions(): void
    {
        $student = $this->makeStudent();
        $this->setOpening($student, 3);
        $this->tuition($student, 4800000);
        $this->sessions($this->classA, array_map(fn ($day) => sprintf('2026-09-%02d', $day), range(1, 24)));
        $next = $this->makeClass('OBS-RENEWAL');
        $this->post(route('students.transfer-class', $student->id), ['class_id' => $next->id, 'transferred_on' => '2026-10-04'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $ledger = app(SessionLedger::class);
        $before = $ledger->quote($student->fresh());
        $this->assertSame(['opening' => 3, 'paid' => 24, 'used' => 24, 'balance' => 3], $ledger->forStudent($student));
        $this->assertSame('new', $before['mode']);
        $this->submitReceipt($student, 12);
        $receipt = TuitionReceipt::where('student_id', $student->id)->latest('id')->firstOrFail();
        $this->assertNull($receipt->student_tuition_id);
        $this->assertSame($before, $ledger->quote($student->fresh()));
        $this->actingAs($this->makeUser(Roles::ADMIN))->post(route('tuition.receipts.approve.action', $receipt->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $receipt->refresh();
        $this->assertNotNull($receipt->student_tuition_id);
        $this->assertSame(2, StudentTuition::where('student_id', $student->id)->count());
        $this->assertSame(3, $student->fresh()->opening_sessions);
        $this->assertSame(['opening' => 3, 'paid' => 36, 'used' => 24, 'balance' => 15], $ledger->forStudent($student));
        $after = $ledger->quote($student->fresh());
        $this->assertSame(array_replace($before, ['paid' => 36, 'balance' => 15, 'carry' => 15, 'needed' => 9, 'max_sessions' => 9, 'suggested' => 9]), $after);
        $this->assertEquals(12 * 200000, $receipt->fresh()->amount);
        $this->assertEquals(12 * 200000, $receipt->sessionBreakdown()['session_value']);
        $this->assertEquals(12 * 200000, $receipt->tuition->final_amount);
    }

    public function test_class_transfer_preserves_opening_and_quote_until_legitimate_new_sessions_are_used(): void
    {
        $student = $this->makeStudent();
        $this->setOpening($student, 3);
        $this->tuition($student, 4800000);
        $next = $this->makeClass('OBS-NEXT');
        $ledger = app(SessionLedger::class);
        $before = $ledger->quote($student->fresh());
        $this->post(route('students.transfer-class', $student->id), ['class_id' => $next->id, 'transferred_on' => '2026-10-04'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, $student->fresh()->opening_sessions);
        $this->assertSame(['opening' => 3, 'paid' => 24, 'used' => 0, 'balance' => 27], $ledger->forStudent($student));
        $this->assertSame(array_replace($before, ['class_name' => $next->name]), $ledger->quote($student->fresh()));
        $this->sessions($this->classA, ['2026-10-05']);
        $this->sessions($next, ['2026-10-05']);
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->assertSame(['opening' => 3, 'paid' => 24, 'used' => 1, 'balance' => 26], $ledger->forStudent($student->fresh()));
        $this->assertSame(0, $ledger->quote($student->fresh())['needed']);
        $this->assertSame(3, $student->fresh()->opening_sessions);
        $receipt = TuitionReceipt::where('student_id', $student->id)->firstOrFail();
        $this->assertEquals(24 * 200000, $receipt->amount);
    }

    public function test_new_student_without_manual_entry_uses_history_and_keeps_opening_zero(): void
    {
        $student = $this->makeStudent();
        $this->tuition($student, 2000000);
        $this->sessions($this->classA, ['2026-09-05', '2026-09-07']);
        $this->assertSame(0, $student->fresh()->opening_sessions);
        $this->assertNull($student->fresh()->opening_sessions_set_at);
        $this->assertSame(['opening' => 0, 'paid' => 10, 'used' => 2, 'balance' => 8], app(SessionLedger::class)->forStudent($student));
        $this->assertSame(14, app(SessionLedger::class)->quote($student->fresh())['needed']);
    }

    private function setOpening(Student $student, int $sessions): void
    {
        $this->actingAs($this->academic)->post(route('students.opening-sessions', $student->id), ['sessions' => $sessions])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function assertOpeningAudit(Student $student, User $user, int $old, int $new): void
    {
        $activity = Activity::query()->where('subject_type', $student->getMorphClass())->where('subject_id', $student->id)
            ->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($old, $activity->properties->get('old')['opening_sessions']);
        $this->assertSame($new, $activity->properties->get('attributes')['opening_sessions']);
        $this->assertTrue($activity->created_at->equalTo(now()));
    }

    private function openingRows(Student $student): array
    {
        $file = UploadedFile::fake()->createWithContent('opening.csv', "\xEF\xBB\xBFMã học viên,Số buổi,Đã học\n{$student->code},24,21\n");

        return app(TuitionImportService::class)->parse($file, $this->branch->id)['rows'];
    }

    private function submitReceipt(Student $student, int $sessions): void
    {
        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), [
            'student_id' => $student->id, 'session_count' => $sessions, 'amount' => $sessions * 200000,
            'payment_method' => 'cash', 'is_vat_invoice' => 0, 'paper_invoice_number' => 'OBS-'.$student->id,
            'submit_action' => 'submit',
        ])->assertRedirect()->assertSessionHasNoErrors();
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

    private function makeStudent(): Student
    {
        $this->sequence++;
        $student = Student::create(['code' => 'HV-OBS-'.$this->sequence, 'name' => 'Học viên '.$this->sequence,
            'phone' => '0900'.str_pad((string) $this->sequence, 6, '0', STR_PAD_LEFT), 'branch_id' => $this->branch->id,
            'current_class_id' => $this->classA->id, 'status' => 'studying']);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $this->classA->id, 'enrolled_at' => '2026-09-01', 'status' => 'completed']);

        return $student;
    }

    private function sessions(ClassModel $class, array $dates): void
    {
        foreach ($dates as $date) {
            ClassSession::create(['class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => $date,
                'start_time' => '08:00', 'end_time' => '19:30', 'status' => 'scheduled', 'type' => ClassSession::TYPE_REGULAR]);
        }
    }

    private function tuition(Student $student, int $paid = 0): StudentTuition
    {
        $tuition = StudentTuition::create(['student_id' => $student->id, 'class_id' => $student->current_class_id,
            'branch_id' => $student->branch_id, 'session_count' => 24, 'total_amount' => 4800000,
            'final_amount' => 4800000, 'paid_amount' => 0, 'debt_amount' => 4800000, 'status' => 'unpaid']);
        if ($paid > 0) {
            TuitionReceipt::create(['receipt_number' => TuitionReceipt::generateReceiptNumber(), 'student_tuition_id' => $tuition->id,
                'student_id' => $student->id, 'session_count' => (int) ($paid / 200000), 'session_unit_price' => 200000,
                'amount' => $paid, 'tuition_amount' => $paid, 'payment_method' => 'cash',
                'payment_date' => now(), 'status' => TuitionReceipt::STATUS_APPROVED, 'creator_id' => $this->academic->id]);
            $tuition->recalculateDebt();
        }

        return $tuition;
    }
}
