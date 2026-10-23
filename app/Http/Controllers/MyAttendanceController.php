<?php

namespace App\Http\Controllers;

use App\Helpers\AclHelper;
use App\Models\Branch;
use App\Models\PayrollPeriod;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Services\StaffAttendance\StaffAttendanceService;
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
 * Chấm công của nhân sự (/cham-cong): Chấm công (ảnh khuôn mặt + GPS, giờ máy chủ), Lịch sử công và
 * Đơn chấm công & nghỉ (+ lối tắt các yêu cầu khác theo vai trò). Mọi nhân sự (portal.staff) dùng được, trên máy tính
 * lẫn điện thoại. Trước đây là giao diện điện thoại riêng /m (bỏ 14/10/2026, link /m cũ chuyển hướng sang đây).
 */
class MyAttendanceController extends Controller
{
    /** Yêu cầu khác theo vai trò (màn gốc trên máy tính), lọc theo quyền xem route như sidebar. */
    private const ROLE_REQUESTS = [
        ['label' => 'Đề xuất sửa giáo trình', 'route' => 'syllabus.teacher-propose', 'icon' => 'rate_review', 'can' => ['syllabus.propose_adjustment']],
        ['label' => 'Customize syllabus', 'route' => 'syllabus.teacher-adjust', 'icon' => 'event_repeat', 'can' => ['syllabus.propose_adjustment']],
        ['label' => 'Hoàn tiền & khất nợ học phí', 'route' => 'tuition.refunds', 'icon' => 'currency_exchange', 'can' => ['refund_transfer.request']],
        ['label' => 'Yêu cầu hủy hóa đơn', 'route' => 'tuition.invoices.cancellations', 'icon' => 'receipt_long', 'can' => ['invoice.request_cancel']],
        ['label' => 'Nhờ hỗ trợ / giao việc', 'route' => 'tasks.create', 'icon' => 'assignment_add', 'can' => ['work_task.request', 'work_task.create']],
        ['label' => 'Gửi ticket hỗ trợ', 'route' => 'tickets.create', 'icon' => 'confirmation_number', 'can' => ['support_ticket.create']],
    ];

    public function __construct(private readonly StaffAttendanceService $attendance) {}

    /** Chấm công hôm nay. */
    public function home(Request $request): Response
    {
        $user = $request->user()->loadMissing('branch');
        $branch = $user->branch;
        $now = now();
        $today = StaffAttendance::forDay($user->id, $now);
        $expected = $this->attendance->expectedTimes($user, $branch, $now);
        $workBranches = $this->attendance->workBranches($user);
        // Nhiều cơ sở: chỉ khóa khi mọi cơ sở đều không chấm được (máy chủ kiểm tra lại theo cơ sở thực tế lúc bấm).
        $blocks = ($workBranches->isEmpty() ? collect([null]) : $workBranches)
            ->map(fn (?Branch $b) => $this->attendance->punchBlockReason($user, $b, $now));

        return Inertia::render('MyAttendance/CheckIn', [
            'me' => [
                'name' => $user->name,
                'code' => $user->employee_code,
                'role' => ($role = $user->getRoleNames()->first()) ? AclHelper::shortRoleLabel($role) : null,
            ],
            'branch' => $branch ? $this->branchData($branch) : null,
            // Mọi cơ sở làm việc (chính + làm thêm): trang chọn cơ sở gần GPS nhất, máy chủ kiểm tra lại khi chấm.
            'branches' => $workBranches->map(fn (Branch $b) => $this->branchData($b))->values()->all(),
            'expected' => $expected,
            'today' => $today ? $this->attendanceData($today) : null,
            'serverTime' => $now->toIso8601String(),
            'locked' => PayrollPeriod::isLockedFor($now) ? PayrollPeriod::lockedMessage($now) : null,
            // Lý do hôm nay không chấm công (part-time không có buổi dạy / ngày nghỉ), null = chấm được.
            'blocked' => $blocks->contains(null) ? null : $blocks->first(),
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
            return redirect()->route('my-attendance.home')->with('warning', $message.' Bạn đi muộn '.$record->late_minutes.' phút, hệ thống đã lập biên bản chờ giải trình.');
        }

        return redirect()->route('my-attendance.home')->with('success', $message);
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

        return Inertia::render('MyAttendance/History', [
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

        return Inertia::render('MyAttendance/Requests', [
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

        return redirect()->route('my-attendance.requests')->with('success', 'Đã gửi đơn '.$created->typeLabel().', chờ Admin duyệt.');
    }

    public function cancelRequest(Request $request, StaffAttendanceRequest $attendanceRequest): RedirectResponse
    {
        $this->attendance->cancel($request->user(), $attendanceRequest);

        return redirect()->route('my-attendance.requests')->with('success', 'Đã rút đơn.');
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

    /** @return array<string, mixed> dữ liệu 1 ngày công cho màn Chấm công / Lịch sử công */
    /** @return array<string, mixed> */
    private function branchData(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'address' => $branch->address,
            'latitude' => $branch->latitude,
            'longitude' => $branch->longitude,
            'radius' => $branch->checkin_radius ?: Branch::DEFAULT_CHECKIN_RADIUS,
            'grace' => (int) $branch->late_grace_minutes,
            'has_location' => $branch->hasCheckinLocation(),
        ];
    }

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

    private function month(Request $request): Carbon
    {
        $value = (string) $request->query('month');

        return preg_match('/^\d{4}-\d{2}$/', $value) ? Carbon::createFromFormat('!Y-m', $value) : now()->startOfMonth();
    }
}
