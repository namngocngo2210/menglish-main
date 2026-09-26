{{-- Form giao việc cho Trợ giảng theo ca (Trước / Trong / Sau giờ học) — dùng chung trang (tasks/ta-assign) và modal ($asModal).
     Alpine x-data inline (không <script>) nên chạy được khi htmx đổ vào modal. Id trong modal có tiền tố "modal-".
     Biến: $assistants, $branches, $classes, $classSessions, $cutoff, $asModal. --}}
@php
    $asModal = $asModal ?? false;
    $p = $asModal ? 'modal-' : '';
@endphp
<div @class(['mx-auto max-w-4xl' => ! $asModal])
     x-data="{
        assignDate: @js(old('assign_date', now()->toDateString())),
        classSessions: @js($classSessions),
        tasks: @js(collect(old('tasks', [['category' => 'before']]))->values()->map(fn ($t, $i) => [
            'id' => $i + 1,
            'category' => $t['category'] ?? 'before',
            'content' => $t['content'] ?? '',
            'attach_class' => in_array($t['attach_class'] ?? null, ['1', 'on'], true),
            'class_id' => (string) ($t['class_id'] ?? ''),
            'class_session_id' => (string) ($t['class_session_id'] ?? ''),
            'session' => $t['session'] ?? '',
        ])),
        sessionsFor(item) {
            return (this.classSessions[item.class_id] || []).filter(s => s.date === this.assignDate);
        },
        addTask() { this.tasks.push({ id: Date.now(), category: 'during', content: '', attach_class: false, class_id: '', class_session_id: '', session: '' }); },
        removeTask(index) { if (this.tasks.length > 1) this.tasks.splice(index, 1); }
     }">
    <form id="{{ $p }}ta-assign-form" action="{{ route('tasks.ta-assign.store') }}" method="POST" @class(['space-y-lg', 'rounded-xl border border-outline-variant bg-surface-container-lowest p-lg' => ! $asModal])>
        @csrf
        <div class="grid grid-cols-1 gap-md md:grid-cols-3">
            <x-ui.select name="assistant_id" :id="$asModal ? 'modal-ta-assistant_id' : 'f_assistant_id'" label="Chọn Trợ giảng" required placeholder="-- Chọn Trợ giảng --"
                         :options="$assistants->mapWithKeys(fn ($ta) => [$ta->id => $ta->name])" />
            <x-ui.date label="Ngày giao việc" name="assign_date" :id="$p.'assign_date'" required x-model="assignDate" />
            <x-ui.select name="branch_id" :id="$asModal ? 'modal-ta-branch_id' : 'f_branch_id'" label="Chi nhánh" placeholder="-- Chọn Chi nhánh --" :options="$branches->pluck('name', 'id')" />
        </div>
        @if ($assistants->isEmpty())
            <x-ui.alert type="warning">Chưa có tài khoản trợ giảng nào đang hoạt động trong phạm vi bạn quản lý.</x-ui.alert>
        @endif

        <div class="space-y-md border-t border-surface-container pt-md">
            <div class="flex items-center justify-between">
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">checklist</span> Danh sách nhiệm vụ
                </h2>
                <span class="font-caption text-caption text-on-surface-variant" x-text="tasks.length + ' đầu việc'"></span>
            </div>
            <x-input-error :messages="array_merge($errors->get('tasks'), collect($errors->get('tasks.*'))->flatten()->all())" />

            <template x-for="(item, index) in tasks" :key="item.id">
                <div class="relative space-y-sm rounded-lg border border-outline-variant bg-surface-container-low p-md">
                    <x-ui.button variant="ghost" icon="delete" x-on:click="removeTask(index)" title="Xóa đầu việc" aria-label="Xóa đầu việc" class="absolute right-sm top-sm p-xs hover:bg-error-container hover:text-error" />
                    <div class="grid grid-cols-1 gap-sm pr-xl md:grid-cols-12">
                        <label class="md:col-span-3">
                            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Nhóm đầu mục</span>
                            <select :name="'tasks[' + index + '][category]'" x-model="item.category" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-sm pr-lg font-body-small text-body-small">
                                @foreach (\App\Models\WorkTask::TIME_SLOTS as $slot => $slotLabel)
                                    <option value="{{ $slot }}">{{ $slotLabel }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="md:col-span-7">
                            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Nội dung</span>
                            <textarea :name="'tasks[' + index + '][content]'" x-model="item.content" required rows="2" maxlength="500" placeholder="Nhập nội dung công việc..."
                                      class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small"></textarea>
                        </label>
                        <label class="flex items-center gap-xs md:col-span-2 md:pt-lg">
                            <input type="hidden" :name="'tasks[' + index + '][attach_class]'" :value="item.attach_class ? '1' : '0'">
                            <input type="checkbox" x-model="item.attach_class" class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container">
                            <span class="font-body-small text-body-small font-medium">Gắn lớp?</span>
                        </label>
                    </div>
                    <div x-show="item.attach_class" x-cloak class="grid grid-cols-1 gap-sm border-t border-dashed border-outline-variant pt-sm md:grid-cols-2">
                        <label>
                            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Lớp học <span class="text-error">*</span></span>
                            <select :name="'tasks[' + index + '][class_id]'" x-model="item.class_id" x-on:change="item.class_session_id = ''" :required="item.attach_class"
                                    class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-sm pr-lg font-body-small text-body-small">
                                <option value="">Chọn lớp học</option>
                                @foreach ($classes as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }}){{ $c->schedule_text ? ' - '.$c->schedule_text : '' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Buổi học <span class="text-error">*</span></span>
                            <select x-show="sessionsFor(item).length > 0" :name="sessionsFor(item).length > 0 ? 'tasks[' + index + '][class_session_id]' : ''" x-model="item.class_session_id"
                                    class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-sm pr-lg font-body-small text-body-small">
                                <option value="">Chọn buổi học</option>
                                <template x-for="s in sessionsFor(item)" :key="s.id">
                                    <option :value="s.id" x-text="s.label" :selected="String(s.id) === item.class_session_id"></option>
                                </template>
                            </select>
                            <input x-show="sessionsFor(item).length === 0" type="text" :name="sessionsFor(item).length === 0 ? 'tasks[' + index + '][session]' : ''" x-model="item.session"
                                   placeholder="Lớp không có buổi học trong ngày — nhập tên buổi"
                                   class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small">
                        </label>
                    </div>
                </div>
            </template>

            <x-ui.button variant="secondary" icon="add" class="w-full border-dashed" x-on:click="addTask()">Thêm đầu việc</x-ui.button>
        </div>

        <div class="flex flex-col items-center gap-xs border-t border-surface-container pt-md">
            @unless ($asModal)
                <x-ui.button type="submit" icon="send" class="w-full sm:w-auto sm:min-w-[220px]">Gửi nhiệm vụ</x-ui.button>
            @endunless
            <p class="font-body-small text-body-small text-on-surface-variant">Khuyến nghị gửi trước {{ str_replace(':', 'h', $cutoff) }} — gửi trễ vẫn được, hệ thống sẽ báo Admin.</p>
            <p class="font-caption text-caption text-on-surface-variant">Hạn mỗi ca: gắn buổi học → Trước giờ học = giờ vào lớp, Trong giờ học = giờ tan lớp, Sau giờ học = tan lớp + 60 phút; không gắn buổi → 14:00 / 18:00 / 21:30.</p>
        </div>
    </form>
</div>
