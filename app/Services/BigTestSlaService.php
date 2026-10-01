<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\Penalty;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SLA của Big Test:
 *  - Trả kết quả cho phụ huynh tối đa 7 ngày kể từ ngày thi (BigTest::RESULT_DEADLINE_DAYS); trễ → 50.000đ / ngày trễ.
 *  - Học thuật duyệt & phân phối đề trước ngày thi ít nhất 3 ngày, hệ thống cảnh báo từ 7 ngày trước và nhắc mỗi ngày.
 * Mọi biên bản tự lập đi đúng quy trình duyệt (pending, người báo "Tự động", người chốt quyết mức phạt) và idempotent.
 */
class BigTestSlaService
{
    public const AUTO_LATE_RESULTS = 'big_test_late_results';

    /** Quét kết quả trễ hạn chỉ trong N ngày gần nhất — không phạt hồi tố các đợt thi quá cũ. */
    public const LATE_SCAN_DAYS = 90;

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
        $now ??= now();
        $created = 0;

        $tests = BigTest::with('classModel')
            ->whereNull('results_completed_at')
            ->whereNotNull('class_id')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', $now->copy()->subDays(self::LATE_SCAN_DAYS))
            ->where('scheduled_at', '<', $now->copy()->startOfDay()->subDays(BigTest::RESULT_DEADLINE_DAYS))
            ->orderBy('id')
            ->get();

        foreach ($tests as $test) {
            $days = $test->lateDays($now);
            if ($days <= 0) {
                continue;
            }
            $violation = "Trả kết quả Big Test trễ {$days} ngày (hạn ".BigTest::RESULT_DEADLINE_DAYS.' ngày)';
            $amount = BigTest::LATE_FINE_PER_DAY * $days;

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
                    .', hạn trả kết quả '.$test->resultsDueAt()->format('d/m/Y').'. Mức phạt gợi ý '.number_format(BigTest::LATE_FINE_PER_DAY, 0, ',', '.')
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
}
