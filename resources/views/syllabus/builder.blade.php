<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="font-h1 text-h1 text-on-surface">Soạn syllabus theo chặng</h1>
                    <p class="font-body-base text-on-surface-variant">Thiết lập cấu trúc chương trình học và nội dung chi tiết từng buổi.</p>
                    <p class="font-caption text-caption text-on-surface-variant">Giáo trình → Chặng (Big Test cuối chặng) → Unit → Buổi. Số buổi đánh liên tục trong cả giáo trình.</p>
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


    @php($canManage = auth()->user()->can('syllabus.manage'))

    @can('syllabus.manage')
        <x-ui.modal name="new-curriculum" title="Tạo giáo trình mới" max-width="xl" :show="$errors->has('code') && ! old('_curriculum_id')">
            <form id="new-curriculum-form" method="POST" action="{{ route('syllabus.curriculums.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 p-md">
                @csrf
                <x-ui.input name="code" label="Mã giáo trình" required :value="'CUR-'.strtoupper(\Illuminate\Support\Str::random(4))" />
                <x-ui.input name="version" label="Phiên bản" required value="v1.0" />
                <x-ui.input name="title" label="Tên giáo trình" required class="md:col-span-2" placeholder="IELTS Foundation - Level 1" />
                <x-ui.select name="course_id" label="Khóa học áp dụng" placeholder="-- Chọn khóa học --" :options="$courses->pluck('name', 'id')" />
                <x-ui.input name="stage_name" label="Tên chặng đầu tiên" placeholder="Chặng 1: Xây dựng nền tảng" hint="Để trống sẽ đặt là “Chặng 1”." />
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
            <x-ui.empty-state icon="library_books" title="Chưa có giáo trình nào" description="Tạo giáo trình đầu tiên để bắt đầu soạn chặng, unit và buổi học.">
                @can('syllabus.manage')
                    <x-ui.button icon="library_add" x-data @click="$dispatch('open-modal', 'new-curriculum')">Tạo giáo trình mới</x-ui.button>
                @endcan
            </x-ui.empty-state>
        @else
            @php($builderUrl = fn (array $params = []) => route('syllabus.builder', ['curriculum' => $curriculum->id] + $params))

            {{-- Thông tin giáo trình + Trình độ áp dụng --}}
            <section class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between gap-2 mb-5 pb-3 border-b border-gray-100">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">info</span>
                        <h2 class="text-sm font-bold text-gray-900">Thông tin giáo trình</h2>
                        <x-ui.badge color="primary">{{ $stages->count() }} chặng · {{ $units->count() }} unit · {{ $lessons->count() }} buổi</x-ui.badge>
                    </div>
                    @if ($canManage)
                        <form method="POST" action="{{ route('syllabus.curriculums.destroy', $curriculum->id) }}" data-confirm="Xóa giáo trình {{ $curriculum->title }} cùng toàn bộ chặng, unit, buổi học và tài liệu?">
                            @csrf @method('DELETE')
                            <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete">Xóa giáo trình</x-ui.button>
                        </form>
                    @endif
                </div>

                <form method="POST" action="{{ route('syllabus.curriculums.update', $curriculum->id) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf @method('PUT')
                    <input type="hidden" name="_curriculum_id" value="{{ $curriculum->id }}">
                    <input type="hidden" name="levels_submitted" value="1">
                    <x-ui.input name="title" label="Tên giáo trình" required :value="$curriculum->title" :disabled="! $canManage" />
                    <x-ui.input name="code" label="Mã giáo trình" required :value="$curriculum->code" :disabled="! $canManage" />
                    <x-ui.input name="version" label="Phiên bản" required :value="$curriculum->version" :disabled="! $canManage" />
                    <x-ui.select name="course_id" label="Khóa học áp dụng" placeholder="-- Không gắn khóa --" :value="$curriculum->course_id" :options="$courses->pluck('name', 'id')" :disabled="! $canManage" />
                    <div class="md:col-span-2">
                        <x-ui.field label="Trình độ áp dụng" hint="Lớp thuộc trình độ được chọn sẽ mặc định học giáo trình này khi mở chặng.">
                            @php($selectedLevels = collect(old('level_ids', $curriculum->levels->pluck('id')->all()))->map(fn ($id) => (int) $id))
                            <div class="flex flex-wrap gap-2">
                                @forelse ($levels as $level)
                                    <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-outline-variant text-xs {{ $level->syllabus_curriculum_id && $level->syllabus_curriculum_id !== $curriculum->id ? 'text-gray-400' : 'text-gray-700' }}">
                                        <input type="checkbox" name="level_ids[]" value="{{ $level->id }}" class="rounded border-gray-300 text-primary focus:ring-primary-container"
                                               @checked($selectedLevels->contains($level->id)) @disabled(! $canManage)>
                                        {{ $level->name }} <span class="font-mono text-[10px] text-gray-400">{{ $level->code }}</span>
                                        @if ($level->syllabus_curriculum_id && $level->syllabus_curriculum_id !== $curriculum->id)
                                            <span class="text-[10px]" title="Đang gắn giáo trình khác — chọn sẽ chuyển sang giáo trình này">(đang dùng GT khác)</span>
                                        @endif
                                    </label>
                                @empty
                                    <span class="text-xs text-gray-400">Chưa có trình độ nào — tạo ở màn Cấu hình trình độ.</span>
                                @endforelse
                            </div>
                        </x-ui.field>
                    </div>
                    <div class="md:col-span-2">
                        <x-ui.textarea name="description" label="Mô tả" rows="2" :value="$curriculum->description" :disabled="! $canManage" />
                    </div>
                    @if ($canManage)
                        <div class="md:col-span-2 flex justify-end">
                            <x-ui.button type="submit" icon="save">Lưu thông tin giáo trình</x-ui.button>
                        </div>
                    @endif
                </form>
            </section>

            {{-- Ô soạn thảo chặng / unit / buổi --}}
            @if ($editor)
                @php($model = $editor['model'])
                <section id="editor" class="bg-white border border-primary-container/40 rounded-2xl p-6 shadow-sm relative overflow-hidden">
                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-primary-container"></div>
                    @if ($editor['type'] === 'stage')
                        <div class="flex flex-wrap items-center gap-sm mb-lg">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1;">info</span>
                            <h2 class="font-h3 text-h3">Thông tin chung chặng học</h2>
                            <x-ui.badge color="primary">{{ $model ? 'Sửa '.$model->label : 'Thêm chặng mới (Chặng '.($stages->max('position') + 1).')' }}</x-ui.badge>
                        </div>
                        <form method="POST" action="{{ $model ? route('syllabus.stages.update', $model->id) : route('syllabus.stages.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-lg"
                              x-data="{ link: @js((string) old('overview_link', $model?->overview_link ?? '')), preview: @js((string) old('overview_link', $model?->overview_link ?? '')), isImage(u) { return /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(u || '') } }">
                            @csrf
                            @if ($model) @method('PUT') @else <input type="hidden" name="curriculum_id" value="{{ $curriculum->id }}"> @endif
                            <x-ui.input name="name" label="Tên chặng học" required :value="$model?->name" placeholder="Ví dụ: Chặng 1: Xây dựng nền tảng" />
                            {{-- A6 Q4: chặng chỉ tự mở khi Big Test chặng trước được duyệt & gửi PH — không cho chọn "mở theo tuần / thủ công". --}}
                            <x-ui.field label="Chính sách mở khóa" hint="Mỗi lớp 1 chặng mở; Học thuật chỉ đóng/chuyển chặng tay khi có ngoại lệ.">
                                <select disabled class="w-full rounded-lg border border-outline-variant bg-surface-container-low py-sm pl-md pr-xl font-body-base text-body-base text-on-surface">
                                    <option selected>Hoàn thành Big Test chặng trước (duyệt &amp; gửi PH) mới được mở</option>
                                </select>
                            </x-ui.field>
                            <div class="md:col-span-2 space-y-sm">
                                <x-ui.field label="Link ảnh/tài liệu tổng quan chặng (overview_link)" name="overview_link">
                                    <div class="flex gap-md">
                                        <input type="url" name="overview_link" x-model="link" placeholder="https://example.com/image-syllabus.jpg"
                                               class="flex-1 min-w-0 rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                                        <x-ui.button variant="secondary" icon="visibility" @click="preview = link">Xem thử</x-ui.button>
                                    </div>
                                </x-ui.field>
                                <div class="w-full h-64 rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low flex flex-col items-center justify-center overflow-hidden relative">
                                    <template x-if="preview && isImage(preview)">
                                        <img :src="preview" alt="Ảnh tổng quan chặng" class="absolute inset-0 w-full h-full object-contain bg-white">
                                    </template>
                                    <template x-if="preview && ! isImage(preview)">
                                        <a :href="preview" target="_blank" rel="noopener" class="inline-flex items-center gap-xs font-body-medium text-primary hover:underline">
                                            <span class="material-symbols-outlined">open_in_new</span>Mở tài liệu tổng quan chặng
                                        </a>
                                    </template>
                                    <div x-show="! preview" class="flex flex-col items-center text-on-surface-variant text-center px-lg">
                                        <span class="material-symbols-outlined text-[48px] mb-xs">image</span>
                                        <p class="font-body-base font-medium">Khu vực hiển thị preview ảnh mục lục tổng quan</p>
                                        <p class="font-caption text-caption">Nhập link bên trên để hiển thị hình ảnh</p>
                                    </div>
                                </div>
                            </div>
                            <div class="md:col-span-2"><x-ui.textarea name="description" label="Mục tiêu / đầu ra của chặng" rows="2" :value="$model?->description" /></div>
                            <x-ui.input name="big_test_title" label="Big Test cuối chặng" :value="$model?->big_test_title" placeholder="Big Test chặng 2 — 4 kỹ năng" />
                            <x-ui.textarea name="big_test_note" label="Ghi chú Big Test (dạng đề, thời lượng, đầu ra)" rows="2" :value="$model?->big_test_note" />
                            {{-- Thanh hành động dính đáy như mockup (không có "tự động lưu nháp": chỉ lưu khi bấm nút). --}}
                            <div class="md:col-span-2 sticky bottom-0 z-10 -mx-6 -mb-6 mt-md bg-surface-container-lowest border-t border-outline-variant px-6 py-md flex items-center justify-end gap-md shadow-[0_-4px_24px_rgba(0,0,0,0.06)]">
                                <x-ui.button variant="secondary" :href="$builderUrl()">Hủy</x-ui.button>
                                <x-ui.button type="submit" icon="save">{{ $model ? 'Lưu chặng học' : 'Thêm chặng học' }}</x-ui.button>
                            </div>
                        </form>
                    @elseif ($editor['type'] === 'unit')
                        @php($stage = $model?->stage ?? $editor['parent'])
                        <h2 class="text-sm font-bold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">{{ $model ? 'edit' : 'add_circle' }}</span>
                            {{ $model ? 'Sửa Unit '.$model->unit_number.': '.$model->title : 'Thêm Unit vào '.$stage?->label }}
                        </h2>
                        <form method="POST" action="{{ $model ? route('syllabus.units.update', $model->id) : route('syllabus.units.store') }}" class="space-y-4">
                            @csrf
                            @if ($model) @method('PUT') @else <input type="hidden" name="curriculum_id" value="{{ $curriculum->id }}"> @endif
                            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                                <x-ui.input type="number" name="unit_number" label="Unit số" required min="1" :value="$model?->unit_number ?? (($units->max('unit_number') ?? 0) + 1)" />
                                <div class="sm:col-span-3"><x-ui.input name="title" label="Tên Unit" required :value="$model?->title" placeholder="Unit 3: Environment & Climate Change" /></div>
                            </div>
                            <x-ui.select name="stage_id" label="Thuộc chặng" required :value="$stage?->id" :options="$stages->pluck('label', 'id')" />
                            <x-ui.textarea name="objectives" label="Mô tả / mục tiêu Unit" rows="2" :value="$model?->objectives" />
                            <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                                <x-ui.button variant="secondary" :href="$builderUrl()">Hủy</x-ui.button>
                                <x-ui.button type="submit" icon="save">{{ $model ? 'Lưu Unit' : 'Thêm Unit' }}</x-ui.button>
                            </div>
                        </form>
                    @else
                        @php($unit = $model?->unit ?? $editor['parent'])
                        <h2 class="text-sm font-bold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">{{ $model ? 'edit' : 'add_circle' }}</span>
                            {{ $model ? 'Sửa Buổi '.$model->session_no.': '.$model->title : 'Thêm buổi vào Unit '.$unit?->unit_number.': '.$unit?->title }}
                        </h2>
                        <form method="POST" action="{{ $model ? route('syllabus.lessons.update', $model->id) : route('syllabus.lessons.store') }}" class="space-y-4">
                            @csrf
                            @if ($model) @method('PUT') @endif
                            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                                <x-ui.input type="number" name="session_no" label="Buổi số" required min="1" hint="Đánh liên tục trong cả giáo trình" :value="$model?->session_no ?? (($lessons->max('session_no') ?? 0) + 1)" />
                                <div class="sm:col-span-3"><x-ui.input name="title" label="Tiêu đề buổi học" required :value="$model?->title" placeholder="Buổi 01: Introduction to IELTS & Greetings" /></div>
                            </div>
                            <x-ui.select name="unit_id" label="Thuộc Unit" required :value="$unit?->id"
                                         :options="$units->mapWithKeys(fn ($u) => [$u->id => 'Unit '.$u->unit_number.': '.$u->title])" />
                            <x-ui.textarea name="objectives" label="Mục tiêu buổi học (Target)" rows="3" :value="$model?->objectives" placeholder="Người học cần đạt được điều gì sau buổi này..." />
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <x-ui.textarea name="content" label="Nội dung bài học chính" rows="7" :value="$model?->content" placeholder="Nhập nội dung chi tiết (hoạt động trên lớp)..." />
                                <x-ui.textarea name="homework_guide" label="Bài tập về nhà (Homework)" rows="7" :value="$model?->homework_guide" placeholder="Ghi chú bài tập hoặc link bài tập..." />
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <x-ui.textarea name="vocabulary_focus" label="Trọng tâm từ vựng" rows="2" :value="$model?->vocabulary_focus" />
                                <x-ui.textarea name="grammar_focus" label="Trọng tâm ngữ pháp" rows="2" :value="$model?->grammar_focus" />
                            </div>
                            <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                                <x-ui.button variant="secondary" :href="$builderUrl()">Hủy</x-ui.button>
                                <x-ui.button type="submit" icon="save">{{ $model ? 'Lưu buổi học' : 'Thêm buổi học vào chặng' }}</x-ui.button>
                            </div>
                        </form>
                    @endif
                </section>
            @endif

            {{-- Cây Chặng → Unit → Buổi --}}
            <section class="space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">account_tree</span>
                        <h2 class="text-sm font-bold text-gray-900">Chặng học của giáo trình</h2>
                    </div>
                    @if ($canManage)
                        <x-ui.button size="sm" icon="add" :href="$builderUrl(['new_stage' => 1]).'#editor'">Thêm chặng</x-ui.button>
                    @endif
                </div>
                <x-ui.alert type="info">Mỗi lớp chỉ học 1 chặng tại một thời điểm. Khi Big Test của chặng được duyệt và gửi kết quả cho phụ huynh, chặng đóng và chặng kế tiếp (theo thứ tự dưới đây) tự mở.</x-ui.alert>

                @foreach ($stages as $stage)
                    @php($openClasses = $openClassesByStage->get($stage->id, collect()))
                    <article class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                        <header class="p-5 border-b border-gray-100 bg-gray-50/60 flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                            <div class="flex items-start gap-3 min-w-0">
                                <div class="w-9 h-9 shrink-0 rounded-xl bg-primary-container/10 text-primary font-bold flex items-center justify-center text-sm">{{ $stage->position }}</div>
                                <div class="min-w-0">
                                    @php($stageLessonCount = $stage->units->sum(fn ($u) => $u->lessons->count()))
                                    <h3 class="font-h3 text-h3 text-on-surface flex flex-wrap items-center gap-sm">{{ $stage->label }}
                                        <span class="px-md py-0.5 bg-primary-fixed/50 text-primary font-label text-label rounded-full">Tổng số: {{ str_pad((string) $stageLessonCount, 2, '0', STR_PAD_LEFT) }} buổi</span>
                                    </h3>
                                    <p class="text-[11px] text-gray-500">{{ $stage->units->count() }} unit · {{ $stage->units->sum(fn ($u) => $u->lessons->count()) }} buổi
                                        @if ($openClasses->isNotEmpty()) · Đang học: {{ $openClasses->map(fn ($a) => $a->classModel?->name)->filter()->implode(', ') }} @endif
                                    </p>
                                    @if ($stage->description)<p class="text-xs text-gray-600 mt-1 whitespace-pre-line">{{ $stage->description }}</p>@endif
                                    <p class="text-[11px] mt-1.5 flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center gap-1 text-purple-700 font-semibold"><span class="material-symbols-outlined text-[14px]">quiz</span>{{ $stage->big_test_title ?: 'Big Test cuối chặng (chưa đặt tên)' }}</span>
                                        @if ($stage->big_test_note)<span class="text-gray-500">— {{ $stage->big_test_note }}</span>@endif
                                        @if ($stage->overview_link)
                                            <a href="{{ $stage->overview_link }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline"><span class="material-symbols-outlined text-[14px]">open_in_new</span>Tổng quan chặng</a>
                                        @endif
                                    </p>
                                </div>
                            </div>
                            @if ($canManage)
                                <div class="flex flex-wrap items-center gap-1 shrink-0">
                                    @unless ($loop->first)
                                        <form method="POST" action="{{ route('syllabus.stages.move', $stage->id) }}">@csrf <input type="hidden" name="direction" value="up">
                                            <x-ui.button type="submit" variant="ghost" size="sm" icon="arrow_upward" title="Chuyển lên" />
                                        </form>
                                    @endunless
                                    @unless ($loop->last)
                                        <form method="POST" action="{{ route('syllabus.stages.move', $stage->id) }}">@csrf <input type="hidden" name="direction" value="down">
                                            <x-ui.button type="submit" variant="ghost" size="sm" icon="arrow_downward" title="Chuyển xuống" />
                                        </form>
                                    @endunless
                                    <x-ui.button variant="ghost" size="sm" icon="edit" :href="$builderUrl(['edit_stage' => $stage->id]).'#editor'">Sửa</x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" icon="add" :href="$builderUrl(['new_unit' => $stage->id]).'#editor'">Unit</x-ui.button>
                                    <form method="POST" action="{{ route('syllabus.stages.destroy', $stage->id) }}" data-confirm="Xóa {{ $stage->label }}?">
                                        @csrf @method('DELETE')
                                        <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa chặng" />
                                    </form>
                                </div>
                            @endif
                        </header>

                        <div class="p-4 space-y-3">
                            @forelse ($stage->units as $u)
                                <div class="border border-gray-200 rounded-xl">
                                    <div class="px-4 py-3 flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold text-gray-900">Unit {{ $u->unit_number }}: {{ $u->title }}</p>
                                            @if ($u->objectives)<p class="text-[11px] text-gray-500 truncate">{{ $u->objectives }}</p>@endif
                                        </div>
                                        @if ($canManage)
                                            <div class="flex items-center gap-1 shrink-0">
                                                <x-ui.button variant="ghost" size="sm" icon="add" :href="$builderUrl(['new_lesson' => $u->id]).'#editor'">Buổi</x-ui.button>
                                                <x-ui.button variant="ghost" size="sm" icon="edit" :href="$builderUrl(['edit_unit' => $u->id]).'#editor'">Sửa</x-ui.button>
                                                <form method="POST" action="{{ route('syllabus.units.destroy', $u->id) }}" data-confirm="Xóa Unit {{ $u->unit_number }} cùng {{ $u->lessons->count() }} buổi học?">
                                                    @csrf @method('DELETE')
                                                    <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa unit" />
                                                </form>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="border-t border-gray-100 divide-y divide-gray-100">
                                        @forelse ($u->lessons as $lesson)
                                            <details class="px-4 py-2.5 text-xs group">
                                                <summary class="flex items-center justify-between gap-2 cursor-pointer list-none">
                                                    <span class="flex items-center gap-2 min-w-0">
                                                        <span class="w-7 h-7 shrink-0 rounded-lg bg-orange-100 text-primary font-bold flex items-center justify-center text-[11px]">{{ $lesson->session_no }}</span>
                                                        <span class="font-semibold text-gray-800 truncate">Buổi {{ $lesson->session_no }}: {{ $lesson->title }}</span>
                                                    </span>
                                                    <span class="material-symbols-outlined text-[18px] text-gray-400 group-open:rotate-180 transition">expand_more</span>
                                                </summary>
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3 text-gray-700">
                                                    <p class="whitespace-pre-line"><span class="font-semibold">Mục tiêu:</span> {{ $lesson->objectives ?: 'Chưa cập nhật' }}</p>
                                                    <p class="whitespace-pre-line"><span class="font-semibold">Nội dung chính:</span> {{ $lesson->content ?: 'Chưa cập nhật' }}</p>
                                                    <p class="whitespace-pre-line"><span class="font-semibold">Bài tập về nhà:</span> {{ $lesson->homework_guide ?: 'Chưa cập nhật' }}</p>
                                                    <p class="whitespace-pre-line"><span class="font-semibold">Từ vựng:</span> {{ $lesson->vocabulary_focus ?: '—' }}</p>
                                                    <p class="whitespace-pre-line"><span class="font-semibold">Ngữ pháp:</span> {{ $lesson->grammar_focus ?: '—' }}</p>
                                                </div>
                                                @if ($canManage)
                                                    <div class="flex justify-end gap-1 mt-2">
                                                        <x-ui.button variant="ghost" size="sm" icon="edit" :href="$builderUrl(['edit_lesson' => $lesson->id]).'#editor'">Sửa buổi</x-ui.button>
                                                        <form method="POST" action="{{ route('syllabus.lessons.destroy', $lesson->id) }}" data-confirm="Xóa Buổi {{ $lesson->session_no }}?">
                                                            @csrf @method('DELETE')
                                                            <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete">Xóa</x-ui.button>
                                                        </form>
                                                    </div>
                                                @endif
                                            </details>
                                        @empty
                                            <p class="px-4 py-3 text-[11px] text-gray-400">Unit chưa có buổi học nào.</p>
                                        @endforelse
                                    </div>
                                </div>
                            @empty
                                <p class="text-center text-xs text-gray-400 py-6">Chặng chưa có unit nào.</p>
                            @endforelse
                            @if ($canManage)
                                @php($lastUnit = $stage->units->last())
                                <a href="{{ $lastUnit ? $builderUrl(['new_lesson' => $lastUnit->id]).'#editor' : $builderUrl(['new_unit' => $stage->id]).'#editor' }}"
                                   class="w-full py-lg border-2 border-dashed border-outline-variant rounded-xl flex flex-col items-center justify-center gap-sm text-on-surface-variant hover:bg-white hover:border-primary/50 hover:text-primary transition-all group">
                                    <span class="w-10 h-10 rounded-full bg-surface-container-high flex items-center justify-center group-hover:bg-primary-fixed transition-colors">
                                        <span class="material-symbols-outlined text-[24px]">add</span>
                                    </span>
                                    <span class="font-body-medium">{{ $lastUnit ? 'Thêm buổi học mới vào chặng' : 'Thêm Unit đầu tiên cho chặng' }}</span>
                                </a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </section>
        @endif

        <div class="flex items-center justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('syllabus.documents')">Quay lại</x-ui.button>
            <x-ui.button icon="arrow_forward" :href="route('syllabus.assignments')">Tiếp tục: Chặng của lớp</x-ui.button>
        </div>
    </div>
</x-app-layout>
