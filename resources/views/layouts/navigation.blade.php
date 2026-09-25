@php
    $user = Auth::user();

    $canAccess = function ($permission = null) use ($user) {
        if (!$user) return false;
        try {
            if ($user->hasRole('admin')) return true;
            if (!$permission) return true;

            if (is_array($permission)) {
                foreach ($permission as $perm) {
                    if (str_contains($perm, '.')) {
                        [$mod, $act] = explode('.', $perm, 2);
                        if ($user->hasModuleAction($mod, $act)) return true;
                    } elseif ($user->can($perm)) {
                        return true;
                    }
                }
                return false;
            }

            if (str_contains($permission, '.')) {
                [$mod, $act] = explode('.', $permission, 2);
                return $user->hasModuleAction($mod, $act);
            }

            return (bool) $user->can($permission);
        } catch (\Throwable $e) {
            return $user->hasRole('admin');
        }
    };

    $rawMenuGroups = [
        [
            'id' => 'crm',
            'label' => 'CRM & Tuyển sinh',
            'icon' => 'group',
            'route_check' => 'crm.*',
            'items' => [
                ['label' => 'Bảng Kanban Leads', 'url' => route('crm.pipeline'), 'active' => request()->routeIs('crm.pipeline'), 'permission' => 'lead.view'],
                ['label' => 'Danh sách Lead', 'url' => route('crm.customers.index'), 'active' => request()->routeIs('crm.customers.index') || request()->routeIs('crm.customers.show') || request()->routeIs('crm.customers.edit'), 'permission' => 'lead.view'],
                ['label' => 'Lead chưa liên hệ (SLA 24h)', 'url' => route('notifications.index'), 'active' => request()->routeIs('notifications.*'), 'permission' => 'lead.view'],
                ['label' => 'Học viên đã nhập học', 'url' => route('crm.customers.won'), 'active' => request()->routeIs('crm.customers.won'), 'permission' => 'lead.view'],
                ['label' => 'Lead thất bại (Lost)', 'url' => route('crm.lost-deals'), 'active' => request()->routeIs('crm.lost-deals'), 'permission' => 'lead.mark_lost'],
                ['label' => 'Báo cáo CRM & Tuyển sinh', 'url' => route('crm.reports'), 'active' => request()->routeIs('crm.reports'), 'permission' => 'report.view'],
            ]
        ],
        [
            'id' => 'hr',
            'label' => 'Nhân sự & Vận hành',
            'icon' => 'badge',
            'route_check' => 'users.*',
            'items' => [
                ['label' => 'Danh sách Nhân sự & Tài khoản', 'url' => route('users.index'), 'active' => request()->routeIs('users.*'), 'permission' => 'user.view'],
                ['label' => 'Lịch làm việc & TKB', 'url' => route('tasks.schedule-config'), 'active' => request()->routeIs('tasks.schedule-config'), 'permission' => 'attendance_student.record'],
                ['label' => 'Phân công Trợ giảng', 'url' => route('tasks.ta-assign'), 'active' => request()->routeIs('tasks.ta-assign'), 'permission' => 'work_task.assign'],
                ['label' => 'Báo cáo trực lớp TA', 'url' => route('tasks.class-reports.create'), 'active' => request()->routeIs('tasks.class-reports.create'), 'permission' => 'work_task.view'],
                ['label' => 'Xác nhận hoàn thành việc', 'url' => route('tasks.manual-approvals'), 'active' => request()->routeIs('tasks.manual-approvals'), 'permission' => 'work_task.approve'],
                ['label' => 'Nhật ký sự vụ (Học vụ)', 'url' => route('reports.journal'), 'active' => request()->routeIs('reports.journal'), 'permission' => null],
                ['label' => 'Báo cáo định kỳ của tôi', 'url' => route('reports.my'), 'active' => request()->routeIs('reports.my'), 'permission' => null],
                ['label' => 'Cấu hình KPI Học vụ', 'url' => route('kpi.criteria'), 'active' => request()->routeIs('kpi.criteria'), 'permission' => null],
                ['label' => 'Tổng hợp KPI tháng', 'url' => route('kpi.monthly'), 'active' => request()->routeIs('kpi.monthly') || request()->routeIs('kpi.evaluate'), 'permission' => null],
                ['label' => 'Rà soát điểm danh (Học vụ)', 'url' => route('kpi.attendance-review'), 'active' => request()->routeIs('kpi.attendance-review'), 'permission' => null],
            ]
        ],
        [
            'id' => 'students',
            'label' => 'Hồ sơ Học sinh',
            'icon' => 'school',
            'route_check' => 'students.*',
            'items' => [
                ['label' => 'Danh sách Học sinh & Liên kết lớp', 'url' => route('students.index'), 'active' => request()->routeIs('students.index'), 'permission' => 'student.view'],
                ['label' => 'Xác nhận nhập học', 'url' => route('students.enrollments'), 'active' => request()->routeIs('students.enrollments'), 'permission' => 'student.assign_class'],
            ]
        ],
        [
            'id' => 'student_portal',
            'label' => 'Cổng Phụ huynh & Học sinh',
            'icon' => 'family_restroom',
            'route_check' => 'portal.*',
            'items' => [
                ['label' => 'Trang chủ Phụ huynh/HS (#2)', 'url' => route('portal.student.home'), 'active' => request()->routeIs('portal.student.home'), 'permission' => 'attendance_student.view'],
                ['label' => 'Học tập & Nộp bài tập (#3)', 'url' => route('portal.student.homework'), 'active' => request()->routeIs('portal.student.homework*'), 'permission' => 'attendance_student.view'],
                ['label' => 'Luyện phát âm AI (#4)', 'url' => route('portal.student.pronunciation'), 'active' => request()->routeIs('portal.student.pronunciation*'), 'permission' => 'attendance_student.view'],
                ['label' => 'Hộp thư Thông báo (#5)', 'url' => route('portal.student.notifications'), 'active' => request()->routeIs('portal.student.notifications*'), 'permission' => 'notification.view'],
                ['label' => 'Khảo sát 5 Sao (#6)', 'url' => route('portal.student.survey'), 'active' => request()->routeIs('portal.student.survey*'), 'permission' => 'attendance_student.view'],
                ['label' => 'Gửi Feedback Góp ý (#7)', 'url' => route('portal.student.feedback'), 'active' => request()->routeIs('portal.student.feedback*'), 'permission' => 'support_ticket.create'],
                ['label' => 'App Mobile Shell (#1)', 'url' => route('portal.app-shell'), 'active' => request()->routeIs('portal.app-shell'), 'permission' => 'attendance_student.view'],

            ]
        ],
        [
            'id' => 'classes',
            'label' => 'Lớp học & Lịch dạy',
            'icon' => 'meeting_room',
            'route_check' => 'classes.*',
            'items' => [
                ['label' => 'Đặt lịch khách học thử (#1)', 'url' => route('classes.trial-booking'), 'active' => request()->routeIs('classes.trial-booking*'), 'permission' => ['lead.view', 'user.view']],
                ['label' => 'Tạo lớp mới (#2)', 'url' => route('classes.create'), 'active' => request()->routeIs('classes.create'), 'permission' => 'class.create'],
                ['label' => 'Hồ sơ lớp học (#3)', 'url' => route('classes.profile'), 'active' => request()->routeIs('classes.profile*'), 'permission' => ['lead.view', 'user.view']],
                ['label' => 'Sơ đồ khối lớp học thuật (#4)', 'url' => route('classes.academic-overview'), 'active' => request()->routeIs('classes.academic-overview*'), 'permission' => ['syllabus.manage', 'user.view', 'lead.view']],
                ['label' => 'Danh sách lớp chi tiết (#5)', 'url' => route('classes.academic-list'), 'active' => request()->routeIs('classes.academic-list*'), 'permission' => ['lead.view', 'user.view']],
                ['label' => 'Dashboard Báo cáo Đào tạo', 'url' => route('academic.dashboards.reports'), 'active' => request()->routeIs('academic.dashboards.reports*'), 'permission' => ['lead.view', 'user.view']],
                ['label' => 'Dashboard Nhật ký sự vụ cơ sở', 'url' => route('academic.dashboards.incidents'), 'active' => request()->routeIs('academic.dashboards.incidents*'), 'permission' => ['lead.view', 'user.view']],
                ['label' => 'Dashboard Lớp học theo ngày', 'url' => route('tasks.classes-dashboard'), 'active' => request()->routeIs('tasks.classes-dashboard'), 'permission' => ['lead.view', 'user.view']],
                ['label' => 'Cấu hình Lịch & TKB Lớp', 'url' => route('tasks.schedule-config'), 'active' => request()->routeIs('tasks.schedule-config'), 'permission' => 'class.update'],
                ['label' => 'Chốt học phí & Xếp lớp', 'url' => route('crm.closing-wizard'), 'active' => request()->routeIs('crm.closing-wizard'), 'permission' => 'lead.convert'],
            ]
        ],
        [
            'id' => 'syllabus',
            'label' => 'Syllabus & Giáo trình',
            'icon' => 'menu_book',
            'route_check' => 'syllabus.*',
            'items' => [
                ['label' => 'Tài liệu & Giáo trình (#1)', 'url' => route('syllabus.documents'), 'active' => request()->routeIs('syllabus.documents'), 'permission' => 'syllabus.manage'],
                ['label' => 'Soạn Syllabus chặng (#2)', 'url' => route('syllabus.builder'), 'active' => request()->routeIs('syllabus.builder'), 'permission' => 'syllabus.manage'],
                ['label' => 'Giao chặng cho GV (#3)', 'url' => route('syllabus.assignments'), 'active' => request()->routeIs('syllabus.assignments'), 'permission' => 'syllabus.manage'],
                ['label' => 'Xem bài giảng GV (Cổng GV #4)', 'url' => route('syllabus.teacher-view'), 'active' => request()->routeIs('syllabus.teacher-view'), 'permission' => 'syllabus.manage'],
                ['label' => 'Đề xuất sửa giáo trình (#5)', 'url' => route('syllabus.teacher-propose'), 'active' => request()->routeIs('syllabus.teacher-propose'), 'permission' => 'syllabus.manage'],
                ['label' => 'Duyệt đề xuất sửa GT (#6)', 'url' => route('syllabus.versions'), 'active' => request()->routeIs('syllabus.versions'), 'permission' => 'syllabus.manage'],
                ['label' => 'Xin điều chỉnh tiến độ GV (#7)', 'url' => route('syllabus.teacher-adjust'), 'active' => request()->routeIs('syllabus.teacher-adjust'), 'permission' => 'syllabus.manage'],
                ['label' => 'Duyệt điều chỉnh tiến độ (#8)', 'url' => route('syllabus.adjustment-requests'), 'active' => request()->routeIs('syllabus.adjustment-requests'), 'permission' => 'syllabus.manage'],
                ['label' => 'Duyệt phân phối Big Test', 'url' => route('syllabus.big-tests.distribution'), 'active' => request()->routeIs('syllabus.big-tests.distribution'), 'permission' => 'syllabus.manage'],
                ['label' => 'Nhắc lịch Big Test', 'url' => route('syllabus.big-tests.schedules'), 'active' => request()->routeIs('syllabus.big-tests.schedules'), 'permission' => 'syllabus.manage'],
                ['label' => 'Bảng điểm & Kết quả Big Test', 'url' => route('syllabus.big-tests.results'), 'active' => request()->routeIs('syllabus.big-tests.results'), 'permission' => 'syllabus.manage'],
            ]
        ],
        [
            'id' => 'teacher_schedule',
            'label' => 'Cổng Giáo viên & Giảng dạy',
            'icon' => 'calendar_month',
            'route_check' => 'payroll.timesheets.teachers',
            'items' => [
                ['label' => 'Check-in ca dạy hôm nay', 'url' => route('teacher.home'), 'active' => request()->routeIs('teacher.home') || request()->routeIs('teacher.checkin'), 'permission' => 'attendance_student.record'],
                ['label' => 'Điểm danh lớp học hôm nay', 'url' => route('teacher.home'), 'active' => request()->routeIs('teacher.attendance*'), 'permission' => 'attendance_student.record'],
                ['label' => 'Chi tiết Lịch & Giờ dạy GV', 'url' => route('payroll.timesheets.teachers'), 'active' => request()->routeIs('payroll.timesheets.teachers'), 'permission' => 'attendance_student.record'],
                ['label' => 'Chấm bài nộp của lớp (Cổng GV)', 'url' => route('portal.teacher.submissions'), 'active' => request()->routeIs('portal.teacher.submissions*'), 'permission' => 'attendance_student.record'],
                ['label' => 'Nhiệm vụ hôm nay (TA Portal)', 'url' => route('portal.ta-tasks'), 'active' => request()->routeIs('portal.ta-tasks'), 'permission' => 'work_task.view'],
                ['label' => 'Báo cáo tháng của tôi', 'url' => route('reports.my'), 'active' => request()->routeIs('reports.my'), 'permission' => null],
            ]
        ],
        [
            'id' => 'foreign_teachers',
            'label' => 'Xếp lịch GVNN',
            'icon' => 'event_available',
            'route_check' => 'tasks.schedule-config',
            'items' => [
                ['label' => 'Xếp lịch Giáo viên Nước ngoài', 'url' => route('tasks.schedule-config'), 'active' => request()->routeIs('tasks.schedule-config'), 'permission' => 'class.update'],
                ['label' => 'Theo dõi giờ dạy & Lương GVNN', 'url' => route('payroll.timesheets.teachers'), 'active' => request()->routeIs('payroll.timesheets.teachers'), 'permission' => 'attendance_staff.view'],
            ]
        ],
        [
            'id' => 'tuition',
            'label' => 'Học phí & Hoá đơn',
            'icon' => 'monetization_on',
            'route_check' => 'tuition.*',
            'items' => [
                ['label' => 'Danh sách Học viên & Thu phí', 'url' => route('tuition.students'), 'active' => request()->routeIs('tuition.students'), 'permission' => 'tuition.view'],
                ['label' => 'Nhập danh sách hàng loạt (Excel)', 'url' => route('tuition.import'), 'active' => request()->routeIs('tuition.import'), 'permission' => 'tuition.create'],
                ['label' => 'Lập phiếu thu học phí', 'url' => route('tuition.receipts.create'), 'active' => request()->routeIs('tuition.receipts.create') || request()->routeIs('tuition.receipts.store'), 'permission' => 'tuition.create'],
                ['label' => 'Duyệt phiếu thu học phí', 'url' => route('tuition.receipts.approve'), 'active' => request()->routeIs('tuition.receipts.approve*'), 'permission' => 'tuition.approve'],
                ['label' => 'Lịch sử thu học phí', 'url' => route('tuition.history'), 'active' => request()->routeIs('tuition.history'), 'permission' => 'tuition.view'],
                ['label' => 'Duyệt / Hủy hóa đơn', 'url' => route('tuition.invoices.cancellations'), 'active' => request()->routeIs('tuition.invoices.cancellations*'), 'permission' => 'invoice.approve_cancel'],
                ['label' => 'Hoàn tiền & Khất nợ', 'url' => route('tuition.refunds'), 'active' => request()->routeIs('tuition.refunds*'), 'permission' => 'refund_transfer.request'],
                ['label' => 'Thu phí quá hạn & Nhắc phí', 'url' => route('tuition.overdue'), 'active' => request()->routeIs('tuition.overdue*'), 'permission' => 'tuition.report_overdue'],
                ['label' => 'Cấu hình Nhắc nợ', 'url' => route('system-config.debt-reminders'), 'active' => request()->routeIs('system-config.debt-reminders'), 'permission' => 'fee_reminder_config.manage'],
                ['label' => 'Cấu hình dải số hóa đơn & Ngân hàng', 'url' => route('tuition.config'), 'active' => request()->routeIs('tuition.config*'), 'permission' => 'bank_account.manage'],
            ]
        ],
        [
            'id' => 'finance',
            'label' => 'Báo cáo Thu - Chi',
            'icon' => 'query_stats',
            'route_check' => 'finance.*',
            'items' => [
                ['label' => 'Báo cáo Doanh thu tạm tính', 'url' => route('finance.reports.revenue'), 'active' => request()->routeIs('finance.reports.*'), 'permission' => 'report.view'],
                ['label' => 'Sổ khoản chi vận hành', 'url' => route('finance.expenses.index'), 'active' => request()->routeIs('finance.expenses.*'), 'permission' => 'tuition.view'],
            ]
        ],
        [
            'id' => 'inventory',
            'label' => 'Hàng hoá & Danh mục',
            'icon' => 'inventory_2',
            'route_check' => 'merchandise.*',
            'items' => [
                ['label' => 'Quản lý Giáo trình & Tài liệu', 'url' => route('syllabus.documents'), 'active' => request()->routeIs('syllabus.documents'), 'permission' => 'syllabus.manage'],
                ['label' => 'Danh mục Hàng hóa & Vật phẩm', 'url' => route('merchandise.index'), 'active' => request()->routeIs('merchandise.*'), 'permission' => 'system_category.manage'],
            ]
        ],
        [
            'id' => 'tasks',
            'label' => 'Phân công & Trợ giảng',
            'icon' => 'task_alt',
            'route_check' => 'tasks.*',
            'items' => [
                ['label' => 'Danh sách công việc & Giao việc', 'url' => route('tasks.index'), 'active' => request()->routeIs('tasks.index'), 'permission' => 'work_task.view'],
                ['label' => 'Giao việc cho Trợ giảng (TA)', 'url' => route('tasks.ta-assign'), 'active' => request()->routeIs('tasks.ta-assign'), 'permission' => 'work_task.assign'],
                ['label' => 'Nhiệm vụ hôm nay (TA Portal)', 'url' => route('portal.ta-tasks'), 'active' => request()->routeIs('portal.ta-tasks'), 'permission' => 'work_task.view'],
                ['label' => 'Nộp báo cáo trực lớp TA', 'url' => route('tasks.class-reports.create'), 'active' => request()->routeIs('tasks.class-reports.create'), 'permission' => 'work_task.view'],
                ['label' => 'Xác nhận hoàn thành thủ công', 'url' => route('tasks.manual-approvals'), 'active' => request()->routeIs('tasks.manual-approvals'), 'permission' => 'work_task.approve'],
                ['label' => 'Bảng KPI tự động', 'url' => route('tasks.kpi-dashboard'), 'active' => request()->routeIs('tasks.kpi-dashboard'), 'permission' => 'kpi.confirm'],
            ]
        ],
        [
            'id' => 'tickets',
            'label' => 'Hỗ trợ & Ticket',
            'icon' => 'confirmation_number',
            'route_check' => 'tickets.*',
            'items' => [
                ['label' => 'Danh sách Ticket hỗ trợ', 'url' => route('tickets.index'), 'active' => request()->routeIs('tickets.index') || request()->routeIs('tickets.show'), 'permission' => 'support_ticket.view'],
                ['label' => 'Tạo Ticket mới', 'url' => route('tickets.create'), 'active' => request()->routeIs('tickets.create'), 'permission' => 'support_ticket.create'],
                ['label' => 'Cấu hình Email nhận', 'url' => route('system-config.ticket-emails'), 'active' => request()->routeIs('system-config.ticket-emails*'), 'permission' => 'support_ticket.update'],
            ]
        ],
        [
            'id' => 'timesheets',
            'label' => 'Chấm công',
            'icon' => 'schedule',
            'route_check' => 'payroll.timesheets.*',
            'items' => (auth()->user()?->hasRole('teacher') || auth()->user()?->hasRole('teacher_fulltime') || auth()->user()?->hasRole('teacher_parttime')) ? [
                ['label' => 'Check-in ca dạy của tôi', 'url' => route('portal.teacher.submissions'), 'active' => request()->routeIs('portal.teacher.submissions*'), 'permission' => null],
                ['label' => 'Cổng Giáo viên & Điểm danh', 'url' => route('payroll.timesheets.teachers'), 'active' => request()->routeIs('payroll.timesheets.teachers'), 'permission' => null],
                ['label' => 'Chi tiết Giờ dạy của tôi', 'url' => route('payroll.timesheets.teachers'), 'active' => request()->routeIs('payroll.timesheets.teachers'), 'permission' => null],
            ] : [
                ['label' => 'Chấm công đơn lẻ (GV & TA)', 'url' => route('payroll.timesheets.manual'), 'active' => request()->routeIs('payroll.timesheets.manual'), 'permission' => 'attendance_staff.manual_record'],
                ['label' => 'Chi tiết Chấm công Giáo viên', 'url' => route('payroll.timesheets.teachers'), 'active' => request()->routeIs('payroll.timesheets.teachers'), 'permission' => 'attendance_staff.view'],
                ['label' => 'Chấm công AppSheet', 'url' => route('payroll.timesheets.appsheet'), 'active' => request()->routeIs('payroll.timesheets.appsheet'), 'permission' => 'attendance_staff.view'],
                ['label' => 'Lịch sử Đồng bộ Chấm công', 'url' => route('payroll.timesheets.sync-history'), 'active' => request()->routeIs('payroll.timesheets.sync-history'), 'permission' => 'attendance_staff.sync'],
            ]
        ],
        [
            'id' => 'payroll',
            'label' => 'Lương & Thưởng',
            'icon' => 'payments',
            'route_check' => 'payroll.*',
            'items' => [
                ['label' => 'Danh sách Bảng lương theo kỳ', 'url' => route('payroll.periods.index'), 'active' => request()->routeIs('payroll.periods.*'), 'permission' => 'payroll.view'],
                ['label' => 'Lương của tôi (Teacher Portal)', 'url' => route('portal.my-salary'), 'active' => request()->routeIs('portal.my-salary'), 'permission' => 'payroll.view_own'],
                ['label' => 'Cấu hình Tham số Lương', 'url' => route('payroll.config.settings'), 'active' => request()->routeIs('payroll.config.settings'), 'permission' => 'teacher_rate.manage'],
                ['label' => 'Cấu hình Đơn giá Giáo viên', 'url' => route('payroll.config.teacher-rates'), 'active' => request()->routeIs('payroll.config.teacher-rates'), 'permission' => 'teacher_rate.manage'],
                ['label' => 'Cấu hình Mốc Hoa hồng / Thưởng', 'url' => route('payroll.config.commission-tiers'), 'active' => request()->routeIs('payroll.config.commission-tiers'), 'permission' => 'commission_config.manage'],
                ['label' => 'Bảng xếp hạng KPI & Thưởng', 'url' => route('payroll.kpi-leaderboard'), 'active' => request()->routeIs('payroll.kpi-leaderboard'), 'permission' => 'report.view'],
                ['label' => 'Danh sách Vi phạm & Phạt', 'url' => route('penalties.index'), 'active' => request()->routeIs('penalties.*'), 'permission' => 'violation.view'],
            ]
        ],
        [
            'id' => 'survey',
            'label' => 'Khảo sát / Test',
            'icon' => 'ballot',
            'route_check' => 'placement-tests.*',
            'items' => [
                ['label' => 'Quản lý Đề Test đầu vào (AI)', 'url' => route('placement-tests.index'), 'active' => request()->routeIs('placement-tests.*'), 'permission' => 'entrance_test.view'],
                ['label' => 'Thang điểm & Hướng dẫn chấm (Rubric)', 'url' => route('placement-tests.rubric-guide'), 'active' => request()->routeIs('placement-tests.rubric-guide'), 'permission' => 'entrance_test.view'],
                ['label' => 'Quản lý Đợt Khảo sát', 'url' => route('surveys.index'), 'active' => request()->routeIs('surveys.*'), 'permission' => 'survey.manage'],
                ['label' => 'Bảng giá & Khóa học', 'url' => route('courses.index'), 'active' => request()->routeIs('courses.*'), 'permission' => 'level.view'],
                ['label' => 'Cấu hình Khung trình độ (CEFR)', 'url' => route('course-levels.index'), 'active' => request()->routeIs('course-levels.*'), 'permission' => 'level.view'],
            ]
        ],
        [
            'id' => 'media',
            'label' => 'Media & Tệp tin',
            'icon' => 'perm_media',
            'route_check' => 'media.*',
            'items' => [
                ['label' => 'Quản lý Media & File lưu trữ', 'url' => route('media.index'), 'active' => request()->routeIs('media.*'), 'permission' => 'media.view'],
            ]
        ],

        [
            'id' => 'permissions',
            'label' => 'Phân quyền & Hệ thống',
            'icon' => 'verified_user',
            'route_check' => 'roles.*',
            'items' => [
                ['label' => 'Quản lý Tài khoản & Phân vai trò', 'url' => route('users.index'), 'active' => request()->routeIs('users.*'), 'permission' => 'user.view'],
                ['label' => 'Quản lý Cơ sở & Chi nhánh', 'url' => route('branches.index'), 'active' => request()->routeIs('branches.*'), 'permission' => 'branch.view'],
                ['label' => 'Danh sách Vai trò (Roles)', 'url' => route('roles.index'), 'active' => request()->routeIs('roles.*'), 'permission' => 'role.view'],
                ['label' => 'Danh sách Quyền (Permissions)', 'url' => route('permissions.index'), 'active' => request()->routeIs('permissions.*'), 'permission' => 'permission.view'],
                ['label' => 'Danh mục hệ thống', 'url' => route('system-categories.index'), 'active' => request()->routeIs('system-categories.*'), 'permission' => 'system_category.manage'],
                ['label' => 'Quản lý Ngày nghỉ lễ', 'url' => route('holidays.index'), 'active' => request()->routeIs('holidays.*'), 'permission' => 'holiday.manage'],
                ['label' => 'Cấu hình Tài khoản Ngân hàng', 'url' => route('system-config.bank-accounts'), 'active' => request()->routeIs('system-config.bank-accounts'), 'permission' => 'bank_account.manage'],
                ['label' => 'Cấu hình Email nhận Ticket', 'url' => route('system-config.ticket-emails'), 'active' => request()->routeIs('system-config.ticket-emails*'), 'permission' => 'support_ticket.update'],
                ['label' => 'Thông số Hosting & Máy chủ', 'url' => route('system-config.hosting'), 'active' => request()->routeIs('system-config.hosting'), 'permission' => 'bank_account.manage'],
                ['label' => 'Nhật ký vận hành (Activity Logs)', 'url' => route('activity-logs.index'), 'active' => request()->routeIs('activity-logs.*'), 'permission' => 'activity_log.view'],
                ['label' => 'Tổng hợp Báo cáo & Nhật ký', 'url' => route('reports.all'), 'active' => request()->routeIs('reports.all'), 'permission' => 'activity_log.view'],
            ]
        ],
    ];

    // ──────────────────────────────────────────────
    // Phân quyền HIỂN THỊ MENU theo ROLE: mỗi nhóm màn hình chỉ hiện cho
    // đúng các role liên quan. Cổng Học viên/Phụ huynh và Cổng Giáo viên là
    // giao diện dành riêng cho end-user nên KHÔNG hiện cho admin/nhân sự.
    // ──────────────────────────────────────────────
    $groupRoles = [
        'crm'              => ['admin', 'manager', 'sales_consultant'],
        'hr'               => ['admin', 'manager', 'academic_staff', 'academic_lead'],
        'students'         => ['admin', 'manager', 'academic_staff', 'academic_lead'],
        'student_portal'   => ['student'],
        'classes'          => ['admin', 'manager', 'academic_staff', 'academic_lead'],
        'syllabus'         => ['admin', 'manager', 'academic_staff', 'academic_lead'],
        'teacher_schedule' => ['teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'],
        'foreign_teachers' => ['admin', 'manager', 'academic_staff', 'academic_lead'],
        'tuition'          => ['admin', 'manager', 'accountant'],
        'finance'          => ['admin', 'manager', 'accountant'],
        'inventory'        => ['admin', 'manager', 'academic_staff'],
        'tasks'            => ['admin', 'manager', 'academic_staff', 'academic_lead'],
        'tickets'          => ['admin', 'manager', 'academic_staff', 'academic_lead', 'accountant', 'sales_consultant', 'teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'],
        'timesheets'       => ['admin', 'manager', 'academic_staff', 'teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'],
        'payroll'          => ['admin', 'manager', 'accountant', 'teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'],
        'survey'           => ['admin', 'manager', 'academic_staff', 'academic_lead'],
        'media'            => ['admin', 'manager'],
        'permissions'      => ['admin', 'manager'],
    ];

    $menuGroups = [];
    $initialOpenGroups = [];
    foreach ($rawMenuGroups as $group) {
        // Chặn theo role: nếu role hiện tại không nằm trong danh sách của nhóm thì bỏ qua
        $allowedRoles = $groupRoles[$group['id']] ?? [];
        if (!empty($allowedRoles) && !($user && $user->hasAnyRole($allowedRoles))) {
            continue;
        }

        $filteredItems = [];
        $hasActiveItem = false;
        foreach ($group['items'] as $subItem) {
            if ($canAccess($subItem['permission'] ?? null)) {
                $isItemActive = $subItem['active'] || request()->fullUrlIs($subItem['url']) || request()->url() === $subItem['url'];
                if ($isItemActive) {
                    $hasActiveItem = true;
                }
                $subItem['active'] = $isItemActive;
                $filteredItems[] = $subItem;
            }
        }

        if (!empty($filteredItems)) {
            $group['items'] = $filteredItems;
            $group['default_url'] = $filteredItems[0]['url'];
            $group['has_active_child'] = $hasActiveItem;
            $isGroupActive = request()->routeIs($group['route_check']) || $hasActiveItem;
            $group['is_active'] = $isGroupActive;
            $initialOpenGroups[$group['id']] = $isGroupActive;
            $menuGroups[] = $group;
        }
    }
