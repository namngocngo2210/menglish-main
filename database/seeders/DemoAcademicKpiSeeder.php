<?php

namespace Database\Seeders;

use App\Models\AcademicProject;
use App\Models\AcademicProjectMilestone;
use App\Models\ClassSession;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\KpiEvaluationItem;
use App\Models\MaterialOrder;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Kpi\KpiSheetService;
use App\Services\MaterialOrderService;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu demo KPI Học thuật (Trưởng Học thuật, academiclead@): đủ nguồn số liệu cho các mục tự đếm của bộ KPI V11 và phiếu
 * tháng trước đã chốt / tháng này đang chấm. Mốc thời gian tương đối với hôm nay (L = tháng trước, C = tháng này, N = tháng sau).
 * - 2 dự án học thuật (giáo trình Starters K28, chương trình IELTS Foundation 2027) với các mốc deliverable: phần lớn đúng hạn,
 *   vài mốc trễ vài ngày, mốc giao GV khác để đối chiếu (không tính cho Trưởng Học thuật), mốc tháng sau chưa tới hạn.
 * - Việc được giao (Giao việc) có hạn trong tháng: đúng hạn, trễ vài giờ, việc tháng này còn đang làm.
 * - Order "Học liệu học thuật" theo buổi học thật, phần lớn giao trước hạn ngày 5: 1 order giao 12–24 giờ trước giờ dùng mỗi
 *   tháng, 1 order tháng trước tạo sát giờ (lỗi bên yêu cầu, không tính), 1 order đầu tháng này giao sau giờ dùng (mục tối đa
 *   50%, phiếu tối đa loại B), order GV vừa gửi còn chờ xử lý.
 * - Số liệu điền tay (chất lượng deliverable, tiến độ chương trình, đào tạo GV, xử lý phản ánh, lỗi lặp lại, nghỉ / họp):
 *   tháng trước đủ và chốt phiếu; tháng này có mục "Không phát sinh" và 1 mục chưa chấm.
 *
 * Gọi từ DemoPayrollSeeder (php artisan demo:luong), chạy được cả trên CSDL đã có dữ liệu demo lương. Idempotent: dự án đầu
 * tiên đã có thì bỏ qua. Chỉ thêm dữ liệu; riêng phiếu KPI của tài khoản mẫu academiclead@ thì phiếu tháng này (chờ duyệt) được
 * điền lại, phiếu tháng trước chỉ chốt lại khi do chính seeder demo chốt.
 */
class DemoAcademicKpiSeeder extends Seeder
{
    public const LEAD = 'academiclead@menglish.edu.vn';

    private const ADMIN = 'admin@menglish.edu.vn';

    /** Dự án: khóa => [tên, loại, mô tả]. Tên có tiền tố "# " như dữ liệu nghiệp vụ mẫu khác. */
    private const PROJECTS = [
        'book' => ['# Giáo trình Starters K28 (Book 1–2)', 'book', 'Biên soạn Student Book, Workbook, slide và bộ test cho khóa Starters khai giảng K28.'],
        'ielts' => ['# Chương trình IELTS Foundation 2027', 'curriculum', 'Xây dựng khung chương trình, rubric và bộ test cho lộ trình IELTS Foundation áp dụng từ 2027.'],
    ];

    /**
     * Mốc deliverable: [dự án, tên, tháng (-1 L, 0 C, 1 N), ngày, người phụ trách ('lead' | null = chủ dự án | email), số ngày trễ
     * khi xong (null = chưa xong)]. Mốc chưa tới hạn để Đang làm / Chưa bắt đầu.
     */
    private const MILESTONES = [
        ['book', 'Unit 1–3: bản thảo Student Book', -1, 5, 'lead', 0],
        ['book', 'Unit 1–3: Workbook + audio', -1, 12, null, 0],
        ['ielts', 'Khung chương trình & mục tiêu đầu ra', -1, 15, 'lead', 0],
        ['book', 'Slide Unit 1–6', -1, 19, 'gv.native1@menglish.edu.vn', 1],
        ['ielts', 'Bộ test đầu vào Foundation', -1, 26, 'lead', 2],
        ['book', 'Unit 4–6: bản thảo Student Book', 0, 3, 'lead', 0],
        ['book', 'Mini test Unit 1–6', 0, 6, null, 5],
        ['ielts', 'Speaking rubric Foundation', 0, 9, 'lead', 0],
        ['book', 'Unit 7–9: bản thảo Student Book', 0, 20, 'lead', 0],
        ['ielts', 'Slide Foundation tuần 1–4', 0, 24, 'gv.hamy@menglish.edu.vn', null],
        ['book', 'Duyệt in Book 1', 1, 5, 'lead', null],
        ['ielts', 'Pilot lớp IELTS Foundation K28', 1, 15, 'lead', null],
    ];

