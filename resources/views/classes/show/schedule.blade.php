{{-- Trang lớp · Lịch & buổi học: lịch cố định + toàn bộ buổi học của lớp; cấu hình lịch mở màn TKB lọc sẵn lớp này.
     GVNN không cố định: gán / đổi GVNN theo từng buổi sắp tới (modal "Gán GVNN").
     Trợ giảng không cố định: cột Trợ giảng lấy từ việc giao cho trợ giảng (theo ca) gắn lớp trong ngày. --}}
@php
    $today = today();
    $upcoming = $sessions->filter(fn ($s) => $s->date->gte($today) && $s->status !== 'cancelled')->count();
    $foreignEditableIds = $foreignEditableIds ?? [];
    $canAssignForeign = $canManage && ! empty($foreignEditableIds);
    $oldSessionIds = array_map('intval', (array) old('session_ids', []));
@endphp

<div class="space-y-4">
    <div class="flex flex-col gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-base font-bold text-on-surface">Lịch học</h2>
            <p class="text-xs text-on-surface-variant">{{ $class->schedule_text ?: 'Lớp chưa có lịch học cố định.' }}
                · {{ $sessions->where('status', '!=', 'cancelled')->count() }} buổi, còn {{ $upcoming }} buổi sắp tới</p>
        </div>
        @if ($canManage)
            <div class="flex flex-wrap items-center gap-sm">
            @if ($canAssignForeign)
                <x-ui.button variant="secondary" icon="translate" x-on:click="$dispatch('assign-foreign', { ids: [] })">Gán GVNN</x-ui.button>
            @endif
            @can('work_task.assign')
                <x-ui.button variant="ghost" icon="support_agent" :href="route('tasks.ta-assign')" modal="4xl">Giao việc trợ giảng</x-ui.button>
            @endcan
            @can('work_task.view')
                <x-ui.button :variant="$class->status === 'pending_schedule' ? 'primary' : 'secondary'" icon="edit_calendar"
                             :href="route('tasks.schedule-config', ['class_id' => $class->id])">{{ $class->scheduleConfig ? 'Sửa lịch' : 'Cấu hình lịch' }}</x-ui.button>
            @endcan
            </div>
        @endif
    </div>

    <x-ui.data-table min-width="760px">
        <table class="text-xs">
            <thead>
                <tr>
                    <th>Ngày</th>
                    <th>Giờ</th>
                    <th>Phòng</th>
                    <th>Giáo viên</th>
                    <th>GVNN</th>
                    <th>Trợ giảng</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sessions as $s)
                    @php $isToday = $s->date->isSameDay($today); @endphp
                    <tr class="{{ $isToday ? 'bg-primary-container/5' : '' }} {{ $s->status === 'cancelled' ? 'text-on-surface-subtle' : '' }}">
                        <td class="font-code font-bold">{{ $s->date->format('d/m/Y') }}@if ($isToday) <span class="ml-1 text-xs font-semibold text-primary">Hôm nay</span>@endif</td>
                        <td class="font-code">{{ $s->start_time?->format('H:i') }}–{{ $s->end_time?->format('H:i') }}</td>
                        <td>{{ $s->room ?: ($class->room ?: '—') }}</td>
                        {{-- Lớp không có GV chính: teacher_id của buổi chính là GVNN → không lặp tên ở cột Giáo viên. --}}
                        <td>{{ ($s->teacher_id && (int) $s->teacher_id !== (int) $s->foreign_teacher_id ? $s->teacher?->name : null) ?? '—' }}</td>
                        <td>
                            <div class="flex items-center gap-xs">
                                <span>{{ $s->foreignTeacher?->name ?? '—' }}</span>
                                @if ($canAssignForeign && in_array($s->id, $foreignEditableIds, true))
                                    <button type="button" class="text-xs font-semibold text-primary hover:underline"
                                            x-on:click="$dispatch('assign-foreign', { ids: [{{ $s->id }}], current: @js($s->foreign_teacher_id ? (string) $s->foreign_teacher_id : '') })"
                                            aria-label="Đổi GVNN buổi {{ $s->date->format('d/m/Y') }}">{{ $s->foreign_teacher_id ? 'Đổi' : 'Gán' }}</button>
                                @endif
                            </div>
                        </td>
                        <td>{{ collect($assistantsByDate[$s->date->toDateString()] ?? [])->push($s->assistant?->name)->filter()->unique()->implode(', ') ?: '—' }}</td>
                        <td>
                            @if ($s->status === 'cancelled')
                                <x-ui.badge color="neutral">{{ $s->holiday ? 'Nghỉ lễ' : 'Đã hủy' }}</x-ui.badge>
                            @elseif ($s->date->lt($today))
                                <x-ui.badge color="success">Đã diễn ra</x-ui.badge>
                            @else
                                <x-ui.badge color="info">Sắp diễn ra</x-ui.badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7"><x-ui.empty-state icon="event_busy" title="Lớp chưa có buổi học nào. Cấu hình lịch để sinh buổi học." /></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.data-table>
