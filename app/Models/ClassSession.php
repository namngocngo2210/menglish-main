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
        'assistant_id',
        'status',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
    ];

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
}
