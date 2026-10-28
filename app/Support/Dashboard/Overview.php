<?php

namespace App\Support\Dashboard;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CrmCustomer;
use App\Models\CrmTrialBooking;
use App\Models\MiniTestScore;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentTuition;
use App\Models\SupportTicket;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\DataScope;
use App\Support\Money;
use App\Support\Roles;
use App\Support\TuitionBranchScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tổng quan thiết kế lại (chủ dự án 28/10/2026, canvas "Thiết kế lại Tổng quan theo vai trò"): mọi vai trò đọc cùng một
 * thứ tự — lời chào, "Cần bạn xử lý" (một danh sách gộp, quá hạn lên đầu, mỗi dòng có nút làm ngay), khối riêng của vai trò,
 * số liệu tháng. Lớp này chỉ bổ sung các khối mới; số liệu cũ (roleDashboard, teaching, dailyReport) giữ nguyên.
 *
 * Mỗi dòng "Cần bạn xử lý": title, meta, tone (overdue | today | soon | info), when (nhãn thời hạn), href, action (nút).
 */
final class Overview
{
    /** Số dòng "Cần bạn xử lý" hiện trên Tổng quan; phần dư xem ở màn gốc qua link từng dòng. */
    public const TODO_LIMIT = 8;

    /** Số buổi điểm danh bù giáo viên còn làm được (khớp cổng giáo viên). */
    private const MAKEUP_LOOKBACK_DAYS = 30;

    private const TONE_ORDER = ['overdue' => 0, 'today' => 1, 'soon' => 2, 'info' => 3];

    /**
     * @param  Closure(string, array<int, string>=): bool  $canOpen
     */
    public function __construct(
        private readonly User $user,
        private readonly Closure $canOpen,
    ) {}

    /** Loại bố cục: admin | manager | academic | teaching | sales | personal. */
    public function kind(): string
    {
        return match (true) {
            $this->user->can('dashboard.operations') => DataScope::isAll($this->user, 'dashboard') ? 'admin' : 'manager',
            $this->user->can('dashboard.academic') => 'academic',
            $this->isTeachingOnly() => 'teaching',
            $this->user->hasRole(Roles::SALES_CONSULTANT) => 'sales',
            default => 'personal',
        };
    }

    /**
     * @param  array<string, mixed>  $roleDashboard  dữ liệu cũ của DashboardController (queues, overdueTasks, myTasks…)
     * @return array<string, mixed>
     */
    public function build(array $roleDashboard): array
    {
        $kind = $this->kind();
        $operations = in_array($kind, ['admin', 'manager'], true);
        $branchIds = $kind === 'manager' ? $this->user->branchIds() : null;

        return [
            'kind' => $kind,
            'greeting' => $this->greeting(),
            'todo' => $this->todo($kind, $roleDashboard),
            'kpis' => $operations ? $this->operationsKpis($branchIds) : null,
            'alerts' => $operations ? $this->alerts($branchIds) : null,
            'branches' => $kind === 'admin' ? $this->branches() : null,
            'revenueWeeks' => $operations ? $this->revenueWeeks($branchIds) : null,
            'todaySessions' => $this->seesBranchSessions($kind) ? $this->todaySessions($branchIds) : null,
            'funnel' => $kind === 'sales' && $this->seesLeads() ? $this->funnel() : null,
            'shifts' => $kind === 'teaching' ? $this->shifts() : null,
        ];
    }

    /** Chỉ giáo viên / trợ giảng (không kiêm vai trò văn phòng). */
    private function isTeachingOnly(): bool
    {
        $roles = $this->user->getRoleNames();

        return $roles->isNotEmpty() && $roles->diff(Roles::TEACHING)->isEmpty();
    }

    private function isAssistantOnly(): bool
    {
        $roles = $this->user->getRoleNames();

        return $roles->isNotEmpty() && $roles->diff([Roles::ASSISTANT])->isEmpty();
    }

    private function seesLeads(): bool
    {
        return $this->user->can('lead.view') && ($this->canOpen)('crm.customers.index');
    }

