<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="font-h1 text-h1 text-on-surface">Quản lý tài liệu giáo trình</h1>
                <p class="font-body-base text-on-surface-variant">Quản lý và cập nhật tài liệu cho các khóa học.</p>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" icon="edit_document" :href="route('syllabus.builder')">Soạn syllabus</x-ui.button>
                <x-ui.button icon="menu_book" :href="route('syllabus.teacher-view')">Xem như giáo viên</x-ui.button>
            </div>
        </div>
    </x-slot>


    @php($canUpload = auth()->user()->can('syllabus.upload'))

    {{-- Mockup 01_Web_Admin/01_quan_ly_tai_lieu_giao_trinh: form tải lên (giáo trình → chặng → đối tượng xem → file) + danh sách. --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        @if ($canUpload)
        <section class="lg:col-span-4 flex flex-col gap-4">
            <div class="bg-surface-container-lowest rounded-xl border border-outline-variant p-lg shadow-sm">
                <h2 class="font-h3 text-h3 text-on-surface mb-md">Tải lên tài liệu mới</h2>

                @if ($curriculums->isEmpty())
                    <x-ui.empty-state icon="library_add" title="Chưa có giáo trình" description="Tạo giáo trình ở màn Soạn syllabus trước khi tải tài liệu.">
                        <x-ui.button icon="add" :href="route('syllabus.builder')">Tạo giáo trình</x-ui.button>
                    </x-ui.empty-state>
                @else
                @php($stageOptions = $curriculums->mapWithKeys(fn ($c) => [$c->id => $c->stages->map(fn ($s) => ['id' => $s->id, 'label' => $s->label])->values()]))
                <form action="{{ route('syllabus.documents.store') }}" method="POST" enctype="multipart/form-data" class="space-y-md"
                      x-data="{
                        curriculum: @js((string) old('curriculum_id', '')),
                        stage: @js((string) old('stage_id', '')),
                        stages: @js($stageOptions),
                        teachers: @js((bool) old('visible_to_teachers')),
                        assistants: @js((bool) old('visible_to_assistants')),
                        downloadable: @js((bool) old('downloadable')),
                        fileName: '',
                        dragging: false,
                        drop(e) { this.dragging = false; if (e.dataTransfer.files.length) { this.$refs.file.files = e.dataTransfer.files; this.fileName = e.dataTransfer.files[0].name; } },
                      }">
                    @csrf
                    <x-ui.field label="Chọn giáo trình" name="curriculum_id" required>
                        <select name="curriculum_id" required x-model="curriculum" @change="stage = ''" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                            <option value="">-- Chọn giáo trình --</option>
                            @foreach ($curriculums as $c)
                                <option value="{{ $c->id }}">{{ $c->title }} ({{ $c->code }} · {{ $c->version }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field label="Chọn chặng học" name="stage_id" required hint="Chặng lấy từ màn Soạn syllabus của giáo trình đã chọn.">
                        <select name="stage_id" x-model="stage" :required="(stages[curriculum] || []).length > 0" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                            <option value="">-- Chọn chặng học --</option>
                            <template x-for="s in (stages[curriculum] || [])" :key="s.id">
                                <option :value="String(s.id)" x-text="s.label" :selected="String(s.id) === stage"></option>
                            </template>
                        </select>
                    </x-ui.field>
                    <x-ui.input name="title" label="Tên tài liệu" required placeholder="IELTS Reading Masterclass - Student Book" />

                    <x-ui.field label="Chọn đối tượng xem" required hint="Admin, Học vụ, Học thuật luôn xem được mọi tài liệu.">
                        <div class="grid grid-cols-2 gap-sm bg-surface-container-low p-md rounded-lg border border-outline-variant font-body-small text-body-small">
                            @foreach (['Admin', 'Học vụ', 'Học thuật'] as $role)
                                <label class="flex items-center gap-2 text-on-surface-variant">
                                    <input type="checkbox" checked disabled class="rounded border-gray-300 text-primary h-4 w-4 opacity-70" />
                                    <span>{{ $role }}</span>
                                </label>
                            @endforeach
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="visible_to_teachers" value="1" x-model="teachers" class="rounded border-gray-300 text-primary focus:ring-primary-container h-4 w-4" />
                                <span class="font-medium text-on-surface">Giáo viên</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="visible_to_assistants" value="1" x-model="assistants" class="rounded border-gray-300 text-primary focus:ring-primary-container h-4 w-4" />
                                <span class="font-medium text-on-surface">Trợ giảng</span>
                            </label>
                            <label class="col-span-2 flex items-center gap-2 cursor-pointer pt-sm border-t border-outline-variant">
                                <input type="checkbox" name="downloadable" value="1" x-model="downloadable" class="rounded border-gray-300 text-primary focus:ring-primary-container h-4 w-4" />
                                <span class="font-medium text-on-surface">Cho phép GV/TG tải về (bỏ chọn = chỉ xem trực tuyến)</span>
                            </label>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Tài liệu đính kèm" name="file" required>
                        <label class="flex flex-col items-center justify-center gap-xs rounded-xl border-2 border-dashed px-md py-lg text-center cursor-pointer transition-colors"
                               :class="dragging ? 'border-primary-container bg-primary-fixed/30' : 'border-outline-variant bg-surface-container-low hover:border-primary/50'"
                               @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="drop($event)">
                            <span class="material-symbols-outlined text-[36px] text-on-surface-variant">cloud_upload</span>
                            <p class="font-body-small text-body-small text-on-surface-variant">Kéo thả file vào đây hoặc</p>
                            <span class="inline-flex items-center rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-xs font-body-medium text-body-small text-primary">Chọn file từ máy tính</span>
                            <p class="font-caption text-caption text-on-surface-variant" x-show="! fileName">Hỗ trợ PDF, DOCX, PPTX, XLSX, ảnh, audio, video (Tối đa 100MB)</p>
                            <p class="font-caption text-caption font-semibold text-on-surface" x-show="fileName" x-cloak x-text="fileName"></p>
                            <input type="file" name="file" x-ref="file" required class="sr-only" @change="fileName = $event.target.files[0]?.name || ''"
                                   accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp,.mp3,.wav,.m4a,.ogg,.mp4,.mov,.webm,.m4v" />
                        </label>
                    </x-ui.field>

                    <div x-show="(teachers || assistants) && ! downloadable" x-cloak class="flex items-start gap-sm rounded-lg border border-amber-200 bg-amber-50 p-md text-amber-900">
                        <span class="material-symbols-outlined text-[20px] text-amber-600" style="font-variation-settings: 'FILL' 1;">info</span>
                        <p class="font-body-small text-body-small">Khóa tải xuống — Giáo viên chỉ được phép xem trực tuyến để bảo vệ tài liệu.</p>
                    </div>

                    <div class="pt-sm border-t border-outline-variant">
                        <x-ui.button type="submit" icon="save" class="w-full">Lưu tài liệu</x-ui.button>
                    </div>
                </form>
                @endif
            </div>
        </section>
        @endif

        <section class="{{ $canUpload ? 'lg:col-span-8' : 'lg:col-span-12' }} flex flex-col gap-4 min-w-0">
            <x-ui.data-table min-width="760px">
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <h2 class="font-h3 text-h3 text-on-surface">Danh sách tài liệu đã tải lên</h2>
                        <x-ui.badge>{{ $documents->total() }} tài liệu</x-ui.badge>
                    </div>
                    <form method="GET" class="flex flex-wrap items-center gap-2">
                        <x-ui.select name="curriculum_id" placeholder="Tất cả giáo trình" :options="$curriculums->pluck('title', 'id')" onchange="this.form.submit()" />
                        <x-ui.input name="search" icon="search" :value="request('search')" placeholder="Tìm kiếm tài liệu..." />
                    </form>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Tên tài liệu</th>
                            <th>Chặng học</th>
                            <th>Đối tượng xem</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documents as $doc)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-lg bg-primary-fixed/40 text-primary flex items-center justify-center shrink-0">
                                            <span class="material-symbols-outlined text-[18px]">{{ $doc->icon }}</span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-body-medium text-body-medium text-on-surface line-clamp-1">{{ $doc->title }}</p>
                                            <p class="font-caption text-caption text-on-surface-variant mt-0.5">
                                                {{ $doc->curriculum?->title }} • <span class="font-mono uppercase">{{ $doc->extension }}</span> • <span class="font-mono">{{ $doc->size_human }}</span>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">{{ $doc->stage_label ?: '—' }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($doc->audience_labels as $label)
                                            <x-ui.badge :color="in_array($label, ['Giáo viên', 'Trợ giảng'], true) ? 'primary' : 'neutral'" :dot="false">{{ $label }}</x-ui.badge>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($doc->downloadable)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-tertiary/10 px-sm py-0.5 font-label text-label text-tertiary"><span class="material-symbols-outlined text-[14px]">download</span>Có thể tải</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-error/10 px-sm py-0.5 font-label text-label text-error"><span class="material-symbols-outlined text-[14px]">visibility</span>Chỉ xem online</span>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($doc->downloadable)
                                            <x-ui.button variant="ghost" size="sm" icon="download" :href="route('syllabus.documents.file', ['id' => $doc->id, 'download' => 1])" title="Tải xuống" />
                                        @endif
                                        <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('syllabus.teacher-view', ['document' => $doc->id])" title="Xem chi tiết" />
                                        @if ($canUpload)
                                            <form method="POST" action="{{ route('syllabus.documents.destroy', $doc->id) }}" data-confirm="Xóa tài liệu {{ $doc->title }}? File sẽ bị xóa khỏi máy chủ.">
                                                @csrf @method('DELETE')
                                                <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa" />
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-ui.empty-state icon="folder_off" title="Chưa có tài liệu nào" description="Tài liệu được tải lên sẽ hiển thị tại đây." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$documents" unit="tài liệu" /></x-slot:footer>
            </x-ui.data-table>
        </section>
    </div>
</x-app-layout>