    /** Việc giao cho Trưởng Học thuật: [tên, tháng, ngày, giờ hạn, số phút trễ khi xong (âm = xong sớm; null = chưa xong)]. */
    private const TASKS = [
        ['Duyệt giáo án tuần 1 các lớp K27', -1, 4, '17:00', -90],
        ['Tổng hợp phản ánh về GV tháng trước', -1, 8, '12:00', -30],
        ['Chuẩn bị nội dung họp chuyên môn GV part-time', -1, 13, '18:00', -240],
        ['Rà soát bộ đề Big Test kỳ 3', -1, 18, '17:00', 120],
        ['Lên lịch dự giờ GV tháng sau', -1, 27, '17:00', -60],
        ['Phân bổ giáo trình cho các lớp K28', 0, 2, '12:00', -45],
        ['Đào tạo GV mới: quy trình học thuật', 0, 5, '17:00', -15],
        ['Báo cáo chất lượng đầu ra tháng trước', 0, 7, '17:00', 360],
        ['Dự giờ lớp Giao tiếp Pro B1 K28 (Ba Đình)', 0, 16, '19:30', -20],
        ['Cập nhật rubric Speaking cho GV', 0, 23, '17:00', null],
    ];

    /**
     * Order học liệu học thuật (hạn xử lý: ngày 5 của tháng dùng): [tháng, ngày dùng (buổi học đầu tiên từ ngày này), tên, ngày tạo
     * (tính từ đầu tháng dùng, âm = cuối tháng trước; ['h', n] = n giờ trước giờ dùng), lúc giao xong (['day', n] = 17:00 ngày n;
     * ['h', n] = n giờ trước giờ dùng, âm = sau giờ dùng; null = chưa giao)].
     */
    private const ORDERS = [
        [-1, 8, 'Bộ flashcard Unit 1–3 (in màu)', -3, ['day', 3]],
        [-1, 12, 'Đề mini test Unit 2 + đáp án', -2, ['day', 4]],
        [-1, 3, 'Phiếu bài tập Speaking theo cặp', -5, ['h', 16]],
        [-1, 16, 'Audio bài nghe Unit 3 (bản chỉnh)', 1, ['day', 5]],
        [-1, 22, 'Worksheet ôn tập Unit 1–4', ['h', 10], ['h', 3]],
        [-1, 25, 'Đề Big Test kỳ 3 bản in', 2, ['day', 5]],
        [0, 2, 'Slide ôn tập Unit 1', -6, ['h', -2]],
        [0, 3, 'Bộ tranh chủ đề Family', -1, ['h', 20]],
        [0, 4, 'Bộ thẻ từ vựng Unit 1', -5, ['day', 1]],
        [0, 6, 'Student Book bản PDF cho GV', -4, ['day', 2]],
        [0, 9, 'Slide buổi 3–4 (đã duyệt)', -3, ['day', 4]],
        [0, 14, 'Đề mini test Unit 1', -2, ['day', 5]],
        [0, 20, 'Đề Big Test giữa khóa', 3, ['day', 5]],
        [0, 27, 'Phiếu chấm Speaking giữa khóa', ['h', 0], null],
    ];

