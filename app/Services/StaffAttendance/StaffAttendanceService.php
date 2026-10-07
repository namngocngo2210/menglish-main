<?php

namespace App\Services\StaffAttendance;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassSession;
use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\User;
use App\Support\DataScope;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Chấm công hằng ngày trên điện thoại + đơn xin duyệt về chấm công.
 *
 * Quy tắc:
 *  - Giờ vào / ra = giờ máy chủ lúc bấm (không nhận giờ từ điện thoại).
 *  - Chỉ chấm tại cơ sở làm việc chính của nhân sự (users.branch_id), GPS phải nằm trong bán kính cơ sở đã cài.
 *  - Giờ phải có mặt: GV / TA / GVNN theo giờ lớp được phân công trong ngày — buổi đầu tiên (Ca 1 18:00 hay lớp lệch
 *    18:10…, khung giờ ca dạy), không theo một giờ chung cố định; không có buổi → không tính muộn;
 *    nhân sự khác theo giờ làm việc của cơ sở. Muộn quá số phút cho phép của cơ sở → tự lập biên bản "chờ giải trình",
 *    0đ (người chốt quyết mức phạt, giống biên bản SLA); đơn xin đi muộn được duyệt → không tính lỗi, biên bản tự hủy.
 *  - Ngày thuộc kỳ lương đã chốt thì không chấm / không sửa được nữa.
 */
class StaffAttendanceService
{
    public const IN = 'in';

    public const OUT = 'out';

    /** Đơn xin nghỉ tối đa bao nhiêu ngày một lần. */
    public const MAX_LEAVE_DAYS = 31;

    /**
     * Chấm công vào / ra cho chính mình.
     *
     * @throws ValidationException
     */
    public function punch(User $user, string $kind, float $latitude, float $longitude, ?float $accuracy, UploadedFile $photo): StaffAttendance
    {
        $now = now();
        $branch = $this->ensureCanPunchAt($user, $now);
        $distance = $branch->distanceTo($latitude, $longitude);
        $radius = $branch->checkin_radius ?: Branch::DEFAULT_CHECKIN_RADIUS;
        if ($distance > $radius) {
            throw ValidationException::withMessages([
                'location' => "Bạn đang cách {$branch->name} khoảng ".number_format($distance, 0, ',', '.')." m, chỉ chấm công được trong bán kính {$radius} m quanh cơ sở.",
            ]);
        }

        $existing = StaffAttendance::forDay($user->id, $now);
        if ($kind === self::IN && $existing?->check_in_at) {
            throw ValidationException::withMessages(['kind' => 'Hôm nay bạn đã chấm công vào lúc '.$existing->check_in_at->format('H:i').'.']);
        }
        if ($kind === self::OUT && ! $existing?->check_in_at) {
            throw ValidationException::withMessages(['kind' => 'Hôm nay bạn chưa chấm công vào. Nếu quên chấm vào, hãy gửi đơn "Bổ sung công".']);
        }

        $path = $photo->store(StaffAttendance::PHOTO_DIR.'/'.$now->format('Y/m'), 'local');
        $prefix = $kind === self::IN ? 'check_in' : 'check_out';
        $oldPhoto = $existing?->{$prefix.'_photo'};

        $attendance = DB::transaction(function () use ($user, $branch, $now, $existing, $prefix, $path, $latitude, $longitude, $distance, $accuracy) {
            $attendance = $existing ?? new StaffAttendance([
                'user_id' => $user->id,
                'work_date' => $now->toDateString(),
                'source' => StaffAttendance::SOURCE_MOBILE,
            ]);
            $attendance->fill([
                'branch_id' => $branch->id,
                $prefix.'_at' => $now,
                $prefix.'_photo' => $path,
                $prefix.'_lat' => $latitude,
                $prefix.'_lng' => $longitude,
                $prefix.'_distance' => $distance,
                $prefix.'_accuracy' => $accuracy !== null ? (int) round($accuracy) : null,
            ]);
            $this->recalculate($attendance, $user, $branch);
            $attendance->save();
            $this->syncLatePenalty($attendance);

            return $attendance;
        });

        // Chấm ra lần nữa (về muộn hơn) thay ảnh cũ.
        if ($oldPhoto && $oldPhoto !== $path) {
            Storage::disk('local')->delete($oldPhoto);
        }

        return $attendance;
    }

