<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\Branch;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\User;
use App\Services\StaffAttendance\StaffAttendanceService;
use App\Support\DataScope;
use App\Support\Rbac;
use App\Support\Ui;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chấm công hằng ngày (Người dùng → Chấm công → "Chấm công hằng ngày"): quản lý xem ai đã vào / ra, đi muộn,
 * chưa chấm, nghỉ phép theo ngày; xem tổng hợp theo tháng (đối soát lương). Chi tiết 1 lượt chấm (ảnh khuôn mặt,
 * vị trí, khoảng cách tới cơ sở) mở trong modal. Phạm vi: staff_checkin.scope_branch | scope_all.
 */
class StaffAttendanceController extends Controller
{
    use RendersModals;

    public const STATUSES = [
        'checked_in' => 'Đã chấm vào',
        'late' => 'Đi muộn',
        'missing' => 'Chưa chấm công',
        'leave' => 'Nghỉ có phép',
    ];

    public function __construct(private readonly StaffAttendanceService $attendance) {}

    /** Người xem được 1 lượt chấm công: chính người đó, hoặc người có quyền xem chấm công trong phạm vi chi nhánh. */
    public static function canSee(User $viewer, StaffAttendance $attendance): bool
    {
        return (int) $attendance->user_id === (int) $viewer->id
            || ($viewer->can('staff_checkin.view') && DataScope::coversBranch($viewer, 'staff_checkin', $attendance->branch_id ? (int) $attendance->branch_id : null));
    }

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $filters = $request->validate([
            'view' => ['nullable', 'in:day,month'],
            'date' => ['nullable', 'date'],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::STATUSES))],
            'search' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
        ]);
        $view = $filters['view'] ?? 'day';
        $date = isset($filters['date']) ? Carbon::parse($filters['date'])->startOfDay() : today();
        $month = isset($filters['month']) ? Carbon::createFromFormat('!Y-m', $filters['month']) : today()->startOfMonth();

        $users = $this->staffQuery($viewer, $filters['branch_id'] ?? null, $filters['search'] ?? null)
            ->when($filters['user_id'] ?? null, fn (Builder $q, int $id) => $q->whereKey($id));
        $day = $date->toDateString();
        if ($view === 'day' && ($status = $filters['status'] ?? null)) {
            $users->where(match ($status) {
                'checked_in' => fn (Builder $q) => $q->whereHas('staffAttendances', fn ($a) => $a->whereDate('work_date', $day)->whereNotNull('check_in_at')),
                'late' => fn (Builder $q) => $q->whereHas('staffAttendances', fn ($a) => $a->whereDate('work_date', $day)->where('late_minutes', '>', 0)),
                'leave' => fn (Builder $q) => $q->whereHas('staffAttendanceRequests', fn ($r) => $r->approved()->where('type', StaffAttendanceRequest::TYPE_LEAVE)->overlapping($day, $day)),
                'missing' => fn (Builder $q) => $q->whereDoesntHave('staffAttendances', fn ($a) => $a->whereDate('work_date', $day)->whereNotNull('check_in_at'))
                    ->whereDoesntHave('staffAttendanceRequests', fn ($r) => $r->approved()->where('type', StaffAttendanceRequest::TYPE_LEAVE)->overlapping($day, $day)),
            });
        }
        $page = $users->with('branch:id,name')->orderBy('branch_id')->orderBy('name')
            ->paginate($request->perPage(25))->withQueryString();
        $ids = $page->getCollection()->pluck('id');

        if ($view === 'month') {
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();
            $rows = $page->through(fn (User $u) => [
                'user' => $this->userData($u),
                'summary' => $this->attendance->summary($u->id, $start, $end),
            ]);
        } else {
            $records = StaffAttendance::query()->whereIn('user_id', $ids)->whereDate('work_date', $day)
                ->with('penalty:id,code,status')->get()->keyBy('user_id');
            $leaves = StaffAttendanceRequest::query()->approved()->whereIn('user_id', $ids)
                ->where('type', StaffAttendanceRequest::TYPE_LEAVE)->overlapping($day, $day)->get()->keyBy('user_id');
            $rows = $page->through(function (User $u) use ($records, $leaves) {
                $a = $records->get($u->id);

                return [
                    'user' => $this->userData($u),
                    'attendance' => $a ? [
                        'id' => $a->id,
                        'check_in' => $a->check_in_at?->format('H:i'),
                        'check_out' => $a->check_out_at?->format('H:i'),
                        'check_in_photo' => $a->check_in_photo ? route('staff-attendance.photo', [$a->id, 'in']) : null,
                        'check_in_distance' => $a->check_in_distance,
                        'expected' => trim(substr((string) $a->expected_start, 0, 5).' – '.substr((string) $a->expected_end, 0, 5), ' –'),
                        'status_label' => $a->statusLabel(),
                        'status_tone' => $a->statusTone(),
                        'source' => $a->source,
                        'penalty' => $a->penalty?->code,
                    ] : null,
                    'leave' => $leaves->get($u->id)?->reason,
                ];
            });
        }

        $scopeIds = DataScope::branchIds($viewer, 'staff_checkin');
        $branches = Branch::query()->active()->orderBy('name')
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('id', $scopeIds))
            ->get(['id', 'name', 'latitude', 'longitude']);

        return Inertia::render('StaffAttendance/Index', [
            'view' => $view,
            'date' => $day,
            'month' => $month->format('Y-m'),
            'rows' => $rows,
            'branches' => Ui::options($branches, 'name'),
            'statuses' => Ui::options(self::STATUSES),
            'stats' => $view === 'day' ? $this->dayStats($viewer, $filters['branch_id'] ?? null, $day) : null,
            'unconfigured' => $branches->reject(fn (Branch $b) => $b->hasCheckinLocation())
                ->pluck('name')->values()->all(),
        ]);
    }

    /** Chi tiết 1 ngày công: ảnh vào / ra, vị trí, khoảng cách, giờ phải có mặt, biên bản đi muộn. */
    public function show(Request $request, StaffAttendance $attendance): Response
    {
        abort_unless($request->user()->can('staff_checkin.view') && self::canSee($request->user(), $attendance), 403);
        $attendance->load(['user:id,name,employee_code', 'branch', 'penalty:id,code,status']);
        $branch = $attendance->branch;
        $punch = fn (string $prefix, string $kind) => $attendance->{$prefix.'_at'} ? [
            'time' => $attendance->{$prefix.'_at'}->format('H:i:s'),
            'photo' => $attendance->{$prefix.'_photo'} ? route('staff-attendance.photo', [$attendance->id, $kind]) : null,
            'distance' => $attendance->{$prefix.'_distance'},
            'accuracy' => $attendance->{$prefix.'_accuracy'},
            'map' => $attendance->{$prefix.'_lat'} !== null
                ? 'https://www.google.com/maps/search/?api=1&query='.$attendance->{$prefix.'_lat'}.','.$attendance->{$prefix.'_lng'}
                : null,
        ] : null;

        return $this->modalPage('StaffAttendance/Show', [
            'attendance' => [
                'id' => $attendance->id,
                'user' => $attendance->user?->name,
                'code' => $attendance->user?->employee_code,
                'branch' => $branch?->name,
                'radius' => $branch?->checkin_radius,
                'date' => $attendance->work_date->toDateString(),
                'source' => $attendance->source,
                'expected_start' => $attendance->expected_start ? substr((string) $attendance->expected_start, 0, 5) : null,
                'expected_end' => $attendance->expected_end ? substr((string) $attendance->expected_end, 0, 5) : null,
                'in' => $punch('check_in', StaffAttendanceService::IN),
                'out' => $punch('check_out', StaffAttendanceService::OUT),
                'status_label' => $attendance->statusLabel(),
                'status_tone' => $attendance->statusTone(),
                'note' => $attendance->note,
                'penalty' => $attendance->penalty ? [
                    'code' => $attendance->penalty->code,
                    'url' => route('penalties.index', ['search' => $attendance->penalty->code]),
                ] : null,
            ],
        ]);
    }

    /** Nhân sự (có cổng nhân sự, đang hoạt động) trong phạm vi chi nhánh của người xem. */
    private function staffQuery(User $viewer, ?int $branchId, ?string $search): Builder
    {
        $query = User::query()->where('is_active', true);
        Rbac::scopeUsersWithPermission($query, 'portal.staff');
        DataScope::apply($query, $viewer, 'staff_checkin', null, fn (Builder $q, array $ids) => $q->whereIn('branch_id', $ids));

        return $query
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when($search, fn (Builder $q, string $s) => $q->where(fn (Builder $w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('employee_code', 'like', "%{$s}%")));
    }

    /** @return array{staff: int, checked_in: int, late: int, leave: int} */
    private function dayStats(User $viewer, ?int $branchId, string $day): array
    {
        $ids = $this->staffQuery($viewer, $branchId, null)->pluck('id');
        $records = StaffAttendance::query()->whereIn('user_id', $ids)->whereDate('work_date', $day)->get(['late_minutes', 'late_excused', 'check_in_at']);

        return [
            'staff' => $ids->count(),
            'checked_in' => $records->whereNotNull('check_in_at')->count(),
            'late' => $records->filter(fn (StaffAttendance $a) => $a->isLate())->count(),
            'leave' => StaffAttendanceRequest::query()->approved()->whereIn('user_id', $ids)
                ->where('type', StaffAttendanceRequest::TYPE_LEAVE)->overlapping($day, $day)->distinct()->count('user_id'),
        ];
    }

    /** @return array<string, mixed> */
    private function userData(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'code' => $u->employee_code, 'branch' => $u->branch?->name];
    }
}
