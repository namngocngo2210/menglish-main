<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.teacher-view') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="font-h1 text-h1 text-on-surface">Đề xuất sửa giáo trình</h1>
                    <p class="font-body-base text-on-surface-variant">Gửi đề xuất sửa lỗi hoặc nội dung giáo trình lên Ban Học thuật.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" icon="checklist_rtl" :href="route('syllabus.versions')">Xem trạng thái đề xuất</x-ui.button>
                <x-ui.button icon="speed" :href="route('syllabus.teacher-adjust')">Xin điều chỉnh tiến độ</x-ui.button>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 5])

    {{-- Mockup 03_Cong_Giao_Vien/09: form (giáo trình, buổi học tùy chọn, mô tả thay đổi) + Lịch sử đề xuất có lọc. --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        @can('syllabus.propose_adjustment')
        <div class="lg:col-span-5 flex flex-col gap-4 min-w-0">
            <div class="bg-surface-container-lowest rounded-xl border border-outline-variant p-lg shadow-sm"
                 x-data="{ curriculum: @js((string) old('curriculum_id', '')), lessons: @js($curriculums->mapWithKeys(fn ($c) => [$c->id => $c->lessons->map(fn ($l) => ['id' => $l->id, 'label' => 'Buổi '.$l->session_no.': '.$l->title.($l->unit ? ' (Unit '.$l->unit->unit_number.')' : '')])->values()])) }">
                <form method="POST" action="{{ route('syllabus.proposals.store') }}" enctype="multipart/form-data" class="flex flex-col gap-md">
                    @csrf
                    <x-ui.field label="Chọn giáo trình" name="curriculum_id" required>
                        <select name="curriculum_id" required x-model="curriculum" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                            <option value="">Chọn giáo trình...</option>
                            @foreach ($curriculums as $c)
                                <option value="{{ $c->id }}">{{ $c->title }} ({{ $c->version }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <x-ui.field label="Chọn buổi học (Tùy chọn)" name="lesson_id" hint="Bỏ trống nếu đề xuất áp dụng chung cho cả giáo trình.">
                        <select name="lesson_id" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                            <option value="">Chọn buổi học...</option>
                            <template x-for="lesson in (lessons[curriculum] || [])" :key="lesson.id">
                                <option :value="lesson.id" x-text="lesson.label"></option>
                            </template>
                        </select>
                    </x-ui.field>

                    <x-ui.textarea name="new_content" label="Mô tả thay đổi đề xuất" rows="5" required placeholder="Nhập chi tiết nội dung cần sửa đổi..." />

                    <details class="group rounded-lg border border-outline-variant bg-surface-container-low" @if ($errors->hasAny(['old_content', 'reason', 'attachment', 'proposal_type'])) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between px-md py-sm font-body-medium text-body-medium text-on-surface">
                            Thông tin bổ sung (tùy chọn)
                            <span class="material-symbols-outlined text-[18px] text-on-surface-variant transition group-open:rotate-180">expand_more</span>
                        </summary>
                        <div class="flex flex-col gap-md border-t border-outline-variant p-md">
                            <x-ui.select name="proposal_type" label="Loại đề xuất" placeholder="-- Chọn loại --" :options="[
                                'Sửa lỗi chính tả / ngữ pháp trong bài giảng' => 'Sửa lỗi chính tả / ngữ pháp trong bài giảng',
                                'Cập nhật file audio / video bị lỗi' => 'Cập nhật file audio / video bị lỗi',
                                'Thay đổi độ dài / thời gian bài tập' => 'Thay đổi độ dài / thời gian bài tập',
                                'Bổ sung hoạt động / trò chơi tương tác' => 'Bổ sung hoạt động / trò chơi tương tác',
                                'Khác' => 'Khác',
                            ]" />
                            <x-ui.textarea name="old_content" label="Nội dung hiện tại trong giáo trình" rows="2" />
                            <x-ui.textarea name="reason" label="Lý do thay đổi" rows="2" />
                            <x-ui.field label="File đính kèm" name="attachment" hint="PDF, Word, PowerPoint, Excel, ảnh hoặc audio — tối đa 20 MB.">
                                <input type="file" name="attachment" class="block w-full text-xs text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-primary border border-dashed border-gray-300 rounded-xl p-2" />
                            </x-ui.field>
                        </div>
                    </details>

                    <div class="flex justify-end">
                        <x-ui.button type="submit" icon="send">Gửi đề xuất</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
        @endcan

        <div class="lg:col-span-7 flex flex-col gap-4 min-w-0">
            <x-ui.data-table min-width="640px">
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <h2 class="font-h3 text-h3 text-on-surface">Lịch sử đề xuất</h2>
                        <x-ui.badge>{{ $proposals->total() }} đề xuất</x-ui.badge>
                    </div>
                    <form method="GET" class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px] text-on-surface-variant">filter_list</span>
                        <x-ui.select name="status" :value="$status" placeholder="Lọc: tất cả trạng thái" :options="\App\Models\SyllabusChangeProposal::STATUS_LABELS" onchange="this.form.submit()" />
                    </form>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Giáo trình / Buổi</th>
                            <th>Nội dung đề xuất</th>
                            <th>Ngày gửi</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($proposals as $p)
                            <tr>
                                <td>
                                    <p class="font-body-medium text-body-medium text-on-surface">{{ $p->curriculum?->title }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $p->lesson ? 'Buổi '.$p->lesson->session_no : ($p->unit ? 'Unit '.$p->unit->unit_number : 'Chung') }}</p>
                                </td>
                                <td class="max-w-xs">
                                    <a href="{{ route('syllabus.versions', ['proposal' => $p->id]) }}" class="text-on-surface line-clamp-2 hover:text-primary">{{ $p->new_content }}</a>
                                </td>
                                <td class="font-mono text-on-surface-variant whitespace-nowrap">{{ $p->created_at->format('d/m/Y') }}</td>
                                <td class="whitespace-nowrap">
                                    <x-ui.badge :color="$p->status_color">{{ $p->status_label }}</x-ui.badge>
                                    @if ($p->status === 'rejected' && $p->review_note)
                                        <p class="mt-1 max-w-[220px] whitespace-normal font-caption text-caption text-error">{{ $p->review_note }}</p>
                                    @endif
                                </td>
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
