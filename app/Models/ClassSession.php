<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClassSession extends Model
{
    use HasFactory;

    public const TYPE_REGULAR = 'regular';

    public const TYPE_SUPPORT = 'support';

    public const TYPE_MAKEUP = 'makeup';

    protected $table = 'class_sessions';

    protected $attributes = [
        'type' => self::TYPE_REGULAR,
    ];

    protected $fillable = [
        'class_id',
        'branch_id',
        'date',
        'shift_name',
        'type',
        'start_time',
        'end_time',
        'room',
        'teacher_id',
        'foreign_teacher_id',
        'assistant_id',
        'status',
        'notes',
        'holiday_id',
        'rescheduled_from_id',
    ];

    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
    ];

    /**
     * Chuẩn hóa giờ về H:i:s để so sánh chuỗi trong truy vấn (start_time < '18:00:00') cho kết quả
     * giống nhau trên MySQL (cột TIME) và SQLite (lưu nguyên chuỗi).
     */
    public function setStartTimeAttribute($value): void
    {
        $this->attributes['start_time'] = self::normalizeTime($value);
    }

    public function setEndTimeAttribute($value): void
    {
        $this->attributes['end_time'] = self::normalizeTime($value);
    }

    /** Chấm công dạy (check-in) phải làm trong vòng N giờ (SLA gv.checkin_window, mặc định 24h) kể từ GIỜ BẮT ĐẦU buổi; quá hạn GV mất công trừ khi Học vụ xác nhận. */
    public static function checkinWindowHours(): int
    {
        return \App\Services\Sla\Sla::value('gv.checkin_window');
    }

    /** Cảnh báo GV khi cửa sổ check-in còn dưới số phút này. */
    public const CHECKIN_WARNING_MINUTES = 15;

    /** Điểm danh học sinh của GV chỉ mở trong ±N giờ (SLA gv.attendance_window, mặc định 24h) quanh giờ bắt đầu buổi (Học vụ có attendance_student.record_any thì không giới hạn). */
    public static function attendanceWindowHours(): int
    {
        return \App\Services\Sla\Sla::value('gv.attendance_window');
    }

    /** Thời điểm bắt đầu buổi (ngày buổi + giờ bắt đầu). */
    public function startsAt(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse($this->date->format('Y-m-d').' '.($this->start_time?->format('H:i') ?? '00:00'));
    }

    /** Hạn chót check-in = giờ bắt đầu + checkinWindowHours(). */
    public function checkinDeadline(): \Illuminate\Support\Carbon
    {
        return $this->startsAt()->addHours(self::checkinWindowHours());
    }

    /** Cửa sổ điểm danh của GV: [bắt đầu − N giờ, bắt đầu + N giờ] (N = attendanceWindowHours()). */
    public function withinTeacherAttendanceWindow(?\Carbon\CarbonInterface $now = null): bool
    {
        $now ??= now();
        $start = $this->startsAt();
        $hours = self::attendanceWindowHours();

        return $now->gte($start->copy()->subHours($hours)) && $now->lte($start->copy()->addHours($hours));
    }

    /** Tên ca theo khung giờ ca dạy (Ca 1, Ca 2, Ca chiều…); tên tạm cũ "Slot n" không hiển thị. */
    public function shiftLabel(): ?string
    {
        $name = trim((string) $this->shift_name);

        return $name === '' || str_starts_with($name, 'Slot') ? null : $name;
    }

    /** Tên phòng để hiển thị: "Phòng P101"; tên đã có chữ "Phòng" (vd. "Phòng bổ trợ") giữ nguyên; chưa có phòng → null. */
    public function roomLabel(): ?string
    {
        $room = trim((string) $this->room);

        return match (true) {
            $room === '' => null,
            str_starts_with(mb_strtolower($room), 'phòng') => $room,
            default => 'Phòng '.$room,
        };
    }

    public static function normalizeTime($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        return is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) ? $value.':00' : $value;
    }

    public function classModel()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function assistant()
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }

    public function foreignTeacher()
    {
        return $this->belongsTo(User::class, 'foreign_teacher_id');
    }

    /** Ngày nghỉ lễ đã làm buổi này bị hủy (null nếu không phải do nghỉ lễ). */
    public function holiday()
    {
        return $this->belongsTo(Holiday::class, 'holiday_id');
    }

    /** Buổi gốc (bị hủy vì nghỉ lễ) mà buổi học bù này thay thế. */
    public function rescheduledFrom()
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    /** Buổi học bù được xếp cho buổi bị hủy này. */
    public function makeupSession(): HasOne
    {
        return $this->hasOne(self::class, 'rescheduled_from_id');
    }

    /**
     * Buổi có nhân sự này (GV chính, GVNN hoặc trợ giảng).
     */
    public function scopeForStaff(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('teacher_id', $userId)
            ->orWhere('foreign_teacher_id', $userId)
            ->orWhere('assistant_id', $userId));
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(StudentAttendance::class, 'class_session_id');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(TeacherTimesheet::class, 'class_session_id');
    }

    /** Khách CRM đặt học thử vào buổi này. */
    public function trialBookings(): HasMany
    {
        return $this->hasMany(CrmTrialBooking::class, 'class_session_id');
    }

    public function supportSession(): HasOne
    {
        return $this->hasOne(SupportSession::class, 'class_session_id');
    }

    /**
     * Buổi chính khóa chưa diễn ra (từ hôm nay), chưa điểm danh/check-in và không
     * gắn buổi phụ đạo: được phép sửa nhân sự/phòng hoặc xóa để xếp lại lịch.
     * Buổi đã có dữ liệu thực tế là lịch sử — không được ghi đè.
     */
    public function scopeReplaceable(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_REGULAR)
            ->where('status', 'scheduled')
            ->whereDate('date', '>=', now()->toDateString())
            ->whereDoesntHave('attendances')
            ->whereDoesntHave('timesheets')
            ->whereDoesntHave('supportSession')
            // Buổi đã có khách hẹn học thử: xóa đi thì lịch hẹn mồ côi, Sales không biết để báo khách.
            ->whereDoesntHave('trialBookings', fn (Builder $q) => $q->where('status', 'scheduled'));
    }

    /**
     * Buổi chưa diễn ra được đồng bộ nhân sự/phòng khi sửa lớp: buổi chính khóa + buổi học bù
     * (xếp tự động khi thêm ngày nghỉ), cùng điều kiện bảo vệ như replaceable() (chưa điểm danh,
     * chưa chấm công, không gắn phụ đạo). Không dùng để xóa — xếp lại TKB vẫn chỉ xóa buổi chính khóa.
     */
    public function scopeStaffSyncable(Builder $query): Builder
    {
        return $query->whereIn('type', [self::TYPE_REGULAR, self::TYPE_MAKEUP])
            ->where('status', 'scheduled')
            ->whereDate('date', '>=', now()->toDateString())
            ->whereDoesntHave('attendances')
            ->whereDoesntHave('timesheets')
            ->whereDoesntHave('supportSession');
    }
}
