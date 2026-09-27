<x-app-layout>
    {{-- Tiêu đề "…Admin" chỉ cho bảng điều hành; nhân sự khác (GV, TA…) thấy "Tổng quan" như tên menu. --}}
    <x-ui.page-header :title="auth()->user()?->can('dashboard.operations') ? 'Bảng Điều Khiển Trung Tâm — MEnglish Admin' : 'Tổng quan'" icon="dashboard" />

    @php
        $user = Auth::user();
        $isAdminOrManager = $user && $user->can('dashboard.operations');
        $canLead = $user && $user->can('lead.view');
        $canTuition = $user && $user->can('tuition.view');
        $canStudent = $user && $user->can('student.view');
        $canClass = $user && $user->can('class.view');
        $canPayroll = $user && ($user->can('payroll.view') || $user->can('payroll.view_own'));
        $canSyllabus = $user && $user->can('syllabus.manage');
        $canTest = $user && $user->can('entrance_test.view');
        $canSystem = $user && $user->can('user.view');
        $canTask = $user && $user->can('work_task.view');
    @endphp

    <div class="space-y-6">
        @unless($isAdminOrManager)
            {{-- Welcome Banner for Staff / Teachers --}}
            <div class="bg-surface-container-lowest rounded-2xl p-6 border border-surface-container-highest shadow-sm flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-on-surface">Xin chào, {{ $user->name }}!</h2>
                    <p class="text-xs text-on-surface-variant mt-1">Vai trò: <span class="font-semibold text-primary">{{ $user->getRoleNames()->map(fn ($r) => \App\Helpers\AclHelper::shortRoleLabel($r))->implode(', ') ?: 'Nhân viên' }}</span> · Chi nhánh: {{ $user->branch?->name ?? 'Trung tâm' }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($canPayroll)
                        <x-ui.button variant="secondary" size="sm" icon="payments" :href="route('portal.my-salary')">Lương của tôi</x-ui.button>
                    @endif
                    @if($canTask)
                        <x-ui.button variant="secondary" size="sm" icon="checklist" :href="route('portal.ta-tasks')">Nhiệm vụ hôm nay</x-ui.button>
                    @endif
                </div>
            </div>
        @endunless

        @if (! empty($roleDashboard))
            @include('dashboard.partials.role-widgets', ['roleDashboard' => $roleDashboard])
        @endif

        @php
            // Thẻ số liệu & ô phân hệ chỉ hiện link user mở được: quyền đọc từ middleware `can:` của route như menu trái
            // (SidebarMenu::canSee). `can` bổ sung cho route tự kiểm tra quyền trong controller.
            $navMenu = app(\App\Support\Navigation\SidebarMenu::class);
            $canOpen = fn (string $route, array $can = []) => $user && $navMenu->canSee($user, ['route' => $route, 'can' => $can], request());
            $linkTo = fn (string $label, string $route, array $params = [], array $can = []) => $canOpen($route, $can)
                ? ['label' => $label, 'url' => route($route, $params)]
                : null;
        @endphp

        @if (empty($roleDashboard))
        {{-- Key Operating KPI Cards (Gated by Permissions) — vai trò không có dashboard riêng --}}
        @php
            // Số liệu theo phạm vi dữ liệu của user (chi nhánh / lớp mình), không phải toàn trung tâm.
            $showLeadCard = $canLead && $canOpen('crm.pipeline');
            $showTuitionCard = $canTuition && $canOpen('tuition.students');
            $showStudentCard = $canStudent && $canOpen('students.index');
            $payrollRoute = $user->can('payroll.view') ? 'payroll.periods.index' : 'portal.my-salary';
            $showPayrollCard = $canPayroll && $canOpen($payrollRoute);

            if ($showLeadCard) {
                $dbLeadCount = \App\Models\CrmCustomer::query()->visibleTo($user)->count();
                $dbWonCount = \App\Models\CrmCustomer::query()->visibleTo($user)->where('stage', 'won')->count();
            }
            if ($showTuitionCard) {
                $tuitionScope = fn () => \App\Models\StudentTuition::query()->whereHas('student', fn ($q) => $q->visibleTo($user));
                $dbTuitionPaid = $tuitionScope()->sum('paid_amount');
                $dbOverdueCount = $tuitionScope()->where('status', 'overdue')->count();
            }
            if ($showStudentCard) {
                $dbStudentCount = \App\Models\Student::query()->visibleTo($user)->count();
                $dbClassCount = \App\Models\ClassModel::query()->visibleTo($user)->where('status', 'active')->count();
            }
            $latestPayroll = $showPayrollCard ? \App\Models\PayrollPeriod::latest()->first() : null;
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @if($showLeadCard)
                {{-- CRM Lead KPI --}}
                <a href="{{ route('crm.pipeline') }}" class="bg-surface-container-lowest rounded-2xl p-5 border border-surface-container-highest shadow-sm hover:border-primary-container hover:shadow-md transition group">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Leads Tuyển Sinh</span>
                        <div class="w-10 h-10 rounded-xl bg-primary-container/10 text-primary flex items-center justify-center group-hover:scale-110 transition">
                            <span class="material-symbols-outlined text-xl">pie_chart</span>
                        </div>
                    </div>
                    <div class="mt-3 text-2xl font-black text-on-surface">{{ $dbLeadCount }} leads</div>
                    <div class="mt-1 flex items-center justify-between text-xs">
                        <span class="text-tertiary font-bold">{{ $dbWonCount }} deals đã chốt</span>
                        <span class="text-primary font-bold group-hover:translate-x-1 transition">→ Pipeline</span>
                    </div>
                </a>
            @endif

            @if($showTuitionCard)
                {{-- Tuition KPI --}}
                <a href="{{ route('tuition.students') }}" class="bg-surface-container-lowest rounded-2xl p-5 border border-surface-container-highest shadow-sm hover:border-warning hover:shadow-md transition group">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Thu Học Phí (Thực thu)</span>
                        <div class="w-10 h-10 rounded-xl bg-warning-container text-warning flex items-center justify-center group-hover:scale-110 transition">
                            <span class="material-symbols-outlined text-xl">payments</span>
                        </div>
                    </div>
                    <div class="mt-3 text-2xl font-black text-on-surface">{{ number_format($dbTuitionPaid / 1000000, 1) }} tr</div>
                    <div class="mt-1 flex items-center justify-between text-xs">
                        <span class="text-error font-bold">{{ $dbOverdueCount }} HV quá hạn</span>
                        <span class="text-warning font-bold group-hover:translate-x-1 transition">→ Thu phí</span>
                    </div>
                </a>
            @endif

            @if($showStudentCard)
                {{-- Active Students KPI (GV / TA không xem được danh sách học viên → không hiện) --}}
                <a href="{{ route('students.index') }}" class="bg-surface-container-lowest rounded-2xl p-5 border border-surface-container-highest shadow-sm hover:border-tertiary hover:shadow-md transition group">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Học Viên Trong Hệ Thống</span>
                        <div class="w-10 h-10 rounded-xl bg-tertiary/10 text-tertiary flex items-center justify-center group-hover:scale-110 transition">
                            <span class="material-symbols-outlined text-xl">school</span>
                        </div>
                    </div>
                    <div class="mt-3 text-2xl font-black text-on-surface">{{ $dbStudentCount }} học viên</div>
                    <div class="mt-1 flex items-center justify-between text-xs">
                        <span class="text-tertiary font-bold">{{ $dbClassCount }} lớp đang chạy</span>
                        <span class="text-tertiary font-bold group-hover:translate-x-1 transition">→ Hồ sơ</span>
                    </div>
                </a>
            @endif

            @if($showPayrollCard)
                {{-- Payroll KPI --}}
                <a href="{{ route($payrollRoute) }}" class="bg-surface-container-lowest rounded-2xl p-5 border border-surface-container-highest shadow-sm hover:border-info hover:shadow-md transition group">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Lương &amp; Thu nhập</span>
                        <div class="w-10 h-10 rounded-xl bg-info-container text-info flex items-center justify-center group-hover:scale-110 transition">
                            <span class="material-symbols-outlined text-xl">account_balance_wallet</span>
                        </div>
                    </div>
                    <div class="mt-3 text-2xl font-black text-on-surface">{{ $user->can('payroll.view') ? number_format(($latestPayroll?->total_amount ?? 0) / 1000000, 1) . ' tr' : 'Xem phiếu' }}</div>
                    <div class="mt-1 flex items-center justify-between text-xs">
                        <span class="text-info font-bold">{{ $latestPayroll?->title ?? 'Chưa có kỳ lương' }}</span>
                        <span class="text-info font-bold group-hover:translate-x-1 transition">→ Chi tiết</span>
                    </div>
                </a>
            @endif
        </div>

        @endif

        @php
            // Phân hệ: [bật?, tiêu đề, mô tả, icon, lớp icon, lớp viền hover, lớp link hover, links]; ô không còn link nào thì ẩn.
            $modules = collect([
                [$canLead, 'CRM &amp; Tuyển sinh', 'Quản lý khách hàng tiềm năng', 'pie_chart', 'bg-primary-container/10 text-primary', 'hover:border-primary-container/50', 'hover:bg-primary-container/10 hover:text-primary', fn () => [
                    $linkTo('Pipeline Kanban', 'crm.pipeline'),
                    $linkTo('DS Khách hàng', 'crm.customers.index'),
                    $linkTo('Chốt &amp; Xếp lớp', 'crm.closing-wizard', [], ['class.update']),
                    $linkTo('Báo cáo Doanh số', 'crm.reports'),
                ]],
                [$canTuition, 'Học phí &amp; Hóa đơn', 'Thu phí và quản lý công nợ', 'receipt_long', 'bg-warning-container text-warning', 'hover:border-warning/50', 'hover:bg-warning-container hover:text-on-warning-container', fn () => [
                    $linkTo('DS Thu phí', 'tuition.students'),
                    $linkTo('Lập Phiếu thu', 'tuition.receipts.create'),
                    $linkTo('Duyệt Phiếu thu', 'tuition.receipts.approve'),
                    $linkTo('Thu quá hạn', 'tuition.overdue'),
                ]],
                [$canStudent, 'Hồ sơ Học sinh', 'Quản lý thông tin học viên', 'school', 'bg-tertiary/10 text-tertiary', 'hover:border-tertiary/50', 'hover:bg-tertiary/10 hover:text-tertiary', fn () => [
                    $linkTo('DS &amp; Liên kết lớp', 'students.index'),
                    $linkTo('Chờ khai giảng', 'students.index', ['status' => 'waiting_start']),
                    $linkTo('Đang học', 'students.index', ['status' => 'studying']),
                    $linkTo('Xác nhận nhập học', 'students.enrollments'),
                ]],
                [$canClass, 'Lớp học &amp; Lịch dạy', 'Lịch học, điểm danh &amp; TKB', 'meeting_room', 'bg-secondary/10 text-secondary', 'hover:border-secondary/50', 'hover:bg-secondary/10 hover:text-secondary', fn () => [
                    $linkTo('Lịch học các lớp', 'tasks.classes-dashboard'),
                    $linkTo('Lịch &amp; TKB lớp', 'tasks.schedule-config'),
                    $linkTo('Lịch dạy GV', 'payroll.timesheets.teachers', [], ['attendance_staff.view', 'payroll.view_own']),
                ]],
                [$canTask, 'Phân công &amp; Trợ giảng', 'Công việc ca trực &amp; báo cáo', 'task_alt', 'bg-primary-container/10 text-primary', 'hover:border-primary-container/50', 'hover:bg-primary-container/10 hover:text-primary', fn () => [
                    $linkTo('Danh sách việc', 'tasks.index'),
                    $linkTo('Nhiệm vụ hôm nay', 'portal.ta-tasks'),
                    $linkTo('Báo cáo trực lớp', 'tasks.class-reports.create'),
                ]],
                [$canSyllabus, 'Syllabus &amp; Giáo trình', 'Soạn giáo trình &amp; Big Test', 'auto_stories', 'bg-purple-50 text-purple-600', 'hover:border-purple-500/50', 'hover:bg-purple-50 hover:text-purple-700', fn () => [
                    $linkTo('Giáo trình tài liệu', 'syllabus.documents'),
                    $linkTo('Soạn Syllabus', 'syllabus.builder'),
                    $linkTo('Phân phối Big Test', 'syllabus.big-tests.distribution'),
                ]],
                [$canSystem, 'Quản trị Hệ thống', 'Tài khoản &amp; Phân quyền', 'settings', 'bg-info-container text-info', 'hover:border-info/50', 'hover:bg-info-container hover:text-info', fn () => [
                    $linkTo('Tài khoản', 'users.index'),
                    $linkTo('Vai trò (Roles)', 'roles.index'),
                    $linkTo('Permissions', 'permissions.index'),
                    $linkTo('Nhật ký vận hành', 'activity-logs.index'),
                ]],
            ])
                ->filter(fn (array $module) => $module[0])
                ->map(fn (array $module) => [...array_slice($module, 1, 6), array_values(array_filter(($module[7])()))])
                ->filter(fn (array $module) => $module[6] !== [])
                ->values();
        @endphp

        @if ($modules->isNotEmpty())
        {{-- Quick Access Module Grid --}}
        <div class="space-y-3">
            <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-base">grid_view</span>
                Các Phân Hệ Chức Năng Của Bạn
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($modules as [$title, $subtitle, $icon, $iconClass, $borderClass, $linkClass, $links])
                    <div class="bg-surface-container-lowest rounded-2xl p-5 border border-surface-container-highest shadow-sm {{ $borderClass }} transition space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl {{ $iconClass }} flex items-center justify-center">
                                    <span class="material-symbols-outlined text-lg">{{ $icon }}</span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-sm text-on-surface">{!! $title !!}</h3>
                                    <span class="text-[11px] text-on-surface-variant/70">{!! $subtitle !!}</span>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            @foreach ($links as $link)
                                <a href="{{ $link['url'] }}" class="p-2 rounded-lg bg-surface-container-low {{ $linkClass }} transition font-medium">{!! $link['label'] !!}</a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
</x-app-layout>
