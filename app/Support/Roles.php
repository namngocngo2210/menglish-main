<?php

namespace App\Support;

/**
 * Bộ vai trò CỐ ĐỊNH của hệ thống (tên vai trò = chức danh). Mọi logic nghiệp vụ theo chức danh (loại đơn giá dạy, công thức
 * lương / KPI, kỳ báo cáo, người nhận thông báo…) dùng hằng số ở đây thay vì gõ chuỗi tên vai trò. Quyền "được làm gì / thấy gì"
 * vẫn đi qua permission + DataScope, không dùng lớp này (xem docs/rbac.md, App\Support\StaffType).
 *
 * Tập quyền mặc định của từng vai trò: config/access.php (chỉ dùng khi cài mới; migration đảm bảo đủ vai trò trên hệ thống đang chạy).
 */
final class Roles
{
    /** Super Admin: bất biến, luôn toàn quyền (Rbac::SUPER_ADMIN). */
    public const ADMIN = 'admin';

    /** Quản lý cơ sở. */
    public const MANAGER = 'manager';

    /** Học thuật (Trưởng học thuật). */
    public const ACADEMIC_LEAD = 'academic_lead';

    /** Học vụ. */
    public const ACADEMIC_STAFF = 'academic_staff';

    /** Chuyên viên tư vấn tuyển sinh (Sales). */
    public const SALES_CONSULTANT = 'sales_consultant';

    public const TEACHER_FULLTIME = 'teacher_fulltime';

    public const TEACHER_PARTTIME = 'teacher_parttime';

    /** Trợ giảng. */
    public const ASSISTANT = 'assistant';

    public const STUDENT = 'student';

    /** Toàn bộ vai trò của hệ thống, theo thứ tự hiển thị. */
    public const ALL = [
        self::ADMIN,
        self::MANAGER,
        self::ACADEMIC_LEAD,
        self::ACADEMIC_STAFF,
        self::SALES_CONSULTANT,
        self::TEACHER_FULLTIME,
        self::TEACHER_PARTTIME,
        self::ASSISTANT,
        self::STUDENT,
    ];

    /** Giáo viên (full-time + part-time). */
    public const TEACHERS = [self::TEACHER_FULLTIME, self::TEACHER_PARTTIME];

    /** Nhân sự trực tiếp đứng lớp: giáo viên + trợ giảng. */
    public const TEACHING = [self::TEACHER_FULLTIME, self::TEACHER_PARTTIME, self::ASSISTANT];

    /** Khối đào tạo: giáo viên, trợ giảng, Học vụ, Học thuật. */
    public const ACADEMIC = [self::TEACHER_FULLTIME, self::TEACHER_PARTTIME, self::ASSISTANT, self::ACADEMIC_STAFF, self::ACADEMIC_LEAD];

    /** Vai trò có bộ tiêu chí KPI riêng (màn Tiêu chí KPI) và được chấm KPI tháng: mọi nhân sự, trừ Admin và Học viên. */
    public const KPI_ROLES = [self::ACADEMIC_STAFF, self::ACADEMIC_LEAD, self::MANAGER, self::SALES_CONSULTANT, self::TEACHER_FULLTIME, self::TEACHER_PARTTIME, self::ASSISTANT];

    /** Vai trò mặc định nhận việc / thông báo vận hành của cơ sở. */
    public const BRANCH_OPERATORS = [self::ACADEMIC_STAFF, self::MANAGER];

    /** Nhãn đầy đủ mặc định (Admin có thể đặt lại ở màn Vai trò: roles.label). */
    public const LABELS = [
        self::ADMIN => 'Quản trị viên Cấp cao (Admin)',
        self::MANAGER => 'Quản lý Cơ sở (Manager)',
        self::ACADEMIC_LEAD => 'Học thuật độc lập (Academic Lead)',
        self::ACADEMIC_STAFF => 'Học vụ (Academic Staff)',
        self::SALES_CONSULTANT => 'Chuyên viên Tư vấn Tuyển sinh (Sales)',
        self::TEACHER_FULLTIME => 'Giáo viên Fulltime',
        self::TEACHER_PARTTIME => 'Giáo viên Parttime',
        self::ASSISTANT => 'Trợ giảng (Teaching Assistant)',
        self::STUDENT => 'Học viên (Student)',
    ];

    /** Nhãn ngắn mặc định (bảng, ô chọn người nhận): "Admin", "Học thuật"… */
    public const SHORT_LABELS = [
        self::ADMIN => 'Admin',
        self::MANAGER => 'Quản lý cơ sở',
        self::ACADEMIC_LEAD => 'Học thuật',
        self::ACADEMIC_STAFF => 'Học vụ',
        self::SALES_CONSULTANT => 'Tư vấn viên',
        self::TEACHER_FULLTIME => 'Giáo viên Full-time',
        self::TEACHER_PARTTIME => 'Giáo viên Part-time',
        self::ASSISTANT => 'Trợ giảng',
        self::STUDENT => 'Học viên',
    ];

    /**
     * Vai trò đã bỏ khỏi hệ thống → vai trò nhận lại người đang giữ (migration dọn dẹp trên hệ thống còn dữ liệu cũ).
     * Kế toán & Thu ngân → Quản lý cơ sở; Giáo viên giảng dạy → Giáo viên Full-time.
     */
    public const RETIRED = [
        'accountant' => self::MANAGER,
        'teacher' => self::TEACHER_FULLTIME,
    ];

    public static function isFixed(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }
}
