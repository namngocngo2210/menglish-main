{{-- Cấu hình Trình độ & Syllabus (mockup cau-hinh-trinh-do): thống kê, tìm kiếm, kéo thả sắp xếp, sửa/xóa, bật/tắt trạng thái,
     nhóm trình độ, mô tả, gắn giáo trình — form thêm/sửa là panel trượt bên phải như mockup. --}}
@php
    $blank = ['id' => null, 'code' => '', 'name' => '', 'description' => '', 'level_group' => '', 'target' => '', 'duration' => '', 'lessons_count' => 24, 'syllabus_curriculum_id' => '', 'is_active' => true];
    $inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20';
    $syllabusOptions = $curriculums->mapWithKeys(fn ($c) => [$c->id => [
        'label' => $c->code.($c->version ? '.'.$c->version : '').' - '.$c->title,
        'updated' => $c->updated_at?->format('d/m/Y'),
    ]]);
    $canUpdate = auth()->user()->can('level.update');
@endphp
<x-app-layout title="Cấu hình Trình độ & Syllabus">
    <div x-data="{
            open: {{ $errors->hasAny(['code', 'name', 'description', 'target', 'lessons_count', 'level_group', 'syllabus_curriculum_id']) ? 'true' : 'false' }},
            level: @js(old('_level', $blank)),
            baseUrl: @js(url('/course-levels')),
            syllabi: @js($syllabusOptions),
            pickSyllabus: false,
            create() { this.level = @js($blank); this.pickSyllabus = false; this.open = true; },
            edit(level) { this.level = { ...level, syllabus_curriculum_id: level.syllabus_curriculum_id ?? '', level_group: level.level_group ?? '', duration: level.duration ?? '', description: level.description ?? '' }; this.pickSyllabus = false; this.open = true; },
         }"
         x-effect="document.body.classList.toggle('overflow-hidden', open)">
        <x-ui.page-header title="Cấu hình Trình độ & Syllabus" description="Quản lý danh sách trình độ đào tạo và thiết lập giáo trình tương ứng.">
            <x-slot:actions>
                @can('level.create')
                    <x-ui.button icon="add_circle" x-on:click="create()">Thêm trình độ mới</x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert type="success" class="mb-lg" dismissible>{{ session('status') }}</x-ui.alert>
        @endif

        <div class="mb-lg grid grid-cols-1 gap-md sm:grid-cols-3">
            <x-ui.stat-card label="Tổng số trình độ" :value="$stats['total']" icon="layers" tone="primary" :hint="$stats['active'].' đang hoạt động'" />
            <x-ui.stat-card label="Syllabus hoạt động" :value="$stats['syllabus']" icon="auto_stories" tone="success" hint="Giáo trình gắn với trình độ đang hoạt động" />
            <x-ui.stat-card label="Nhóm đào tạo" :value="str_pad((string) $stats['groups'], 2, '0', STR_PAD_LEFT)" icon="groups" tone="secondary" />
        </div>

        <x-ui.filter-bar placeholder="Tìm kiếm trình độ..." :action="route('course-levels.index')">
            <x-ui.select name="group" :options="$groups->combine($groups)" placeholder="Tất cả nhóm" inline-label="Nhóm:" />
            <x-ui.select name="status" :options="['active' => 'Hoạt động', 'inactive' => 'Ngừng hoạt động']" placeholder="Mọi trạng thái" inline-label="Trạng thái:" />
        </x-ui.filter-bar>

        <x-ui.data-table min-width="960px">
            <x-slot:header>
                <h3 class="font-h3 text-h3 text-on-surface">Danh sách trình độ đào tạo</h3>
                @if ($canUpdate && $canReorder && $levels->count() > 1)
                    <span class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">drag_indicator</span> Kéo biểu tượng ở cột STT để sắp xếp thứ tự
                    </span>
                @endif
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th class="w-16">STT</th>
                        <th>Mã trình độ</th>
                        <th>Tên trình độ</th>
                        <th>Nhóm trình độ</th>
                        <th>Syllabus gắn kèm</th>
                        <th class="text-center">Khóa / Lớp</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody @if ($canUpdate && $canReorder)
                           x-data="levelReorder(@js(route('course-levels.reorder')))"
                           x-on:dragover.prevent="over($event)" x-on:drop.prevent="drop()"
                       @endif>
                    @forelse ($levels as $lv)
                        @php
                            $inUse = $lv->courses_count > 0 || $lv->classes_count > 0;
                            $studentsUsing = (int) ($studentCounts[$lv->code] ?? 0);
                        @endphp
                        <tr data-level-id="{{ $lv->id }}" @if ($canUpdate && $canReorder) x-on:dragstart="start($event)" x-on:dragend="end()" @endif>
                            <td class="whitespace-nowrap text-on-surface-variant">
                                @if ($canUpdate && $canReorder)
                                    <span class="material-symbols-outlined cursor-grab align-middle text-[20px] hover:text-primary"
                                          x-on:mousedown="$el.closest('tr').setAttribute('draggable', 'true')" title="Kéo để sắp xếp" aria-hidden="true">drag_indicator</span>
                                @endif
                                <span class="font-code">{{ $levels->firstItem() + $loop->index }}</span>
                            </td>
                            <td class="font-code font-semibold">{{ $lv->code }}</td>
                            <td>
                                <div class="font-semibold">{{ $lv->name }}</div>
                                <div class="font-caption text-caption text-on-surface-variant">{{ $lv->target }} · {{ $lv->duration ?: $lv->lessons_count.' buổi' }}</div>
                                @if ($lv->description)
                                    <div class="max-w-xs truncate font-caption text-caption text-on-surface-variant" title="{{ $lv->description }}">{{ $lv->description }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($lv->level_group)
                                    <span class="rounded bg-secondary-fixed px-sm py-[2px] font-caption text-caption font-semibold text-on-secondary-fixed">{{ $lv->level_group }}</span>
                                @else
                                    <span class="font-caption text-caption italic text-on-surface-variant">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($lv->syllabus)
                                    <x-ui.badge color="secondary" :dot="false" :pill="true" class="font-code font-semibold" title="{{ $lv->syllabus->title }}">{{ $lv->syllabus->code }}{{ $lv->syllabus->version ? '.'.$lv->syllabus->version : '' }}</x-ui.badge>
                                @else
                                    <span class="font-caption text-caption italic text-on-surface-variant">Chưa gắn Syllabus</span>
                                @endif
                            </td>
                            <td class="text-center font-code">{{ $lv->courses_count }} / {{ $lv->classes_count }}</td>
                            <td>
                                @if ($canUpdate)
                                    <form method="POST" action="{{ route('course-levels.update', $lv->id) }}">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="toggle_status" value="1">
                                        <button type="submit" title="Bấm để {{ $lv->is_active ? 'ngừng' : 'kích hoạt lại' }}">
                                            <x-ui.badge :color="$lv->is_active ? 'success' : 'neutral'" pill>{{ $lv->is_active ? 'Hoạt động' : 'Ngừng hoạt động' }}</x-ui.badge>
                                        </button>
                                    </form>
                                @else
                                    <x-ui.badge :color="$lv->is_active ? 'success' : 'neutral'" pill>{{ $lv->is_active ? 'Hoạt động' : 'Ngừng hoạt động' }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center justify-end gap-xs">
                                    @if ($canUpdate)
                                        <x-ui.button variant="ghost" icon="edit" title="Chỉnh sửa" aria-label="Sửa {{ $lv->name }}"
                                            x-on:click="edit({{ \Illuminate\Support\Js::from($lv->only(['id', 'code', 'name', 'description', 'level_group', 'target', 'duration', 'lessons_count', 'syllabus_curriculum_id', 'is_active'])) }})" />
                                    @endif
                                    @can('level.delete')
                                        @if ($inUse)
                                            {{-- Cảnh báo theo mockup: hover nút xóa → "Không thể xóa" + số lớp / học sinh tham chiếu. --}}
                                            <div class="group relative" data-delete-blocked>
                                                <span class="inline-flex cursor-not-allowed p-sm text-on-surface-variant/40" tabindex="0" aria-label="Không thể xóa {{ $lv->name }}">
                                                    <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                                                </span>
                                                <div class="pointer-events-none absolute bottom-full right-0 z-20 mb-xs hidden w-64 rounded-lg bg-inverse-surface p-sm text-left text-inverse-on-surface shadow-level-3 group-hover:block group-focus-within:block">
                                                    <p class="flex items-center gap-xs font-body-small text-body-small font-semibold text-error-container">
                                                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">warning</span> Không thể xóa
                                                    </p>
                                                    <p class="mt-xs whitespace-normal font-caption text-caption">
                                                        Trình độ này đang có {{ $lv->classes_count }} lớp học{{ $lv->courses_count ? ', '.$lv->courses_count.' khóa học' : '' }} và {{ $studentsUsing }} học sinh tham chiếu. Vui lòng chuyển sang 'Ngừng hoạt động'.
                                                    </p>
                                                </div>
                                            </div>
                                        @else
                                            <form method="POST" action="{{ route('course-levels.destroy', $lv->id) }}" class="inline" onsubmit="return confirm('Xóa trình độ {{ $lv->code }}?');">
                                                @csrf @method('DELETE')
                                                <x-ui.button type="submit" variant="danger-text" icon="delete" title="Xóa" aria-label="Xóa {{ $lv->name }}" />
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-ui.empty-state icon="layers" title="Không có trình độ phù hợp" description="Thử đổi từ khóa hoặc xóa bộ lọc." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$levels" unit="trình độ" /></x-slot:footer>
        </x-ui.data-table>

        {{-- Panel thêm / sửa trình độ (trượt từ phải) --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex justify-end" x-on:keydown.escape.window="open = false" role="dialog" aria-modal="true" aria-labelledby="level-panel-title">
            <div class="absolute inset-0 bg-on-surface/40 backdrop-blur-xs" x-on:click="open = false" x-show="open" x-transition.opacity></div>
            <form method="POST" :action="level.id ? baseUrl + '/' + level.id : @js(route('course-levels.store'))"
                  class="relative flex h-full w-full max-w-lg flex-col bg-surface-container-lowest shadow-level-3"
                  x-show="open" x-transition:enter="transition duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0">
                @csrf
                <template x-if="level.id"><input type="hidden" name="_method" value="PUT"></template>
                <div class="flex items-start justify-between gap-md border-b border-surface-container p-lg">
                    <div>
                        <h2 id="level-panel-title" class="font-h2 text-h2 text-on-surface">Thêm/Sửa Trình độ đào tạo</h2>
                        <p class="font-body-small text-body-small text-on-surface-variant" x-text="level.id ? 'Đang sửa ' + level.code + ' — nhập thông tin chi tiết và thiết lập Syllabus.' : 'Nhập thông tin chi tiết và thiết lập Syllabus.'"></p>
                    </div>
                    <x-ui.button variant="ghost" icon="close" x-on:click="open = false" aria-label="Đóng" />
                </div>

                <div class="flex-1 space-y-lg overflow-y-auto p-lg">
                    <section class="space-y-md">
                        <h3 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Thông tin chung</h3>
                        <div class="grid grid-cols-2 gap-md">
                            <x-ui.input label="Mã trình độ" name="code" id="lv_code" x-model="level.code" x-bind:disabled="!!level.id" required maxlength="20" placeholder="KID-BEG-01" class="font-code" />
                            <div>
                                <x-ui.input label="Nhóm trình độ" name="level_group" id="lv_group" hint="VD: KIDS, TEENS, IELTS, ADULTS" x-model="level.level_group" list="lv_groups" maxlength="50" class="uppercase" />
                                <datalist id="lv_groups">@foreach ($groups->merge(['KIDS', 'TEENS', 'IELTS', 'ADULTS'])->unique() as $g)<option value="{{ $g }}">@endforeach</datalist>
                            </div>
                        </div>
                        <x-ui.input label="Tên trình độ" name="name" id="lv_name" x-model="level.name" required placeholder="Nhập tên trình độ..." />
                        <x-ui.textarea label="Mô tả" name="description" id="lv_description" x-model="level.description" rows="3" maxlength="1000" placeholder="Mô tả tóm tắt về trình độ này..." />
                        <x-ui.input label="Chuẩn đầu ra (Target)" name="target" id="lv_target" x-model="level.target" required placeholder="CEFR B1 / IELTS 5.0" />
                        <div class="grid grid-cols-2 gap-md">
                            <x-ui.input type="number" label="Số buổi học" name="lessons_count" id="lv_lessons" min="1" x-model="level.lessons_count" required />
                            <x-ui.input label="Thời lượng" name="duration" id="lv_duration" x-model="level.duration" placeholder="12 tuần / 24 buổi" />
                        </div>
                        <label class="flex cursor-pointer items-center justify-between gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md">
                            <span>
                                <span class="block font-body-medium text-body-medium text-on-surface">Trạng thái hoạt động</span>
                                <span class="block font-caption text-caption text-on-surface-variant">Cho phép sử dụng trình độ này trong tuyển sinh.</span>
                            </span>
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" x-model="level.is_active" class="peer sr-only">
                            <span class="relative h-6 w-11 shrink-0 rounded-full bg-surface-container-highest transition after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-surface-container-lowest after:shadow after:transition peer-checked:bg-primary-container peer-checked:after:translate-x-5 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-container/40" aria-hidden="true"></span>
                        </label>
                    </section>

                    <section class="space-y-md border-t border-surface-container pt-lg">
                        <div class="flex items-center justify-between">
                            <h3 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Thiết lập Syllabus</h3>
                            <x-ui.button variant="ghost" size="sm" icon="add" class="font-semibold !text-primary" x-on:click="pickSyllabus = true">Gắn Syllabus mới</x-ui.button>
                        </div>
                        <input type="hidden" name="syllabus_curriculum_id" :value="level.syllabus_curriculum_id ?? ''">
                        <template x-if="level.syllabus_curriculum_id && syllabi[level.syllabus_curriculum_id]">
                            <div class="flex items-center justify-between gap-md rounded-lg border border-primary-container/30 bg-primary-container/5 p-md">
                                <div class="flex min-w-0 items-center gap-md">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-container/10 text-primary"><span class="material-symbols-outlined" aria-hidden="true">menu_book</span></span>
                                    <div class="min-w-0">
                                        <p class="truncate font-body-medium text-body-medium font-semibold text-on-surface" x-text="syllabi[level.syllabus_curriculum_id].label"></p>
                                        <p class="font-caption text-caption text-on-surface-variant" x-text="'Cập nhật: ' + (syllabi[level.syllabus_curriculum_id].updated ?? '—') + ' | Trạng thái: Hiện tại'"></p>
                                    </div>
                                </div>
                                <x-ui.button variant="ghost" icon="link_off" class="hover:!text-error" title="Gỡ Syllabus" aria-label="Gỡ Syllabus" x-on:click="level.syllabus_curriculum_id = ''" />
                            </div>
                        </template>
                        <template x-if="!level.syllabus_curriculum_id && !pickSyllabus">
                            <p class="rounded-lg border border-dashed border-outline-variant p-md text-center font-body-small text-body-small italic text-on-surface-variant">Chưa gắn Syllabus</p>
                        </template>
                        <div x-show="pickSyllabus" x-cloak>
                            <x-ui.field label="Chọn giáo trình" name="syllabus_curriculum_id" for="lv_syllabus">
                                <select id="lv_syllabus" x-model="level.syllabus_curriculum_id" x-on:change="pickSyllabus = false" class="{{ $inputClass }}">
                                    <option value="">-- Chưa gắn Syllabus --</option>
                                    @foreach ($curriculums as $cur)
                                        <option value="{{ $cur->id }}">{{ $cur->code }}{{ $cur->version ? ' ('.$cur->version.')' : '' }} — {{ $cur->title }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        </div>
                    </section>
                </div>

                <div class="flex justify-end gap-sm border-t border-surface-container p-lg">
                    <x-ui.button variant="secondary" x-on:click="open = false">Hủy bỏ</x-ui.button>
                    <x-ui.button type="submit">Lưu thay đổi</x-ui.button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            // Kéo thả sắp xếp trình độ: đổi vị trí dòng trên trang rồi lưu thứ tự mới (POST ids theo thứ tự).
            function levelReorder(url) {
                return {
                    dragging: null,
                    start(e) { this.dragging = e.target.closest('tr'); this.dragging.classList.add('opacity-60'); e.dataTransfer.effectAllowed = 'move'; },
                    end() { this.dragging?.classList.remove('opacity-60'); this.dragging?.removeAttribute('draggable'); },
                    over(e) {
                        const row = e.target.closest('tr');
                        if (!this.dragging || !row || row === this.dragging) return;
                        const after = e.clientY > row.getBoundingClientRect().top + row.offsetHeight / 2;
                        row.parentNode.insertBefore(this.dragging, after ? row.nextSibling : row);
                    },
                    drop() {
                        const ids = [...this.$root.querySelectorAll('tr[data-level-id]')].map(r => r.dataset.levelId);
                        fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({ ids }),
                        }).then(r => r.ok ? r.json() : Promise.reject())
                          .then(d => window.dispatchEvent(new CustomEvent('toast', { detail: { message: d.message, type: 'success' } })))
                          .catch(() => window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Không lưu được thứ tự, vui lòng tải lại trang.', type: 'error' } })));
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