    /**
     * Số liệu điền tay theo tên tiêu chí (khớp đầu tên): [tháng trước, tháng này]; 'na' = Không phát sinh, null = chưa chấm.
     */
    private const MANUAL = [
        'Chất lượng deliverable' => [92, 88],
        'Kiểm soát tiến độ' => [96, 94],
        'Đào tạo & triển khai' => [100, 100],
        'Xử lý phản ánh về GV' => [88, null],
        'Lỗi GV lặp lại' => [8, 'na'],
        'Nghỉ không phép' => [0, 0],
        'Họp bắt buộc' => [0, 1],
    ];

    private Carbon $realNow;

    private Carbon $thisMonth;

    private User $lead;

    private User $admin;

    public function run(): void
    {
        $lead = User::where('email', self::LEAD)->first();
        $admin = User::where('email', self::ADMIN)->first();
        if (! $lead || ! $admin || ! KpiCriterion::forRole(Roles::ACADEMIC_LEAD)->active()->exists()) {
            $this->command?->warn('DemoAcademicKpiSeeder: chưa có tài khoản Trưởng Học thuật mẫu hoặc bộ KPI Học thuật — bỏ qua.');

            return;
        }
        if (AcademicProject::withTrashed()->where('name', self::PROJECTS['book'][0])->exists()) {
            $this->command?->info('DemoAcademicKpiSeeder: đã có dữ liệu demo KPI Học thuật — bỏ qua.');

            return;
        }
        $this->lead = $lead;
        $this->admin = $admin;
        $previousTestNow = Carbon::getTestNow();
        $this->realNow = now()->copy();
        $this->thisMonth = $this->realNow->copy()->startOfMonth();

        try {
            DB::transaction(function () {
                $this->projects();
                $this->tasks();
                $this->orders();
                $this->sheets();
            });
        } finally {
            Carbon::setTestNow($previousTestNow);
        }

        $this->command?->info('DemoAcademicKpiSeeder: '.AcademicProjectMilestone::whereHas('project', fn ($q) => $q->where('name', 'like', '# %'))->count().' mốc dự án, '
            .WorkTask::where('assignee_id', $lead->id)->count().' việc, '
            .MaterialOrder::where('category', MaterialOrder::CATEGORY_ACADEMIC)->count().' order học liệu học thuật.');
    }

    /** Ngày trong tháng lệch $offset so với tháng này (ngày vượt số ngày của tháng thì lấy ngày cuối). */
    private function day(int $offset, int $day): Carbon
    {
        $month = $this->thisMonth->copy()->addMonthsNoOverflow($offset);

        return $month->day(min($day, $month->daysInMonth));
    }

    /** Thời điểm đã qua (trước "bây giờ" thật) thì đặt đồng hồ về đó và trả true. */
    private function travel(Carbon $at): bool
    {
        if ($at->gte($this->realNow)) {
            return false;
        }
        Carbon::setTestNow($at->copy());

        return true;
    }

    private function projects(): void
    {
        $start = $this->day(-2, 20);
        $this->travel($start->copy()->setTime(9, 0));
        $projects = collect(self::PROJECTS)->map(fn (array $p) => AcademicProject::create([
            'name' => $p[0],
            'type' => $p[1],
            'description' => $p[2],
            'owner_id' => $this->lead->id,
            'start_date' => $start->toDateString(),
            'deadline' => $this->day(2, 28)->toDateString(),
            'status' => 'active',
            'kickoff_notes' => 'Kick-off với tổ chuyên môn, chốt master plan và người phụ trách từng mốc.',
            'plan_locked_at' => $start->copy()->addDays(3)->setTime(17, 0),
            'plan_locked_by' => $this->lead->id,
            'created_by' => $this->lead->id,
        ]));
        $assignees = User::whereIn('email', collect(self::MILESTONES)->pluck(4)->filter(fn ($a) => is_string($a) && str_contains($a, '@'))->all())->pluck('id', 'email');
        $today = $this->realNow->copy()->startOfDay();

        foreach (self::MILESTONES as $i => [$project, $title, $offset, $day, $assignee, $lateDays]) {
            $due = $this->day($offset, $day);
            $done = $lateDays === null ? null : $due->copy()->addDays($lateDays)->setTime(16, 30);
            if ($done && $done->gte($this->realNow)) {
                $done = null;
            }
            $assigneeId = match (true) {
                $assignee === 'lead' => $this->lead->id,
                $assignee === null => null,
                default => $assignees[$assignee] ?? $this->lead->id,
            };
            AcademicProjectMilestone::create([
                'academic_project_id' => $projects[$project]->id,
                'title' => $title,
                'assignee_id' => $assigneeId,
                'start_date' => $due->copy()->subDays(10)->toDateString(),
                'due_date' => $due->toDateString(),
                'status' => $status = $done ? 'done' : ($due->copy()->subDays(10)->lte($today) ? 'in_progress' : 'todo'),
                'target_quantity' => 1,
                'done_quantity' => ['done' => 1, 'in_progress' => 0.5, 'todo' => 0][$status],
                'completed_at' => $done,
                'sort_order' => $i + 1,
            ]);
        }
        Carbon::setTestNow($this->realNow);
    }

