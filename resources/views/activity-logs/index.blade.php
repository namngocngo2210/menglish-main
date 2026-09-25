<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-indigo-600">history_edu</span>
                    Nhật Ký Vận Hành Toàn Hệ Thống
                </h1>
                <p class="text-xs text-gray-500">Mỗi thao tác được ghi một lần, kèm dữ liệu trước/sau khi sửa. Lọc theo ngày, xuất Excel; Admin có thể hoàn tác thao tác sửa đơn giản.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-3 py-1.5 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-700 font-mono font-bold text-xs flex items-center gap-1.5 shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>Hôm nay: {{ number_format($totalLogsToday) }} thao tác</span>
                </span>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-2xs flex items-center justify-between">
                <div>
                    <div class="text-[11px] text-gray-400 font-bold uppercase tracking-wider">Tổng số thao tác đã ghi</div>
                    <div class="text-xl font-black text-gray-900 mt-0.5">{{ number_format($totalLogsCount) }}</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <span class="material-symbols-outlined">dataset</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-2xs flex items-center justify-between">
                <div>
                    <div class="text-[11px] text-gray-400 font-bold uppercase tracking-wider">Thao tác trong ngày</div>
                    <div class="text-xl font-black text-emerald-600 mt-0.5">{{ number_format($totalLogsToday) }}</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined">today</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-2xs flex items-center justify-between">
                <div>
                    <div class="text-[11px] text-gray-400 font-bold uppercase tracking-wider">Nhân viên hoạt động hôm nay</div>
                    <div class="text-xl font-black text-orange-600 mt-0.5">{{ number_format($activeUsersToday) }} người</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 flex items-center justify-center">
                    <span class="material-symbols-outlined">group</span>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-4 space-y-3">
            @if ($errors->has('undo'))
                <div class="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold">{{ $errors->first('undo') }}</div>
            @endif
            @if (session('status'))
                <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold">{{ session('status') }}</div>
            @endif
            <form method="GET" action="{{ route('activity-logs.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <!-- Search input -->
                <div class="lg:col-span-2">
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Tìm kiếm từ khóa</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-2.5 text-gray-400 text-[18px]">search</span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nội dung thao tác, tên nhân viên, email..."
                               class="w-full pl-9 pr-3 py-2 rounded-xl border border-gray-200 text-xs focus:ring-indigo-500 focus:border-indigo-500" />
                    </div>
                </div>

                <!-- Module Select -->
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Phân hệ / Module</label>
                    <select name="log_name" class="w-full py-2 px-3 rounded-xl border border-gray-200 text-xs focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">-- Tất cả phân hệ --</option>
                        @foreach ($allLogNames as $name)
                            <option value="{{ $name }}" @selected(request('log_name') === $name)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- User Select -->
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Người thực hiện</label>
                    <select name="causer_id" class="w-full py-2 px-3 rounded-xl border border-gray-200 text-xs focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">-- Tất cả nhân sự --</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected(request('causer_id') == $u->id)>{{ $u->name }} ({{ $u->email }})</option>
                        @endforeach
                    </select>
                </div>

                <!-- Event Select -->
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Hành động</label>
                    <select name="event" class="w-full py-2 px-3 rounded-xl border border-gray-200 text-xs focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">-- Tất cả hành động --</option>
                        @foreach ($events as $ev)
                            <option value="{{ $ev }}" @selected(request('event') === $ev)>{{ \App\Support\Audit::eventLabel($ev) }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Date range -->
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Từ ngày</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="w-full py-2 px-3 rounded-xl border border-gray-200 text-xs focus:ring-indigo-500 focus:border-indigo-500" />
                    <x-input-error :messages="$errors->get('date_from')" class="mt-1 text-xs" />
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Đến ngày</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="w-full py-2 px-3 rounded-xl border border-gray-200 text-xs focus:ring-indigo-500 focus:border-indigo-500" />
                    <x-input-error :messages="$errors->get('date_to')" class="mt-1 text-xs" />
                </div>

                <!-- Actions / Buttons -->
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 py-2 px-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-2xs transition flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">filter_list</span>
                        <span>Lọc</span>
                    </button>
                    <a href="{{ route('activity-logs.export', request()->only(['search', 'log_name', 'causer_id', 'date_from', 'date_to', 'event'])) }}"
                       class="flex-1 py-2 px-3.5 rounded-xl border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-xs font-bold transition flex items-center justify-center gap-1" title="Xuất Excel (CSV) theo bộ lọc hiện tại">
                        <span class="material-symbols-outlined text-[16px]">download</span>
                        <span>Xuất Excel</span>
                    </a>
                    @if (request()->hasAny(['search', 'log_name', 'causer_id', 'date_from', 'date_to', 'event']))
                        <a href="{{ route('activity-logs.index') }}" class="p-2 rounded-xl border border-gray-200 hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition" title="Xóa bộ lọc">
                            <span class="material-symbols-outlined text-[16px]">refresh</span>
                        </a>
                    @endif
                </div>
            </form>
        </div>

        <!-- Table of Activity Logs -->
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-2xs">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between bg-white">
                <span class="font-bold text-xs text-gray-900 uppercase tracking-wider">Danh Sách Lịch Sử Thao Tác</span>
                <span class="text-xs text-gray-400">Hiển thị dữ liệu thời gian thực</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Người thực hiện</th>
                            <th class="py-3 px-4">Thời điểm &amp; IP</th>
                            <th class="py-3 px-4">Phân hệ</th>
                            <th class="py-3 px-4">Hành động</th>
                            <th class="py-3 px-4">Chi tiết thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($logs as $log)
                            <tr class="hover:bg-slate-50/80 transition align-top">
                                <!-- User -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 font-bold text-xs flex items-center justify-center font-mono">
                                            {{ strtoupper(substr($log->causer?->name ?? 'HT', 0, 2)) }}
                                        </div>
                                        <div>
                                            <div class="font-bold text-gray-900">{{ $log->causer?->name ?? 'Hệ thống tự động' }}</div>
                                            <div class="text-[11px] text-gray-400 font-mono">{{ $log->causer?->email ?? 'system@menglish.edu.vn' }}</div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Timestamp & IP -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-mono text-gray-800 font-medium">{{ $log->created_at->format('H:i:s d/m/Y') }}</div>
                                    @if ($log->properties->has('ip'))
                                        <div class="text-[10px] text-gray-400 font-mono flex items-center gap-0.5 mt-0.5">
                                            <span class="material-symbols-outlined text-[12px]">router</span>
                                            <span>{{ $log->properties['ip'] }}</span>
                                        </div>
                                    @endif
                                </td>

                                <!-- Module Badge -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @php
                                        $modName = $log->log_name ?: 'Hệ thống';
                                        $badgeClass = match(true) {
                                            str_contains($modName, 'CRM') => 'bg-orange-50 text-orange-700 border-orange-200',
                                            str_contains($modName, 'Học phí') => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                            str_contains($modName, 'Học viên') => 'bg-blue-50 text-blue-700 border-blue-200',
                                            str_contains($modName, 'Đề thi') => 'bg-purple-50 text-purple-700 border-purple-200',
                                            str_contains($modName, 'Giáo trình') => 'bg-cyan-50 text-cyan-700 border-cyan-200',
                                            str_contains($modName, 'lương') || str_contains($modName, 'chấm công') => 'bg-amber-50 text-amber-700 border-amber-200',
                                            str_contains($modName, 'việc') => 'bg-teal-50 text-teal-700 border-teal-200',
                                            str_contains($modName, 'Ticket') => 'bg-rose-50 text-rose-700 border-rose-200',
                                            str_contains($modName, 'Media') => 'bg-pink-50 text-pink-700 border-pink-200',
                                            default => 'bg-slate-100 text-slate-700 border-slate-200',
                                        };
                                    @endphp
                                    <span class="px-2 py-0.5 rounded-md border text-[11px] font-bold {{ $badgeClass }}">
                                        {{ $modName }}
                                    </span>
                                </td>

                                <!-- Event -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @php
                                        $event = $log->event ?: 'Thao tác';
                                        $eventBadge = match(true) {
                                            str_contains($event, 'Tạo') || str_contains($event, 'created') => 'bg-emerald-100 text-emerald-800',
                                            str_contains($event, 'Cập nhật') || str_contains($event, 'updated') => 'bg-blue-100 text-blue-800',
                                            str_contains($event, 'Xóa') || str_contains($event, 'deleted') => 'bg-rose-100 text-rose-800',
                                            str_contains($event, 'Duyệt') => 'bg-teal-100 text-teal-800',
                                            default => 'bg-gray-100 text-gray-800',
                                        };
                                    @endphp
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold {{ $eventBadge }}">
                                        {{ \App\Support\Audit::eventLabel($log->event) }}
                                    </span>
                                </td>

                                <!-- Description, before/after diff & payload -->
                                <td class="py-3.5 px-4">
                                    <div class="font-semibold text-gray-900 leading-snug">
                                        {{ $log->description }}
                                    </div>
                                    @if ($log->subject_type)
                                        <div class="text-[10px] text-gray-400 font-mono mt-0.5">{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</div>
                                    @endif
                                    @php $diffRows = \App\Http\Controllers\ActivityLogController::diff($log); @endphp
                                    @if (! empty($diffRows))
                                        <details class="mt-1.5 text-xs text-gray-600" @if ($log->event === 'updated') open @endif>
                                            <summary class="cursor-pointer text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 inline-flex items-center gap-0.5">
                                                <span class="material-symbols-outlined text-[13px]">compare_arrows</span>
                                                <span>So sánh trước / sau ({{ count($diffRows) }} trường)</span>
                                            </summary>
                                            <table class="mt-1.5 w-full border border-gray-200 rounded-lg overflow-hidden text-[11px]">
                                                <thead class="bg-gray-50 text-gray-500">
                                                    <tr>
                                                        <th class="px-2 py-1 text-left font-semibold">Trường</th>
                                                        <th class="px-2 py-1 text-left font-semibold">Trước</th>
                                                        <th class="px-2 py-1 text-left font-semibold">Sau</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-100">
                                                    @foreach ($diffRows as $field => $row)
                                                        <tr>
                                                            <td class="px-2 py-1 font-mono text-gray-500">{{ $field }}</td>
                                                            <td class="px-2 py-1 text-rose-700 bg-rose-50/40 break-all">{{ \App\Http\Controllers\ActivityLogController::stringify($row['old']) }}</td>
                                                            <td class="px-2 py-1 text-emerald-700 bg-emerald-50/40 break-all">{{ \App\Http\Controllers\ActivityLogController::stringify($row['new']) }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </details>
                                    @endif
                                    @if ($canUndo && $log->event === 'updated' && ! empty($diffRows))
                                        @php [, , $undoError] = \App\Http\Controllers\ActivityLogController::undoPlan($log); @endphp
                                        @if (! $undoError)
                                            <form method="POST" action="{{ route('activity-logs.undo', $log->id) }}" class="mt-1.5" onsubmit="return confirm('Khôi phục các giá trị trước của thao tác này?');">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-amber-200 bg-amber-50 text-amber-800 text-[11px] font-bold hover:bg-amber-100">
                                                    <span class="material-symbols-outlined text-[14px]">undo</span> Hoàn tác
                                                </button>
                                            </form>
                                        @endif
                                    @endif
                                    @if ($log->properties->has('payload') && ! empty($log->properties['payload']))
                                        <details class="mt-1.5 text-xs text-gray-500">
                                            <summary class="cursor-pointer text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 inline-flex items-center gap-0.5">
                                                <span class="material-symbols-outlined text-[13px]">code</span>
                                                <span>Xem chi tiết tham số</span>
                                            </summary>
                                            <div class="mt-1.5 p-2.5 bg-slate-900 text-slate-100 rounded-xl font-mono text-[11px] overflow-x-auto shadow-inner">
                                                <pre>{{ json_encode($log->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </div>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-10 text-center text-gray-400 text-xs">
                                    Không tìm thấy nhật ký vận hành nào phù hợp với bộ lọc.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-gray-100 bg-white">
                <x-pagination :paginator="$logs" />
            </div>
        </div>
    </div>
</x-app-layout>
