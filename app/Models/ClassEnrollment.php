<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassEnrollment extends Model
{
    use HasFactory;

    protected $table = 'class_enrollments';

    protected $fillable = [
        'student_id',
        'class_id',
        'customer_id',
        'enrolled_at',
        'curriculum_delivered',
        'zalo_group_added',
        'account_sent',
        'status',
        'confirmed_at',
        'confirmed_by',
    ];

    /** Checklist "Xác nhận chính thức" sau khi chốt: cột => nhãn. */
    public const CONFIRMATION_CHECKLIST = [
        'account_sent' => 'Đã gửi tài khoản học viên',
        'zalo_group_added' => 'Đã vào nhóm Zalo lớp',
        'curriculum_delivered' => 'Đã nhận giáo trình',
    ];

    protected $casts = [
        'enrolled_at' => 'date',
        'curriculum_delivered' => 'boolean',
        'zalo_group_added' => 'boolean',
        'account_sent' => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'customer_id');
    }
}
