<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use WeakMap;

/**
 * Nguồn duy nhất cho điều hướng ứng dụng: sidebar (1 mục = 1 workspace), tab của workspace, nút hành động của
 * workspace, menu con trang Cài đặt và menu "Tạo mới" trên topbar.
 *
 * Nguyên tắc hiển thị (tránh lệch quyền giữa menu và route):
 *  1. Ability của mỗi item được ĐỌC TỰ ĐỘNG từ middleware `can:<ability>` của route
 *     (bao gồm middleware của group). User phải thỏa TẤT CẢ ability đó.
 *  2. `can` (tuỳ chọn) — danh sách ability bổ sung, thỏa MỘT trong số đó. Chỉ dùng
 *     cho route không có middleware `can:` (controller tự kiểm tra quyền) hoặc khi
 *     cần hạn chế hơn route (vd. nút "Lập phiếu thu" cần tuition.create).
 *  3. `anchor` (item) / `can` (workspace) — quyền "neo" của khu nghiệp vụ, thỏa MỘT trong số đó (vd. tab Học phí cho
 *     người xử lý nghiệp vụ kế toán, Cổng giáo viên cho portal.teacher / portal.assistant). Workspace không còn tab
 *     nào sẽ bị ẩn. Không kiểm tra tên vai trò — Admin đổi quyền là menu đổi theo.
 *  4. Kết quả `can()` được nhớ theo request (WeakMap theo Request) nên sidebar + tab + Cài đặt chỉ hỏi Gate
 *     một lần cho mỗi ability.
 */
final class SidebarMenu
{
    /** Quyền đối tượng của cổng giáo viên / trợ giảng. */
    private const TEACHING_PORTAL = ['portal.teacher', 'portal.assistant'];

    /** Quyền "neo" của từng khu nghiệp vụ (xem nguyên tắc 3). */
    private const CRM = ['lead.create', 'lead.update']; // BA 26/09/2026: Học vụ là actor chính bên CRM.

    private const CLASS_MANAGER = ['class.update']; // Giáo viên xem lớp mình ở Cổng giáo viên.

    private const SYLLABUS_MANAGER = ['syllabus.manage']; // Giáo viên dùng mục giáo trình trong Cổng giáo viên.

    private const TUITION = ['tuition.approve', 'tuition.reject', 'invoice.request_cancel', 'refund_transfer.request'];

    private const HR = ['user.view', 'kpi.view'];

    private const TASK_ASSIGNER = ['work_task.create']; // GV / TA dùng "Nhiệm vụ hôm nay" ở Cổng giáo viên.

    private const STAFF = ['portal.staff'];

    private const PAYROLL = ['payroll.view', 'payroll.view_own'];

    private const SYSTEM = ['role.view', 'permission.view', 'branch.view', 'activity_log.view'];

    /** @var array<string, list<string>> */
    private array $abilityCache = [];

    /** @var WeakMap<Request, array<string, bool>> Kết quả can() theo request. */
    private WeakMap $allowCache;

    public function __construct(private readonly Router $router)
    {
        $this->allowCache = new WeakMap;
    }

