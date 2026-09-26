{{-- Form nộp báo cáo trực lớp — dùng chung trang (tasks/class-report-create) và modal ($asModal: id tiền tố "modal-", nút Nộp ở footer).
     Alpine x-data inline (không <script>) nên chạy được khi htmx đổ vào modal. Đổi lớp: form GET gửi bằng requestSubmit()
     → trong modal được hx-boost, tải lại nội dung modal theo lớp mới. Upload ảnh: form multipart (htmx gửi FormData).
     Biến: như controller createClassReport + $asModal. --}}
@php
    $asModal = $asModal ?? false;
    $p = $asModal ? 'modal-' : 'f_';
@endphp
<div @class(['space-y-md', 'mx-auto max-w-2xl pb-24 md:pb-0' => ! $asModal])
     x-data="{
        supports: {{ \Illuminate\Support\Js::from(collect(old('supports', []))->values()->map(fn ($sup, $i) => [
            'id' => $i + 1,
            'student_id' => (string) ($sup['student_id'] ?? ''),
            'absence_session' => $sup['absence_session'] ?? '',
            'reason' => $sup['reason'] ?? '',
            'action_plan' => $sup['action_plan'] ?? '',
        ])) }},
        previews: [],
        addSupport() { this.supports.push({ id: Date.now(), student_id: '', absence_session: '', reason: '', action_plan: '' }); },
        removeSupport(index) { this.supports.splice(index, 1); },
        syncPreviews(input) {
            this.previews.forEach(p => URL.revokeObjectURL(p.url));
            this.previews = Array.from(input.files).map(f => ({ name: f.name, url: URL.createObjectURL(f) }));
        },
        removePreview(index) {
            const input = this.$refs.photos;
            const dt = new DataTransfer();
            Array.from(input.files).forEach((f, i) => { if (i !== index) dt.items.add(f); });
            input.files = dt.files;
            this.syncPreviews(input);
        }
     }">

    @unless ($asModal)
        <header class="flex items-center gap-sm">
            <x-ui.button variant="ghost" icon="arrow_back" :href="route('portal.ta-tasks')" aria-label="Quay lại" />
            <div class="min-w-0">
                <h1 class="font-h2 text-h2 text-on-surface">Nộp báo cáo trực lớp</h1>
                <p class="font-body-small text-body-small text-on-surface-variant">Nội dung bài giảng, nhật ký lớp và học sinh cần bổ trợ.</p>
            </div>
        </header>
    @endunless

    @if ($classes->isEmpty())
        <x-ui.empty-state icon="class" title="Bạn chưa phụ trách lớp nào" description="Chỉ GV / GVNN / trợ giảng của lớp (hoặc người quản lý lớp) được nộp báo cáo trực lớp." />
    @else
    {{-- Đổi lớp: tải lại để lấy danh sách buổi / học sinh / người xác nhận của lớp --}}
    <form method="GET" action="{{ route('tasks.class-reports.create') }}" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
        @if ($taskId)
            <input type="hidden" name="task_id" value="{{ $taskId }}">
        @endif
        <div class="flex items-center gap-sm">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-fixed text-primary"><span class="material-symbols-outlined" aria-hidden="true">class</span></span>
            <div class="min-w-0 flex-1">
                <x-ui.select name="class_id" label="Lớp học" :id="$p.'pick_class_id'" onchange="this.form.requestSubmit()" :disabled="(bool) $task?->class_id" aria-label="Lớp học">
                    @foreach ($classes as $c)
                        <option value="{{ $c->id }}" @selected($selectedClass?->id === $c->id)>{{ $c->name }} ({{ $c->code }}){{ $c->schedule_text ? ' - '.$c->schedule_text : '' }}</option>
                    @endforeach
                </x-ui.select>
            </div>
        </div>
        @if ($task)
            <p class="mt-sm flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">assignment</span>
                Đầu việc: <span class="font-semibold text-on-surface">{{ $task->title }}</span> · giao bởi {{ $task->creator?->name ?? '—' }}
            </p>
        @endif
        <noscript><x-ui.button type="submit" size="sm" variant="secondary" class="mt-sm">Chọn lớp</x-ui.button></noscript>
    </form>

    <form id="{{ $asModal ? 'modal-' : '' }}class-report-form" action="{{ route('tasks.class-reports.store') }}" method="POST" enctype="multipart/form-data" class="space-y-md">
        @csrf
        <input type="hidden" name="class_id" value="{{ $selectedClass?->id }}">
        @if ($taskId)
            <input type="hidden" name="task_id" value="{{ $taskId }}">
        @endif
        <x-ui.errors :messages="array_merge($errors->get('class_id'), $errors->get('task_id'))" />

        <div class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
            <div class="flex items-start gap-sm">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-secondary-fixed text-secondary"><span class="material-symbols-outlined" aria-hidden="true">event_note</span></span>
                <div class="min-w-0 flex-1 space-y-xs">
                    <span class="block font-label text-label uppercase text-on-surface-variant">Buổi học</span>
                    @if ($sessionOptions->isNotEmpty())
                        <x-ui.select name="class_session_id" :id="$p.'class_session_id'" placeholder="-- Nhập tên buổi bên dưới --" aria-label="Buổi học">
                            @foreach ($sessionOptions as $sid => $label)
                                <option value="{{ $sid }}" @selected((string) old('class_session_id', $defaultSessionId) === (string) $sid)>{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    @endif
                    <x-ui.input name="session_name" :id="$p.'session_name'" :value="$task?->lesson_session" maxlength="255" aria-label="Tên buổi"
                           placeholder="{{ $sessionOptions->isNotEmpty() ? 'Hoặc nhập tên buổi (để trống = theo buổi đã chọn)' : 'VD: Buổi 5 - Listening Practice' }}" />
                    <x-ui.errors :messages="array_merge($errors->get('session_name'), $errors->get('class_session_id'))" />
                </div>
            </div>
        </div>

        <div class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
            <x-ui.textarea name="hom_nay_hoc_gi" :id="$p.'hom_nay_hoc_gi'" label="Hôm nay học gì" required rows="3" placeholder="Tóm tắt nội dung chính đã giảng dạy..." />
            <x-ui.textarea name="nhat_ky_day" :id="$p.'nhat_ky_day'" label="Nhật ký dạy (Tùy chọn)" rows="3" placeholder="Ghi chú về thái độ học tập, vấn đề phát sinh..." />

            <div class="space-y-sm">
                <span class="block font-body-small text-body-small font-medium text-on-surface">Đính kèm hình ảnh bảng/lớp <span class="text-on-surface-variant">(không bắt buộc)</span></span>
                <div class="flex flex-wrap gap-sm">
                    <template x-for="(p, i) in previews" :key="p.url">
                        <div class="relative h-20 w-20 overflow-hidden rounded-lg border border-outline-variant">
                            <img :src="p.url" :alt="p.name" class="h-full w-full object-cover">
                            <button type="button" x-on:click="removePreview(i)" class="absolute right-0.5 top-0.5 rounded-full bg-black/60 p-[2px] text-white" aria-label="Bỏ ảnh">
                                <span class="material-symbols-outlined text-[14px]">close</span>
                            </button>
                        </div>
                    </template>
                    <label class="flex h-20 w-20 cursor-pointer flex-col items-center justify-center gap-xs rounded-lg border-2 border-dashed border-outline-variant text-on-surface-variant hover:border-primary-container hover:text-primary">
                        <span class="material-symbols-outlined" aria-hidden="true">add_a_photo</span>
                        <span class="text-[11px] font-semibold">Thêm ảnh</span>
                        <input x-ref="photos" type="file" name="board_images[]" accept="image/*" multiple class="sr-only" x-on:change="syncPreviews($event.target)">
                    </label>
                </div>
                <p class="font-caption text-caption text-on-surface-variant">JPG, PNG, WEBP — tối đa 10 ảnh, mỗi ảnh ≤ 10MB.</p>
                <x-ui.errors :messages="array_merge($errors->get('board_images'), collect($errors->get('board_images.*'))->flatten()->all())" />
            </div>
        </div>

        {{-- Học sinh cần bổ trợ --}}
        <div class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
            <div class="flex items-center justify-between border-b border-surface-container pb-sm">
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-error" aria-hidden="true">warning</span> Học sinh cần bổ trợ
                </h2>
                <span class="font-caption text-caption text-on-surface-variant" x-text="supports.length + ' học sinh'"></span>
            </div>
            <x-ui.errors :messages="collect($errors->get('supports.*'))->flatten()->all()" />
            <p x-show="supports.length === 0" class="text-center font-body-small text-body-small italic text-on-surface-variant">Chưa có học sinh cần bổ trợ.</p>
            <template x-for="(sup, idx) in supports" :key="sup.id">
                <div class="relative space-y-sm rounded-lg border border-outline-variant bg-surface-container-low p-sm">
                    <x-ui.button variant="ghost" icon="delete" x-on:click="removeSupport(idx)" title="Xóa" aria-label="Xóa" class="absolute right-xs top-xs p-xs hover:bg-error-container hover:text-error" />
                    <div class="grid grid-cols-1 gap-sm pr-lg sm:grid-cols-2">
                        <label>
                            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Học sinh</span>
                            <select :name="'supports[' + idx + '][student_id]'" x-model="sup.student_id" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-sm pr-lg font-body-small text-body-small">
                                <option value="">-- Chọn học sinh --</option>
                                @foreach ($students as $st)
                                    <option value="{{ $st->id }}">{{ $st->name }} ({{ $st->code }})</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Buổi vắng (Tùy chọn)</span>
                            <select :name="'supports[' + idx + '][absence_session]'" x-model="sup.absence_session" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-sm pr-lg font-body-small text-body-small">
                                <option value="">-- Chọn buổi vắng --</option>
                                @foreach ($sessionOptions as $label)
                                    <option value="{{ $label }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Lý do <span class="text-error">*</span></span>
                        <textarea :name="'supports[' + idx + '][reason]'" x-model="sup.reason" rows="2" placeholder="Mô tả lý do cần bổ trợ..." class="w-full rounded-lg border border-outline-variant px-sm py-xs font-body-small text-body-small"></textarea>
                    </label>
                    <label class="block">
                        <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Kế hoạch xử lý</span>
                        <textarea :name="'supports[' + idx + '][action_plan]'" x-model="sup.action_plan" rows="2" placeholder="Nhập kế hoạch xử lý..." class="w-full rounded-lg border border-secondary-fixed bg-secondary-fixed/20 px-sm py-xs font-body-small text-body-small"></textarea>
                    </label>
                </div>
            </template>
            <x-ui.button variant="secondary" icon="add" class="w-full border-dashed" x-on:click="addSupport()">Thêm học sinh cần bổ trợ</x-ui.button>
        </div>

        <div class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md text-center">
            @unless ($asModal)
                <x-ui.button type="submit" icon="send" class="w-full">Nộp báo cáo</x-ui.button>
            @endunless
            <p class="font-body-small text-body-small text-on-surface-variant">
                <span class="font-semibold text-tertiary">Có ảnh đính kèm</span> → hoàn thành ngay.
                <span class="font-semibold text-error">Không có ảnh</span> →
                @if ($selectedClass?->teacher_id && (int) $selectedClass->teacher_id !== (int) auth()->id())
                    chờ GV chính xác nhận{{ $confirmer ? ' ('.$confirmer->name.')' : '' }}.
                @elseif ($confirmer)
                    lớp chưa có GV chính — chờ người giao việc ({{ $confirmer->name }}) xác nhận.
                @else
                    lớp chưa có GV chính và chưa gắn đầu việc được giao — cần đính kèm ít nhất 1 ảnh.
                @endif
            </p>
        </div>
    </form>
    @endif
</div>
