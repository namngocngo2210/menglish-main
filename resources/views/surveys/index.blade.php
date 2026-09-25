<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">ballot</span>
                    Quản lý Đợt Khảo sát Chất lượng
                </h1>
                <p class="text-xs text-gray-500">Tạo khảo sát + hạn hoàn thành; học viên/phụ huynh nộp tại Cổng PH/HS (màn Khảo sát 5 sao)</p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-xs font-semibold text-emerald-900">{{ session('status') }}</div>
        @endif

        <!-- Tạo khảo sát mới -->
        <form action="{{ route('surveys.store') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-4">
            @csrf
            <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[18px]">add_circle</span>
                Tạo đợt khảo sát mới
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                <div class="md:col-span-6">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Tiêu đề khảo sát <span class="text-rose-500">*</span></label>
                    <input type="text" name="title" required value="{{ old('title') }}" placeholder="VD: Đánh giá chất lượng cơ sở vật chất tháng 10"
                           class="w-full text-xs rounded-xl border border-gray-200 px-3 py-2 font-semibold" />
                    @error('title') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Hạn hoàn thành</label>
                    <input type="date" name="deadline" value="{{ old('deadline') }}" min="{{ now()->toDateString() }}"
                           class="w-full text-xs rounded-xl border border-gray-200 px-3 py-2" />
                    @error('deadline') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-3 flex items-end">
                    <button type="submit" class="w-full px-4 py-2 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition">
                        Tạo khảo sát
                    </button>
                </div>
                <div class="md:col-span-12">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Mô tả / câu hỏi hướng dẫn (tùy chọn)</label>
                    <textarea name="description" rows="2" placeholder="VD: Đánh giá phòng học, thiết bị, thái độ hỗ trợ của học vụ..."
                              class="w-full text-xs rounded-xl border border-gray-200 px-3 py-2">{{ old('description') }}</textarea>
                </div>
            </div>
        </form>

        <!-- Danh sách -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Tiêu đề</th>
                        <th class="py-3 px-4">Hạn</th>
                        <th class="py-3 px-4">Trạng thái</th>
                        <th class="py-3 px-4">Người tạo</th>
                        <th class="py-3 px-4 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($surveys as $sv)
                        <tr class="hover:bg-orange-50/20 transition align-top">
                            <td class="py-3.5 px-4">
                                <form action="{{ route('surveys.update', $sv->id) }}" method="POST" class="space-y-1">
                                    @csrf
                                    @method('PUT')
                                    <input type="text" name="title" value="{{ $sv->title }}" required class="w-full text-xs font-bold text-gray-900 rounded-lg border border-gray-200 px-2 py-1.5" />
                                    <textarea name="description" rows="1" class="w-full text-[11px] text-gray-500 rounded-lg border border-gray-200 px-2 py-1" placeholder="Mô tả...">{{ $sv->description }}</textarea>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <input type="date" name="deadline" value="{{ $sv->deadline?->format('Y-m-d') }}" class="text-[11px] rounded-lg border border-gray-200 px-2 py-1" />
                                        <label class="flex items-center gap-1 text-[11px] font-semibold text-gray-600">
                                            <input type="hidden" name="is_active" value="0" />
                                            <input type="checkbox" name="is_active" value="1" {{ $sv->is_active ? 'checked' : '' }} class="rounded text-emerald-600" />
                                            Đang mở
                                        </label>
                                        <button type="submit" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-[11px] font-bold transition">Lưu</button>
                                    </div>
                                </form>
                            </td>
                            <td class="py-3.5 px-4 font-mono text-gray-600 whitespace-nowrap">
                                {{ $sv->deadline?->format('d/m/Y') ?? '—' }}
                                @if ($sv->deadline && $sv->deadline->isToday())
                                    <span class="text-rose-600 font-bold">• Hôm nay</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $sv->is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-gray-100 text-gray-600 border-gray-200' }}">
                                    {{ $sv->is_active ? 'Đang mở' : 'Đã đóng' }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-gray-600">{{ $sv->creator?->name ?? '—' }}</td>
                            <td class="py-3.5 px-4 text-right">
                                <form action="{{ route('surveys.destroy', $sv->id) }}" method="POST" class="inline" data-confirm="Xóa khảo sát \"{{ $sv->title }}\"? Lịch sử đã nộp của học viên vẫn được giữ.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-[11px] font-bold transition">Xóa</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center py-8 text-gray-400 text-xs">Chưa có đợt khảo sát nào.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('submit', function (event) {
            const form = event.target instanceof Element ? event.target.closest('form[data-confirm]') : null;
            if (form && !window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        }, true);
    </script>
    @endpush
</x-app-layout>
