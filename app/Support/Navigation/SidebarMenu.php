<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * Menu sidebar + menu "Tạo mới" của layout ứng dụng.
 *
 * Nguyên tắc hiển thị (tránh lệch quyền giữa menu và route):
 *  1. Ability của mỗi item được ĐỌC TỰ ĐỘNG từ middleware `can:<ability>` của route
 *     (bao gồm middleware của group). User phải thỏa TẤT CẢ ability đó.
 *  2. `can` (tuỳ chọn) — danh sách ability bổ sung, thỏa MỘT trong số đó. Chỉ dùng
 *     cho route không có middleware `can:` (controller tự kiểm tra quyền) hoặc khi
 *     cần hạn chế hơn route (vd. quick-link "Lập phiếu thu" cần tuition.create).
 *  3. `roles` (tuỳ chọn) — item chỉ dành cho các role cụ thể (controller kiểm tra theo role).
 *  4. Nhóm có `roles` chỉ hiện với các role đó; nhóm không còn item nào sẽ bị ẩn.
 */
final class SidebarMenu
{
    private const TEACHER_ROLES = ['teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'];

    /** Role được StaffReportController cho phép (controller tự kiểm tra, route không có can:). */
    private const REPORT_ROLES = [
        'admin', 'manager', 'academic_staff', 'academic_lead',
        'teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant',
    ];

    private const STAFF_ROLES = [
        'admin', 'manager', 'accountant', 'academic_staff', 'academic_lead', 'sales_consultant',
        'teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant',
    ];

    /** @var array<string, list<string>> */
    private array $abilityCache = [];

    public function __construct(private readonly Router $router) {}

