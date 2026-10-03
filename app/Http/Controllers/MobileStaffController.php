<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Models\Branch;
use App\Models\PayrollPeriod;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\User;
use App\Services\StaffAttendance\StaffAttendanceService;
use App\Support\Approvals\ApprovalInboxService;
use App\Support\Approvals\ApprovalItem;
use App\Support\Navigation\SidebarMenu;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Giao diện điện thoại cho nhân sự (/m): Chấm công (ảnh khuôn mặt + GPS, giờ máy chủ), Lịch sử công,
 * Xin duyệt (đơn chấm công / nghỉ + các yêu cầu theo vai trò) và Cần duyệt (hộp Việc cần duyệt, gọn cho màn nhỏ).
 * Mọi nhân sự (portal.staff) dùng được; quyền duyệt theo từng nguồn duyệt như trên máy tính.
 */
class MobileStaffController extends Controller
{
    /** Yêu cầu khác theo vai trò (màn gốc trên máy tính), lọc theo quyền xem route như sidebar. */
    private const ROLE_REQUESTS = [
        ['label' => 'Đề xuất sửa giáo trình', 'route' => 'syllabus.teacher-propose', 'icon' => 'rate_review', 'can' => ['syllabus.propose_adjustment']],
        ['label' => 'Xin điều chỉnh tiến độ', 'route' => 'syllabus.teacher-adjust', 'icon' => 'event_repeat', 'can' => ['syllabus.propose_adjustment']],
        ['label' => 'Hoàn tiền & khất nợ học phí', 'route' => 'tuition.refunds', 'icon' => 'currency_exchange', 'can' => ['refund_transfer.request']],
        ['label' => 'Yêu cầu hủy hóa đơn', 'route' => 'tuition.invoices.cancellations', 'icon' => 'receipt_long', 'can' => ['invoice.request_cancel']],
        ['label' => 'Nhờ hỗ trợ / giao việc', 'route' => 'tasks.create', 'icon' => 'assignment_add', 'can' => ['work_task.request', 'work_task.create']],
        ['label' => 'Gửi ticket hỗ trợ', 'route' => 'tickets.create', 'icon' => 'confirmation_number', 'can' => ['support_ticket.create']],
    ];

    public function __construct(
        private readonly StaffAttendanceService $attendance,
        private readonly ApprovalInboxService $inbox,
    ) {}

    /** Chấm công hôm nay. */
    public function home(Request $request): Response
    {
        $user = $request->user()->loadMissing('branch');
        $branch = $user->branch;
        $now = now();
        $today = StaffAttendance::forDay($user->id, $now);
        $expected = $this->attendance->expectedTimes($user, $branch, $now);

        return Inertia::render('Mobile/CheckIn', [
            ...$this->nav($user),
            'me' => [
                'name' => $user->name,
                'code' => $user->employee_code,
                'role' => ($role = $user->getRoleNames()->first()) ? AclHelper::shortRoleLabel($role) : null,
            ],
            'branch' => $branch ? [
                'name' => $branch->name,
                'address' => $branch->address,
                'latitude' => $branch->latitude,
                'longitude' => $branch->longitude,
                'radius' => $branch->checkin_radius ?: Branch::DEFAULT_CHECKIN_RADIUS,
                'grace' => (int) $branch->late_grace_minutes,
                'has_location' => $branch->hasCheckinLocation(),
            ] : null,
            'expected' => $expected,
            'today' => $today ? $this->attendanceData($today) : null,
            'serverTime' => $now->toIso8601String(),
            'locked' => PayrollPeriod::isLockedFor($now) ? PayrollPeriod::lockedMessage($now) : null,
        ]);
    }

