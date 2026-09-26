{{-- Nhật ký vận hành (mockup epic-5/nhat-ky-van-hanh): lọc module / người / ngày, bảng + panel "Chi tiết đối chiếu"
     (so sánh trước / sau, chi tiết kỹ thuật, Hoàn tác cho Admin), Xuất Excel theo bộ lọc. --}}
@php
    $groups = \App\Http\Controllers\ActivityLogController::MODULE_GROUPS;
    $activeGroup = request('module');
    $filterKeys = ['search', 'log_name', 'causer_id', 'date_from', 'date_to', 'event', 'module'];
    $eventStyle = fn (?string $event) => match ($event) {
        'created' => ['add_circle', 'Thêm', 'text-tertiary'],
        'updated' => ['edit', 'Sửa', 'text-secondary'],
        'deleted' => ['delete', 'Xóa', 'text-error'],
        'restored' => ['restore', 'Khôi phục', 'text-tertiary'],
        default => ['bolt', \App\Support\Audit::eventLabel($event), 'text-on-surface-variant'],
    };
@endphp
<x-app-layout title="Nhật ký vận hành">
    <div x-data="{ openId: null }" x-on:keydown.escape.window="openId = null">
        <x-ui.page-header title="Nhật ký vận hành" description="Theo dõi và đối soát các thay đổi dữ liệu hệ thống trong thời gian thực. Mỗi thao tác ghi một dòng kèm dữ liệu trước / sau.">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="download" :href="route('activity-logs.export', request()->only($filterKeys))">Xuất Excel</x-ui.button>
                <x-ui.button variant="secondary" icon="refresh" :href="route('activity-logs.index', request()->only($filterKeys))">Làm mới</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if ($errors->has('undo'))
            <x-ui.alert type="error" class="mb-md">{{ $errors->first('undo') }}</x-ui.alert>
        @endif
        @if (session('status'))
            <x-ui.alert type="success" class="mb-md" dismissible>{{ session('status') }}</x-ui.alert>
        @endif

        <div class="mb-md grid grid-cols-1 gap-md sm:grid-cols-3">
            <x-ui.stat-card label="Tổng số thao tác đã ghi" :value="number_format($totalLogsCount)" icon="dataset" />
            <x-ui.stat-card label="Thao tác trong ngày" :value="number_format($totalLogsToday)" icon="today" tone="success" />
            <x-ui.stat-card label="Nhân viên hoạt động hôm nay" :value="number_format($activeUsersToday).' người'" icon="group" tone="primary" />
        </div>

        <x-ui.data-table min-width="900px">
            <x-slot:header>
                <form method="GET" action="{{ route('activity-logs.index') }}" class="flex w-full flex-col gap-sm">
                    <div class="flex flex-wrap items-center gap-xs">
                        <a href="{{ route('activity-logs.index', request()->except(['module', 'page'])) }}"
                           class="rounded-full border px-sm py-[2px] font-body-small text-body-small {{ ! $activeGroup ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low' }}">Tất cả</a>
                        @foreach ($groups as $key => $group)
                            <a href="{{ route('activity-logs.index', array_merge(request()->except('page'), ['module' => $key])) }}"
                               class="rounded-full border px-sm py-[2px] font-body-small text-body-small {{ $activeGroup === $key ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low' }}">{{ $group['label'] }}</a>
                        @endforeach
                        @if ($activeGroup)
                            <input type="hidden" name="module" value="{{ $activeGroup }}">
                        @endif
                    </div>
                    <div class="flex flex-wrap items-end gap-sm">
                        <div class="min-w-[240px] flex-1"><x-ui.input name="search" icon="search" :value="request('search')" placeholder="Tìm tên người thực hiện, mã bản ghi..." aria-label="Tìm kiếm" /></div>
                        <x-ui.select name="log_name" :options="collect($allLogNames)->mapWithKeys(fn ($n) => [$n => $n])" placeholder="Mọi phân hệ" aria-label="Phân hệ" />
                        <x-ui.select name="causer_id" :options="$users->pluck('name', 'id')" placeholder="Mọi người thực hiện" aria-label="Người thực hiện" />
                        <x-ui.select name="event" :options="$events->mapWithKeys(fn ($e) => [$e => \App\Support\Audit::eventLabel($e)])" placeholder="Mọi loại" aria-label="Loại thao tác" />
                        <div class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">calendar_today</span>
                            <x-ui.date name="date_from" :value="request('date_from')" aria-label="Từ ngày" />
                            đến
                            <x-ui.date name="date_to" :value="request('date_to')" aria-label="Đến ngày" />
                        </div>
                        <x-ui.button type="submit" icon="filter_list">Lọc</x-ui.button>
                        @if (request()->hasAny($filterKeys))
                            <x-ui.button variant="ghost" icon="close" :href="route('activity-logs.index')">Xóa lọc</x-ui.button>
                        @endif
                    </div>
                    <x-ui.errors :messages="array_merge($errors->get('date_from'), $errors->get('date_to'))" />
                </form>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Người thực hiện</th>
                        <th>Thời điểm</th>
                        <th>Module</th>
                        <th>Loại</th>
                        <th>Hành động</th>
                        <th class="text-right"><span class="sr-only">Chi tiết</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        @php
                            [$evIcon, $evLabel, $evTone] = $eventStyle($log->event);
                            $causerRole = $log->causer?->roles?->first()?->name;
                        @endphp
                        <tr class="cursor-pointer" x-on:click="openId = {{ $log->id }}" :class="openId === {{ $log->id }} ? 'bg-primary-fixed/40' : ''">
                            <td>
                                <div class="flex items-center gap-sm">
                                    <x-ui.avatar :name="$log->causer?->name ?? 'Hệ thống'" size="sm" />
                                    <div class="min-w-0">
                                        <div class="font-semibold text-on-surface">{{ $log->causer?->name ?? 'Hệ thống tự động' }}</div>
                                        <div class="font-caption text-caption text-on-surface-variant">
                                            {{ $causerRole ? \App\Helpers\AclHelper::shortRoleLabel($causerRole) : 'Hệ thống' }}@if ($log->causer_id) <span class="font-code">#{{ $log->causer_id }}</span>@endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap font-code text-body-small">{{ $log->created_at->format('H:i:s') }}<span class="block text-caption text-on-surface-variant">{{ $log->created_at->format('d/m/Y') }}</span></td>
                            <td><x-ui.badge color="neutral" :dot="false">{{ $log->log_name ?: 'Hệ thống' }}</x-ui.badge></td>
                            <td class="whitespace-nowrap"><span class="inline-flex items-center gap-xs font-body-small text-body-small {{ $evTone }}"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ $evIcon }}</span>{{ $evLabel }}</span></td>
                            <td class="max-w-[380px]">
                                <div class="text-on-surface">{{ $log->description }}</div>
                                @if ($log->subject_type)
                                    <div class="font-code text-caption text-on-surface-variant">{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</div>
                                @endif
                            </td>
                            <td class="text-right"><x-ui.button variant="ghost" size="sm" icon="visibility" aria-label="Xem chi tiết đối chiếu" x-on:click.stop="openId = {{ $log->id }}" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state icon="history" title="Không tìm thấy nhật ký vận hành nào phù hợp với bộ lọc" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>
                <x-ui.pagination :paginator="$logs" unit="nhật ký" />
            </x-slot:footer>
        </x-ui.data-table>

        {{-- Panel "Chi tiết đối chiếu" (1 panel / dòng, render sẵn phía server) --}}
        @foreach ($logs as $log)
            @php
                $diffRows = \App\Http\Controllers\ActivityLogController::diff($log);
                [, $undoError] = [null, null];
                if ($canUndo && $log->event === 'updated' && ! empty($diffRows)) {
                    [, , $undoError] = \App\Http\Controllers\ActivityLogController::undoPlan($log);
                    $undoable = ! $undoError;
                } else {
                    $undoable = false;
                }
            @endphp
            <div x-show="openId === {{ $log->id }}" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Chi tiết đối chiếu">
                <div class="absolute inset-0 bg-on-surface/40" x-on:click="openId = null"></div>
                <aside class="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col bg-surface-container-lowest shadow-xl">
                    <header class="flex items-start justify-between gap-sm border-b border-surface-container px-md py-sm">
                        <div>
                            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-secondary" aria-hidden="true">info</span>Chi tiết đối chiếu</h2>
                            <p class="font-code text-caption text-on-surface-variant">Bản ghi {{ $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '#'.$log->id }}</p>
                        </div>
                        <x-ui.button variant="ghost" icon="close" aria-label="Đóng" x-on:click="openId = null" />
                    </header>
                    <div class="flex-1 space-y-md overflow-y-auto p-md font-body-small text-body-small">
                        <dl class="grid grid-cols-2 gap-sm rounded-lg bg-surface-container-low p-sm">
                            <div><dt class="text-on-surface-variant">Module</dt><dd class="font-semibold">{{ $log->log_name ?: 'Hệ thống' }}</dd></div>
                            <div><dt class="text-on-surface-variant">Thao tác bởi</dt><dd class="font-semibold">{{ $log->causer?->name ?? 'Hệ thống tự động' }}</dd></div>
                            <div><dt class="text-on-surface-variant">Thời điểm</dt><dd class="font-code">{{ $log->created_at->format('H:i:s d/m/Y') }}</dd></div>
                            <div><dt class="text-on-surface-variant">Loại</dt><dd>{{ \App\Support\Audit::eventLabel($log->event) }}</dd></div>
                            <div class="col-span-2"><dt class="text-on-surface-variant">Nội dung</dt><dd>{{ $log->description }}</dd></div>
                        </dl>

                        <section class="space-y-xs">
                            <h3 class="font-label text-label uppercase text-on-surface-variant">So sánh trước / sau</h3>
                            @if (empty($diffRows))
                                <p class="italic text-on-surface-variant">Thao tác không có dữ liệu trước / sau (chỉ ghi nhận hành động).</p>
                            @else
                                <div class="overflow-hidden rounded-lg border border-outline-variant">
                                    <div class="grid grid-cols-2 bg-surface-container-low font-label text-label uppercase text-on-surface-variant">
                                        <span class="px-sm py-xs">Dữ liệu trước</span><span class="border-l border-outline-variant px-sm py-xs">Dữ liệu sau</span>
                                    </div>
                                    @foreach ($diffRows as $field => $row)
                                        @php $changed = \App\Http\Controllers\ActivityLogController::stringify($row['old']) !== \App\Http\Controllers\ActivityLogController::stringify($row['new']); @endphp
                                        <div class="grid grid-cols-2 border-t border-outline-variant font-code text-caption">
                                            <span class="break-all px-sm py-xs {{ $changed ? 'bg-error-container/30 text-error' : '' }}">{{ $field }}: "{{ \App\Http\Controllers\ActivityLogController::stringify($row['old']) }}"</span>
                                            <span class="break-all border-l border-outline-variant px-sm py-xs {{ $changed ? 'bg-tertiary-fixed/30 text-tertiary' : '' }}">{{ $field }}: "{{ \App\Http\Controllers\ActivityLogController::stringify($row['new']) }}"</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </section>

                        <section class="space-y-xs rounded-lg bg-inverse-surface/95 p-sm font-code text-caption text-inverse-on-surface">
                            <h3 class="flex items-center gap-xs font-label text-label uppercase"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">terminal</span>Chi tiết kỹ thuật</h3>
                            <p>IP Address: {{ $log->properties['ip'] ?? '—' }}</p>
                            <p class="break-all">User-Agent: {{ $log->properties['user_agent'] ?? '—' }}</p>
                            <p class="break-all">Transaction ID: {{ $log->batch_uuid ?? '—' }}</p>
                            @if (! empty($log->properties['url'] ?? null))
                                <p class="break-all">URL: {{ $log->properties['method'] ?? '' }} {{ $log->properties['url'] }}</p>
                            @endif
                        </section>
                        @if ($canUndo && $log->event === 'updated' && ! empty($diffRows) && ! $undoable)
                            <p class="font-caption text-caption italic text-on-surface-variant">Không hoàn tác được: {{ $undoError }}</p>
                        @endif
                    </div>
                    <footer class="flex gap-sm border-t border-surface-container bg-surface-container-low p-md">
                        @if ($undoable)
                            <form method="POST" action="{{ route('activity-logs.undo', $log->id) }}" class="flex-1" onsubmit="return confirm('Khôi phục các giá trị trước của thao tác này?');">
                                @csrf
                                <x-ui.button type="submit" variant="danger" icon="undo" class="w-full">Hoàn tác</x-ui.button>
                            </form>
                        @endif
                        <x-ui.button variant="secondary" class="flex-1" x-on:click="openId = null">Đóng chi tiết</x-ui.button>
                    </footer>
                </aside>
            </div>
        @endforeach
    </div>
</x-app-layout>
