<?php

namespace App\Services\StaffAttendance;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassSession;
use App\Models\Holiday;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\User;
use App\Services\PayrollFormulaService;
use App\Support\DataScope;
use App\Support\Roles;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Chấm công hằng ngày trên điện thoại + đơn xin duyệt về chấm công.
 *
 * Quy tắc:
 *  - Giờ vào / ra = giờ máy chủ lúc bấm (không nhận giờ từ điện thoại).
 *  - Chấm tại một trong các cơ sở làm việc của nhân sự (cơ sở chính users.branch_id + cơ sở làm việc thêm user_branches):
 *    lấy cơ sở đã cài toạ độ gần GPS nhất, GPS phải nằm trong bán kính cơ sở đó. Bản ghi công lưu cơ sở đã chấm.
 *    Giáo viên / trợ giảng chưa gắn cơ sở chấm được ở mọi cơ sở.
 *  - Giờ phải có mặt: GV / TA / GVNN theo giờ lớp được phân công trong ngày — buổi đầu tiên (Ca 1 18:00 hay lớp lệch
 *    18:10…, khung giờ ca dạy), không theo một giờ chung cố định; không có buổi → không tính muộn;
 *    nhân sự khác theo giờ làm việc của cơ sở. Muộn quá số phút cho phép của cơ sở → ghi số phút muộn; bảng lương Full-time
 *    cộng dồn giờ muộn cả tháng × công giờ (không lập biên bản từng ngày, TK-0013); đơn xin đi muộn được duyệt →
 *    không tính muộn. Quên chấm vào / ra: phạt mỗi lần trên bảng lương (workdays()). GV / TA không bị trừ ở đây: đi muộn đã trừ trên check-in ca dạy (tránh phạt 2 lần).
 *  - Ngày thuộc kỳ lương đã chốt thì không chấm / không sửa được nữa.
 *  - Ngày được chấm (22/10/2026, punchBlockReason): part-time (xếp loại như bảng lương) chỉ ngày có buổi dạy / dạy thay /
 *    học bù chưa hủy; nhân sự khác mọi ngày trừ ngày nghỉ của cơ sở. Áp cả khi bấm chấm công và đơn "Bổ sung công".
 */
class StaffAttendanceService
{
    public const IN = 'in';

    public const OUT = 'out';

    /** Chấm ra cho ngày hôm qua (chưa chấm ra) được tới giờ này sáng hôm sau. */
    public const OVERNIGHT_CHECKOUT_UNTIL = 6;

    /** Đơn xin nghỉ tối đa bao nhiêu ngày một lần. */
    public const MAX_LEAVE_DAYS = 31;

    public function __construct(private readonly PayrollFormulaService $payroll) {}