</div>

@if ($canAssignForeign)
    @php
        $editableSessions = $sessions->filter(fn ($s) => in_array($s->id, $foreignEditableIds, true))->values();
        $weekdays = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
    @endphp
    <div x-data="{ ids: @js($oldSessionIds) }"
         x-on:assign-foreign.window="ids = $event.detail.ids.length ? $event.detail.ids : @js($editableSessions->pluck('id')->all()); (document.getElementById('assign-foreign-gvnn') || {}).value = $event.detail.current ?? ''; $dispatch('open-modal', 'assign-foreign')">
        <x-ui.modal name="assign-foreign" :title="'Gán GVNN cho lớp '.$class->code" max-width="2xl" :show="old('_modal') === 'assign-foreign'">
            <form id="assign-foreign-form" method="POST" action="{{ route('classes.foreign-teacher', $class->id) }}" class="space-y-md">
                @csrf
                <input type="hidden" name="_modal" value="assign-foreign">
                <p class="text-xs text-on-surface-variant">GVNN không cố định theo lớp: chọn GVNN rồi tick các buổi áp dụng. Chỉ đổi được buổi sắp tới chưa điểm danh; giáo viên chính giữ nguyên.</p>
                <x-ui.select name="giao_vien_nn" label="Giáo viên nước ngoài" placeholder="-- Chọn GVNN --" required id="assign-foreign-gvnn"
                             :options="['none' => 'Không có GVNN (gỡ khỏi các buổi đã chọn)'] + $foreignTeacherOptions->mapWithKeys(fn ($t) => [$t->id => $t->name])->all()" />
                <div>
                    <div class="mb-xs flex items-center justify-between">
                        <span class="text-xs font-semibold text-on-surface">Buổi áp dụng <span class="font-normal text-on-surface-variant" x-text="'(' + ids.length + ' buổi)'"></span></span>
                        <span class="flex gap-md text-xs font-semibold">
                            <button type="button" class="text-primary hover:underline" x-on:click="ids = @js($editableSessions->pluck('id')->all())">Chọn tất cả</button>
                            <button type="button" class="text-on-surface-variant hover:underline" x-on:click="ids = []">Bỏ chọn</button>
                        </span>
                    </div>
                    <div class="max-h-72 space-y-1 overflow-y-auto rounded-lg border border-outline-variant p-sm">
                        @foreach ($editableSessions as $s)
                            <label class="flex cursor-pointer items-center gap-sm rounded px-xs py-1 text-xs hover:bg-surface-container-low">
                                <input type="checkbox" name="session_ids[]" value="{{ $s->id }}" x-model.number="ids"
                                       class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40">
                                <span class="font-code font-bold">{{ $weekdays[$s->date->dayOfWeek] }} {{ $s->date->format('d/m/Y') }}</span>
                                <span class="font-code text-on-surface-variant">{{ $s->start_time?->format('H:i') }}–{{ $s->end_time?->format('H:i') }}</span>
                                <span class="ml-auto text-on-surface-variant">{{ $s->foreignTeacher?->name ? 'GVNN: '.$s->foreignTeacher->name : 'Chưa có GVNN' }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('session_ids')<p class="mt-xs text-xs text-error">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-center gap-sm text-xs">
                    <input type="checkbox" name="set_default" value="1" @checked(old('set_default')) class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40">
                    Đặt làm GVNN mặc định của lớp (buổi sinh thêm sau này sẽ nhận GVNN này)
                </label>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'assign-foreign')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="assign-foreign-form" icon="save">Lưu GVNN</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
@endif
