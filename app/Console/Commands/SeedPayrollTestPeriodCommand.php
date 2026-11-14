<?php

namespace App\Console\Commands;

use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SeedPayrollTestPeriodCommand extends Command
{
    protected $signature = 'payroll:test-period {--cleanup : Remove tagged QA data} {--emails= : Required comma-separated test staff emails when seeding}';

    protected $description = 'Prepare isolated payroll lifecycle QA periods on staging only';

    /** Golden accounts hold fixed BA expectations; they must never join a mutable QA period. */
    private const GOLDEN_EMAILS = ['ft-a.gd@test.menglish', 'pt-a.gd@test.menglish', 'ht-a.gd@test.menglish'];

    public function handle(): int
    {
        if (! app()->environment('staging')) {
            $this->error('This command is allowed only in staging.');

            return self::FAILURE;
        }

        if ($this->option('cleanup')) {
            DB::transaction(function () {
                $periods = PayrollPeriod::whereNotNull('staff_allowlist')->where('code', 'like', 'PR-2026-TEST-%')->get();
                foreach ($periods as $period) {
                    TeacherTimesheet::where('notes', 'payroll:test-period:'.$period->code)->delete();
                    // Both records and transitions cascade when the period is deleted.
                    $period->delete();
                    $this->info('Removed '.$period->code);
                }
            });

            return self::SUCCESS;
        }

        $emails = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->option('emails'))), fn ($email) => $email !== '')));
        if ($emails === []) {
            $this->error('Provide test staff emails with --emails=a@example.test,b@example.test.');

            return self::FAILURE;
        }
        $golden = array_intersect(array_map('strtolower', $emails), self::GOLDEN_EMAILS);
        if ($golden !== []) {
            $this->error('Golden accounts cannot be used in a QA period: '.implode(', ', $golden));

            return self::FAILURE;
        }
        $users = User::with('roles')->whereIn('email', $emails)->where('is_active', true)->staffAccounts()->get();
        $invalid = array_diff($emails, $users->pluck('email')->all());
        if ($invalid !== []) {
            $this->error('Email not found or not an active staff account: '.implode(', ', $invalid));

            return self::FAILURE;
        }
        $allowlist = $users->pluck('id')->all();

        try {
            DB::transaction(function () use ($users, $allowlist) {
                // Check both ranges before creating or updating either period.
                foreach (['A' => 1, 'B' => 2] as $suffix => $month) {
                    $start = Carbon::create(2026, $month, 1)->startOfDay();
                    $end = $start->copy()->endOfMonth();
                    $range = [$start->toDateString(), $end->toDateString()];
                    $code = 'PR-2026-TEST-'.$suffix;
                    if (TeacherTimesheet::whereBetween('teaching_date', $range)->whereNotIn('user_id', $allowlist)->exists()
                        || Penalty::withoutViewerScope()->whereBetween('violation_date', $range)->whereNotIn('user_id', $allowlist)->exists()
                        || StaffAttendance::whereBetween('work_date', $range)->whereNotIn('user_id', $allowlist)->exists()
                        || PayrollPeriod::whereNull('staff_allowlist')
                            ->where(fn ($query) => $query->where('code', $code)
                                ->orWhere(fn ($overlap) => $overlap->whereDate('start_date', '<=', $range[1])->whereDate('end_date', '>=', $range[0])))
                            ->exists()) {
                        throw ValidationException::withMessages(['period' => 'Real data exists in '.$code.' range or its code is already used by a normal period. Refusing to seed.']);
                    }
                }

                foreach (['A' => 1, 'B' => 2] as $suffix => $month) {
                    $code = 'PR-2026-TEST-'.$suffix;
                    $existing = PayrollPeriod::where('code', $code)->lockForUpdate()->first();
                    if ($existing && $existing->status !== PayrollPeriod::STATUS_DRAFT) {
                        $this->info($code.' is past draft; skip.');

                        continue;
                    }
                    $start = Carbon::create(2026, $month, 1)->startOfDay();
                    $period = PayrollPeriod::updateOrCreate(['code' => $code], [
                        'title' => 'QA payroll '.$suffix, 'month' => $month, 'year' => 2026,
                        'start_date' => $start, 'end_date' => $start->copy()->endOfMonth(),
                        'status' => PayrollPeriod::STATUS_DRAFT, 'staff_allowlist' => $allowlist,
                    ]);
                    if ($suffix === 'A') {
                        foreach ($users as $user) {
                            if (! $user->hasAnyRole(Roles::TEACHERS)) {
                                continue;
                            }
                            // A workshop needs no class and has complete punches to avoid missing-punch fines.
                            TeacherTimesheet::firstOrCreate([
                                'user_id' => $user->id, 'notes' => 'payroll:test-period:'.$code,
                            ], [
                                'teaching_date' => '2026-01-15', 'scheduled_time' => '18:00-20:00',
                                'checkin_time' => '18:00', 'checkout_time' => '20:00',
                                'hours' => 2, 'hourly_rate' => 300000, 'type' => 'workshop',
                                'source' => TeacherTimesheet::SOURCE_MANUAL, 'status' => 'valid',
                            ]);
                        }
                        $period->calculatePayrollForPeriod();
                        foreach ($period->records()->get() as $record) {
                            if ($record->netShortfall() > 0) {
                                throw ValidationException::withMessages(['period' => $code.' has negative net pay for '.$record->user_id.'. Use clean test accounts.']);
                            }
                            if ($record->kpi_state[0] !== 'pending') {
                                continue;
                            }
                            match ($record->kpi_source) {
                                PayrollRecord::KPI_RETENTION => $record->forceFill(['kpi_finalized_at' => now()])->saveQuietly(),
                                PayrollRecord::KPI_ACADEMIC => $record->forceFill(['kpi_score' => 0])->saveQuietly(),
                                default => $record->forceFill(['kpi_manual_amount' => 0])->saveQuietly(),
                            };
                        }
                    }
                    $this->info($code.' ready (draft).');
                }
            });
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first());

            return self::FAILURE;
        }

        $this->info('Next: open PR-2026-TEST-A, close -> publish -> mark paid (TC-VD-04..07).');
        $this->info('Use PR-2026-TEST-B for next-period late timesheet/penalty checks (TC-PHAT-05); calculate and finalize KPI before closing B.');

        return self::SUCCESS;
    }
}
