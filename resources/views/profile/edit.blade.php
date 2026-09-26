<x-app-layout>
    {{-- Theo trạng thái tài khoản (không theo ?force_password) để tắt ngay sau khi đổi mật khẩu --}}
    @if(auth()->user()?->must_change_password)
        <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
            <div class="rounded-xl border border-warning/30 bg-warning-container p-4 text-sm font-semibold text-on-warning-container">
                Đây là mật khẩu tạm. Vui lòng đổi mật khẩu trước khi tiếp tục sử dụng hệ thống.
            </div>
        </div>
    @endif
    <div x-data="{ activeTab: 'operations' }" class="space-y-6">
        {{-- Top Profile Banner & User Identity --}}
        <div class="bg-surface-container-lowest rounded-3xl border border-surface-container-highest shadow-sm overflow-hidden">
            <div class="h-28 sm:h-32 bg-gradient-to-r from-[#0d1527] via-[#1a2c4e] to-secondary relative p-6">
                <div class="absolute inset-0 bg-[radial-gradient(#ffffff_1px,transparent_1px)] [background-size:16px_16px] opacity-10"></div>
            </div>

            <div class="px-6 sm:px-8 pb-6 relative">
                <div class="flex flex-col sm:flex-row sm:items-end justify-between -mt-12 sm:-mt-14 gap-4 pb-6 border-b border-surface-container-highest">
                    <div class="flex items-end gap-4">
                        {{-- Avatar --}}
                        <div class="relative">
                            <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-2xl bg-gradient-to-br from-primary-container via-primary-container to-warning/70 text-white flex items-center justify-center text-3xl sm:text-4xl font-black border-4 border-white shadow-xl">
                                {{ Str::substr($user->name ?? 'A', 0, 1) }}
                            </div>
                            <span class="absolute bottom-1 right-1 w-4 h-4 bg-tertiary border-2 border-white rounded-full title='Đang hoạt động'"></span>
                        </div>

                        {{-- User Info --}}
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-xl sm:text-2xl font-black text-on-surface">{{ $user->name }}</h1>
                                @foreach ($user->getRoleNames() as $role)
                                    <x-ui.badge color="secondary" :pill="true">
                                        {{ ucfirst($role) }}
                                    </x-ui.badge>
                                @endforeach
                            </div>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-on-surface-variant">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm text-on-surface-variant/70">mail</span>
                                    <span>{{ $user->email }}</span>
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm text-on-surface-variant/70">domain</span>
                                    <span>{{ $user->branch?->name ?? 'Toàn hệ thống ME Education' }}</span>
                                </span>
                                <span class="flex items-center gap-1 font-mono">
                                    <span class="material-symbols-outlined text-sm text-on-surface-variant/70">badge</span>
                                    <span>#NV-{{ str_pad($user->id, 4, '0', STR_PAD_LEFT) }}</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Fast Actions --}}
                    <div class="flex items-center gap-2 self-start sm:self-auto">
                        <x-ui.button variant="secondary" icon="bug_report" :href="route('tickets.create')">Báo lỗi / Ticket</x-ui.button>
                        <x-ui.button icon="manage_accounts" x-on:click="activeTab = 'settings'">Cài đặt tài khoản</x-ui.button>
                    </div>
                </div>

                {{-- 4 KPI Highlight Cards for Current User --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5 pt-5">
                    {{-- Stat 1: Lương thực lĩnh --}}
                    <x-ui.stat-card label="Lương kỳ gần nhất" icon="wallet" tone="success"
                        :value="$latestPayroll ? number_format($latestPayroll->net_salary) . 'đ' : 'Chưa kết chuyển'"
                        :hint="$latestPayroll && $latestPayroll->period ? 'Kỳ ' . $latestPayroll->period->name : 'Theo dõi tự động'" />

                    {{-- Stat 2: Giờ dạy / Chấm công --}}
                    <x-ui.stat-card label="Giờ dạy tháng này" icon="schedule" tone="secondary"
                        :value="number_format($totalMonthlyHours, 1) . ' giờ'"
                        :hint="'Tháng ' . now()->format('m/Y') . ' (' . count($monthlyTimesheets) . ' ca)'" />

                    {{-- Stat 3: Nhiệm vụ đang phụ trách --}}
                    <x-ui.stat-card label="Việc cần làm" icon="task_alt" tone="warning"
                        :value="$pendingTasksCount . ' việc'" hint="Đang trong tiến độ" />

                    {{-- Stat 4: Tickets hỗ trợ --}}
                    <x-ui.stat-card label="Ticket cá nhân" icon="confirmation_number" tone="secondary"
                        :value="count($myTickets) . ' yêu cầu'" hint="Đã tiếp nhận IT" />
                </div>

                {{-- Navigation Tabs --}}
                <div class="flex items-center gap-2 sm:gap-4 overflow-x-auto border-b border-surface-container-highest mt-6 pt-2 scrollbar-none">
                    <button 
                        @click="activeTab = 'operations'"
                        class="pb-3 px-2 text-xs font-bold transition whitespace-nowrap flex items-center gap-1.5 border-b-2"
                        :class="activeTab === 'operations' ? 'border-primary-container text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface'"
                    >
                        <span class="material-symbols-outlined text-[18px]">dashboard</span>
                        <span>Vận hành &amp; Nhiệm vụ</span>
                    </button>

                    <button 
                        @click="activeTab = 'payroll'"
                        class="pb-3 px-2 text-xs font-bold transition whitespace-nowrap flex items-center gap-1.5 border-b-2"
                        :class="activeTab === 'payroll' ? 'border-primary-container text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface'"
                    >
                        <span class="material-symbols-outlined text-[18px]">payments</span>
                        <span>Lương &amp; Phiếu lương cá nhân</span>
                    </button>

                    <button 
                        @click="activeTab = 'tickets'"
                        class="pb-3 px-2 text-xs font-bold transition whitespace-nowrap flex items-center gap-1.5 border-b-2"
                        :class="activeTab === 'tickets' ? 'border-primary-container text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface'"
                    >
                        <span class="material-symbols-outlined text-[18px]">bug_report</span>
                        <span>Báo lỗi &amp; Ticket ({{ count($myTickets) }})</span>
                    </button>

                    <button 
                        @click="activeTab = 'settings'"
                        class="pb-3 px-2 text-xs font-bold transition whitespace-nowrap flex items-center gap-1.5 border-b-2"
                        :class="activeTab === 'settings' ? 'border-primary-container text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface'"
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
                    <div class="bg-surface-container-lowest rounded-3xl border border-surface-container-highest p-6 shadow-sm space-y-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-black text-on-surface uppercase tracking-wider flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-[20px]">assignment</span>
                                Nhiệm vụ &amp; Công việc được giao
                            </h2>
                            <a href="{{ route('tasks.index') }}" class="text-xs font-bold text-primary hover:underline">
                                Xem tất cả việc &rarr;
                            </a>
                        </div>

                        <div class="space-y-2.5">
                            @forelse ($myTasks as $task)
                                <div class="p-3.5 rounded-2xl bg-surface-container-low/70 border border-surface-container-highest hover:border-surface-container-highest transition flex items-center justify-between gap-3">
                                    <div class="space-y-1 min-w-0 flex-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-bold text-xs text-on-surface truncate">{{ $task->title }}</span>
                                            @if ($task->status === 'completed')
                                                <x-ui.badge color="success" :pill="true">Hoàn thành</x-ui.badge>
                                            @elseif ($task->status === 'in_progress')
                                                <x-ui.badge color="secondary" :pill="true">Đang làm</x-ui.badge>
                                            @else
                                                <x-ui.badge color="warning" :pill="true">Chờ xử lý</x-ui.badge>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-on-surface-variant flex items-center gap-3">
                                            @if ($task->due_date)
                                                <span class="flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-xs text-on-surface-variant/70">event</span>
                                                    <span>Hạn: {{ $task->due_date->format('d/m/Y') }}</span>
                                                </span>
                                            @endif
                                            @if ($task->time_slot_category)
                                                <span>Ca: {{ $task->time_slot_category }}</span>
                                            @endif
                                        </div>
                                    </div>

                                    <x-ui.button variant="secondary" size="sm" :href="route('tasks.index')">
                                        Chi tiết
                                    </x-ui.button>
                                </div>
                            @empty
                                <x-ui.empty-state icon="task" title="Hiện tại bạn không có nhiệm vụ tồn đọng nào cần xử lý." />
                            @endforelse
                        </div>
                    </div>

                    {{-- Classes taught or assisted --}}
                    <div class="bg-surface-container-lowest rounded-3xl border border-surface-container-highest p-6 shadow-sm space-y-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-black text-on-surface uppercase tracking-wider flex items-center gap-2">
                                <span class="material-symbols-outlined text-secondary text-[20px]">school</span>
                                Lớp học đang phụ trách
                            </h2>
                            <a href="{{ route('tasks.classes-dashboard') }}" class="text-xs font-bold text-primary hover:underline">
                                Xem Dashboard Lớp &rarr;
                            </a>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @forelse ($assignedClasses as $cls)
                                <div class="p-4 rounded-2xl bg-surface-container-low/70 border border-surface-container-highest space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="font-extrabold text-xs text-on-surface font-mono">{{ $cls->code }}</span>
                                        <x-ui.badge color="secondary" :pill="true">
                                            {{ \App\Support\StatusLabel::for($cls->status) }}
                                        </x-ui.badge>
                                    </div>
                                    <div class="text-xs font-bold text-on-surface truncate">{{ $cls->name }}</div>
                                    <div class="text-[11px] text-on-surface-variant space-y-0.5">
                                        <div>Khóa: {{ $cls->course?->name ?? 'Chưa cập nhật' }}</div>
                                        <div>Lịch: {{ $cls->schedule_text ?? 'Chưa cập nhật' }}</div>
                                    </div>
                                </div>
                            @empty
                                <x-ui.empty-state class="col-span-2" icon="meeting_room" title="Chưa có lớp học được gán trực tiếp cho tài khoản này." />
                            @endforelse
                        </div>
                    </div>
                </div>

                {{-- Right 1 Col: Recent Activities & Timesheet widget --}}
                <div class="space-y-6">
                    {{-- Quick Timesheet summary --}}
                    <div class="bg-surface-container-lowest rounded-3xl border border-surface-container-highest p-6 shadow-sm space-y-4">
                        <h2 class="text-sm font-black text-on-surface uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-tertiary text-[20px]">history_toggle_off</span>
                            Ca dạy gần nhất
                        </h2>

                        <div class="space-y-2.5">
                            @forelse ($recentTimesheets as $ts)
                                <div class="p-3 rounded-xl bg-surface-container-low/60 border border-surface-container-highest flex items-center justify-between text-xs">
                                    <div>
                                        <div class="font-bold text-on-surface">{{ $ts->classModel?->code ?? 'Lớp giảng dạy' }}</div>
                                        <div class="text-[10px] text-on-surface-variant/70">{{ \Carbon\Carbon::parse($ts->teaching_date ?? $ts->date)->format('d/m/Y') }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-extrabold text-on-surface font-mono">{{ $ts->hours }}h</div>
                                        <div class="text-[10px] text-tertiary font-bold">{{ $ts->status_label ?? \App\Support\StatusLabel::for($ts->status) }}</div>
                                    </div>
                                </div>
                            @empty
                                <x-ui.empty-state icon="history_toggle_off" title="Chưa ghi nhận ca dạy gần đây." />
                            @endforelse
                        </div>
                    </div>

                    {{-- Personal Activity Log --}}
                    <div class="bg-surface-container-lowest rounded-3xl border border-surface-container-highest p-6 shadow-sm space-y-4">
                        <h2 class="text-sm font-black text-on-surface uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-secondary text-[20px]">history</span>
                            Nhật ký thao tác gần đây
                        </h2>

                        <div class="space-y-3">
                            @forelse ($myActivities as $act)
                                <div class="text-xs space-y-0.5 border-l-2 border-secondary/30 pl-3 py-0.5">
                                    <div class="font-medium text-on-surface">{{ $act->description }}</div>
                                    <div class="text-[10px] text-on-surface-variant/70 font-mono">{{ $act->created_at->diffForHumans() }}</div>
                                </div>
                            @empty
                                <x-ui.empty-state icon="history" title="Chưa có nhật ký hoạt động hệ thống." />
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- TAB 2: LƯƠNG & PHIẾU LƯƠNG CÁ NHÂN --}}
        <div x-show="activeTab === 'payroll'" class="space-y-6" x-cloak>
            <div class="bg-surface-container-lowest rounded-3xl border border-surface-container-highest p-6 sm:p-8 shadow-sm space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-6 border-b border-surface-container-highest gap-4">
                    <div>
                        <h2 class="text-lg font-black text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">receipt_long</span>
                            Phiếu Lương Cá Nhân Chi Tiết
                        </h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="px-3 py-1 rounded-xl bg-secondary/10 text-secondary text-xs font-bold">
                            Kỳ: {{ $latestPayroll?->period?->name ?? 'Tháng ' . now()->format('m/Y') }}
                        </span>
                        <x-ui.button size="sm" icon="visibility" :href="route('portal.my-salary')">Mở Cổng Lương</x-ui.button>
                    </div>
                </div>

                @if ($latestPayroll)
                    {{-- Payslip Breakdown Grid --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
                        <div class="space-y-3 bg-surface-container-low/70 p-5 rounded-2xl border border-surface-container-highest">
                            <h3 class="font-bold text-on-surface uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-tertiary text-base">add_circle</span>
                                1. Các khoản thu nhập
                            </h3>
                            <div class="flex justify-between py-1.5 border-b border-surface-container-highest">
                                <span class="text-on-surface-variant">Lương cơ bản:</span>
                                <x-ui.money :value="$latestPayroll->base_salary" class="font-bold" />
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-surface-container-highest">
                                <span class="text-on-surface-variant">Thù lao giảng dạy:</span>
                                <x-ui.money :value="$latestPayroll->teaching_salary" class="font-bold" />
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-surface-container-highest">
                                <span class="text-on-surface-variant">Thưởng KPI / Doanh số:</span>
                                <span class="font-mono font-bold text-tertiary">+{{ number_format($latestPayroll->kpi_bonus) }}đ</span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-surface-container-highest">
                                <span class="text-on-surface-variant">Thưởng tái tục học viên:</span>
                                <span class="font-mono font-bold text-tertiary">+{{ number_format($latestPayroll->renew_bonus) }}đ</span>
                            </div>
                            <div class="flex justify-between pt-1">
                                <span class="text-on-surface-variant">Phụ cấp &amp; Trợ cấp:</span>
                                <span class="font-mono font-bold text-tertiary">+{{ number_format($latestPayroll->allowance) }}đ</span>
                            </div>
                        </div>

                        <div class="space-y-3 bg-surface-container-low/70 p-5 rounded-2xl border border-surface-container-highest">
                            <h3 class="font-bold text-on-surface uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-error text-base">remove_circle</span>
                                2. Các khoản giảm trừ
                            </h3>
                            <div class="flex justify-between py-1.5 border-b border-surface-container-highest">
                                <span class="text-on-surface-variant">Bảo hiểm XH &amp; Y tế:</span>
                                <span class="font-mono font-bold text-error">-{{ number_format($latestPayroll->insurance_deduction) }}đ</span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-surface-container-highest">
                                <span class="text-on-surface-variant">Thuế TNCN tạm tính:</span>
                                <span class="font-mono font-bold text-error">-{{ number_format($latestPayroll->tax_deduction) }}đ</span>
                            </div>
                            <div class="flex justify-between pt-1">
                                <span class="text-on-surface-variant">Giảm trừ phạt / Vi phạm:</span>
                                <span class="font-mono font-bold text-error">-{{ number_format($latestPayroll->penalty_deduction) }}đ</span>
                            </div>
                        </div>
                    </div>

                    {{-- Net Salary Banner --}}
                    <div class="p-6 rounded-2xl bg-gradient-to-r from-inverse-surface via-inverse-surface to-secondary text-white flex flex-col sm:flex-row sm:items-center justify-between shadow-xl gap-4">
                        <div>
                            <span class="text-xs text-white/70 block uppercase tracking-wider font-bold">Tổng thực lĩnh chuyển khoản:</span>
                            <span class="text-2xl sm:text-3xl font-black font-mono text-primary">{{ number_format($latestPayroll->net_salary) }}đ</span>
                        </div>
                        <div class="text-left sm:text-right">
                            <span class="text-xs text-white/70 block">Trạng thái phiếu lương:</span>
                            <span class="text-sm font-bold text-tertiary-container flex items-center gap-1 sm:justify-end">
                                <span class="material-symbols-outlined text-base">verified</span>
                                <span>{{ $latestPayroll->status === 'paid' ? 'Đã thanh toán' : ($latestPayroll->status === 'approved' ? 'Đã duyệt chi' : 'Dự thảo') }}</span>
                            </span>
                        </div>
                    </div>
                @else
                    <x-ui.empty-state class="bg-surface-container-low/50 rounded-2xl border border-surface-container-highest" icon="receipt"
                        title="Chưa có bản ghi phiếu lương nào cho tài khoản này."
                        description="Phiếu lương sẽ tự động hiển thị sau khi bộ phận Kế toán / HR chốt bảng lương định kỳ hàng tháng." />
                @endif

                {{-- Payroll History Table --}}
                <div class="pt-4 space-y-3">
                    <h3 class="font-black text-sm text-on-surface uppercase tracking-wider">Lịch sử các kỳ lương gần đây</h3>
                    <x-ui.data-table>
                        <table>
                            <thead>
                                <tr>
                                    <th>Kỳ lương</th>
                                    <th class="text-right">Lương cơ bản</th>
                                    <th class="text-right">Giảng dạy &amp; KPI</th>
                                    <th class="text-right">Giảm trừ</th>
                                    <th class="text-right">Thực lĩnh</th>
                                    <th class="text-center">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($recentPayrolls as $p)
                                    <tr>
                                        <td class="font-bold text-on-surface">{{ $p->period?->name ?? 'Kỳ ' . $p->created_at->format('m/Y') }}</td>
                                        <td class="text-right"><x-ui.money :value="$p->base_salary" /></td>
                                        <td class="text-right font-mono text-tertiary">+{{ number_format($p->teaching_salary + $p->kpi_bonus + $p->renew_bonus) }}đ</td>
                                        <td class="text-right font-mono text-error">-{{ number_format($p->insurance_deduction + $p->tax_deduction + $p->penalty_deduction) }}đ</td>
                                        <td class="text-right font-mono font-black text-primary">{{ number_format($p->net_salary) }}đ</td>
                                        <td class="text-center">
                                            <x-ui.badge :color="$p->status === 'paid' ? 'success' : 'secondary'" :pill="true">
                                                {{ \App\Support\StatusLabel::for($p->status) }}
                                            </x-ui.badge>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6"><x-ui.empty-state icon="receipt_long" title="Chưa có lịch sử kỳ lương nào." /></td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </x-ui.data-table>
                </div>
            </div>
        </div>

        {{-- TAB 3: BÁO LỖI & TICKET CÁ NHÂN --}}
        <div x-show="activeTab === 'tickets'" class="space-y-6" x-cloak>
            <div class="bg-surface-container-lowest rounded-3xl border border-surface-container-highest p-6 sm:p-8 shadow-sm space-y-5">
                <div class="flex items-center justify-between pb-4 border-b border-surface-container-highest">
                    <div>
                        <h2 class="text-base font-black text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-error">bug_report</span>
                            Yêu cầu hỗ trợ &amp; Ticket báo lỗi của bạn
                        </h2>
                    </div>

                    <x-ui.button icon="add" :href="route('tickets.create')">Tạo Ticket Mới</x-ui.button>
                </div>

                <div class="space-y-3">
                    @forelse ($myTickets as $ticket)
                        <a href="{{ route('tickets.show', $ticket->id) }}" class="block p-4 rounded-2xl bg-surface-container-low/70 border border-surface-container-highest hover:border-outline-variant hover:bg-surface-container-lowest transition group shadow-2xs">
                            <div class="flex items-center justify-between gap-3">
                                <div class="space-y-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-mono font-bold text-xs text-primary">{{ $ticket->code }}</span>
                                        <span class="font-bold text-xs text-on-surface group-hover:text-primary transition">{{ $ticket->title }}</span>
                                        <x-ui.badge :color="$ticket->priority === 'urgent' ? 'error' : ($ticket->priority === 'high' ? 'primary' : 'neutral')" :pill="true">
                                            {{ $ticket->priority_label }}
                                        </x-ui.badge>
                                    </div>
                                    <div class="text-[11px] text-on-surface-variant flex items-center gap-3">
                                        <span>Danh mục: {{ $ticket->category_label }}</span>
                                        <span>Gửi lúc: {{ $ticket->created_at->format('d/m/Y H:i') }}</span>
                                        @if ($ticket->assignee)
                                            <span>Phụ trách: {{ $ticket->assignee->name }}</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 shrink-0">
                                    <x-ui.badge :color="$ticket->status === 'resolved' ? 'success' : ($ticket->status === 'in_progress' ? 'secondary' : 'warning')">
                                        {{ $ticket->status_label }}
                                    </x-ui.badge>
                                    <span class="material-symbols-outlined text-on-surface-variant/70 group-hover:text-primary transition">chevron_right</span>
                                </div>
                            </div>
                        </a>
                    @empty
                        <x-ui.empty-state icon="task_alt" title="Bạn chưa gửi yêu cầu hỗ trợ hoặc báo lỗi nào." />
                    @endforelse
                </div>
            </div>
        </div>

        {{-- TAB 4: CÀI ĐẶT TÀI KHOẢN & ĐỔI MẬT KHẨU --}}
        <div x-show="activeTab === 'settings'" class="space-y-6" x-cloak>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Info form --}}
                <div class="p-6 sm:p-8 bg-surface-container-lowest border border-surface-container-highest shadow-sm rounded-3xl">
                    @include('profile.partials.update-profile-information-form')
                </div>

                {{-- Password form --}}
                <div class="p-6 sm:p-8 bg-surface-container-lowest border border-surface-container-highest shadow-sm rounded-3xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