    private function tasks(): void
    {
        foreach (self::TASKS as [$title, $offset, $day, $time, $lateMinutes]) {
            $due = $this->day($offset, $day)->setTimeFromTimeString($time);
            $created = $due->copy()->subDays(4)->setTime(9, 15);
            if (! $this->travel($created)) {
                continue;
            }
            $task = WorkTask::create([
                'title' => $title,
                'description' => 'Việc giao Trưởng Học thuật.',
                'creator_id' => $this->admin->id,
                'assignee_id' => $this->lead->id,
                'branch_id' => $this->lead->branch_id,
                'task_type' => 'one_time',
                'due_date' => $due->toDateString(),
                'due_time' => $time,
                'status' => 'new',
            ]);
            $done = $lateMinutes === null ? null : $due->copy()->addMinutes($lateMinutes);
            if ($done && $this->travel($done)) {
                $task->update(['status' => 'completed', 'completed_at' => $done, 'completion_note' => 'Đã xong.']);
            } elseif ($this->travel($created->copy()->addDay())) {
                $task->update(['status' => 'in_progress']);
            }
        }
        Carbon::setTestNow($this->realNow);
    }

    private function orders(): void
    {
        $service = app(MaterialOrderService::class);
        foreach (self::ORDERS as [$offset, $useDay, $title, $created, $done]) {
            $session = $this->sessionOn($offset, $useDay);
            if (! $session) {
                continue;
            }
            $use = Carbon::parse($session->date)->setTimeFromTimeString(Carbon::parse($session->start_time)->format('H:i'));
            $at = fn (array|int $spec) => match (true) {
                is_int($spec) => $this->day($offset, 1)->addDays($spec - 1)->setTime(10, 0),
                $spec[0] === 'day' => $this->day($offset, $spec[1])->setTime(17, 0),
                default => $use->copy()->subHours($spec[1]),
            };
            // Order chưa giao: GV vừa gửi hôm nay.
            $createdAt = $done === null ? $this->realNow->copy()->subHours(5) : $at($created);
            if (! $this->travel($createdAt)) {
                continue;
            }
            $requester = User::find($session->teacher_id ?: $session->classModel?->teacher_id) ?? $this->lead;
            $order = $service->create($requester, [
                'category' => MaterialOrder::CATEGORY_ACADEMIC,
                'branch_id' => $session->classModel->branch_id,
                'class_id' => $session->class_id,
                'title' => $title,
                'use_date' => $use->toDateString(),
            ]);
            if ($done === null) {
                continue;
            }
            $doneAt = $at($done);
            $claimAt = $createdAt->copy()->addHours(2)->min($doneAt->copy()->subHour());
            if ($this->travel($claimAt)) {
                $service->claim($order, $this->lead);
            }
            if ($this->travel($doneAt)) {
                $service->finish($order, $this->lead, MaterialOrder::STATUS_DONE, 'Đã gửi file / bàn giao bản in cho GV.');
            }
        }
        Carbon::setTestNow($this->realNow);
    }

