<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-4">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h2 class="text-lg font-black text-gray-900">Điều phối danh sách chờ lớp</h2>
            <p class="text-xs text-gray-500 mt-1">Ưu tiên cao và thời gian chờ lâu được xếp trước. Hệ thống chỉ gợi ý lớp còn chỗ, đúng khóa và đúng chi nhánh.</p>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-xs text-left min-w-[900px]">
                <thead class="bg-gray-50 text-[11px] uppercase text-gray-500">
                    <tr>
                        <th class="p-3">Lead</th>
                        <th class="p-3">Nhu cầu</th>
                        <th class="p-3">Thời gian chờ</th>
                        <th class="p-3">Lớp phù hợp</th>
                        <th class="p-3 text-right">Xử lý</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($waitingLeads as $lead)
                        @php($matches = $matchingClassesByLead->get($lead->id, collect()))
                        <tr class="align-top">
                            <td class="p-3">
                                <div class="font-bold text-gray-900">{{ $lead->name }}</div>
                                <div class="text-gray-500">{{ $lead->phone }} · {{ $lead->assignedUser?->name ?? 'Chưa phân công' }}</div>
                            </td>
                            <td class="p-3">
                                <div class="font-semibold">{{ $lead->waitingCourse?->name ?? 'Chưa chọn khóa' }}</div>
                                <div class="text-gray-500">{{ $lead->waitingBranch?->name ?? 'Chưa chọn cơ sở' }} · {{ $lead->preferred_schedule }}</div>
                                <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-yellow-50 text-yellow-700 font-bold">Ưu tiên {{ $lead->waiting_priority }}/5</span>
                            </td>
                            <td class="p-3">
                                <div class="font-semibold">{{ $lead->waiting_since?->format('d/m/Y') ?? '—' }}</div>
                                <div class="{{ $lead->waiting_since?->diffInDays(today()) >= 7 ? 'text-rose-600 font-bold' : 'text-gray-500' }}">
                                    {{ $lead->waiting_since ? $lead->waiting_since->diffInDays(today()).' ngày' : 'Chưa xác định' }}
                                </div>
                            </td>
                            <td class="p-3">
                                @forelse ($matches as $class)
                                    <div class="mb-1"><span class="font-bold text-emerald-700">{{ $class->name }}</span> · còn {{ max(0, $class->max_capacity - $class->active_enrollments_count) }} chỗ</div>
                                @empty
                                    <span class="text-amber-600 font-semibold">Chưa có lớp phù hợp</span>
                                @endforelse
                            </td>
                            <td class="p-3 text-right whitespace-nowrap">
                                <a href="{{ route('crm.customers.show', $lead->id) }}" class="inline-flex px-3 py-1.5 rounded-xl bg-slate-900 text-white font-bold">Mở hồ sơ</a>
                                @if ($matches->isNotEmpty())
                                    @can('lead.convert')
                                    <a href="{{ route('crm.closing-wizard', ['customer_id' => $lead->id]) }}" class="inline-flex ml-1 px-3 py-1.5 rounded-xl bg-[#ea580c] text-white font-bold">Xếp lớp</a>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-gray-400">Không có Lead đang chờ lớp.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