    /**
     * Workspace của sidebar, chia theo khu (`section`, hiển thị tiêu đề khu). `items` là các tab (màn hình) của
     * workspace, `actions` là nút hành động (mở modal nếu có `modal`). `active` là pattern cho Request::routeIs
     * (mặc định = route của item). Mỗi route chỉ thuộc đúng một workspace.
     *
     * @return list<array{id: string, section: string, label: string, icon: string, can?: list<string>, items: list<array<string, mixed>>, actions?: list<array<string, mixed>>}>
     */
    public function definition(): array
    {
        return [
            [
                'id' => 'crm',
                'section' => 'Tuyển sinh',
                'label' => 'Khách hàng (CRM)',
                'icon' => 'person_search',
                // Tab: Kanban | Danh sách. Các màn còn lại hiển thị dạng lọc nhanh (`as` => chip, dưới tab `chip_of`)
                // hoặc trong menu thả xuống (`as` => menu / action có `menu`) để thanh tab gọn.
                'items' => self::anchored(self::CRM, [
                    ['label' => 'Kanban', 'route' => 'crm.pipeline'],
                    ['label' => 'Danh sách', 'route' => 'crm.customers.index', 'active' => ['crm.customers.index', 'crm.customers.show', 'crm.customers.edit', 'crm.customers.create', 'crm.import*']],
                    // Lọc theo query trên cùng route Danh sách (không phải màn riêng).
                    ['label' => 'Chưa liên hệ >24h', 'route' => 'crm.customers.index', 'query' => ['sla' => 1], 'as' => 'chip', 'chip_of' => 'crm.customers.index', 'count' => 'sla', 'tone' => 'danger'],
                    ['label' => 'Chờ xếp lớp', 'route' => 'crm.waiting-list', 'as' => 'chip', 'chip_of' => 'crm.customers.index', 'count' => 'waiting_class'],
                    ['label' => 'Đã nhập học', 'route' => 'crm.customers.won', 'as' => 'chip', 'chip_of' => 'crm.customers.index', 'count' => 'won'],
                    ['label' => 'Thất bại', 'route' => 'crm.lost-deals', 'as' => 'chip', 'chip_of' => 'crm.customers.index', 'count' => 'lost'],
                    ['label' => 'Đã xóa', 'route' => 'crm.customers.deleted', 'as' => 'chip', 'chip_of' => 'crm.customers.index', 'count' => 'deleted'],
                    ['label' => 'Xác nhận chính thức', 'route' => 'crm.confirmations', 'as' => 'menu', 'menu' => 'Xếp lớp', 'icon' => 'verified'],
                ]),
                'actions' => self::anchored(self::CLASS_MANAGER, [
                    ['label' => 'Chốt học phí & Xếp lớp', 'route' => 'crm.closing-wizard', 'icon' => 'how_to_reg', 'variant' => 'secondary', 'menu' => 'Xếp lớp'],
                ]),
            ],
            [
                'id' => 'trial',
                'section' => 'Tuyển sinh',
                'label' => 'Test đầu vào & học thử',
                'icon' => 'quiz',
                'items' => [
                    ...self::anchored(self::CLASS_MANAGER, [
                        ['label' => 'Lịch học thử', 'route' => 'classes.trial-booking', 'active' => ['classes.trial-booking*']],
                    ]),
                    ['label' => 'Đề test đầu vào (AI)', 'route' => 'placement-tests.index', 'active' => ['placement-tests.index', 'placement-tests.create', 'placement-tests.show', 'placement-tests.edit', 'placement-tests.results.*']],
                    ['label' => 'Thang điểm & Hướng dẫn chấm', 'route' => 'placement-tests.rubric-guide'],
                ],
            ],
            [
                'id' => 'students',
                'section' => 'Đào tạo',
                'label' => 'Học viên',
                'icon' => 'school',
                'items' => [
                    ['label' => 'Danh sách học viên', 'route' => 'students.index', 'active' => ['students.index', 'students.show', 'students.edit', 'students.create']],
                    ['label' => 'Xác nhận nhập học', 'route' => 'students.enrollments'],
                ],
            ],
            [
                'id' => 'classes',
                'section' => 'Đào tạo',
                'label' => 'Lớp học',
                'icon' => 'co_present',
                'items' => self::anchored(self::CLASS_MANAGER, [
                    ['label' => 'Hồ sơ lớp', 'route' => 'classes.profile', 'active' => ['classes.profile*']],
                    ['label' => 'Sơ đồ khối', 'route' => 'classes.academic-overview', 'active' => ['classes.academic-overview*']],
                    ['label' => 'Danh sách lớp chi tiết', 'route' => 'classes.academic-list', 'active' => ['classes.academic-list*']],
                    ['label' => 'Lớp theo ngày', 'route' => 'tasks.classes-dashboard'],
                    ['label' => 'Báo cáo đào tạo', 'route' => 'academic.dashboards.reports'],
                    ['label' => 'Nhật ký sự vụ lớp', 'route' => 'academic.dashboards.incidents'],
                ]),
            ],
            [
                'id' => 'syllabus',
                'section' => 'Đào tạo',
                'label' => 'Giáo trình',
                'icon' => 'menu_book',
                'items' => [
                    ...self::anchored(self::SYLLABUS_MANAGER, [
                        ['label' => 'Tài liệu', 'route' => 'syllabus.documents'],
                        ['label' => 'Soạn syllabus', 'route' => 'syllabus.builder'],
                        ['label' => 'Giao chặng', 'route' => 'syllabus.assignments'],
                        ['label' => 'Duyệt đề xuất sửa', 'route' => 'syllabus.versions'],
                        ['label' => 'Duyệt điều chỉnh tiến độ', 'route' => 'syllabus.adjustment-requests'],
                    ]),
                    // Phía giáo viên (mockup 03_Cong_Giao_Vien/07–11, 14): ai xem được giáo trình đều thấy.
                    ['label' => 'Chặng đang dạy & Order Test', 'route' => 'syllabus.teaching-stages', 'active' => ['syllabus.teaching-stages', 'teacher.order-test*']],
                    ['label' => 'Xem bài giảng', 'route' => 'syllabus.teacher-view'],
                    ['label' => 'Đề xuất sửa giáo trình', 'route' => 'syllabus.teacher-propose'],
                    ['label' => 'Xin điều chỉnh tiến độ', 'route' => 'syllabus.teacher-adjust'],
                ],
            ],
            [
                'id' => 'big_test',
                'section' => 'Đào tạo',
                'label' => 'Big Test',
                'icon' => 'assignment',
                'items' => self::anchored(self::SYLLABUS_MANAGER, [
                    ['label' => 'Duyệt phân phối', 'route' => 'syllabus.big-tests.distribution'],
                    ['label' => 'Nhắc lịch', 'route' => 'syllabus.big-tests.schedules'],
                    ['label' => 'Bảng điểm & Kết quả', 'route' => 'syllabus.big-tests.results', 'active' => ['syllabus.big-tests.results*']],
                ]),
            ],
            [
                'id' => 'surveys',
                'section' => 'Đào tạo',
                'label' => 'Khảo sát',
                'icon' => 'poll',
                'items' => [
                    ['label' => 'Đợt khảo sát', 'route' => 'surveys.index', 'active' => ['surveys.*']],
                ],
            ],
            [
                'id' => 'tuition',
                'section' => 'Tài chính',
                'label' => 'Học phí',
                'icon' => 'monetization_on',
                // Người xử lý nghiệp vụ kế toán (duyệt / trả về phiếu, hủy hóa đơn, hoàn / chuyển phí).
                'items' => self::anchored(self::TUITION, [
                    ['label' => 'Công nợ học viên', 'route' => 'tuition.students', 'active' => ['tuition.students', 'tuition.receipts.create', 'tuition.receipts.edit', 'tuition.import*']],
                    ['label' => 'Duyệt phiếu thu', 'route' => 'tuition.receipts.approve', 'active' => ['tuition.receipts.approve*']],
                    ['label' => 'Lịch sử thu', 'route' => 'tuition.history', 'active' => ['tuition.history*']],
                    ['label' => 'Hóa đơn', 'route' => 'tuition.invoices.cancellations', 'active' => ['tuition.invoices.cancellations*']],
                    ['label' => 'Hoàn tiền & Khất nợ', 'route' => 'tuition.refunds', 'active' => ['tuition.refunds*']],
                    ['label' => 'Quá hạn & Nhắc phí', 'route' => 'tuition.overdue', 'active' => ['tuition.overdue*']],
                ]),
                // Như nút cũ ở header trang Học phí: chỉ cần tuition.create (không neo kế toán).
                'actions' => [
                    ['label' => 'Nhập Excel', 'route' => 'tuition.import', 'icon' => 'upload_file', 'variant' => 'secondary', 'modal' => 'lg', 'can' => ['tuition.create']],
                    // Lập phiếu tự do giữ trang riêng (IX-3); lập từ dòng khoản học phí mới mở modal.
                    ['label' => 'Lập phiếu thu', 'route' => 'tuition.receipts.create', 'icon' => 'add_card', 'can' => ['tuition.create']],
                ],
            ],
            [
                'id' => 'finance',
                'section' => 'Tài chính',
                'label' => 'Thu - Chi',
                'icon' => 'query_stats',
                'items' => [
                    ['label' => 'Doanh thu tạm tính', 'route' => 'finance.reports.revenue', 'active' => ['finance.reports.*']],
                    ['label' => 'Khoản chi vận hành', 'route' => 'finance.expenses.index', 'active' => ['finance.expenses.*']],
                ],
            ],
            [
                'id' => 'merchandise',
                'section' => 'Tài chính',
                'label' => 'Kho vật phẩm',
                'icon' => 'inventory_2',
                'items' => [
                    ['label' => 'Hàng hóa & Vật phẩm', 'route' => 'merchandise.index', 'active' => ['merchandise.*']],
                ],
            ],
            [
                'id' => 'hr',
                'section' => 'Nhân sự',
                'label' => 'Nhân sự',
                'icon' => 'badge',
                'items' => [
                    ...self::anchored(self::HR, [
                        ['label' => 'Nhân sự & Tài khoản', 'route' => 'users.index', 'active' => ['users.*']],
                        ['label' => 'KPI tháng', 'route' => 'kpi.monthly', 'active' => ['kpi.monthly', 'kpi.evaluate']],
                        ['label' => 'Rà soát điểm danh', 'route' => 'kpi.attendance-review'],
                        ['label' => 'Nhật ký sự vụ học vụ', 'route' => 'reports.journal'],
                    ]),
                    ...self::anchored(self::TASK_ASSIGNER, [
                        ['label' => 'KPI tự động', 'route' => 'tasks.kpi-dashboard'],
                    ]),
                    ...self::anchored(self::PAYROLL, [
                        ['label' => 'Xếp hạng KPI & Thưởng', 'route' => 'payroll.kpi-leaderboard'],
                    ]),
                ],
            ],
            [
                'id' => 'tasks',
                'section' => 'Nhân sự',
                'label' => 'Công việc',
                'icon' => 'task_alt',
                'items' => self::anchored(self::TASK_ASSIGNER, [
                    ['label' => 'Danh sách công việc', 'route' => 'tasks.index', 'active' => ['tasks.index', 'tasks.show', 'tasks.create', 'tasks.edit', 'tasks.ta-assign', 'tasks.class-reports.*']],
                    ['label' => 'Xác nhận hoàn thành', 'route' => 'tasks.manual-approvals'],
                ]),
                'actions' => self::anchored(self::TASK_ASSIGNER, [
                    ['label' => 'Giao việc cho Trợ giảng', 'route' => 'tasks.ta-assign', 'icon' => 'support_agent', 'variant' => 'secondary', 'modal' => '4xl'],
                    ['label' => 'Báo cáo trực lớp', 'route' => 'tasks.class-reports.create', 'icon' => 'rate_review', 'variant' => 'secondary', 'modal' => '2xl'],
                ]),
            ],
            [
                'id' => 'tickets',
                'section' => 'Nhân sự',
                'label' => 'Ticket hỗ trợ',
                'icon' => 'confirmation_number',
                'items' => self::anchored(self::STAFF, [
                    ['label' => 'Ticket hỗ trợ', 'route' => 'tickets.index', 'active' => ['tickets.index', 'tickets.show', 'tickets.create']],
                ]),
            ],
            [
                'id' => 'timesheets',
                'section' => 'Nhân sự',
                'label' => 'Chấm công',
                'icon' => 'schedule',
                // Kế toán chỉ thấy tab chấm công nào Admin cấp quyền (attendance_staff.*).
                'items' => [
                    ['label' => 'Giờ dạy giáo viên', 'route' => 'payroll.timesheets.teachers', 'can' => ['attendance_staff.view', ...self::TEACHING_PORTAL]],
                    ['label' => 'Chấm công đơn lẻ', 'route' => 'payroll.timesheets.manual'],
                    ['label' => 'AppSheet', 'route' => 'payroll.timesheets.appsheet'],
                    ['label' => 'Lịch sử đồng bộ', 'route' => 'payroll.timesheets.sync-history'],
                ],
            ],
            [
                'id' => 'payroll',
                'section' => 'Nhân sự',
                'label' => 'Lương & Phạt',
                'icon' => 'payments',
                'items' => self::anchored(self::PAYROLL, [
                    ['label' => 'Bảng lương theo kỳ', 'route' => 'payroll.periods.index', 'active' => ['payroll.periods.*']],
                    ['label' => 'Vi phạm & Phạt', 'route' => 'penalties.index', 'active' => ['penalties.*']],
                ]),
            ],
            [
                'id' => 'reports',
                'section' => 'Báo cáo',
                'label' => 'Báo cáo',
                'icon' => 'monitoring',
                'items' => [
                    ...self::anchored(self::CRM, [
                        ['label' => 'Báo cáo tuyển sinh', 'route' => 'crm.reports'],
                    ]),
                ],
            ],
            [
                'id' => 'personal',
                'section' => 'Cá nhân',
                'label' => 'Của tôi',
                'icon' => 'person',
                'items' => [
                    ['label' => 'Báo cáo định kỳ của tôi', 'route' => 'reports.my'],
                    // Trung tâm thông báo (cũng mở từ chuông trên topbar).
                    ['label' => 'Thông báo', 'route' => 'notifications.index', 'active' => ['notifications.*']],
                    ['label' => 'Lương của tôi', 'route' => 'portal.my-salary', 'can' => ['payroll.view_own']],
                ],
            ],
            [
                'id' => 'teacher_portal',
                'section' => 'Cá nhân',
                'label' => 'Cổng Giáo viên',
                'icon' => 'cast_for_education',
                'items' => self::anchored(self::TEACHING_PORTAL, [
                    ['label' => 'Check-in & Điểm danh hôm nay', 'route' => 'teacher.home', 'active' => ['teacher.home', 'teacher.attendance*'], 'can' => ['attendance_student.record']],
                    ['label' => 'Chấm bài nộp của lớp', 'route' => 'portal.teacher.submissions', 'active' => ['portal.teacher.submissions*']],
                    ['label' => 'Khách học thử', 'route' => 'teacher.trial-guests', 'active' => ['teacher.trial-guests*']],
                    ['label' => 'Nhiệm vụ hôm nay của TA', 'route' => 'portal.ta-tasks', 'can' => ['work_task.view']],
                ]),
            ],
            [
                'id' => 'student_portal',
                'section' => 'Cá nhân',
                'label' => 'Cổng Phụ huynh & Học sinh',
                'icon' => 'family_restroom',
                'can' => ['portal.student'],
                'items' => [
                    ['label' => 'Trang chủ', 'route' => 'portal.student.home', 'active' => ['portal.student.home*']],
                    ['label' => 'Học tập & Nộp bài tập', 'route' => 'portal.student.homework', 'active' => ['portal.student.homework*']],
                    ['label' => 'Luyện phát âm AI', 'route' => 'portal.student.pronunciation', 'active' => ['portal.student.pronunciation*']],
                    ['label' => 'Hộp thư thông báo', 'route' => 'portal.student.notifications', 'active' => ['portal.student.notifications*']],
                    ['label' => 'Khảo sát 5 sao', 'route' => 'portal.student.survey', 'active' => ['portal.student.survey*']],
                    ['label' => 'Gửi góp ý', 'route' => 'portal.student.feedback', 'active' => ['portal.student.feedback*']],
                    ['label' => 'Ứng dụng di động', 'route' => 'portal.app-shell'],
                ],
            ],
        ];
    }