    /**
     * Cơ sở nhân sự được chấm công hôm nay (đã cài toạ độ, ngày chưa chốt lương).
     *
     * @throws ValidationException
     */
    public function ensureCanPunchAt(User $user, CarbonInterface $now): Branch
    {
        $branch = $user->branch;
        if (! $branch) {
            throw ValidationException::withMessages(['location' => 'Tài khoản của bạn chưa được gán cơ sở làm việc. Hãy báo Admin / Quản lý cơ sở.']);
        }
        if (! $branch->hasCheckinLocation()) {
            throw ValidationException::withMessages(['location' => "Cơ sở {$branch->name} chưa cài toạ độ chấm công. Hãy báo Admin vào Cài đặt → Cơ sở & chi nhánh → Chấm công."]);
        }
        if (PayrollPeriod::isLockedFor($now)) {
            throw ValidationException::withMessages(['kind' => PayrollPeriod::lockedMessage($now)]);
        }

        return $branch;
    }

    /**
     * Giờ phải có mặt / được về của nhân sự trong ngày ("HH:MM", null = không tính muộn / về sớm). Giáo viên / trợ giảng
     * (quyền Cổng giáo viên / trợ giảng) tính theo buổi dạy trong ngày, nhân sự khác theo giờ làm việc của cơ sở.
     *
     * Kèm danh sách ca dạy trong ngày (tên ca + giờ + lớp) để nhân sự thấy giờ đối chiếu.
     *
     * @return array{start: ?string, end: ?string, basis: string, sessions: list<array{shift: ?string, time: string, class: ?string}>}
     */
    public function expectedTimes(User $user, ?Branch $branch, CarbonInterface $date): array
    {
        if ($user->can('portal.teacher') || $user->can('portal.assistant')) {
            $sessions = ClassSession::query()->forStaff($user->id)
                ->with('classModel:id,name,code')
                ->whereDate('date', $date->toDateString())
                ->where('status', '!=', 'cancelled')
                ->orderBy('start_time')
                ->get(['id', 'class_id', 'shift_name', 'start_time', 'end_time']);
            if ($sessions->isEmpty()) {
                return ['start' => null, 'end' => null, 'basis' => 'Không có buổi dạy', 'sessions' => []];
            }

            return [
                'start' => $sessions->min(fn (ClassSession $s) => $s->start_time?->format('H:i')),
                'end' => $sessions->max(fn (ClassSession $s) => $s->end_time?->format('H:i')),
                'basis' => 'Theo giờ lớp được phân công',
                'sessions' => $sessions->map(fn (ClassSession $s) => [
                    'shift' => $s->shiftLabel(),
                    'time' => $s->start_time?->format('H:i').'–'.$s->end_time?->format('H:i'),
                    'class' => $s->classModel?->code ?? $s->classModel?->name,
                ])->values()->all(),
            ];
        }

        return ['start' => $branch?->workStart(), 'end' => $branch?->workEnd(), 'basis' => 'Theo giờ làm việc cơ sở', 'sessions' => []];
    }

    /** Tính lại giờ phải có mặt, số phút muộn / về sớm và "muộn có phép" của dòng chấm công (chưa lưu). */
    public function recalculate(StaffAttendance $attendance, User $user, ?Branch $branch): void
    {
        $date = Carbon::parse($attendance->work_date);
        $expected = $this->expectedTimes($user, $branch, $date);
        $attendance->expected_start = $expected['start'];
        $attendance->expected_end = $expected['end'];

        $late = 0;
        if ($expected['start'] && $attendance->check_in_at) {
            $minutes = (int) floor($date->copy()->setTimeFromTimeString($expected['start'])->diffInMinutes($attendance->check_in_at, false));
            $late = $minutes > (int) ($branch?->late_grace_minutes ?? 0) ? $minutes : 0;
        }
        $early = 0;
        if ($expected['end'] && $attendance->check_out_at) {
            $early = max(0, (int) floor($attendance->check_out_at->diffInMinutes($date->copy()->setTimeFromTimeString($expected['end']), false)));
        }

        $attendance->late_minutes = min($late, 65535);
        $attendance->early_minutes = min($early, 65535);
        $attendance->late_excused = $this->hasApproved($user->id, StaffAttendanceRequest::TYPE_LATE_EARLY, $date)
            || $this->hasApproved($user->id, StaffAttendanceRequest::TYPE_LEAVE, $date);
    }