    /**
     * Khai báo menu. `active` là pattern cho Request::routeIs (mặc định = route của item).
     *
     * @return list<array{id: string, label: string, icon: string, roles?: list<string>, items: list<array<string, mixed>>}>
     */
    public function definition(): array
    {
        return [
            [
                'id' => 'crm',
                'label' => 'CRM & Tuyển sinh',
                'icon' => 'person_search',
                'roles' => ['admin', 'manager', 'sales_consultant'],
                'items' => [
                    ['label' => 'Bảng Kanban Leads', 'route' => 'crm.pipeline'],
                    ['label' => 'Danh sách Lead', 'route' => 'crm.customers.index', 'active' => ['crm.customers.index', 'crm.customers.show', 'crm.customers.edit', 'crm.customers.create']],
                    ['label' => 'Chờ xếp lớp', 'route' => 'crm.waiting-list'],
                    ['label' => 'Lead chưa liên hệ (SLA 24h)', 'route' => 'notifications.index', 'active' => ['notifications.*']],
                    ['label' => 'Học viên đã nhập học', 'route' => 'crm.customers.won'],
                    ['label' => 'Lead thất bại', 'route' => 'crm.lost-deals'],
                    ['label' => 'Báo cáo CRM & Tuyển sinh', 'route' => 'crm.reports'],
                ],
            ],
            [
                'id' => 'hr',
                'label' => 'Nhân sự & Vận hành',
                'icon' => 'badge',
                'roles' => ['admin', 'manager', 'academic_staff', 'academic_lead'],
                'items' => [
                    ['label' => 'Nhân sự & Tài khoản', 'route' => 'users.index', 'active' => ['users.*']],
                    ['label' => 'Nhật ký sự vụ học vụ', 'route' => 'reports.journal', 'roles' => self::REPORT_ROLES],
                    ['label' => 'Cấu hình KPI học vụ', 'route' => 'kpi.criteria'],
                    ['label' => 'Tổng hợp KPI tháng', 'route' => 'kpi.monthly', 'active' => ['kpi.monthly', 'kpi.evaluate']],
                    ['label' => 'Rà soát điểm danh', 'route' => 'kpi.attendance-review'],
                ],
            ],
            [
                'id' => 'students',
                'label' => 'Hồ sơ Học sinh',
                'icon' => 'school',
                'roles' => ['admin', 'manager', 'academic_staff', 'academic_lead'],
                'items' => [
                    ['label' => 'Học sinh & Liên kết lớp', 'route' => 'students.index', 'active' => ['students.index', 'students.show', 'students.edit', 'students.create']],
                    ['label' => 'Xác nhận nhập học', 'route' => 'students.enrollments'],
                ],
            ],
            [
                'id' => 'student_portal',
                'label' => 'Cổng Phụ huynh & Học sinh',
                'icon' => 'family_restroom',
                'roles' => ['student'],
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
            [
                'id' => 'classes',
                'label' => 'Lớp học & Lịch dạy',
                'icon' => 'meeting_room',
                'roles' => ['admin', 'manager', 'academic_staff', 'academic_lead'],
                'items' => [
                    ['label' => 'Đặt lịch học thử', 'route' => 'classes.trial-booking', 'active' => ['classes.trial-booking*']],
                    ['label' => 'Tạo lớp mới', 'route' => 'classes.create'],
                    ['label' => 'Hồ sơ lớp học', 'route' => 'classes.profile', 'active' => ['classes.profile*']],
                    ['label' => 'Sơ đồ khối lớp học thuật', 'route' => 'classes.academic-overview', 'active' => ['classes.academic-overview*']],
                    ['label' => 'Danh sách lớp chi tiết', 'route' => 'classes.academic-list', 'active' => ['classes.academic-list*']],
                    ['label' => 'Dashboard báo cáo đào tạo', 'route' => 'academic.dashboards.reports', 'can' => ['class.update']],
                    ['label' => 'Dashboard nhật ký sự vụ', 'route' => 'academic.dashboards.incidents', 'can' => ['class.update']],
                    ['label' => 'Dashboard lớp học theo ngày', 'route' => 'tasks.classes-dashboard'],
                    ['label' => 'Cấu hình Lịch & TKB lớp', 'route' => 'tasks.schedule-config'],
                    ['label' => 'Chốt học phí & Xếp lớp', 'route' => 'crm.closing-wizard'],
                ],
            ],
            [
                'id' => 'syllabus',
                'label' => 'Syllabus & Giáo trình',
                'icon' => 'menu_book',
                'roles' => ['admin', 'manager', 'academic_staff', 'academic_lead'],
                'items' => [
                    ['label' => 'Tài liệu & Giáo trình', 'route' => 'syllabus.documents'],
                    ['label' => 'Soạn Syllabus chặng', 'route' => 'syllabus.builder'],
                    ['label' => 'Giao chặng cho giáo viên', 'route' => 'syllabus.assignments'],
                    ['label' => 'Xem bài giảng (Cổng GV)', 'route' => 'syllabus.teacher-view'],
                    ['label' => 'Đề xuất sửa giáo trình', 'route' => 'syllabus.teacher-propose'],
                    ['label' => 'Duyệt đề xuất sửa giáo trình', 'route' => 'syllabus.versions'],
                    ['label' => 'Xin điều chỉnh tiến độ', 'route' => 'syllabus.teacher-adjust'],
                    ['label' => 'Duyệt điều chỉnh tiến độ', 'route' => 'syllabus.adjustment-requests'],
                    ['label' => 'Duyệt phân phối Big Test', 'route' => 'syllabus.big-tests.distribution'],
                    ['label' => 'Nhắc lịch Big Test', 'route' => 'syllabus.big-tests.schedules'],
                    ['label' => 'Bảng điểm & Kết quả Big Test', 'route' => 'syllabus.big-tests.results', 'active' => ['syllabus.big-tests.results*']],
                ],
            ],
            [
                'id' => 'teacher_schedule',
                'label' => 'Cổng Giáo viên & Giảng dạy',
                'icon' => 'co_present',
                'roles' => self::TEACHER_ROLES,
                'items' => [
                    ['label' => 'Check-in & Điểm danh hôm nay', 'route' => 'teacher.home', 'active' => ['teacher.home', 'teacher.attendance*'], 'can' => ['attendance_student.record']],
                    ['label' => 'Chấm bài nộp của lớp', 'route' => 'portal.teacher.submissions', 'active' => ['portal.teacher.submissions*'], 'roles' => array_merge(['admin'], self::TEACHER_ROLES)],
                    ['label' => 'Khách học thử', 'route' => 'teacher.trial-guests', 'active' => ['teacher.trial-guests*'], 'roles' => array_merge(['admin'], self::TEACHER_ROLES)],
                    ['label' => 'Nhiệm vụ hôm nay của TA', 'route' => 'portal.ta-tasks', 'can' => ['work_task.view']],
                ],
            ],
            [
                'id' => 'tuition',
                'label' => 'Học phí & Hoá đơn',
                'icon' => 'monetization_on',
                'roles' => ['admin', 'manager', 'accountant'],
                'items' => [
                    ['label' => 'Học viên & Thu phí', 'route' => 'tuition.students'],
                    ['label' => 'Nhập danh sách từ Excel', 'route' => 'tuition.import'],
                    ['label' => 'Lập phiếu thu học phí', 'route' => 'tuition.receipts.create'],
                    ['label' => 'Duyệt phiếu thu học phí', 'route' => 'tuition.receipts.approve', 'active' => ['tuition.receipts.approve*']],
                    ['label' => 'Lịch sử thu học phí', 'route' => 'tuition.history'],
                    ['label' => 'Duyệt / Hủy hóa đơn', 'route' => 'tuition.invoices.cancellations', 'active' => ['tuition.invoices.cancellations*']],
                    ['label' => 'Hoàn tiền & Khất nợ', 'route' => 'tuition.refunds', 'active' => ['tuition.refunds*']],
                    ['label' => 'Thu phí quá hạn & Nhắc phí', 'route' => 'tuition.overdue', 'active' => ['tuition.overdue*']],
                    ['label' => 'Cấu hình nhắc nợ', 'route' => 'system-config.debt-reminders'],
                    ['label' => 'Dải số hóa đơn & Ngân hàng', 'route' => 'tuition.config', 'active' => ['tuition.config*']],
                ],
            ],
            [
                'id' => 'finance',
                'label' => 'Báo cáo Thu - Chi',
                'icon' => 'query_stats',
                'roles' => ['admin', 'manager', 'accountant'],
                'items' => [
                    ['label' => 'Doanh thu tạm tính', 'route' => 'finance.reports.revenue', 'active' => ['finance.reports.*']],
                    ['label' => 'Sổ khoản chi vận hành', 'route' => 'finance.expenses.index', 'active' => ['finance.expenses.*']],
                ],
            ],
            [
                'id' => 'inventory',
                'label' => 'Hàng hoá & Danh mục',
                'icon' => 'inventory_2',
                'roles' => ['admin', 'manager', 'academic_staff'],
                'items' => [
                    ['label' => 'Hàng hóa & Vật phẩm', 'route' => 'merchandise.index', 'active' => ['merchandise.*']],
                ],
            ],
            [
                'id' => 'tasks',
                'label' => 'Phân công & Trợ giảng',
                'icon' => 'task_alt',
                'roles' => ['admin', 'manager', 'academic_staff', 'academic_lead'],
                'items' => [
                    ['label' => 'Công việc & Giao việc', 'route' => 'tasks.index', 'active' => ['tasks.index', 'tasks.show', 'tasks.create', 'tasks.edit']],
                    ['label' => 'Giao việc cho Trợ giảng', 'route' => 'tasks.ta-assign'],
                    ['label' => 'Báo cáo trực lớp TA', 'route' => 'tasks.class-reports.create'],
                    ['label' => 'Xác nhận hoàn thành việc', 'route' => 'tasks.manual-approvals'],
                    ['label' => 'Bảng KPI tự động', 'route' => 'tasks.kpi-dashboard'],
                ],
            ],
            [
                'id' => 'tickets',
                'label' => 'Hỗ trợ & Ticket',
                'icon' => 'confirmation_number',
                'roles' => self::STAFF_ROLES,
                'items' => [
                    ['label' => 'Danh sách ticket', 'route' => 'tickets.index', 'active' => ['tickets.index', 'tickets.show']],
                    ['label' => 'Tạo ticket mới', 'route' => 'tickets.create'],
                    ['label' => 'Cấu hình Email nhận Ticket', 'route' => 'system-config.ticket-emails', 'active' => ['system-config.ticket-emails*']],
                ],
            ],
            [
                'id' => 'timesheets',
                'label' => 'Chấm công',
                'icon' => 'schedule',
                'roles' => ['admin', 'manager', 'academic_staff', ...self::TEACHER_ROLES],
                'items' => [
                    ['label' => 'Chấm công đơn lẻ (GV & TA)', 'route' => 'payroll.timesheets.manual'],
                    ['label' => 'Giờ dạy & Chấm công giáo viên', 'route' => 'payroll.timesheets.teachers', 'can' => ['attendance_staff.view', 'payroll.view_own']],
                    ['label' => 'Chấm công AppSheet', 'route' => 'payroll.timesheets.appsheet'],
                    ['label' => 'Lịch sử đồng bộ chấm công', 'route' => 'payroll.timesheets.sync-history'],
                ],
            ],
            [
                'id' => 'payroll',
                'label' => 'Lương & Thưởng',
                'icon' => 'payments',
                'roles' => ['admin', 'manager', 'accountant', ...self::TEACHER_ROLES],
                'items' => [
                    ['label' => 'Bảng lương theo kỳ', 'route' => 'payroll.periods.index', 'active' => ['payroll.periods.*']],
                    ['label' => 'Lương của tôi', 'route' => 'portal.my-salary', 'can' => ['payroll.view_own']],
                    ['label' => 'Cấu hình tham số lương', 'route' => 'payroll.config.settings'],
                    ['label' => 'Đơn giá giáo viên', 'route' => 'payroll.config.teacher-rates'],
                    ['label' => 'Mốc hoa hồng / Thưởng', 'route' => 'payroll.config.commission-tiers'],
                    ['label' => 'Xếp hạng KPI & Thưởng', 'route' => 'payroll.kpi-leaderboard'],
                    ['label' => 'Vi phạm & Phạt', 'route' => 'penalties.index', 'active' => ['penalties.*']],
                ],
            ],
            [
                'id' => 'survey',
                'label' => 'Khảo sát / Test',
                'icon' => 'ballot',
                'roles' => ['admin', 'manager', 'academic_staff', 'academic_lead'],
                'items' => [
                    ['label' => 'Đề test đầu vào (AI)', 'route' => 'placement-tests.index', 'active' => ['placement-tests.index', 'placement-tests.create', 'placement-tests.show', 'placement-tests.edit', 'placement-tests.results.*']],
                    ['label' => 'Thang điểm & Hướng dẫn chấm', 'route' => 'placement-tests.rubric-guide'],
                    ['label' => 'Đợt khảo sát', 'route' => 'surveys.index', 'active' => ['surveys.*']],
                    ['label' => 'Bảng giá & Khóa học', 'route' => 'courses.index', 'active' => ['courses.*']],
                    ['label' => 'Khung trình độ (CEFR)', 'route' => 'course-levels.index', 'active' => ['course-levels.*']],
                ],
            ],
            [
                'id' => 'media',
                'label' => 'Media & Tệp tin',
                'icon' => 'perm_media',
                'roles' => ['admin', 'manager'],
                'items' => [
                    ['label' => 'Media & File lưu trữ', 'route' => 'media.index', 'active' => ['media.*']],
                ],
            ],
            [
                'id' => 'personal',
                'label' => 'Của tôi',
                'icon' => 'person',
                'roles' => self::REPORT_ROLES,
                'items' => [
                    ['label' => 'Báo cáo định kỳ của tôi', 'route' => 'reports.my', 'roles' => self::REPORT_ROLES],
                ],
            ],
            [
                'id' => 'permissions',
                'label' => 'Phân quyền & Hệ thống',
                'icon' => 'admin_panel_settings',
                'roles' => ['admin', 'manager'],
                'items' => [
                    ['label' => 'Cơ sở & Chi nhánh', 'route' => 'branches.index', 'active' => ['branches.*']],
                    ['label' => 'Vai trò', 'route' => 'roles.index', 'active' => ['roles.*']],
                    ['label' => 'Quyền', 'route' => 'permissions.index', 'active' => ['permissions.*']],
                    ['label' => 'Danh mục hệ thống', 'route' => 'system-categories.index', 'active' => ['system-categories.*']],
                    ['label' => 'Ngày nghỉ lễ', 'route' => 'holidays.index', 'active' => ['holidays.*']],
                    ['label' => 'Tài khoản ngân hàng', 'route' => 'system-config.bank-accounts'],
                    ['label' => 'Thông số hosting & máy chủ', 'route' => 'system-config.hosting'],
                    ['label' => 'Nhật ký vận hành', 'route' => 'activity-logs.index', 'active' => ['activity-logs.*']],
                    ['label' => 'Tổng hợp báo cáo & nhật ký', 'route' => 'reports.all', 'roles' => ['admin', 'manager']],
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
            ['label' => 'Tạo ticket hỗ trợ', 'icon' => 'confirmation_number', 'route' => 'tickets.create', 'roles' => self::STAFF_ROLES],
        ];
    }

    /**
     * Nhóm menu đã lọc theo quyền của user, kèm url + trạng thái active.
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
            if (! empty($group['roles']) && ! $user->hasAnyRole($group['roles'])) {
                continue;
            }

            $items = $this->visibleItems($user, $group['items'], $request);
            if ($items === []) {
                continue;
            }

            $group['items'] = $items;
            $group['is_active'] = collect($items)->contains('active', true);
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function quickCreateFor(?User $user): array
    {
        return $user ? $this->visibleItems($user, $this->quickCreateDefinition(), null) : [];
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
    public function canSee(User $user, array $item): bool
    {
        if (! $this->router->has($item['route'])) {
            return false;
        }

        foreach ($this->routeAbilities($item['route']) as $ability) {
            if (! $user->can($ability)) {
                return false;
            }
        }

        if (! empty($item['can']) && ! collect($item['can'])->contains(fn (string $ability) => $user->can($ability))) {
            return false;
        }

        if (! empty($item['roles']) && ! $user->hasAnyRole($item['roles'])) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function visibleItems(User $user, array $items, ?Request $request): array
    {
        $visible = [];
        foreach ($items as $item) {
            if (! $this->canSee($user, $item)) {
                continue;
            }

            $item['url'] = route($item['route']);
            $item['active'] = $request !== null && $request->routeIs(...($item['active'] ?? [$item['route']]));
            $visible[] = $item;
        }

        return $visible;
    }
}