    /** Buổi học (không hủy) đầu tiên của lớp demo từ ngày $day của tháng lệch $offset (trong tháng đó). */
    private function sessionOn(int $offset, int $day): ?ClassSession
    {
        return ClassSession::with('classModel')
            ->whereHas('classModel', fn ($q) => $q->where('code', 'like', 'DEMO-%'))
            ->where('status', '!=', 'cancelled')->whereNotNull('start_time')
            ->whereBetween('date', [$this->day($offset, $day)->toDateString(), $this->day($offset, 31)->toDateString()])
            ->orderBy('date')->orderBy('start_time')->first();
    }

    /** Phiếu tháng trước: điền + chốt (Admin duyệt đầu tháng này). Phiếu tháng này: điền phần lớn mục điền tay. */
    private function sheets(): void
    {
        $sheets = app(KpiSheetService::class);
        $last = $this->thisMonth->copy()->subMonthNoOverflow();
        $comment = 'Đánh giá KPI tháng '.$last->format('m/Y').'.';
        $criteria = KpiCriterion::forRole(Roles::ACADEMIC_LEAD)->active()->ordered()->get();

        $previous = KpiEvaluation::firstOrCreate(
            ['user_id' => $this->lead->id, 'month' => $last->month, 'year' => $last->year],
            ['period_months' => 1, 'total_score' => 0, 'status' => KpiEvaluation::STATUS_PENDING]
        );
        // Phiếu do seeder demo chốt (cùng lời nhận xét) thì chấm lại theo nguồn số liệu mới; phiếu người dùng chốt giữ nguyên.
        $ours = $previous->status === KpiEvaluation::STATUS_APPROVED && $previous->comment === $comment;
        if ($previous->status === KpiEvaluation::STATUS_PENDING || $ours) {
            $this->travel($this->thisMonth->copy()->setTime(10, 30)->min($this->realNow->copy()->subMinutes(20)));
            $previous->items()->delete();
            $previous->update(['status' => KpiEvaluation::STATUS_PENDING]);
            $this->fill($previous, $criteria, 0);
            $sheet = $sheets->sheet($this->lead, $last->month, $last->year, $previous->fresh('items'));
            foreach ($sheet['lines'] as $line) {
                KpiEvaluationItem::updateOrCreate(
                    ['kpi_evaluation_id' => $previous->id, 'kpi_criterion_id' => $line['criterion']->id],
                    [
                        'actual' => $line['value'] === null ? null : (string) $line['value'],
                        'score' => $line['level'] ?? 0,
                        'evidence' => $line['auto'] ? $line['evidence'] : null,
                        'not_applicable' => $line['na'],
                    ]
                );
            }
            $previous->update([
                'evaluator_id' => $this->admin->id,
                'total_score' => $sheet['total'],
                'status' => KpiEvaluation::STATUS_APPROVED,
                'comment' => $comment,
                'decided_at' => now(),
            ]);
        }

        Carbon::setTestNow($this->realNow);
        $sheets->ensureSheets($this->thisMonth->month, $this->thisMonth->year);
        $current = KpiEvaluation::where('user_id', $this->lead->id)->where('month', $this->thisMonth->month)->where('year', $this->thisMonth->year)
            ->where('status', KpiEvaluation::STATUS_PENDING)->first();
        if ($current) {
            $this->fill($current, $criteria, 1);
        }
    }

    /** Ghi số liệu điền tay của tiêu chí (khớp đầu tên với MANUAL); $column 0 = tháng trước, 1 = tháng này. */
    private function fill(KpiEvaluation $evaluation, Collection $criteria, int $column): void
    {
        foreach ($criteria as $criterion) {
            if ($criterion->isAuto() && ! $criterion->isRateSource()) {
                continue;
            }
            $value = collect(self::MANUAL)->first(fn ($v, string $prefix) => str_starts_with($criterion->name, $prefix));
            if ($value === null) {
                continue;
            }
            $value = $value[$column];
            $na = $value === 'na' && $criterion->allow_na;
            KpiEvaluationItem::updateOrCreate(
                ['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $criterion->id],
                $na || $value === null
                    ? ['actual' => null, 'score' => 0, 'not_applicable' => $na]
                    : ['actual' => (string) $value, 'score' => $criterion->levelFor((float) $value) ?? 0, 'not_applicable' => false]
            );
        }
    }
}
