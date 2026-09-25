{{-- Học viên đã chốt nhưng chưa có lớp (lead ở Chờ xếp lớp). Cần: $waitingLeads, $matchingClassesByLead --}}
<div id="waiting-class" class="bg-white rounded-2xl border border-rose-200 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-rose-100 bg-rose-50/60 flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-rose-600">error</span>
            <div>
                <h2 class="text-sm font-black text-gray-900">Chờ xếp lớp (Cần xử lý gấp)</h2>
                <p class="text-xs text-gray-500">Hiện có {{ str_pad((string) $waitingLeads->count(), 2, '0', STR_PAD_LEFT) }} học viên đang đợi phân bổ vào lớp. Gợi ý lớp đúng khóa, đúng chi nhánh, còn chỗ.</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-rose-100 text-rose-700 text-[11px] font-bold">
            <span class="material-symbols-outlined text-[14px]">schedule</span>Ưu tiên xử lý
        </span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs min-w-[900px]">
            <thead class="bg-gray-50 text-[11px] uppercase text-gray-500">
                <tr>
                    <th class="py-3 px-4">Họ tên</th>
                    <th class="py-3 px-4">Số điện thoại</th>
                    <th class="py-3 px-4">Chi nhánh &amp; Khóa</th>
                    <th class="py-3 px-4">Thời điểm chốt</th>
                    <th class="py-3 px-4 text-right">Hành động</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($waitingLeads as $lead)
                    @php($matches = $matchingClassesByLead->get($lead->id, collect()))
                    <tr class="align-top">
                        <td class="py-3 px-4">
                            <a href="{{ route('crm.customers.show', $lead->id) }}" class="font-bold text-gray-900 hover:text-primary">{{ $lead->name }}</a>
                            <div class="text-[11px] text-gray-400 font-mono">{{ $lead->convertedStudent?->code }}</div>
                        </td>
                        <td class="py-3 px-4 font-mono">{{ $lead->phone }}</td>
                        <td class="py-3 px-4">
                            <div class="font-semibold text-gray-900">{{ $lead->branch?->name ?? '—' }}</div>
                            <div class="text-gray-500">{{ $lead->waitingCourse?->name ?? 'Chưa chọn khóa' }}</div>
                        </td>
                        <td class="py-3 px-4">
                            <div class="font-semibold">{{ $lead->converted_at?->format('H:i d/m/Y') ?? '—' }}</div>
                            @if ($lead->converted_at)
                                <div class="{{ $lead->converted_at->diffInDays(now()) >= 7 ? 'text-rose-600 font-bold' : 'text-gray-500' }}">Chờ {{ (int) $lead->converted_at->diffInDays(now()) }} ngày</div>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-right">
                            @can('student.assign_class')
                                @if ($matches->isNotEmpty())
                                    <form action="{{ route('crm.customers.assign-class', $lead->id) }}" method="POST" class="inline-flex items-center gap-2 justify-end">
                                        @csrf
                                        <select name="class_id" required class="rounded-lg border-gray-200 text-xs">
                                            @foreach ($matches as $class)
                                                <option value="{{ $class->id }}">{{ $class->name }} · còn {{ $class->max_capacity > 0 ? max(0, $class->max_capacity - $class->active_enrollments_count) : '∞' }} chỗ</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-primary text-white font-bold whitespace-nowrap">
                                            <span class="material-symbols-outlined text-[16px]">assignment_turned_in</span>Gán lớp
                                        </button>
                                    </form>
                                @else
                                    <span class="text-amber-600 font-semibold">Chưa có lớp phù hợp</span>
                                @endif
                            @else
                                <span class="text-gray-400">Học vụ sẽ gán lớp</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center text-gray-400">Không có học viên nào đang chờ xếp lớp.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