    /**
     * Biên bản tự động khi đi muộn: lập mới (chờ giải trình, 0đ), cập nhật số phút khi còn chờ giải trình,
     * hoặc tự hủy khi không còn muộn (đơn xin đi muộn được duyệt / bổ sung công sửa giờ).
     */
    public function syncLatePenalty(StaffAttendance $attendance): void
    {
        $penalty = $attendance->penalty;
        $violation = 'Đi muộn '.$attendance->late_minutes.' phút (chấm công ngày '.$attendance->work_date->format('d/m/Y').')';

        if ($attendance->isLate()) {
            if ($penalty && $penalty->status !== 'cancelled') {
                if ($penalty->status === 'pending') {
                    $penalty->update(['violation_type' => $violation]);
                }

                return;
            }
            $penalty = Penalty::create([
                'code' => Penalty::generateCode(),
                'user_id' => $attendance->user_id,
                'violation_type' => $violation,
                'error_category' => 'operations',
                'violation_date' => $attendance->work_date->toDateString(),
                'amount' => 0,
                'reporter_id' => null,
                'status' => 'pending',
                'notes' => 'Tự động từ chấm công: giờ vào '.$attendance->check_in_at?->format('H:i').', giờ phải có mặt '.substr((string) $attendance->expected_start, 0, 5).'.',
            ]);
            $attendance->penalty()->associate($penalty)->save();
            AdminNotification::create([
                'user_id' => $attendance->user_id,
                'type' => 'penalty_created',
                'title' => "Biên bản {$penalty->code}: {$violation}",
                'message' => 'Nếu có lý do, hãy gửi giải trình hoặc đơn "Xin đi muộn / về sớm" cho ngày này.',
                'data' => ['link' => route('penalties.index', ['search' => $penalty->code])],
                'is_read' => false,
            ]);

            return;
        }

        if ($penalty && in_array($penalty->status, ['pending', 'explained'], true)) {
            $penalty->update([
                'status' => 'cancelled',
                'decided_at' => now(),
                'decision_note' => $attendance->late_excused
                    ? 'Tự hủy: đã duyệt đơn xin đi muộn / nghỉ cho ngày này.'
                    : 'Tự hủy: giờ chấm công đã sửa, không còn đi muộn.',
            ]);
        }
    }

    private function hasApproved(int $userId, string $type, CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return StaffAttendanceRequest::query()->approved()->where('user_id', $userId)->where('type', $type)
            ->overlapping($day, $day)->exists();
    }

    // ───────────────────────── Đơn xin duyệt ─────────────────────────

