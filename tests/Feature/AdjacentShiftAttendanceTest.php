<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CoverRequest;
use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\Student;
use App\Models\SupportSession;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\SupportSessionService;
use App\Services\TeachingShifts\AdjacentShiftAttendance;
use App\Services\TeachingShifts\ScheduleConfirmation;
use App\Services\TeachingShifts\TimesheetSoftDelete;
use App\Support\DataScopeGuard;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdjacentShiftAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $admin;

    private ClassModel $classModel;

    private ClassSession $first;

    private ClassSession $second;

    private TeacherTimesheet $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-12 19:00:00'));
        $branch = Branch::create(['code' => 'ADJ', 'name' => 'Adjacent shifts', 'is_active' => true]);
        $this->teacher = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'base_salary' => 0]);
        $this->teacher->assignRole(Roles::TEACHER_PARTTIME);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->classModel = ClassModel::create(['code' => 'ADJ-1', 'name' => 'Adjacent class', 'branch_id' => $branch->id,
            'teacher_id' => $this->teacher->id, 'status' => 'active']);
        $this->first = $this->makeSession('17:00', '18:00');
        $this->second = $this->makeSession('18:00', '19:30');
        $this->trigger = $this->timesheet($this->first, ['source' => TeacherTimesheet::SOURCE_CHECKIN]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function makeSession(string $start, string $end, array $attributes = []): ClassSession
    {
        return ClassSession::create($attributes + ['class_id' => $this->classModel->id, 'branch_id' => $this->classModel->branch_id,
            'teacher_id' => $this->teacher->id, 'date' => '2026-11-12', 'start_time' => $start, 'end_time' => $end,
            'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled']);
    }

    private function timesheet(ClassSession $session, array $attributes = []): TeacherTimesheet
    {
        return TeacherTimesheet::create($attributes + ['user_id' => $this->teacher->id, 'class_id' => $session->class_id,
            'class_session_id' => $session->id, 'teaching_date' => $session->date, 'hours' => 1,
            'scheduled_time' => $session->start_time->format('H:i').'-'.$session->end_time->format('H:i'),
            'type' => 'regular', 'status' => 'valid']);
    }

    private function runJob(): array
    {
        return app(AdjacentShiftAttendance::class)->run(now());
    }

    private function autoRow(): TeacherTimesheet
    {
        return TeacherTimesheet::where('class_session_id', $this->second->id)->sole();
    }

    private function cover(ClassSession $session): void
    {
        CoverRequest::create(['class_session_id' => $session->id, 'requested_by' => $this->teacher->id,
            'original_teacher_id' => $this->teacher->id, 'status' => 'open']);
    }

    private function lockedPeriod(): PayrollPeriod
    {
        return PayrollPeriod::create(['code' => 'ADJ-LOCK', 'title' => 'Locked period', 'month' => 11, 'year' => 2026,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => PayrollPeriod::STATUS_PUBLISHED]);
    }

    public function test_creates_exact_adjacent_fields_and_is_idempotent_without_chaining(): void
    {
        $third = $this->makeSession('19:30', '21:00');
        $this->assertSame(['created' => 1, 'removed' => 0], $this->runJob());
        $row = $this->autoRow();
        $this->assertSame($this->teacher->id, $row->user_id);
        $this->assertSame($this->classModel->id, $row->class_id);
        $this->assertSame('2026-11-12', $row->teaching_date->toDateString());
        $this->assertSame('18:00-19:30', $row->scheduled_time);
        $this->assertSame('18:00', $row->checkin_time);
        $this->assertSame(1.5, (float) $row->hours);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(0, $row->early_leave_minutes);
        $this->assertFalse($row->late_notified);
        $this->assertSame('regular', $row->type);
        $this->assertSame('auto_adjacent', $row->source);
        $this->assertSame('Tự động ca liền kề', $row->source_label);
        $this->assertSame('pending_review', $row->status);
        $this->assertSame('nhap', $row->reconcile_status);
        $this->assertFalse($row->payroll_locked);
        $this->assertFalse($row->bi_xoa_boi_hoc_vu);
        $this->assertNull($row->created_by);
        $this->assertSame('Tự động ca liền kề', $row->notes);
        $this->assertSame(['created' => 0, 'removed' => 0], $this->runJob());
        $this->assertFalse(TeacherTimesheet::where('class_session_id', $third->id)->exists());
    }

    public function test_main_teacher_falls_back_to_class_and_adjacent_session_can_be_in_another_class(): void
    {
        $other = ClassModel::create(['code' => 'ADJ-2', 'name' => 'Other class', 'branch_id' => $this->classModel->branch_id,
            'teacher_id' => $this->teacher->id, 'status' => 'active']);
        $this->first->update(['teacher_id' => null]);
        $this->second->update(['teacher_id' => null, 'class_id' => $other->id]);
        $this->assertSame(1, $this->runJob()['created']);
        $this->assertSame($other->id, $this->autoRow()->class_id);
    }

    public function test_fulltime_inactive_and_deleted_teachers_are_excluded(): void
    {
        $this->teacher->syncRoles([Roles::TEACHER_FULLTIME]);
        $this->assertSame(0, $this->runJob()['created']);
        $this->teacher->syncRoles([Roles::TEACHER_PARTTIME]);
        $this->teacher->update(['is_active' => false]);
        $this->assertSame(0, $this->runJob()['created']);
        $this->teacher->update(['is_active' => true]);
        $this->teacher->delete();
        $this->assertSame(0, $this->runJob()['created']);
    }

    public function test_adjacency_requires_exact_time_same_day_and_same_main_teacher(): void
    {
        $this->second->update(['start_time' => '18:01']);
        $this->assertSame(0, $this->runJob()['created']);
        $this->second->update(['start_time' => '18:00', 'date' => '2026-11-11']);
        $this->assertSame(0, $this->runJob()['created']);
        $this->second->update(['date' => '2026-11-12', 'teacher_id' => $this->admin->id]);
        $this->assertSame(0, $this->runJob()['created']);
    }

    public function test_only_own_live_non_sub_non_invalid_checkin_triggers(): void
    {
        foreach (['manual', 'schedule', 'auto_adjacent'] as $source) {
            $this->trigger->update(['source' => $source]);
            $this->assertSame(0, $this->runJob()['created']);
        }
        $this->trigger->update(['source' => 'checkin', 'status' => 'invalid']);
        $this->assertSame(0, $this->runJob()['created']);
        $this->trigger->update(['status' => 'valid', 'type' => 'sub']);
        $this->assertSame(0, $this->runJob()['created']);
        $this->trigger->update(['type' => 'regular', 'bi_xoa_boi_hoc_vu' => true]);
        $this->assertSame(0, $this->runJob()['created']);
        TeacherTimesheet::withSoftDeleted()->whereKey($this->trigger->id)->delete();
        $this->timesheet($this->first, ['user_id' => $this->admin->id, 'source' => 'checkin']);
        $this->assertSame(0, $this->runJob()['created']);
    }

    public function test_cover_cancelled_moved_and_support_target_sessions_are_excluded(): void
    {
        $this->second->update(['status' => 'cancelled']);
        $this->assertSame(0, $this->runJob()['created']);
        $this->second->update(['status' => 'scheduled', 'type' => ClassSession::TYPE_SUPPORT]);
        $this->assertSame(0, $this->runJob()['created']);
        $this->second->update(['type' => ClassSession::TYPE_REGULAR]);
        $this->cover($this->second);
        $this->assertSame(0, $this->runJob()['created']);
        CoverRequest::query()->update(['status' => 'approved']);
        $this->assertSame(0, $this->runJob()['created']);
        CoverRequest::query()->delete();
        $this->makeSession('18:00', '19:30', ['date' => '2026-11-13', 'rescheduled_from_id' => $this->second->id]);
        $this->assertSame(0, $this->runJob()['created']);
    }

    public function test_cancelled_moved_or_cover_predecessor_does_not_trigger(): void
    {
        $this->first->update(['status' => 'cancelled']);
        $this->assertSame(0, $this->runJob()['created']);
        $this->first->update(['status' => 'scheduled']);
        $this->cover($this->first);
        $this->assertSame(0, $this->runJob()['created']);
        CoverRequest::query()->delete();
        $this->makeSession('17:00', '18:00', ['date' => '2026-11-13', 'rescheduled_from_id' => $this->first->id]);
        $this->assertSame(0, $this->runJob()['created']);
    }

    public function test_window_uses_yesterday_start_plus_24_hours_including_exact_deadline(): void
    {
        $this->first->update(['date' => '2026-11-11', 'start_time' => '22:00', 'end_time' => '23:00']);
        $this->second->update(['date' => '2026-11-11', 'start_time' => '23:00', 'end_time' => '23:59']);
        $this->trigger->update(['teaching_date' => '2026-11-11']);
        $this->travelTo(Carbon::parse('2026-11-12 23:00:01'));
        $this->assertSame(0, $this->runJob()['created']);
        $this->travelTo(Carbon::parse('2026-11-12 23:00:00'));
        $this->assertSame(1, $this->runJob()['created']);
    }

    public function test_existing_rows_including_soft_deleted_invalid_and_unlinked_duplicates_prevent_creation(): void
    {
        $row = $this->timesheet($this->second, ['bi_xoa_boi_hoc_vu' => true]);
        $this->assertSame(0, $this->runJob()['created']);
        TeacherTimesheet::withSoftDeleted()->whereKey($row->id)->update(['bi_xoa_boi_hoc_vu' => false, 'status' => 'invalid']);
        $this->assertSame(0, $this->runJob()['created']);
        TeacherTimesheet::whereKey($row->id)->update(['status' => 'valid', 'class_session_id' => null]);
        $this->assertSame(0, $this->runJob()['created']);
    }

    public function test_locked_period_prevents_creation_and_cleanup(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $period = $this->lockedPeriod();
        $this->second->update(['status' => 'cancelled']);
        $this->assertSame(['created' => 0, 'removed' => 0], $this->runJob());
        $this->assertNotNull($row->fresh());
        $period->delete();
        $row->delete();
        $this->second->update(['status' => 'scheduled']);
        $this->lockedPeriod();
        $this->assertSame(0, $this->runJob()['created']);
    }

    public function test_cleanup_removes_draft_auto_rows_after_cancel_cover_move_teacher_change_or_lost_trigger(): void
    {
        foreach (['cancel', 'cover', 'move', 'teacher', 'invalid', 'deleted'] as $change) {
            $this->runJob();
            $row = $this->autoRow();
            $child = null;
            if ($change === 'cancel') {
                $this->second->update(['status' => 'cancelled']);
            } elseif ($change === 'cover') {
                $this->cover($this->second);
            } elseif ($change === 'move') {
                $child = $this->makeSession('18:00', '19:30', ['date' => '2026-11-13', 'rescheduled_from_id' => $this->second->id]);
            } elseif ($change === 'teacher') {
                $this->second->update(['teacher_id' => $this->admin->id]);
            } elseif ($change === 'invalid') {
                $this->trigger->update(['status' => 'invalid']);
            } else {
                $this->trigger->delete();
            }
            $this->assertSame(1, $this->runJob()['removed'], $change);
            $this->assertNull($row->fresh());
            $this->second->update(['status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
            CoverRequest::query()->delete();
            $child?->delete();
            if ($change === 'deleted') {
                $this->trigger = $this->timesheet($this->first, ['source' => 'checkin']);
            } else {
                $this->trigger->update(['status' => 'valid']);
            }
        }
    }

    public function test_cleanup_preserves_review_closed_soft_deleted_and_row_locked_attendance(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $this->second->update(['status' => 'cancelled']);
        foreach ([['reconcile_status' => 'cho_doi_soat'], ['reconcile_status' => 'da_chot'],
            ['reconcile_status' => 'nhap', 'payroll_locked' => true],
            ['payroll_locked' => false, 'bi_xoa_boi_hoc_vu' => true]] as $attributes) {
            TeacherTimesheet::withSoftDeleted()->whereKey($row->id)->update($attributes);
            $this->assertSame(0, $this->runJob()['removed']);
            $this->assertTrue(TeacherTimesheet::withSoftDeleted()->whereKey($row->id)->exists());
        }
    }

    public function test_checkin_on_auto_row_is_successful_and_preserves_all_fields(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $before = $row->getRawOriginal();
        $this->actingAs($this->teacher)->postJson(route('teacher.checkin'), ['session_ids' => [$this->second->id]])
            ->assertOk()->assertJsonPath('message', 'Ca này đã có công — không cần check-in lại.');
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->post(route('teacher.checkin'), ['session_ids' => [$this->second->id]])
            ->assertRedirect()->assertSessionHas('success', 'Ca này đã có công — không cần check-in lại.');
        $this->assertSame($before, $row->fresh()->getRawOriginal());
    }

    public function test_soft_deleted_checkin_returns_409_after_permission_and_window_checks(): void
    {
        $this->timesheet($this->second, ['bi_xoa_boi_hoc_vu' => true]);
        $this->actingAs($this->teacher)->postJson(route('teacher.checkin'), ['session_ids' => [$this->second->id]])
            ->assertStatus(409)->assertJsonPath('message', 'Học vụ đã xóa công ca này, liên hệ Học vụ');
        $this->post(route('teacher.checkin'), ['session_ids' => [$this->second->id]])
            ->assertSessionHasErrors('class_ids')->assertSessionHas('error', 'Học vụ đã xóa công ca này, liên hệ Học vụ');
        $this->post(route('teacher.checkin'), ['session_ids' => [$this->second->id]], ['X-Inertia' => 'true'])
            ->assertRedirect()->assertSessionHasErrors('class_ids');
        $this->second->update(['teacher_id' => $this->admin->id]);
        $this->postJson(route('teacher.checkin'), ['session_ids' => [$this->second->id]])
            ->assertSessionHasErrors(['class_ids' => 'Không check-in được ca nào — Adjacent class: không phải buổi được phân công cho bạn.']);
        $this->second->update(['teacher_id' => $this->teacher->id]);
        $this->travelTo(Carbon::parse('2026-11-13 18:01:00'));
        $this->postJson(route('teacher.checkin'), ['session_ids' => [$this->second->id]])
            ->assertSessionHasErrors(['class_ids' => 'Không check-in được ca nào — Adjacent class: Quá hạn chấm công (hạn 18:00 13/11/2026) — liên hệ Học vụ để được xác nhận công.']);
        $this->assertSame(1, TeacherTimesheet::withSoftDeleted()->where('class_session_id', $this->second->id)->count());
    }

    public function test_rejected_checkin_can_be_retried_but_locked_row_cannot_be_changed(): void
    {
        $row = $this->timesheet($this->second, ['status' => 'invalid', 'source' => 'manual']);
        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['session_ids' => [$this->second->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame('checkin', $row->fresh()->source);
        $row->refresh()->update(['status' => 'invalid', 'payroll_locked' => true]);
        $this->postJson(route('teacher.checkin'), ['session_ids' => [$this->second->id]])->assertStatus(409);
        $this->assertSame('invalid', $row->fresh()->status);
    }

    public function test_retrying_rejected_reconciled_attendance_requires_review_again(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $this->actingAs($this->admin)->post(route('payroll.timesheets.review', $row->id), ['decision' => 'invalid', 'rejection_reason' => 'Sai công'])
            ->assertSessionHasNoErrors();
        $this->assertSame('da_chot', $row->fresh()->reconcile_status);
        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['session_ids' => [$this->second->id]])->assertSessionHasNoErrors();
        $this->assertSame('pending_review', $row->fresh()->status);
        $this->assertSame('nhap', $row->fresh()->reconcile_status);
    }

    public function test_adjusting_reconciled_attendance_requires_review_again(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        app(ScheduleConfirmation::class)->confirm($this->second, $this->teacher, $this->admin);
        $this->actingAs($this->admin)->put(route('payroll.timesheets.adjust', $row->id), [
            'time_in' => '18:05', 'time_out' => '19:30', 'adjustment_reason' => 'Sửa giờ đối soát',
        ])->assertSessionHasNoErrors();
        $this->assertSame('pending_review', $row->fresh()->status);
        $this->assertSame('nhap', $row->fresh()->reconcile_status);
    }

    public function test_manual_retry_of_rejected_reconciled_attendance_requires_review_again(): void
    {
        // matchSession picks the first assigned session of the day, so reuse the first session's row.
        $row = $this->trigger;
        $row->update(['status' => 'invalid', 'reconcile_status' => 'da_chot']);
        $this->actingAs($this->admin)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id, 'teaching_date' => '2026-11-12',
            'time_in' => '18:00', 'time_out' => '19:30', 'type' => 'regular', 'notes' => 'Bổ sung công',
        ])->assertSessionHasNoErrors();
        $this->assertSame('pending_review', $row->fresh()->status);
        $this->assertSame('nhap', $row->fresh()->reconcile_status);
    }

    public function test_schedule_confirmation_blocks_deleted_rows_and_preserves_auto_source_and_times(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $service = app(ScheduleConfirmation::class);
        $service->confirm($this->second, $this->teacher, $this->admin);
        $this->assertSame('auto_adjacent', $row->fresh()->source);
        $this->assertSame('18:00', $row->fresh()->checkin_time);
        $this->assertSame('da_chot', $row->fresh()->reconcile_status);
        $row->update(['bi_xoa_boi_hoc_vu' => true]);
        $this->lockedPeriod();
        $this->assertSame('Học vụ đã xóa công ca này (dùng Chấm công thủ công)', $service->skipReason($this->second, $this->teacher));
        $this->actingAs($this->admin)->post(route('payroll.timesheets.sessions.confirm', $this->second->id))
            ->assertSessionHasErrors('session');
    }

    public function test_soft_delete_creates_penalty_and_hides_row_from_queries_and_screens(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $penalty = app(TimesheetSoftDelete::class)->softDelete($row, $this->admin, 'Sai công ca');
        $deleted = TeacherTimesheet::withSoftDeleted()->findOrFail($row->id);
        $this->assertTrue($deleted->bi_xoa_boi_hoc_vu);
        $this->assertSame($this->admin->id, $deleted->soft_deleted_by);
        $this->assertTrue($deleted->soft_deleted_at->equalTo(now()));
        $this->assertSame('Sai công ca', $deleted->soft_delete_reason);
        $this->assertSame('Chấm công sai: công ca bị Học vụ xóa', $penalty->violation_type);
        $this->assertSame('operations', $penalty->error_category);
        $this->assertSame($this->teacher->id, $penalty->user_id);
        $this->assertSame($this->classModel->id, $penalty->class_id);
        $this->assertSame($this->admin->id, $penalty->reporter_id);
        $this->assertSame(0.0, (float) $penalty->amount);
        $this->assertSame('pending', $penalty->status);
        $this->assertSame('2026-11-12', $penalty->violation_date->toDateString());
        $this->assertTrue($penalty->violation_at->equalTo(now()));
        $this->assertStringContainsString('Sai công ca', $penalty->notes);
        $this->assertStringContainsString('12/11/2026', $penalty->notes);
        $this->assertStringContainsString('18:00-19:30', $penalty->notes);
        $this->assertNull(TeacherTimesheet::find($row->id));
        $this->assertNull(TeacherTimesheet::findDuplicate($this->teacher->id, $this->classModel->id, '2026-11-12', $this->second->id));
        $this->actingAs($this->admin)->get(route('payroll.timesheets.teachers', ['user_id' => $this->teacher->id, 'month' => '2026-11']))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('timesheets.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($row->id)));
    }

    public function test_soft_delete_refuses_closed_legacy_valid_row_and_locked_period_or_row(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        foreach ([['reconcile_status' => 'da_chot', 'status' => 'valid'], ['reconcile_status' => null, 'status' => 'valid'],
            ['reconcile_status' => 'nhap', 'payroll_locked' => true]] as $attributes) {
            $row->update($attributes);
            try {
                app(TimesheetSoftDelete::class)->softDelete($row, $this->admin, 'Reason');
                $this->fail('Expected a conflict');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
        $row->update(['payroll_locked' => false]);
        $this->lockedPeriod();
        try {
            app(TimesheetSoftDelete::class)->softDelete($row, $this->admin, 'Reason');
            $this->fail('Expected a payroll conflict');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Kỳ lương đã chốt, không xóa mềm được công', $exception->getMessage());
        }
        $this->assertSame(0, Penalty::count());
        $this->assertFalse($row->fresh()->bi_xoa_boi_hoc_vu);
    }

    public function test_soft_delete_route_validates_reason_and_logs_action(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $this->actingAs($this->admin)->post(route('payroll.timesheets.soft-delete', $row->id), [])->assertSessionHasErrors('reason');
        $this->post(route('payroll.timesheets.soft-delete', $row->id), ['reason' => 'Sai công'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_log', ['subject_id' => $row->id, 'subject_type' => TeacherTimesheet::class, 'causer_id' => $this->admin->id]);
        $this->assertSame(1, Penalty::count());
    }

    public function test_review_closes_reconciliation_for_valid_invalid_and_bulk_decisions(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $this->actingAs($this->admin)->post(route('payroll.timesheets.review', $row->id), ['decision' => 'invalid', 'rejection_reason' => 'Sai công'])
            ->assertSessionHasNoErrors();
        $this->assertSame('da_chot', $row->fresh()->reconcile_status);
        $row->update(['reconcile_status' => 'cho_doi_soat']);
        $this->post(route('payroll.timesheets.review', $row->id), ['decision' => 'valid'])->assertSessionHasNoErrors();
        $this->assertSame('da_chot', $row->fresh()->reconcile_status);
        $row->refresh()->update(['status' => 'pending_review', 'reconcile_status' => 'nhap']);
        $this->post(route('payroll.timesheets.bulk-review'), ['ids' => [$row->id]])->assertSessionHasNoErrors();
        $this->assertSame('da_chot', $row->fresh()->reconcile_status);
    }

    public function test_locked_rows_refuse_review_bulk_review_and_adjustment(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $row->update(['payroll_locked' => true]);
        $this->actingAs($this->admin)->postJson(route('payroll.timesheets.review', $row->id), ['decision' => 'valid'])->assertStatus(409);
        $this->postJson(route('payroll.timesheets.bulk-review'), ['ids' => [$row->id]])->assertRedirect();
        $this->putJson(route('payroll.timesheets.adjust', $row->id), ['time_in' => '18:00', 'time_out' => '19:30', 'adjustment_reason' => 'Reason'])->assertStatus(409);
        $this->assertSame('pending_review', $row->fresh()->status);
    }

    public function test_schedule_review_of_rejected_auto_row_preserves_source_and_times(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $row->update(['status' => 'invalid', 'reconcile_status' => 'cho_doi_soat']);
        app(ScheduleConfirmation::class)->confirm($this->second, $this->teacher, $this->admin);
        $this->assertSame('auto_adjacent', $row->fresh()->source);
        $this->assertSame('18:00', $row->fresh()->checkin_time);
        $this->assertSame('valid', $row->fresh()->status);
        $this->assertSame('da_chot', $row->fresh()->reconcile_status);
    }

    public function test_job_bypasses_viewer_scope_without_leaking_the_bypass(): void
    {
        $branch = Branch::create(['code' => 'ADJ-SCOPE', 'name' => 'Scoped branch', 'is_active' => true]);
        $staff = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $staff->assignRole(Roles::ACADEMIC_STAFF);
        $this->actingAs($staff);
        DataScopeGuard::current()->enforce(true);
        try {
            $this->assertSame(1, $this->runJob()['created']);
            $this->assertTrue(DataScopeGuard::current()->active());
        } finally {
            DataScopeGuard::current()->enforce(false);
        }
    }

    public function test_manual_entry_refuses_soft_deleted_session(): void
    {
        $this->timesheet($this->second, ['bi_xoa_boi_hoc_vu' => true]);
        $this->actingAs($this->admin)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id, 'teaching_date' => '2026-11-12',
            'time_in' => '18:00', 'time_out' => '19:30', 'type' => 'regular', 'notes' => 'Bổ sung công',
        ])->assertSessionHasErrors('teaching_date');
        $this->assertSame(1, TeacherTimesheet::withSoftDeleted()->where('class_session_id', $this->second->id)->count());
    }

    public function test_support_completion_does_not_recreate_deleted_attendance(): void
    {
        $student = Student::create(['code' => 'ADJ-ST', 'name' => 'Student', 'phone' => '0901234567', 'branch_id' => $this->classModel->branch_id, 'status' => 'studying']);
        $support = SupportSession::create(['class_id' => $this->classModel->id, 'student_id' => $student->id,
            'teacher_id' => $this->teacher->id, 'class_session_id' => $this->second->id, 'session_date' => '2026-11-12',
            'start_time' => '18:00', 'end_time' => '19:30', 'status' => 'scheduled']);
        $this->timesheet($this->second, ['bi_xoa_boi_hoc_vu' => true]);
        app(SupportSessionService::class)->complete($support, 'Completed', $this->admin, recordAttendance: false);
        $this->assertFalse(TeacherTimesheet::where('class_session_id', $this->second->id)->exists());
        $this->assertSame(1, TeacherTimesheet::withSoftDeleted()->where('class_session_id', $this->second->id)->count());
    }

    public function test_support_completion_resets_explicit_reconciliation_and_preserves_locked_rows(): void
    {
        $student = Student::create(['code' => 'ADJ-SUP', 'name' => 'Student', 'phone' => '0901234567',
            'branch_id' => $this->classModel->branch_id, 'status' => 'studying']);
        $support = SupportSession::create(['class_id' => $this->classModel->id, 'student_id' => $student->id,
            'teacher_id' => $this->teacher->id, 'class_session_id' => $this->second->id, 'session_date' => '2026-11-12',
            'start_time' => '18:00', 'end_time' => '19:30', 'status' => 'scheduled']);
        $row = $this->timesheet($this->second, ['reconcile_status' => 'da_chot']);
        app(SupportSessionService::class)->complete($support, 'Completed', $this->admin, recordAttendance: false);
        $this->assertSame('pending_review', $row->fresh()->status);
        $this->assertSame('nhap', $row->fresh()->reconcile_status);
        $row->update(['payroll_locked' => true, 'status' => 'valid', 'reconcile_status' => 'da_chot']);
        $before = $row->fresh()->getRawOriginal();
        app(SupportSessionService::class)->complete($support, 'Completed again', $this->admin, recordAttendance: false);
        $this->assertSame($before, $row->fresh()->getRawOriginal());
    }

    public function test_soft_delete_route_requires_permission_and_reachable_class(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $payload = ['reason' => 'Sai công'];
        $this->actingAs($this->teacher)->post(route('payroll.timesheets.soft-delete', $row->id), $payload)->assertForbidden();
        $branch = Branch::create(['code' => 'ADJ-OTHER', 'name' => 'Other branch', 'is_active' => true]);
        $staff = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $staff->assignRole(Roles::ACADEMIC_STAFF);
        $this->actingAs($staff)->post(route('payroll.timesheets.soft-delete', $row->id), $payload)->assertForbidden();
        $this->assertFalse($row->fresh()->bi_xoa_boi_hoc_vu);
        $this->assertSame(0, Penalty::count());
    }

    public function test_migration_is_reversible_and_refuses_duplicate_ids_without_deleting_data(): void
    {
        $migration = require database_path('migrations/2026_11_12_100000_add_adjacent_attendance_columns_to_teacher_timesheets.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('teacher_timesheets', 'reconcile_status'));
        Schema::table('teacher_timesheets', fn ($table) => $table->dropUnique('teacher_timesheet_user_session_unique'));
        $attributes = (array) DB::table('teacher_timesheets')->where('id', $this->trigger->id)->first();
        unset($attributes['id']);
        $duplicateId = DB::table('teacher_timesheets')->insertGetId($attributes);
        try {
            $migration->up();
            $this->fail('Expected duplicate pre-check to refuse migration');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString((string) $this->trigger->id, $exception->getMessage());
            $this->assertStringContainsString((string) $duplicateId, $exception->getMessage());
            $this->assertSame(2, DB::table('teacher_timesheets')->count());
        } finally {
            DB::table('teacher_timesheets')->where('id', $duplicateId)->delete();
            Schema::table('teacher_timesheets', fn ($table) => $table->unique(['user_id', 'class_session_id'], 'teacher_timesheet_user_session_unique'));
            $migration->up();
        }
        $this->assertTrue(Schema::hasColumn('teacher_timesheets', 'reconcile_status'));
        $this->assertSame(1, TeacherTimesheet::count());
    }

    public function test_migration_down_refuses_a_live_deleted_pair_without_discarding_rows(): void
    {
        $this->timesheet($this->second, ['bi_xoa_boi_hoc_vu' => true]);
        $this->timesheet($this->second);
        $migration = require database_path('migrations/2026_11_12_100000_add_adjacent_attendance_columns_to_teacher_timesheets.php');
        try {
            $migration->down();
            $this->fail('Expected rollback to preserve the pair');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('duplicate row ids', $exception->getMessage());
        }
        $this->assertSame(3, TeacherTimesheet::withSoftDeleted()->count());
        $this->assertTrue(Schema::hasColumn('teacher_timesheets', 'bi_xoa_boi_hoc_vu'));
    }

    public function test_unique_index_allows_one_deleted_and_one_live_row_but_not_two_live_rows(): void
    {
        $this->timesheet($this->second, ['bi_xoa_boi_hoc_vu' => true]);
        $this->timesheet($this->second);
        $this->assertSame(2, TeacherTimesheet::withSoftDeleted()->where('class_session_id', $this->second->id)->count());
        $this->expectException(UniqueConstraintViolationException::class);
        $this->timesheet($this->second);
    }

    public function test_command_and_five_minute_schedule_are_registered(): void
    {
        $this->artisan('attendance:auto-adjacent-shifts')->expectsOutputToContain('1')->assertSuccessful();
        $this->assertSame('auto_adjacent', $this->autoRow()->source);
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'attendance:auto-adjacent-shifts'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_teacher_home_counts_auto_row_and_shows_adjacent_hint_but_not_for_soft_deleted(): void
    {
        $find = fn (AssertableInertia $page, int $sessionId) => collect($page->toArray()['props']['shifts'])->firstWhere('session_id', $sessionId);
        $this->actingAs($this->teacher)->get(route('teacher.home'))->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($find) {
                $shift = $find($page, $this->second->id);
                $this->assertFalse($shift['checked_in']);
                $this->assertTrue($shift['adjacent_hint']);
                $this->assertFalse($find($page, $this->first->id)['adjacent_hint']);
            });
        $this->runJob();
        $row = $this->autoRow();
        $this->actingAs($this->teacher)->get(route('teacher.home'))
            ->assertInertia(function (AssertableInertia $page) use ($find) {
                $shift = $find($page, $this->second->id);
                $this->assertTrue($shift['checked_in']);
                $this->assertFalse($shift['adjacent_hint']);
            });
        app(TimesheetSoftDelete::class)->softDelete($row, $this->admin, 'Sai công ca');
        $this->actingAs($this->teacher)->get(route('teacher.home'))
            ->assertInertia(fn (AssertableInertia $page) => $this->assertFalse($find($page, $this->second->id)['checked_in']));
    }

    public function test_reconciliation_screen_labels_auto_rows(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $this->actingAs($this->admin)->get(route('payroll.timesheets.teachers', ['user_id' => $this->teacher->id, 'month' => '2026-11', 'day' => '2026-11-12']))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('timesheets.data', fn ($rows) => collect($rows)->firstWhere('id', $row->id)['source_label'] === 'Tự động ca liền kề'
                    && collect($rows)->firstWhere('id', $row->id)['can_close'] === true)
                ->where('scheduleSessions', fn ($rows) => collect($rows)->firstWhere('id', $this->second->id)['state'] === 'auto_adjacent'));
    }

    public function test_close_route_moves_nhap_to_da_chot_and_refuses_closed_or_locked_rows(): void
    {
        $this->runJob();
        $row = $this->autoRow();
        $this->actingAs($this->teacher)->post(route('payroll.timesheets.close', $row->id))->assertForbidden();
        $this->actingAs($this->admin)->post(route('payroll.timesheets.close', $row->id))->assertRedirect();
        $this->assertSame('da_chot', $row->fresh()->reconcile_status);
        $this->assertSame('pending_review', $row->fresh()->status);
        $this->actingAs($this->admin)->post(route('payroll.timesheets.close', $row->id))->assertStatus(409);
        $row->fresh()->update(['reconcile_status' => 'nhap', 'payroll_locked' => true]);
        $this->actingAs($this->admin)->post(route('payroll.timesheets.close', $row->id))->assertStatus(409);
        $this->assertSame('nhap', $row->fresh()->reconcile_status);
    }

    public function test_admin_unlock_keeps_da_chot_enables_soft_delete_and_refuses_payroll_locked_rows(): void
    {
        $row = $this->timesheet($this->second, ['reconcile_status' => 'da_chot', 'status' => 'valid', 'source' => 'manual']);
        $payload = ['reason' => 'Sai công'];
        $this->actingAs($this->admin)->post(route('payroll.timesheets.soft-delete', $row->id), $payload)->assertStatus(409);

        $staff = User::factory()->create(['branch_id' => $this->classModel->branch_id, 'is_active' => true]);
        $staff->assignRole(Roles::ACADEMIC_STAFF);
        $this->actingAs($staff)->post(route('payroll.timesheets.unlock', $row->id))->assertForbidden();
        $this->actingAs($this->admin)->post(route('payroll.timesheets.unlock', $row->id))->assertRedirect();
        $fresh = $row->fresh();
        $this->assertSame('da_chot', $fresh->reconcile_status);
        $this->assertSame('pending_review', $fresh->status);

        $this->actingAs($this->admin)->post(route('payroll.timesheets.soft-delete', $row->id), $payload)->assertRedirect();
        $this->assertTrue(TeacherTimesheet::withSoftDeleted()->find($row->id)->bi_xoa_boi_hoc_vu);

        $locked = $this->timesheet($this->makeSession('20:00', '21:00'), ['reconcile_status' => 'da_chot', 'status' => 'valid', 'payroll_locked' => true]);
        $this->actingAs($this->admin)->post(route('payroll.timesheets.unlock', $locked->id))->assertStatus(409);
        $this->assertSame('valid', $locked->fresh()->status);
    }

    public function test_duplicates_tab_lists_two_main_teachers_only_and_does_not_block_payroll_close(): void
    {
        $other = User::factory()->create(['branch_id' => $this->classModel->branch_id, 'is_active' => true]);
        $other->assignRole(Roles::TEACHER_PARTTIME);
        $this->timesheet($this->first, ['user_id' => $other->id, 'source' => 'manual']);
        // Cross-teaching sub on another session must not be reported.
        $this->timesheet($this->second, ['user_id' => $other->id, 'type' => 'sub']);
        $this->timesheet($this->second, ['source' => 'manual']);

        $this->actingAs($this->admin)->get(route('payroll.timesheets.teachers', ['month' => '2026-11', 'tab' => 'duplicates']))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tab', 'duplicates')
                ->has('duplicates', 1)
                ->where('duplicates.0.session_id', $this->first->id)
                ->has('duplicates.0.rows', 2));

        // Warning only: the payroll-close gate ignores duplicates (rows are closed / legacy NULL here).
        $this->assertSame(0, TeacherTimesheet::notClosed()->count());
    }
}
