{{-- Thông tin ticket + phân công người xử lý — dùng chung trang và modal. Biến: $ticket, $staffs. --}}
{{-- Sidebar Info --}}
<div class="space-y-4">
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-4 text-xs">
        <h3 class="font-bold text-gray-900 uppercase tracking-wider pb-2 border-b border-gray-100">
            Thông tin Ticket
        </h3>

        <div class="space-y-3">
            <div class="flex justify-between">
                <span class="text-gray-500">Danh mục:</span>
                <span class="font-bold text-gray-900">{{ $ticket->category_label }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Mức độ ưu tiên:</span>
                <span class="px-2 py-0.5 rounded-full border text-[10px] {{ $ticket->priority_badge }}">{{ strtoupper($ticket->priority) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Trạng thái:</span>
                <span class="px-2 py-0.5 rounded-full border font-bold text-[10px] {{ $ticket->status_badge }}">{{ $ticket->status_label }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Người tạo:</span>
                <span class="font-bold text-gray-900">{{ $ticket->creator?->name }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Ngày tạo:</span>
                <span class="font-mono text-gray-700">{{ $ticket->created_at->format('d/m/Y H:i') }}</span>
            </div>
        </div>

        {{-- Reassign Staff Form --}}
        <div class="pt-3 border-t border-gray-100 space-y-2">
            <label class="block font-bold text-gray-800">Người phụ trách xử lý:</label>
            <form action="{{ route('tickets.assign', $ticket->id) }}" method="POST" class="space-y-2">
                @csrf
                <select name="assignee_id" class="w-full text-xs rounded-xl border border-gray-200 p-2">
                    <option value="">-- Chưa phân công --</option>
                    @foreach ($staffs as $staff)
                        <option value="{{ $staff->id }}" {{ $ticket->assignee_id == $staff->id ? 'selected' : '' }}>{{ $staff->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="w-full py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold rounded-lg text-xs transition">
                    Cập nhật Phân công
                </button>
            </form>
        </div>
    </div>
</div>
