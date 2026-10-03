<?php

namespace App\Support\Dashboard;

use App\Models\BigTest;
use App\Models\CrmCustomer;
use App\Models\CrmTrialBooking;
use App\Models\PayrollPeriod;
use App\Models\StudentTuition;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\Approvals\ApprovalInboxService;
use App\Support\DataScope;
use App\Support\TuitionBranchScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tổng quan cá nhân cho mọi vai trò trừ Admin / Quản lý cơ sở (chủ dự án 03/10/2026: Admin xem nhiều số liệu, các vai trò
 * khác chủ yếu thấy lịch hẹn và đầu việc cần xử lý trong tuần / tháng). Mỗi khối chỉ có khi user có quyền tương ứng và
 * mở được màn đích; số liệu theo phạm vi dữ liệu của user (chi nhánh / khách mình phụ trách / việc giao cho mình).
 *  - Lịch hẹn 7 ngày tới: hẹn test đầu vào, học thử, hẹn gọi lại khách (CRM); Big Test (Học thuật).
 *  - Việc cần xử lý: số đếm + link (khách quá hạn gọi lại, chưa liên hệ >24h, chờ xếp lớp, mục chờ duyệt theo nguồn,
 *    học phí quá hạn, kỳ lương chưa chốt).
 *  - Việc của tôi: việc được giao chưa xong, hạn trong tuần này hoặc đã quá hạn.
 */
final class MyWorkBoard
{
    public const AGENDA_DAYS = 7;

    /** Số mục lịch hẹn tối đa (cả 7 ngày); phần dư hiện "+N lịch nữa". */
    private const AGENDA_LIMIT = 40;

    private const OPEN_TASK_STATUSES = ['new', 'in_progress', 'overdue', 'blocked'];

