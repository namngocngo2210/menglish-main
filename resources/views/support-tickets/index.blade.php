<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">confirmation_number</span>
                    Trung Tâm Hỗ Trợ &amp; Xử Lý Yêu Cầu (Tickets)
                </h1>
                <p class="text-xs text-gray-500">Tiếp nhận yêu cầu kỹ thuật, vận hành, học vụ và theo dõi tiến độ xử lý</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('system-config.ticket-emails') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white hover:bg-gray-50 border border-gray-200 text-gray-700 text-xs font-semibold shadow-xs transition">
                    <span class="material-symbols-outlined text-[18px] text-gray-500">settings</span>
                    <span>Cấu hình Email nhận</span>
                </a>
                <a href="{{ route('tickets.create') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition">
                    <span class="material-symbols-outlined text-[18px]">add_circle</span>
                    <span>Tạo Ticket Mới</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <!-- Stats Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <div class="text-xs text-gray-500 font-medium">Tổng Ticket</div>
                    <div class="text-xl font-bold text-gray-900 font-mono">{{ $stats['total'] }}</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-gray-50 text-gray-700 flex items-center justify-center">
                    <span class="material-symbols-outlined">inbox</span>
                </div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-amber-200 shadow-sm flex items-center justify-between bg-amber-50/20">
                <div>
                    <div class="text-xs text-amber-700 font-medium">Mới tiếp nhận</div>
                    <div class="text-xl font-bold text-amber-900 font-mono">{{ $stats['open'] }}</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-800 flex items-center justify-center">
                    <span class="material-symbols-outlined">hourglass_top</span>
                </div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-blue-200 shadow-sm flex items-center justify-between bg-blue-50/20">
                <div>
                    <div class="text-xs text-blue-700 font-medium">Đang xử lý</div>
                    <div class="text-xl font-bold text-blue-900 font-mono">{{ $stats['in_progress'] }}</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-800 flex items-center justify-center">
                    <span class="material-symbols-outlined">pending</span>
                </div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-emerald-200 shadow-sm flex items-center justify-between bg-emerald-50/20">
                <div>
                    <div class="text-xs text-emerald-700 font-medium">Đã giải quyết</div>
                    <div class="text-xl font-bold text-emerald-900 font-mono">{{ $stats['resolved'] }}</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-800 flex items-center justify-center">
                    <span class="material-symbols-outlined">check_circle</span>
                </div>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4">
            <form action="{{ route('tickets.index') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Tìm theo mã TK, tiêu đề, nội dung..." class="text-xs rounded-xl border border-gray-200 p-2 sm:col-span-2">
                <select name="status" class="text-xs rounded-xl border border-gray-200 p-2">
                    <option value="">-- Tất cả trạng thái --</option>
                    <option value="open" {{ request('status') === 'open' ? 'selected' : '' }}>Mới tiếp nhận (Open)</option>
                    <option value="in_progress" {{ request('status') === 'in_progress' ? 'selected' : '' }}>Đang xử lý (In Progress)</option>
                    <option value="resolved" {{ request('status') === 'resolved' ? 'selected' : '' }}>Đã giải quyết (Resolved)</option>
                    <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>Đã đóng (Closed)</option>
                </select>
                <button type="submit" class="px-4 py-2 bg-gray-900 hover:bg-black text-white font-bold rounded-xl shadow-sm transition">
                    Lọc Tickets
                </button>
            </form>
        </div>

        <!-- Tickets Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Mã Ticket</th>
                        <th class="py-3 px-4">Tiêu đề &amp; Danh mục</th>
                        <th class="py-3 px-4">Mức độ ưu tiên</th>
                        <th class="py-3 px-4">Người tạo</th>
                        <th class="py-3 px-4">Người phụ trách</th>
                        <th class="py-3 px-4">Trạng thái</th>
                        <th class="py-3 px-4 text-right">Chi tiết</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    @forelse ($tickets as $ticket)
                        <tr class="hover:bg-purple-50/10 transition">
                            <td class="py-3.5 px-4">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-orange-50 text-[#c2410c] border border-orange-200 font-mono font-bold text-xs shadow-2xs">
                                    #{{ $ticket->code }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4">
                                <div class="font-bold text-gray-900">{{ $ticket->title }}</div>
                                <div class="text-[11px] text-gray-400">{{ $ticket->category_label }} · {{ $ticket->created_at->format('d/m/Y H:i') }}</div>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="px-2.5 py-0.5 rounded-full border text-[10px] {{ $ticket->priority_badge }}">
                                    {{ strtoupper($ticket->priority) }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 font-medium text-gray-800">{{ $ticket->creator?->name ?? 'Hệ thống' }}</td>
                            <td class="py-3.5 px-4 font-medium text-gray-800">
                                @if ($ticket->assignee)
                                    <span class="text-primary font-bold">{{ $ticket->assignee->name }}</span>
                                @else
                                    <span class="text-gray-400 italic">Chưa phân công</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="px-2.5 py-1 rounded-full border font-bold text-[10px] {{ $ticket->status_badge }}">
                                    {{ $ticket->status_label }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                <a href="{{ route('tickets.show', $ticket->id) }}" class="px-3 py-1 rounded-lg bg-orange-50 hover:bg-orange-100 text-primary font-bold text-xs transition inline-flex items-center gap-1">
                                    <span>Trao đổi</span>
                                    <span class="material-symbols-outlined text-[14px]">forum</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-8 text-gray-400 text-xs">Chưa có ticket nào được tạo.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-pagination :paginator="$tickets" />
        </div>
    </div>
</x-app-layout>