    /**
     * Gửi đơn của chính mình.
     *
     * @param  array{type: string, date_from: string, date_to?: ?string, check_in_time?: ?string, check_out_time?: ?string, reason: string}  $data
     *
     * @throws ValidationException
     */
    public function submit(User $user, array $data): StaffAttendanceRequest
    {
        $type = $data['type'];
        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = $type === StaffAttendanceRequest::TYPE_LEAVE && ! empty($data['date_to']) ? Carbon::parse($data['date_to'])->startOfDay() : $from->copy();

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['date_to' => 'Ngày kết thúc phải từ ngày bắt đầu trở đi.']);
        }
        if ($from->diffInDays($to) + 1 > self::MAX_LEAVE_DAYS) {
            throw ValidationException::withMessages(['date_to' => 'Mỗi đơn xin nghỉ tối đa '.self::MAX_LEAVE_DAYS.' ngày.']);
        }
        if ($type === StaffAttendanceRequest::TYPE_CORRECTION) {
            if ($from->isAfter(today())) {
                throw ValidationException::withMessages(['date_from' => 'Chỉ bổ sung công cho hôm nay hoặc ngày đã qua.']);
            }
            if (empty($data['check_in_time']) && empty($data['check_out_time'])) {
                throw ValidationException::withMessages(['check_in_time' => 'Nhập giờ vào hoặc giờ ra cần bổ sung.']);
            }
            if (! empty($data['check_in_time']) && ! empty($data['check_out_time']) && $data['check_out_time'] <= $data['check_in_time']) {
                throw ValidationException::withMessages(['check_out_time' => 'Giờ ra phải sau giờ vào.']);
            }
        }
        foreach ([$from, $to] as $day) {
            if (PayrollPeriod::isLockedFor($day)) {
                throw ValidationException::withMessages(['date_from' => PayrollPeriod::lockedMessage($day)]);
            }
        }
        $duplicate = StaffAttendanceRequest::query()->pending()->where('user_id', $user->id)->where('type', $type)
            ->overlapping($from->toDateString(), $to->toDateString())->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['date_from' => 'Bạn đã có đơn "'.StaffAttendanceRequest::TYPES[$type].'" đang chờ duyệt cho ngày này.']);
        }

        return StaffAttendanceRequest::create([
            'user_id' => $user->id,
            'branch_id' => $user->branch_id,
            'type' => $type,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'check_in_time' => $type === StaffAttendanceRequest::TYPE_CORRECTION ? ($data['check_in_time'] ?? null) : null,
            'check_out_time' => $type === StaffAttendanceRequest::TYPE_CORRECTION ? ($data['check_out_time'] ?? null) : null,
            'reason' => trim($data['reason']),
            'status' => StaffAttendanceRequest::STATUS_PENDING,
        ]);
    }

    /** Người gửi rút đơn còn chờ duyệt. */
    public function cancel(User $user, StaffAttendanceRequest $request): void
    {
        if ((int) $request->user_id !== (int) $user->id || $request->status !== StaffAttendanceRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['request' => 'Chỉ rút được đơn của bạn đang chờ duyệt.']);
        }
        $request->update(['status' => StaffAttendanceRequest::STATUS_CANCELLED]);
    }

    /** Đơn đang chờ mà người duyệt xử lý được: trong phạm vi chi nhánh, không phải đơn của chính mình. */
    public function reviewQuery(User $reviewer): Builder
    {
        $query = StaffAttendanceRequest::query()->pending()->where('user_id', '!=', $reviewer->id);
        if (! $reviewer->can('staff_checkin.approve')) {
            return $query->whereRaw('1 = 0');
        }

        return DataScope::apply($query, $reviewer, 'staff_checkin', null, fn (Builder $q, array $ids) => $q->whereIn('branch_id', $ids));
    }

    /** @throws ValidationException */
    public function approve(StaffAttendanceRequest $request, User $reviewer): void
    {
        $this->ensureReviewable($request, $reviewer);
        foreach ([$request->date_from, $request->date_to] as $day) {
            if (PayrollPeriod::isLockedFor($day)) {
                throw ValidationException::withMessages(['request' => PayrollPeriod::lockedMessage($day)]);
            }
        }

        DB::transaction(function () use ($request, $reviewer) {
            $request->update([
                'status' => StaffAttendanceRequest::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);
            $this->applyApproved($request);
        });

        $this->notifyRequester($request, 'Đã duyệt', null);
    }

    /** @throws ValidationException */
    public function reject(StaffAttendanceRequest $request, User $reviewer, string $reason): void
    {
        $this->ensureReviewable($request, $reviewer);
        $request->update([
            'status' => StaffAttendanceRequest::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);
        $this->notifyRequester($request, 'Từ chối', $reason);
    }

    private function ensureReviewable(StaffAttendanceRequest $request, User $reviewer): void
    {
        if (! $this->reviewQuery($reviewer)->whereKey($request->id)->exists()) {
            throw ValidationException::withMessages(['request' => 'Đơn không còn chờ duyệt hoặc ngoài phạm vi duyệt của bạn.']);
        }
    }

    /** Áp đơn đã duyệt vào bảng công (đơn xin nghỉ chỉ cần trạng thái đã duyệt). */
    private function applyApproved(StaffAttendanceRequest $request): void
    {
        $user = $request->user;
        $days = collect();
        for ($day = $request->date_from->copy(); $day->lte($request->date_to); $day->addDay()) {
            $days->push($day->copy());
        }

        foreach ($days as $day) {
            $attendance = StaffAttendance::forDay($user->id, $day);
            if ($request->type === StaffAttendanceRequest::TYPE_CORRECTION) {
                $attendance ??= new StaffAttendance([
                    'user_id' => $user->id,
                    'work_date' => $day->toDateString(),
                    'source' => StaffAttendance::SOURCE_REQUEST,
                    'branch_id' => $request->branch_id ?? $user->branch_id,
                ]);
                if ($request->check_in_time) {
                    $attendance->check_in_at = $day->copy()->setTimeFromTimeString((string) $request->check_in_time);
                }
                if ($request->check_out_time) {
                    $attendance->check_out_at = $day->copy()->setTimeFromTimeString((string) $request->check_out_time);
                }
                $attendance->note = trim('Bổ sung công đã duyệt: '.$request->reason);
            }
            if (! $attendance) {
                continue;
            }
            $this->recalculate($attendance, $user, $attendance->branch ?? $user->branch);
            $attendance->save();
            $this->syncLatePenalty($attendance->fresh(['penalty']));
        }
    }

    private function notifyRequester(StaffAttendanceRequest $request, string $verb, ?string $reason): void
    {
        AdminNotification::create([
            'user_id' => $request->user_id,
            'type' => 'staff_attendance_request',
            'title' => "{$verb} đơn {$request->typeLabel()} ngày {$request->periodLabel()}",
            'message' => $reason ? 'Lý do: '.$reason : 'Đơn của bạn đã được duyệt.',
            'data' => ['link' => route('mobile.requests')],
            'is_read' => false,
        ]);
    }

    // ───────────────────────── Tổng hợp cho bảng lương ─────────────────────────

    /**
     * Tổng hợp chấm công của 1 người trong khoảng ngày (phiếu lương, màn chấm công).
     *
     * @return array{days: int, late_count: int, late_minutes: int, excused_late: int, early_count: int, missing_out: int, leave_days: int}
     */
    public function summary(int $userId, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = StaffAttendance::query()->where('user_id', $userId)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get(['check_in_at', 'check_out_at', 'late_minutes', 'early_minutes', 'late_excused', 'work_date']);
        $late = $rows->filter(fn (StaffAttendance $a) => $a->isLate());

        $leaveDays = StaffAttendanceRequest::query()->approved()->where('user_id', $userId)
            ->where('type', StaffAttendanceRequest::TYPE_LEAVE)
            ->overlapping($start->toDateString(), $end->toDateString())
            ->get(['date_from', 'date_to'])
            ->sum(function (StaffAttendanceRequest $r) use ($start, $end) {
                $from = $r->date_from->max($start->copy()->startOfDay());
                $to = $r->date_to->min($end->copy()->startOfDay());

                return $to->lt($from) ? 0 : (int) $from->diffInDays($to) + 1;
            });

        return [
            'days' => $rows->whereNotNull('check_in_at')->count(),
            'late_count' => $late->count(),
            'late_minutes' => (int) $late->sum('late_minutes'),
            'excused_late' => $rows->filter(fn (StaffAttendance $a) => $a->late_minutes > 0 && $a->late_excused)->count(),
            'early_count' => $rows->where('early_minutes', '>', 0)->count(),
            'missing_out' => $rows->filter(fn (StaffAttendance $a) => $a->check_in_at && ! $a->check_out_at && $a->work_date->lt(today()))->count(),
            'leave_days' => (int) $leaveDays,
        ];
    }
}
