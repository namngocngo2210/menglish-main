<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.teacher-view') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">edit_attributes</span>
                        Đề xuất sửa giáo trình
                    </h1>
                    <p class="text-xs text-gray-500">Giáo viên gửi đề xuất sửa nội dung giáo trình / bài học lên Ban Học thuật duyệt.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" icon="checklist_rtl" :href="route('syllabus.versions')">Xem trạng thái đề xuất</x-ui.button>
                <x-ui.button icon="speed" :href="route('syllabus.teacher-adjust')">Xin điều chỉnh tiến độ</x-ui.button>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 5])

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        @can('syllabus.propose_adjustment')
        <div class="lg:col-span-5 flex flex-col gap-4 min-w-0">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm"
                 x-data="{ curriculum: @js((string) old('curriculum_id', '')), units: @js($curriculums->mapWithKeys(fn ($c) => [$c->id => $c->units->map(fn ($u) => ['id' => $u->id, 'label' => 'Buổi '.$u->unit_number.': '.$u->title])->values()])) }">
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                    <span class="material-symbols-outlined text-primary">post_add</span>
                    <h2 class="text-sm font-bold text-gray-900">Gửi đề xuất sửa mới</h2>
                </div>

                <form method="POST" action="{{ route('syllabus.proposals.store') }}" enctype="multipart/form-data" class="flex flex-col gap-4">
                    @csrf
                    <x-ui.field label="Giáo trình" name="curriculum_id" required>
                        <select name="curriculum_id" required x-model="curriculum" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm text-body-base">
                            <option value="">Chọn giáo trình...</option>
                            @foreach ($curriculums as $c)
                                <option value="{{ $c->id }}">{{ $c->title }} ({{ $c->version }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <x-ui.field label="Buổi học / Unit cần sửa" name="unit_id" hint="Bỏ trống nếu đề xuất áp dụng cho toàn bộ giáo trình.">
                        <select name="unit_id" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm text-body-base">
                            <option value="">-- Toàn bộ giáo trình --</option>
                            <template x-for="unit in (units[curriculum] || [])" :key="unit.id">
                                <option :value="unit.id" x-text="unit.label"></option>
                            </template>
                        </select>
                    </x-ui.field>

                    <x-ui.select name="proposal_type" label="Loại đề xuất" placeholder="-- Chọn loại --" :options="[
                        'Sửa lỗi chính tả / ngữ pháp trong bài giảng' => 'Sửa lỗi chính tả / ngữ pháp trong bài giảng',
                        'Cập nhật file audio / video bị lỗi' => 'Cập nhật file audio / video bị lỗi',
                        'Thay đổi độ dài / thời gian bài tập' => 'Thay đổi độ dài / thời gian bài tập',
                        'Bổ sung hoạt động / trò chơi tương tác' => 'Bổ sung hoạt động / trò chơi tương tác',
                        'Khác' => 'Khác',
                    ]" />
                    <x-ui.textarea name="old_content" label="Nội dung hiện tại trong giáo trình" rows="2" />
                    <x-ui.textarea name="new_content" label="Nội dung mới đề xuất" rows="3" required />
                    <x-ui.textarea name="reason" label="Lý do thay đổi" rows="2" />
                    <x-ui.field label="File đính kèm (tùy chọn)" name="attachment" hint="PDF, Word, PowerPoint, Excel, ảnh hoặc audio — tối đa 20 MB.">
                        <input type="file" name="attachment" class="block w-full text-xs text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-primary border border-dashed border-gray-300 rounded-xl p-2" />
                    </x-ui.field>

                    <x-ui.button type="submit" icon="send" class="w-full">Gửi đề xuất tới Ban Học thuật</x-ui.button>
                </form>
            </div>
        </div>
        @endcan

        <div class="lg:col-span-7 flex flex-col gap-4 min-w-0">
            <x-ui.data-table min-width="640px">
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">history_edu</span>
                        <h2 class="text-sm font-bold text-gray-900">Lịch sử đề xuất của bạn</h2>
                        <x-ui.badge>{{ $proposals->total() }} đề xuất</x-ui.badge>
                    </div>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Giáo trình / Buổi</th>
                            <th>Nội dung đề xuất</th>
                            <th>Ngày gửi</th>
                            <th class="text-right">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($proposals as $p)
                            <tr>
                                <td>
                                    <p class="font-bold text-gray-900">{{ $p->curriculum?->title }}</p>
                                    <p class="text-[11px] text-gray-400">{{ $p->unit ? 'Buổi '.$p->unit->unit_number.': '.$p->unit->title : 'Chung toàn giáo trình' }}</p>
                                </td>
                                <td class="max-w-xs">
                                    <a href="{{ route('syllabus.versions', ['proposal' => $p->id]) }}" class="text-gray-800 line-clamp-2 hover:text-primary">{{ $p->new_content }}</a>
                                    @if ($p->status === 'rejected' && $p->review_note)
                                        <p class="text-[11px] text-rose-600 mt-1">Lý do từ chối: {{ $p->review_note }}</p>
                                    @endif
                                </td>
                                <td class="font-mono text-gray-500 whitespace-nowrap">{{ $p->created_at->format('d/m/Y') }}</td>
                                <td class="text-right whitespace-nowrap"><x-ui.badge :color="$p->status_color">{{ $p->status_label }}</x-ui.badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-ui.empty-state icon="edit_note" title="Bạn chưa gửi đề xuất nào" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$proposals" unit="đề xuất" /></x-slot:footer>
            </x-ui.data-table>
        </div>
    </div>
</x-app-layout>
