{{-- Dashboard lớp học theo ngày / ma trận khung giờ tuần — dữ liệu lấy từ buổi học thật (class_sessions). --}}
<x-app-layout title="Dashboard lớp học">
    <x-ui.page-header title="Dashboard lớp học" description="Quản lý lịch học, điểm danh và chấm công giảng viên">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="download" :href="request()->fullUrlWithQuery(['export' => 1])" title="Xuất Excel đúng dữ liệu đang xem">Xuất báo cáo</x-ui.button>
            @can('class.create')
                <x-ui.button icon="add" :href="route('classes.create')">Thêm lớp học</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if (! auth()->user()->branch_id && ! auth()->user()->hasRole('admin'))
        <x-ui.alert type="warning" class="mb-lg" data-testid="no-branch-alert">Tài khoản chưa gán chi nhánh, liên hệ Quản trị viên.</x-ui.alert>
    @endif

    <x-ui.tabs class="mb-lg">
        <x-ui.tab icon="today" :href="route('tasks.classes-dashboard', ['tab' => 'day', 'date' => $date, 'branch_id' => $branchId])" :active="$tab === 'day'">Theo ngày</x-ui.tab>
        <x-ui.tab icon="calendar_view_week" :href="route('tasks.classes-dashboard', ['tab' => 'week', 'week' => $week, 'branch_id' => $branchId])" :active="$tab === 'week'">Theo tuần</x-ui.tab>
    </x-ui.tabs>

    @if ($tab === 'day')
        {{-- ─── THEO NGÀY ─── --}}
        <x-ui.filter-bar :search="null" :action="route('tasks.classes-dashboard')" x-data="{ more: {{ $attendanceFilter || $teacherFilter ? 'true' : 'false' }} }">
            <input type="hidden" name="tab" value="day">
            <x-ui.select name="branch_id" inline-label="Chi nhánh:" :options="$branches->pluck('name', 'id')" :value="$branchId" placeholder="Tất cả chi nhánh" />
            <x-ui.date name="date" inline-label="Chọn ngày:" :value="$date" />
            <x-ui.button variant="secondary" icon="filter_list" x-on:click="more = !more" ::aria-expanded="more">Lọc thêm</x-ui.button>
            <div x-show="more" x-cloak class="flex w-full flex-wrap items-center gap-md">
                <x-ui.select name="teacher_id" inline-label="Giáo viên:" :options="$dayTeachers->pluck('name', 'id')" :value="$teacherFilter" placeholder="Tất cả giáo viên" />
                <x-ui.select name="attendance" inline-label="Điểm danh:" :options="['done' => 'Đã điểm danh', 'missing' => 'Chưa điểm danh', 'upcoming' => 'Chưa diễn ra', 'cancelled' => 'Hủy / nghỉ lễ']" :value="$attendanceFilter" placeholder="Tất cả trạng thái" />
            </div>
        </x-ui.filter-bar>

        <div class="mb-lg grid grid-cols-2 gap-md lg:grid-cols-4">
            <x-ui.stat-card label="Buổi học trong ngày" :value="$dayStats['total']" icon="event" />
            <x-ui.stat-card label="Đã điểm danh" :value="$dayStats['done']" tone="success" icon="how_to_reg" />
            <x-ui.stat-card label="Chưa điểm danh" :value="$dayStats['missing']" tone="error" icon="pending_actions" />
            <x-ui.stat-card label="Hủy / nghỉ lễ" :value="$dayStats['cancelled']" tone="warning" icon="event_busy" />
        </div>

        <div class="grid grid-cols-1 gap-lg xl:grid-cols-4">
            <div class="xl:col-span-3">
                <x-ui.data-table min-width="920px">
                    <x-slot:header>
                        <h3 class="font-h3 text-h3 text-on-surface">Buổi học ngày {{ \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') }}</h3>
                        <span class="font-body-small text-body-small text-on-surface-variant">{{ $selectedBranch?->name ?? 'Tất cả chi nhánh' }}</span>
                    </x-slot:header>
                    <table>
                        <thead>
                            <tr>
                                <th>Tên lớp</th>
                                <th>Khung giờ</th>
                                <th>Phòng học</th>
                                <th>GV chính</th>
                                <th>GVNN</th>
                                <th>Trợ giảng</th>
                                <th class="text-center">Sĩ số</th>
                                <th class="text-center">Điểm danh</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($daySessions as $session)
                                @php
                                    $class = $session->classModel;
                                    $state = $dashboard->attendanceState($session, $today);
                                    // teacher_id cũ = GV chính ?? GVNN: không lặp tên GVNN ở cột GV chính.
                                    $mainTeacher = $session->teacher_id && $session->teacher_id !== $session->foreign_teacher_id ? $session->teacher : null;
                                    $window = $dashboard->attendanceWindow($session);
                                @endphp
                                <tr data-session-id="{{ $session->id }}">
                                    <td>
                                        <div class="font-semibold text-on-surface">{{ $class?->name ?? 'Lớp đã xóa' }}</div>
                                        <div class="flex flex-wrap items-center gap-xs font-caption text-caption text-on-surface-variant">
                                            <span class="font-code">{{ $class?->code }}</span>
                                            @if ($session->type === \App\Models\ClassSession::TYPE_MAKEUP)
                                                <x-ui.badge color="warning">Học bù</x-ui.badge>
                                            @elseif ($session->type === \App\Models\ClassSession::TYPE_SUPPORT)
                                                <x-ui.badge color="secondary">Phụ đạo</x-ui.badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap font-code">{{ $session->start_time?->format('H:i') }} - {{ $session->end_time?->format('H:i') }}</td>
                                    <td class="whitespace-nowrap">{{ $session->room ?: '—' }}</td>
                                    <td class="whitespace-nowrap">{{ $mainTeacher?->name ?? '—' }}</td>
                                    <td class="whitespace-nowrap">{{ $session->foreignTeacher?->name ?? '—' }}</td>
                                    <td class="whitespace-nowrap">{{ $session->assistant?->name ?? '—' }}</td>
                                    <td class="whitespace-nowrap text-center font-code">
                                        {{ $class ? ($seats[$class->id] ?? 0) : 0 }}/{{ $class?->max_capacity ?: '∞' }}
                                    </td>
                                    <td class="text-center">
                                        <x-ui.badge :color="$state['color']" pill>{{ $state['label'] }}@if ($state['key'] === 'done') ({{ $session->attendances_count }})@endif</x-ui.badge>
                                        @if ($session->holiday)
                                            <div class="mt-xs font-caption text-caption text-on-surface-variant">{{ $session->holiday->name }}</div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        @if ($session->status !== 'cancelled' && $class && $state['key'] !== 'done' && $window === 'before' && auth()->user()->can('attendance_student.record'))
                                            {{-- Mockup: chưa tới giờ học → nút Chấm công khóa kèm quy định cửa sổ 24h. --}}
                                            <div class="inline-flex flex-col items-end gap-xs">
                                                <x-ui.button size="sm" variant="secondary" icon="how_to_reg" disabled>Chấm công</x-ui.button>
                                                <span class="max-w-[180px] whitespace-normal text-right font-caption text-caption text-on-surface-variant">Chỉ được chấm công trong vòng 24h sau giờ học</span>
                                            </div>
                                        @elseif ($session->status !== 'cancelled' && $class && ! $session->date->gt($today) && auth()->user()->can('attendance_student.record'))
                                            <x-ui.button size="sm" :variant="$state['key'] === 'done' || $window === 'closed' ? 'secondary' : 'primary'" icon="how_to_reg"
                                                :href="route('teacher.attendance', ['classId' => $class->id, 'session' => $session->id, 'date' => $session->date->toDateString()])"
                                                :title="$window === 'closed' && $state['key'] !== 'done' ? 'Quá 24h sau giờ học — điểm danh bù, Học vụ sẽ rà soát' : null">
                                                {{ $state['key'] === 'done' ? 'Xem điểm danh' : ($window === 'closed' ? 'Điểm danh bù' : 'Chấm công') }}
                                            </x-ui.button>
                                        @elseif ($session->status === 'cancelled' && $session->makeupSession)
                                            <span class="font-caption text-caption text-on-surface-variant">Bù ngày {{ $session->makeupSession->date->format('d/m') }}</span>
                                        @else
                                            <span class="font-caption text-caption text-on-surface-variant">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9">
                                        <x-ui.empty-state icon="event_available" title="Không có buổi học nào"
                                            description="Không có buổi học nào {{ $attendanceFilter || $teacherFilter ? 'khớp bộ lọc' : 'trong ngày đã chọn' }}{{ $selectedBranch ? ' tại '.$selectedBranch->name : '' }}. Lịch học được sinh từ màn TKB." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    <x-slot:footer>
                        <div class="px-md py-sm font-body-small text-body-small text-on-surface-variant">
                            Hiển thị {{ $daySessions->count() }} buổi học của {{ $daySessions->pluck('class_id')->unique()->count() }} lớp học
                        </div>
                    </x-slot:footer>
                </x-ui.data-table>
            </div>

            {{-- Trợ giảng có ca trong ngày (từ buổi học thật) --}}
            <aside class="rounded-xl border border-outline-variant bg-surface-container-low p-md">
                <div class="mb-md flex items-center gap-sm border-b border-outline-variant pb-sm">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">support_agent</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Trợ giảng làm việc {{ \Illuminate\Support\Carbon::parse($date)->isToday() ? 'hôm nay' : 'ngày '.\Illuminate\Support\Carbon::parse($date)->format('d/m') }}</h3>
                </div>
                <ul class="space-y-sm">
                    @forelse ($assistantsToday as $duty)
                        <li class="flex items-center gap-sm rounded-lg border border-outline-variant bg-surface-container-lowest p-sm">
                            <x-ui.avatar :name="$duty['user']->name" size="sm" />
                            <div class="min-w-0">
                                <p class="truncate font-body-medium text-body-medium text-on-surface">{{ $duty['user']->name }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ $duty['from'] }} - {{ $duty['to'] }} · {{ $duty['sessions'] }} buổi</p>
                            </div>
                        </li>
                    @empty
                        <li class="py-md text-center font-body-small text-body-small text-on-surface-variant">Không có trợ giảng nào có ca trong ngày.</li>
                    @endforelse
                </ul>
                @can('work_task.assign')
                    <x-ui.button variant="secondary" class="mt-md w-full" :href="route('portal.ta-tasks', ['date' => $date])">Xem tất cả trợ giảng</x-ui.button>
                    <x-ui.button variant="ghost" icon="add" class="mt-xs w-full" :href="route('tasks.ta-assign')">Giao việc cho trợ giảng</x-ui.button>
                @endcan
            </aside>
        </div>
    @else
        {{-- ─── THEO TUẦN (ma trận khung giờ) ─── --}}
        <x-ui.filter-bar :search="null" :action="route('tasks.classes-dashboard')">
            <input type="hidden" name="tab" value="week">
            <x-ui.select name="branch_id" inline-label="Chi nhánh:" :options="$branches->pluck('name', 'id')" :value="$branchId" placeholder="Tất cả chi nhánh" />
            <x-ui.input type="week" name="week" inline-label="Chọn tuần:" :value="$week" />
        </x-ui.filter-bar>

        <div class="mb-md flex flex-wrap items-center gap-md font-caption text-caption text-on-surface-variant">
            <span class="flex items-center gap-xs"><span class="h-3 w-3 rounded border border-blue-200 bg-blue-50"></span> Chính khóa (màu theo khóa học)</span>
            <span class="flex items-center gap-xs"><span class="h-3 w-3 rounded border border-amber-300 bg-amber-50"></span> Học bù</span>
            <span class="flex items-center gap-xs"><span class="h-3 w-3 rounded border border-purple-300 bg-purple-50"></span> Phụ đạo</span>
            <span class="flex items-center gap-xs"><span class="h-3 w-3 rounded border border-outline-variant bg-surface-container-low"></span> Đã hủy / nghỉ lễ</span>
        </div>

        @if (empty($matrix['rows']))
            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                <x-ui.empty-state icon="calendar_view_week" title="Tuần này chưa có buổi học"
                    description="Tuần {{ $weekStart->format('d/m') }} - {{ $weekStart->addDays(6)->format('d/m/Y') }} chưa có buổi học nào{{ $selectedBranch ? ' tại '.$selectedBranch->name : '' }}." />
            </div>
        @else
            <x-ui.data-table min-width="980px">
                <table class="table-fixed">
                    <thead>
                        <tr>
                            <th class="w-[120px] text-center">Khung giờ</th>
                            @foreach ($matrix['days'] as $iso => $day)
                                <th class="text-center {{ $day->isToday() ? 'text-primary' : '' }}">
                                    {{ \App\Services\ClassDashboardService::WEEKDAYS[$iso] }}
                                    <span class="block font-caption text-caption normal-case text-on-surface-variant">{{ $day->format('d/m') }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="align-top">
                        @foreach ($matrix['rows'] as $slot => $cells)
                            <tr>
                                <td class="text-center font-code font-semibold">{{ $slot }}</td>
                                @foreach ($cells as $iso => $sessions)
                                    <td class="!px-xs">
                                        <div class="space-y-xs">
                                            @foreach ($sessions as $session)
                                                <a href="{{ route('tasks.classes-dashboard', ['tab' => 'day', 'date' => $session->date->toDateString(), 'branch_id' => $branchId]) }}"
                                                   class="block rounded-lg border p-xs transition hover:shadow-md {{ \App\Services\ClassDashboardService::tone($session) }}"
                                                   title="{{ $session->classModel?->name }} · {{ $session->room }}">
                                                    <p class="truncate text-[12px] font-semibold">{{ $session->classModel?->code ?? $session->classModel?->name }}</p>
                                                    <p class="truncate text-[11px] opacity-80">{{ $session->room ?: 'Chưa có phòng' }}</p>
                                                    @if ($session->status === 'cancelled')
                                                        <p class="text-[10px] font-semibold no-underline">{{ $session->holiday ? 'Nghỉ lễ' : 'Đã hủy' }}</p>
                                                    @elseif ($session->attendances_count > 0)
                                                        <p class="text-[10px] font-semibold">✓ Đã điểm danh</p>
                                                    @endif
                                                </a>
                                            @endforeach
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        @endif
    @endif
</x-app-layout>
