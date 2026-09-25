<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-orange-100 rounded-xl flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-[24px]">assignment_ind</span>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight">Chặng học của lớp</h1>
                    <p class="text-xs text-gray-500">Mỗi lớp học 1 chặng tại một thời điểm. Chặng đóng khi Big Test được duyệt và gửi phụ huynh, chặng kế tiếp tự mở.</p>
                </div>
            </div>
            <x-ui.button icon="menu_book" :href="route('syllabus.teacher-view')">Màn GV xem giáo trình</x-ui.button>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 3])

    @php
        $canOverride = auth()->user()->can('syllabus.approve_adjustment');
        $curriculumData = $curriculums->map(fn ($c) => ['id' => $c->id, 'stages' => $c->stages->map(fn ($s) => ['id' => $s->id, 'label' => $s->label])->values()])->values();
        $classCurriculum = $classes->mapWithKeys(fn ($c) => [$c->id => $levelCurriculum[$c->level] ?? null]);
        $classOpenStage = $openByClass->map(fn ($a) => $a->stage_name);
    @endphp

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6"
         x-data="{ closeUrl: '', closeLabel: '' }">
        {{-- Mở chặng cho lớp --}}
        <aside class="xl:col-span-4 space-y-6">
            <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm"
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
                <div class="flex items-center gap-2 mb-5 pb-3 border-b border-gray-100">
                    <span class="material-symbols-outlined text-primary">add_task</span>
                    <h2 class="text-sm font-bold text-gray-900">Mở chặng cho lớp</h2>
                </div>

                <form action="{{ route('syllabus.assignments.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <x-ui.field label="Lớp" name="class_id" required>
                        <select name="class_id" required x-model="classId" @change="pickClass()" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm text-body-base">
                            <option value="" disabled>Chọn lớp...</option>
                            @foreach ($classes as $class)
                                <option value="{{ $class->id }}">{{ $class->name }} ({{ $class->code }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <p x-show="classOpen[classId]" x-cloak class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2">
                        Lớp đang học <strong x-text="classOpen[classId]"></strong>. Chặng mới chỉ mở được sau khi chặng này đóng@if ($canOverride), hoặc chọn "Chuyển chặng" bên dưới@endif.
                    </p>

                    <x-ui.field label="Giáo trình" name="curriculum_id" hint="Mặc định theo Trình độ của lớp.">
                        <select name="curriculum_id" x-model="curriculumId" @change="stageId = ''" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm text-body-base">
                            <option value="">— Theo trình độ của lớp —</option>
                            @foreach ($curriculums as $c)
                                <option value="{{ $c->id }}">{{ $c->title }} ({{ $c->code }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <x-ui.field label="Chặng" name="stage_id" hint="Để trống: chặng đầu tiên lớp chưa học xong.">
                        <select name="stage_id" x-model="stageId" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm text-body-base">
                            <option value="">— Chặng kế tiếp của lớp —</option>
                            <template x-for="s in stages" :key="s.id">
                                <option :value="String(s.id)" x-text="s.label" :selected="String(s.id) === stageId"></option>
                            </template>
                        </select>
                    </x-ui.field>

                    <x-ui.select name="user_id" label="Giáo viên phụ trách" placeholder="— GV chính của lớp —"
                                 :options="$teachers->mapWithKeys(fn ($t) => [$t->id => $t->name])" />
                    <x-ui.input type="date" name="deadline" label="Dự kiến hoàn thành chặng" />

                    @if ($canOverride)
                        <div class="border-t border-gray-100 pt-3 space-y-2">
                            <label class="flex items-start gap-2 text-xs text-gray-700">
                                <input type="checkbox" name="replace_current" value="1" x-model="replace" class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary-container">
                                <span><strong>Chuyển chặng (Học thuật):</strong> đóng chặng đang mở của lớp rồi mở chặng đã chọn.</span>
                            </label>
                            <div x-show="replace" x-cloak>
                                <x-ui.textarea name="reason" label="Lý do" rows="2" placeholder="VD: Lớp đã thi chặng 1 ở cơ sở cũ, chuyển thẳng chặng 2" />
                            </div>
                        </div>
                    @endif

                    <x-ui.button type="submit" icon="send" class="w-full justify-center">Mở chặng</x-ui.button>
                </form>
            </section>
        </aside>

        {{-- Lịch sử chặng của các lớp --}}
        <div class="xl:col-span-8">
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3.5 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3 bg-gray-50/50">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">history_edu</span>
                        <h2 class="text-sm font-bold text-gray-900">Chặng của các lớp</h2>
                        <x-ui.badge>{{ $assignments->total() }} lượt</x-ui.badge>
                    </div>
                    <form method="GET" action="{{ route('syllabus.assignments') }}" class="flex items-center gap-2">
                        <div class="relative">
                            <input type="search" name="search" value="{{ request('search') }}" class="pl-9 pr-3 py-1.5 bg-white border border-gray-200 rounded-xl text-xs focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none w-56" placeholder="Tìm giáo viên, lớp, chặng..." />
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">search</span>
                        </div>
                        <x-ui.select name="class_id" placeholder="Tất cả lớp" :options="$classes->pluck('name', 'id')" onchange="this.form.submit()" />
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="py-3 px-4">Lớp / Giáo trình</th>
                                <th class="py-3 px-4">Chặng</th>
                                <th class="py-3 px-4">Giáo viên</th>
                                <th class="py-3 px-4">Mở / Đóng</th>
                                <th class="py-3 px-4 text-right">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            @forelse ($assignments as $as)
                                <tr class="align-top">
                                    <td class="py-3.5 px-4">
                                        <p class="font-semibold text-gray-900">{{ $as->classModel?->name ?? 'Chưa gắn lớp' }}</p>
                                        <p class="text-[11px] text-gray-400">{{ $as->curriculum?->title ?? '—' }}</p>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <p class="font-semibold text-primary">{{ $as->stage?->label ?? $as->stage_name ?? '—' }}</p>
                                        <p class="text-[11px] text-gray-500">{{ $as->assigned_chapters }}</p>
                                        @if ($as->extra_sessions)
                                            <p class="text-[11px] text-amber-700">+{{ $as->extra_sessions }} buổi giãn tiến độ</p>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4">{{ $as->teacher?->name ?? '—' }}</td>
                                    <td class="py-3.5 px-4 text-[11px] space-y-1">
                                        <p><span class="text-gray-400">Mở:</span> {{ ($as->opened_at ?? $as->created_at)?->format('d/m/Y') }}{{ $as->opener ? ' · '.$as->opener->name : '' }}</p>
                                        @if ($as->open_reason)<p class="text-gray-500">{{ $as->open_reason }}</p>@endif
                                        @if ($as->closed_at)
                                            <p><span class="text-gray-400">Đóng:</span> {{ $as->closed_at->format('d/m/Y') }}{{ $as->closer ? ' · '.$as->closer->name : '' }}</p>
                                            @if ($as->close_reason)<p class="text-gray-500">{{ $as->close_reason }}</p>@endif
                                        @elseif ($as->deadline)
                                            <p><span class="text-gray-400">Dự kiến xong:</span> {{ $as->deadline->format('d/m/Y') }}</p>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                        @if ($as->isOpen())
                                            <div class="flex flex-col items-end gap-2">
                                                <x-ui.badge color="success">Đang học</x-ui.badge>
                                                @if ($canOverride)
                                                    <x-ui.button variant="secondary" size="sm" icon="lock"
                                                                 @click="closeUrl = @js(route('syllabus.assignments.close', $as->id)); closeLabel = @js(($as->stage?->label ?? $as->stage_name).' — lớp '.$as->classModel?->name); $dispatch('open-modal', 'close-stage')">Đóng tay</x-ui.button>
                                                @endif
                                            </div>
                                        @else
                                            <x-ui.badge>Đã đóng</x-ui.badge>
                                            @if ($as->curriculum_completed_at)
                                                <p class="text-[11px] text-emerald-700 mt-1">Hoàn thành giáo trình</p>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center py-12 text-gray-400">Chưa có lớp nào được mở chặng.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-gray-100"><x-ui.pagination :paginator="$assignments" unit="lượt" /></div>
            </section>
        </div>

        @if ($canOverride)
            <x-ui.modal name="close-stage" title="Đóng tay chặng đang mở" max-width="md" :show="$errors->has('reason') && ! old('class_id')">
                <form id="close-stage-form" method="POST" :action="closeUrl" class="space-y-3 p-md">
                    @csrf
                    <p class="text-xs text-gray-600">Chặng: <strong x-text="closeLabel"></strong>. Thông thường chặng tự đóng khi Big Test được duyệt và gửi phụ huynh — chỉ đóng tay khi có ngoại lệ.</p>
                    <x-ui.textarea name="reason" label="Lý do" required rows="3" />
                    <label class="flex items-center gap-2 text-xs text-gray-700">
                        <input type="checkbox" name="open_next" value="1" checked class="rounded border-gray-300 text-primary focus:ring-primary-container">
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
