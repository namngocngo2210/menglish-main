{{-- Thông tin ticket + phân công người xử lý — dùng chung trang và modal. Biến: $ticket, $staffs. --}}
{{-- Sidebar Info --}}
<div class="space-y-4">
    <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-5 space-y-4 text-xs">
        <h3 class="font-bold text-on-surface uppercase tracking-wider pb-2 border-b border-surface-container-highest">
            Thông tin Ticket
        </h3>

        <div class="space-y-3">
            <div class="flex justify-between">
                <span class="text-on-surface-variant">Danh mục:</span>
                <span class="font-bold text-on-surface">{{ $ticket->category_label }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-on-surface-variant">Mức độ ưu tiên:</span>
                <span class="px-2 py-0.5 rounded-full border text-[10px] {{ $ticket->priority_badge }}">{{ strtoupper($ticket->priority) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-on-surface-variant">Trạng thái:</span>
                <span class="px-2 py-0.5 rounded-full border font-bold text-[10px] {{ $ticket->status_badge }}">{{ $ticket->status_label }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-on-surface-variant">Người tạo:</span>
                <span class="font-bold text-on-surface">{{ $ticket->creator?->name }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-on-surface-variant">Ngày tạo:</span>
                <span class="font-mono text-on-surface-variant">{{ $ticket->created_at->format('d/m/Y H:i') }}</span>
            </div>
        </div>

        {{-- Reassign Staff Form --}}
        <div class="pt-3 border-t border-surface-container-highest space-y-2">
            <label class="block font-bold text-on-surface">Người phụ trách xử lý:</label>
            <form action="{{ route('tickets.assign', $ticket->id) }}" method="POST" class="space-y-2">
                @csrf
                <x-ui.select name="assignee_id" class="text-xs" placeholder="-- Chưa phân công --" :value="$ticket->assignee_id"
                             :options="$staffs->pluck('name', 'id')" />
                <x-ui.button type="submit" variant="secondary" size="sm" class="w-full">Cập nhật Phân công</x-ui.button>
            </form>
        </div>
    </div>
</div>
