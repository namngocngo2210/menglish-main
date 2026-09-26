{{-- Danh sách ticket: Tạo mở modal 2xl, "Trao đổi" mở modal 3xl (hội thoại + trả lời, đẩy URL /tickets/{id});
     thao tác xong server phát "tickets-changed" → #ticket-list tự tải lại (giữ bộ lọc, trang hiện tại). --}}
<x-app-layout>
    <x-ui.page-header title="Trung Tâm Hỗ Trợ & Xử Lý Yêu Cầu (Tickets)" icon="confirmation_number">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="settings" :href="route('system-config.ticket-emails')">Cấu hình Email nhận</x-ui.button>
            <x-ui.button icon="add_circle" :href="route('tickets.create')" modal="2xl">Tạo Ticket Mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div id="ticket-list" class="space-y-6"
         hx-get="{{ route('tickets.index', request()->query()) }}" hx-trigger="tickets-changed from:body" hx-select="#ticket-list" hx-swap="outerHTML" hx-disinherit="*">
        {{-- Stats Cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <x-ui.stat-card label="Tổng Ticket" :value="$stats['total']" icon="inbox" />
            <x-ui.stat-card label="Mới tiếp nhận" :value="$stats['open']" tone="warning" icon="hourglass_top" />
            <x-ui.stat-card label="Đang xử lý" :value="$stats['in_progress']" tone="secondary" icon="pending" />
            <x-ui.stat-card label="Đã giải quyết" :value="$stats['resolved']" tone="success" icon="check_circle" />
        </div>

        {{-- Filter & Search Bar --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-4">
            <form action="{{ route('tickets.index') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs">
                <div class="sm:col-span-2"><x-ui.input name="search" :value="request('search')" placeholder="Tìm theo mã TK, tiêu đề, nội dung..." class="text-xs" /></div>
                <x-ui.select name="status" class="text-xs" placeholder="-- Tất cả trạng thái --"
                             :options="['open' => 'Mới tiếp nhận (Open)', 'in_progress' => 'Đang xử lý (In Progress)', 'resolved' => 'Đã giải quyết (Resolved)', 'closed' => 'Đã đóng (Closed)']" />
                <x-ui.button type="submit" variant="secondary">Lọc Tickets</x-ui.button>
            </form>
        </div>

        {{-- Tickets Table --}}
        <x-ui.data-table>
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Mã Ticket</th>
                        <th>Tiêu đề &amp; Danh mục</th>
                        <th>Mức độ ưu tiên</th>
                        <th>Người tạo</th>
                        <th>Người phụ trách</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Chi tiết</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-primary-container/10 text-primary border border-primary-container/30 font-mono font-bold text-xs shadow-2xs">
                                    #{{ $ticket->code }}
                                </span>
                            </td>
                            <td>
                                <div class="font-bold text-on-surface">{{ $ticket->title }}</div>
                                <div class="text-[11px] text-on-surface-variant/70">{{ $ticket->category_label }} · {{ $ticket->created_at->format('d/m/Y H:i') }}</div>
                            </td>
                            <td>
                                <span class="px-2.5 py-0.5 rounded-full border text-[10px] {{ $ticket->priority_badge }}">
                                    {{ strtoupper($ticket->priority) }}
                                </span>
                            </td>
                            <td class="font-medium">{{ $ticket->creator?->name ?? 'Hệ thống' }}</td>
                            <td class="font-medium">
                                @if ($ticket->assignee)
                                    <span class="text-primary font-bold">{{ $ticket->assignee->name }}</span>
                                @else
                                    <span class="text-on-surface-variant/70 italic">Chưa phân công</span>
                                @endif
                            </td>
                            <td>
                                <span class="px-2.5 py-1 rounded-full border font-bold text-[10px] {{ $ticket->status_badge }}">
                                    {{ $ticket->status_label }}
                                </span>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <x-ui.button variant="ghost" size="sm" icon="forum" :href="route('tickets.show', $ticket->id)" hx-get="{{ route('tickets.show', $ticket->id) }}" hx-target="#remote-modal-body" hx-swap="innerHTML" hx-push-url="true" data-modal-size="3xl">Trao đổi</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7"><x-ui.empty-state title="Chưa có ticket nào được tạo." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$tickets" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
