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
            ->whereDoesntHave('supportSession');
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
