{{-- TKB — cấu hình lịch lặp của lớp (tạo mới hoặc sửa lịch đã có) + báo cáo phòng / nhân sự theo buổi học thật. --}}
@php
    $days = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];
    $defaults = [
        'academic_year' => now()->format('Y').' - '.now()->addYear()->format('Y'),
        'start_date' => now()->format('Y-m-d'),
        'end_date' => now()->addMonths(3)->format('Y-m-d'),
        'slot1_day' => 'Thứ 2', 'slot1_start' => '18:00', 'slot1_end' => '19:30',
        'slot2_day' => '', 'slot2_start' => '18:00', 'slot2_end' => '19:30',
    ];
    $initial = [];
    foreach ($defaults as $key => $default) {
        $fromClass = $selectedClassId ? ($scheduleData[$selectedClassId][$key] ?? null) : null;
        $initial[$key] = old($key, $fromClass ?? $default);
    }
    $initial['class_id'] = (string) ($selectedClassId ?? '');
    $canSchedule = auth()->user()->can('work_task.assign');
@endphp
<x-app-layout title="TKB — Quản lý lớp học">
    <x-ui.page-header title="TKB — Quản lý lớp học" description="Cấu hình thời khóa biểu lớp học và báo cáo phòng / nhân sự theo buổi học thực tế.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="download" :href="request()->fullUrlWithQuery(['export' => 1])">Xuất báo cáo phòng / nhân sự</x-ui.button>
            <x-ui.button variant="secondary" icon="dashboard" :href="route('tasks.classes-dashboard')">Dashboard lớp</x-ui.button>
            @can('class.create')
                <x-ui.button icon="add" :href="route('classes.create')">Tạo lớp mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert type="success" class="mb-lg" dismissible>{{ session('success') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
        {{-- ─── Cấu hình lịch lớp ─── --}}
        <section class="space-y-lg lg:col-span-7">
            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md"
                 x-data="scheduleForm(@js($scheduleData), @js($initial))">
                <div class="mb-md flex items-center gap-sm border-b border-surface-container pb-sm">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">calendar_month</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Cấu hình lịch lớp</h2>
                </div>

                @if ($canSchedule)
                    <form action="{{ route('tasks.schedule-config.update') }}" method="POST" class="space-y-md">
                        @csrf
                        <x-ui.field label="Lớp học" name="class_id" for="tkb_class_id" required
                            hint="Lớp đã có TKB có thể sửa: buổi đã qua, đã điểm danh hoặc đã chấm công được giữ nguyên, chỉ các buổi sắp tới được xếp lại.">
                            <select id="tkb_class_id" name="class_id" x-model="form.class_id" @change="pick()" required
                                    class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base focus:border-primary-container focus:ring-2 focus:ring-primary-container/20">
                                <option value="">-- Chọn lớp học --</option>
                                <optgroup label="Chưa có TKB">
                                    @foreach ($classes->filter(fn ($c) => ! $c->scheduleConfig && ! in_array($c->status, ['cancelled', 'completed'], true)) as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                                    @endforeach
                                </optgroup>
                                <optgroup label="Đã có TKB — sửa lịch">
                                    @foreach ($classes->filter(fn ($c) => $c->scheduleConfig && ! in_array($c->status, ['cancelled', 'completed'], true)) as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }}) · {{ $c->schedule_text }}</option>
                                    @endforeach
                                </optgroup>
                            </select>
                        </x-ui.field>

                        <template x-if="hasSchedule">
                            <x-ui.alert type="info">Lớp này đã có thời khóa biểu — lưu lại sẽ xếp lại các buổi <strong>từ hôm nay</strong>; buổi quá khứ và buổi đã có dữ liệu thực tế không bị thay đổi.</x-ui.alert>
                        </template>

                        <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                            <x-ui.field label="Năm học áp dụng" name="academic_year" for="tkb_year">
                                <input id="tkb_year" name="academic_year" x-model="form.academic_year" class="w-full rounded-lg border border-outline-variant px-md py-sm font-body-base text-body-base">
                            </x-ui.field>
                            <x-ui.field label="Khai giảng" name="start_date" for="tkb_start" required>
                                <input id="tkb_start" type="date" name="start_date" x-model="form.start_date" required class="w-full rounded-lg border border-outline-variant px-md py-sm font-body-base text-body-base">
                            </x-ui.field>
                            <x-ui.field label="Kết thúc" name="end_date" for="tkb_end" required>
                                <input id="tkb_end" type="date" name="end_date" x-model="form.end_date" required class="w-full rounded-lg border border-outline-variant px-md py-sm font-body-base text-body-base">
                            </x-ui.field>
                        </div>

                        <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                            @foreach ([1, 2] as $n)
                                <div class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-low p-md">
                                    <h3 class="flex items-center gap-xs font-body-medium text-body-medium font-semibold text-on-surface">
                                        <span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">{{ $n === 1 ? 'looks_one' : 'looks_two' }}</span>
                                        Slot {{ $n }}
                                    </h3>
                                    <x-ui.field label="Ngày trong tuần" name="slot{{ $n }}_day" for="tkb_slot{{ $n }}_day">
                                        <select id="tkb_slot{{ $n }}_day" name="slot{{ $n }}_day" x-model="form.slot{{ $n }}_day"
                                                class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base">
                                            @if ($n === 2)<option value="">-- Không học ca 2 --</option>@endif
                                            @foreach ($days as $day)
                                                <option value="{{ $day }}">{{ $day }}</option>
                                            @endforeach
                                        </select>
                                    </x-ui.field>
                                    <div class="grid grid-cols-2 gap-sm">
                                        <x-ui.field label="Giờ bắt đầu" name="slot{{ $n }}_start" for="tkb_slot{{ $n }}_start">
                                            <input id="tkb_slot{{ $n }}_start" type="time" name="slot{{ $n }}_start" x-model="form.slot{{ $n }}_start" class="w-full rounded-lg border border-outline-variant px-sm py-sm font-code">
                                        </x-ui.field>
                                        <x-ui.field label="Giờ kết thúc" name="slot{{ $n }}_end" for="tkb_slot{{ $n }}_end">
                                            <input id="tkb_slot{{ $n }}_end" type="time" name="slot{{ $n }}_end" x-model="form.slot{{ $n }}_end" class="w-full rounded-lg border border-outline-variant px-sm py-sm font-code">
                                        </x-ui.field>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-sm border-t border-surface-container pt-md">
                            <label class="mr-auto inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant" x-show="status === 'upcoming'">
                                <input type="checkbox" name="activate" value="1" class="rounded border-outline-variant">
                                Kích hoạt lớp (đang "Sắp khai giảng")
                            </label>
                            <x-ui.button variant="secondary" x-on:click="reset()">Hủy thay đổi</x-ui.button>
                            <x-ui.button type="submit" x-text="hasSchedule ? 'Cập nhật lịch' : 'Tạo lịch'">Cập nhật lịch</x-ui.button>
                        </div>
                    </form>
                @else
                    <p class="font-body-small text-body-small text-on-surface-variant">Bạn chỉ có quyền xem thời khóa biểu. Liên hệ Học vụ để thay đổi lịch lớp.</p>
                @endif
            </div>

            {{-- Buổi bị hủy do nghỉ lễ thêm sau --}}
            @if ($holidaySessions->isNotEmpty())
                <x-ui.data-table>
                    <x-slot:header>
                        <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                            <span class="material-symbols-outlined text-amber-600" aria-hidden="true">event_busy</span>
                            Buổi học bị hủy do ngày nghỉ
                        </h3>
                        <span class="font-body-small text-body-small text-on-surface-variant">{{ $holidaySessions->count() }} buổi sắp tới</span>
                    </x-slot:header>
                    <table>
                        <thead><tr><th>Lớp</th><th>Buổi bị hủy</th><th>Ngày nghỉ</th><th>Học bù</th></tr></thead>
                        <tbody>
                            @foreach ($holidaySessions as $s)
                                <tr>
                                    <td>{{ $s->classModel?->name }} <span class="block font-code text-caption text-on-surface-variant">{{ $s->classModel?->code }}</span></td>
                                    <td class="whitespace-nowrap font-code">{{ $s->date->format('d/m/Y') }} {{ $s->start_time?->format('H:i') }}</td>
                                    <td>{{ $s->holiday?->name }}</td>
                                    <td class="whitespace-nowrap">
                                        @if ($s->makeupSession)
                                            <x-ui.badge color="warning">{{ $s->makeupSession->date->format('d/m/Y') }} {{ $s->makeupSession->start_time?->format('H:i') }}</x-ui.badge>
                                        @else
                                            <x-ui.badge color="error">Chưa xếp bù</x-ui.badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif

            {{-- Danh sách lớp --}}
            <div x-data="{ q: '' }">
                <x-ui.data-table>
                    <x-slot:header>
                        <h3 class="font-h3 text-h3 text-on-surface">Danh sách lớp hiện tại</h3>
                        <input type="search" x-model="q" placeholder="Tìm lớp..." aria-label="Tìm lớp"
                               class="w-48 rounded-lg border border-outline-variant px-md py-xs font-body-small text-body-small">
                    </x-slot:header>
                    <table>
                        <thead><tr><th>Tên lớp</th><th>Giảng viên</th><th>Trạng thái</th><th class="text-right">Thao tác</th></tr></thead>
                        <tbody>
                            @forelse ($classes as $c)
                                <tr x-show="q === '' || @js(mb_strtolower($c->name.' '.$c->code)).includes(q.toLowerCase())">
                                    <td>
                                        <span class="font-semibold">{{ $c->name }}</span>
                                        <span class="block font-code text-caption text-on-surface-variant">{{ $c->code }} · {{ $c->schedule_text ?: 'Chưa có TKB' }}</span>
                                    </td>
                                    <td>{{ $c->teacher?->name ?? $c->foreignTeacher?->name ?? 'Chưa phân công' }}</td>
                                    <td>
                                        @switch($c->status)
                                            @case('active') <x-ui.badge color="success">Đang học</x-ui.badge> @break
                                            @case('upcoming') <x-ui.badge color="info">Sắp khai giảng</x-ui.badge> @break
                                            @case('pending_schedule') <x-ui.badge color="warning">Chưa xếp lịch</x-ui.badge> @break
                                            @case('cancelled') <x-ui.badge color="error">Đã hủy</x-ui.badge> @break
                                            @default <x-ui.badge>Đã kết thúc</x-ui.badge>
                                        @endswitch
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        @if ($canSchedule && ! in_array($c->status, ['cancelled', 'completed'], true))
                                            <x-ui.button size="sm" variant="ghost" icon="edit_calendar" :href="route('tasks.schedule-config', ['class_id' => $c->id])">{{ $c->scheduleConfig ? 'Sửa lịch' : 'Xếp lịch' }}</x-ui.button>
                                        @endif
                                        @if (in_array($c->status, ['active', 'completed'], true) && auth()->user()->can('class.update'))
                                            <form action="{{ route('tasks.schedule-config.update') }}" method="POST" class="inline">
                                                @csrf
                                                <input type="hidden" name="toggle_class_id" value="{{ $c->id }}">
                                                <x-ui.button type="submit" size="sm" :variant="$c->status === 'active' ? 'danger-text' : 'ghost'">{{ $c->status === 'active' ? 'Kết thúc lớp' : 'Mở lại lớp' }}</x-ui.button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><x-ui.empty-state icon="school" title="Chưa có lớp nào" description="Bạn chưa phụ trách lớp học nào." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-ui.data-table>
            </div>
        </section>

        {{-- ─── Báo cáo phòng / nhân sự ─── --}}
        <section class="lg:col-span-5">
            <div class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                <div class="flex items-center gap-sm border-b border-surface-container pb-sm">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">groups</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Báo cáo phòng / nhân sự</h2>
                </div>

                <form method="GET" action="{{ route('tasks.schedule-config') }}" class="grid grid-cols-1 gap-sm sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                    <x-ui.select name="report_branch_id" label="Chi nhánh" :options="$branches->pluck('name', 'id')" :value="$reportBranchId" />
                    <x-ui.date name="report_date" label="Từ ngày (7 ngày)" :value="$reportStart->toDateString()" />
                    <x-ui.button type="submit" variant="secondary" icon="filter_list">Xem</x-ui.button>
                </form>

                @if ($classCountChange['previous'] !== $classCountChange['current'])
                    <x-ui.alert type="warning">
                        Số lớp có lịch đã thay đổi <strong>{{ $classCountChange['previous'] }} → {{ $classCountChange['current'] }}</strong> so với 7 ngày trước, kiểm tra lại số nhân sự trợ giảng cần bố trí.
                    </x-ui.alert>
                @endif

                <form action="{{ route('tasks.hr-demand.save') }}" method="POST" class="space-y-md">
                    @csrf
                    <input type="hidden" name="branch_id" value="{{ $reportBranchId }}">
                    <x-ui.data-table>
                        <table>
                            <thead>
                                <tr>
                                    <th>Ngày</th>
                                    <th class="text-center">Số ca</th>
                                    <th class="text-center">Phòng</th>
                                    <th class="text-center">TA có ca</th>
                                    <th class="text-center">Nhân sự cần</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report as $row)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            <span class="font-semibold">{{ $row['label'] }}</span>
                                            <span class="block font-code text-caption text-on-surface-variant">{{ $row['date']->format('d/m') }}</span>
                                        </td>
                                        <td class="text-center font-code">{{ $row['shifts'] }}</td>
                                        <td class="text-center font-code">{{ $row['rooms'] }}</td>
                                        <td class="text-center font-code">{{ $row['assistants'] }}</td>
                                        <td class="text-center">
                                            <input type="number" name="demands[{{ $row['date']->toDateString() }}]" value="{{ $row['staff_needed'] }}" min="0" max="50"
                                                   @disabled(! $canSchedule) aria-label="Nhân sự cần {{ $row['date']->format('d/m') }}"
                                                   class="w-16 rounded-lg border px-xs py-xs text-center font-code {{ $row['saved'] ? 'border-outline-variant' : 'border-dashed border-outline-variant text-on-surface-variant' }}">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.data-table>
                    <p class="font-caption text-caption text-on-surface-variant">Số ca, phòng và TA tính từ buổi học thực tế của chi nhánh. Ô viền nét đứt là gợi ý (chưa lưu).</p>
                    @if ($canSchedule)
                        <x-ui.button type="submit" variant="secondary" icon="save" class="w-full">Lưu báo cáo nhân sự</x-ui.button>
                    @endif
                </form>
            </div>
        </section>
    </div>

    @push('scripts')
        <script>
            function scheduleForm(schedules, initial) {
                return {
                    schedules,
                    initial,
                    form: { ...initial },
                    get hasSchedule() { return !!(this.form.class_id && this.schedules[this.form.class_id]?.slot1_day); },
                    get status() { return this.schedules[this.form.class_id]?.status ?? null; },
                    pick() {
                        const data = this.schedules[this.form.class_id];
                        if (!data || !data.slot1_day) return;
                        for (const key of Object.keys(this.form)) {
                            if (key !== 'class_id' && data[key] !== undefined) this.form[key] = data[key] ?? (key === 'slot2_day' ? '' : this.form[key]);
                        }
                    },
                    reset() { this.form = { ...this.initial }; },
                };
            }
        </script>
    @endpush
</x-app-layout>