@endphp

<script>
    function sidebarNavigation(initialGroups) {
        return {
            openGroups: Object.assign({}, initialGroups || {}),
            init() {
                // 1. Khôi phục các accordion đã mở từ localStorage (bảo toàn trạng thái qua các lần click & chuyển trang)
                try {
                    const saved = localStorage.getItem('menglish_sidebar_open_groups');
                    if (saved) {
                        const parsed = JSON.parse(saved);
                        if (parsed && typeof parsed === 'object') {
                            Object.keys(parsed).forEach((k) => {
                                if (parsed[k] === true) {
                                    this.openGroups[k] = true;
                                }
                            });
                        }
                    }
                } catch (e) {}

                // 2. Luôn đảm bảo accordion chứa trang hiện tại được giữ mở
                if (initialGroups && typeof initialGroups === 'object') {
                    Object.keys(initialGroups).forEach((k) => {
                        if (initialGroups[k]) {
                            this.openGroups[k] = true;
                        }
                    });
                }

                this.persistOpenGroups();

                // 3. Khôi phục vị trí cuộn thanh menu
                this.$nextTick(() => {
                    const nav = this.$refs.navContainer;
                    if (nav) {
                        const savedScroll = sessionStorage.getItem('sidebar_scroll_top');
                        if (savedScroll !== null) {
                            nav.scrollTop = parseInt(savedScroll, 10);
                        } else {
                            const activeItem = nav.querySelector('.bg-primary');
                            if (activeItem) {
                                activeItem.scrollIntoView({ block: 'nearest', behavior: 'instant' });
                            }
                        }
                    }
                });
            },
            toggle(id) {
                this.openGroups[id] = !this.openGroups[id];
                this.persistOpenGroups();
                this.$nextTick(() => this.saveScroll());
            },
            keepOpen(id) {
                this.openGroups[id] = true;
                this.persistOpenGroups();
                this.saveScroll();
            },
            persistOpenGroups() {
                try {
                    localStorage.setItem('menglish_sidebar_open_groups', JSON.stringify(this.openGroups));
                } catch (e) {}
            },
            saveScroll() {
                const nav = this.$refs.navContainer;
                if (nav) {
                    sessionStorage.setItem('sidebar_scroll_top', nav.scrollTop);
                }
            }
        };
    }
