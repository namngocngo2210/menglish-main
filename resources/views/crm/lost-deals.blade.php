<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-4">
        <!-- Lost deals table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[980px]">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4 min-w-[180px] whitespace-nowrap">Khách hàng</th>
                            <th class="py-3 px-4 min-w-[160px] whitespace-nowrap">Khóa quan tâm</th>
                            <th class="py-3 px-4 min-w-[140px] whitespace-nowrap">Giá trị mất (Lost Value)</th>
                            <th class="py-3 px-4 min-w-[160px] whitespace-nowrap">Lý do thất bại</th>
                            <th class="py-3 px-4 min-w-[140px] whitespace-nowrap">Sales phụ trách</th>
                            <th class="py-3 px-4 min-w-[160px] whitespace-nowrap">Ghi chú</th>
                            <th class="py-3 px-4 text-right min-w-[100px] whitespace-nowrap">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($lostCustomers as $lc)
                            <tr class="hover:bg-rose-50/20 transition">
                                <td class="py-3.5 px-4 font-medium whitespace-nowrap">
                                    <a href="{{ route('crm.customers.show', $lc->id) }}" class="font-bold text-gray-900 hover:text-primary transition whitespace-nowrap">{{ $lc->name }}</a>
                                    <div class="text-[11px] text-gray-400 font-mono whitespace-nowrap">{{ $lc->phone }}</div>
                                </td>
                                <td class="py-3.5 px-4 font-semibold text-gray-900 whitespace-nowrap">{{ $lc->course_interest }}</td>
                                <td class="py-3.5 px-4 font-mono font-bold text-rose-600 whitespace-nowrap">{{ number_format($lc->deal_value) }}đ</td>
                                <td class="py-3.5 px-4 text-rose-700 font-medium whitespace-nowrap">{{ $lc->lost_reason ?? 'Chưa ghi nhận lý do' }}</td>
                                <td class="py-3.5 px-4 whitespace-nowrap">{{ $lc->assignedUser?->name ?? 'Chưa phân công' }}</td>
                                <td class="py-3.5 px-4 text-gray-600">{{ $lc->notes }}</td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <a href="{{ route('crm.customers.show', $lc->id) }}" class="text-primary hover:underline font-semibold whitespace-nowrap">Xem chi tiết</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-6 text-gray-400 text-xs">Không có deal nào ở trạng thái Lost.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
