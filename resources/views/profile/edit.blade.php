<x-app-layout>
    @if(request()->boolean('force_password'))
        <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-900">
                Đây là mật khẩu tạm. Vui lòng đổi mật khẩu trước khi tiếp tục sử dụng hệ thống.
            </div>
        </div>
    @endif
    <div x-data="{ activeTab: 'operations' }" class="space-y-6">
        {{-- Top Profile Banner & User Identity --}}
        <div class="bg-white rounded-3xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="h-28 sm:h-32 bg-gradient-to-r from-[#0d1527] via-[#1a2c4e] to-blue-600 relative p-6">
                <div class="absolute inset-0 bg-[radial-gradient(#ffffff_1px,transparent_1px)] [background-size:16px_16px] opacity-10"></div>
            </div>

            <div class="px-6 sm:px-8 pb-6 relative">
                <div class="flex flex-col sm:flex-row sm:items-end justify-between -mt-12 sm:-mt-14 gap-4 pb-6 border-b border-gray-100">
                    <div class="flex items-end gap-4">
                        {{-- Avatar --}}
                        <div class="relative">
                            <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-2xl bg-gradient-to-br from-primary-container via-orange-500 to-amber-400 text-white flex items-center justify-center text-3xl sm:text-4xl font-black border-4 border-white shadow-xl">
                                {{ Str::substr($user->name ?? 'A', 0, 1) }}
                            </div>
                            <span class="absolute bottom-1 right-1 w-4 h-4 bg-emerald-500 border-2 border-white rounded-full title='Đang hoạt động'"></span>
                        </div>

                        {{-- User Info --}}
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-xl sm:text-2xl font-black text-gray-900">{{ $user->name }}</h1>
                                @foreach ($user->getRoleNames() as $role)
                                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-extrabold uppercase tracking-wider bg-blue-50 text-blue-700 border border-blue-200">
                                        {{ ucfirst($role) }}
                                    </span>
                                @endforeach
                            </div>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm text-gray-400">mail</span>
                                    <span>{{ $user->email }}</span>
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm text-gray-400">domain</span>
                                    <span>{{ $user->branch?->name ?? 'Toàn hệ thống ME Education' }}</span>
                                </span>
                                <span class="flex items-center gap-1 font-mono">
                                    <span class="material-symbols-outlined text-sm text-gray-400">badge</span>
                                    <span>#NV-{{ str_pad($user->id, 4, '0', STR_PAD_LEFT) }}</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Fast Actions --}}
                    <div class="flex items-center gap-2 self-start sm:self-auto">
                        <a href="{{ route('tickets.create') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-xs font-bold text-gray-700 shadow-sm transition">
                            <span class="material-symbols-outlined text-[18px] text-rose-500">bug_report</span>
                            <span>Báo lỗi / Ticket</span>
                        </a>
                        <button @click="activeTab = 'settings'" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition">
                            <span class="material-symbols-outlined text-[18px]">manage_accounts</span>
                            <span>Cài đặt tài khoản</span>
                        </button>
                    </div>
                </div>

                {{-- 4 KPI Highlight Cards for Current User --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5 pt-5">
                    {{-- Stat 1: Lương thực lĩnh --}}
                    <div class="bg-gray-50/80 p-3.5 rounded-2xl border border-gray-100 space-y-1">
                        <div class="flex items-center justify-between text-gray-500">
                            <span class="text-[11px] font-bold uppercase tracking-wider">Lương kỳ gần nhất</span>
                            <span class="material-symbols-outlined text-base text-emerald-600">wallet</span>
                        </div>
                        <div class="text-lg sm:text-xl font-black text-gray-900 font-mono">
                            {{ $latestPayroll ? number_format($latestPayroll->net_salary) . 'đ' : 'Chưa kết chuyển' }}
                        </div>
                        <div class="text-[11px] text-emerald-600 font-semibold flex items-center gap-1">
                            @if ($latestPayroll && $latestPayroll->period)
                                <span>Kỳ {{ $latestPayroll->period->name }}</span>
                            @else
                                <span>Theo dõi tự động</span>
                            @endif
                        </div>
                    </div>

                    {{-- Stat 2: Giờ dạy / Chấm công --}}
                    <div class="bg-gray-50/80 p-3.5 rounded-2xl border border-gray-100 space-y-1">
                        <div class="flex items-center justify-between text-gray-500">
                            <span class="text-[11px] font-bold uppercase tracking-wider">Giờ dạy tháng này</span>
                            <span class="material-symbols-outlined text-base text-blue-600">schedule</span>
                        </div>
                        <div class="text-lg sm:text-xl font-black text-gray-900 font-mono">
                            {{ number_format($totalMonthlyHours, 1) }} giờ
                        </div>
                        <div class="text-[11px] text-blue-600 font-semibold">
                            <span>Tháng {{ now()->format('m/Y') }} ({{ count($monthlyTimesheets) }} ca)</span>
                        </div>
                    </div>

                    {{-- Stat 3: Nhiệm vụ đang phụ trách --}}
                    <div class="bg-gray-50/80 p-3.5 rounded-2xl border border-gray-100 space-y-1">
                        <div class="flex items-center justify-between text-gray-500">
                            <span class="text-[11px] font-bold uppercase tracking-wider">Việc cần làm</span>
                            <span class="material-symbols-outlined text-base text-amber-500">task_alt</span>
                        </div>
                        <div class="text-lg sm:text-xl font-black text-gray-900 font-mono">
                            {{ $pendingTasksCount }} việc
                        </div>
                        <div class="text-[11px] text-amber-600 font-semibold">
                            <span>Đang trong tiến độ</span>
                        </div>
                    </div>

                    {{-- Stat 4: Tickets hỗ trợ --}}
                    <div class="bg-gray-50/80 p-3.5 rounded-2xl border border-gray-100 space-y-1">
                        <div class="flex items-center justify-between text-gray-500">
                            <span class="text-[11px] font-bold uppercase tracking-wider">Ticket cá nhân</span>
                            <span class="material-symbols-outlined text-base text-indigo-600">confirmation_number</span>
                        </div>
                        <div class="text-lg sm:text-xl font-black text-gray-900 font-mono">
                            {{ count($myTickets) }} yêu cầu
                        </div>
                        <div class="text-[11px] text-indigo-600 font-semibold">
                            <span>Đã tiếp nhận IT</span>
                        </div>
                    </div>
                </div>

                {{-- Navigation Tabs --}}
                <div class="flex items-center gap-2 sm:gap-4 overflow-x-auto border-b border-gray-200 mt-6 pt-2 scrollbar-none">
                    <button 
                        @click="activeTab = 'operations'"
                        class="pb-3 px-2 text-xs font-bold transition whitespace-nowrap flex items-center gap-1.5 border-b-2"
                        :class="activeTab === 'operations' ? 'border-primary-container text-primary' : 'border-transparent text-gray-500 hover:text-gray-900'"
                    >
                        <span class="material-symbols-outlined text-[18px]">dashboard</span>
                        <span>Vận hành &amp; Nhiệm vụ</span>
                    </button>

                    <button 
                        @click="activeTab = 'payroll'"
                        class="pb-3 px-2 text-xs font-bold transition whitespace-nowrap flex items-center gap-1.5 border-b-2"
                        :class="activeTab === 'payroll' ? 'border-primary-container text-primary' : 'border-transparent text-gray-500 hover:text-gray-900'"
                    >
                        <span class="material-symbols-outlined text-[18px]">payments</span>
                        <span>Lương &amp; Phiếu lương cá nhân</span>
                    </button>

                    <button 
                        @click="activeTab = 'tickets'"
                        class="pb-3 px-2 text-xs font-bold transition whitespace-nowrap flex items-center gap-1.5 border-b-2"
                        :class="activeTab === 'tickets' ? 'border-primary-container text-primary' : 'border-transparent text-gray-500 hover:text-gray-900'"
                    >
                        <span class="material-symbols-outlined text-[18px]">bug_report</span>
                        <span>Báo lỗi &amp; Ticket ({{ count($myTickets) }})</span>
                    </button>

                    <button 
                        @click="activeTab = 'settings'"
                        class="pb-3 px-2 text-xs font-bold transition whitespace-nowrap flex items-center gap-1.5 border-b-2"
                        :class="activeTab === 'settings' ? 'border-primary-container text-primary' : 'border-transparent text-gray-500 hover:text-gray-900'"
                    >
                        <span class="material-symbols-outlined text-[18px]">settings</span>
                        <span>Cài đặt tài khoản &amp; Bảo mật</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- TAB 1: VẬN HÀNH & NHIỆM VỤ CÁ NHÂN --}}
        <div x-show="activeTab === 'operations'" class="space-y-6" x-cloak>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {{-- Left 2 Cols: My Assigned Work Tasks --}}
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm space-y-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-black text-gray-900 uppercase tracking-wider flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-[20px]">assignment</span>
                                Nhiệm vụ &amp; Công việc được giao
                            </h2>
                            <a href="{{ route('tasks.index') }}" class="text-xs font-bold text-primary hover:underline">
                                Xem tất cả việc &rarr;
                            </a>
                        </div>

                        <div class="space-y-2.5">
                            @forelse ($myTasks as $task)
                                <div class="p-3.5 rounded-2xl bg-gray-50/70 border border-gray-100 hover:border-gray-200 transition flex items-center justify-between gap-3">
                                    <div class="space-y-1 min-w-0 flex-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-bold text-xs text-gray-900 truncate">{{ $task->title }}</span>
                                            @if ($task->status === 'completed')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Hoàn thành</span>
                                            @elseif ($task->status === 'in_progress')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">Đang làm</span>
                                            @else
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">Chờ xử lý</span>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-gray-500 flex items-center gap-3">
                                            @if ($task->due_date)
                                                <span class="flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-xs text-gray-400">event</span>
                                                    <span>Hạn: {{ $task->due_date->format('d/m/Y') }}</span>
                                                </span>
                                            @endif
                                            @if ($task->time_slot_category)
                                                <span>Ca: {{ $task->time_slot_category }}</span>
                                            @endif
                                        </div>
                                    </div>

                                    <a href="{{ route('tasks.index') }}" class="px-3 py-1.5 rounded-xl bg-white border border-gray-200 hover:bg-gray-100 text-xs font-bold text-gray-700 shadow-2xs shrink-0 transition">
                                        Chi tiết
                                    </a>
                                </div>
                            @empty
                                <div class="py-8 text-center text-xs text-gray-400 space-y-1">
                                    <span class="material-symbols-outlined text-3xl text-gray-300 block mx-auto">task</span>
                                    <div>Hiện tại bạn không có nhiệm vụ tồn đọng nào cần xử lý.</div>
                                </div>
                            @endforelse
                        </div>
                    </div>

                    {{-- Classes taught or assisted --}}
                    <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm space-y-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-black text-gray-900 uppercase tracking-wider flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-[20px]">school</span>
                                Lớp học đang phụ trách
                            </h2>
                            <a href="{{ route('tasks.classes-dashboard') }}" class="text-xs font-bold text-primary hover:underline">
                                Xem Dashboard Lớp &rarr;
                            </a>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @forelse ($assignedClasses as $cls)
                                <div class="p-4 rounded-2xl bg-gray-50/70 border border-gray-100 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="font-extrabold text-xs text-gray-900 font-mono">{{ $cls->code }}</span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700">
                                            {{ \App\Support\StatusLabel::for($cls->status) }}
                                        </span>
                                    </div>
                                    <div class="text-xs font-bold text-gray-800 truncate">{{ $cls->name }}</div>
                                    <div class="text-[11px] text-gray-500 space-y-0.5">
                                        <div>Khóa: {{ $cls->course?->name ?? 'Chưa cập nhật' }}</div>
                                        <div>Lịch: {{ $cls->schedule_text ?? 'Chưa cập nhật' }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="col-span-2 py-6 text-center text-xs text-gray-400 space-y-1">
                                    <span class="material-symbols-outlined text-3xl text-gray-300 block mx-auto">meeting_room</span>
                                    <div>Chưa có lớp học được gán trực tiếp cho tài khoản này.</div>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                {{-- Right 1 Col: Recent Activities & Timesheet widget --}}
                <div class="space-y-6">
                    {{-- Quick Timesheet summary --}}
                    <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm space-y-4">
                        <h2 class="text-sm font-black text-gray-900 uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-emerald-600 text-[20px]">history_toggle_off</span>
                            Ca dạy gần nhất
                        </h2>

                        <div class="space-y-2.5">
                            @forelse ($recentTimesheets as $ts)
                                <div class="p-3 rounded-xl bg-gray-50/60 border border-gray-100 flex items-center justify-between text-xs">
                                    <div>
                                        <div class="font-bold text-gray-900">{{ $ts->classModel?->code ?? 'Lớp giảng dạy' }}</div>
                                        <div class="text-[10px] text-gray-400">{{ \Carbon\Carbon::parse($ts->teaching_date ?? $ts->date)->format('d/m/Y') }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-extrabold text-gray-900 font-mono">{{ $ts->hours }}h</div>
                                        <div class="text-[10px] text-emerald-600 font-bold">{{ $ts->status_label ?? \App\Support\StatusLabel::for($ts->status) }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="py-4 text-center text-xs text-gray-400">
                                    Chưa ghi nhận ca dạy gần đây.
                                </div>
                            @endforelse
                        </div>
                    </div>

                    {{-- Personal Activity Log --}}
                    <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm space-y-4">
                        <h2 class="text-sm font-black text-gray-900 uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-indigo-600 text-[20px]">history</span>
                            Nhật ký thao tác gần đây
                        </h2>

                        <div class="space-y-3">
                            @forelse ($myActivities as $act)
                                <div class="text-xs space-y-0.5 border-l-2 border-indigo-200 pl-3 py-0.5">
                                    <div class="font-medium text-gray-800">{{ $act->description }}</div>
                                    <div class="text-[10px] text-gray-400 font-mono">{{ $act->created_at->diffForHumans() }}</div>
                                </div>
                            @empty
                                <div class="py-4 text-center text-xs text-gray-400">
                                    Chưa có nhật ký hoạt động hệ thống.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- TAB 2: LƯƠNG & PHIẾU LƯƠNG CÁ NHÂN --}}
        <div x-show="activeTab === 'payroll'" class="space-y-6" x-cloak>
            <div class="bg-white rounded-3xl border border-gray-200 p-6 sm:p-8 shadow-sm space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-6 border-b border-gray-100 gap-4">
                    <div>
                        <h2 class="text-lg font-black text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">receipt_long</span>
                            Phiếu Lương Cá Nhân Chi Tiết
                        </h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="px-3 py-1 rounded-xl bg-blue-50 text-blue-700 text-xs font-bold">
                            Kỳ: {{ $latestPayroll?->period?->name ?? 'Tháng ' . now()->format('m/Y') }}
                        </span>
                        <a href="{{ route('portal.my-salary') }}" class="px-3.5 py-1.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition inline-flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">visibility</span>
                            <span>Mở Cổng Lương</span>
                        </a>
                    </div>
                </div>

                @if ($latestPayroll)
                    {{-- Payslip Breakdown Grid --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
                        <div class="space-y-3 bg-gray-50/70 p-5 rounded-2xl border border-gray-100">
                            <h3 class="font-bold text-gray-900 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-emerald-600 text-base">add_circle</span>
                                1. Các khoản thu nhập
                            </h3>
                            <div class="flex justify-between py-1.5 border-b border-gray-200">
                                <span class="text-gray-600">Lương cơ bản:</span>
                                <span class="font-mono font-bold text-gray-900">{{ number_format($latestPayroll->base_salary) }}đ</span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-gray-200">
                                <span class="text-gray-600">Thù lao giảng dạy:</span>
                                <span class="font-mono font-bold text-gray-900">{{ number_format($latestPayroll->teaching_salary) }}đ</span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-gray-200">
                                <span class="text-gray-600">Thưởng KPI / Doanh số:</span>
                                <span class="font-mono font-bold text-emerald-600">+{{ number_format($latestPayroll->kpi_bonus) }}đ</span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-gray-200">
                                <span class="text-gray-600">Thưởng tái tục học viên:</span>
                                <span class="font-mono font-bold text-emerald-600">+{{ number_format($latestPayroll->renew_bonus) }}đ</span>
                            </div>
                            <div class="flex justify-between pt-1">
                                <span class="text-gray-600">Phụ cấp &amp; Trợ cấp:</span>
                                <span class="font-mono font-bold text-emerald-600">+{{ number_format($latestPayroll->allowance) }}đ</span>
                            </div>
                        </div>

                        <div class="space-y-3 bg-gray-50/70 p-5 rounded-2xl border border-gray-100">
                            <h3 class="font-bold text-gray-900 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-rose-600 text-base">remove_circle</span>
                                2. Các khoản giảm trừ
                            </h3>
                            <div class="flex justify-between py-1.5 border-b border-gray-200">
                                <span class="text-gray-600">Bảo hiểm XH &amp; Y tế:</span>
                                <span class="font-mono font-bold text-rose-600">-{{ number_format($latestPayroll->insurance_deduction) }}đ</span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-gray-200">
                                <span class="text-gray-600">Thuế TNCN tạm tính:</span>
                                <span class="font-mono font-bold text-rose-600">-{{ number_format($latestPayroll->tax_deduction) }}đ</span>
                            </div>
                            <div class="flex justify-between pt-1">
                                <span class="text-gray-600">Giảm trừ phạt / Vi phạm:</span>
                                <span class="font-mono font-bold text-rose-600">-{{ number_format($latestPayroll->penalty_deduction) }}đ</span>
                            </div>
                        </div>
                    </div>

                    {{-- Net Salary Banner --}}
                    <div class="p-6 rounded-2xl bg-gradient-to-r from-slate-900 via-slate-800 to-blue-600 text-white flex flex-col sm:flex-row sm:items-center justify-between shadow-xl gap-4">
                        <div>
                            <span class="text-xs text-white/70 block uppercase tracking-wider font-bold">Tổng thực lĩnh chuyển khoản:</span>
                            <span class="text-2xl sm:text-3xl font-black font-mono text-primary">{{ number_format($latestPayroll->net_salary) }}đ</span>
                        </div>
                        <div class="text-left sm:text-right">
                            <span class="text-xs text-white/70 block">Trạng thái phiếu lương:</span>
                            <span class="text-sm font-bold text-emerald-400 flex items-center gap-1 sm:justify-end">
                                <span class="material-symbols-outlined text-base">verified</span>
                                <span>{{ $latestPayroll->status === 'paid' ? 'Đã thanh toán' : ($latestPayroll->status === 'approved' ? 'Đã duyệt chi' : 'Dự thảo') }}</span>
                            </span>
                        </div>
                    </div>
                @else
                    <div class="py-12 text-center text-xs text-gray-400 space-y-2 bg-gray-50/50 rounded-2xl border border-gray-100">
                        <span class="material-symbols-outlined text-4xl text-gray-300 block mx-auto">receipt</span>
                        <div class="font-bold text-gray-700">Chưa có bản ghi phiếu lương nào cho tài khoản này.</div>
                        <p class="max-w-md mx-auto text-gray-500">Phiếu lương sẽ tự động hiển thị sau khi bộ phận Kế toán / HR chốt bảng lương định kỳ hàng tháng.</p>
                    </div>
                @endif

                {{-- Payroll History Table --}}
                <div class="pt-4 space-y-3">
                    <h3 class="font-black text-sm text-gray-900 uppercase tracking-wider">Lịch sử các kỳ lương gần đây</h3>
                    <div class="overflow-x-auto rounded-2xl border border-gray-200">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 text-gray-500 font-bold uppercase tracking-wider border-b border-gray-200">
                                <tr>
                                    <th class="p-3.5">Kỳ lương</th>
                                    <th class="p-3.5 text-right">Lương cơ bản</th>
                                    <th class="p-3.5 text-right">Giảng dạy &amp; KPI</th>
                                    <th class="p-3.5 text-right">Giảm trừ</th>
                                    <th class="p-3.5 text-right">Thực lĩnh</th>
                                    <th class="p-3.5 text-center">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($recentPayrolls as $p)
                                    <tr class="hover:bg-gray-50/80 transition">
                                        <td class="p-3.5 font-bold text-gray-900">{{ $p->period?->name ?? 'Kỳ ' . $p->created_at->format('m/Y') }}</td>
                                        <td class="p-3.5 text-right font-mono">{{ number_format($p->base_salary) }}đ</td>
                                        <td class="p-3.5 text-right font-mono text-emerald-600">+{{ number_format($p->teaching_salary + $p->kpi_bonus + $p->renew_bonus) }}đ</td>
                                        <td class="p-3.5 text-right font-mono text-rose-600">-{{ number_format($p->insurance_deduction + $p->tax_deduction + $p->penalty_deduction) }}đ</td>
                                        <td class="p-3.5 text-right font-mono font-black text-primary">{{ number_format($p->net_salary) }}đ</td>
                                        <td class="p-3.5 text-center">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $p->status === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800' }}">
                                                {{ \App\Support\StatusLabel::for($p->status) }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="p-6 text-center text-gray-400">Chưa có lịch sử kỳ lương nào.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- TAB 3: BÁO LỖI & TICKET CÁ NHÂN --}}
        <div x-show="activeTab === 'tickets'" class="space-y-6" x-cloak>
            <div class="bg-white rounded-3xl border border-gray-200 p-6 sm:p-8 shadow-sm space-y-5">
                <div class="flex items-center justify-between pb-4 border-b border-gray-100">
                    <div>
                        <h2 class="text-base font-black text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-rose-600">bug_report</span>
                            Yêu cầu hỗ trợ &amp; Ticket báo lỗi của bạn
                        </h2>
                    </div>

                    <a href="{{ route('tickets.create') }}" class="px-4 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">add</span>
                        <span>Tạo Ticket Mới</span>
                    </a>
                </div>

                <div class="space-y-3">
                    @forelse ($myTickets as $ticket)
                        <a href="{{ route('tickets.show', $ticket->id) }}" class="block p-4 rounded-2xl bg-gray-50/70 border border-gray-100 hover:border-gray-300 hover:bg-white transition group shadow-2xs">
                            <div class="flex items-center justify-between gap-3">
                                <div class="space-y-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-mono font-bold text-xs text-primary">{{ $ticket->code }}</span>
                                        <span class="font-bold text-xs text-gray-900 group-hover:text-primary transition">{{ $ticket->title }}</span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $ticket->priority === 'urgent' ? 'bg-rose-100 text-rose-800' : ($ticket->priority === 'high' ? 'bg-orange-100 text-orange-800' : 'bg-gray-100 text-gray-800') }}">
                                            {{ $ticket->priority_label }}
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-gray-500 flex items-center gap-3">
                                        <span>Danh mục: {{ $ticket->category_label }}</span>
                                        <span>Gửi lúc: {{ $ticket->created_at->format('d/m/Y H:i') }}</span>
                                        @if ($ticket->assignee)
                                            <span>Phụ trách: {{ $ticket->assignee->name }}</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="px-2.5 py-1 rounded-xl text-xs font-bold {{ $ticket->status === 'resolved' ? 'bg-emerald-100 text-emerald-800' : ($ticket->status === 'in_progress' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') }}">
                                        {{ $ticket->status_label }}
                                    </span>
                                    <span class="material-symbols-outlined text-gray-400 group-hover:text-primary transition">chevron_right</span>
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="py-8 text-center text-xs text-gray-400 space-y-2">
                            <span class="material-symbols-outlined text-3xl text-gray-300 block mx-auto">task_alt</span>
                            <div>Bạn chưa gửi yêu cầu hỗ trợ hoặc báo lỗi nào.</div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- TAB 4: CÀI ĐẶT TÀI KHOẢN & ĐỔI MẬT KHẨU --}}
        <div x-show="activeTab === 'settings'" class="space-y-6" x-cloak>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Info form --}}
                <div class="p-6 sm:p-8 bg-white border border-gray-200 shadow-sm rounded-3xl">
                    @include('profile.partials.update-profile-information-form')
                </div>

                {{-- Password form --}}
                <div class="p-6 sm:p-8 bg-white border border-gray-200 shadow-sm rounded-3xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