    /**
     * Menu con trang Cài đặt (cấu hình + danh mục), chia nhóm. URL cũ giữ nguyên, layout tự hiện menu con
     * khi route hiện tại thuộc một mục ở đây.
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    public function settingsDefinition(): array
    {
        return [
            [
                'label' => 'Tổ chức & Đào tạo',
                'items' => [
                    ...self::anchored(self::SYSTEM, [
                        ['label' => 'Cơ sở & Chi nhánh', 'route' => 'branches.index', 'active' => ['branches.*']],
                    ]),
                    ['label' => 'Khóa học & Bảng giá', 'route' => 'courses.index', 'active' => ['courses.*']],
                    ['label' => 'Trình độ (CEFR)', 'route' => 'course-levels.index', 'active' => ['course-levels.*']],
                    ...self::anchored(self::SYSTEM, [
                        ['label' => 'Ngày nghỉ lễ', 'route' => 'holidays.index', 'active' => ['holidays.*']],
                        ['label' => 'Danh mục hệ thống', 'route' => 'system-categories.index', 'active' => ['system-categories.*']],
                    ]),
                    ...self::anchored(self::CLASS_MANAGER, [
                        ['label' => 'Lịch & TKB lớp', 'route' => 'tasks.schedule-config'],
                    ]),
                    ...self::anchored(self::HR, [
                        ['label' => 'Tiêu chí KPI học vụ', 'route' => 'kpi.criteria'],
                    ]),
                ],
            ],
            [
                'label' => 'Lương',
                'items' => self::anchored(self::PAYROLL, [
                    ['label' => 'Tham số lương', 'route' => 'payroll.config.settings'],
                    ['label' => 'Đơn giá giáo viên', 'route' => 'payroll.config.teacher-rates'],
                    ['label' => 'Mốc hoa hồng / Thưởng', 'route' => 'payroll.config.commission-tiers'],
                ]),
            ],
            [
                'label' => 'Học phí',
                'items' => [
                    ...self::anchored(self::TUITION, [
                        ['label' => 'Nhắc nợ học phí', 'route' => 'system-config.debt-reminders'],
                        ['label' => 'Dải số hóa đơn', 'route' => 'tuition.config', 'active' => ['tuition.config*']],
                    ]),
                    ...self::anchored(self::SYSTEM, [
                        ['label' => 'Tài khoản ngân hàng', 'route' => 'system-config.bank-accounts'],
                    ]),
                ],
            ],
            [
                'label' => 'Hệ thống',
                'items' => [
                    ...self::anchored(self::STAFF, [
                        ['label' => 'Email nhận Ticket', 'route' => 'system-config.ticket-emails', 'active' => ['system-config.ticket-emails*']],
                    ]),
                    ...self::anchored(self::SYSTEM, [
                        ['label' => 'Vai trò', 'route' => 'roles.index', 'active' => ['roles.*']],
                        ['label' => 'Quyền', 'route' => 'permissions.index', 'active' => ['permissions.*']],
                        ['label' => 'Nhật ký vận hành', 'route' => 'activity-logs.index', 'active' => ['activity-logs.*']],
                        ['label' => 'Tổng hợp báo cáo & nhật ký', 'route' => 'reports.all'],
                    ]),
                    ['label' => 'Media & File lưu trữ', 'route' => 'media.index', 'active' => ['media.*']],
                    ...self::anchored(self::SYSTEM, [
                        ['label' => 'Hosting & Máy chủ', 'route' => 'system-config.hosting'],
                    ]),
                ],
            ],
        ];
    }

    /**
     * Các lối tắt của nút "Tạo mới" trên topbar.
     *
     * @return list<array<string, mixed>>
     */
    public function quickCreateDefinition(): array
    {
        return [
            ['label' => 'Thêm khách mới', 'icon' => 'person_add', 'route' => 'crm.customers.create', 'can' => ['lead.create']],
            ['label' => 'Tạo lớp', 'icon' => 'add_home', 'route' => 'classes.create', 'can' => ['class.create']],
            ['label' => 'Lập phiếu thu', 'icon' => 'receipt_long', 'route' => 'tuition.receipts.create', 'can' => ['tuition.create']],
            ['label' => 'Giao việc', 'icon' => 'assignment_add', 'route' => 'tasks.create', 'can' => ['work_task.create']],
            ['label' => 'Tạo ticket hỗ trợ', 'icon' => 'confirmation_number', 'route' => 'tickets.create', 'can' => ['portal.staff']],
        ];
    }

