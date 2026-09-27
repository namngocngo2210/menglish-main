{{-- Đổi trạng thái ticket (chọn là gửi ngay; requestSubmit để trong modal được hx-boost). Biến: $ticket. --}}
@can('support_ticket.close')
<form action="{{ route('tickets.status.update', $ticket->id) }}" method="POST" class="flex items-center gap-1.5">
    @csrf
    <select name="status" onchange="this.form.requestSubmit()" aria-label="Trạng thái ticket" class="text-xs font-bold rounded-xl border border-surface-container-highest p-2 {{ $ticket->status_badge }}">
        <option value="open" {{ $ticket->status === 'open' ? 'selected' : '' }}>Mới tiếp nhận (Open)</option>
        <option value="in_progress" {{ $ticket->status === 'in_progress' ? 'selected' : '' }}>Đang xử lý (In Progress)</option>
        <option value="resolved" {{ $ticket->status === 'resolved' ? 'selected' : '' }}>Đã giải quyết (Resolved)</option>
        <option value="closed" {{ $ticket->status === 'closed' ? 'selected' : '' }}>Đã đóng (Closed)</option>
    </select>
</form>
@else
    {{-- Người tạo / người tham gia chỉ xem trạng thái; đổi trạng thái cần quyền support_ticket.close. --}}
    <span class="inline-flex items-center rounded-xl border border-surface-container-highest p-2 text-xs font-bold {{ $ticket->status_badge }}">{{ ['open' => 'Mới tiếp nhận (Open)', 'in_progress' => 'Đang xử lý (In Progress)', 'resolved' => 'Đã giải quyết (Resolved)', 'closed' => 'Đã đóng (Closed)'][$ticket->status] ?? $ticket->status }}</span>
@endcan