    /** Lịch lớp hôm nay của cơ sở: Quản lý cơ sở và người vận hành lớp (Học vụ). */
    private function seesBranchSessions(string $kind): bool
    {
        return $kind === 'manager' || ($kind === 'personal' && $this->user->can('class.update') && ($this->canOpen)('classes.index'));
    }

    /** @return array{title: string, date: string, scope: string} */
    private function greeting(): array
    {
        $hour = (int) now()->format('G');
        $part = match (true) {
            $hour < 11 => 'buổi sáng',
            $hour < 13 => 'buổi trưa',
            $hour < 18 => 'buổi chiều',
            default => 'buổi tối',
        };
        $weekday = ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'][now()->dayOfWeek];

        return [
            'title' => "Chào {$part}, {$this->user->name}",
            'date' => $weekday.', '.now()->format('d/m/Y'),
            'scope' => $this->user->can('dashboard.operations') && DataScope::isAll($this->user, 'dashboard')
                ? 'Toàn hệ thống'
                : ($this->user->branch?->name ?? 'Trung tâm'),
        ];
    }

    // ── Cần bạn xử lý ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $board
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function todo(string $kind, array $board): array
    {
        $items = collect();

        // Hàng chờ đếm sẵn của bảng cũ (phiếu thu, việc chờ xác nhận, đề xuất giáo trình, học phí quá hạn…): mỗi nguồn một dòng.
        foreach ($board['queues'] ?? [] as $queue) {
            if ((int) $queue['value'] <= 0 || str_starts_with((string) $queue['label'], 'Lịch hẹn test')) {
                continue;
            }
            $items->push($this->item(
                title: (string) $queue['label'],
                meta: $queue['hint'] ?? null,
                tone: str_contains(mb_strtolower($queue['label']), 'quá hạn') ? 'overdue' : 'today',
                when: str_contains(mb_strtolower($queue['label']), 'quá hạn') ? 'Quá hạn' : 'Đang chờ',
                href: $queue['href'] ?? null,
                action: $this->queueAction((string) $queue['label']),
                count: (int) $queue['value'],
            ));
        }

        // Học thuật: từng đề xuất giãn tiến độ chờ duyệt.
        foreach ($board['pendingAdjustments'] ?? [] as $req) {
            $items->push($this->item(
                title: 'Giãn tiến độ: '.($req['class'] ?? 'Lớp'),
                meta: collect([$req['teacher'] ?? null, $req['reason'] ?? null])->filter()->implode(' · '),
                tone: 'today',
                when: 'Chờ duyệt',
                href: route('syllabus.adjustment-requests'),
                action: 'Duyệt',
            ));
        }

        // Admin / Quản lý: việc quá hạn của nhân sự (5 việc hạn sớm nhất).
        foreach ($board['overdueTasks'] ?? [] as $task) {
            $items->push($this->item(
                title: $task['title'],
                meta: $task['assignee'] ?? 'Chưa phân công',
                tone: 'overdue',
                when: $task['due_date'] ? 'Hạn '.Carbon::parse($task['due_date'])->format('d/m') : 'Quá hạn',
                href: route('tasks.index', ['tab' => 'assigned', 'status' => 'overdue']),
                action: 'Xem',
            ));
        }

        if (in_array($kind, ['personal', 'sales'], true) && $this->seesLeads()) {
            $items = $items->concat($this->callItems());
        }

        if ($kind === 'teaching') {
            $items = $items->concat($this->teachingItems());
        }

        // Việc được giao cho tôi: quá hạn / đến hạn hôm nay.
        foreach ($board['myTasks']['items'] ?? [] as $task) {
            $dueToday = $task['due'] && str_starts_with($task['due'], today()->format('d/m'));
            if (! $task['overdue'] && ! $dueToday) {
                continue;
            }
            $items->push($this->item(
                title: $task['title'],
                meta: 'Việc của tôi · '.$task['status_label'],
                tone: $task['overdue'] ? 'overdue' : 'today',
                when: $task['overdue'] ? 'Quá hạn '.($task['due'] ?? '') : 'Hôm nay',
                href: $board['myTasks']['url'],
                action: 'Mở',
            ));
        }

        $sorted = $items->sortBy(fn (array $item) => self::TONE_ORDER[$item['tone']])->values();

        return [
            'items' => $sorted->take(self::TODO_LIMIT)->all(),
            'total' => (int) $sorted->sum(fn (array $item) => $item['count'] ?? 1),
        ];
    }

