<?php

namespace App\Services\StaffAttendance;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassSession;
use App\Models\Holiday;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
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
 *    nhân sự khác theo giờ làm việc của cơ sở. Muộn quá số phút cho phép của cơ sở → tự lập biên bản "chờ giải trình",
 *    0đ (người chốt quyết mức phạt, giống biên bản SLA); đơn xin đi muộn được duyệt → không tính lỗi, biên bản tự hủy.
 *    GV / TA không lập biên bản ở đây: đi muộn đã trừ trên check-in ca dạy (tránh phạt 2 lần).
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
     * Biên bản tự động khi đi muộn: lập mới (chờ giải trình, 0đ), cập nhật số phút khi còn chờ giải trình,
     * hoặc tự hủy khi không còn muộn (đơn xin đi muộn được duyệt / bổ sung công sửa giờ).
     */
    public function syncLatePenalty(StaffAttendance $attendance): void
    {
        // Biên bản tự động của ngày công, bất kể người đang thao tác (duyệt đơn, sửa giờ) có thấy biên bản đó hay không.
        $penalty = $attendance->penalty()->withoutViewerScope()->first();
        $violation = 'Đi muộn '.$attendance->late_minutes.' phút (chấm công ngày '.$attendance->work_date->format('d/m/Y').')';

        // GV / TA: đi muộn đã tính trên check-in ca dạy (trừ tiền ca theo số phút muộn) — chấm công chỉ ghi nhận,
        // không lập thêm biên bản để không phạt 2 lần cho cùng một lần vào lớp muộn.
        if ($attendance->isLate() && ! $this->latePenalized($attendance)) {
            if ($penalty && in_array($penalty->status, ['pending', 'explained'], true)) {
                $penalty->update(['status' => 'cancelled', 'decided_at' => now(), 'decision_note' => 'Tự hủy: đi muộn của GV / TA tính theo check-in ca dạy.']);
            }

            return;
        }

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

    /** Đi muộn của ngày công có lập biên bản không: không với GV / TA (giờ phải có mặt theo buổi dạy). */
    private function latePenalized(StaffAttendance $attendance): bool
    {
        $user = $attendance->user;

        return ! ($user && ($user->can('portal.teacher') || $user->can('portal.assistant')));
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
     * tính lại (đi muộn không còn được miễn → biên bản tự lập lại). Đơn bổ sung công không thu hồi — sửa giờ công trực tiếp.
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

        // Ngày nghỉ phép = ngày làm việc trong đơn nghỉ đã duyệt: bỏ ngày lễ của cơ sở, ngày nghỉ cố định trong tuần và
        // ngày nhân sự vẫn chấm công vào (đi làm dù có đơn); nhiều đơn trùng ngày chỉ tính 1 lần.
        $user = User::find($userId);
        $worked = $rows->whereNotNull('check_in_at')->map(fn (StaffAttendance $a) => $a->work_date->toDateString())->flip();
        $leaveDates = [];
        StaffAttendanceRequest::query()->approved()->where('user_id', $userId)
            ->where('type', StaffAttendanceRequest::TYPE_LEAVE)
            ->overlapping($start->toDateString(), $end->toDateString())
            ->get(['date_from', 'date_to'])
            ->each(function (StaffAttendanceRequest $r) use ($start, $end, $user, $worked, &$leaveDates) {
                $to = $r->date_to->min($end->copy()->startOfDay());
                for ($day = $r->date_from->max($start->copy()->startOfDay())->copy(); $day->lte($to); $day->addDay()) {
                    $key = $day->toDateString();
                    if (isset($worked[$key]) || $user?->isWeeklyDayOff($day)
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
