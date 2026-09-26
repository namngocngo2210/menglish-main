{{-- Hồ sơ học sinh — danh sách (mockup epic-6/ho-so-hoc-sinh-danh-sach-lien-ket-lop): lọc chi nhánh / lớp / chip 6 trạng thái (A6 Q5),
     cột Họ tên & Ngày sinh, Thông tin liên hệ, Lớp hiện tại, Trạng thái, thao tác Chi tiết + Liên kết lớp khác. --}}
@php
    $canLink = auth()->user()->can('student.assign_class');
    $linkOptions = $linkableClasses->map(fn ($c) => [
        'id' => $c->id,
        'label' => $c->name.' ('.$c->code.')',
        'branch_id' => $c->branch_id,
        'seats' => ($c->roster_count ?? 0).'/'.($c->max_capacity > 0 ? $c->max_capacity : '∞'),
        'full' => $c->max_capacity > 0 && ($c->roster_count ?? 0) >= $c->max_capacity,
    ])->values();
@endphp
<x-app-layout title="Hồ sơ học sinh">
    <div x-data="{
            linkStudent: null,
            classes: @js($linkOptions),
            baseUrl: @js(url('/students')),
            openLink(student) { this.linkStudent = student; this.$dispatch('open-modal', 'link-class'); },
            get options() { return this.linkStudent ? this.classes.filter(c => !this.linkStudent.branch_id || c.branch_id === this.linkStudent.branch_id).filter(c => !this.linkStudent.class_ids.includes(c.id)) : []; },
         }">
        <x-ui.page-header title="Hồ sơ học sinh" description="Quản lý và tra cứu thông tin học sinh toàn hệ thống.">
            <x-slot:actions>
                <div class="flex items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest px-md py-sm">
                    <span class="font-body-small text-body-small text-on-surface-variant">Tổng số học sinh</span>
                    <span class="font-h3 text-h3 text-primary" data-testid="student-total">{{ number_format($totalStudents, 0, ',', '.') }}</span>
                </div>
                <x-ui.button variant="secondary" icon="how_to_reg" :href="route('students.enrollments')">Tiếp nhận &amp; Xếp lớp</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>


        {{-- Bộ lọc --}}
        <form method="GET" action="{{ route('students.index') }}" role="search"
              class="mb-lg space-y-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
            <div class="grid grid-cols-1 items-end gap-md md:grid-cols-[2fr_1fr_1fr_auto]">
                <x-ui.input type="search" name="search" id="st_search" label="Tìm kiếm" icon="search" :value="request('search')" placeholder="Tìm học sinh hoặc SĐT..." />
                <x-ui.select name="branch_id" label="Chi nhánh" :options="$branches->pluck('name', 'id')" placeholder="Tất cả chi nhánh" />
                <x-ui.select name="class_id" label="Lớp học" :options="$classes->pluck('name', 'id')" placeholder="Tất cả các lớp" aria-label="Lọc theo lớp" />
                <x-ui.button type="submit" icon="filter_list">Lọc dữ liệu</x-ui.button>
            </div>
            <div class="flex flex-wrap items-center gap-sm">
                <span class="font-label-caps text-label-caps uppercase text-on-surface-variant">Trạng thái</span>
                @foreach (\App\Models\Student::STATUSES as $statusKey => $statusLabel)
                    <label class="cursor-pointer">
                        <input type="checkbox" name="statuses[]" value="{{ $statusKey }}" @checked(in_array($statusKey, $statuses, true)) class="peer sr-only" onchange="this.form.submit()">
                        <span class="inline-flex items-center gap-xs rounded-full border border-outline-variant px-md py-xs font-body-small text-body-small text-on-surface-variant transition peer-checked:border-primary-container peer-checked:bg-primary-container/10 peer-checked:font-semibold peer-checked:text-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary-container/40">
                            {{ $statusLabel }}
                        </span>
                    </label>
                @endforeach
                @if (collect(request()->only(['search', 'branch_id', 'class_id', 'status', 'statuses']))->filter()->isNotEmpty())
                    <x-ui.button variant="ghost" size="sm" icon="filter_alt_off" :href="route('students.index')" class="ml-auto">Xóa lọc</x-ui.button>
                @endif
            </div>
        </form>

        <x-ui.data-table min-width="900px">
            <table>
                <thead>
                    <tr>
                        <th>Họ tên &amp; Ngày sinh</th>
                        <th>Thông tin liên hệ</th>
                        <th>Lớp hiện tại</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($students as $st)
                        <tr>
                            <td>
                                <a href="{{ route('students.show', $st->id) }}" class="flex items-center gap-sm">
                                    <x-ui.avatar :name="$st->name" />
                                    <span class="min-w-0">
                                        <span class="block font-body-medium text-body-medium font-semibold text-on-surface hover:text-primary">{{ $st->name }}</span>
                                        <span class="block font-caption text-caption text-on-surface-variant">
                                            {{ $st->dob ? $st->dob->format('d/m/Y') : 'Chưa có ngày sinh' }} · <span class="font-code">{{ $st->code }}</span>
                                        </span>
                                    </span>
                                </a>
                            </td>
                            <td>
                                <p class="font-code text-code text-on-surface">{{ $st->phone ?: '—' }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ $st->email ?: 'Chưa có email' }}</p>
                            </td>
                            <td>
                                @if ($st->currentClass)
                                    <span class="inline-flex rounded bg-secondary-fixed px-sm py-[2px] font-code text-caption font-semibold text-on-secondary-fixed" title="{{ $st->currentClass->name }}">{{ $st->currentClass->code ?: $st->currentClass->name }}</span>
                                    <span class="mt-xs block max-w-[200px] truncate font-caption text-caption text-on-surface-variant">{{ $st->currentClass->name }}</span>
                                @else
                                    <span class="inline-flex rounded bg-surface-container-high px-sm py-[2px] font-caption text-caption text-on-surface-variant">Chưa có lớp</span>
                                @endif
                            </td>
                            <td><x-ui.badge :color="$st->status_color" pill>{{ $st->status_label }}</x-ui.badge></td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-sm">
                                    <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('students.show', $st->id)">Chi tiết</x-ui.button>
                                    @if ($canLink && $st->status !== \App\Models\Student::STATUS_DROPPED)
                                        <x-ui.button variant="secondary" size="sm"
                                            x-on:click="openLink({{ \Illuminate\Support\Js::from(['id' => $st->id, 'name' => $st->name, 'branch_id' => $st->branch_id, 'class_ids' => array_values(array_filter([$st->current_class_id]))]) }})">
                                            Liên kết lớp khác
                                        </x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                @if (collect(request()->only(['search', 'branch_id', 'class_id', 'status', 'statuses']))->filter()->isNotEmpty())
                                    <x-ui.empty-state icon="search_off" title="Không tìm thấy học viên" description="Thử đổi từ khóa hoặc bỏ bớt bộ lọc.">
                                        <x-ui.button variant="secondary" size="sm" :href="route('students.index')">Xóa bộ lọc</x-ui.button>
                                    </x-ui.empty-state>
                                @else
                                    <x-ui.empty-state icon="school" title="Chưa có học viên nào" description="Danh sách chỉ gồm học viên thuộc chi nhánh / lớp bạn được phân quyền." />
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$students" :options="[10, 20, 50]" unit="học sinh" /></x-slot:footer>
        </x-ui.data-table>

        {{-- Popup "Liên kết lớp khác" (học song song, không đổi lớp chính) --}}
        @if ($canLink)
            <x-ui.modal name="link-class" title="Liên kết lớp khác" max-width="md">
                <form id="list-link-class-form" method="POST" :action="baseUrl + '/' + linkStudent?.id + '/link-class'" class="space-y-md" data-testid="list-link-class-form">
                    @csrf
                    <p class="font-body-small text-body-small text-on-surface-variant">Học viên <strong x-text="linkStudent?.name"></strong> học thêm lớp này, lớp chính giữ nguyên.</p>
                    <x-ui.select name="class_id" id="list_link_class" label="Lớp liên kết" required placeholder="-- Chọn lớp cùng chi nhánh --" :value="''">
                        <template x-for="c in options" :key="c.id">
                            <option :value="c.id" :disabled="c.full" x-text="c.label + ' — ' + c.seats + (c.full ? ' (Đã đủ sĩ số)' : '')"></option>
                        </template>
                    </x-ui.select>
                    <p x-show="options.length === 0" class="font-caption text-caption text-on-surface-variant">Không còn lớp cùng chi nhánh để liên kết.</p>
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'link-class')">Hủy</x-ui.button>
                    <x-ui.button type="submit" form="list-link-class-form" icon="add_link">Liên kết lớp</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>
</x-app-layout>
