<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\Penalty;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Sla\Sla;
use App\Support\Money;
use App\Support\Rbac;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SLA của Big Test (ngưỡng / bật tắt / mức phạt ở trang Cấu hình SLA, nhóm "Big Test"):
 *  - big_test.results_late: trả kết quả cho phụ huynh tối đa N ngày kể từ ngày thi (mặc định 7); trễ → mức gợi ý (mặc định 50.000đ) × số ngày trễ.
 *  - big_test.paper_approval: Học thuật duyệt & phân phối đề trước ngày thi ít nhất N ngày (mặc định 3);
 *    big_test.paper_reminder: hệ thống cảnh báo từ N ngày trước (mặc định 7) và nhắc mỗi ngày.
 *  - big_test.paper_missing: còn dưới N giờ (mặc định 24) mà đề chưa phân phối → biên bản cho Học thuật.
 * Mọi biên bản tự lập đi đúng quy trình duyệt (pending, người báo "Tự động", người chốt quyết mức phạt) và idempotent.
 * Tắt "Tự lập biên bản" của một SLA thì chỉ gửi thông báo (1 lần / người / đợt thi).
 */
class BigTestSlaService
{
    public const AUTO_LATE_RESULTS = 'big_test_late_results';

    public const AUTO_PAPER_MISSING = 'big_test_paper_missing';

    public const RULE_LATE_RESULTS = 'big_test.results_late';

    public const RULE_PAPER_REMINDER = 'big_test.paper_reminder';

    public const RULE_PAPER_APPROVAL = 'big_test.paper_approval';

    public const RULE_PAPER_MISSING = 'big_test.paper_missing';

    /** Quét kết quả trễ hạn chỉ trong N ngày gần nhất — không phạt hồi tố các đợt thi quá cũ. */
    public const LATE_SCAN_DAYS = 90;

    /** Hệ thống cảnh báo / nhắc hằng ngày duyệt đề từ N ngày trước ngày thi. */
    public function warnDays(): int
    {
        return Sla::value(self::RULE_PAPER_REMINDER);
    }

    /** Học thuật phải duyệt & phân phối đề trước ngày thi ít nhất N ngày. */
    public function approveBeforeDays(): int
    {
        return Sla::value(self::RULE_PAPER_APPROVAL);
    }

    /** GV chưa nhận đề khi còn dưới N giờ tới giờ thi → tự lập biên bản cho Học thuật (FR-SYL-08). */
    public function paperMissingHours(): int
    {
        return Sla::value(self::RULE_PAPER_MISSING);
    }

    /**
     * Người duyệt Big Test (Học thuật): người đang hoạt động có quyền big_test.approve, không tính Super Admin
     * (Admin vẫn duyệt được nhưng không bị lập biên bản / giao việc thay Học thuật).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function approvers(): \Illuminate\Support\Collection
    {
        return Rbac::scopeUsersWithPermission(User::query(), 'big_test.approve')
            ->where('is_active', true)->whereNull('locked_at')
            ->whereDoesntHave('roles', fn ($r) => $r->where('name', Rbac::SUPER_ADMIN))
            ->orderBy('id')->get();
    }

    /** Đã nhập đủ kết quả cả lớp (không còn bản nháp, mọi học viên đang học có kết quả) — chỉ còn chờ duyệt / gửi. */
    public function resultsEntered(BigTest $test): bool
    {
        $results = BigTestResult::where('big_test_id', $test->id)->get(['student_id', 'status']);
        if ($results->isEmpty() || $results->contains(fn ($r) => $r->status === 'draft')) {
            return false;
        }
        $rosterIds = $test->classModel?->roster()->pluck('students.id') ?? collect();

        return $rosterIds->diff($results->pluck('student_id'))->isEmpty();
    }

