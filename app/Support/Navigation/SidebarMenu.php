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
 *  3. `anchor` (item) / `can` (nhóm) — quyền "neo" của khu nghiệp vụ, thỏa MỘT trong số đó (vd. mục Học phí cho
 *     người xử lý nghiệp vụ kế toán, mục Cổng giáo viên cho portal.teacher / portal.assistant). Nhóm gộp mục của
 *     nhiều khu nên neo đặt theo item; nhóm không còn item nào sẽ bị ẩn. Không kiểm tra tên vai trò — Admin đổi
 *     quyền là menu đổi theo.
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

    public function __construct(private readonly Router $router) {}

    /**
     * Khai báo menu, chia theo khu (`section`, hiển thị tiêu đề khu). `active` là pattern cho Request::routeIs
     * (mặc định = route của item). Nhóm gộp từ nhiều khu nghiệp vụ nên quyền neo đặt ở từng item (`anchor`).
     *
     * @return list<array{id: string, section: string, label: string, icon: string, can?: list<string>, items: list<array<string, mixed>>}>
     */
    public function definition(): array
    {
        return [
            [
                'id' => 'crm',
                'section' => 'Tuyển sinh',
                'label' => 'CRM & Tuyển sinh',
                'icon' => 'person_search',
                'items' => [
                    ...self::anchored(self::CRM, [
                        ['label' => 'Bảng Kanban Leads', 'route' => 'crm.pipeline'],
                        ['label' => 'Danh sách Lead', 'route' => 'crm.customers.index', 'active' => ['crm.customers.index', 'crm.customers.show', 'crm.customers.edit', 'crm.customers.create']],
                        ['label' => 'Lead chưa liên hệ (SLA 24h)', 'route' => 'notifications.index', 'active' => ['notifications.*']],
                        ['label' => 'Chờ xếp lớp', 'route' => 'crm.waiting-list'],
                    ]),
                    ...self::anchored(self::CLASS_MANAGER, [
                        ['label' => 'Đặt lịch học thử', 'route' => 'classes.trial-booking', 'active' => ['classes.trial-booking*']],
                        ['label' => 'Chốt học phí & Xếp lớp', 'route' => 'crm.closing-wizard'],
                    ]),
                    ...self::anchored(self::CRM, [
                        ['label' => 'Học viên đã nhập học', 'route' => 'crm.customers.won'],
                        ['label' => 'Lead thất bại', 'route' => 'crm.lost-deals'],
                    ]),
                    ['label' => 'Đề test đầu vào (AI)', 'route' => 'placement-tests.index', 'active' => ['placement-tests.index', 'placement-tests.create', 'placement-tests.show', 'placement-tests.edit', 'placement-tests.results.*']],
                    ['label' => 'Thang điểm & Hướng dẫn chấm', 'route' => 'placement-tests.rubric-guide'],
                    ...self::anchored(self::CRM, [
                        ['label' => 'Báo cáo CRM & Tuyển sinh', 'route' => 'crm.reports'],
                    ]),
                ],
            ],
            [
                'id' => 'classes',
                'section' => 'Đào tạo',
                'label' => 'Học viên & Lớp học',
                'icon' => 'school',
                'items' => [
                    ['label' => 'Học sinh & Liên kết lớp', 'route' => 'students.index', 'active' => ['students.index', 'students.show', 'students.edit', 'students.create']],
                    ['label' => 'Xác nhận nhập học', 'route' => 'students.enrollments'],
                    ...self::anchored(self::CLASS_MANAGER, [
                        ['label' => 'Hồ sơ lớp học', 'route' => 'classes.profile', 'active' => ['classes.profile*']],
                        ['label' => 'Sơ đồ khối lớp học thuật', 'route' => 'classes.academic-overview', 'active' => ['classes.academic-overview*']],
                        ['label' => 'Danh sách lớp chi tiết', 'route' => 'classes.academic-list', 'active' => ['classes.academic-list*']],
                        ['label' => 'Dashboard lớp học theo ngày', 'route' => 'tasks.classes-dashboard'],
                        ['label' => 'Dashboard báo cáo đào tạo', 'route' => 'academic.dashboards.reports'],
                        ['label' => 'Dashboard nhật ký sự vụ', 'route' => 'academic.dashboards.incidents'],
                    ]),
                    ['label' => 'Đợt khảo sát', 'route' => 'surveys.index', 'active' => ['surveys.*']],
                ],
            ],
            [
                'id' => 'syllabus',
                'section' => 'Đào tạo',
                'label' => 'Giáo trình & Big Test',
                'icon' => 'menu_book',
                'items' => [
                    ...self::anchored(self::SYLLABUS_MANAGER, [
                        ['label' => 'Tài liệu & Giáo trình', 'route' => 'syllabus.documents'],
                        ['label' => 'Soạn Syllabus chặng', 'route' => 'syllabus.builder'],
                        ['label' => 'Giao chặng cho giáo viên', 'route' => 'syllabus.assignments'],
                        ['label' => 'Duyệt đề xuất sửa giáo trình', 'route' => 'syllabus.versions'],
                        ['label' => 'Duyệt điều chỉnh tiến độ', 'route' => 'syllabus.adjustment-requests'],
                        ['label' => 'Duyệt phân phối Big Test', 'route' => 'syllabus.big-tests.distribution'],
                        ['label' => 'Nhắc lịch Big Test', 'route' => 'syllabus.big-tests.schedules'],
                        ['label' => 'Bảng điểm & Kết quả Big Test', 'route' => 'syllabus.big-tests.results', 'active' => ['syllabus.big-tests.results*']],
                    ]),
                    // Phía giáo viên (mockup 03_Cong_Giao_Vien/07–11, 14): ai xem được giáo trình đều thấy.
                    ['label' => 'Chặng đang dạy & Order Test', 'route' => 'syllabus.teaching-stages', 'active' => ['syllabus.teaching-stages', 'teacher.order-test*']],
                    ['label' => 'Xem bài giảng (Cổng GV)', 'route' => 'syllabus.teacher-view'],
                    ['label' => 'Đề xuất sửa giáo trình', 'route' => 'syllabus.teacher-propose'],
                    ['label' => 'Xin điều chỉnh tiến độ', 'route' => 'syllabus.teacher-adjust'],
                ],
            ],
            [
                'id' => 'tuition',
                'section' => 'Tài chính',
                'label' => 'Học phí & Hoá đơn',
                'icon' => 'monetization_on',
                // Người xử lý nghiệp vụ kế toán (duyệt / trả về phiếu, hủy hóa đơn, hoàn / chuyển phí).
                'items' => self::anchored(self::TUITION, [
                    ['label' => 'Học viên & Thu phí', 'route' => 'tuition.students'],
                    ['label' => 'Nhập danh sách từ Excel', 'route' => 'tuition.import'],
                    ['label' => 'Lập phiếu thu học phí', 'route' => 'tuition.receipts.create'],
                    ['label' => 'Duyệt phiếu thu học phí', 'route' => 'tuition.receipts.approve', 'active' => ['tuition.receipts.approve*']],
                    ['label' => 'Lịch sử thu học phí', 'route' => 'tuition.history'],
                    ['label' => 'Duyệt / Hủy hóa đơn', 'route' => 'tuition.invoices.cancellations', 'active' => ['tuition.invoices.cancellations*']],
                    ['label' => 'Hoàn tiền & Khất nợ', 'route' => 'tuition.refunds', 'active' => ['tuition.refunds*']],
                    ['label' => 'Thu phí quá hạn & Nhắc phí', 'route' => 'tuition.overdue', 'active' => ['tuition.overdue*']],
                ]),
            ],
            [
                'id' => 'finance',
                'section' => 'Tài chính',
                'label' => 'Thu - Chi & Kho',
                'icon' => 'query_stats',
                'items' => [
                    ['label' => 'Doanh thu tạm tính', 'route' => 'finance.reports.revenue', 'active' => ['finance.reports.*']],
                    ['label' => 'Sổ khoản chi vận hành', 'route' => 'finance.expenses.index', 'active' => ['finance.expenses.*']],
                    ['label' => 'Hàng hóa & Vật phẩm', 'route' => 'merchandise.index', 'active' => ['merchandise.*']],
                ],
            ],
            [
                'id' => 'hr',
                'section' => 'Nhân sự',
                'label' => 'Nhân sự & KPI',
                'icon' => 'badge',
                'items' => [
                    ...self::anchored(self::HR, [
                        ['label' => 'Nhân sự & Tài khoản', 'route' => 'users.index', 'active' => ['users.*']],
                        ['label' => 'Nhật ký sự vụ học vụ', 'route' => 'reports.journal'],
                        ['label' => 'Tổng hợp KPI tháng', 'route' => 'kpi.monthly', 'active' => ['kpi.monthly', 'kpi.evaluate']],
                        ['label' => 'Rà soát điểm danh', 'route' => 'kpi.attendance-review'],
                    ]),
                    ...self::anchored(self::TASK_ASSIGNER, [
                        ['label' => 'Bảng KPI tự động', 'route' => 'tasks.kpi-dashboard'],
                    ]),
                    ...self::anchored(self::PAYROLL, [
                        ['label' => 'Xếp hạng KPI & Thưởng', 'route' => 'payroll.kpi-leaderboard'],
                    ]),
                ],
            ],
            [
                'id' => 'tasks',
                'section' => 'Nhân sự',
                'label' => 'Công việc & Ticket',
                'icon' => 'task_alt',
                'items' => [
                    ...self::anchored(self::TASK_ASSIGNER, [
                        ['label' => 'Công việc & Giao việc', 'route' => 'tasks.index', 'active' => ['tasks.index', 'tasks.show', 'tasks.create', 'tasks.edit']],
                        ['label' => 'Giao việc cho Trợ giảng', 'route' => 'tasks.ta-assign'],
                        ['label' => 'Báo cáo trực lớp TA', 'route' => 'tasks.class-reports.create'],
                        ['label' => 'Xác nhận hoàn thành việc', 'route' => 'tasks.manual-approvals'],
                    ]),
                    ...self::anchored(self::STAFF, [
                        ['label' => 'Ticket hỗ trợ', 'route' => 'tickets.index', 'active' => ['tickets.index', 'tickets.show', 'tickets.create']],
                    ]),
                ],
            ],
            [
                'id' => 'payroll',
                'section' => 'Nhân sự',
                'label' => 'Chấm công & Lương',
                'icon' => 'payments',
                'items' => [
                    // Kế toán chỉ thấy mục chấm công nào Admin cấp quyền (attendance_staff.*).
                    ['label' => 'Chấm công đơn lẻ (GV & TA)', 'route' => 'payroll.timesheets.manual'],
                    ['label' => 'Giờ dạy & Chấm công giáo viên', 'route' => 'payroll.timesheets.teachers', 'can' => ['attendance_staff.view', ...self::TEACHING_PORTAL]],
                    ['label' => 'Chấm công AppSheet', 'route' => 'payroll.timesheets.appsheet'],
                    ['label' => 'Lịch sử đồng bộ chấm công', 'route' => 'payroll.timesheets.sync-history'],
                    ...self::anchored(self::PAYROLL, [
                        ['label' => 'Bảng lương theo kỳ', 'route' => 'payroll.periods.index', 'active' => ['payroll.periods.*']],
                        ['label' => 'Vi phạm & Phạt', 'route' => 'penalties.index', 'active' => ['penalties.*']],
                    ]),
                ],
            ],
            [
                'id' => 'catalog',
                'section' => 'Hệ thống',
                'label' => 'Danh mục dùng chung',
                'icon' => 'category',
                'items' => [
                    ...self::anchored(self::SYSTEM, [
                        ['label' => 'Cơ sở & Chi nhánh', 'route' => 'branches.index', 'active' => ['branches.*']],
                    ]),
                    ['label' => 'Bảng giá & Khóa học', 'route' => 'courses.index', 'active' => ['courses.*']],
                    ['label' => 'Khung trình độ (CEFR)', 'route' => 'course-levels.index', 'active' => ['course-levels.*']],
                    ...self::anchored(self::SYSTEM, [
                        ['label' => 'Ngày nghỉ lễ', 'route' => 'holidays.index', 'active' => ['holidays.*']],
                        ['label' => 'Danh mục hệ thống', 'route' => 'system-categories.index', 'active' => ['system-categories.*']],
                    ]),
                    ['label' => 'Media & File lưu trữ', 'route' => 'media.index', 'active' => ['media.*']],
                ],
            ],
            [
                'id' => 'settings',
                'section' => 'Hệ thống',
                'label' => 'Cấu hình nghiệp vụ',
                'icon' => 'tune',
                'items' => [
                    ...self::anchored(self::CLASS_MANAGER, [
                        ['label' => 'Lịch & TKB lớp', 'route' => 'tasks.schedule-config'],
                    ]),
                    ...self::anchored(self::HR, [
                        ['label' => 'KPI học vụ', 'route' => 'kpi.criteria'],
                    ]),
                    ...self::anchored(self::PAYROLL, [
                        ['label' => 'Tham số lương', 'route' => 'payroll.config.settings'],
                        ['label' => 'Đơn giá giáo viên', 'route' => 'payroll.config.teacher-rates'],
                        ['label' => 'Mốc hoa hồng / Thưởng', 'route' => 'payroll.config.commission-tiers'],
                    ]),
                    ...self::anchored(self::TUITION, [
                        ['label' => 'Nhắc nợ học phí', 'route' => 'system-config.debt-reminders'],
                        ['label' => 'Dải số hóa đơn & Ngân hàng', 'route' => 'tuition.config', 'active' => ['tuition.config*']],
                    ]),
                    ...self::anchored(self::SYSTEM, [
                        ['label' => 'Tài khoản ngân hàng', 'route' => 'system-config.bank-accounts'],
                    ]),
                    ...self::anchored(self::STAFF, [
                        ['label' => 'Email nhận Ticket', 'route' => 'system-config.ticket-emails', 'active' => ['system-config.ticket-emails*']],
                    ]),
                ],
            ],
            [
                'id' => 'permissions',
                'section' => 'Hệ thống',
                'label' => 'Phân quyền & Nhật ký',
                'icon' => 'admin_panel_settings',
                'items' => self::anchored(self::SYSTEM, [
                    ['label' => 'Vai trò', 'route' => 'roles.index', 'active' => ['roles.*']],
                    ['label' => 'Quyền', 'route' => 'permissions.index', 'active' => ['permissions.*']],
                    ['label' => 'Nhật ký vận hành', 'route' => 'activity-logs.index', 'active' => ['activity-logs.*']],
                    ['label' => 'Tổng hợp báo cáo & nhật ký', 'route' => 'reports.all'],
                    ['label' => 'Thông số hosting & máy chủ', 'route' => 'system-config.hosting'],
                ]),
            ],
            [
                'id' => 'personal',
                'section' => 'Cá nhân',
                'label' => 'Của tôi',
                'icon' => 'person',
                'items' => [
                    ['label' => 'Báo cáo định kỳ của tôi', 'route' => 'reports.my'],
                    ['label' => 'Lương của tôi', 'route' => 'portal.my-salary', 'can' => ['payroll.view_own']],
                ],
            ],
            [
                'id' => 'teacher_portal',
                'section' => 'Cá nhân',
                'label' => 'Cổng Giáo viên',
                'icon' => 'co_present',
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
            if (! empty($group['can']) && ! collect($group['can'])->contains(fn (string $ability) => $user->can($ability))) {
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

        foreach (['can', 'anchor'] as $key) {
            if (! empty($item[$key]) && ! collect($item[$key])->contains(fn (string $ability) => $user->can($ability))) {
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
