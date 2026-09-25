{{-- Cấu hình Trình độ & Syllabus (mockup cau-hinh-trinh-do): thống kê, tìm kiếm, sửa/xóa, bật/tắt trạng thái, nhóm trình độ, gắn giáo trình. --}}
@php
    $blank = ['id' => null, 'code' => '', 'name' => '', 'level_group' => '', 'target' => '', 'duration' => '', 'lessons_count' => 24, 'syllabus_curriculum_id' => '', 'is_active' => true];
    $inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20';
@endphp
<x-app-layout title="Cấu hình Trình độ & Syllabus">
    <div x-data="{
            open: {{ $errors->hasAny(['code', 'name', 'target', 'lessons_count', 'level_group', 'syllabus_curriculum_id']) ? 'true' : 'false' }},
            level: @js(old('_level', $blank)),
            baseUrl: @js(url('/course-levels')),
            create() { this.level = @js($blank); this.open = true; },
            edit(level) { this.level = { ...level, syllabus_curriculum_id: level.syllabus_curriculum_id ?? '', level_group: level.level_group ?? '', duration: level.duration ?? '' }; this.open = true; },
         }">
        <x-ui.page-header title="Cấu hình Trình độ & Syllabus" description="Quản lý danh sách trình độ đào tạo và thiết lập giáo trình tương ứng.">
            <x-slot:actions>
                @can('level.create')
                    <x-ui.button icon="add_circle" x-on:click="create()">Thêm trình độ mới</x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.tabs class="mb-lg">
            <x-ui.tab icon="sell" :href="route('courses.index')">Bảng giá &amp; Khóa học</x-ui.tab>
            <x-ui.tab icon="layers" :href="route('course-levels.index')" active>Trình độ</x-ui.tab>
            <x-ui.tab icon="menu_book" :href="route('syllabus.documents')">Giáo trình &amp; Syllabus</x-ui.tab>
        </x-ui.tabs>

        @if (session('status'))
            <x-ui.alert type="success" class="mb-lg" dismissible>{{ session('status') }}</x-ui.alert>
        @endif

        <div class="mb-lg grid grid-cols-1 gap-md sm:grid-cols-3">
            <x-ui.stat-card label="Tổng số trình độ" :value="$stats['total']" icon="layers" tone="primary" :hint="$stats['active'].' đang hoạt động'" />
            <x-ui.stat-card label="Syllabus hoạt động" :value="$stats['syllabus']" icon="menu_book" tone="success" hint="Giáo trình gắn với trình độ đang hoạt động" />
            <x-ui.stat-card label="Nhóm đào tạo" :value="str_pad((string) $stats['groups'], 2, '0', STR_PAD_LEFT)" icon="groups" tone="secondary" />
        </div>

        <x-ui.filter-bar placeholder="Tìm kiếm mã, tên, nhóm trình độ..." :action="route('course-levels.index')">
            <x-ui.select name="group" :options="$groups->combine($groups)" placeholder="Tất cả nhóm" inline-label="Nhóm:" />
            <x-ui.select name="status" :options="['active' => 'Hoạt động', 'inactive' => 'Ngừng hoạt động']" placeholder="Mọi trạng thái" inline-label="Trạng thái:" />
        </x-ui.filter-bar>

        <x-ui.data-table min-width="960px">
            <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Danh sách trình độ đào tạo</h3></x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>STT</th>
                        <th>Mã trình độ</th>
                        <th>Tên trình độ</th>
                        <th>Nhóm trình độ</th>
                        <th>Syllabus gắn kèm</th>
                        <th class="text-center">Khóa / Lớp</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($levels as $lv)
                        @php $inUse = $lv->courses_count > 0 || $lv->classes_count > 0; @endphp
                        <tr data-level-id="{{ $lv->id }}">
                            <td class="font-code text-on-surface-variant">{{ $levels->firstItem() + $loop->index }}</td>
                            <td class="font-code font-semibold">{{ $lv->code }}</td>
                            <td>
                                <div class="font-semibold">{{ $lv->name }}</div>
                                <div class="font-caption text-caption text-on-surface-variant">{{ $lv->target }} · {{ $lv->duration ?: $lv->lessons_count.' buổi' }}</div>
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
                                    <span class="rounded-full bg-blue-600/10 px-sm py-[2px] font-code text-caption font-semibold text-blue-700" title="{{ $lv->syllabus->title }}">{{ $lv->syllabus->code }}{{ $lv->syllabus->version ? '.'.$lv->syllabus->version : '' }}</span>
                                @else
                                    <span class="font-caption text-caption italic text-on-surface-variant">Chưa gắn Syllabus</span>
                                @endif
                            </td>
                            <td class="text-center font-code">{{ $lv->courses_count }} / {{ $lv->classes_count }}</td>
                            <td>
                                @can('level.update')
                                    <form method="POST" action="{{ route('course-levels.update', $lv->id) }}">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="toggle_status" value="1">
                                        <button type="submit" title="Bấm để {{ $lv->is_active ? 'ngừng' : 'kích hoạt lại' }}">
                                            <x-ui.badge :color="$lv->is_active ? 'success' : 'neutral'">{{ $lv->is_active ? 'Hoạt động' : 'Ngừng hoạt động' }}</x-ui.badge>
                                        </button>
                                    </form>
                                @else
                                    <x-ui.badge :color="$lv->is_active ? 'success' : 'neutral'">{{ $lv->is_active ? 'Hoạt động' : 'Ngừng hoạt động' }}</x-ui.badge>
                                @endcan
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @can('level.update')
                                    <x-ui.button variant="ghost" icon="edit" aria-label="Sửa {{ $lv->name }}"
                                        x-on:click="edit({{ \Illuminate\Support\Js::from($lv->only(['id', 'code', 'name', 'level_group', 'target', 'duration', 'lessons_count', 'syllabus_curriculum_id', 'is_active'])) }})" />
                                @endcan
                                @can('level.delete')
                                    @if ($inUse)
                                        <span title="Đang gắn với khóa học/lớp học — không thể xóa" class="inline-flex p-sm text-on-surface-variant/40">
                                            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                                        </span>
                                    @else
                                        <form method="POST" action="{{ route('course-levels.destroy', $lv->id) }}" class="inline" onsubmit="return confirm('Xóa trình độ {{ $lv->code }}?');">
                                            @csrf @method('DELETE')
                                            <x-ui.button type="submit" variant="danger-text" icon="delete" aria-label="Xóa {{ $lv->name }}" />
                                        </form>
                                    @endif
                                @endcan
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

        {{-- Modal thêm / sửa trình độ --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-md" x-on:keydown.escape.window="open = false">
            <div x-on:click.outside="open = false" class="w-full max-w-lg space-y-md rounded-2xl bg-surface-container-lowest p-lg shadow-xl">
                <div class="flex items-center justify-between border-b border-surface-container pb-sm">
                    <h3 class="font-h3 text-h3 text-on-surface" x-text="level.id ? 'Sửa trình độ ' + level.code : 'Thêm trình độ mới'"></h3>
                    <button type="button" x-on:click="open = false" class="text-on-surface-variant" aria-label="Đóng"><span class="material-symbols-outlined">close</span></button>
                </div>
                <form method="POST" :action="level.id ? baseUrl + '/' + level.id : @js(route('course-levels.store'))" class="space-y-sm">
                    @csrf
                    <template x-if="level.id"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="grid grid-cols-2 gap-sm">
                        <x-ui.field label="Mã trình độ" name="code" for="lv_code" required>
                            <input id="lv_code" name="code" x-model="level.code" :disabled="!!level.id" required maxlength="20" placeholder="KID-BEG-01" class="{{ $inputClass }} font-code">
                        </x-ui.field>
                        <x-ui.field label="Nhóm trình độ" name="level_group" for="lv_group" hint="VD: KIDS, TEENS, IELTS">
                            <input id="lv_group" name="level_group" x-model="level.level_group" list="lv_groups" maxlength="50" class="{{ $inputClass }} uppercase">
                            <datalist id="lv_groups">@foreach ($groups as $g)<option value="{{ $g }}">@endforeach</datalist>
                        </x-ui.field>
                    </div>
                    <x-ui.field label="Tên trình độ" name="name" for="lv_name" required>
                        <input id="lv_name" name="name" x-model="level.name" required class="{{ $inputClass }}">
                    </x-ui.field>
                    <x-ui.field label="Chuẩn đầu ra (Target)" name="target" for="lv_target" required>
                        <input id="lv_target" name="target" x-model="level.target" required placeholder="CEFR B1 / IELTS 5.0" class="{{ $inputClass }}">
                    </x-ui.field>
                    <div class="grid grid-cols-2 gap-sm">
                        <x-ui.field label="Số buổi học" name="lessons_count" for="lv_lessons" required>
                            <input id="lv_lessons" type="number" min="1" name="lessons_count" x-model="level.lessons_count" required class="{{ $inputClass }}">
                        </x-ui.field>
                        <x-ui.field label="Thời lượng" name="duration" for="lv_duration">
                            <input id="lv_duration" name="duration" x-model="level.duration" placeholder="12 tuần / 24 buổi" class="{{ $inputClass }}">
                        </x-ui.field>
                    </div>
                    <x-ui.field label="Syllabus gắn kèm" name="syllabus_curriculum_id" for="lv_syllabus">
                        <select id="lv_syllabus" name="syllabus_curriculum_id" x-model="level.syllabus_curriculum_id" class="{{ $inputClass }}">
                            <option value="">-- Chưa gắn Syllabus --</option>
                            @foreach ($curriculums as $cur)
                                <option value="{{ $cur->id }}">{{ $cur->code }}{{ $cur->version ? ' ('.$cur->version.')' : '' }} — {{ $cur->title }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <template x-if="level.id">
                        <label class="inline-flex items-center gap-xs font-body-small text-body-small">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" x-model="level.is_active" class="rounded border-outline-variant">
                            Đang hoạt động
                        </label>
                    </template>
                    <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                        <x-ui.button variant="secondary" x-on:click="open = false">Hủy</x-ui.button>
                        <x-ui.button type="submit" icon="save">Lưu</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