    /**
     * Chấm công vào / ra cho chính mình.
     *
     * @throws ValidationException
     */
    public function punch(User $user, string $kind, float $latitude, float $longitude, ?float $accuracy, UploadedFile $photo): StaffAttendance
    {
        $now = now();
        $existing = StaffAttendance::forDay($user->id, $now);
        // Chấm ra sau nửa đêm (tới 06:00) cho ngày hôm qua còn thiếu giờ ra — ca tối kéo dài / quên chấm ra.
        $overnight = $kind === self::OUT && ! $existing?->check_in_at ? $this->openOvernight($user, $now) : null;
        $existing = $overnight ?? $existing;
        $branch = $this->ensureCanPunchAt($user, $now)
            ->sortBy(fn (Branch $b) => $b->distanceTo($latitude, $longitude))
            ->first();
        if ($reason = $this->punchBlockReason($user, $branch, $overnight?->work_date ?? $now)) {
            throw ValidationException::withMessages(['kind' => $reason]);
        }
        $distance = $branch->distanceTo($latitude, $longitude);
        $radius = $branch->checkin_radius ?: Branch::DEFAULT_CHECKIN_RADIUS;
        if ($distance > $radius) {
            throw ValidationException::withMessages([
                'location' => "Bạn đang cách {$branch->name} khoảng ".number_format($distance, 0, ',', '.')." m, chỉ chấm công được trong bán kính {$radius} m quanh cơ sở.",
            ]);
        }

        if ($kind === self::IN && $existing?->check_in_at) {
            throw ValidationException::withMessages(['kind' => 'Hôm nay bạn đã chấm công vào lúc '.$existing->check_in_at->format('H:i').'.']);
        }
        if ($kind === self::OUT && ! $existing?->check_in_at) {
            throw ValidationException::withMessages(['kind' => 'Hôm nay bạn chưa chấm công vào. Nếu quên chấm vào, hãy gửi đơn "Bổ sung công".']);
        }

        $path = $photo->store(StaffAttendance::PHOTO_DIR.'/'.$now->format('Y/m'), 'local');
        $prefix = $kind === self::IN ? 'check_in' : 'check_out';
        $oldPhoto = $existing?->{$prefix.'_photo'};

        // Ngày làm ở nhiều cơ sở (vào ở A, ra ở B): bản ghi công giữ cơ sở chấm vào, giờ làm / muộn tính theo cơ sở đó.
        $dayBranch = ($existing?->branch_id ? $existing->branch : null) ?? $branch;

        try {
            $attendance = $this->savePunch($user, $dayBranch, $now, $existing, $prefix, $path, $latitude, $longitude, $distance, $accuracy);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            // Bấm chấm vào 2 lần cùng lúc (mạng chậm): lượt sau vướng khóa 1 dòng / người / ngày.
            if ($e instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['kind' => 'Lượt chấm công vừa được ghi nhận. Hãy tải lại trang để xem giờ đã chấm.']);
            }
            throw $e;
        }

        // Chấm ra lần nữa (về muộn hơn) thay ảnh cũ.
        if ($oldPhoto && $oldPhoto !== $path) {
            Storage::disk('local')->delete($oldPhoto);
        }