    private function queueAction(string $label): string
    {
        $label = mb_strtolower($label);

        return match (true) {
            str_contains($label, 'phiếu thu'), str_contains($label, 'duyệt'), str_contains($label, 'đề xuất'), str_contains($label, 'order') => 'Duyệt',
            str_contains($label, 'xác nhận') => 'Xác nhận',
            str_contains($label, 'ticket') => 'Xử lý',
            str_contains($label, 'xếp lớp') => 'Xếp lớp',
            str_contains($label, 'liên hệ') => 'Gọi',
            default => 'Mở',
        };
    }

    /** @return array<string, mixed> */
    private function item(string $title, ?string $meta, string $tone, string $when, ?string $href, string $action, ?int $count = null): array
    {
        return compact('title', 'meta', 'tone', 'when', 'href', 'action') + ['count' => $count];
    }

    /** Khách cần gọi lại hôm nay (kể cả đã quá hẹn), theo phạm vi khách của mình. */
    private function callItems(): Collection
    {
        return CrmCustomer::query()->visibleTo($this->user)->followUpDueToday()
            ->orderBy('next_follow_up_at')->limit(6)
            ->get(['id', 'name', 'stage', 'next_follow_up_at'])
            ->map(function (CrmCustomer $c) {
                $overdue = $c->next_follow_up_at->lt(now());

                return $this->item(
                    title: 'Gọi lại: '.$c->name,
                    meta: $c->stage_label,
                    tone: $overdue ? 'overdue' : 'today',
                    when: $overdue
                        ? ($c->next_follow_up_at->isToday() ? 'Trễ hẹn '.$c->next_follow_up_at->format('H:i') : 'Quá hạn '.$c->next_follow_up_at->format('d/m'))
                        : $c->next_follow_up_at->format('H:i'),
                    href: route('crm.customers.show', $c->id),
                    action: 'Gọi',
                );
            });
    }

    /** Giáo viên / trợ giảng: buổi chưa điểm danh, ca đã bắt đầu chưa check-in, khách học thử chờ phản hồi. */
    private function teachingItems(): Collection
    {
        $items = collect();
        $userId = (int) $this->user->id;

        $unattended = ClassSession::query()->forStaff($userId)->with('classModel:id,name')
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', today()->subDays(self::MAKEUP_LOOKBACK_DAYS)->toDateString())
            ->whereDate('date', '<=', today()->toDateString())
            ->whereDoesntHave('attendances')
            ->whereNot(fn ($q) => $q->where('type', ClassSession::TYPE_SUPPORT)->where('status', 'completed'))
            ->orderBy('date')->orderBy('start_time')
            ->get()
            ->filter(fn (ClassSession $s) => $s->startsAt()->lte(now()));
        foreach ($unattended as $session) {
            $items->push($this->item(
                title: 'Điểm danh '.($session->classModel?->name ?? 'buổi học').' · '.$session->start_time?->format('H:i').' '.$session->date->format('d/m'),
                meta: $session->date->isToday() ? 'Buổi hôm nay chưa điểm danh' : 'Điểm danh bù',
                tone: $session->date->isToday() ? 'today' : 'overdue',
                when: $session->date->isToday() ? 'Hôm nay' : 'Quá hạn',
                href: route('teacher.attendance', ['classId' => $session->class_id, 'session' => $session->id]),
                action: 'Điểm danh',
            ));
        }

        $trials = CrmTrialBooking::query()->with(['customer:id,name', 'session:id,date,start_time', 'classModel:id,name'])
            ->whereNull('feedback')->where('status', '!=', 'cancelled')->whereHas('customer')
            ->whereHas('session', fn (Builder $s) => $s->forStaff($userId)
                ->whereDate('date', '>=', today()->subDays(self::MAKEUP_LOOKBACK_DAYS)->toDateString())
                ->whereDate('date', '<=', today()->toDateString()))
            ->get()
            ->filter(fn (CrmTrialBooking $b) => $b->session?->startsAt()->lte(now()));
        foreach ($trials as $booking) {
            $items->push($this->item(
                title: 'Phản hồi khách học thử '.$booking->customer->name,
                meta: collect([$booking->classModel?->name, $booking->session?->date?->format('d/m')])->filter()->implode(' · '),
                tone: 'today',
                when: 'Chờ phản hồi',
                href: route('teacher.trial-guests'),
                action: 'Phản hồi',
            ));
        }

        return $items;
    }