    /**
     * Người chịu trách nhiệm trả kết quả: còn thiếu / nháp → GV chính của lớp (nhập điểm);
     * đã nhập đủ, chờ duyệt & gửi → người duyệt Big Test đầu tiên (id nhỏ nhất, không phải Admin).
     * Chọn 1 người để biên bản là 1 / đợt thi; người chốt có thể hủy / đổi nếu quy trách nhiệm sai.
     */
    public function responsibleForResults(BigTest $test): ?User
    {
        if ($this->resultsEntered($test)) {
            return $this->approvers()->first();
        }
        $teacherId = $test->classModel?->teacher_id;

        return ($teacherId ? User::find($teacherId) : null) ?? $this->approvers()->first();
    }

    /**
     * Mỗi đợt thi trả kết quả trễ hạn → 1 biên bản (pending) cho người chịu trách nhiệm, mức phạt gợi ý = 50.000đ × số ngày trễ,
     * cập nhật mỗi ngày trong lúc còn trễ (người chốt vẫn quyết mức cuối cùng). Dừng khi đã trả đủ kết quả
     * (results_completed_at) hoặc biên bản đã được chốt / đóng.
     *
     * @return int số biên bản tạo mới
     */
    public function enforceLateResults(?Carbon $now = null): int
    {
        if (! Sla::enabled(self::RULE_LATE_RESULTS)) {
            return 0;
        }
        $now ??= now();
        $created = 0;
        $deadlineDays = BigTest::resultDeadlineDays();
        $finePerDay = (float) Sla::rule(self::RULE_LATE_RESULTS)['amount'];

        $tests = BigTest::with('classModel')
            ->whereNull('results_completed_at')
            ->whereNotNull('class_id')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', $now->copy()->subDays(self::LATE_SCAN_DAYS))
            ->where('scheduled_at', '<', $now->copy()->startOfDay()->subDays($deadlineDays))
            ->orderBy('id')
            ->get();

        foreach ($tests as $test) {
            $days = $test->lateDays($now);
            if ($days <= 0) {
                continue;
            }
            $violation = "Trả kết quả Big Test trễ {$days} ngày (hạn {$deadlineDays} ngày)";
            $amount = $finePerDay * $days;

            $penalty = Penalty::where('big_test_id', $test->id)->where('auto_source', self::AUTO_LATE_RESULTS)->first();
            if ($penalty) {
                // Biên bản đã chốt / đóng → không đụng; còn chờ giải trình → cập nhật số ngày trễ và mức gợi ý.
                if (in_array($penalty->status, ['pending', 'explained'], true)) {
                    $update = ['violation_type' => $violation, 'amount' => $amount];
                    if ($penalty->status === 'pending' && ($responsible = $this->responsibleForResults($test))) {
                        $update['user_id'] = $responsible->id;
                    }
                    $penalty->update($update);
                }

                continue;
            }

            $responsible = $this->responsibleForResults($test);
            if (! $responsible) {
                continue;
            }
            if (! Sla::penalizes(self::RULE_LATE_RESULTS)) {
                $this->notifyOnce($responsible, $test, self::RULE_LATE_RESULTS, 'Quá hạn trả kết quả Big Test',
                    "{$test->title} — trễ {$days} ngày so với hạn {$test->resultsDueAt()->format('d/m/Y')}. Hãy hoàn tất và gửi kết quả cho phụ huynh.",
                    route('syllabus.big-tests.results', $test->id));

                continue;
            }

            $penalty = DB::transaction(fn () => Penalty::create([
                'code' => Penalty::generateCode(),
                'user_id' => $responsible->id,
                'class_id' => $test->class_id,
                'big_test_id' => $test->id,
                'auto_source' => self::AUTO_LATE_RESULTS,
                'violation_type' => $violation,
                'error_category' => 'academic',
                'violation_date' => $test->resultsDueAt()->toDateString(),
                'amount' => $amount,
                'reporter_id' => null,
                'status' => 'pending',
                'notes' => "Big Test {$test->code} ({$test->title}) thi ngày ".$test->scheduled_at->format('d/m/Y')
                    .', hạn trả kết quả '.$test->resultsDueAt()->format('d/m/Y').'. Mức phạt gợi ý '.Money::format($finePerDay, '')
                    .'đ/ngày trễ, cập nhật mỗi ngày tới khi trả đủ kết quả.',
            ]));
            $created++;

            AdminNotification::create([
                'user_id' => $responsible->id,
                'type' => 'penalty_created',
                'title' => "Biên bản {$penalty->code}: trả kết quả Big Test trễ hạn",
                'message' => "{$test->title} — trễ {$days} ngày so với hạn {$test->resultsDueAt()->format('d/m/Y')}. Hãy hoàn tất kết quả và gửi giải trình.",
                'data' => ['link' => route('penalties.index', ['search' => $penalty->code]), 'big_test_id' => $test->id],
                'is_read' => false,
            ]);
        }

        return $created;
    }