    public function punch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([StaffAttendanceService::IN, StaffAttendanceService::OUT])],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:6144'],
        ], [
            'latitude.required' => 'Chưa lấy được vị trí GPS của điện thoại. Hãy bật định vị và cho phép trình duyệt truy cập vị trí.',
            'longitude.required' => 'Chưa lấy được vị trí GPS của điện thoại.',
            'photo.required' => 'Hãy chụp ảnh khuôn mặt trước khi chấm công.',
            'photo.image' => 'Ảnh chụp không hợp lệ, hãy chụp lại.',
            'photo.max' => 'Ảnh quá lớn (tối đa 6 MB), hãy chụp lại.',
        ]);

        $record = $this->attendance->punch(
            $request->user(),
            $validated['kind'],
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
            $request->file('photo'),
        );

        $in = $validated['kind'] === StaffAttendanceService::IN;
        $time = ($in ? $record->check_in_at : $record->check_out_at)->format('H:i');
        $message = ($in ? 'Đã chấm công vào lúc ' : 'Đã chấm công ra lúc ').$time.'.';
        if ($in && $record->isLate()) {
            return redirect()->route('mobile.home')->with('warning', $message.' Bạn đi muộn '.$record->late_minutes.' phút, hệ thống đã lập biên bản chờ giải trình.');
        }

        return redirect()->route('mobile.home')->with('success', $message);
    }

    /** Lịch sử công theo tháng của chính mình. */
    public function history(Request $request): Response
    {
        $user = $request->user();
        $month = $this->month($request);
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $rows = StaffAttendance::query()->where('user_id', $user->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->with('penalty:id,code,status')
            ->orderByDesc('work_date')->get();
        $leaves = StaffAttendanceRequest::query()->approved()->where('user_id', $user->id)
            ->where('type', StaffAttendanceRequest::TYPE_LEAVE)
            ->overlapping($start->toDateString(), $end->toDateString())
            ->orderByDesc('date_from')->get();

        return Inertia::render('Mobile/History', [
            ...$this->nav($user),
            'month' => $month->format('Y-m'),
            'monthLabel' => 'Tháng '.$month->format('m/Y'),
            'prevMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->lte(now()->startOfMonth()) ? $month->copy()->addMonth()->format('Y-m') : null,
            'summary' => $this->attendance->summary($user->id, $start, $end),
            'rows' => $rows->map(fn (StaffAttendance $a) => $this->attendanceData($a))->values()->all(),
            'leaves' => $leaves->map(fn (StaffAttendanceRequest $r) => ['id' => $r->id, 'period' => $r->periodLabel(), 'reason' => $r->reason])->values()->all(),
        ]);
    }

    /** Xin duyệt: đơn chấm công / nghỉ của tôi + yêu cầu khác theo vai trò. */
    public function requests(Request $request, SidebarMenu $menu): Response
    {
        $user = $request->user();
        $mine = StaffAttendanceRequest::query()->where('user_id', $user->id)
            ->with('reviewer:id,name')
            ->latest()->limit(30)->get();

        return Inertia::render('Mobile/Requests', [
            ...$this->nav($user),
            'types' => collect(StaffAttendanceRequest::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'today' => now()->toDateString(),
            'requests' => $mine->map(fn (StaffAttendanceRequest $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'type_label' => $r->typeLabel(),
                'period' => $r->periodLabel(),
                'reason' => $r->reason,
                'status' => $r->status,
                'status_label' => $r->statusLabel(),
                'status_tone' => $r->statusTone(),
                'reviewer' => $r->reviewer?->name,
                'rejection_reason' => $r->rejection_reason,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all(),
            'roleRequests' => collect(self::ROLE_REQUESTS)
                ->filter(fn (array $link) => $menu->canSee($user, $link, $request))
                ->map(fn (array $link) => ['label' => $link['label'], 'icon' => $link['icon'], 'url' => route($link['route'])])
                ->values()->all(),
        ]);
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(StaffAttendanceRequest::TYPES))],
            'date_from' => ['required', 'date'],
            'date_to' => ['nullable', 'date'],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'date_from.required' => 'Chọn ngày.',
            'reason.required' => 'Nhập lý do.',
        ]);

        $created = $this->attendance->submit($request->user(), $validated);

        return redirect()->route('mobile.requests')->with('success', 'Đã gửi đơn '.$created->typeLabel().', chờ quản lý duyệt.');
    }

    public function cancelRequest(Request $request, StaffAttendanceRequest $attendanceRequest): RedirectResponse
    {
        $this->attendance->cancel($request->user(), $attendanceRequest);

        return redirect()->route('mobile.requests')->with('success', 'Đã rút đơn.');
    }

    /** Cần duyệt: mọi mục chờ user duyệt (gom từ các nguồn duyệt), bấm mở chi tiết + Duyệt / Từ chối. */
    public function approvals(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->inbox->canView($user), 403, 'Bạn không có quyền duyệt mục nào.');

        return Inertia::render('Mobile/Approvals', [
            ...$this->nav($user),
            'sections' => array_map(fn (array $section) => [
                'key' => $section['source']->key(),
                'label' => $section['source']->label(),
                'count' => $section['count'],
                'indexUrl' => $section['source']->indexUrl(),
                'items' => $section['items']->map(fn (ApprovalItem $item) => [
                    'ref' => $item->ref(),
                    'title' => $item->title,
                    'subtitle' => $item->subtitle,
                    'amount' => $item->amount,
                    'created_ago' => $item->createdAt?->diffForHumans(),
                    'url' => route('approvals.show', [$item->source, $item->id]),
                ])->values()->all(),
            ], array_values(array_filter($this->inbox->sections($user, null, 20), fn (array $s) => $s['count'] > 0))),
        ]);
    }

    /** Ảnh khuôn mặt lúc chấm công: chính người chấm, hoặc người xem chấm công trong phạm vi chi nhánh. */
    public function photo(Request $request, StaffAttendance $attendance, string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, [StaffAttendanceService::IN, StaffAttendanceService::OUT], true), 404);
        abort_unless(StaffAttendanceController::canSee($request->user(), $attendance), 403);
        $path = $kind === StaffAttendanceService::IN ? $attendance->check_in_photo : $attendance->check_out_photo;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** @return array<string, mixed> dữ liệu 1 ngày công cho màn điện thoại */
    private function attendanceData(StaffAttendance $a): array
    {
        return [
            'id' => $a->id,
            'date' => $a->work_date->toDateString(),
            'check_in' => $a->check_in_at?->format('H:i'),
            'check_out' => $a->check_out_at?->format('H:i'),
            'check_in_photo' => $a->check_in_photo ? route('staff-attendance.photo', [$a->id, 'in']) : null,
            'check_out_photo' => $a->check_out_photo ? route('staff-attendance.photo', [$a->id, 'out']) : null,
            'check_in_distance' => $a->check_in_distance,
            'expected_start' => $a->expected_start ? substr((string) $a->expected_start, 0, 5) : null,
            'expected_end' => $a->expected_end ? substr((string) $a->expected_end, 0, 5) : null,
            'late_minutes' => $a->late_minutes,
            'early_minutes' => $a->early_minutes,
            'late_excused' => $a->late_excused,
            'status_label' => $a->statusLabel(),
            'status_tone' => $a->statusTone(),
            'source' => $a->source,
            'penalty' => $a->relationLoaded('penalty') && $a->penalty ? ['code' => $a->penalty->code, 'status' => $a->penalty->status] : null,
        ];
    }

    /** @return array{mobileNav: array<string, mixed>} số đếm trên thanh điều hướng dưới */
    private function nav(User $user): array
    {
        return ['mobileNav' => [
            'approvals' => $this->inbox->badge($user),
            'myPending' => StaffAttendanceRequest::query()->pending()->where('user_id', $user->id)->count(),
        ]];
    }

    private function month(Request $request): Carbon
    {
        $value = (string) $request->query('month');

        return preg_match('/^\d{4}-\d{2}$/', $value) ? Carbon::createFromFormat('!Y-m', $value) : now()->startOfMonth();
    }
}
