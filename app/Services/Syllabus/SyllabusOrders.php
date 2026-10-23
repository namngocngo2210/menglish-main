<?php

namespace App\Services\Syllabus;

use App\Models\AdminNotification;
use App\Models\BigTestOrder;
use App\Models\ClassModel;
use App\Models\MaterialOrder;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Models\User;
use App\Services\BigTestSlaService;
use App\Services\MaterialOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Nút ORDER theo mốc syllabus (docs/kho-syllabus.md §9.3, Bước 7, câu 5): buổi có mốc Order in ấn / mini test / Big Test của
 * chặng đang mở đến hạn khi lớp đã tới buổi đó (buổi hiện tại hoặc đã dạy) mà chưa có order gắn buổi. In ấn → vận hành cơ sở
 * (order học liệu loại In ấn); đề → Học thuật (order đề Big Test / mini test, hạn xử lý trước buổi test ≥ 3 buổi).
 */
class SyllabusOrders
{
    public const ORDER_MILESTONES = ['order_print', 'order_mini_test', 'order_big_test'];

    /** Số buổi học trước buổi test mà Học thuật phải xử lý xong đề (câu 5). */
    public const TEST_LEAD_SESSIONS = 3;

    public function __construct(private readonly ClassSyllabus $syllabus) {}

    /** Lớp GV dạy (GV được giao chặng / GV chính / GVNN) đang có chặng mở. */
    public function classesOf(User $user): Collection
    {
        return ClassModel::visibleTo($user)
            ->whereIn('id', SyllabusAssignment::open()->whereNotNull('class_id')->select('class_id'))
            ->where(fn ($q) => $q->where('teacher_id', $user->id)->orWhere('foreign_teacher_id', $user->id)
                ->orWhereIn('id', SyllabusAssignment::open()->where('user_id', $user->id)->select('class_id')))
            ->orderBy('name')->get();
    }

    /**
     * Mốc order của chặng lớp đang học.
     *
     * @return Collection<int, array{lesson: SyllabusLesson, kind: string, label: string, due: bool, ordered: ?string, target: string, use_date: ?Carbon, exam_date: ?Carbon, due_date: ?Carbon}>
     */
    public function milestones(SyllabusAssignment $assignment): Collection
    {
        $timeline = $this->syllabus->timeline($assignment);
        $rows = $timeline['rows']->values();
        $currentIndex = $timeline['current'] ? $rows->search(fn ($r) => $r['lesson']->id === $timeline['current']['lesson']->id) : $rows->count();
        // Order bị từ chối không tính: GV order lại được.
        $ordered = MaterialOrder::where('syllabus_assignment_id', $assignment->id)->where('status', '!=', MaterialOrder::STATUS_REJECTED)
            ->get(['code', 'syllabus_lesson_id'])->keyBy('syllabus_lesson_id')
            ->union(BigTestOrder::where('syllabus_assignment_id', $assignment->id)->where('status', '!=', 'rejected')
                ->get(['code', 'syllabus_lesson_id'])->keyBy('syllabus_lesson_id'));
        // Order đề gửi từ màn "Order test" cũ (không gắn buổi) cùng lớp / chặng / loại đề: lần lượt tính cho mốc chưa order.
        $unlinked = BigTestOrder::where('class_id', $assignment->class_id)->whereNull('syllabus_lesson_id')->where('status', '!=', 'rejected')
            ->when($assignment->stage_id, fn ($q) => $q->where('syllabus_stage_id', $assignment->stage_id), fn ($q) => $q->where('stage_name', $assignment->stage_name))
            ->orderBy('id')->get(['code', 'test_type'])->groupBy('test_type');
        $branch = $assignment->classModel?->branch?->name;

        return $rows->map(function (array $row, int $i) use ($rows, $currentIndex, $ordered, $unlinked, $branch) {
            $kind = $row['data']['notice_milestone'] ?? null;
            if (! in_array($kind, self::ORDER_MILESTONES, true)) {
                return null;
            }
            // Buổi test kế tiếp từ mốc: order đề → buổi Mini test / Big test tương ứng; in ấn → buổi test gần nhất.
            $types = match ($kind) {
                'order_big_test' => ['big_test'],
                'order_mini_test' => ['mini_test'],
                default => ['mini_test', 'big_test'],
            };
            $examIndex = $rows->keys()->first(fn (int $k) => $k >= $i && in_array($rows[$k]['data']['lesson_type'] ?? null, $types, true));
            $dateOf = fn (?int $k) => $k === null || ! isset($rows[$k]) ? null : ($rows[$k]['planned_date'] ?? $rows[$k]['taught_at']?->copy()->startOfDay());
            $examDate = $dateOf($examIndex);
            $useDate = $examDate ?? $dateOf($i);
            $code = $ordered->get($row['lesson']->id)?->code;
            if (! $code && $kind !== 'order_print') {
                $code = $unlinked->get($kind === 'order_big_test' ? 'big' : 'mini')?->shift()?->code;
            }

            return [
                'lesson' => $row['lesson'],
                'kind' => $kind,
                'label' => SyllabusLesson::MILESTONES[$kind],
                // Đến hạn khi lớp đã tới buổi có mốc, đến khi buổi dùng / buổi test đã qua.
                'due' => $i <= $currentIndex && ($useDate === null || $useDate->gte(today())),
                'ordered' => $code,
                'target' => $kind === 'order_print' ? 'Vận hành cơ sở'.($branch ? " {$branch}" : '') : 'Học thuật',
                'use_date' => $useDate,
                'exam_date' => $examDate,
                // Học thuật xử lý xong đề trước buổi test ít nhất 3 buổi.
                'due_date' => $examIndex !== null ? ($dateOf($examIndex - self::TEST_LEAD_SESSIONS) ?? $examDate?->copy()->subDays(7)) : null,
            ];
        })->filter()->values();
    }