    // ── Admin / Quản lý cơ sở ───────────────────────────────────────────────────────────────────────────────────────

    /** Phiếu thu đã duyệt trong khoảng ngày, theo chi nhánh của học viên. */
    private function revenue(?array $branchIds, Carbon $from, Carbon $to): float
    {
        return (float) TuitionReceipt::query()
            ->where('status', TuitionReceipt::STATUS_APPROVED)
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchIds !== null, fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->whereIn('branch_id', $branchIds)))
            ->sum('amount');
    }

    /**
     * 4 thẻ số liệu tháng của Admin / Quản lý, mỗi thẻ có so sánh.
     *
     * @return list<array<string, mixed>>
     */
    private function operationsKpis(?array $branchIds): array
    {
        $monthStart = now()->startOfMonth();
        $lastStart = $monthStart->copy()->subMonthNoOverflow();
        // So cùng số ngày của tháng trước (tháng này mới qua N ngày).
        $lastSameDay = $lastStart->copy()->addDays(min(now()->day, $lastStart->daysInMonth) - 1)->endOfDay();
        $inBranches = fn (Builder $q) => $branchIds === null ? $q : $q->whereIn('branch_id', $branchIds);

        $revenue = $this->revenue($branchIds, $monthStart, now()->endOfMonth());
        $lastRevenue = $this->revenue($branchIds, $lastStart, $lastSameDay);
        $studying = $inBranches(Student::query()->where('status', 'studying'))->count();
        $newStudents = $inBranches(Student::query()->where('status', 'studying')->where('created_at', '>=', $monthStart))->count();
        $leads = $inBranches(CrmCustomer::query()->where('created_at', '>=', $monthStart))->count();
        $closed = $inBranches(CrmCustomer::query()->where('converted_at', '>=', $monthStart))->count();
        $classes = $inBranches(ClassModel::query()->where('status', 'active'))->count();
        $students = $classes > 0 ? (int) round(ClassModel::rosterStudentsFor($inBranches(ClassModel::query()->where('status', 'active'))->get(['id']))->sum(fn ($r) => $r->count()) / $classes) : 0;

        $change = $lastRevenue > 0 ? (int) round(($revenue - $lastRevenue) / $lastRevenue * 100) : null;

        return [
            [
                'label' => 'Doanh thu tháng '.now()->format('m/Y'),
                'value' => Money::format($revenue),
                'hint' => $change === null ? 'Phiếu thu đã duyệt' : ($change >= 0 ? '+' : '').$change.'% so với cùng kỳ tháng trước',
                'tone' => $change === null ? 'default' : ($change >= 0 ? 'success' : 'error'),
                'href' => ($this->canOpen)('tuition.students') ? route('tuition.students') : null,
            ],
            [
                'label' => 'Học viên đang học',
                'value' => number_format($studying),
                'hint' => $newStudents > 0 ? "+{$newStudents} học viên mới trong tháng" : 'Chưa có học viên mới trong tháng',
                'tone' => 'default',
                'href' => ($this->canOpen)('students.index') ? route('students.index') : null,
            ],
            [
                'label' => 'Lead mới trong tháng',
                'value' => number_format($leads),
                'hint' => "Đã chốt {$closed}".($leads > 0 ? ' · tỉ lệ chốt '.round($closed / $leads * 100).'%' : ''),
                'tone' => 'default',
                'href' => ($this->canOpen)('crm.customers.index') ? route('crm.customers.index') : null,
            ],
            [
                'label' => 'Lớp đang chạy',
                'value' => number_format($classes),
                'hint' => $classes > 0 ? "Sĩ số trung bình {$students}" : null,
                'tone' => 'default',
                'href' => ($this->canOpen)('classes.index') ? route('classes.index') : null,
            ],
        ];
    }

    /**
     * Cảnh báo vận hành: việc quá hạn (người nhiều nhất), học phí quá hạn, ticket chưa ai nhận.
     *
     * @return list<array<string, mixed>>
     */
    private function alerts(?array $branchIds): array
    {
        $overdue = WorkTask::query()->withDeadline()
            ->whereNotIn('status', ['completed', 'canceled'])
            ->where(fn (Builder $q) => $q->where('status', 'overdue')->orWhereDate('due_date', '<', today()))
            ->when($branchIds !== null, fn (Builder $q) => $q->where(fn (Builder $b) => $b->whereIn('branch_id', $branchIds)
                ->orWhereHas('assignee', fn (Builder $a) => $a->whereIn('branch_id', $branchIds))));
        $overdueCount = (clone $overdue)->count();
        $top = (clone $overdue)->whereNotNull('assignee_id')->selectRaw('assignee_id, count(*) as total')
            ->groupBy('assignee_id')->orderByDesc('total')->limit(2)->pluck('total', 'assignee_id');
        $names = User::whereIn('id', $top->keys())->pluck('name', 'id');

        $tuitions = TuitionBranchScope::tuitions(StudentTuition::query()->whereHas('student'), $branchIds)->overdueNow();
        $tuitionCount = (clone $tuitions)->count();
        $tuitionDebt = (float) (clone $tuitions)->sum('debt_amount');

        $unassigned = SupportTicket::query()->where('status', 'open')->whereNull('assignee_id')
            ->when($branchIds !== null, fn (Builder $q) => $q->whereHas('creator', fn (Builder $c) => $c->whereIn('branch_id', $branchIds)))
            ->count();

        return [
            [
                'value' => $overdueCount,
                'tone' => $overdueCount > 0 ? 'overdue' : 'info',
                'title' => 'Việc quá hạn',
                'meta' => $top->isEmpty() ? 'Không có việc quá hạn' : 'Nhiều nhất: '.$top->map(fn ($n, $id) => ($names[$id] ?? '—').' '.$n)->implode(' · '),
                'href' => route('tasks.index', ['tab' => 'assigned', 'status' => 'overdue']),
            ],
            [
                'value' => $tuitionCount,
                'tone' => $tuitionCount > 0 ? 'overdue' : 'info',
                'title' => 'Học phí quá hạn',
                'meta' => $tuitionCount > 0 ? 'Còn nợ '.Money::format($tuitionDebt) : 'Không có khoản quá hạn',
                'href' => ($this->canOpen)('tuition.overdue') ? route('tuition.overdue') : null,
            ],
            [
                'value' => $unassigned,
                'tone' => $unassigned > 0 ? 'today' : 'info',
                'title' => 'Ticket chưa có người xử lý',
                'meta' => $unassigned > 0 ? 'Cần giao người nhận' : 'Ticket nào cũng đã có người nhận',
                'href' => ($this->canOpen)('tickets.index') ? route('tickets.index') : null,
            ],
        ];
    }

    /**
     * So sánh các cơ sở trong tháng (Admin).
     *
     * @return list<array<string, mixed>>
     */
    private function branches(): array
    {
        $monthStart = now()->startOfMonth();
        $branches = Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $ids = $branches->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $revenue = TuitionReceipt::query()->where('tuition_receipts.status', TuitionReceipt::STATUS_APPROVED)
            ->whereBetween('payment_date', [$monthStart->toDateString(), now()->endOfMonth()->toDateString()])
            ->join('students', 'students.id', '=', 'tuition_receipts.student_id')
            ->selectRaw('students.branch_id as branch_id, sum(tuition_receipts.amount) as total')
            ->groupBy('students.branch_id')->pluck('total', 'branch_id');
        $count = fn (Builder $q) => $q->whereIn('branch_id', $ids)->selectRaw('branch_id, count(*) as total')->groupBy('branch_id')->pluck('total', 'branch_id');
        $studying = $count(Student::query()->where('status', 'studying'));
        $leads = $count(CrmCustomer::query()->where('created_at', '>=', $monthStart));
        $closed = $count(CrmCustomer::query()->where('converted_at', '>=', $monthStart));
        $classes = $count(ClassModel::query()->where('status', 'active'));
        $overdue = $count(WorkTask::query()->withDeadline()->whereNotIn('status', ['completed', 'canceled'])
            ->where(fn (Builder $q) => $q->where('status', 'overdue')->orWhereDate('due_date', '<', today())));

        return $branches->map(fn (Branch $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'revenue' => Money::format((float) ($revenue[$b->id] ?? 0)),
            'revenueValue' => (float) ($revenue[$b->id] ?? 0),
            'studying' => (int) ($studying[$b->id] ?? 0),
            'leads' => (int) ($leads[$b->id] ?? 0),
            'closed' => (int) ($closed[$b->id] ?? 0),
            'classes' => (int) ($classes[$b->id] ?? 0),
            'overdue' => (int) ($overdue[$b->id] ?? 0),
        ])->sortByDesc(fn (array $b) => [$b['revenueValue'], $b['studying']])->values()->all();
    }

    /**
     * Doanh thu 5 tuần gần nhất (thứ 2 – chủ nhật); tuần này tính đến hôm nay.
     *
     * @return list<array{label: string, value: float, display: string, current: bool}>
     */
    private function revenueWeeks(?array $branchIds): array
    {
        $thisWeek = now()->startOfWeek(Carbon::MONDAY);

        return collect(range(4, 0))->map(function (int $ago) use ($thisWeek, $branchIds) {
            $start = $thisWeek->copy()->subWeeks($ago);
            $value = $this->revenue($branchIds, $start, $start->copy()->endOfWeek(Carbon::SUNDAY));

            return [
                'label' => $start->format('d/m'),
                'value' => $value,
                'display' => $value >= 1_000_000 ? rtrim(rtrim(number_format($value / 1_000_000, 1, ',', '.'), '0'), ',').' tr' : Money::format($value),
                'current' => $ago === 0,
            ];
        })->all();
    }

    /**
     * Buổi học hôm nay của cơ sở, kèm trạng thái check-in của giáo viên và điểm danh.
     *
     * @return array{items: list<array<string, mixed>>, total: int, notCheckedIn: int, notAttended: int}
     */
    private function todaySessions(?array $branchIds): array
    {
        $sessions = ClassSession::query()
            ->with(['classModel:id,name,code', 'teacher:id,name', 'assistant:id,name'])
            ->whereDate('date', today()->toDateString())
            ->where('status', '!=', 'cancelled')
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $branchIds))
            ->when($branchIds === null, fn (Builder $q) => $q->whereHas('classModel', fn (Builder $c) => $c->visibleTo($this->user)))
            ->orderBy('start_time')
            ->get();
        $ids = $sessions->pluck('id');
        $checkedIn = TeacherTimesheet::query()->whereIn('class_session_id', $ids)->whereNotNull('checkin_time')
            ->get(['class_session_id', 'user_id'])->map(fn ($t) => $t->class_session_id.'-'.$t->user_id)->flip();
        $attended = StudentAttendance::query()->whereIn('class_session_id', $ids)->distinct()->pluck('class_session_id')->flip();

        $items = $sessions->map(function (ClassSession $s) use ($checkedIn, $attended) {
            $start = $s->startsAt();
            $end = Carbon::parse($s->date->format('Y-m-d').' '.($s->end_time?->format('H:i') ?? '23:59'));
            $teacherIn = $s->teacher_id && $checkedIn->has($s->id.'-'.$s->teacher_id);
            $done = $attended->has($s->id);
            [$status, $tone] = match (true) {
                now()->lt($start) => [$start->diffInMinutes(now(), true) <= 30 ? 'Sắp bắt đầu' : 'Sắp tới', 'soon'],
                now()->lte($end) => $teacherIn ? ['Đang học', 'done'] : ['GV chưa check-in', 'overdue'],
                default => $done ? ['Đã điểm danh', 'done'] : ['Chưa điểm danh', 'today'],
            };

            return [
                'id' => $s->id,
                'time' => $s->start_time?->format('H:i').'–'.$s->end_time?->format('H:i'),
                'class' => $s->classModel?->name ?? 'Lớp',
                'meta' => collect([$s->roomLabel(), $s->teacher?->name ? 'GV '.$s->teacher->name : null, $s->assistant?->name ? 'TG '.$s->assistant->name : null])->filter()->implode(' · '),
                'status' => $status,
                'tone' => $tone,
                'href' => route('classes.show', $s->class_id),
            ];
        });

        return [
            'items' => $items->take(12)->values()->all(),
            'total' => $items->count(),
            'notCheckedIn' => $items->where('status', 'GV chưa check-in')->count(),
            'notAttended' => $items->where('status', 'Chưa điểm danh')->count(),
        ];
    }

    /**
     * Phễu khách tạo trong tháng theo bước hiện tại (khách đã qua bước sau được tính cả ở bước trước).
     *
     * @return array{rows: list<array{label: string, value: int}>, lost: int}
     */
    private function funnel(): array
    {
        $stages = array_keys(CrmCustomer::PIPELINE_STAGES);
        $position = array_flip($stages);
        $byStage = CrmCustomer::query()->visibleTo($this->user)->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');
        $atLeast = fn (string $stage) => (int) $byStage->filter(fn ($n, $s) => isset($position[$s]) && $position[$s] >= $position[$stage])->sum();

        return [
            'rows' => [
                ['label' => 'Khách mới', 'value' => (int) $byStage->sum()],
                ['label' => 'Đã tư vấn', 'value' => $atLeast('consulting')],
                ['label' => 'Hẹn test', 'value' => $atLeast('test_scheduled')],
                ['label' => 'Đã test', 'value' => $atLeast('tested')],
                ['label' => 'Đã chốt', 'value' => $atLeast('waiting_class')],
            ],
            'lost' => (int) ($byStage[CrmCustomer::STAGE_LOST] ?? 0),
        ];
    }

    // ── Giáo viên / trợ giảng ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * Ca dạy hôm nay (ca tiếp theo + danh sách), học sinh cần chú ý, lương tạm tính tháng, nhiệm vụ trực lớp hôm nay.
     *
     * @return array<string, mixed>
     */
    private function shifts(): array
    {
        $userId = (int) $this->user->id;
        $sessions = ClassSession::query()->forStaff($userId)->with(['classModel:id,name,code'])
            ->whereDate('date', today()->toDateString())->where('status', '!=', 'cancelled')
            ->orderBy('start_time')->get();
        ClassModel::loadRosterCounts($sessions->pluck('classModel')->filter()->unique('id'));
        $checkins = TeacherTimesheet::query()->where('user_id', $userId)->whereIn('class_session_id', $sessions->pluck('id'))
            ->whereNotNull('checkin_time')->pluck('checkin_time', 'class_session_id');
        $attended = StudentAttendance::query()->whereIn('class_session_id', $sessions->pluck('id'))->distinct()->pluck('class_session_id')->flip();
        $trials = CrmTrialBooking::query()->whereIn('class_session_id', $sessions->pluck('id'))->where('status', '!=', 'cancelled')->whereHas('customer')
            ->selectRaw('class_session_id, count(*) as total')->groupBy('class_session_id')->pluck('total', 'class_session_id');

        $rows = $sessions->map(function (ClassSession $s) use ($checkins, $attended, $trials) {
            $start = $s->startsAt();
            $end = Carbon::parse($s->date->format('Y-m-d').' '.($s->end_time?->format('H:i') ?? '23:59'));
            $done = $attended->has($s->id);
            [$status, $tone] = match (true) {
                now()->gt($end) => $done ? ['Đã dạy', 'done'] : ['Chưa điểm danh', 'overdue'],
                now()->gte($start) => ['Đang dạy', 'today'],
                default => ['Sắp tới', 'soon'],
            };

            return [
                'id' => $s->id,
                'classId' => $s->class_id,
                'start' => $s->start_time?->format('H:i'),
                'time' => $s->start_time?->format('H:i').'–'.$s->end_time?->format('H:i'),
                'class' => $s->classModel?->name ?? 'Lớp',
                'room' => $s->roomLabel(),
                'students' => $s->type === ClassSession::TYPE_SUPPORT ? 1 : (int) $s->classModel?->roster_count,
                'trials' => (int) ($trials[$s->id] ?? 0),
                'checkedIn' => $checkins->has($s->id),
                'checkinTime' => $checkins->has($s->id) ? Carbon::parse($checkins[$s->id])->format('H:i') : null,
                'minutesToStart' => now()->lt($start) ? (int) now()->diffInMinutes($start) : null,
                'ended' => now()->gt($end),
                'status' => $status,
                'tone' => $tone,
                'attendanceUrl' => route('teacher.attendance', ['classId' => $s->class_id, 'session' => $s->id]),
            ];
        });

        $next = $rows->first(fn (array $r) => ! $r['ended']);

        return [
            'next' => $next,
            'today' => $rows->values()->all(),
            'hours' => round($sessions->sum(fn (ClassSession $s) => $s->start_time && $s->end_time ? $s->start_time->diffInMinutes($s->end_time) / 60 : 0), 1),
            'homeUrl' => route('teacher.home'),
            'lessonUrl' => ($this->canOpen)('teacher.lesson-today') ? route('teacher.lesson-today') : null,
            'attention' => $this->attentionStudents(),
            'salary' => $this->salaryEstimate(),
            'taTasks' => $this->isAssistantOnly() || $this->user->hasRole(Roles::ASSISTANT) ? $this->taTasks() : null,
        ];
    }

    /** Học sinh có mini test dưới 7/10 trong 30 ngày qua ở lớp mình dạy. */
    private function attentionStudents(): array
    {
        $userId = (int) $this->user->id;
        $classIds = ClassModel::query()->where(fn ($q) => $q->where('teacher_id', $userId)
            ->orWhere('foreign_teacher_id', $userId)->orWhere('assistant_id', $userId))->pluck('id');

        return MiniTestScore::with(['student:id,name', 'classModel:id,name'])
            ->whereIn('class_id', $classIds)
            ->whereDate('test_date', '>=', now()->subDays(30)->toDateString())
            ->where('max_score', '>', 0)
            ->whereRaw('score * 10 < max_score * 7')
            ->latest('test_date')->limit(5)->get()
            ->map(fn (MiniTestScore $s) => [
                'name' => $s->student?->name ?? 'Học sinh',
                'class' => $s->classModel?->name,
                'why' => ($s->name ? $s->name.': ' : 'Mini test: ').rtrim(rtrim(number_format((float) $s->score, 1, ',', ''), '0'), ',').'/'.rtrim(rtrim(number_format((float) $s->max_score, 1, ',', ''), '0'), ','),
            ])->values()->all();
    }

    /**
     * Lương tạm tính theo giờ dạy đã chấm công trong tháng (như cổng giáo viên). Chưa có đơn giá → amount null, ghi rõ
     * "Chưa có đơn giá" thay vì hiện số giờ ở chỗ số tiền.
     *
     * @return array{amount: ?string, hours: float, missingRate: bool, url: ?string}
     */
    private function salaryEstimate(): array
    {
        $timesheets = TeacherTimesheet::query()->where('user_id', $this->user->id)
            ->whereBetween('teaching_date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->where('status', '!=', 'rejected')->get(['teaching_date', 'hours']);
        $amount = null;
        $missing = false;
        foreach ($timesheets as $ts) {
            $rate = TeacherHourlyRate::rateFor($this->user->id, $ts->teaching_date);
            $rate === null ? $missing = true : $amount = ($amount ?? 0) + $rate * (float) $ts->hours;
        }

        return [
            // Chưa dạy giờ nào: 0 đ; có giờ mà chưa có đơn giá: null (giao diện ghi "Chưa có đơn giá").
            'amount' => $amount === null ? ($timesheets->isEmpty() ? Money::format(0) : null) : Money::format($amount),
            'hours' => round((float) $timesheets->sum('hours'), 1),
            'missingRate' => $missing || ($amount === null && $timesheets->isNotEmpty()),
            'url' => ($this->canOpen)('portal.my-salary') ? route('portal.my-salary') : null,
        ];
    }

    /** Nhiệm vụ trực lớp hôm nay của trợ giảng: số đã xong / tổng. */
    private function taTasks(): array
    {
        $tasks = WorkTask::query()->where('assignee_id', $this->user->id)->whereDate('due_date', today()->toDateString())->get(['status']);

        return [
            'done' => $tasks->whereIn('status', ['completed', 'pending_confirmation'])->count(),
            'total' => $tasks->count(),
            'url' => route('portal.ta-tasks'),
        ];
    }
}