    /**
     * Workspace đã lọc theo quyền của user: `items` = tab được xem (kèm url + active), `actions` = nút hành động,
     * `url` = tab đầu tiên (link của sidebar), `is_active` = route hiện tại thuộc workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function groupsFor(?User $user, ?Request $request = null): array
    {
        if (! $user) {
            return [];
        }

        $groups = [];
        foreach ($this->definition() as $group) {
            if (! empty($group['can']) && ! $this->allowsAny($user, $group['can'], $request)) {
                continue;
            }

            $items = $this->visibleItems($user, $group['items'], $request);
            if ($items === []) {
                continue;
            }

            // Active theo mọi tab của workspace (kể cả tab user không thấy) để sidebar luôn đánh dấu đúng khu.
            $group['is_active'] = $request !== null && $this->matchesAny($request, $group['items']);
            $group['items'] = $items;
            $group['actions'] = $this->visibleItems($user, $group['actions'] ?? [], $request);
            $group['url'] = $items[0]['url'];
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Workspace chứa route hiện tại (hoặc theo `$id`), đã lọc quyền; null nếu route không thuộc workspace nào.
     * Tab đang mở giữ nguyên query (bộ lọc) nếu vẫn ở đúng route đó.
     *
     * @return array<string, mixed>|null
     */
    public function workspaceFor(?User $user, Request $request, ?string $id = null): ?array
    {
        if (! $user) {
            return null;
        }

        $definition = $id !== null
            ? collect($this->definition())->firstWhere('id', $id)
            : collect($this->definition())->first(fn (array $group) => $this->matchesAny($request, $group['items']));
        if (! $definition || (! empty($definition['can']) && ! $this->allowsAny($user, $definition['can'], $request))) {
            return null;
        }

        // Nút hành động hiện cả khi user không thấy tab nào (vd. Học vụ được lập phiếu thu nhưng không xử lý kế toán).
        $workspace = [
            ...$definition,
            'items' => $this->visibleItems($user, $definition['items'], $request),
            'actions' => $this->visibleItems($user, $definition['actions'] ?? [], $request),
        ];
        if ($workspace['items'] === [] && $workspace['actions'] === []) {
            return null;
        }

        $current = $request->route()?->getName();
        $workspace['items'] = array_map(
            fn (array $item) => $item['route'] === $current && empty($item['query']) && empty($item['as']) ? [...$item, 'url' => $request->fullUrl()] : $item,
            $workspace['items'],
        );

        return $workspace;
    }

