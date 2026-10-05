<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tiêu chí KPI theo vai trò cố định (role = Roles::*). weight = % của quỹ KPI vai trò (tổng các mục đang áp dụng = 100%);
 * số tiền tối đa của mục = quỹ × weight%. Đo bằng số lần trong tháng, đơn vị chọn từ danh sách UNITS (dữ liệu chuẩn hóa).
 * Bộ Học vụ mặc định theo file KPI Học vụ (Excel).
 */
class KpiCriterion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'kpi_criteria';

    /**
     * Bộ tiêu chí KPI Học vụ theo file "KPI Học vụ / Điều phối vận hành MEducation": 6 nhóm / 15 tiêu chí, quỹ 2.000.000đ/tháng.
     * Mỗi tiêu chí đo bằng SỐ LẦN lỗi / case trễ trong tháng (càng ít càng tốt) và chỉ có 3 mức: ≤ max_full → 100%,
     * ≤ max_half → 50%, vượt → 0%. weight = % của quỹ (quỹ tiêu chí ÷ 2.000.000đ). Admin sửa được ở màn Tiêu chí KPI.
     */
    public const DEFAULT_ACADEMIC_ITEMS = [
        ['group_name' => 'Học phí & dữ liệu', 'code' => '1.1', 'name' => 'Quy trình nhắc & follow học phí đúng hạn', 'weight' => 12.5, 'unit' => 'ho_so', 'max_full' => 0, 'max_half' => 2, 'description' => 'Số hồ sơ KHÔNG thực hiện đúng quy trình nhắc học phí (không nhắc, nhắc 1 lần rồi bỏ, không follow, không báo cáo founder khi phụ huynh không hợp tác). Không đo việc phụ huynh có đóng tiền hay không.'],
        ['group_name' => 'Học phí & dữ liệu', 'code' => '1.2', 'name' => 'Thu đúng học phí', 'weight' => 7.5, 'unit' => 'lan', 'max_full' => 0, 'max_half' => 1, 'description' => 'Số lần thu sai/thiếu học phí trong tháng (sai số tiền, bỏ sót giảm trừ, thu nhầm gói học).'],
        ['group_name' => 'Học phí & dữ liệu', 'code' => '1.3', 'name' => 'Dữ liệu học sinh/học phí trên hệ thống', 'weight' => 5, 'unit' => 'sai_sot', 'max_full' => 0, 'max_half' => 2, 'description' => 'Số sai sót dữ liệu trên webapp/hệ thống trong tháng (nhập sai, thiếu, cập nhật trễ).'],
        ['group_name' => 'Học viên & phụ huynh', 'code' => '2.1', 'name' => 'SLA phản hồi phụ huynh', 'weight' => 7.5, 'unit' => 'case', 'max_full' => 4, 'max_half' => 10, 'description' => 'Số case phản hồi trễ SLA trong tháng qua Zalo Saleswork (SLA: trong ngày làm việc).'],
        ['group_name' => 'Học viên & phụ huynh', 'code' => '2.2', 'name' => 'Case học sinh bỏ sót', 'weight' => 2.5, 'unit' => 'case', 'max_full' => 0, 'max_half' => 1, 'description' => 'Số case học sinh đang học bị bỏ sót (nghỉ không được follow/nhắc học bù, vấn đề không được ghi nhận). Chỉ tính nếu chưa bị tính ở 2.3.'],
        ['group_name' => 'Học viên & phụ huynh', 'code' => '2.3', 'name' => 'Founder phải can thiệp trực tiếp', 'weight' => 5, 'unit' => 'lan', 'max_full' => 4, 'max_half' => 10, 'description' => 'Số lần founder phải trực tiếp xử lý thay/can thiệp trong tháng do học vụ xử lý chưa tới nơi tới chốn.'],
        ['group_name' => 'Học viên & phụ huynh', 'code' => '2.4', 'name' => 'Feedback phụ huynh theo từng lớp (mốc Big Test)', 'weight' => 5, 'unit' => 'lop', 'max_full' => 0, 'max_half' => 1, 'description' => 'Số lớp đến mốc Big Test trong tháng (~16–18 buổi/lần) nhưng CHƯA đạt tối thiểu 40% học sinh của lớp phản hồi feedback, CỘNG số feedback tiêu cực chưa được xử lý/báo cáo.'],
        ['group_name' => 'Tuyển sinh & Truyền thông', 'code' => '3.1', 'name' => 'Xử lý data/test tuyển sinh', 'weight' => 10, 'unit' => 'case', 'max_full' => 0, 'max_half' => 2, 'description' => 'Số data/case tuyển sinh bị bỏ sót hoặc xử lý trễ trong tháng (không liên hệ, không sắp lịch test, không follow sau test).'],
        ['group_name' => 'Tuyển sinh & Truyền thông', 'code' => '3.2', 'name' => 'Truyền thông — đăng bài đúng hạn', 'weight' => 10, 'unit' => 'lan', 'max_full' => 0, 'max_half' => 2, 'description' => 'Số lần không đăng bài truyền thông đúng hạn/kế hoạch được phân công trong tháng. Chỉ chấm đúng hạn, không chấm chất lượng nội dung.'],
        ['group_name' => 'Báo cáo & tuân thủ quy trình', 'code' => '4.1', 'name' => 'Nộp báo cáo đúng hạn', 'weight' => 7.5, 'unit' => 'lan', 'max_full' => 4, 'max_half' => 10, 'description' => 'Số lần nộp báo cáo vận hành ngày trễ hạn (trước 9h sáng hôm sau) trong tháng.'],
        ['group_name' => 'Báo cáo & tuân thủ quy trình', 'code' => '4.2', 'name' => 'Nội dung báo cáo đầy đủ, đúng form', 'weight' => 3.75, 'unit' => 'lan', 'max_full' => 4, 'max_half' => 10, 'description' => 'Số lần báo cáo thiếu mục / sai nội dung / ghi chung chung trong tháng.'],
        ['group_name' => 'Báo cáo & tuân thủ quy trình', 'code' => '4.3', 'name' => 'Tuân thủ quy trình chuyên môn đã đào tạo', 'weight' => 3.75, 'unit' => 'lan', 'max_full' => 4, 'max_half' => 10, 'description' => 'Số lần làm sai quy trình đã được đào tạo trong tháng (bàn giao ca, xử lý học phí, test/tuyển sinh…).'],
        ['group_name' => 'Vận hành lớp học', 'code' => '5.1', 'name' => 'Sự cố vận hành lớp do lỗi học vụ', 'weight' => 10, 'unit' => 'su_co', 'max_full' => 4, 'max_half' => 10, 'description' => 'Số sự cố vận hành lớp trong tháng do lỗi học vụ (thiếu GV/TG không có phương án thay thế, lớp bị bỏ trống, sai lịch).'],
        ['group_name' => 'Vận hành lớp học', 'code' => '5.2', 'name' => 'Cơ sở vật chất & xuất nhập sách', 'weight' => 5, 'unit' => 'sai_sot', 'max_full' => 4, 'max_half' => 10, 'description' => 'Số sai sót trong tháng về cơ sở vật chất, xuất nhập sách, kiểm kê, chuẩn bị phòng học.'],
        ['group_name' => 'Giáo viên & phối hợp', 'code' => '6.1', 'name' => 'Phối hợp thông tin với giáo viên', 'weight' => 5, 'unit' => 'lan', 'max_full' => 4, 'max_half' => 10, 'description' => 'Số lần phối hợp sai/chậm với giáo viên gây ảnh hưởng buổi dạy trong tháng.'],
    ];

    /** Đơn vị đếm (khóa lưu DB => nhãn). Chọn từ danh sách, không gõ tay, để số liệu KPI cùng một chuẩn. */
    public const UNITS = [
        'lan' => 'lần',
        'ho_so' => 'hồ sơ',
        'case' => 'case',
        'sai_sot' => 'sai sót',
        'su_co' => 'sự cố',
        'lop' => 'lớp',
        'buoi' => 'buổi',
        'hoc_vien' => 'học viên',
        'bai' => 'bài',
        'ngay' => 'ngày',
    ];

    /** Vai trò có bộ tiêu chí KPI (mọi vai trò nhân sự; Admin và Học viên không chấm KPI). */
    public const ROLES = Roles::KPI_ROLES;

    public static function unitLabel(?string $unit): string
    {
        return self::UNITS[$unit] ?? (string) $unit;
    }

    /** Nhãn ngưỡng hiển thị từ số lần tối đa: "0 hồ sơ", "≤ 4 case". */
    public static function thresholdLabel(?int $max, ?string $unit): ?string
    {
        if ($max === null) {
            return null;
        }

        return trim(($max === 0 ? '0' : '≤ '.$max).' '.self::unitLabel($unit));
    }

    /** Tiêu chí đo bằng số lần (có ngưỡng tối đa cho mức 100% / 50%). */
    public function isCountBased(): bool
    {
        return $this->max_full !== null && $this->max_half !== null;
    }

    /**
     * Mức đạt từ số lần thực tế: ≤ ngưỡng 100% → 100, ≤ ngưỡng 50% → 50, vượt → 0 (không nội suy). Null nếu tiêu chí không đo bằng số lần.
     */
    public function levelForCount(int|float $count): ?int
    {
        if (! $this->isCountBased()) {
            return null;
        }

        return match (true) {
            $count <= $this->max_full => 100,
            $count <= $this->max_half => 50,
            default => 0,
        };
    }

    protected $fillable = [
        'role',
        'group_name',
        'code',
        'name',
        'weight',
        'target',
        'threshold_full',
        'threshold_half',
        'max_full',
        'max_half',
        'unit',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'max_full' => 'integer',
        'max_half' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Tiêu chí của một vai trò. */
    public function scopeForRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /** Vai trò KPI của nhân sự: vai trò đầu tiên của người đó nằm trong danh sách có bộ tiêu chí. */
    public static function roleFor(User $user): ?string
    {
        return $user->getRoleNames()->first(fn (string $role) => in_array($role, self::ROLES, true));
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