</script>

<aside
    class="fixed left-0 top-0 h-full w-[280px] bg-navy text-white flex flex-col z-40 transition-transform duration-200 lg:translate-x-0 border-r border-white/10 shadow-2xl"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    x-data="sidebarNavigation(@js($initialOpenGroups))"
>
    <!-- Brand Header / Logo -->
    <div class="h-16 flex items-center gap-3 px-5 border-b border-white/10 shrink-0 bg-navy-dark">
        <!-- Orange ME EDUCATION Logo & Brand Header linking to Dashboard -->
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 group focus:outline-none transition cursor-pointer" title="Về Bảng Điều Khiển Trung Tâm (Dashboard)">
            <div class="w-10 h-10 rounded-xl bg-primary flex flex-col items-center justify-center shadow-md shadow-primary/20 shrink-0 select-none text-white leading-tight group-hover:scale-105 transition-transform">
                <span class="font-extrabold text-[15px] tracking-tight leading-none">ME</span>
                <span class="text-[7px] font-bold tracking-tighter uppercase scale-90 leading-none pt-0.5 opacity-95">EDUCATION</span>
            </div>
            <div>
                <div class="font-extrabold text-base tracking-wider text-white flex items-center gap-1.5 group-hover:text-primary transition-colors">
                    MENGLISH
                    <span class="text-[10px] font-bold uppercase tracking-widest px-1.5 py-0.5 rounded bg-primary/20 text-orange-400 border border-primary/30">Admin</span>
                </div>
            </div>
        </a>
        <button class="ml-auto lg:hidden text-white/60 hover:text-white p-1" @click="sidebarOpen = false">
            <span class="material-symbols-outlined text-xl">close</span>
        </button>
    </div>

    <!-- Navigation links -->
    <nav 
        x-ref="navContainer"
        @scroll.passive="saveScroll()"
        class="flex-1 overflow-y-auto py-3 px-3 space-y-1 text-sm select-none scrollbar-thin"
    >
        @foreach ($menuGroups as $group)
            @php
                $isGroupActive = $group['is_active'] ?? false;
            @endphp
            <div 
                x-data="{ 
                    show: false, 
                    timeout: null,
                    top: 0,
                    left: 0,
                    calcPos() {
                        const rect = this.$el.getBoundingClientRect();
                        this.left = rect.right;
                        this.top = rect.top;
                        // Clamp top để flyout không tràn đáy viewport khi nhóm menu nằm ở cuối sidebar
                        this.$nextTick(() => {
                            const fly = document.getElementById('sidebar-flyout-{{ $group['id'] }}');
                            if (!fly) return;
                            const margin = 8;
                            const h = fly.offsetHeight;
                            let t = rect.top;
                            if (t + h > window.innerHeight - margin) {
                                t = window.innerHeight - h - margin;
                            }
                            this.top = Math.max(margin, t);
                        });
                    }
                }"
                @mouseenter="clearTimeout(timeout); calcPos(); show = true"
                @mouseleave="timeout = setTimeout(() => show = false, 150)"
                class="relative space-y-1"
            >
                <button 
                    type="button"
                    class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-[13px] font-medium transition-all duration-150 {{ $isGroupActive ? 'bg-primary text-white font-bold shadow-sm shadow-primary/30' : 'text-gray-300 hover:bg-white/5 hover:text-white' }}"
                >
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-[20px] {{ $isGroupActive ? 'text-white' : 'text-gray-400' }}">{{ $group['icon'] }}</span>
                        <span>{{ $group['label'] }}</span>
                    </div>

                    <span class="material-symbols-outlined text-[16px] text-gray-400">
                        chevron_right
                    </span>
                </button>

                <!-- Flyout Submenu using x-teleport to escape sidebar overflow -->
                <template x-teleport="body">
                    <div 
                        id="sidebar-flyout-{{ $group['id'] }}"
                        x-show="show"
                        x-cloak
                        @mouseenter="clearTimeout(timeout); show = true"
                        @mouseleave="timeout = setTimeout(() => show = false, 150)"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-x-2"
                        x-transition:enter-end="opacity-100 translate-x-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-x-0"
                        x-transition:leave-end="opacity-0 -translate-x-2"
                        class="fixed z-[100] w-64 bg-[#1e293b] shadow-2xl shadow-black/60 border border-white/10 rounded-xl py-2 max-h-[calc(100vh-16px)] overflow-y-auto scrollbar-thin before:absolute before:top-0 before:bottom-0 before:-left-3 before:w-3 before:bg-transparent"
                        :style="`top: ${top}px; left: ${left + 5}px;`"
                    >
                        <div class="px-4 py-2 border-b border-white/10 mb-2">
                            <span class="text-sm font-bold text-white uppercase tracking-wider">{{ $group['label'] }}</span>
                        </div>
                        <div class="flex flex-col gap-0.5 px-2">
                            @foreach ($group['items'] as $subItem)
                                <a 
                                    href="{{ $subItem['url'] }}" 
                                    class="flex items-center gap-2 px-3 py-2 rounded-lg text-[13px] font-medium transition duration-150 {{ $subItem['active'] ? 'bg-primary text-white font-bold' : 'text-gray-300 hover:bg-white/10 hover:text-white' }}"
                                >
                                    {{ $subItem['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </template>
            </div>
        @endforeach
    </nav>

    <!-- Bottom of Sidebar: "Vai trò hiện tại" -->
    <div class="p-3.5 border-t border-white/10 shrink-0 bg-navy-dark">
        <div class="text-[11px] text-gray-400 font-medium px-1 mb-1.5">
            Vai trò hiện tại
        </div>
        <div class="flex items-center justify-between px-3 py-2.5 rounded-xl bg-navy-light border border-white/10 hover:border-white/20 transition cursor-pointer group">
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                <span class="text-xs font-bold text-white">
                    @php
                        $userRoles = Auth::user()?->getRoleNames();
                        $roleName = $userRoles && $userRoles->isNotEmpty() ? ucfirst($userRoles->first()) : 'Admin';
                    @endphp
                    {{ $roleName }}
                </span>
            </div>
            <span class="material-symbols-outlined text-xs text-gray-400 group-hover:text-white transition">chevron_right</span>
        </div>
    </div>
</aside>

<div
    class="fixed inset-0 bg-black/60 backdrop-blur-xs z-30 lg:hidden"
    x-show="sidebarOpen"
    x-cloak
    @click="sidebarOpen = false"
></div>
