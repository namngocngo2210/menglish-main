<x-app-layout>
    <x-ui.page-header title="Quản lý Đợt Khảo sát Chất lượng" icon="ballot">
        <x-slot:actions>
            <x-ui.button icon="add_circle" x-on:click="$dispatch('open-modal', 'new-survey')">Tạo đợt khảo sát</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-4">
        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

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
                            <td class="max-w-md">
                                <p class="font-semibold text-on-surface">{{ $sv->title }}</p>
                                @if ($sv->description)
                                    <p class="line-clamp-2 text-xs text-on-surface-variant">{{ $sv->description }}</p>
                                @endif
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
                            <td class="text-right whitespace-nowrap">
                                <x-ui.button variant="ghost" size="sm" icon="edit" x-on:click="$dispatch('open-modal', 'edit-survey-{{ $sv->id }}')">Sửa</x-ui.button>
                                <form action="{{ route('surveys.destroy', $sv->id) }}" method="POST" class="inline" data-confirm="Xóa khảo sát &quot;{{ $sv->title }}&quot;? Lịch sử đã nộp của học viên vẫn được giữ." data-confirm-label="Xóa" data-confirm-danger>
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger-text" size="sm">Xóa</x-ui.button>
                                </form>

                                @php($editKey = 'edit-survey-'.$sv->id)
                                @php($reopen = old('_modal') === $editKey)
                                <x-ui.modal :name="$editKey" :title="'Sửa khảo sát'" class="text-left whitespace-normal" :show="$reopen">
                                    <form id="{{ $editKey }}-form" action="{{ route('surveys.update', $sv->id) }}" method="POST" class="space-y-md">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="_modal" value="{{ $editKey }}">
                                        {{-- Không dùng old() mặc định của component: nhiều modal sửa cùng tên trường trên 1 trang. --}}
                                        <x-ui.field label="Tiêu đề khảo sát" :name="$reopen ? 'title' : null" required>
                                            <input type="text" name="title" value="{{ $reopen ? old('title') : $sv->title }}" required class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                                        </x-ui.field>
                                        <x-ui.field label="Hạn hoàn thành" :name="$reopen ? 'deadline' : null">
                                            <input type="date" name="deadline" value="{{ $reopen ? old('deadline') : $sv->deadline?->format('Y-m-d') }}" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                                        </x-ui.field>
                                        <x-ui.field label="Mô tả / câu hỏi hướng dẫn" :name="$reopen ? 'description' : null">
                                            <textarea name="description" rows="3" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">{{ $reopen ? old('description') : $sv->description }}</textarea>
                                        </x-ui.field>
                                        <label class="flex items-center gap-sm font-body-medium text-body-medium text-on-surface">
                                            <input type="hidden" name="is_active" value="0" />
                                            <input type="checkbox" name="is_active" value="1" @checked($reopen ? old('is_active') : $sv->is_active) class="rounded border-outline-variant text-tertiary focus:ring-tertiary" />
                                            Đang mở
                                        </label>
                                    </form>
                                    <x-slot:footer>
                                        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $editKey }}')">Hủy</x-ui.button>
                                        <x-ui.button type="submit" form="{{ $editKey }}-form" icon="save">Lưu</x-ui.button>
                                    </x-slot:footer>
                                </x-ui.modal>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="ballot" title="Chưa có đợt khảo sát nào." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>
    </div>

    {{-- Tạo khảo sát mới --}}
    <x-ui.modal name="new-survey" title="Tạo đợt khảo sát mới" :show="old('_modal') === 'new-survey'">
        <form id="new-survey-form" action="{{ route('surveys.store') }}" method="POST" class="space-y-md">
            @csrf
            <input type="hidden" name="_modal" value="new-survey">
            <x-ui.input name="title" label="Tiêu đề khảo sát" required placeholder="VD: Đánh giá chất lượng cơ sở vật chất tháng 10" />
            <x-ui.date name="deadline" label="Hạn hoàn thành" min="{{ now()->toDateString() }}" />
            <x-ui.textarea name="description" label="Mô tả / câu hỏi hướng dẫn (tùy chọn)" rows="3" placeholder="VD: Đánh giá phòng học, thiết bị, thái độ hỗ trợ của học vụ..." />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'new-survey')">Hủy</x-ui.button>
            <x-ui.button type="submit" form="new-survey-form" icon="add">Tạo khảo sát</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

</x-app-layout>
