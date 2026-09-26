<x-app-layout>
    <x-ui.page-header title="Quản lý Đợt Khảo sát Chất lượng" icon="ballot" />

    <div class="space-y-4">

        {{-- Tạo khảo sát mới --}}
        <form action="{{ route('surveys.store') }}" method="POST" class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-5 space-y-4">
            @csrf
            <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[18px]">add_circle</span>
                Tạo đợt khảo sát mới
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                <div class="md:col-span-6">
                    <x-ui.input name="title" label="Tiêu đề khảo sát" required placeholder="VD: Đánh giá chất lượng cơ sở vật chất tháng 10" />
                </div>
                <div class="md:col-span-3">
                    <x-ui.date name="deadline" label="Hạn hoàn thành" min="{{ now()->toDateString() }}" />
                </div>
                <div class="md:col-span-3 flex items-end">
                    <x-ui.button type="submit" class="w-full">
                        Tạo khảo sát
                    </x-ui.button>
                </div>
                <div class="md:col-span-12">
                    <x-ui.textarea name="description" label="Mô tả / câu hỏi hướng dẫn (tùy chọn)" rows="2" placeholder="VD: Đánh giá phòng học, thiết bị, thái độ hỗ trợ của học vụ..." />
                </div>
            </div>
        </form>

        {{-- Danh sách --}}
        <x-ui.data-table>
            <table>
                <thead>
                    <tr>
                        <th>Tiêu đề</th>
                        <th>Hạn</th>
                        <th>Trạng thái</th>
                        <th>Người tạo</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($surveys as $sv)
                        <tr class="align-top">
                            <td>
                                <form action="{{ route('surveys.update', $sv->id) }}" method="POST" class="space-y-1">
                                    @csrf
                                    @method('PUT')
                                    <input type="text" name="title" value="{{ $sv->title }}" required class="w-full text-xs font-bold text-on-surface rounded-lg border border-surface-container-highest px-2 py-1.5" />
                                    <textarea name="description" rows="1" class="w-full text-[11px] text-on-surface-variant rounded-lg border border-surface-container-highest px-2 py-1" placeholder="Mô tả...">{{ $sv->description }}</textarea>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <input type="date" name="deadline" value="{{ $sv->deadline?->format('Y-m-d') }}" class="text-[11px] rounded-lg border border-surface-container-highest px-2 py-1" />
                                        <label class="flex items-center gap-1 text-[11px] font-semibold text-on-surface-variant">
                                            <input type="hidden" name="is_active" value="0" />
                                            <input type="checkbox" name="is_active" value="1" {{ $sv->is_active ? 'checked' : '' }} class="rounded border-outline-variant text-tertiary focus:ring-tertiary" />
                                            Đang mở
                                        </label>
                                        <x-ui.button type="submit" variant="secondary" size="sm">Lưu</x-ui.button>
                                    </div>
                                </form>
                            </td>
                            <td class="font-mono text-on-surface-variant whitespace-nowrap">
                                {{ $sv->deadline?->format('d/m/Y') ?? '—' }}
                                @if ($sv->deadline && $sv->deadline->isToday())
                                    <span class="text-error font-bold">• Hôm nay</span>
                                @endif
                            </td>
                            <td>
                                <x-ui.badge :color="$sv->is_active ? 'success' : 'neutral'" :pill="true">
                                    {{ $sv->is_active ? 'Đang mở' : 'Đã đóng' }}
                                </x-ui.badge>
                            </td>
                            <td class="text-on-surface-variant">{{ $sv->creator?->name ?? '—' }}</td>
                            <td class="text-right">
                                <form action="{{ route('surveys.destroy', $sv->id) }}" method="POST" class="inline" data-confirm="Xóa khảo sát \"{{ $sv->title }}\"? Lịch sử đã nộp của học viên vẫn được giữ.">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger-text" size="sm">Xóa</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="ballot" title="Chưa có đợt khảo sát nào." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>
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
