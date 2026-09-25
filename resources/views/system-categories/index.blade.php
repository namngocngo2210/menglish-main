<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-900">Quản lý Danh mục hệ thống</h2>
    </x-slot>

    @php
        $typeLabels = [
            'lead_source' => 'Nguồn khách hàng',
            'lost_reason' => 'Lý do không chốt',
            'position' => 'Chức vụ',
            'fine_level' => 'Mức phạt',
        ];
    @endphp

    <div class="max-w-4xl mx-auto space-y-4">
        <div class="flex items-center justify-between">
            <div class="flex gap-1 p-1 bg-gray-100 rounded-xl">
                @foreach ($types as $t)
                    <a href="{{ route('system-categories.index', ['type' => $t]) }}"
                       class="px-4 py-2 rounded-lg text-sm font-medium {{ $type === $t ? 'bg-white shadow-sm text-primary' : 'text-gray-500' }}">
                        {{ $typeLabels[$t] ?? $t }}
                    </a>
                @endforeach
            </div>
            @can('system_category.manage')
                <a href="{{ route('system-categories.create', ['type' => $type]) }}" class="inline-flex items-center gap-2 bg-primary hover:bg-primary-hover text-white text-sm font-medium px-4 py-2 rounded-lg">
                    <span class="material-symbols-outlined text-[18px]">add</span> Thêm danh mục mới
                </a>
            @endcan
        </div>

        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
            <table class="w-full text-left border-collapse text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3">Mã</th>
                        <th class="px-4 py-3">Tên danh mục</th>
                        <th class="px-4 py-3">Thứ tự</th>
                        <th class="px-4 py-3">Trạng thái</th>
                        <th class="px-4 py-3 text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($categories as $category)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-gray-600">{{ $category->code }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $category->name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $category->sort_order }}</td>
                            <td class="px-4 py-3">
                                @if ($category->is_active)
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">Đang dùng</span>
                                @else
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">Đã ngừng</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right space-x-2">
                                @can('system_category.manage')
                                    <a href="{{ route('system-categories.edit', $category) }}" class="text-gray-500 hover:text-primary"><span class="material-symbols-outlined text-[18px] align-middle">edit</span></a>
                                    @if ($category->is_active)
                                        <form action="{{ route('system-categories.destroy', $category) }}" method="POST" class="inline">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-gray-500 hover:text-red-600"><span class="material-symbols-outlined text-[18px] align-middle">block</span></button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Chưa có danh mục nào.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-pagination :paginator="$categories" />
        </div>
    </div>
</x-app-layout>
