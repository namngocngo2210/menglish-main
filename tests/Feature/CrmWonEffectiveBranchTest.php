<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Support\DataScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CrmWonEffectiveBranchTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchX;

    private Branch $branchY;

    private Course $course;

    private ClassModel $movingClass;

    private User $editor;

    private User $owner;

    private User $otherOwner;

    /** @var list<CrmCustomer> */
    private array $moved;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-27 10:00:00'));

        $this->branchX = Branch::create(['name' => 'Origin X', 'code' => 'WON-X', 'is_active' => true]);
        $this->branchY = Branch::create(['name' => 'Destination Y', 'code' => 'WON-Y', 'is_active' => true]);
        $this->course = Course::create(['name' => 'Won branch course', 'code' => 'WON', 'is_active' => true]);
        $this->movingClass = $this->makeClass($this->branchX, 'MOVING');
        $this->editor = $this->viewer($this->branchX, 'all');
        $this->editor->givePermissionTo(['class.view', 'class.edit', 'class.update']);
        $this->owner = $this->viewer($this->branchX, 'own');
        $this->otherOwner = $this->viewer($this->branchY, 'own');
        $this->moved = [
            $this->makeWon($this->movingClass),
            $this->makeWon($this->movingClass, ['assigned_user_id' => $this->otherOwner->id]),
        ];
    }

    public function test_scope_all_follows_the_class_move_for_both_records_and_labels(): void
    {
        $before = $this->won($this->editor, ['branch_id' => $this->branchX->id]);
        $this->assertIds($before, $this->moved);
        $this->assertSame([$this->branchX->name], array_unique(array_column($before->inertiaProps('wonCustomers.data'), 'branch')));

        $this->moveClass();

        $unfiltered = $this->won($this->editor);
        $this->assertIds($unfiltered, $this->moved);
        $this->assertSame([$this->branchY->name], array_unique(array_column($unfiltered->inertiaProps('wonCustomers.data'), 'branch')));
        $destination = $this->won($this->editor, ['branch_id' => $this->branchY->id]);
        $this->assertIds($destination, $this->moved);
        foreach ($destination->inertiaProps('wonCustomers.data') as $row) {
            $this->assertSame($this->branchY->name, $row['branch']);
            $this->assertSame($this->movingClass->name, $row['class']['name']);
        }
        $this->assertIds($this->won($this->editor, ['branch_id' => $this->branchX->id]), []);
    }

    public function test_branch_visibility_is_strict_effective_branch_even_for_an_assigned_record(): void
    {
        $viewerX = $this->viewer($this->branchX, 'branch');
        $viewerY = $this->viewer($this->branchY, 'branch');
        $this->moved[0]->update(['assigned_user_id' => $viewerX->id]);
        $this->assertIds($this->won($viewerX), $this->moved);
        $this->assertIds($this->won($viewerY), []);

        $this->moveClass();
        $stayingX = $this->makeWon($this->makeClass($this->branchX, 'STAY-X'), [
            'branch_id' => $this->branchY->id, 'assigned_user_id' => $viewerY->id,
        ]);

        $this->assertIds($this->won($viewerY), $this->moved);
        $this->assertIds($this->won($viewerX), [$stayingX]);
        $this->assertIds($this->won($viewerY, ['branch_id' => $this->branchY->id]), $this->moved);
        $this->assertIds($this->won($viewerX, ['branch_id' => $this->branchX->id]), [$stayingX]);
        foreach (['csv', 'xlsx'] as $format) {
            $this->assertEqualsCanonicalizing(array_column($this->moved, 'code'), array_column($this->exportRows($viewerY, $format), 0));
            $this->assertSame([$stayingX->code], array_column($this->exportRows($viewerX, $format), 0));
        }
    }

    public function test_additional_authorized_branches_can_filter_by_effective_branch(): void
    {
        $viewer = $this->viewer($this->branchX, 'branch');
        $viewer->branches()->attach($this->branchY->id);
        $stayingX = $this->makeWon($this->makeClass($this->branchX, 'STAY-X'));
        $this->moveClass();

        $this->assertIds($this->won($viewer), [...$this->moved, $stayingX]);
        $this->assertIds($this->won($viewer, ['branch_id' => $this->branchY->id]), $this->moved);
        $this->assertIds($this->won($viewer, ['branch_id' => $this->branchX->id]), [$stayingX]);
    }

    public function test_forbidden_branch_query_is_ignored_without_leaking_rows_totals_or_class_options(): void
    {
        $viewerX = $this->viewer($this->branchX, 'branch');
        $viewerY = $this->viewer($this->branchY, 'branch');
        $classX = $this->makeClass($this->branchX, 'PRIVATE-X');
        $stayingX = $this->makeWon($classX);
        $this->moveClass();

        // Existing CRM semantics ignore an unauthorized branch filter; the base scope must still hold.
        $forX = $this->won($viewerX, ['branch_id' => $this->branchY->id]);
        $this->assertIds($forX, [$stayingX]);
        $this->assertTotals($forX, 1, 1000000, 250000, 750000);
        $this->assertSame([$classX->id], array_column($forX->inertiaProps('filterClasses'), 'value'));
        $forY = $this->won($viewerY, ['branch_id' => $this->branchX->id]);
        $this->assertIds($forY, $this->moved);
        $this->assertTotals($forY, 2, 2000000, 500000, 1500000);
        $this->assertSame([$this->movingClass->id], array_column($forY->inertiaProps('filterClasses'), 'value'));
    }

    public function test_branch_filter_choices_follow_visible_won_records_after_a_class_move(): void
    {
        $viewerX = $this->viewer($this->branchX, 'branch');
        $viewerY = $this->viewer($this->branchY, 'branch');
        $candidate = $this->viewer($this->branchY, 'own');
        $candidate->givePermissionTo('lead.be_assigned');
        // Legacy owners are not candidates, so their options must come from visible records.
        $this->assertFalse($this->owner->can('lead.be_assigned'));
        $this->assertFalse($this->otherOwner->can('lead.be_assigned'));
        foreach ($this->moved as $lead) {
            $lead->update(['source' => 'Moved class referral', 'assigned_user_id' => $this->owner->id]);
        }
        $this->makeWon($this->makeClass($this->branchX, 'CHOICES-X'), [
            'source' => 'Origin only event', 'assigned_user_id' => $this->otherOwner->id,
        ]);
        $this->moveClass();

        $destination = $this->won($viewerY);
        $this->assertSame(['Moved class referral'], $destination->inertiaProps('filterSources'));
        $destinationOwners = array_column($destination->inertiaProps('filterSales'), 'value');
        $this->assertContains($this->owner->id, $destinationOwners);
        $this->assertNotContains($this->otherOwner->id, $destinationOwners);
        // Eligible branch candidates remain available even without a visible won record.
        $this->assertContains($candidate->id, $destinationOwners);

        $origin = $this->won($viewerX);
        $this->assertSame(['Origin only event'], $origin->inertiaProps('filterSources'));
        $originOwners = array_column($origin->inertiaProps('filterSales'), 'value');
        $this->assertContains($this->otherOwner->id, $originOwners);
        $this->assertNotContains($this->owner->id, $originOwners);
    }

    public function test_won_list_and_export_query_counts_do_not_grow_with_rows_in_the_same_class(): void
    {
        $viewer = $this->viewer($this->branchY, 'branch');
        $this->moveClass();
        $read = function (string $format) use ($viewer): void {
            if ($format === 'list') {
                $this->assertIds($this->won($viewer), $this->moved);
            } else {
                $this->assertCount(count($this->moved), $this->exportRows($viewer, $format));
            }
        };
        $countQueries = function (string $format) use ($read): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $read($format);

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        };
        $few = [];
        foreach (['list', 'csv', 'xlsx'] as $format) {
            $read($format); // Warm permissions, SSR and each export path before measuring.
            $few[$format] = $countQueries($format);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->moved[] = $this->makeWon($this->movingClass);
        }
        foreach ($few as $format => $queries) {
            $this->assertGreaterThan(0, $queries);
            $this->assertLessThanOrEqual($queries, $countQueries($format), $format.' queries must not grow per won row.');
        }
    }

    public function test_own_scope_remains_assignment_based_across_branches_and_filters_cannot_expand_it(): void
    {
        $this->moveClass();
        $response = $this->won($this->owner, ['branch_id' => $this->branchX->id, 'class_id' => $this->movingClass->id]);
        $this->assertIds($response, [$this->moved[0]]);
        $this->assertSame($this->branchY->name, $response->inertiaProps('wonCustomers.data.0.branch'));
        $this->assertTotals($response, 1, 1000000, 250000, 750000);
        $this->assertIds($this->won($this->owner, ['assigned_user_id' => $this->otherOwner->id]), []);
    }

    public static function leadScopes(): array
    {
        return ['all' => ['all'], 'branch' => ['branch'], 'own' => ['own']];
    }

    #[DataProvider('leadScopes')]
    public function test_lead_only_viewer_with_own_student_and_class_scopes_still_uses_the_extant_class_branch(string $leadScope): void
    {
        $viewer = $this->viewer($this->branchY, $leadScope, false);
        $this->moved[0]->update(['assigned_user_id' => $viewer->id]);
        $this->moveClass();

        $this->assertFalse($viewer->can('student.view'));
        $this->assertFalse($viewer->can('class.view'));
        $this->assertSame(DataScope::OWN, DataScope::level($viewer, 'student'));
        $this->assertSame(DataScope::OWN, DataScope::level($viewer, 'class'));
        $this->assertFalse(Student::query()->visibleTo($viewer)->whereKey($this->moved[0]->converted_student_id)->exists());
        $this->assertFalse(ClassModel::query()->visibleTo($viewer)->whereKey($this->movingClass->id)->exists());

        $response = $this->won($viewer, ['branch_id' => $this->branchY->id, 'class_id' => $this->movingClass->id]);
        $this->assertIds($response, $leadScope === 'own' ? [$this->moved[0]] : $this->moved);
        foreach ($response->inertiaProps('wonCustomers.data') as $row) {
            $this->assertSame($this->branchY->name, $row['branch']);
            $this->assertSame($this->movingClass->name, $row['class']['name']);
        }
        $this->assertSame([$this->movingClass->id], array_column($response->inertiaProps('filterClasses'), 'value'));
    }

    public static function fallbackRelations(): array
    {
        return [
            'no converted student' => ['no_student'],
            'no current class' => ['no_class'],
            'soft deleted student' => ['deleted_student'],
            'soft deleted class' => ['deleted_class'],
            'null class branch' => ['null_class_branch'],
            'soft deleted class branch' => ['deleted_class_branch'],
        ];
    }

    #[DataProvider('fallbackRelations')]
    public function test_missing_effective_branch_falls_back_to_crm_branch_for_visibility_filtering_labels_and_exports(string $missing): void
    {
        $this->moveClass();
        $class = $this->makeClass($this->branchY, 'FALLBACK');
        $fallback = $this->makeWon($class);
        $student = $fallback->convertedStudent;
        // The student's historical branch must never be used as the fallback.
        $student->update(['branch_id' => $this->branchY->id]);
        switch ($missing) {
            case 'no_student':
                $fallback->update(['converted_student_id' => null]);
                break;
            case 'no_class':
                $student->update(['current_class_id' => null]);
                break;
            case 'deleted_student':
                $student->delete();
                break;
            case 'deleted_class':
                $class->delete();
                break;
            case 'null_class_branch':
                $class->update(['branch_id' => null]);
                break;
            case 'deleted_class_branch':
                $deletedBranch = Branch::create(['name' => 'Deleted branch', 'code' => 'WON-DEL', 'is_active' => true]);
                $class->update(['branch_id' => $deletedBranch->id]);
                $deletedBranch->delete();
                break;
        }

        $response = $this->won($this->editor, ['branch_id' => $this->branchX->id]);
        $this->assertIds($response, [$fallback]);
        $this->assertSame($this->branchX->name, $response->inertiaProps('wonCustomers.data.0.branch'));
        $this->assertIds($this->won($this->editor, ['branch_id' => $this->branchY->id]), $this->moved);
        $this->assertIds($this->won($this->viewer($this->branchX, 'branch')), [$fallback]);
        $this->assertIds($this->won($this->viewer($this->branchY, 'branch')), $this->moved);
        foreach (['csv', 'xlsx'] as $format) {
            $rows = $this->exportRows($this->editor, $format, ['branch_id' => $this->branchX->id]);
            $this->assertCount(1, $rows);
            $this->assertSame($fallback->code, $rows[0][0]);
            $this->assertSame($this->branchX->name, $rows[0][4]);
        }
    }

    public function test_null_crm_branch_does_not_hide_a_valid_class_branch_and_null_fallback_is_all_only(): void
    {
        $this->moved[0]->update(['branch_id' => null]);
        $unbranched = CrmCustomer::create([
            'code' => 'WON-UNBRANCHED', 'name' => 'Unbranched won', 'phone' => '0901819999',
            'stage' => 'won', 'branch_id' => null, 'assigned_user_id' => $this->owner->id,
            'converted_at' => now(),
        ]);
        $this->moveClass();

        $all = $this->won($this->editor);
        $this->assertIds($all, [...$this->moved, $unbranched]);
        $rows = collect($all->inertiaProps('wonCustomers.data'))->keyBy('id');
        $this->assertNull($rows[$unbranched->id]['branch']);
        $this->assertSame($this->branchY->name, $rows[$this->moved[0]->id]['branch']);
        $this->assertIds($this->won($this->viewer($this->branchY, 'branch')), $this->moved);
        $this->assertIds($this->won($this->viewer($this->branchX, 'branch')), []);
        $this->assertIds($this->won($this->owner), [$this->moved[0], $unbranched]);
    }

    public function test_branch_filtered_totals_pagination_and_class_options_use_the_same_visible_records(): void
    {
        // Eleven is the smallest fixture that spans two supported ten-row pages.
        for ($i = 0; $i < 9; $i++) {
            $this->moved[] = $this->makeWon($this->movingClass);
        }
        $this->makeWon($this->makeClass($this->branchX, 'EXCLUDED'));
        $this->moveClass();

        $first = $this->won($this->editor, ['branch_id' => $this->branchY->id, 'per_page' => 10]);
        $second = $this->won($this->editor, ['branch_id' => $this->branchY->id, 'per_page' => 10, 'page' => 2]);
        $this->assertCount(10, $first->inertiaProps('wonCustomers.data'));
        $this->assertCount(1, $second->inertiaProps('wonCustomers.data'));
        $this->assertSame(2, $second->inertiaProps('wonCustomers.current_page'));
        $this->assertSame(2, $first->inertiaProps('wonCustomers.last_page'));
        $ids = array_merge(array_column($first->inertiaProps('wonCustomers.data'), 'id'), array_column($second->inertiaProps('wonCustomers.data'), 'id'));
        $this->assertEqualsCanonicalizing(array_column($this->moved, 'id'), $ids);
        foreach ([$first, $second] as $response) {
            $this->assertSame(11, $response->inertiaProps('wonCustomers.total'));
            $this->assertTotals($response, 11, 11000000, 2750000, 8250000);
            $this->assertSame([['value' => $this->movingClass->id, 'label' => $this->movingClass->name]], $response->inertiaProps('filterClasses'));
            foreach ($response->inertiaProps('wonCustomers.data') as $row) {
                $this->assertSame($this->branchY->name, $row['branch']);
            }
        }
    }

    public static function retainedFilters(): array
    {
        return [
            'search' => ['search'], 'from date' => ['from'], 'to date' => ['to'],
            'assignee' => ['assignee'], 'class' => ['class'], 'source' => ['source'], 'combined' => ['combined'],
        ];
    }

    #[DataProvider('retainedFilters')]
    public function test_existing_filters_remain_intersections_with_effective_branch(string $filter): void
    {
        $target = $this->moved[0];
        $target->update(['name' => 'Needle target', 'source' => 'Referral', 'converted_at' => '2026-11-20 12:00:00']);
        $this->moved[1]->update(['source' => 'Website', 'converted_at' => '2026-11-18 12:00:00']);
        $later = $this->makeWon($this->makeClass($this->branchY, 'OTHER-Y'), [
            'assigned_user_id' => $this->otherOwner->id, 'source' => 'Website', 'converted_at' => '2026-11-22 12:00:00',
        ]);
        // A matching lead in X must not survive the effective Y filter.
        $this->makeWon($this->makeClass($this->branchX, 'OTHER-X'), [
            'name' => 'Needle origin', 'source' => 'Referral', 'converted_at' => '2026-11-20 12:00:00',
        ]);
        $this->moveClass();

        $filters = match ($filter) {
            'search' => ['search' => 'Needle'],
            'from' => ['from' => '2026-11-20'],
            'to' => ['to' => '2026-11-20'],
            'assignee' => ['assigned_user_id' => $this->owner->id],
            'class' => ['class_id' => $this->movingClass->id],
            'source' => ['source' => 'Referral'],
            'combined' => ['search' => 'Needle', 'from' => '2026-11-20', 'to' => '2026-11-20', 'source' => 'Referral', 'assigned_user_id' => $this->owner->id, 'class_id' => $this->movingClass->id],
        };
        $response = $this->won($this->editor, ['branch_id' => $this->branchY->id] + $filters);
        $expected = match ($filter) {
            'class', 'to' => $this->moved,
            'from' => [$target, $later],
            default => [$target],
        };
        $this->assertIds($response, $expected);
        $count = count($expected);
        $this->assertTotals($response, $count, $count * 1000000, $count * 250000, $count * 750000);
        foreach (['csv', 'xlsx'] as $format) {
            $rows = $this->exportRows($this->editor, $format, ['branch_id' => $this->branchY->id] + $filters);
            $this->assertEqualsCanonicalizing(array_column($expected, 'code'), array_column($rows, 0));
            foreach ($rows as $row) {
                $this->assertSame($this->branchY->name, $row[4]);
            }
        }
    }

    public static function exportFormats(): array
    {
        return ['csv' => ['csv'], 'xlsx' => ['xlsx']];
    }

    #[DataProvider('exportFormats')]
    public function test_unfiltered_exports_label_the_effective_branch(string $format): void
    {
        $this->moveClass();
        $rows = $this->exportRows($this->editor, $format);
        $this->assertEqualsCanonicalizing(array_column($this->moved, 'code'), array_column($rows, 0));
        foreach ($rows as $row) {
            $this->assertSame($this->branchY->name, $row[4]);
            $this->assertSame($this->movingClass->name, $row[7]);
            $this->assertEquals(1000000, $row[8]);
            $this->assertEquals(250000, $row[9]);
            $this->assertEquals(750000, $row[10]);
        }
    }

    #[DataProvider('exportFormats')]
    public function test_exports_include_all_visible_pages_and_never_expand_branch_or_own_scope(string $format): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->moved[] = $this->makeWon($this->movingClass);
        }
        $stayingX = $this->makeWon($this->makeClass($this->branchX, 'EXCLUDED'));
        $this->moveClass();

        $rows = $this->exportRows($this->editor, $format, ['branch_id' => $this->branchY->id, 'per_page' => 10, 'page' => 2]);
        $this->assertCount(11, $rows);
        $this->assertEqualsCanonicalizing(array_column($this->moved, 'code'), array_column($rows, 0));
        $branchRows = $this->exportRows($this->viewer($this->branchY, 'branch'), $format, ['branch_id' => $this->branchX->id]);
        $this->assertEqualsCanonicalizing(array_column($this->moved, 'code'), array_column($branchRows, 0));
        $originRows = $this->exportRows($this->viewer($this->branchX, 'branch'), $format, ['branch_id' => $this->branchY->id]);
        $this->assertSame([$stayingX->code], array_column($originRows, 0));
        $ownRows = $this->exportRows($this->otherOwner, $format, ['branch_id' => $this->branchX->id]);
        $this->assertSame([$this->moved[1]->code], array_column($ownRows, 0));
        $this->assertSame($this->branchY->name, $ownRows[0][4]);
    }

    public function test_class_move_and_won_reads_preserve_historical_business_data(): void
    {
        foreach ($this->moved as $lead) {
            ClassEnrollment::create([
                'student_id' => $lead->converted_student_id, 'class_id' => $this->movingClass->id, 'customer_id' => $lead->id,
                'enrolled_at' => '2026-11-01', 'confirmed_at' => '2026-11-02 12:00:00', 'confirmed_by' => $this->editor->id,
                'account_sent' => true, 'curriculum_delivered' => true, 'status' => 'completed',
            ]);
            CrmCustomerHistory::create([
                'customer_id' => $lead->id, 'user_id' => $this->owner->id, 'type' => 'stage_change',
                'from_stage' => 'waiting_class', 'to_stage' => 'won', 'content' => 'Original closing history',
                'changes' => ['branch_id' => ['old' => null, 'new' => $this->branchX->id]],
            ]);
            TuitionReceipt::create([
                'receipt_number' => 'WON-RECEIPT-'.$lead->id, 'student_tuition_id' => $lead->convertedStudent->tuition->id,
                'student_id' => $lead->converted_student_id, 'amount' => 250000, 'payment_method' => 'cash',
                'payment_date' => '2026-11-20', 'creator_id' => $this->owner->id, 'approver_id' => $this->editor->id,
                'status' => 'approved', 'approved_at' => '2026-11-20 12:00:00',
            ]);
        }
        $tables = ['crm_customers', 'students', 'student_tuitions', 'tuition_receipts', 'class_enrollments', 'crm_customer_histories'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        $this->moveClass();
        $this->won($this->editor, ['branch_id' => $this->branchY->id]);
        foreach (['csv', 'xlsx'] as $format) {
            $this->exportRows($this->editor, $format, ['branch_id' => $this->branchY->id]);
        }

        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->orderBy('id')->get()->toArray(), $table.' must remain unchanged');
        }
        $this->assertSame($this->branchY->id, $this->movingClass->fresh()->branch_id);
    }

    public function test_won_visibility_does_not_broaden_generic_lead_detail_or_write_authorization(): void
    {
        $viewerY = $this->viewer($this->branchY, 'branch');
        $viewerY->givePermissionTo('lead.update');
        $viewerX = $this->viewer($this->branchX, 'branch');
        $this->moveClass();

        $this->assertFalse(CrmCustomer::query()->visibleTo($viewerY)->whereKey($this->moved[0]->id)->exists());
        $this->assertTrue(CrmCustomer::query()->visibleTo($viewerX)->whereKey($this->moved[0]->id)->exists());
        $destination = $this->actingAs($viewerY)->get(route('crm.customers.index'))->assertOk();
        $this->assertSame(0, $destination->inertiaProps('customers.total'));
        $this->actingAs($viewerY)->get(route('crm.customers.show', $this->moved[0]->id))->assertNotFound();
        $this->actingAs($viewerY)->put(route('crm.customers.update', $this->moved[0]->id), ['notes' => 'Must not be written'])->assertNotFound();
        $origin = $this->actingAs($viewerX)->get(route('crm.customers.index'))->assertOk();
        $this->assertEqualsCanonicalizing(array_column($this->moved, 'id'), array_column($origin->inertiaProps('customers.data'), 'id'));
        $this->assertIds($this->won($viewerY), $this->moved);
    }

    private function viewer(Branch $branch, string $leadScope, bool $allRelations = true): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        // Direct grants avoid role defaults obscuring the scope being exercised.
        $user->givePermissionTo(['lead.view', 'lead.scope_'.$leadScope]);
        if ($allRelations) {
            $user->givePermissionTo(['student.scope_all', 'class.scope_all']);
        }

        return $user;
    }

    private function makeClass(Branch $branch, string $code): ClassModel
    {
        return ClassModel::create([
            'name' => 'Class '.$code, 'code' => 'WON-'.$code, 'branch_id' => $branch->id,
            'course_id' => $this->course->id, 'program' => $this->course->name,
            'level' => 'B1', 'max_capacity' => 20, 'min_students' => 3, 'status' => 'active',
        ]);
    }

    private function makeWon(ClassModel $class, array $attributes = []): CrmCustomer
    {
        $number = ++$this->sequence;
        $student = Student::create([
            'code' => 'WON-STUDENT-'.$number, 'name' => 'Student '.$number, 'phone' => sprintf('090181%04d', $number),
            'branch_id' => $this->branchX->id, 'current_class_id' => $class->id, 'status' => 'studying',
        ]);
        StudentTuition::create([
            'student_id' => $student->id, 'class_id' => $class->id, 'branch_id' => $this->branchX->id,
            'total_amount' => 1000000, 'final_amount' => 1000000, 'paid_amount' => 250000, 'debt_amount' => 750000,
            'session_count' => 24, 'status' => 'partial',
        ]);

        return CrmCustomer::create($attributes + [
            'code' => 'WON-CUSTOMER-'.$number, 'name' => 'Won customer '.$number, 'phone' => $student->phone,
            'branch_id' => $this->branchX->id, 'assigned_user_id' => $this->owner->id,
            'stage' => 'won', 'converted_student_id' => $student->id, 'converted_by' => $this->editor->id,
            'commission_user_id' => $this->owner->id, 'converted_at' => Carbon::parse('2026-11-20 10:00:00')->addSeconds($number),
            'deal_value' => 1000000, 'source' => 'Referral', 'course_interest' => $this->course->name,
            'fee_paid_at_closing' => true, 'care_checklist' => ['session_1' => ['done_at' => '2026-11-21 10:00:00']],
        ]);
    }

    private function moveClass(): void
    {
        $this->actingAs($this->editor)->put(route('classes.update', $this->movingClass->id), [
            'ten_lop' => $this->movingClass->name, 'ma_lop' => $this->movingClass->code,
            'chi_nhanh' => $this->branchY->id, 'chuong_trinh' => $this->course->name,
            'cap_do' => $this->movingClass->level, 'si_so_toi_da' => 20, 'min_students' => 3,
        ])->assertSessionHasNoErrors()->assertRedirect(route('classes.show', ['id' => $this->movingClass->id]));
        $this->assertSame($this->branchY->id, $this->movingClass->fresh()->branch_id);
    }

    private function won(User $viewer, array $filters = []): TestResponse
    {
        return $this->actingAs($viewer)->get(route('crm.customers.won', $filters))->assertOk();
    }

    /** @param  list<CrmCustomer>  $expected */
    private function assertIds(TestResponse $response, array $expected): void
    {
        $this->assertSame(count($expected), $response->inertiaProps('wonCustomers.total'));
        $this->assertEqualsCanonicalizing(array_column($expected, 'id'), array_column($response->inertiaProps('wonCustomers.data'), 'id'));
    }

    private function assertTotals(TestResponse $response, int $count, int $contract, int $collected, int $debt): void
    {
        $this->assertSame($count, $response->inertiaProps('totalCount'));
        $this->assertEquals($contract, $response->inertiaProps('totalContractAmount'));
        $this->assertEquals($collected, $response->inertiaProps('totalCollectedAmount'));
        $this->assertEquals($debt, $response->inertiaProps('totalDebtAmount'));
    }

    /** @return list<array<int, mixed>> */
    private function exportRows(User $viewer, string $format, array $filters = []): array
    {
        $response = $this->actingAs($viewer)->get(route('crm.customers.won', ['export' => $format] + $filters))->assertOk();
        $this->assertStringContainsString('.'.$format, $response->headers->get('content-disposition'));
        $path = $response->baseResponse->getFile()->getPathname();
        if ($format === 'csv') {
            $this->assertStringStartsWith("\xEF\xBB\xBF", file_get_contents($path));
        }
        $reader = IOFactory::createReader($format === 'csv' ? 'Csv' : 'Xlsx');
        $spreadsheet = $reader->load($path);
        try {
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
            $this->assertSame('Cơ sở', $rows[0][4]);

            return array_slice($rows, 1);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
