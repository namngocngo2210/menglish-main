<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-900">Cấu hình Ngày nghỉ</h2>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-4">
        <div class="flex justify-end">
            @can('holiday.manage')
                <a href="{{ route('holidays.create') }}" class="inline-flex items-center gap-2 bg-primary-container hover:bg-primary-hover text-white text-sm font-medium px-4 py-2 rounded-lg">
                    <span class="material-symbols-outlined text-[18px]">add</span> Thêm ngày nghỉ mới
                </a>
            @endcan
        </div>

        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
            <table class="w-full text-left border-collapse text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3">Tên ngày nghỉ</th>
                        <th class="px-4 py-3">Thời gian</th>
                        <th class="px-4 py-3">Phạm vi áp dụng</th>
                        <th class="px-4 py-3 text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($holidays as $holiday)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ $holiday->name }}</div>
                                <div class="text-xs text-gray-500">Mã: {{ $holiday->code }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-700">{{ $holiday->start_date->format('d/m/Y') }} &rarr; {{ $holiday->end_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3">
                                @if ($holiday->is_system_wide)
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-primary-container/10 text-primary">Toàn hệ thống</span>
                                @else
                                    @foreach ($holiday->branches as $branch)
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">{{ $branch->name }}</span>
                                    @endforeach
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right space-x-2">
                                @can('holiday.manage')
                                    <a href="{{ route('holidays.edit', $holiday) }}" class="text-gray-500 hover:text-primary"><span class="material-symbols-outlined text-[18px] align-middle">edit</span></a>
                                    <form action="{{ route('holidays.destroy', $holiday) }}" method="POST" class="inline" onsubmit="return confirm('Xóa ngày nghỉ này?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-gray-500 hover:text-red-600"><span class="material-symbols-outlined text-[18px] align-middle">delete</span></button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">Chưa có ngày nghỉ nào.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-pagination :paginator="$holidays" />
        </div>
    </div>
</x-app-layout>
