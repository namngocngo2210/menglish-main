<x-app-layout>
    <x-ui.page-header title="Dashboard Nhật ký Sự vụ Cơ sở">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="assessment" :href="route('academic.dashboards.reports')">Dashboard Báo cáo</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">
        {{-- 4 Metric Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-gray-100 text-gray-700 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">auto_stories</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500">Tổng sự vụ ghi nhận</span>
                    <p class="text-2xl font-extrabold text-gray-900">{{ $totalIncidents }} <span class="text-xs text-gray-400">vụ</span></p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-rose-200 bg-rose-50/20 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">emergency</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-rose-700 font-semibold">Khẩn cấp cần xử lý</span>
                    <p class="text-2xl font-extrabold text-rose-600">{{ $urgentCount }} <span class="text-xs text-rose-500">ticket khẩn cấp</span></p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-amber-200 bg-amber-50/20 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">hourglass_top</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-amber-800 font-semibold">Đang theo dõi / Xử lý</span>
                    <p class="text-2xl font-extrabold text-amber-600">{{ $openCount }} <span class="text-xs text-amber-500">vụ</span></p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-emerald-200 bg-emerald-50/20 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">check_circle</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-emerald-800 font-semibold">Đã giải quyết</span>
                    <p class="text-2xl font-extrabold text-emerald-600">{{ $resolvedCount }} <span class="text-xs text-emerald-500">vụ</span></p>
                </div>
            </div>
        </div>

        {{-- Filter & Bảng Sự vụ --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-xs overflow-hidden">
            <div class="p-5 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h3 class="font-bold text-gray-900 text-sm">Danh sách Sự vụ Nổi cộm Các Cơ sở</h3>
                    <p class="text-xs text-gray-500">Theo dõi, giao quyền xử lý và ghi nhận giải pháp khắc phục</p>
                </div>
                <form method="GET" action="{{ route('academic.dashboards.incidents') }}" class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-gray-500 font-medium">Lọc theo:</span>
                    <select name="branch_id" class="text-xs rounded-xl border-gray-200 font-semibold py-1.5 px-3" onchange="this.form.submit()">
                        <option value="">Tất cả cơ sở</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected((string) $branchId === (string) $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <select name="severity" class="text-xs rounded-xl border-gray-200 font-semibold py-1.5 px-3" onchange="this.form.submit()">
                        <option value="all">Mọi mức độ</option>
                        <option value="urgent" @selected($severity === 'urgent')>Khẩn cấp</option>
                        <option value="high" @selected($severity === 'high')>Cao</option>
                        <option value="medium" @selected($severity === 'medium')>Trung bình</option>
                        <option value="low" @selected($severity === 'low')>Thấp</option>
                    </select>
                    <select name="status" class="text-xs rounded-xl border-gray-200 font-semibold py-1.5 px-3" onchange="this.form.submit()">
                        <option value="all">Mọi trạng thái</option>
                        <option value="open" @selected($status === 'open')>Đang xử lý</option>
                        <option value="resolved" @selected($status === 'resolved')>Đã giải quyết</option>
                    </select>
                    <noscript><button type="submit" class="text-xs font-bold">Lọc</button></noscript>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-gray-50 text-gray-600 font-bold uppercase tracking-wider border-b border-gray-200">
                        <tr>
                            <th class="py-3 px-4">Mã / Cơ sở</th>
                            <th class="py-3 px-4">Phân loại sự vụ</th>
                            <th class="py-3 px-4">Chi tiết sự vụ phát sinh</th>
                            <th class="py-3 px-4">Mức độ</th>
                            <th class="py-3 px-4">Người phụ trách & Biện pháp</th>
                            <th class="py-3 px-4">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @php
                            $priorityLabels = ['urgent' => 'Khẩn cấp', 'high' => 'Cao', 'medium' => 'Trung bình', 'low' => 'Thấp'];
                        @endphp
                        @foreach ($urgentTickets as $ticket)
                            <tr class="hover:bg-gray-50/80 transition {{ $ticket->priority === 'urgent' ? 'bg-rose-50/10' : '' }}">
                                <td class="py-3 px-4">
                                    <a href="{{ route('tickets.show', $ticket->id) }}" class="font-bold text-gray-900 font-mono hover:underline">{{ $ticket->code }}</a>
                                    <span class="block text-[11px] text-gray-500">{{ $ticket->creator?->branch?->name ?? 'Chưa cập nhật' }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 text-gray-800 border border-gray-200">{{ $ticket->category_label }}</span>
                                </td>
                                <td class="py-3 px-4 max-w-sm">
                                    <p class="font-semibold text-gray-900">{{ $ticket->title }}</p>
                                    <p class="text-[11px] text-gray-500 line-clamp-1">{{ \Illuminate\Support\Str::limit(strip_tags((string) $ticket->description), 140) }}</p>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-0.5 rounded-full text-[11px] border {{ $ticket->priority_badge }}">{{ $priorityLabels[$ticket->priority] ?? 'Chưa cập nhật' }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="font-bold text-gray-900">{{ $ticket->assignee?->name ?? 'Chưa phân công' }}</span>
                                    <span class="block text-[11px] text-gray-500">Người tạo: {{ $ticket->creator?->name ?? 'Chưa cập nhật' }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $ticket->status_badge }}">{{ $ticket->status_label }}</span>
                                </td>
                            </tr>
                        @endforeach
                        @foreach ($incidents as $incident)
                            <tr class="hover:bg-gray-50/80 transition">
                                <td class="py-3 px-4">
                                    <span class="font-bold text-gray-900 font-mono">{{ $incident->record_code ?: 'Nhật ký #' . $incident->id }}</span>
                                    <span class="block text-[11px] text-gray-500">{{ $incident->user?->branch?->name ?? 'Chưa cập nhật' }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">Nhật ký học vụ</span>
                                </td>
                                <td class="py-3 px-4 max-w-sm">
                                    <p class="font-semibold text-gray-900">{{ $incident->title }}</p>
                                    @if (! empty($incident->data['noi_dung']))
                                        <p class="text-[11px] text-gray-500 line-clamp-1">{{ $incident->data['noi_dung'] }}</p>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-[11px] text-gray-400">—</td>
                                <td class="py-3 px-4">
                                    <span class="font-bold text-gray-900">{{ $incident->user?->name ?? 'Chưa cập nhật' }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-gray-50 text-gray-700 border border-gray-200">{{ $incident->status_label }}</span>
                                </td>
                            </tr>
                        @endforeach
                        @if ($urgentTickets->isEmpty() && $incidents->isEmpty())
                            <tr>
                                <td colspan="6" class="py-10 text-center text-gray-400">Chưa có sự vụ nào phù hợp bộ lọc.</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