        return $attendance;
    }

    private function savePunch(User $user, Branch $dayBranch, CarbonInterface $now, ?StaffAttendance $existing, string $prefix, string $path, float $latitude, float $longitude, ?int $distance, ?float $accuracy): StaffAttendance
    {
        return DB::transaction(function () use ($user, $dayBranch, $now, $existing, $prefix, $path, $latitude, $longitude, $distance, $accuracy) {
            $attendance = $existing ?? new StaffAttendance([
                'user_id' => $user->id,
                'work_date' => $now->toDateString(),
                'source' => StaffAttendance::SOURCE_MOBILE,
            ]);
            $attendance->fill([
                'branch_id' => $dayBranch->id,
                $prefix.'_at' => $now,
                $prefix.'_photo' => $path,
                $prefix.'_lat' => $latitude,
                $prefix.'_lng' => $longitude,
                $prefix.'_distance' => $distance,
                $prefix.'_accuracy' => $accuracy !== null ? (int) round($accuracy) : null,
            ]);
            $this->recalculate($attendance, $user, $dayBranch);
            $attendance->save();
            $this->syncLatePenalty($attendance);

            return $attendance;
        });
    }

    /** Ngày công hôm qua đã chấm vào, chưa chấm ra, còn trong giờ chấm ra qua đêm (trước 06:00) và kỳ lương chưa khóa. */
    public function openOvernight(User $user, CarbonInterface $now): ?StaffAttendance
    {
        if ($now->hour >= self::OVERNIGHT_CHECKOUT_UNTIL) {
            return null;
        }
        $yesterday = StaffAttendance::forDay($user->id, $now->copy()->subDay());

        return $yesterday?->check_in_at && ! $yesterday->check_out_at && ! PayrollPeriod::isLockedFor($yesterday->work_date)
            ? $yesterday : null;
    }

    /**
     * Cơ sở làm việc của nhân sự: cơ sở chính trước, rồi cơ sở làm việc thêm (theo tên). Giáo viên / trợ giảng chưa gắn
     * cơ sở nào (dùng chung, Roles::BRANCH_OPTIONAL) làm được ở mọi cơ sở đang hoạt động.
     *
     * @return Collection<int, Branch>
     */
    public function workBranches(User $user): Collection
    {
        $user->loadMissing(['branch', 'branches']);

        $branches = collect([$user->branch])
            ->merge($user->branches->sortBy('name'))
            ->filter()
            ->unique('id')
            ->values();
        if ($branches->isEmpty() && $user->hasAnyRole(Roles::BRANCH_OPTIONAL)) {
            return Branch::query()->active()->orderBy('name')->get();
        }

        return $branches;
    }

    /**
     * Các cơ sở nhân sự được chấm công hôm nay (đã cài toạ độ, ngày chưa chốt lương).
     *
     * @return Collection<int, Branch>
     *
     * @throws ValidationException
     */
    public function ensureCanPunchAt(User $user, CarbonInterface $now): Collection
    {
        $branches = $this->workBranches($user);
        if ($branches->isEmpty()) {
            throw ValidationException::withMessages(['location' => 'Tài khoản của bạn chưa được gán cơ sở làm việc. Hãy báo Admin / Quản lý cơ sở.']);
        }
        $located = $branches->filter(fn (Branch $b) => $b->hasCheckinLocation())->values();
        if ($located->isEmpty()) {
            $names = $branches->pluck('name')->implode(', ');
            throw ValidationException::withMessages(['location' => "Cơ sở {$names} chưa cài toạ độ chấm công. Hãy báo Admin vào Cài đặt → Cơ sở & chi nhánh → Chấm công."]);
        }
        if (PayrollPeriod::isLockedFor($now)) {
            throw ValidationException::withMessages(['kind' => PayrollPeriod::lockedMessage($now)]);
        }

        return $located;
    }

    /** Lý do không được chấm công ngày $date ở cơ sở $branch (null = được chấm). */
    public function punchBlockReason(User $user, ?Branch $branch, CarbonInterface $date): ?string
    {
        if ($this->payroll->profile($user)['employee_type'] === PayrollRecord::TYPE_PARTTIME) {
            $teaches = ClassSession::query()->forStaff($user->id)
                ->whereDate('date', $date->toDateString())->where('status', '!=', 'cancelled')->exists();

            return $teaches ? null : 'Hôm nay bạn không có buổi dạy / dạy thay / học bù nên không cần chấm công.';
        }

        $holiday = Holiday::query()->coveringBranch($date->toDateString(), $branch?->id)->value('name');
        if ($holiday !== null) {
            return "Hôm nay là ngày nghỉ ({$holiday}), không chấm công.";
        }
        // Ngày nghỉ cố định trong tuần (lịch làm việc riêng) — trừ khi hôm đó có buổi dạy / dạy thay.
        if ($user->isWeeklyDayOff($date) && ! ClassSession::query()->forStaff($user->id)
            ->whereDate('date', $date->toDateString())->where('status', '!=', 'cancelled')->exists()) {
            return 'Hôm nay là ngày nghỉ cố định trong tuần của bạn, không chấm công.';
        }

        return null;
    }

    /**
     * Giờ phải có mặt / được về của nhân sự trong ngày ("HH:MM", null = không tính muộn / về sớm). Giáo viên / trợ giảng
     * (quyền Cổng giáo viên / trợ giảng) tính theo buổi dạy trong ngày, nhân sự khác theo lịch làm việc riêng (nếu có)
     * rồi tới giờ làm việc của cơ sở.
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

        // Lịch làm việc riêng của nhân sự (ca chiều, ca tối…) thay giờ làm việc chung của cơ sở.
        if ($user->work_start_time || $user->work_end_time) {
            return [
                'start' => $user->work_start_time ? substr((string) $user->work_start_time, 0, 5) : $branch?->workStart(),
                'end' => $user->work_end_time ? substr((string) $user->work_end_time, 0, 5) : $branch?->workEnd(),
                'basis' => 'Theo lịch làm việc riêng',
                'sessions' => [],
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
     * Đi muộn chấm công hằng ngày không còn lập biên bản từng ngày (TK-0013, 30/10/2026): bảng lương Full-time trừ
     * tổng giờ muộn trong tháng × công giờ (PayrollPeriod::runCalculation). Biên bản tự động cũ của ngày công còn
     * chờ giải trình / đã giải trình thì tự hủy khi ngày công được tính lại; biên bản đã quyết phạt giữ nguyên nhưng
     * không trừ lương nữa (Penalty::scopeDeductibleFor) để không phạt 2 lần.
     */
    public function syncLatePenalty(StaffAttendance $attendance): void
    {
        // Biên bản tự động của ngày công, bất kể người đang thao tác (duyệt đơn, sửa giờ) có thấy biên bản đó hay không.
        $penalty = $attendance->penalty_id ? $attendance->penalty()->withoutViewerScope()->first() : null;
        if (! $penalty || ! in_array($penalty->status, ['pending', 'explained'], true)) {
            return;
        }

        $penalty->update([
            'status' => 'cancelled',
            'decided_at' => now(),
            'decision_note' => match (true) {
                ! $attendance->isLate() && $attendance->late_excused => 'Tự hủy: đã duyệt đơn xin đi muộn / nghỉ cho ngày này.',
                ! $attendance->isLate() => 'Tự hủy: giờ chấm công đã sửa, không còn đi muộn.',
                default => self::LATE_PENALTY_CANCEL_NOTE,
            },
        ]);
    }

    public const LATE_PENALTY_CANCEL_NOTE = 'Tự hủy: đi muộn chấm công hằng ngày cộng dồn cả tháng và trừ trên bảng lương, không lập biên bản từng ngày.';

    /**
     * Đi muộn chấm công hằng ngày có bị trừ lương không: không với GV / TA (giờ phải có mặt theo buổi dạy, đi muộn đã
     * tính trên check-in ca dạy — tránh phạt 2 lần cho cùng một lần vào lớp muộn).
     */
    public function dailyLatePenalized(User $user): bool
    {
        return ! ($user->can('portal.teacher') || $user->can('portal.assistant'));
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
            $dayBranch = ($branchId = $this->requestBranchId($user, $from, $from)) ? Branch::find($branchId) : $user->branch;
            if ($reason = $this->punchBlockReason($user, $dayBranch, $from)) {
                throw ValidationException::withMessages(['date_from' => str_replace('Hôm nay', 'Ngày '.$from->format('d/m/Y'), $reason)]);
            }
            if (empty($data['check_in_time']) && empty($data['check_out_time'])) {
                throw ValidationException::withMessages(['check_in_time' => 'Nhập giờ vào hoặc giờ ra cần bổ sung.']);
            }
            // Chỉ khai 1 giờ thì so với giờ đã chấm của ngày đó (giờ ra không được sớm hơn giờ vào đã có, và ngược lại).
            $punched = StaffAttendance::forDay($user->id, $from);
            $in = $data['check_in_time'] ?? null ?: $punched?->check_in_at?->format('H:i');
            $out = $data['check_out_time'] ?? null ?: $punched?->check_out_at?->format('H:i');
            if ($in && $out && $out <= $in) {
                throw ValidationException::withMessages(['check_out_time' => 'Giờ ra phải sau giờ vào ('.$in.').']);
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
            'branch_id' => $this->requestBranchId($user, $from, $to),
            'type' => $type,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'check_in_time' => $type === StaffAttendanceRequest::TYPE_CORRECTION ? ($data['check_in_time'] ?? null) : null,
            'check_out_time' => $type === StaffAttendanceRequest::TYPE_CORRECTION ? ($data['check_out_time'] ?? null) : null,
            'reason' => trim($data['reason']),
            'status' => StaffAttendanceRequest::STATUS_PENDING,
        ]);
    }

    /**
     * Cơ sở của đơn (quyết định Quản lý cơ sở nào duyệt): cơ sở đã chấm công ngày đó → cơ sở của buổi dạy trong khoảng
     * ngày (GV / TA dùng chung chưa gắn cơ sở, hoặc dạy ở cơ sở phụ) → cơ sở chính của nhân sự.
     */
    private function requestBranchId(User $user, CarbonInterface $from, CarbonInterface $to): ?int
    {
        $punched = StaffAttendance::query()->where('user_id', $user->id)->whereNotNull('branch_id')
            ->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())
            ->orderBy('work_date')->value('branch_id');
        $taught = $punched ? null : ClassSession::query()->forStaff($user->id)->whereNotNull('branch_id')
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')->orderBy('start_time')->value('branch_id');
        $id = $punched ?? $taught ?? $user->branch_id;

        return $id ? (int) $id : null;
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
        return $this->managedRequests($reviewer)->pending();
    }

    /** Đơn (mọi trạng thái) của nhân sự khác trong phạm vi duyệt của người duyệt. */
    public function managedRequests(User $reviewer): Builder
    {
        $query = StaffAttendanceRequest::query()->where('user_id', '!=', $reviewer->id);
        if (! $reviewer->can('staff_checkin.approve')) {
            return $query->whereRaw('1 = 0');
        }

        // Đơn chưa xác định được cơ sở (nhân sự dùng chung không có buổi / ngày công) → quản lý các cơ sở nhân sự làm việc.
        return DataScope::apply($query, $reviewer, 'staff_checkin', null, fn (Builder $q, array $ids) => $q->where(fn (Builder $w) => $w
            ->whereIn('branch_id', $ids)
            ->orWhere(fn (Builder $o) => $o->whereNull('branch_id')->whereHas('user', fn (Builder $u) => $u->inBranches($ids)))));
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

    /**
     * Thu hồi đơn xin nghỉ / xin đi muộn đã duyệt (duyệt nhầm, nhân sự vẫn đi làm…): đơn chuyển "Đã thu hồi", ngày công
     * tính lại (đi muộn không còn được miễn → lại bị trừ trên bảng lương). Đơn bổ sung công không thu hồi — sửa giờ công trực tiếp.
     *
     * @throws ValidationException
     */
    public function revoke(StaffAttendanceRequest $request, User $reviewer, string $reason): void
    {
        $revocable = $request->status === StaffAttendanceRequest::STATUS_APPROVED
            && in_array($request->type, [StaffAttendanceRequest::TYPE_LEAVE, StaffAttendanceRequest::TYPE_LATE_EARLY], true)
            && $this->managedRequests($reviewer)->whereKey($request->id)->exists();
        if (! $revocable) {
            throw ValidationException::withMessages(['request' => 'Chỉ thu hồi được đơn xin nghỉ / xin đi muộn đã duyệt của nhân sự trong phạm vi của bạn.']);
        }
        foreach ([$request->date_from, $request->date_to] as $day) {
            if (PayrollPeriod::isLockedFor($day)) {
                throw ValidationException::withMessages(['request' => PayrollPeriod::lockedMessage($day)]);
            }
        }

        DB::transaction(function () use ($request, $reviewer, $reason) {
            $request->update([
                'status' => StaffAttendanceRequest::STATUS_REVOKED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $this->applyApproved($request);
        });

        $this->notifyRequester($request, 'Thu hồi', $reason);
    }

    /**
     * Quản lý sửa giờ vào / ra của 1 ngày công (nhân sự quên chấm, chấm sai, đã nghỉ việc…), ghi lý do vào ghi chú.
     * Ô để trống giữ giờ đang có. Ngày thuộc kỳ lương đã khóa thì không sửa.
     *
     * @throws ValidationException
     */
    public function adjust(User $staff, User $editor, CarbonInterface $date, ?string $checkIn, ?string $checkOut, string $reason): StaffAttendance
    {
        if ((int) $staff->id === (int) $editor->id || ! $this->canManageStaff($editor, $staff)) {
            throw ValidationException::withMessages(['user_id' => 'Bạn không sửa được công của nhân sự này (ngoài phạm vi hoặc của chính bạn).']);
        }
        if ($date->isAfter(today())) {
            throw ValidationException::withMessages(['work_date' => 'Chỉ sửa công hôm nay hoặc ngày đã qua.']);
        }
        if (PayrollPeriod::isLockedFor($date)) {
            throw ValidationException::withMessages(['work_date' => PayrollPeriod::lockedMessage($date)]);
        }
        $attendance = StaffAttendance::forDay($staff->id, $date) ?? new StaffAttendance([
            'user_id' => $staff->id,
            'work_date' => $date->toDateString(),
            'source' => StaffAttendance::SOURCE_MANUAL,
            'branch_id' => $this->requestBranchId($staff, $date, $date),
        ]);
        $in = filled($checkIn) ? $date->copy()->startOfDay()->setTimeFromTimeString($checkIn) : $attendance->check_in_at;
        $out = filled($checkOut) ? $date->copy()->startOfDay()->setTimeFromTimeString($checkOut) : $attendance->check_out_at;
        if (! $in && ! $out) {
            throw ValidationException::withMessages(['check_in_time' => 'Nhập giờ vào hoặc giờ ra.']);
        }
        if ($in && $out && $out->lte($in)) {
            throw ValidationException::withMessages(['check_out_time' => 'Giờ ra phải sau giờ vào.']);
        }

        $stamp = '['.now()->format('d/m H:i').'] '.$editor->name.' sửa công';
        $attendance->fill([
            'check_in_at' => $in,
            'check_out_at' => $out,
            'note' => mb_substr(trim(($attendance->note ? $attendance->note."\n" : '').$stamp.': '.trim($reason)), -500),
        ]);

        return DB::transaction(function () use ($attendance, $staff) {
            $this->recalculate($attendance, $staff, $attendance->branch ?? $staff->branch);
            $attendance->save();
            $this->syncLatePenalty($attendance->fresh(['penalty']));

            return $attendance->fresh();
        });
    }

    /** Người duyệt chấm công quản lý được nhân sự này (phạm vi chi nhánh: cơ sở chính hoặc làm thêm của nhân sự). */
    public function canManageStaff(User $manager, User $staff): bool
    {
        if (! $manager->can('staff_checkin.approve')) {
            return false;
        }
        $ids = DataScope::branchIds($manager, 'staff_checkin');
        if ($ids === null) {
            return true;
        }

        return User::query()->whereKey($staff->id)->inBranches($ids)->exists();
    }

    private function ensureReviewable(StaffAttendanceRequest $request, User $reviewer): void
    {
        if (! $this->reviewQuery($reviewer)->whereKey($request->id)->exists()) {
            throw ValidationException::withMessages(['request' => 'Đơn không còn chờ duyệt hoặc ngoài phạm vi duyệt của bạn.']);
        }
    }

    /** Áp đơn đã duyệt (hoặc vừa thu hồi) vào bảng công: tính lại muộn có phép / biên bản các ngày của đơn. */
    private function applyApproved(StaffAttendanceRequest $request): void
    {
        $user = $request->user;
        $days = collect();
        for ($day = $request->date_from->copy(); $day->lte($request->date_to); $day->addDay()) {
            $days->push($day->copy());
        }

        foreach ($days as $day) {
            $attendance = StaffAttendance::forDay($user->id, $day);
            if ($request->type === StaffAttendanceRequest::TYPE_CORRECTION && $request->status === StaffAttendanceRequest::STATUS_APPROVED) {
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
            'data' => ['link' => route('my-attendance.requests')],
            'is_read' => false,
        ]);
    }

    // ───────────────────────── Tổng hợp cho bảng lương ─────────────────────────

    /**
     * Ngày công tính lương Full-time trong kỳ [start, end] (ticket TK-0012 / TK-0013, chủ dự án chốt 30/10/2026):
     * - Công chuẩn (standard_days): số ngày trong kỳ trừ ngày nghỉ cố định trong tuần (User::isPayrollDayOff — nghỉ 1 ngày/tuần
     *   hoặc T7 + CN). Ngày lễ VẪN nằm trong công chuẩn.
     * - Công hưởng lương (paid_days), chỉ tính từ ngày bắt đầu hợp đồng nếu vào giữa kỳ:
     *   · ngày có chấm vào: đủ vào + ra = 1 công (kể cả đã chấm công bù được duyệt); có vào, quên ra, không bù (ngày đã qua)
     *     = 0,5 công;
     *   · ngày nghỉ phép đã duyệt (như summary()['leave_days']);
     *   · ngày lễ rơi vào ngày làm việc (config payroll.workday.paid_holidays).
     * - Quên chấm công (missing_punches): mỗi lần quên chấm vào / chấm ra — ngày có vào mà không ra (chưa bù), hoặc đơn
     *   "Bổ sung công" đã duyệt bù giờ vào / giờ ra (mỗi giờ bù = 1 lần). Phạt mỗi lần trên bảng lương.
     * - Phút đi muộn (late_minutes): tổng late_minutes của các ngày muộn không có phép (StaffAttendance::isLate).
     *
     * @return array<string, mixed>
     */
    public function workdays(User $user, CarbonInterface $start, CarbonInterface $end): array
    {
        $periodStart = $start->copy()->startOfDay();
        $to = $end->copy()->startOfDay();
        $from = $periodStart->copy();
        $contractStart = $user->contract_start_date?->copy()->startOfDay();
        if ($contractStart && $contractStart->gt($from)) {
            $from = $contractStart;
        }

        $standard = 0;
        for ($day = $periodStart->copy(); $day->lte($to); $day->addDay()) {
            if (! $user->isPayrollDayOff($day)) {
                $standard++;
            }
        }

        $result = [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'weekly_days_off' => $user->payrollDaysOff(),
            'standard_days' => $standard, 'paid_days' => 0.0,
            'worked_days' => 0, 'full_days' => 0, 'half_days' => 0, 'makeup_days' => 0,
            'leave_days' => 0, 'holiday_days' => 0,
            'missing_punches' => 0, 'missing_check_in' => 0, 'missing_check_out' => 0,
            'late_days' => 0, 'late_minutes' => 0,
        ];
        if ($from->gt($to)) {
            return $result;
        }

        $range = [$from->toDateString(), $to->toDateString()];
        // Đơn "Bổ sung công" đã duyệt: ngày được chấm công bù + giờ đã bù (mỗi giờ bù = 1 lần quên chấm).
        $makeups = StaffAttendanceRequest::query()->approved()->where('user_id', $user->id)
            ->where('type', StaffAttendanceRequest::TYPE_CORRECTION)
            ->overlapping($range[0], $range[1])
            ->get(['date_from', 'check_in_time', 'check_out_time'])
            ->groupBy(fn (StaffAttendanceRequest $r) => $r->date_from->toDateString());
        foreach ($makeups as $requests) {
            $result['missing_check_in'] += $requests->contains(fn ($r) => filled($r->check_in_time)) ? 1 : 0;
            $result['missing_check_out'] += $requests->contains(fn ($r) => filled($r->check_out_time)) ? 1 : 0;
        }

        $credit = (float) config('payroll.workday.missing_checkout_credit', 0.5);
        $worked = 0.0;
        $workedDates = [];
        $rows = StaffAttendance::query()->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $range[0])->whereDate('work_date', '<=', $range[1])
            ->whereNotNull('check_in_at')
            ->get(['check_in_at', 'check_out_at', 'late_minutes', 'late_excused', 'work_date']);
        foreach ($rows as $row) {
            $key = $row->work_date->toDateString();
            $workedDates[$key] = true;
            $result['worked_days']++;
            if ($makeups->has($key)) {
                $result['makeup_days']++;
            }
            // Có vào, quên ra (ngày đã qua, chưa bù) = nửa công + 1 lần quên chấm; hôm nay chưa chấm ra vẫn đang làm → đủ công.
            if (! $row->check_out_at && $row->work_date->lt(today())) {
                $result['half_days']++;
                $result['missing_check_out']++;
                $worked += $credit;
            } else {
                $result['full_days']++;
                $worked += 1;
            }
            if ($row->isLate()) {
                $result['late_days']++;
                $result['late_minutes'] += (int) $row->late_minutes;
            }
        }

        $result['leave_days'] = $this->summary($user->id, $from, $to, payroll: true)['leave_days'];

        // Ngày lễ rơi vào ngày làm việc (không phải ngày nghỉ cố định, không đi làm) được trả lương.
        if (config('payroll.workday.paid_holidays', true)) {
            $holidays = Holiday::query()
                ->whereDate('start_date', '<=', $range[1])->whereDate('end_date', '>=', $range[0])
                ->where(fn (Builder $q) => $q->where('is_system_wide', true)
                    ->when($user->branch_id, fn (Builder $q) => $q->orWhereHas('branches', fn (Builder $b) => $b->where('branches.id', $user->branch_id))))
                ->get(['start_date', 'end_date'])
                ->map(fn (Holiday $h) => [Carbon::parse($h->start_date)->toDateString(), Carbon::parse($h->end_date)->toDateString()]);
            for ($day = $from->copy(); $holidays->isNotEmpty() && $day->lte($to); $day->addDay()) {
                $key = $day->toDateString();
                if (! isset($workedDates[$key]) && ! $user->isPayrollDayOff($day)
                    && $holidays->contains(fn (array $r) => $r[0] <= $key && $r[1] >= $key)) {
                    $result['holiday_days']++;
                }
            }
        }

        $result['missing_punches'] = $result['missing_check_in'] + $result['missing_check_out'];
        $result['paid_days'] = round($worked + $result['leave_days'] + $result['holiday_days'], 2);

        return $result;
    }

    /**
     * Tổng hợp chấm công của 1 người trong khoảng ngày (phiếu lương, màn chấm công). $payroll: ngày nghỉ cố định theo lịch tính lương (User::payrollDaysOff, có mặc định).
     *
     * @return array{days: int, late_count: int, late_minutes: int, excused_late: int, early_count: int, missing_out: int, leave_days: int}
     */
    public function summary(int $userId, CarbonInterface $start, CarbonInterface $end, bool $payroll = false): array
    {
        $rows = StaffAttendance::query()->where('user_id', $userId)
            ->whereDate('work_date', '>=', $start->toDateString())->whereDate('work_date', '<=', $end->toDateString())
            ->get(['check_in_at', 'check_out_at', 'late_minutes', 'early_minutes', 'late_excused', 'work_date']);
        $late = $rows->filter(fn (StaffAttendance $a) => $a->isLate());

        // Ngày nghỉ phép = ngày làm việc trong đơn nghỉ đã duyệt: bỏ ngày lễ của cơ sở, ngày nghỉ cố định trong tuần và
        // ngày nhân sự vẫn chấm công vào (đi làm dù có đơn); nhiều đơn trùng ngày chỉ tính 1 lần.
        $user = User::find($userId);
        $worked = $rows->whereNotNull('check_in_at')->map(fn (StaffAttendance $a) => $a->work_date->toDateString())->flip();
        $leaveDates = [];
        StaffAttendanceRequest::query()->approved()->where('user_id', $userId)
            ->where('type', StaffAttendanceRequest::TYPE_LEAVE)
            ->overlapping($start->toDateString(), $end->toDateString())
            ->get(['date_from', 'date_to'])
            ->each(function (StaffAttendanceRequest $r) use ($start, $end, $user, $worked, $payroll, &$leaveDates) {
                $to = $r->date_to->min($end->copy()->startOfDay());
                for ($day = $r->date_from->max($start->copy()->startOfDay())->copy(); $day->lte($to); $day->addDay()) {
                    $key = $day->toDateString();
                    if (isset($worked[$key]) || ($payroll ? $user?->isPayrollDayOff($day) : $user?->isWeeklyDayOff($day))
                        || Holiday::query()->coveringBranch($key, $user?->branch_id)->exists()) {
                        continue;
                    }
                    $leaveDates[$key] = true;
                }
            });
        $leaveDays = count($leaveDates);

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