    /** Số mốc đến hạn chưa order của các lớp GV dạy (nút ORDER nhấp nháy); cache 5 phút / người. */
    public function dueCount(User $user): int
    {
        return Cache::remember(self::cacheKey($user->id), now()->addSeconds(random_int(270, 330)), fn () => $this->classesOf($user)->sum(function (ClassModel $class) {
            $assignment = SyllabusAssignment::with('classModel.branch')->open()->where('class_id', $class->id)->first();

            return $assignment ? $this->milestones($assignment)->filter(fn ($m) => $m['due'] && ! $m['ordered'])->count() : 0;
        }));
    }

    /** Tạo order cho mốc đến hạn (mỗi mốc 1 order). */
    public function place(User $user, SyllabusAssignment $assignment, int $lessonId, array $input): string
    {
        $code = DB::transaction(function () use ($user, $assignment, $lessonId, $input) {
            SyllabusAssignment::whereKey($assignment->id)->lockForUpdate()->first();
            $milestone = $this->milestones($assignment->loadMissing('classModel.branch'))->first(fn ($m) => $m['lesson']->id === $lessonId);
            if (! $milestone || ! $milestone['due']) {
                throw ValidationException::withMessages(['lesson_id' => 'Mốc order này chưa đến hạn.']);
            }
            if ($milestone['ordered']) {
                throw ValidationException::withMessages(['lesson_id' => "Mốc này đã order ({$milestone['ordered']})."]);
            }
            $class = $assignment->classModel;
            $stage = $assignment->stage?->label ?? $assignment->stage_name;
            $lesson = $milestone['lesson'];

            if ($milestone['kind'] === 'order_print') {
                return app(MaterialOrderService::class)->create($user, [
                    'branch_id' => $class->branch_id, 'class_id' => $class->id, 'category' => MaterialOrder::CATEGORY_PRINTING,
                    'title' => "In ấn {$stage} – Buổi {$lesson->session_no}: {$lesson->title}",
                    'description' => $input['note'] ?? null, 'quantity' => $input['quantity'] ?? null,
                    'use_date' => ($milestone['use_date'] ?? today()->addDays(3))->toDateString(),
                    'syllabus_assignment_id' => $assignment->id, 'syllabus_lesson_id' => $lesson->id,
                ])->code;
            }

            $order = BigTestOrder::create([
                'code' => 'ORDTEST-'.strtoupper(Str::random(6)),
                'class_id' => $class->id, 'syllabus_stage_id' => $assignment->stage_id, 'teacher_id' => $user->id, 'stage_name' => $stage,
                'test_type' => $milestone['kind'] === 'order_big_test' ? 'big' : 'mini',
                'exam_date' => $milestone['exam_date'],
                'due_date' => ($milestone['due_date'] ?? today()->addDays(BigTestOrder::leadDays()))->max(today()),
                'note' => $input['note'] ?? null, 'status' => 'pending',
                'syllabus_assignment_id' => $assignment->id, 'syllabus_lesson_id' => $lesson->id,
            ]);
            AdminNotification::notifyUsers(app(BigTestSlaService::class)->approverIdsOrAdmins(), 'big_test_order',
                'GV yêu cầu đề test: '.$user->name,
                "Giáo viên {$user->name} order đề {$order->type_label} cho {$stage} của lớp {$class->name} (mốc buổi {$lesson->session_no}).",
                route('syllabus.big-tests.distribution', ['order' => $order->id]), ['big_test_order_id' => $order->id], $user->id);

            return $order->code;
        });
        self::forget([$assignment->user_id, $assignment->classModel?->teacher_id, $assignment->classModel?->foreign_teacher_id, $user->id]);

        return $code;
    }

    /** Xoá số đếm nút ORDER đã cache (sau khi order / bấm Đã dạy / đổi GV lớp). */
    public static function forget(iterable $userIds): void
    {
        foreach ($userIds as $id) {
            if ($id) {
                Cache::forget(self::cacheKey((int) $id));
            }
        }
    }

    public static function cacheKey(int $userId): string
    {
        return "syllabus:v1:order-due:{$userId}";
    }
}
