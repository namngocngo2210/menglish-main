<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-2xl">dashboard</span>
                    Bảng Điều Khiển Trung Tâm — MEnglish Admin
                </h1>
                <p class="text-xs text-gray-500 mt-0.5">Tổng quan không gian làm việc theo vai trò và quyền hạn của bạn</p>
            </div>
        </div>
    </x-slot>

    @php
        $user = Auth::user();
        $isAdminOrManager = $user && ($user->hasRole('admin') || $user->hasRole('manager'));
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
        @if($isAdminOrManager)
            <!-- Workspace Overview Banner -->
            <div class="relative overflow-hidden bg-gradient-to-r from-navy via-navy-light to-navy rounded-3xl p-6 sm:p-8 text-white shadow-xl border border-white/10">
                <div class="relative z-10 max-w-2xl space-y-3">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary-container/20 text-orange-400 border border-primary-container/30 text-xs font-bold">
                        <span class="w-2 h-2 rounded-full bg-primary-container animate-ping"></span>
                        Không gian điều hành trung tâm MEnglish
                    </div>
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight text-white leading-tight">
                        Quản Trị Vận Hành &amp; Đào Tạo Toàn Diện
                    </h2>
                    <p class="text-xs sm:text-sm text-gray-300 leading-relaxed">
                        Hệ thống điều hành tích hợp CRM Tuyển sinh, Quản lý Học vụ, Học phí, Chấm công Tính lương và Ngân hàng Đề thi chuẩn hóa.
                    </p>
                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <a href="{{ route('crm.pipeline') }}" class="px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">view_kanban</span>
                            <span>CRM Tuyển sinh</span>
                        </a>
                        <a href="{{ route('tuition.students') }}" class="px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-semibold backdrop-blur-sm transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[18px]">monetization_on</span>
                            <span>Học phí</span>
                        </a>
                        <a href="{{ route('tasks.index') }}" class="px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-semibold backdrop-blur-sm transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[18px]">task_alt</span>
                            <span>Giao việc &amp; TA</span>
                        </a>
                    </div>
                </div>
                <div class="absolute -right-10 -bottom-10 w-80 h-80 bg-primary-container/20 rounded-full blur-3xl pointer-events-none"></div>
            </div>
        @else
            <!-- Welcome Banner for Staff / Teachers -->
            <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-bold text-gray-900">Xin chào, {{ $user->name }}!</h2>
                    <p class="text-xs text-gray-500 mt-1">Vai trò: <span class="font-semibold text-primary">{{ ucfirst($user->getRoleNames()->first() ?? 'Nhân viên') }}</span> · Chi nhánh: {{ $user->branch?->name ?? 'Trung tâm' }}</p>
                </div>
                <div class="flex items-center gap-2">
                    @if($canPayroll)
                        <a href="{{ route('portal.my-salary') }}" class="px-4 py-2 rounded-xl bg-cyan-50 text-cyan-700 hover:bg-cyan-100 font-semibold text-xs transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px]">payments</span> Lương của tôi
                        </a>
                    @endif
                    @if($canTask)
                        <a href="{{ route('portal.ta-tasks') }}" class="px-4 py-2 rounded-xl bg-orange-50 text-primary hover:bg-orange-100 font-semibold text-xs transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px]">checklist</span> Nhiệm vụ hôm nay
                        </a>
                    @endif
                </div>
            </div>
        @endif

        <!-- Key Operating KPI Cards (Gated by Permissions) -->
        @php
            $dbLeadCount = \App\Models\CrmCustomer::count();
            $dbWonCount = \App\Models\CrmCustomer::where('stage', 'won')->count();
            $dbTuitionPaid = \App\Models\StudentTuition::sum('paid_amount');
            $dbOverdueCount = \App\Models\StudentTuition::where('status', 'overdue')->count();
            $dbStudentCount = \App\Models\Student::count();
            $dbClassCount = \App\Models\ClassModel::count();
            $latestPayroll = \App\Models\PayrollPeriod::latest()->first();
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @if($canLead)
                <!-- CRM Lead KPI -->
                <a href="{{ route('crm.pipeline') }}" class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-primary-container hover:shadow-md transition group">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Leads Tuyển Sinh</span>
                        <div class="w-10 h-10 rounded-xl bg-orange-50 text-primary flex items-center justify-center group-hover:scale-110 transition">
                            <span class="material-symbols-outlined text-xl">pie_chart</span>
                        </div>
                    </div>
                    <div class="mt-3 text-2xl font-black text-gray-900">{{ $dbLeadCount }} leads</div>
                    <div class="mt-1 flex items-center justify-between text-xs">
                        <span class="text-emerald-600 font-bold">{{ $dbWonCount }} deals đã chốt</span>
                        <span class="text-primary font-bold group-hover:translate-x-1 transition">→ Pipeline</span>
                    </div>
                </a>
            @endif

            @if($canTuition)
                <!-- Tuition KPI -->
                <a href="{{ route('tuition.students') }}" class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-amber-500 hover:shadow-md transition group">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Thu Học Phí (Thực thu)</span>
                        <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center group-hover:scale-110 transition">
                            <span class="material-symbols-outlined text-xl">payments</span>
                        </div>
                    </div>
                    <div class="mt-3 text-2xl font-black text-gray-900">{{ number_format($dbTuitionPaid / 1000000, 1) }} tr</div>
                    <div class="mt-1 flex items-center justify-between text-xs">
                        <span class="text-rose-600 font-bold">{{ $dbOverdueCount }} HV quá hạn</span>
                        <span class="text-amber-600 font-bold group-hover:translate-x-1 transition">→ Thu phí</span>
                    </div>
                </a>
            @endif

            @if($canStudent || $canClass)
                <!-- Active Students KPI -->
                <a href="{{ route('students.index') }}" class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-emerald-500 hover:shadow-md transition group">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Học Viên Trong Hệ Thống</span>
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center group-hover:scale-110 transition">
                            <span class="material-symbols-outlined text-xl">school</span>
                        </div>
                    </div>
                    <div class="mt-3 text-2xl font-black text-gray-900">{{ $dbStudentCount }} học viên</div>
                    <div class="mt-1 flex items-center justify-between text-xs">
                        <span class="text-emerald-600 font-bold">{{ $dbClassCount }} lớp đang chạy</span>
                        <span class="text-emerald-600 font-bold group-hover:translate-x-1 transition">→ Hồ sơ</span>
                    </div>
                </a>
            @endif

            @if($canPayroll)
                <!-- Payroll KPI -->
                <a href="{{ $user->can('payroll.view') ? route('payroll.periods.index') : route('portal.my-salary') }}" class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-cyan-500 hover:shadow-md transition group">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Lương &amp; Thu nhập</span>
                        <div class="w-10 h-10 rounded-xl bg-cyan-50 text-cyan-600 flex items-center justify-center group-hover:scale-110 transition">
                            <span class="material-symbols-outlined text-xl">account_balance_wallet</span>
                        </div>
                    </div>
                    <div class="mt-3 text-2xl font-black text-gray-900">{{ $user->can('payroll.view') ? number_format(($latestPayroll?->total_amount ?? 0) / 1000000, 1) . ' tr' : 'Xem phiếu' }}</div>
                    <div class="mt-1 flex items-center justify-between text-xs">
                        <span class="text-cyan-700 font-bold">{{ $latestPayroll?->title ?? 'Tháng 08/2026' }}</span>
                        <span class="text-cyan-600 font-bold group-hover:translate-x-1 transition">→ Chi tiết</span>
                    </div>
                </a>
            @endif
        </div>

        <!-- Quick Access Module Grid -->
        <div class="space-y-3">
            <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-base">grid_view</span>
                Các Phân Hệ Chức Năng Của Bạn
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @if($canLead)
                    <!-- Module 1: CRM -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-primary-container/50 transition space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-orange-50 text-primary flex items-center justify-center">
                                    <span class="material-symbols-outlined text-lg">pie_chart</span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-sm text-gray-900">CRM &amp; Tuyển sinh</h3>
                                    <span class="text-[11px] text-gray-400">Quản lý khách hàng tiềm năng</span>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <a href="{{ route('crm.pipeline') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-orange-50 hover:text-primary transition font-medium">Pipeline Kanban</a>
                            <a href="{{ route('crm.customers.index') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-orange-50 hover:text-primary transition font-medium">DS Khách hàng</a>
                            <a href="{{ route('crm.closing-wizard') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-orange-50 hover:text-primary transition font-medium">Chốt &amp; Xếp lớp</a>
                            <a href="{{ route('crm.reports') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-orange-50 hover:text-primary transition font-medium">Báo cáo Doanh số</a>
                        </div>
                    </div>
                @endif

                @if($canTuition)
                    <!-- Module 2: Tuition -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-amber-500/50 transition space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-lg">receipt_long</span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-sm text-gray-900">Học phí &amp; Hóa đơn</h3>
                                    <span class="text-[11px] text-gray-400">Thu phí và quản lý công nợ</span>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <a href="{{ route('tuition.students') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-amber-50 hover:text-amber-700 transition font-medium">DS Thu phí</a>
                            <a href="{{ route('tuition.receipts.create') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-amber-50 hover:text-amber-700 transition font-medium">Lập Phiếu thu</a>
                            <a href="{{ route('tuition.receipts.approve') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-amber-50 hover:text-amber-700 transition font-medium">Duyệt Phiếu thu</a>
                            <a href="{{ route('tuition.overdue') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-amber-50 hover:text-amber-700 transition font-medium">Thu quá hạn</a>
                        </div>
                    </div>
                @endif

                @if($canStudent)
                    <!-- Module 3: Students -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-emerald-500/50 transition space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-lg">school</span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-sm text-gray-900">Hồ sơ Học sinh</h3>
                                    <span class="text-[11px] text-gray-400">Quản lý thông tin học viên</span>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <a href="{{ route('students.index') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-emerald-50 hover:text-emerald-700 transition font-medium">DS &amp; Liên kết lớp</a>
                            <a href="{{ route('students.index', ['status' => 'waiting_start']) }}" class="p-2 rounded-lg bg-gray-50 hover:bg-emerald-50 hover:text-emerald-700 transition font-medium">Chờ khai giảng</a>
                            <a href="{{ route('students.index', ['status' => 'studying']) }}" class="p-2 rounded-lg bg-gray-50 hover:bg-emerald-50 hover:text-emerald-700 transition font-medium">Đang học</a>
                            <a href="{{ route('students.enrollments') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-emerald-50 hover:text-emerald-700 transition font-medium">Xác nhận nhập học</a>
                        </div>
                    </div>
                @endif

                @if($canClass)
                    <!-- Module 4: Classes -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-blue-500/50 transition space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-lg">meeting_room</span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-sm text-gray-900">Lớp học &amp; Lịch dạy</h3>
                                    <span class="text-[11px] text-gray-400">Lịch học, điểm danh &amp; TKB</span>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <a href="{{ route('tasks.classes-dashboard') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-blue-50 hover:text-blue-700 transition font-medium">Dashboard Lớp</a>
                            <a href="{{ route('tasks.schedule-config') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-blue-50 hover:text-blue-700 transition font-medium">TKB Lớp học</a>
                            <a href="{{ route('payroll.timesheets.teachers') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-blue-50 hover:text-blue-700 transition font-medium">Lịch dạy GV</a>
                        </div>
                    </div>
                @endif

                @if($canTask)
                    <!-- Module 5: Tasks -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-orange-500/50 transition space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-orange-50 text-orange-600 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-lg">task_alt</span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-sm text-gray-900">Phân công &amp; Trợ giảng</h3>
                                    <span class="text-[11px] text-gray-400">Công việc ca trực &amp; báo cáo</span>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <a href="{{ route('tasks.index') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-orange-50 hover:text-primary transition font-medium">Danh sách việc</a>
                            <a href="{{ route('portal.ta-tasks') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-orange-50 hover:text-primary transition font-medium">Nhiệm vụ hôm nay</a>
                            <a href="{{ route('tasks.class-reports.create') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-orange-50 hover:text-primary transition font-medium">Báo cáo trực lớp</a>
                        </div>
                    </div>
                @endif

                @if($canSyllabus)
                    <!-- Module 6: Syllabus -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-purple-500/50 transition space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-lg">auto_stories</span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-sm text-gray-900">Syllabus &amp; Giáo trình</h3>
                                    <span class="text-[11px] text-gray-400">Soạn giáo trình &amp; Big Test</span>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <a href="{{ route('syllabus.documents') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-purple-50 hover:text-purple-700 transition font-medium">Giáo trình tài liệu</a>
                            <a href="{{ route('syllabus.builder') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-purple-50 hover:text-purple-700 transition font-medium">Soạn Syllabus</a>
                            <a href="{{ route('syllabus.big-tests.distribution') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-purple-50 hover:text-purple-700 transition font-medium">Phân phối Big Test</a>
                        </div>
                    </div>
                @endif

                @if($canSystem)
                    <!-- Module 7: System Config -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:border-indigo-500/50 transition space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-lg">settings</span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-sm text-gray-900">Quản trị Hệ thống</h3>
                                    <span class="text-[11px] text-gray-400">Tài khoản &amp; Phân quyền</span>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <a href="{{ route('users.index') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-indigo-50 hover:text-indigo-700 transition font-medium">Tài khoản</a>
                            <a href="{{ route('roles.index') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-indigo-50 hover:text-indigo-700 transition font-medium">Vai trò (Roles)</a>
                            <a href="{{ route('permissions.index') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-indigo-50 hover:text-indigo-700 transition font-medium">Permissions</a>
                            <a href="{{ route('activity-logs.index') }}" class="p-2 rounded-lg bg-gray-50 hover:bg-indigo-50 hover:text-indigo-700 transition font-medium">Nhật ký vận hành</a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
