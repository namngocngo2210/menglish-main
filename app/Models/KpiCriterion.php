<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mục KPI Học vụ. weight = % của quỹ KPI (tổng các mục đang áp dụng = 100%);
 * số tiền tối đa của mục = quỹ × weight%. Nhóm / mã / ngưỡng theo mockup "KPI tháng".
 */
class KpiCriterion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'kpi_criteria';

    /**
     * 6 nhóm / 15 mục theo mockup 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/04_kpi_thang. Mockup ghi quỹ từng mục theo
     * một quỹ lớn hơn (tổng 13 triệu) → quy đổi theo quỹ 2.000.000đ/tháng (A6), giữ thứ tự ưu tiên giữa các mục.
     * Trọng số là % của quỹ (VD 15% = 300.000đ). Admin sửa được ở màn Cấu hình KPI.
     */
    public const DEFAULT_ACADEMIC_ITEMS = [
        ['group_name' => 'Chăm sóc học viên', 'code' => '1.1', 'name' => 'Nhắc học phí', 'weight' => 10, 'threshold_full' => '100%', 'threshold_half' => '80%', 'target' => '100% HV đến hạn được nhắc', 'description' => null],
        ['group_name' => 'Chăm sóc học viên', 'code' => '1.2', 'name' => 'Thu học phí', 'weight' => 15, 'threshold_full' => '95%', 'threshold_half' => '70%', 'target' => '≥ 95% học phí đến hạn được thu', 'description' => null],
        ['group_name' => 'Chăm sóc học viên', 'code' => '1.3', 'name' => 'Hỗ trợ học viên yếu', 'weight' => 5, 'threshold_full' => '10 HV', 'threshold_half' => '5 HV', 'target' => null, 'description' => null],
        ['group_name' => 'Chất lượng giảng dạy', 'code' => '2.1', 'name' => 'Tỉ lệ hoàn thành bài tập', 'weight' => 7.5, 'threshold_full' => '90%', 'threshold_half' => '75%', 'target' => null, 'description' => null],
        ['group_name' => 'Chất lượng giảng dạy', 'code' => '2.2', 'name' => 'Điểm danh đầy đủ', 'weight' => 5, 'threshold_full' => '95%', 'threshold_half' => '80%', 'target' => null, 'description' => null],
        ['group_name' => 'Chất lượng giảng dạy', 'code' => '2.3', 'name' => 'Đánh giá từ học viên', 'weight' => 7.5, 'threshold_full' => '4.5', 'threshold_half' => '4.0', 'target' => null, 'description' => null],
        ['group_name' => 'Chất lượng giảng dạy', 'code' => '2.4', 'name' => 'Feedback Big Test', 'weight' => 10, 'threshold_full' => '100%', 'threshold_half' => '80%', 'target' => null, 'description' => null],
        ['group_name' => 'Vận hành lớp', 'code' => '3.1', 'name' => 'Lên lịch học đúng hạn', 'weight' => 5, 'threshold_full' => '100%', 'threshold_half' => '90%', 'target' => null, 'description' => null],
        ['group_name' => 'Vận hành lớp', 'code' => '3.2', 'name' => 'Xử lý sự cố kỹ thuật', 'weight' => 5, 'threshold_full' => '< 2h', 'threshold_half' => '< 4h', 'target' => null, 'description' => null],
        ['group_name' => 'Quản lý Giáo viên', 'code' => '4.1', 'name' => 'Họp chuyên môn', 'weight' => 5, 'threshold_full' => '4 lần', 'threshold_half' => '2 lần', 'target' => null, 'description' => null],
        ['group_name' => 'Quản lý Giáo viên', 'code' => '4.2', 'name' => 'Tỷ lệ giữ chân GV', 'weight' => 7.5, 'threshold_full' => '100%', 'threshold_half' => '80%', 'target' => null, 'description' => null],
        ['group_name' => 'Phát triển Trung tâm', 'code' => '5.1', 'name' => 'Giới thiệu học viên mới', 'weight' => 7.5, 'threshold_full' => '5 HV', 'threshold_half' => '2 HV', 'target' => null, 'description' => null],
        ['group_name' => 'Phát triển Trung tâm', 'code' => '5.2', 'name' => 'Tham gia sự kiện', 'weight' => 2.5, 'threshold_full' => '2 sự kiện', 'threshold_half' => '1 sự kiện', 'target' => null, 'description' => null],
        ['group_name' => 'Chuyên môn khác', 'code' => '6.1', 'name' => 'Viết bài chuyên môn', 'weight' => 3.75, 'threshold_full' => '2 bài', 'threshold_half' => '1 bài', 'target' => null, 'description' => null],
        ['group_name' => 'Chuyên môn khác', 'code' => '6.2', 'name' => 'Đào tạo nội bộ', 'weight' => 3.75, 'threshold_full' => '1 buổi', 'threshold_half' => null, 'target' => null, 'description' => null],
    ];

    protected $fillable = [
        'group_name',
        'code',
        'name',
        'weight',
        'target',
        'threshold_full',
        'threshold_half',
        'unit',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Thứ tự hiển thị: theo thứ tự cấu hình, rồi mã, rồi id. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('code')->orderBy('id');
    }

    /** Quỹ KPI Học vụ / tháng (đ). */
    public static function fund(): float
    {
        return (float) SystemSetting::get('payroll_academic_kpi_fund', config('payroll.academic_kpi_fund', 2000000));
    }

    /** Số tiền tối đa của mục = quỹ × trọng số %. */
    public function fundAmount(?float $fund = null): float
    {
        return round(($fund ?? self::fund()) * (float) $this->weight / 100, 0);
    }
}
