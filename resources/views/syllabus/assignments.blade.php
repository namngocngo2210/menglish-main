<x-app-layout>
    <x-ui.page-header title="Giao chặng học cho giáo viên" icon="assignment_ind" description="Thiết lập quyền truy cập giáo trình theo từng chặng học cho giáo viên của từng lớp.">
        <x-slot:actions>
            @can('activity_log.view')
                <x-ui.button variant="secondary" icon="history" :href="route('activity-logs.index', ['log_name' => 'Giáo trình & Syllabus'])">Xem log hệ thống</x-ui.button>
            @endcan
            <x-ui.button icon="menu_book" :href="route('syllabus.teacher-view')">Màn GV xem giáo trình</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    @php
        $canManage = auth()->user()->can('syllabus.manage');
        $canOverride = auth()->user()->can('syllabus.approve_adjustment');
        $curriculumData = $curriculums->map(fn ($c) => ['id' => $c->id, 'stages' => $c->stages->map(fn ($s) => ['id' => $s->id, 'label' => $s->label])->values()])->values();
        $classCurriculum = $classes->mapWithKeys(fn ($c) => [$c->id => $levelCurriculum[$c->level] ?? null]);
        $classOpenStage = $openByClass->map(fn ($a) => $a->stage_name);
        $teacherOptions = $teachers->mapWithKeys(fn ($t) => [$t->id => $t->name.($t->employee_code ? ' (ID: '.$t->employee_code.')' : '')]);
    @endphp

    {{-- Mockup 01_Web_Admin/03: form "Thiết lập chặng mới" (lớp, GV, chặng, ngày bắt đầu, lưu ý R19) + "Lịch sử phân quyền chặng học". --}}
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6"
         x-data="{ closeUrl: '', closeLabel: '', editUrl: '', edit: { label: '', user_id: '', start_date: '', deadline: '' } }">
        <aside class="xl:col-span-4 space-y-6">
            @if ($canManage)
            <section class="bg-surface-container-lowest rounded-xl border border-outline-variant p-lg shadow-sm"
                     x-data="{
                        classId: @js((string) old('class_id', '')),
                        curriculumId: @js((string) old('curriculum_id', '')),
                        stageId: @js((string) old('stage_id', '')),
                        replace: @js((bool) old('replace_current')),
                        curriculums: @js($curriculumData),
                        classCurriculum: @js($classCurriculum),
                        classOpen: @js($classOpenStage),
                        get stages() { return (this.curriculums.find(c => String(c.id) === this.curriculumId) || { stages: [] }).stages },
                        pickClass() { const c = this.classCurriculum[this.classId]; if (c) this.curriculumId = String(c); this.stageId = ''; },
                     }">
                <div class="flex items-center gap-2 mb-lg pb-sm border-b border-outline-variant">
                    <span class="material-symbols-outlined text-primary">add_task</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Thiết lập chặng mới</h2>
                </div>

                <form action="{{ route('syllabus.assignments.store') }}" method="POST" class="space-y-md">
                    @csrf
                    <x-ui.select label="Chọn lớp học" name="class_id" id="assign_class_id" required x-model="classId" x-on:change="pickClass()">
                        <option value="" disabled>Chọn lớp học đang quản lý...</option>
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}">{{ $class->name }} ({{ $class->code }})</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.alert type="warning" x-show="classOpen[classId]" x-cloak class="font-caption text-caption">
                        Lớp đang học <strong x-text="classOpen[classId]"></strong>. Chặng mới chỉ mở được sau khi chặng này đóng@if ($canOverride), hoặc chọn "Chuyển chặng" bên dưới@endif.
                    </x-ui.alert>

                    <x-ui.select name="user_id" label="Chọn giáo viên" placeholder="Chọn giáo viên phụ trách... (mặc định GV chính của lớp)" :options="$teacherOptions" />

                    <x-ui.select label="Giáo trình" name="curriculum_id" hint="Mặc định theo Trình độ của lớp." x-model="curriculumId" x-on:change="stageId = ''" placeholder="— Theo trình độ của lớp —">
                        @foreach ($curriculums as $c)
                            <option value="{{ $c->id }}">{{ $c->title }} ({{ $c->code }})</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select label="Chọn chặng học" name="stage_id" hint="Để trống: chặng đầu tiên lớp chưa học xong." x-model="stageId" placeholder="Chọn chặng giáo trình... (chặng kế tiếp của lớp)">
                        <template x-for="s in stages" :key="s.id">
                            <option :value="String(s.id)" x-text="s.label" :selected="String(s.id) === stageId"></option>
                        </template>
                    </x-ui.select>

                    <div class="grid grid-cols-2 gap-md">
                        <x-ui.input type="date" name="start_date" label="Ngày bắt đầu" :value="old('start_date', now()->toDateString())" />
                        <x-ui.input type="date" name="deadline" label="Dự kiến hoàn thành" />
                    </div>

                    @if ($canOverride)
                        <div class="border-t border-outline-variant pt-sm space-y-2">
                            <label class="flex items-start gap-2 font-body-small text-body-small text-on-surface">
                                <input type="checkbox" name="replace_current" value="1" x-model="replace" class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary-container">
                                <span><strong>Chuyển chặng (Học thuật):</strong> đóng chặng đang mở của lớp rồi mở chặng đã chọn.</span>
                            </label>
                            <div x-show="replace" x-cloak>
                                <x-ui.textarea name="reason" label="Lý do" rows="2" placeholder="VD: Lớp đã thi chặng 1 ở cơ sở cũ, chuyển thẳng chặng 2" />
                            </div>
                        </div>
                    @endif

                    <x-ui.alert type="info" class="font-body-small text-body-small">Mỗi LỚP HỌC chỉ được giao duy nhất 1 chặng học có hiệu lực tại một thời điểm. Hệ thống sẽ tự động đóng chặng hiện tại của lớp và mở chặng kế tiếp khi kết quả Big Test được duyệt gửi.</x-ui.alert>

                    <x-ui.button type="submit" icon="send" class="w-full justify-center">Xác nhận giao chặng</x-ui.button>
                </form>
            </section>
            @endif

            <section class="rounded-xl border border-outline-variant bg-surface-container-low p-lg flex items-start justify-between gap-md">
                <div>
                    <h4 class="font-body-medium text-body-medium font-semibold text-on-surface">Cần hỗ trợ?</h4>
                    <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Liên hệ bộ phận Học thuật hoặc Kỹ thuật nếu bạn gặp vấn đề trong quá trình phân quyền giáo trình.</p>
                    @if (Route::has('tickets.create'))
                        <a href="{{ route('tickets.create') }}" class="mt-sm inline-flex items-center gap-1 font-body-small text-body-small font-semibold text-primary hover:underline">Tạo ticket hỗ trợ <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
                    @endif
                </div>
                <span class="material-symbols-outlined text-[32px] text-on-surface-variant">contact_support</span>
            </section>
        </aside>

        {{-- Lịch sử phân quyền chặng học --}}
        <div class="xl:col-span-8 min-w-0">
            <x-ui.data-table min-width="860px">
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">history_edu</span>
                        <h2 class="font-h3 text-h3 text-on-surface">Lịch sử phân quyền chặng học</h2>
                        <x-ui.badge>{{ $assignments->total() }} lượt</x-ui.badge>
                    </div>
                    <form method="GET" action="{{ route('syllabus.assignments') }}" class="flex flex-wrap items-center gap-2">
                        <x-ui.input type="search" name="search" icon="search" :value="request('search')" placeholder="Tìm tên giáo viên, lớp..." />
                        <x-ui.select name="class_id" placeholder="Tất cả lớp" :options="$classes->pluck('name', 'id')" onchange="this.form.submit()" />
                        <x-ui.select name="status" :value="$status" placeholder="Tất cả trạng thái" :options="['in_progress' => 'Đang hiệu lực', 'completed' => 'Đã đóng']" onchange="this.form.submit()" />
                    </form>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Lớp học</th>
                            <th>Giáo viên</th>
                            <th>Chặng học</th>
                            <th>Thời hạn</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($assignments as $as)
                            <tr class="align-top">
                                <td>
                                    <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ $as->classModel?->name ?? 'Chưa gắn lớp' }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ collect([$as->classModel?->branch?->name, $as->classModel?->room])->filter()->implode(' - ') ?: '—' }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $as->curriculum?->title }}</p>
                                </td>
                                <td>
                                    <div class="flex items-center gap-sm">
                                        <x-ui.avatar :name="$as->teacher?->name ?? '?'" size="sm" />
                                        <div>
                                            <p class="font-body-medium text-body-small text-on-surface">{{ $as->teacher?->name ?? '—' }}</p>
                                            @if ($as->teacher?->employee_code)<p class="font-caption text-caption text-on-surface-variant">ID: {{ $as->teacher->employee_code }}</p>@endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="inline-flex rounded-md bg-primary-fixed/50 px-sm py-0.5 font-label text-label text-primary">{{ $as->stage?->label ?? $as->stage_name ?? '—' }}</span>
                                    <p class="font-caption text-caption text-on-surface-variant mt-1">{{ $as->assigned_chapters }}</p>
                                    @if ($as->extra_sessions)
                                        <p class="font-caption text-caption text-warning">+{{ $as->extra_sessions }} buổi giãn tiến độ</p>
                                    @endif
                                </td>
                                <td class="font-caption text-caption space-y-1 whitespace-nowrap">
                                    <p class="flex items-center gap-1 text-on-surface"><span class="material-symbols-outlined text-[14px]">calendar_today</span>{{ ($as->opened_at ?? $as->created_at)?->format('d/m/Y') }}</p>
                                    @if ($as->closed_at)
                                        <p class="flex items-center gap-1 text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">event_busy</span>{{ $as->closed_at->format('d/m/Y') }}</p>
                                        @if ($as->close_reason)<p class="max-w-[200px] whitespace-normal text-on-surface-variant">{{ $as->close_reason }}</p>@endif
                                    @else
                                        <p class="flex items-center gap-1 text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">event_repeat</span>Tự động đóng theo sự kiện</p>
                                        @if ($as->deadline)<p class="text-on-surface-variant">Dự kiến xong: {{ $as->deadline->format('d/m/Y') }}</p>@endif
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($as->isOpen())
                                        <x-ui.badge color="success">Đang học</x-ui.badge>
                                        <p class="font-caption text-caption text-tertiary mt-1">Đang hiệu lực</p>
                                    @else
                                        <x-ui.badge>Đã đóng</x-ui.badge>
                                        @if ($as->curriculum_completed_at)
                                            <p class="font-caption text-caption text-tertiary mt-1">Hoàn thành giáo trình</p>
                                        @endif
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    @if ($as->isOpen())
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($canManage)
                                                <x-ui.button variant="ghost" size="sm" icon="edit" title="Chỉnh sửa"
                                                             @click="editUrl = @js(route('syllabus.assignments.update', $as->id)); edit = { label: @js(($as->stage?->label ?? $as->stage_name).' — lớp '.$as->classModel?->name), user_id: @js((string) $as->user_id), start_date: @js(($as->opened_at ?? $as->created_at)?->toDateString()), deadline: @js($as->deadline?->toDateString() ?? '') }; $dispatch('open-modal', 'edit-stage')" />
                                            @endif
                                            @if ($canOverride)
                                                <x-ui.button variant="secondary" size="sm" icon="lock"
                                                             @click="closeUrl = @js(route('syllabus.assignments.close', $as->id)); closeLabel = @js(($as->stage?->label ?? $as->stage_name).' — lớp '.$as->classModel?->name); $dispatch('open-modal', 'close-stage')">Đóng tay</x-ui.button>
                                            @endif
                                        </div>
                                    @else
                                        <span class="font-caption text-caption text-on-surface-variant" title="{{ $as->closingBigTest ? 'Đóng bởi Big Test '.$as->closingBigTest->code : '' }}">{{ $as->closingBigTest ? 'Big Test '.$as->closingBigTest->code : '—' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-ui.empty-state icon="assignment_ind" title="Chưa có lớp nào được giao chặng" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$assignments" unit="lượt" /></x-slot:footer>
            </x-ui.data-table>
        </div>

        @if ($canManage)
            <x-ui.modal name="edit-stage" title="Chỉnh sửa chặng đang hiệu lực" max-width="md">
                <form id="edit-stage-form" method="POST" :action="editUrl" class="space-y-3 p-md">
                    @csrf @method('PUT')
                    <p class="font-body-small text-body-small text-on-surface-variant">Chặng: <strong x-text="edit.label"></strong>. Muốn đổi sang chặng khác, dùng "Chuyển chặng" (Học thuật, bắt buộc lý do).</p>
                    <x-ui.select label="Giáo viên phụ trách" name="user_id" id="edit_user_id" x-model="edit.user_id" required :options="$teacherOptions" />
                    <div class="grid grid-cols-2 gap-md">
                        <x-ui.date label="Ngày bắt đầu" name="start_date" id="edit_start_date" x-model="edit.start_date" />
                        <x-ui.date label="Dự kiến hoàn thành" name="deadline" id="edit_deadline" x-model="edit.deadline" />
                    </div>
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'edit-stage')">Hủy</x-ui.button>
                    <x-ui.button type="submit" form="edit-stage-form" icon="save">Lưu thay đổi</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif

        @if ($canOverride)
            <x-ui.modal name="close-stage" title="Đóng tay chặng đang mở" max-width="md" :show="$errors->has('reason') && ! old('class_id')">
                <form id="close-stage-form" method="POST" :action="closeUrl" class="space-y-3 p-md">
                    @csrf
                    <p class="font-body-small text-body-small text-on-surface-variant">Chặng: <strong x-text="closeLabel"></strong>. Thông thường chặng tự đóng khi Big Test được duyệt và gửi phụ huynh — chỉ đóng tay khi có ngoại lệ.</p>
                    <x-ui.textarea name="reason" label="Lý do" required rows="3" />
                    <label class="flex items-center gap-2 font-body-small text-body-small text-on-surface">
                        <input type="checkbox" name="open_next" value="1" checked class="rounded border-outline-variant text-primary focus:ring-primary-container">
                        Mở luôn chặng kế tiếp của giáo trình
                    </label>
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'close-stage')">Hủy</x-ui.button>
                    <x-ui.button type="submit" form="close-stage-form" icon="lock">Đóng chặng</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>
</x-app-layout>