    /** Số ngày (theo lịch) từ hôm nay tới ngày thi. */
    public function daysUntil(BigTest $test, ?Carbon $now = null): int
    {
        return (int) ($now ?? now())->copy()->startOfDay()->diffInDays($test->scheduled_at->copy()->startOfDay(), false);
    }

    /** Đề đã quá hạn duyệt (còn dưới 3 ngày tới ngày thi mà chưa phân phối). */
    public function paperOverdue(BigTest $test, ?Carbon $now = null): bool
    {
        return ! $test->is_distributed && $test->scheduled_at !== null
            && $test->scheduled_at->isFuture() && $this->daysUntil($test, $now) < $this->approveBeforeDays();
    }

    /**
     * Đợt thi chưa phân phối đề, thi trong vòng 7 ngày tới.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BigTest>
     */
    public function undistributedUpcoming(?Carbon $now = null): \Illuminate\Database\Eloquent\Collection
    {
        $now ??= now();

        return BigTest::with('classModel')
            ->where('is_distributed', false)
            ->whereNotNull('class_id')
            ->whereBetween('scheduled_at', [$now, $now->copy()->addDays($this->warnDays())->endOfDay()])
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Nhắc Học thuật MỖI NGÀY (thông báo cá nhân, 1 thông báo / người / đợt thi / ngày) duyệt đề các Big Test thi trong 7 ngày tới;
     * còn dưới 3 ngày → "Quá hạn duyệt đề (trước 3 ngày)".
     *
     * @return int số thông báo mới
     */
    public function remindPaperApproval(?Carbon $now = null): int
    {
        if (! Sla::enabled(self::RULE_PAPER_REMINDER)) {
            return 0;
        }
        $now ??= now();
        $approvers = $this->approvers();
        $sent = 0;

        foreach ($this->undistributedUpcoming($now) as $test) {
            $daysLeft = $this->daysUntil($test, $now);
            $overdue = $daysLeft < $this->approveBeforeDays();
            $className = $test->classModel?->name ?? 'lớp';
            $when = $test->scheduled_at->format('H:i d/m/Y');
            $title = $overdue
                ? 'Quá hạn duyệt đề (trước '.$this->approveBeforeDays()." ngày): {$test->code}"
                : "Cần duyệt đề Big Test: {$test->code} (còn {$daysLeft} ngày)";
            $message = "Lớp {$className} thi \"{$test->title}\" lúc {$when}"
                .($overdue
                    ? ' — đề phải được duyệt & phân phối trước ngày thi '.$this->approveBeforeDays().' ngày, hiện chưa duyệt.'
                    : ' — hãy duyệt & phân phối đề trước ngày thi '.$this->approveBeforeDays().' ngày.');

            foreach ($approvers as $user) {
                $exists = AdminNotification::where('user_id', $user->id)->where('type', 'big_test_paper_due')
                    ->where('data->big_test_id', $test->id)->whereDate('created_at', $now->toDateString())->exists();
                if ($exists) {
                    continue;
                }
                AdminNotification::create([
                    'user_id' => $user->id,
                    'type' => 'big_test_paper_due',
                    'title' => $title,
                    'message' => $message,
                    'data' => ['big_test_id' => $test->id, 'days_left' => $daysLeft, 'link' => route('syllabus.big-tests.distribution')],
                    'is_read' => false,
                ]);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Còn 7 ngày tới ngày thi mà đề chưa duyệt → tự tạo việc cho Học thuật (hạn = ngày thi), 1 việc / đợt thi (work_tasks.big_test_id).
     * Việc giao cho người duyệt đầu tiên (không phải Admin); người tạo theo mẫu chăm sóc tháng đầu: Admin đầu tiên, không có thì chính người nhận.
     *
     * @return int số việc tạo mới
     */
    public function ensureApprovalTasks(?Carbon $now = null): int
    {
        if (! Sla::enabled(self::RULE_PAPER_REMINDER)) {
            return 0;
        }
        $assignee = $this->approvers()->first();
        if (! $assignee) {
            return 0;
        }
        $creator = BranchStaff::admins()->first() ?? $assignee;
        $created = 0;

        foreach ($this->undistributedUpcoming($now) as $test) {
            if (WorkTask::withTrashed()->where('big_test_id', $test->id)->exists()) {
                continue;
            }
            $task = WorkTask::create([
                'title' => "Duyệt & phân phối đề Big Test {$test->code}: {$test->classModel?->name}",
                'description' => "Big Test \"{$test->title}\" thi lúc ".$test->scheduled_at->format('H:i d/m/Y')
                    .'. Duyệt & phân phối đề trước ngày thi '.$this->approveBeforeDays().' ngày; việc tự đóng khi đề được phân phối.',
                'creator_id' => $creator->id,
                'assignee_id' => $assignee->id,
                'branch_id' => $test->classModel?->branch_id,
                'class_id' => $test->class_id,
                'big_test_id' => $test->id,
                'task_type' => 'one_time',
                'due_date' => $test->scheduled_at->toDateString(),
                'status' => 'new',
            ]);
            AdminNotification::create([
                'user_id' => $assignee->id,
                'type' => 'task_assigned',
                'title' => 'Việc mới: '.$task->title,
                'message' => 'Hạn '.$test->scheduled_at->format('d/m/Y').' (ngày thi).',
                'data' => ['task_id' => $task->id, 'big_test_id' => $test->id, 'link' => route('tasks.index', ['tab' => 'mine'])],
                'is_read' => false,
            ]);
            $created++;
        }

        return $created;
    }

    /** Đề đã phân phối → đóng (hoàn thành) việc duyệt đề tự tạo của đợt thi. Gọi khi duyệt; lệnh hằng ngày quét bù. */
    public function closeApprovalTasks(?BigTest $test = null): int
    {
        return WorkTask::query()
            ->whereNotNull('big_test_id')
            ->whereIn('status', [...WorkTask::OPEN_STATUSES, 'overdue', 'blocked', 'pending_confirmation'])
            ->whereIn('big_test_id', BigTest::query()->where('is_distributed', true)
                ->when($test, fn ($q) => $q->whereKey($test->id))->select('id'))
            ->get()
            ->each(fn (WorkTask $task) => $task->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completion_note' => 'Đề Big Test đã được duyệt & phân phối (hệ thống tự đóng).',
            ]))
            ->count();
    }

    /**
     * GV chưa nhận đề khi còn dưới 24 giờ tới giờ thi (đề chưa phân phối) → mỗi người có quyền duyệt Big Test (Học thuật, không tính Admin)
     * 1 biên bản (pending, mức phạt 0, người báo "Tự động", người chốt quyết mức phạt) / đợt thi. Idempotent.
     *
     * @return int số biên bản tạo mới
     */
    public function enforcePaperMissing(?Carbon $now = null): int
    {
        if (! Sla::enabled(self::RULE_PAPER_MISSING)) {
            return 0;
        }
        $now ??= now();
        $approvers = $this->approvers();
        $created = 0;
        $hours = $this->paperMissingHours();
        $penalize = Sla::penalizes(self::RULE_PAPER_MISSING);
        $amount = (float) Sla::rule(self::RULE_PAPER_MISSING)['amount'];

        $tests = BigTest::with('classModel')
            ->where('is_distributed', false)
            ->whereNotNull('class_id')
            ->where('scheduled_at', '<', $now->copy()->addHours($hours))
            // Lệnh bị lỡ vài giờ vẫn bắt bù; không phạt hồi tố các đợt thi đã qua lâu.
            ->where('scheduled_at', '>=', $now->copy()->subHours($hours))
            ->get();

        foreach ($tests as $test) {
            foreach ($approvers as $user) {
                if (! $penalize) {
                    $this->notifyOnce($user, $test, self::RULE_PAPER_MISSING, 'GV chưa nhận đề Big Test',
                        "{$test->title} thi lúc ".$test->scheduled_at->format('H:i d/m/Y').' nhưng đề chưa được phân phối — hãy duyệt ngay.',
                        route('syllabus.big-tests.distribution'));

                    continue;
                }
                $exists = Penalty::where('big_test_id', $test->id)->where('user_id', $user->id)
                    ->where('auto_source', self::AUTO_PAPER_MISSING)->exists();
                if ($exists) {
                    continue;
                }
                $penalty = Penalty::create([
                    'code' => Penalty::generateCode(),
                    'user_id' => $user->id,
                    'class_id' => $test->class_id,
                    'big_test_id' => $test->id,
                    'auto_source' => self::AUTO_PAPER_MISSING,
                    'violation_type' => 'GV chưa nhận đề Big Test sát ngày thi (dưới '.$hours.'h)',
                    'error_category' => 'academic',
                    'violation_date' => $test->scheduled_at->toDateString(),
                    'amount' => $amount,
                    'reporter_id' => null,
                    'status' => 'pending',
                    'notes' => "Big Test {$test->code} ({$test->title}) lớp {$test->classModel?->name} thi lúc ".$test->scheduled_at->format('H:i d/m/Y')
                        .' nhưng đề chưa được duyệt & phân phối cho giáo viên. Người chốt quyết mức phạt.',
                ]);
                AdminNotification::create([
                    'user_id' => $user->id,
                    'type' => 'penalty_created',
                    'title' => "Biên bản {$penalty->code}: GV chưa nhận đề Big Test",
                    'message' => "{$test->title} thi lúc ".$test->scheduled_at->format('H:i d/m/Y').' nhưng đề chưa được phân phối — hãy duyệt ngay và gửi giải trình.',
                    'data' => ['link' => route('penalties.index', ['search' => $penalty->code]), 'big_test_id' => $test->id],
                    'is_read' => false,
                ]);
                $created++;
            }
        }

        return $created;
    }

    /** SLA tắt tự lập biên bản: chỉ báo người chịu trách nhiệm, 1 thông báo / người / SLA / đợt thi. */
    private function notifyOnce(User $user, BigTest $test, string $rule, string $title, string $message, string $link): void
    {
        $exists = AdminNotification::where('user_id', $user->id)->where('type', 'sla_breach')
            ->where('data->sla_rule', $rule)->where('data->big_test_id', $test->id)->exists();
        if ($exists) {
            return;
        }
        AdminNotification::create([
            'user_id' => $user->id,
            'type' => 'sla_breach',
            'title' => "Quá hạn SLA: {$title} ({$test->code})",
            'message' => $message,
            'data' => ['sla_rule' => $rule, 'big_test_id' => $test->id, 'link' => $link],
            'is_read' => false,
        ]);
    }
}