    /**
     * @param  Closure(string, array<int, string>=): bool  $canOpen  user mở được route (như menu trái)
     */
    public function __construct(
        private readonly User $user,
        private readonly Closure $canOpen,
        private readonly ApprovalInboxService $approvals,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'type' => 'personal',
            'title' => 'Việc của bạn',
            'scope' => $this->scopeLabel(),
            'updatedAt' => now()->format('H:i d/m/Y'),
            'stats' => $this->stats(),
            'agenda' => $this->hasAgenda() ? $this->agenda() : null,
            'queues' => $this->queues(),
            'myTasks' => $this->myTasks(),
        ];
    }

    /** Khối "Lịch hẹn 7 ngày tới" (Big Test + hẹn test, không gồm gọi lại / học thử của CRM) + "Việc của tôi" cho bảng Học thuật. */
    public function agendaAndTasks(): array
    {
        return ['agenda' => $this->agenda(withCrmCare: false), 'myTasks' => $this->myTasks()];
    }

    /** Vai trò không có nguồn lịch hẹn nào (vd Kế toán) thì không hiện khối lịch. */
    private function hasAgenda(): bool
    {
        return $this->seesLeads() || $this->seesBigTests();
    }

    private function seesBigTests(): bool
    {
        return $this->user->can('dashboard.academic') && ($this->canOpen)('syllabus.big-tests.schedules');
    }

    private function scopeLabel(): string
    {
        return match (true) {
            $this->seesLeads() && DataScope::level($this->user, 'lead') === DataScope::OWN => 'Khách và việc bạn phụ trách',
            DataScope::isAll($this->user, 'lead') => 'Toàn hệ thống',
            default => $this->user->branch?->name ?? 'Chi nhánh của bạn',
        };
    }

    private function seesLeads(): bool
    {
        return $this->user->can('lead.view') && ($this->canOpen)('crm.customers.index');
    }

    private function seesTasks(): bool
    {
        return ($this->canOpen)('tasks.index');
    }

    private function customers(): Builder
    {
        return CrmCustomer::query()->visibleTo($this->user);
    }

    private function agendaEnd(): Carbon
    {
        return today()->addDays(self::AGENDA_DAYS - 1)->endOfDay();
    }

    /** @return list<array<string, mixed>> */
    private function stats(): array
    {
        $stats = [];

        if ($this->seesLeads()) {
            $testToday = $this->customers()->testToday();
            $testCount = (clone $testToday)->count();
            $pending = (clone $testToday)->where('stage', 'test_scheduled')->count();
            $stats[] = ['label' => 'Hẹn test hôm nay', 'value' => number_format($testCount), 'icon' => 'event', 'tone' => $testCount > 0 ? 'primary' : 'default', 'hint' => $testCount > 0 ? "{$pending} chưa làm bài" : null, 'href' => route('crm.customers.index', ['test_today' => 1])];

            $trials = $this->trialBookings()->count();
            $stats[] = ['label' => 'Học thử 7 ngày tới', 'value' => number_format($trials), 'icon' => 'co_present', 'tone' => $trials > 0 ? 'secondary' : 'default', 'hint' => null, 'href' => null];

            $calls = $this->customers()->followUpDueToday();
            $callCount = (clone $calls)->count();
            $callOverdue = (clone $calls)->where('next_follow_up_at', '<', now())->count();
            $stats[] = ['label' => 'Cần gọi lại hôm nay', 'value' => number_format($callCount), 'icon' => 'call', 'tone' => $callOverdue > 0 ? 'error' : ($callCount > 0 ? 'warning' : 'default'), 'hint' => $callOverdue > 0 ? "{$callOverdue} khách đã quá hạn" : null, 'href' => route('crm.customers.index', ['follow_up' => 1])];
        }

        if ($this->seesTasks()) {
            $weekTasks = $this->openTasks()->whereNotNull('due_date')->whereDate('due_date', '<=', today()->endOfWeek()->toDateString());
            $count = (clone $weekTasks)->count();
            $overdue = (clone $weekTasks)->whereDate('due_date', '<', today()->toDateString())->count();
            $stats[] = ['label' => 'Việc của tôi đến hạn tuần này', 'value' => number_format($count), 'icon' => 'task_alt', 'tone' => $overdue > 0 ? 'error' : 'default', 'hint' => $overdue > 0 ? "{$overdue} việc đã quá hạn" : null, 'href' => route('tasks.index', ['tab' => 'mine'])];
        }

        if ($this->approvals->canView($this->user)) {
            $total = array_sum($this->approvals->counts($this->user));
            $stats[] = ['label' => 'Chờ bạn duyệt', 'value' => number_format($total), 'icon' => 'approval', 'tone' => $total > 0 ? 'warning' : 'default', 'hint' => null, 'href' => route('approvals.index')];
        }

        if ($this->seesLeads()) {
            $closed = $this->customers()->whereNotNull('converted_at')->whereBetween('converted_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
            $stats[] = ['label' => 'Khách chốt tháng '.now()->format('m/Y'), 'value' => number_format($closed), 'icon' => 'how_to_reg', 'tone' => 'success', 'hint' => null, 'href' => null];
        }

        if ($this->user->can('tuition.view') && ($this->canOpen)('tuition.overdue')) {
            $overdue = $this->tuitions()->overdueNow()->count();
            $stats[] = ['label' => 'Học phí quá hạn', 'value' => number_format($overdue), 'icon' => 'receipt_long', 'tone' => $overdue > 0 ? 'error' : 'default', 'hint' => null, 'href' => route('tuition.overdue')];
        }

        return array_slice($stats, 0, 5);
    }

    /**
     * Lịch hẹn từ hôm nay đến hết ngày thứ 7, nhóm theo ngày.
     *
     * @param  bool  $withCrmCare  gồm học thử + hẹn gọi lại (việc chăm sóc khách của Học vụ / Sales)
     * @return array{days: list<array<string, mixed>>, total: int, more: int, range: string}
     */
    private function agenda(bool $withCrmCare = true): array
    {
        $items = collect();
        $end = $this->agendaEnd();

        if ($this->seesLeads()) {
            $customerUrl = fn (CrmCustomer $c) => route('crm.customers.show', $c->id);

            $this->customers()->with('assignedUser:id,name')
                ->where('stage', '!=', CrmCustomer::STAGE_LOST)
                ->whereBetween('appointment_at', [today(), $end])
                ->get()
                ->each(fn (CrmCustomer $c) => $items->push([
                    'at' => $c->appointment_at,
                    'kind' => 'test',
                    'kindLabel' => 'Test đầu vào',
                    'title' => $c->name,
                    'subtitle' => collect([$c->appointment_type === 'online' ? 'Online' : 'Tại cơ sở', $c->stage === 'test_scheduled' ? 'Chưa làm bài' : $c->stage_label, $c->assignedUser?->name])->filter()->implode(' · '),
                    'href' => $customerUrl($c),
                ]));

            if ($withCrmCare) {
                $this->trialBookings()->with(['customer:id,name', 'session:id,date,start_time,end_time', 'classModel:id,name'])->get()
                    ->each(function (CrmTrialBooking $booking) use ($items) {
                        $session = $booking->session;
                        // start_time / end_time cast datetime (ngày = hôm nay) → chỉ lấy giờ.
                        $startTime = $session->start_time?->format('H:i');
                        $endTime = $session->end_time?->format('H:i');
                        $items->push([
                            'at' => Carbon::parse($session->date->toDateString().' '.($startTime ?? '00:00')),
                            'kind' => 'trial',
                            'kindLabel' => 'Học thử',
                            'title' => $booking->customer?->name ?? 'Khách học thử',
                            'subtitle' => collect([$booking->classModel?->name, $startTime && $endTime ? "{$startTime}–{$endTime}" : null])->filter()->implode(' · '),
                            'href' => $booking->customer ? route('crm.customers.show', $booking->customer_id) : null,
                        ]);
                    });

                $this->customers()->whereIn('stage', CrmCustomer::ACTIVE_STAGES)
                    ->whereBetween('next_follow_up_at', [today(), $end])
                    ->get()
                    ->each(fn (CrmCustomer $c) => $items->push([
                        'at' => $c->next_follow_up_at,
                        'kind' => 'call',
                        'kindLabel' => 'Gọi lại',
                        'title' => $c->name,
                        'subtitle' => $c->stage_label,
                        'href' => $customerUrl($c),
                    ]));
            }
        }

        if ($this->seesBigTests()) {
            BigTest::query()->with('classModel:id,name')
                ->whereBetween('scheduled_at', [today(), $end])
                ->get()
                ->each(fn (BigTest $test) => $items->push([
                    'at' => $test->scheduled_at,
                    'kind' => 'bigtest',
                    'kindLabel' => 'Big Test',
                    'title' => $test->classModel?->name ?? ($test->title ?? $test->code),
                    'subtitle' => $test->title ?? $test->code,
                    'href' => route('syllabus.big-tests.schedules'),
                ]));
        }

        $sorted = $items->sortBy(fn (array $item) => $item['at']->timestamp)->values();
        $shown = $sorted->take(self::AGENDA_LIMIT);

        return [
            'days' => $shown->groupBy(fn (array $item) => $item['at']->toDateString())
                ->map(fn (Collection $dayItems, string $date) => [
                    'date' => $date,
                    'label' => $this->dayLabel(Carbon::parse($date)),
                    'items' => $dayItems->map(fn (array $item) => [
                        'time' => $item['at']->format('H:i') === '00:00' ? null : $item['at']->format('H:i'),
                        'past' => $item['at']->isPast(),
                    ] + collect($item)->except('at')->all())->values()->all(),
                ])->values()->all(),
            'total' => $sorted->count(),
            'more' => max(0, $sorted->count() - $shown->count()),
            'range' => today()->format('d/m').' – '.$end->format('d/m'),
        ];
    }

    private function dayLabel(Carbon $day): string
    {
        $weekday = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'][$day->dayOfWeek];

        return match (true) {
            $day->isToday() => 'Hôm nay · '.$day->format('d/m'),
            $day->isTomorrow() => 'Ngày mai · '.$day->format('d/m'),
            default => $weekday.' · '.$day->format('d/m'),
        };
    }

    /** Học thử đã hẹn (chưa diễn ra / chưa điểm danh) trong 7 ngày tới, theo phạm vi khách CRM. */
    private function trialBookings(): Builder
    {
        return CrmTrialBooking::query()
            ->where('status', 'scheduled')
            ->whereHas('customer', fn (Builder $c) => $c->visibleTo($this->user))
            ->whereHas('session', fn (Builder $s) => $s->whereDate('date', '>=', today()->toDateString())->whereDate('date', '<=', $this->agendaEnd()->toDateString()));
    }

    private function openTasks(): Builder
    {
        return WorkTask::query()->where('assignee_id', $this->user->id)->whereIn('status', self::OPEN_TASK_STATUSES);
    }

    private function tuitions(): Builder
    {
        return TuitionBranchScope::tuitions(StudentTuition::query()->whereHas('student'), TuitionBranchScope::branchIds($this->user));
    }

    /** @return list<array<string, mixed>> */
    private function queues(): array
    {
        $queues = [];

        if ($this->seesLeads()) {
            $queues[] = ['label' => 'Khách chưa liên hệ >24h', 'value' => $this->customers()->staleNew()->count(), 'icon' => 'person_alert', 'href' => route('crm.customers.index', ['sla' => 1])];
            if ($this->user->can('class.update') && ($this->canOpen)('crm.waiting-list')) {
                $queues[] = ['label' => 'Khách chờ xếp lớp', 'value' => $this->customers()->where('stage', 'waiting_class')->count(), 'icon' => 'event_seat', 'href' => route('crm.waiting-list')];
            }
        }

        if ($this->approvals->canView($this->user)) {
            $counts = $this->approvals->counts($this->user);
            foreach ($this->approvals->visibleSources($this->user) as $key => $source) {
                if (($counts[$key] ?? 0) > 0) {
                    $queues[] = ['label' => 'Duyệt: '.$source->label(), 'value' => $counts[$key], 'icon' => 'approval', 'href' => $source->indexUrl()];
                }
            }
        }

        if ($this->user->can('tuition.view') && ($this->canOpen)('tuition.overdue')) {
            $queues[] = ['label' => 'Học phí quá hạn', 'value' => $this->tuitions()->overdueNow()->count(), 'icon' => 'receipt_long', 'href' => route('tuition.overdue')];
        }

        if (($this->user->can('payroll.calculate') || $this->user->can('payroll.approve')) && ($this->canOpen)('payroll.periods.index')) {
            $queues[] = ['label' => 'Kỳ lương chưa chốt', 'value' => PayrollPeriod::query()->whereNotIn('status', PayrollPeriod::LOCKED_STATUSES)->count(), 'icon' => 'payments', 'href' => route('payroll.periods.index')];
        }

        // Mục còn việc lên trước.
        return collect($queues)->sortByDesc(fn (array $queue) => $queue['value'] > 0)->values()->all();
    }

    /** @return array{items: list<array<string, mixed>>, total: int, url: string|null} */
    private function myTasks(): array
    {
        if (! $this->seesTasks()) {
            return ['items' => [], 'total' => 0, 'url' => null];
        }

        $query = $this->openTasks()->where(fn (Builder $q) => $q->whereNull('due_date')->orWhereDate('due_date', '<=', today()->endOfWeek()->toDateString()));
        $tasks = (clone $query)->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('due_time')->limit(6)->get();

        return [
            'items' => $tasks->map(fn (WorkTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'status_label' => $task->status_label,
                'due' => $task->due_date ? $task->due_date->format('d/m').($task->due_time ? ' '.substr($task->due_time, 0, 5) : '') : null,
                'overdue' => $task->status === 'overdue' || ($task->due_date && $task->due_date->lt(today())),
            ])->all(),
            'total' => (clone $query)->count(),
            'url' => route('tasks.index', ['tab' => 'mine']),
        ];
    }
}