    /**
     * Menu con Cài đặt đã lọc quyền (bỏ nhóm rỗng), kèm url + active.
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    public function settingsFor(?User $user, ?Request $request = null): array
    {
        if (! $user) {
            return [];
        }

        $sections = [];
        foreach ($this->settingsDefinition() as $section) {
            $items = $this->visibleItems($user, $section['items'], $request);
            if ($items !== []) {
                $sections[] = ['label' => $section['label'], 'items' => $items];
            }
        }

        return $sections;
    }

    /** Route hiện tại có thuộc trang Cài đặt không (không phụ thuộc quyền). */
    public function isSettingsRoute(Request $request): bool
    {
        return collect($this->settingsDefinition())->contains(fn (array $section) => $this->matchesAny($request, $section['items']));
    }

    /** URL mục Cài đặt đầu tiên user được xem (null nếu không có). */
    public function settingsUrlFor(?User $user, ?Request $request = null): ?string
    {
        return $this->settingsFor($user, $request)[0]['items'][0]['url'] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function quickCreateFor(?User $user): array
    {
        return $user ? $this->visibleItems($user, $this->quickCreateDefinition(), null) : [];
    }

    /**
     * Danh sách màn hình user được mở (tab workspace + Cài đặt), dùng cho tìm kiếm theo tên màn.
     * `title` = "Workspace › Tab" (hoặc "Cài đặt › Mục").
     *
     * @return list<array{title: string, label: string, url: string}>
     */
    public function screensFor(?User $user, ?Request $request = null): array
    {
        $screens = [];
        foreach ($this->groupsFor($user, $request) as $group) {
            foreach ($group['items'] as $item) {
                $title = $item['label'] === $group['label'] ? $group['label'] : $group['label'].' › '.$item['label'];
                $screens[] = ['title' => $title, 'label' => $item['label'], 'url' => $item['url']];
            }
        }
        foreach ($this->settingsFor($user, $request) as $section) {
            foreach ($section['items'] as $item) {
                $screens[] = ['title' => 'Cài đặt › '.$item['label'], 'label' => $item['label'], 'url' => $item['url']];
            }
        }

        return $screens;
    }

    /**
     * Màn hình có tên khớp từ khóa (không phân biệt hoa thường / dấu tiếng Việt).
     *
     * @return list<array{title: string, label: string, url: string}>
     */
    public function searchScreens(?User $user, string $term, ?Request $request = null): array
    {
        $needle = self::normalize($term);
        if (mb_strlen($needle) < 2) {
            return [];
        }

        return array_values(array_filter(
            $this->screensFor($user, $request),
            fn (array $screen) => str_contains(self::normalize($screen['title']), $needle),
        ));
    }

    /**
     * Ability lấy từ middleware `can:` của route (kể cả middleware của group route).
     *
     * @return list<string>
     */
    public function routeAbilities(string $routeName): array
    {
        if (array_key_exists($routeName, $this->abilityCache)) {
            return $this->abilityCache[$routeName];
        }

        $route = $this->router->getRoutes()->getByName($routeName);
        $abilities = [];
        foreach ((array) ($route?->middleware() ?? []) as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                // can:ability,model -> chỉ lấy ability (menu không có tham số model).
                $abilities[] = explode(',', substr($middleware, 4), 2)[0];
            }
        }

        return $this->abilityCache[$routeName] = array_values(array_unique($abilities));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function canSee(User $user, array $item, ?Request $request = null): bool
    {
        if (! $this->router->has($item['route'])) {
            return false;
        }

        foreach ($this->routeAbilities($item['route']) as $ability) {
            if (! $this->allows($user, $ability, $request)) {
                return false;
            }
        }

        foreach (['can', 'anchor'] as $key) {
            if (! empty($item[$key]) && ! $this->allowsAny($user, $item[$key], $request)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gắn quyền neo của một khu nghiệp vụ cho các item (thỏa MỘT trong số đó, cộng dồn với `can` của item).
     *
     * @param  list<string>  $anchor
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function anchored(array $anchor, array $items): array
    {
        return array_map(fn (array $item) => [...$item, 'anchor' => $anchor], $items);
    }

    private static function normalize(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
    }

    /** can() có nhớ kết quả theo request (không có request thì hỏi Gate trực tiếp). */
    private function allows(User $user, string $ability, ?Request $request): bool
    {
        if ($request === null) {
            return $user->can($ability);
        }

        $cache = $this->allowCache[$request] ?? [];
        $key = $user->getKey().'|'.$ability;
        if (! array_key_exists($key, $cache)) {
            $cache[$key] = $user->can($ability);
            $this->allowCache[$request] = $cache;
        }

        return $cache[$key];
    }

    /**
     * @param  list<string>  $abilities
     */
    private function allowsAny(User $user, array $abilities, ?Request $request): bool
    {
        foreach ($abilities as $ability) {
            if ($this->allows($user, $ability, $request)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function matchesAny(Request $request, array $items): bool
    {
        foreach ($items as $item) {
            if ($request->routeIs(...($item['active'] ?? [$item['route']]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function visibleItems(User $user, array $items, ?Request $request): array
    {
        $visible = [];
        foreach ($items as $item) {
            if (! $this->canSee($user, $item, $request)) {
                continue;
            }

            $item['url'] = route($item['route'], $item['query'] ?? []);
            $item['active'] = $request !== null && $request->routeIs(...($item['active'] ?? [$item['route']]))
                && collect($item['query'] ?? [])->every(fn ($value, $key) => (string) $request->query($key) === (string) $value);
            $visible[] = $item;
        }

        return $visible;
    }
}
