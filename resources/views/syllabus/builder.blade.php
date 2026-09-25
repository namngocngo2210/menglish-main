<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">edit_document</span>
                        Soạn syllabus theo chặng
                    </h1>
                    <p class="text-xs text-gray-500">Chọn giáo trình, cập nhật thông tin chặng và soạn nội dung chi tiết từng buổi học.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" icon="assignment_ind" :href="route('syllabus.assignments')">Giao chặng</x-ui.button>
                @can('syllabus.manage')
                    <x-ui.button icon="library_add" x-data @click="$dispatch('open-modal', 'new-curriculum')">Tạo giáo trình mới</x-ui.button>
                @endcan
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 2])

    @php($canManage = auth()->user()->can('syllabus.manage'))

    @can('syllabus.manage')
        <x-ui.modal name="new-curriculum" title="Tạo giáo trình mới" max-width="xl" :show="$errors->has('code') && ! old('_curriculum_id')">
            <form id="new-curriculum-form" method="POST" action="{{ route('syllabus.curriculums.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 p-md">
                @csrf
                <x-ui.input name="code" label="Mã giáo trình" required :value="'CUR-'.strtoupper(\Illuminate\Support\Str::random(4))" />
                <x-ui.input name="version" label="Phiên bản" required value="v1.0" />
                <x-ui.input name="title" label="Tên giáo trình" required class="md:col-span-2" placeholder="IELTS Foundation - Level 1" />
                <x-ui.select name="course_id" label="Khóa học áp dụng" placeholder="-- Chọn khóa học --" :options="$courses->pluck('name', 'id')" />
                <x-ui.input name="stage_name" label="Chặng học" placeholder="Chặng 1: Xây dựng nền tảng" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'new-curriculum')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="new-curriculum-form" icon="save">Tạo giáo trình</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan

    <div class="max-w-5xl mx-auto space-y-6 pb-10">
        {{-- Chọn giáo trình đang soạn --}}
        <section class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm">
            <form method="GET" action="{{ route('syllabus.builder') }}" class="flex flex-col sm:flex-row sm:items-end gap-3">
                <div class="flex-1">
                    <x-ui.select name="curriculum" label="Giáo trình đang soạn" :value="$curriculum?->id" onchange="this.form.submit()"
                                 :options="$curriculums->mapWithKeys(fn ($c) => [$c->id => $c->title.' ('.$c->code.' · '.$c->version.')'])" />
                </div>
                <noscript><x-ui.button type="submit" variant="secondary">Mở</x-ui.button></noscript>
            </form>
        </section>

        @if (! $curriculum)
            <x-ui.empty-state icon="library_books" title="Chưa có giáo trình nào" description="Tạo giáo trình đầu tiên để bắt đầu soạn bài.">
                @can('syllabus.manage')
                    <x-ui.button icon="library_add" x-data @click="$dispatch('open-modal', 'new-curriculum')">Tạo giáo trình mới</x-ui.button>
                @endcan
            </x-ui.empty-state>
        @else
            {{-- Thông tin chung chặng / giáo trình --}}
            <section class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between gap-2 mb-5 pb-3 border-b border-gray-100">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">info</span>
                        <h2 class="text-sm font-bold text-gray-900">Thông tin chung chặng học</h2>
                    </div>
                    @if ($canManage)
                        <form method="POST" action="{{ route('syllabus.curriculums.destroy', $curriculum->id) }}" data-confirm="Xóa giáo trình {{ $curriculum->title }} cùng toàn bộ bài học và tài liệu?">
                            @csrf @method('DELETE')
                            <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete">Xóa giáo trình</x-ui.button>
                        </form>
                    @endif
                </div>

                <form method="POST" action="{{ route('syllabus.curriculums.update', $curriculum->id) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf @method('PUT')
                    <input type="hidden" name="_curriculum_id" value="{{ $curriculum->id }}">
                    <x-ui.input name="title" label="Tên giáo trình" required :value="$curriculum->title" :disabled="! $canManage" />
                    <x-ui.input name="stage_name" label="Tên chặng học" :value="$curriculum->stage_name" placeholder="Chặng 1: Xây dựng nền tảng (Foundation)" :disabled="! $canManage" />
                    <x-ui.input name="code" label="Mã giáo trình" required :value="$curriculum->code" :disabled="! $canManage" />
                    <x-ui.input name="version" label="Phiên bản" required :value="$curriculum->version" :disabled="! $canManage" />
                    <x-ui.select name="course_id" label="Khóa học áp dụng" placeholder="-- Không gắn khóa --" :value="$curriculum->course_id" :options="$courses->pluck('name', 'id')" :disabled="! $canManage" />
                    <x-ui.select name="unlock_policy" label="Chính sách mở khóa" placeholder="-- Chưa chọn --" :value="$curriculum->unlock_policy" :options="\App\Models\SyllabusCurriculum::UNLOCK_POLICIES" :disabled="! $canManage" />
                    <div class="md:col-span-2">
                        <x-ui.input name="overview_link" type="url" label="Link ảnh / tài liệu tổng quan chặng" :value="$curriculum->overview_link" placeholder="https://..." :disabled="! $canManage" />
                        @if ($curriculum->overview_link)
                            <a href="{{ $curriculum->overview_link }}" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline">
                                <span class="material-symbols-outlined text-[14px]">open_in_new</span> Mở tài liệu tổng quan
                            </a>
                        @endif
                    </div>
                    <div class="md:col-span-2">
                        <x-ui.textarea name="description" label="Mô tả" rows="2" :value="$curriculum->description" :disabled="! $canManage" />
                    </div>
                    @if ($canManage)
                        <div class="md:col-span-2 flex justify-end">
                            <x-ui.button type="submit" icon="save">Lưu thông tin chặng</x-ui.button>
                        </div>
                    @endif
                </form>
            </section>

            {{-- Form soạn / sửa bài học --}}
            @if ($canManage)
            <section id="unit-form" class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm relative overflow-hidden">
                <div class="absolute left-0 top-0 bottom-0 w-1 bg-primary-container"></div>
                <div class="flex items-center justify-between mb-5 pb-3 border-b border-gray-100">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">{{ $editUnit ? 'edit' : 'add_circle' }}</span>
                        <h2 class="text-sm font-bold text-gray-900">{{ $editUnit ? 'Sửa buổi #'.$editUnit->unit_number.': '.$editUnit->title : 'Soạn bài học (Unit) mới' }}</h2>
                    </div>
                    <x-ui.badge color="primary">Đã có {{ $units->count() }} buổi</x-ui.badge>
                </div>

                <form action="{{ $editUnit ? route('syllabus.units.update', $editUnit->id) : route('syllabus.units.store') }}" method="POST" class="space-y-4">
                    @csrf
                    @if ($editUnit)
                        @method('PUT')
                    @else
                        <input type="hidden" name="curriculum_id" value="{{ $curriculum->id }}" />
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                        <x-ui.input type="number" name="unit_number" label="Buổi số" required min="1" :value="$editUnit?->unit_number ?? (($units->max('unit_number') ?? 0) + 1)" />
                        <div class="sm:col-span-3">
                            <x-ui.input name="title" label="Tiêu đề bài học" required :value="$editUnit?->title" placeholder="Buổi 01: Introduction to IELTS & Greetings" />
                        </div>
                    </div>
                    <x-ui.textarea name="objectives" label="Mục tiêu buổi học" rows="2" :value="$editUnit?->objectives" />
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-ui.textarea name="vocabulary_focus" label="Trọng tâm từ vựng" rows="4" :value="$editUnit?->vocabulary_focus" />
                        <x-ui.textarea name="grammar_focus" label="Trọng tâm ngữ pháp" rows="4" :value="$editUnit?->grammar_focus" />
                    </div>
                    <x-ui.textarea name="homework_guide" label="Bài tập về nhà" rows="3" :value="$editUnit?->homework_guide" />

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-gray-100">
                        @if ($editUnit)
                            <x-ui.button variant="secondary" :href="route('syllabus.builder', ['curriculum' => $curriculum->id])">Hủy sửa</x-ui.button>
                        @endif
                        <x-ui.button type="submit" icon="save">{{ $editUnit ? 'Lưu thay đổi' : 'Lưu buổi học' }}</x-ui.button>
                    </div>
                </form>
            </section>
            @endif

            {{-- Danh sách buổi học --}}
            <section class="space-y-4">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">menu_book</span>
                    <h2 class="text-sm font-bold text-gray-900">Danh sách buổi học trong giáo trình ({{ $units->count() }})</h2>
                </div>

                @forelse ($units as $u)
                    <div class="bg-white border {{ $editUnit?->id === $u->id ? 'border-primary-container ring-1 ring-primary-container/30' : 'border-gray-200' }} rounded-2xl overflow-hidden shadow-sm relative">
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-primary-container"></div>
                        <div class="p-5">
                            <div class="flex justify-between items-start gap-3 mb-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-orange-100 text-primary font-bold flex items-center justify-center text-xs">{{ $u->unit_number }}</div>
                                    <div>
                                        <h3 class="text-sm font-bold text-gray-900">{{ $u->title }}</h3>
                                        <span class="text-[11px] text-gray-400">Buổi #{{ $u->unit_number }} · cập nhật {{ $u->updated_at?->format('d/m/Y H:i') }}</span>
                                    </div>
                                </div>
                                @if ($canManage)
                                    <div class="flex items-center gap-1">
                                        <x-ui.button variant="ghost" size="sm" icon="edit" :href="route('syllabus.builder', ['curriculum' => $curriculum->id, 'edit_unit' => $u->id]).'#unit-form'">Sửa</x-ui.button>
                                        <form method="POST" action="{{ route('syllabus.units.destroy', $u->id) }}" data-confirm="Xóa buổi {{ $u->title }}?">
                                            @csrf @method('DELETE')
                                            <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete">Xóa</x-ui.button>
                                        </form>
                                    </div>
                                @endif
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                                <div class="bg-gray-50 rounded-xl p-3.5 border border-gray-100">
                                    <div class="font-semibold text-gray-800 mb-1">Mục tiêu buổi học</div>
                                    <p class="text-gray-600 leading-relaxed whitespace-pre-line">{{ $u->objectives ?: 'Chưa cập nhật' }}</p>
                                </div>
                                <div class="bg-gray-50 rounded-xl p-3.5 border border-gray-100">
                                    <div class="font-semibold text-gray-800 mb-1">Bài tập về nhà</div>
                                    <p class="text-gray-600 leading-relaxed whitespace-pre-line">{{ $u->homework_guide ?: 'Chưa cập nhật' }}</p>
                                </div>
                                @if ($u->vocabulary_focus || $u->grammar_focus)
                                    <div class="md:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-3 bg-orange-50/40 rounded-xl p-3.5 border border-orange-200/50 text-gray-700">
                                        <div>
                                            <span class="font-medium text-gray-900 block text-[11px] uppercase tracking-wider mb-0.5">Từ vựng</span>
                                            <p class="text-[11px] whitespace-pre-line">{{ $u->vocabulary_focus ?: '—' }}</p>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-900 block text-[11px] uppercase tracking-wider mb-0.5">Ngữ pháp</span>
                                            <p class="text-[11px] whitespace-pre-line">{{ $u->grammar_focus ?: '—' }}</p>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center bg-white rounded-2xl border border-gray-200 text-gray-400 text-xs">
                        Chưa có buổi học nào trong giáo trình này.
                    </div>
                @endforelse
            </section>
        @endif

        <div class="flex items-center justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('syllabus.documents')">Quay lại</x-ui.button>
            <x-ui.button icon="arrow_forward" :href="route('syllabus.assignments')">Tiếp tục: Giao chặng</x-ui.button>
        </div>
    </div>
</x-app-layout>
