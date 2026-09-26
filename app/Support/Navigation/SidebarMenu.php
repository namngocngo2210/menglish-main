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
 *  3. Nhóm có `can` (tuỳ chọn) chỉ hiện khi user có MỘT trong các quyền đó (quyền "neo" của khu vực, vd. nhóm
 *     Học phí cho người xử lý nghiệp vụ kế toán, nhóm Cổng giáo viên cho portal.teacher / portal.assistant);
 *     nhóm không còn item nào sẽ bị ẩn. Không kiểm tra tên vai trò — Admin đổi quyền là menu đổi theo.
 */
final class SidebarMenu
{
    /** Quyền đối tượng của cổng giáo viên / trợ giảng. */
    private const TEACHING_PORTAL = ['portal.teacher', 'portal.assistant'];

    /** @var array<string, list<string>> */
    private array $abilityCache = [];

    public function __construct(private readonly Router $router) {}

    /**
     * Khai báo menu. `active` là pattern cho Request::routeIs (mặc định = route của item).
     *
     * @return list<array{id: string, label: string, icon: string, can?: list<string>, items: list<array<string, mixed>>}>
     */
    public function definition(): array
    {
        return [
            [
                'id' => 'crm',
                'label' => 'CRM & Tuyển sinh',
                'icon' => 'person_search',
                // Người làm việc với khách (thêm / sửa khách). BA 26/09/2026: Học vụ là actor chính bên CRM.
                'can' => ['lead.create', 'lead.update'],
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
                'can' => ['user.view', 'kpi.view'],
                'items' => [
                    ['label' => 'Nhân sự & Tài khoản', 'route' => 'users.index', 'active' => ['users.*']],
                    ['label' => 'Nhật ký sự vụ học vụ', 'route' => 'reports.journal'],
                    ['label' => 'Cấu hình KPI học vụ', 'route' => 'kpi.criteria'],
                    ['label' => 'Tổng hợp KPI tháng', 'route' => 'kpi.monthly', 'active' => ['kpi.monthly', 'kpi.evaluate']],
                    ['label' => 'Rà soát điểm danh', 'route' => 'kpi.attendance-review'],
                ],
            ],
            [
                'id' => 'students',
                'label' => 'Hồ sơ Học sinh',
                'icon' => 'school',
                'items' => [
                    ['label' => 'Học sinh & Liên kết lớp', 'route' => 'students.index', 'active' => ['students.index', 'students.show', 'students.edit', 'students.create']],
                    ['label' => 'Xác nhận nhập học', 'route' => 'students.enrollments'],
                ],
            ],
            [
                'id' => 'student_portal',
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
            [
                'id' => 'classes',
                'label' => 'Lớp học & Lịch dạy',
                'icon' => 'meeting_room',
                // Người quản lý lớp (giáo viên xem lớp mình ở Cổng giáo viên).
                'can' => ['class.update'],
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
                // Người quản lý giáo trình (giáo viên dùng nhóm "Giáo trình & Big Test (GV)").
                'can' => ['syllabus.manage'],
                'items' => [
                    ['label' => 'Tài liệu & Giáo trình', 'route' => 'syllabus.documents'],
                    ['label' => 'Soạn Syllabus chặng', 'route' => 'syllabus.builder'],
                    ['label' => 'Giao chặng cho giáo viên', 'route' => 'syllabus.assignments'],
                    ['label' => 'Duyệt đề xuất sửa giáo trình', 'route' => 'syllabus.versions'],
                    ['label' => 'Duyệt điều chỉnh tiến độ', 'route' => 'syllabus.adjustment-requests'],
                    ['label' => 'Duyệt phân phối Big Test', 'route' => 'syllabus.big-tests.distribution'],
                    ['label' => 'Nhắc lịch Big Test', 'route' => 'syllabus.big-tests.schedules'],
                    ['label' => 'Bảng điểm & Kết quả Big Test', 'route' => 'syllabus.big-tests.results', 'active' => ['syllabus.big-tests.results*']],
                ],
            ],
            [
                // Cổng GV — nhóm Giáo trình & Big Test (mockup 03_Cong_Giao_Vien/07–11, 14)
                'id' => 'teacher_syllabus',
                'label' => 'Giáo trình & Big Test (GV)',
                'icon' => 'auto_stories',
                'items' => [
                    ['label' => 'Chặng đang dạy & Order Test', 'route' => 'syllabus.teaching-stages', 'active' => ['syllabus.teaching-stages', 'teacher.order-test*']],
                    ['label' => 'Xem bài giảng (Cổng GV)', 'route' => 'syllabus.teacher-view'],
                    ['label' => 'Đề xuất sửa giáo trình', 'route' => 'syllabus.teacher-propose'],
                    ['label' => 'Xin điều chỉnh tiến độ', 'route' => 'syllabus.teacher-adjust'],
                ],
            ],
            [
                'id' => 'teacher_schedule',
                'label' => 'Cổng Giáo viên & Giảng dạy',
                'icon' => 'co_present',
                'can' => self::TEACHING_PORTAL,
                'items' => [
                    ['label' => 'Check-in & Điểm danh hôm nay', 'route' => 'teacher.home', 'active' => ['teacher.home', 'teacher.attendance*'], 'can' => ['attendance_student.record']],
                    ['label' => 'Chấm bài nộp của lớp', 'route' => 'portal.teacher.submissions', 'active' => ['portal.teacher.submissions*']],
                    ['label' => 'Khách học thử', 'route' => 'teacher.trial-guests', 'active' => ['teacher.trial-guests*']],
                    ['label' => 'Nhiệm vụ hôm nay của TA', 'route' => 'portal.ta-tasks', 'can' => ['work_task.view']],
                ],
            ],
            [
                'id' => 'tuition',
                'label' => 'Học phí & Hoá đơn',
                'icon' => 'monetization_on',
                // Người xử lý nghiệp vụ kế toán (duyệt / trả về phiếu, hủy hóa đơn, hoàn / chuyển phí).
                'can' => ['tuition.approve', 'tuition.reject', 'invoice.request_cancel', 'refund_transfer.request'],
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
                'items' => [
                    ['label' => 'Doanh thu tạm tính', 'route' => 'finance.reports.revenue', 'active' => ['finance.reports.*']],
                    ['label' => 'Sổ khoản chi vận hành', 'route' => 'finance.expenses.index', 'active' => ['finance.expenses.*']],
                ],
            ],
            [
                'id' => 'inventory',
                'label' => 'Hàng hoá & Danh mục',
                'icon' => 'inventory_2',
                'items' => [
                    ['label' => 'Hàng hóa & Vật phẩm', 'route' => 'merchandise.index', 'active' => ['merchandise.*']],
                ],
            ],
            [
                'id' => 'tasks',
                'label' => 'Phân công & Trợ giảng',
                'icon' => 'task_alt',
                // Người giao việc (GV / TA dùng "Nhiệm vụ hôm nay" ở Cổng giáo viên).
                'can' => ['work_task.create'],
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
                'can' => ['portal.staff'],
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
                // Kế toán chỉ thấy mục nào Admin cấp quyền (attendance_staff.*) — mặc định không có mục nào nên nhóm ẩn.
                'items' => [
                    ['label' => 'Chấm công đơn lẻ (GV & TA)', 'route' => 'payroll.timesheets.manual'],
                    ['label' => 'Giờ dạy & Chấm công giáo viên', 'route' => 'payroll.timesheets.teachers', 'can' => ['attendance_staff.view', ...self::TEACHING_PORTAL]],
                    ['label' => 'Chấm công AppSheet', 'route' => 'payroll.timesheets.appsheet'],
                    ['label' => 'Lịch sử đồng bộ chấm công', 'route' => 'payroll.timesheets.sync-history'],
                ],
            ],
            [
                'id' => 'payroll',
                'label' => 'Lương & Thưởng',
                'icon' => 'payments',
                'can' => ['payroll.view', 'payroll.view_own'],
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
                'items' => [
                    ['label' => 'Media & File lưu trữ', 'route' => 'media.index', 'active' => ['media.*']],
                ],
            ],
            [
                'id' => 'personal',
                'label' => 'Của tôi',
                'icon' => 'person',
                'items' => [
                    ['label' => 'Báo cáo định kỳ của tôi', 'route' => 'reports.my'],
                ],
            ],
            [
                'id' => 'permissions',
                'label' => 'Phân quyền & Hệ thống',
                'icon' => 'admin_panel_settings',
                'can' => ['role.view', 'permission.view', 'branch.view', 'activity_log.view'],
                'items' => [
                    ['label' => 'Cơ sở & Chi nhánh', 'route' => 'branches.index', 'active' => ['branches.*']],
                    ['label' => 'Vai trò', 'route' => 'roles.index', 'active' => ['roles.*']],
                    ['label' => 'Quyền', 'route' => 'permissions.index', 'active' => ['permissions.*']],
                    ['label' => 'Danh mục hệ thống', 'route' => 'system-categories.index', 'active' => ['system-categories.*']],
                    ['label' => 'Ngày nghỉ lễ', 'route' => 'holidays.index', 'active' => ['holidays.*']],
                    ['label' => 'Tài khoản ngân hàng', 'route' => 'system-config.bank-accounts'],
                    ['label' => 'Thông số hosting & máy chủ', 'route' => 'system-config.hosting'],
                    ['label' => 'Nhật ký vận hành', 'route' => 'activity-logs.index', 'active' => ['activity-logs.*']],
                    ['label' => 'Tổng hợp báo cáo & nhật ký', 'route' => 'reports.all'],
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

        if (! empty($item['can']) && ! collect($item['can'])->contains(fn (string $ability) => $user->can($ability))) {
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
