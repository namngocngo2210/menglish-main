<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tiêu chí KPI theo vai trò cố định (role = Roles::*). weight = trọng số (% quỹ KPI ở Học vụ, điểm tối đa ở GV part-time;
 * tổng các mục đang áp dụng = 100). Cách tính (measure):
 *  - count: đếm số lần (càng ít càng tốt), đơn vị chọn từ UNITS; bậc = [số lần tối đa, % điểm], vượt bậc cuối = 0%.
 *    Bộ Học vụ dùng 2 bậc max_full (100%) / max_half (50%).
 *  - rate: tỉ lệ % (càng cao càng tốt); bậc = [từ %, % điểm], hoặc linear = điểm theo tỉ lệ, đủ điểm khi đạt full_at %.
 *  - rate_down: tỉ lệ % (càng thấp càng tốt, vd. % lỗi lặp lại); bậc = [tỉ lệ tối đa, % điểm], vượt bậc cuối = 0%.
 * per_month: phiếu theo quý tính từng tháng rồi lấy trung bình. knockout: có từ 1 lần là mất toàn bộ KPI kỳ.
 * allow_na: kỳ không phát sinh đơn vị đánh giá → "Không phát sinh", bỏ khỏi cả tử số và mẫu số của tổng KPI.
 * Bộ Học vụ theo file KPI Học vụ (Excel, chấm tháng); bộ GV part-time theo file KPI GV part-time; bộ Học thuật theo file
 * KPI Học thuật V11 (chấm tháng, xếp loại A–E, thưởng KPI = mức tối đa × hệ số xếp loại).
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

    /**
     * Nguồn số liệu tự động (khóa lưu ở auto_source => nhãn): hệ thống tự đếm từ dữ liệu sẵn có, người chấm không điền.
     * Tiêu chí không có nguồn → người chấm điền tay trên phiếu KPI tháng. Cách đếm: App\Services\Kpi\KpiAutoCounter.
     */
    public const AUTO_SOURCES = [
        'tuition_no_reminder' => 'Học phí quá hạn chưa có lần nhắc',
        'receipt_rejected' => 'Phiếu thu bị từ chối duyệt',
        'care_overdue' => 'Mốc chăm sóc tháng đầu quá hạn',
        'crm_sla_late' => 'Hạn SLA CRM bị trễ (liên hệ, follow, kết quả test, phản hồi học thử)',
        'daily_report_late' => 'Báo cáo ngày nộp sau 9h sáng hôm sau',
        'material_stock' => 'Học liệu xử lý trễ hạn, kiểm kê sách lệch',
        // Giáo viên (đếm số lần)
        'teacher_absent_unexcused' => 'Buổi dạy không có giờ dạy và không có đơn nghỉ được duyệt',
        'teacher_leave' => 'Ngày nghỉ dạy có đơn được duyệt',
        'teacher_late' => 'Buổi dạy check-in muộn',
        // Giáo viên (tỉ lệ %; kỳ chưa có dữ liệu thì người chấm điền tay)
        'test_result' => 'Kết quả test: điểm TB × 50% + tỷ lệ đạt chuẩn × 50%',
        'test_progress' => '% điểm test tăng (lần cuối so lần đầu trong kỳ)',
        'class_attendance_rate' => 'Tỷ lệ chuyên cần học sinh các lớp',
        'homework_rate' => 'Tỷ lệ nộp bài tập về nhà đúng hạn',
        'monthly_report_on_time' => 'Tỷ lệ tháng nộp báo cáo tháng trước 12h ngày mùng 2',
        // Học thuật (tỉ lệ %; kỳ không phát sinh thì "Không phát sinh" hoặc người chấm điền tay)
        'academic_deliverable_on_time' => 'Mốc dự án học thuật đúng hạn (hệ số trễ theo ngày)',
        'task_on_time' => 'Việc được giao đúng hạn (hệ số trễ theo giờ)',
        'academic_order_on_time' => 'Order học liệu học thuật giao đúng SLA trước giờ dùng',
        'student_pass_rate' => 'Tỷ lệ bài test học sinh đạt chuẩn, 3 tháng gần nhất',
        // Nhân sự văn phòng (đếm số lần)
        'staff_late' => 'Ngày chấm công đi muộn quá 10 phút',
    ];

    /** Nguồn tự động cho số liệu tỉ lệ % (còn lại đếm số lần). */
    public const RATE_SOURCES = [
        'test_result', 'test_progress', 'class_attendance_rate', 'homework_rate', 'monthly_report_on_time',
        'academic_deliverable_on_time', 'task_on_time', 'academic_order_on_time', 'student_pass_rate',
    ];

    /** Cách đo: đếm số lần / tỉ lệ %. */
    public const MEASURES = [
        'count' => 'Đếm số lần (càng ít càng tốt)',
        'rate' => 'Tỉ lệ % (càng cao càng tốt)',
        'rate_down' => 'Tỉ lệ % (càng thấp càng tốt)',
    ];

    /** Đơn vị của tiêu chí đo bằng tỉ lệ. */
    public const RATE_UNIT = 'phan_tram';

    /** Vai trò xếp loại A–E theo tổng % đạt (file KPI GV part-time). Mọi vai trò chấm theo tháng, Admin đổi sang quý ở Tiêu chí KPI. */
    public const GRADED_ROLES = [Roles::TEACHER_PARTTIME, Roles::ACADEMIC_LEAD];

    /**
     * Thưởng KPI tối đa / tháng theo xếp loại (đ): thưởng = mức này × hệ số xếp loại. Học thuật 2.000.000đ (file KPI Học thuật,
     * trả ngoài lương; chạy thử chưa vào bảng lương). Admin sửa ở màn Tiêu chí KPI (SystemSetting kpi_grade_fund_{role}).
     */
    public const GRADE_FUND_DEFAULTS = [Roles::ACADEMIC_LEAD => 2000000];

    /** Chu kỳ chấm KPI (số tháng mỗi phiếu => nhãn). */
    public const CYCLES = [1 => 'Theo tháng', 3 => 'Theo quý'];

    /**
     * Xếp loại KPI theo tổng % đạt (file KPI GV part-time): [hạng, từ %, ý nghĩa, hệ số trả lương KPI %, đạt lên bậc].
     */
    public const GRADES = [
        ['A', 95, 'Xuất sắc', 100, true],
        ['B', 85, 'Tốt', 85, true],
        ['C', 70, 'Đạt', 70, false],
        ['D', 50, 'Cần cải thiện', 50, false],
        ['E', 0, 'Không đạt', 0, false],
    ];

    /** Ability xem "KPI của tôi" (Gate định nghĩa ở AppServiceProvider: người có vai trò đang có tiêu chí KPI). */
    public const OWN_ABILITY = 'view-own-kpi';

    /** Vai trò có bộ tiêu chí KPI (mọi vai trò nhân sự; Admin và Học viên không chấm KPI). */
    public const ROLES = Roles::KPI_ROLES;

    public static function unitLabel(?string $unit): string
    {
        return $unit === self::RATE_UNIT ? '%' : (self::UNITS[$unit] ?? (string) $unit);
    }

    /** Nhãn ngưỡng hiển thị từ số lần tối đa: "0 hồ sơ", "≤ 4 case". */
    public static function thresholdLabel(?int $max, ?string $unit): ?string
    {
        if ($max === null) {
            return null;
        }

        return trim(($max === 0 ? '0' : '≤ '.$max).' '.self::unitLabel($unit));
    }

    /** Số liệu do hệ thống tự đếm (không điền tay). */
    public function isAuto(): bool
    {
        return $this->auto_source !== null && isset(self::AUTO_SOURCES[$this->auto_source]);
    }

    /** Nguồn tự động trả tỉ lệ % (kỳ chưa có dữ liệu thì người chấm điền tay). */
    public function isRateSource(): bool
    {
        return $this->isAuto() && in_array($this->auto_source, self::RATE_SOURCES, true);
    }

    /** Đo bằng tỉ lệ % (càng cao hoặc càng thấp càng tốt). */
    public function isRate(): bool
    {
        return in_array($this->measure, ['rate', 'rate_down'], true);
    }

    /** Số càng nhỏ càng tốt (đếm số lần, tỉ lệ % càng thấp càng tốt): bậc = ngưỡng tối đa. */
    public function isLowerBetter(): bool
    {
        return $this->measure !== 'rate';
    }

    /**
     * Bậc tính điểm đã chuẩn hóa: [[ngưỡng, % điểm], …]. Đếm số lần: ngưỡng tăng dần (số lần tối đa); tỉ lệ: ngưỡng giảm dần
     * (từ %). Tiêu chí đếm cũ không có bậc riêng dùng max_full (100%) / max_half (50%).
     *
     * @return list<array{0: float, 1: float}>
     */
    public function tierList(): array
    {
        $tiers = collect(is_array($this->tiers) ? $this->tiers : [])
            ->filter(fn ($t) => is_array($t) && is_numeric($t[0] ?? null) && is_numeric($t[1] ?? null))
            ->map(fn ($t) => [(float) $t[0], (float) $t[1]]);
        if ($tiers->isEmpty() && ! $this->isRate() && $this->max_full !== null && $this->max_half !== null) {
            $tiers = collect([[(float) $this->max_full, 100.0], [(float) $this->max_half, 50.0]]);
        }

        return ($this->isLowerBetter() ? $tiers->sortBy(0) : $tiers->sortByDesc(0))->values()->all();
    }

    /** Tiêu chí có quy tắc tính mức đạt từ số liệu (tiêu chí % cũ không có quy tắc: người chấm chọn % trực tiếp). */
    public function hasRule(): bool
    {
        return ($this->measure === 'rate' && $this->linear) || $this->tierList() !== [];
    }

    /** Tiêu chí đếm số lần có bậc tính điểm. */
    public function isCountBased(): bool
    {
        return ! $this->isRate() && $this->tierList() !== [];
    }

    /** Ngưỡng tỉ lệ đạt đủ điểm khi tính thẳng theo tỉ lệ (mặc định 100%). */
    public function fullAt(): float
    {
        return $this->full_at !== null && (float) $this->full_at > 0 ? (float) $this->full_at : 100.0;
    }

    /**
     * Mức đạt (% điểm của tiêu chí) từ số liệu: đếm số lần → bậc đầu tiên có số lần ≤ ngưỡng, vượt bậc cuối = 0;
     * tỉ lệ → bậc đầu tiên có tỉ lệ ≥ ngưỡng (dưới bậc cuối = 0), hoặc thẳng theo tỉ lệ (0–100%). Null nếu không có quy tắc.
     */
    public function levelFor(int|float $value): int|float|null
    {
        if (! $this->hasRule()) {
            return null;
        }
        $whole = fn (float $v) => floor($v) === $v ? (int) $v : $v;
        if ($this->measure === 'rate' && $this->linear) {
            return $whole(round(max(0.0, min(100.0, $value / $this->fullAt() * 100)), 2));
        }
        foreach ($this->tierList() as [$at, $percent]) {
            if ($this->isLowerBetter() ? $value <= $at : $value >= $at) {
                return $whole($percent);
            }
        }

        return 0;
    }

    /** Giữ cho mã cũ: mức đạt từ số lần. */
    public function levelForCount(int|float $count): int|float|null
    {
        return $this->isCountBased() ? $this->levelFor($count) : null;
    }

    /** Diễn giải cách tính cho màn hình: "≤ 1 lần: 100% · 2: 50% · từ 3: 0%", "≥ 90%: 100% · dưới 80%: 0%", "Theo tỉ lệ, đủ điểm khi đạt 10%". */
    public function ruleLabel(): ?string
    {
        if ($this->knockout) {
            return 'Có từ 1 '.self::unitLabel($this->unit).': mất toàn bộ KPI kỳ';
        }
        if ($this->measure === 'rate' && $this->linear) {
            return 'Theo tỉ lệ, đủ điểm khi đạt '.self::number($this->fullAt()).'%';
        }
        $tiers = $this->tierList();
        if ($tiers === []) {
            return null;
        }
        $parts = [];
        if ($this->measure === 'rate_down') {
            foreach ($tiers as [$at, $percent]) {
                $parts[] = ($at > 0 ? '≤ ' : '').self::number($at).'%: '.self::number($percent).'%';
            }
            $parts[] = 'trên '.self::number(end($tiers)[0]).'%: 0%';

            return implode(' · ', $parts);
        }
        if ($this->isRate()) {
            foreach ($tiers as [$at, $percent]) {
                $parts[] = '≥ '.self::number($at).'%: '.self::number($percent).'%';
            }
            $parts[] = 'dưới '.self::number(end($tiers)[0]).'%: 0%';

            return implode(' · ', $parts);
        }
        $previous = -1;
        foreach ($tiers as $i => [$at, $percent]) {
            $range = match (true) {
                $at <= $previous + 1 => self::number($at),
                $previous < 0 => '≤ '.self::number($at),
                default => self::number($previous + 1).'–'.self::number($at),
            };
            $parts[] = $range.($i === 0 ? ' '.self::unitLabel($this->unit) : '').': '.self::number($percent).'%';
            $previous = $at;
        }
        $parts[] = 'từ '.self::number($previous + 1).': 0%';

        return implode(' · ', $parts);
    }

    /** Quy tắc gửi xuống giao diện để tính mức đạt ngay khi điền (cùng cách tính với levelFor). */
    public function ruleForUi(): array
    {
        return [
            'measure' => $this->isRate() ? 'rate' : 'count',
            'lower_better' => $this->isLowerBetter(),
            'tiers' => $this->tierList(),
            'linear' => $this->measure === 'rate' && (bool) $this->linear,
            'full_at' => $this->fullAt(),
            'has_rule' => $this->hasRule(),
            'knockout' => (bool) $this->knockout,
        ];
    }

    /** Số gọn: 62.5 → "62,5", 100.0 → "100". */
    public static function number(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }

    /** Chu kỳ chấm KPI của vai trò (số tháng mỗi phiếu): 1 = tháng, 3 = quý. */
    public static function periodMonths(?string $role): int
    {
        if ($role === null) {
            return 1;
        }
        $setting = SystemSetting::get('kpi_cycle_'.$role);

        return (int) ($setting ?? 1) === 3 ? 3 : 1;
    }

    /** Thưởng KPI tối đa / tháng theo xếp loại của vai trò (đ), null = vai trò không có khoản thưởng này. */
    public static function gradeFund(?string $role): ?float
    {
        if ($role === null) {
            return null;
        }
        $value = SystemSetting::get('kpi_grade_fund_'.$role) ?? (self::GRADE_FUND_DEFAULTS[$role] ?? null);

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /** Vai trò có xếp loại A–E (GV part-time, Học thuật; phiếu quý của vai trò khác cũng xếp loại). */
    public static function hasGrades(?string $role, int $months = 1): bool
    {
        return in_array($role, self::GRADED_ROLES, true) || $months === 3;
    }

    /**
     * Xếp loại theo tổng % đạt: ['grade' => 'B', 'label' => 'Tốt', 'pay' => 85, 'pass' => true]. $cap = hạng cao nhất được xếp
     * (điều kiện chặn, vd. có order xong sau giờ dùng → tối đa B).
     */
    public static function gradeFor(float $total, ?string $cap = null): array
    {
        $capped = $cap === null;
        foreach (self::GRADES as [$grade, $from, $label, $pay, $pass]) {
            $capped = $capped || $grade === $cap;
            if ($capped && $total >= $from) {
                return ['grade' => $grade, 'label' => $label, 'pay' => $pay, 'pass' => $pass];
            }
        }

        return ['grade' => 'E', 'label' => 'Không đạt', 'pay' => 0, 'pass' => false];
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
        'measure',
        'tiers',
        'linear',
        'full_at',
        'per_month',
        'knockout',
        'allow_na',
        'auto_source',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'max_full' => 'integer',
        'max_half' => 'integer',
        'tiers' => 'array',
        'linear' => 'boolean',
        'full_at' => 'decimal:2',
        'per_month' => 'boolean',
        'knockout' => 'boolean',
        'allow_na' => 'boolean',
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
