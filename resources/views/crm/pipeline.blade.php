<x-app-layout>
    @include('crm.partials.header-tabs')

    {{-- Màu cột theo mockup pipeline-tong-quan-giai-doan (CrmCustomer::stageStyle, chuỗi đầy đủ để Tailwind quét):
         bg-secondary text-secondary border-secondary/20 bg-tertiary text-tertiary border-tertiary/20 bg-primary text-primary border-primary/20
         bg-blue-600 text-blue-600 border-blue-600/20 bg-purple-600 text-purple-600 border-purple-600/20 bg-orange-500 text-orange-500 border-orange-500/20
         bg-indigo-600 text-indigo-600 border-indigo-600/20 bg-emerald-600 text-emerald-600 border-emerald-600/20
         border-l-error border-l-amber-500 border-l-tertiary border-l-emerald-600 border-l-outline-variant --}}
    <div class="space-y-md" x-data="crmKanban(@js($stagePermissions))">
        <!-- Toast Notification -->
        <div
            x-show="toast.show"
            x-cloak
            x-transition:enter="transition ease-out duration-300 transform"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200 transform"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-2"
            class="fixed bottom-6 right-6 z-50 flex items-center gap-sm rounded-xl border px-md py-sm font-body-small text-body-small font-semibold shadow-level-3"
            :class="toast.type === 'error' ? 'bg-error-container border-error/20 text-on-error-container' : 'bg-tertiary-fixed/30 border-tertiary/20 text-on-tertiary-fixed-variant'"
        >
            <span class="material-symbols-outlined text-[20px]" x-text="toast.type === 'error' ? 'error' : 'check_circle'"></span>
            <span x-text="toast.message"></span>
        </div>

        @unless ($stagePermissions['canForward'])
            <x-ui.alert type="info">Giai đoạn khách do Học vụ / Quản lý cơ sở chuyển. Bạn vẫn cập nhật thông tin và ghi nhật ký chăm sóc trong hồ sơ khách.</x-ui.alert>
        @endunless

        @include('crm.partials.list-filters', ['dateLabel' => 'Ngày tạo'])

        <!-- Kanban 8 cột (mockup: tiêu đề cột = chấm màu + TÊN (số lượng)) -->
        <div class="custom-scrollbar overflow-x-auto pb-md">
            <div class="flex min-h-[calc(100vh-320px)] min-w-max items-start gap-md">
                @foreach ($stages as $index => $stage)
                    <div
                        class="kanban-column flex w-[280px] shrink-0 flex-col gap-md rounded-xl transition-colors duration-200"
                        data-stage-id="{{ $stage['id'] }}"
                        data-stage-index="{{ $index }}"
                        @dragover.prevent="onDragOver($event, @js($stage['id']))"
                        @dragleave="onDragLeave($event)"
                        @drop="onDrop($event, @js($stage['id']))"
                    >
                        <!-- Column Header -->
                        <div class="flex items-center justify-between border-b px-xs py-xs {{ $stage['header_border'] }}">
                            <h3 class="flex items-center gap-sm font-label text-label uppercase {{ $stage['text'] }}">
                                <span class="h-2 w-2 rounded-full {{ $stage['dot'] }}"></span>
                                {{ $stage['name'] }} (<span id="badge-count-{{ $stage['id'] }}">{{ $stage['count'] }}</span>)
                            </h3>
                            @if ((float) $stage['amount_raw'] > 0)
                                <span class="font-code text-caption text-on-surface-variant" title="Tổng giá trị hợp đồng">{{ $stage['amount'] }}</span>
                            @endif
                        </div>

                        <!-- Cards list container -->
                        <div class="cards-container min-h-[120px] space-y-md py-xs" id="column-cards-{{ $stage['id'] }}">
                            @forelse ($stage['leads'] as $lead)
                                @php
                                    $draggable = ($stagePermissions['canForward'] && $stage['next'] && ! in_array($stage['next'], $stagePermissions['closed'], true))
                                        || ($stagePermissions['canBackward'] && $index > 0 && ! in_array($stage['id'], $stagePermissions['closed'], true));
                                    $accent = match (true) {
                                        $stage['id'] === 'won' => 'border-l-emerald-600',
                                        $lead['follow_up_state'] === 'overdue' => 'border-l-error',
                                        $lead['follow_up_state'] === 'due_soon' => 'border-l-amber-500',
                                        $lead['follow_up_state'] === 'on_time' => 'border-l-tertiary',
                                        default => 'border-l-outline-variant',
                                    };
                                    $canEditStage = ($stagePermissions['canForward'] || $stagePermissions['canBackward']) && ! in_array($stage['id'], $stagePermissions['closed'], true);
                                @endphp
                                <div
                                    class="kanban-card group relative space-y-md rounded-lg border border-l-4 border-outline-variant/30 bg-surface-container-lowest p-md shadow-level-2 transition-all hover:shadow-level-3 {{ $accent }} {{ $draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer' }}"
                                    draggable="{{ $draggable ? 'true' : 'false' }}"
                                    data-customer-id="{{ $lead['id'] }}"
                                    data-stage-id="{{ $stage['id'] }}"
                                    data-stage-index="{{ $index }}"
                                    @dragstart="onDragStart($event, {{ (int) $lead['id'] }}, @js($stage['id']), @js($lead['name']))"
                                    @dragend="onDragEnd($event)"
                                    @click="openLeadDetails(@js(route('crm.customers.show', $lead['id'])))"
                                >
                                    @if ($canEditStage)
                                        <button
                                            type="button"
                                            @click.stop="openStageEdit({{ (int) $lead['id'] }}, @js($lead['name']), @js($stage['id']))"
                                            class="absolute right-2 top-2 rounded bg-surface-container-low px-1 font-caption text-[10px] text-on-surface-variant opacity-100 transition-opacity hover:text-primary md:opacity-0 md:group-hover:opacity-100"
                                            title="Sửa giai đoạn"
                                        >Sửa giai đoạn</button>
                                    @endif

                                    <div class="flex items-start justify-between gap-sm pr-md">
                                        <div class="min-w-0">
                                            <h4 class="line-clamp-2 font-h3 text-[14px] font-bold uppercase leading-tight text-on-surface">{{ $lead['name'] }}</h4>
                                            <p class="mt-1 font-code text-body-small text-on-surface-variant">{{ $lead['phone'] }}</p>
                                        </div>
                                        <span class="max-w-[96px] shrink-0 truncate rounded px-sm py-[2px] font-caption text-[10px] font-bold {{ $stage['source_badge'] }}" title="Nguồn: {{ $lead['source'] }}">{{ $lead['source'] }}</span>
                                    </div>

                                    <div class="space-y-1.5 text-body-small">
                                        <div class="flex items-center gap-xs text-on-surface-variant">
                                            <span class="material-symbols-outlined text-[16px]">person</span>
                                            <span class="truncate">Phụ huynh: {{ $lead['parent_name'] ?: '—' }}</span>
                                        </div>
                                        <div class="flex items-center gap-xs text-on-surface-variant">
                                            <span class="material-symbols-outlined text-[16px]">account_circle</span>
                                            <span class="truncate">Phụ trách: <span class="font-medium text-on-surface">{{ $lead['agent'] }}</span></span>
                                        </div>
                                        @if ($lead['has_test_result'])
                                            <div class="flex items-center gap-xs text-on-surface-variant">
                                                <span class="material-symbols-outlined text-[16px]">quiz</span>
                                                <span class="truncate">Điểm test: {{ $lead['score'] }}</span>
                                            </div>
                                        @endif
                                        @if ($lead['status'])
                                            <div class="flex items-center gap-xs text-on-surface-variant">
                                                <span class="material-symbols-outlined text-[16px]">payments</span>
                                                <span class="truncate">Học phí: {{ $lead['status'] }}</span>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="space-y-md border-t border-surface-container-highest pt-sm">
                                        @if ($stage['id'] === 'won')
                                            <div class="flex items-center gap-xs font-body-small text-body-small font-medium text-emerald-600">
                                                <span class="material-symbols-outlined text-[18px]">verified</span>
                                                {{ $lead['confirmed'] ? 'Đã hoàn tất hồ sơ' : 'Đã chốt — chờ xác nhận chính thức' }}
                                            </div>
                                        @else
                                            @if ($lead['follow_up_state'])
                                                <div class="flex items-center justify-between gap-sm">
                                                    @if ($lead['follow_up_state'] === 'overdue')
                                                        <span class="flex shrink-0 items-center gap-xs rounded-full bg-error-container px-sm py-xs font-caption text-[10px] font-bold text-error">
                                                            <span class="material-symbols-outlined text-[14px]">alarm_on</span>Quá hạn
                                                        </span>
                                                    @elseif ($lead['follow_up_state'] === 'due_soon')
                                                        <span class="flex shrink-0 items-center gap-xs rounded-full bg-orange-100 px-sm py-xs font-caption text-[10px] font-bold text-orange-600">
                                                            <span class="material-symbols-outlined text-[14px]">priority_high</span>Sắp hết hạn
                                                        </span>
                                                    @else
                                                        <span class="flex shrink-0 items-center gap-xs rounded-full bg-green-100 px-sm py-xs font-caption text-[10px] font-bold text-green-600">
                                                            <span class="material-symbols-outlined text-[14px]">check_circle</span>Còn hạn
                                                        </span>
                                                    @endif
                                                    <div class="text-right font-caption text-caption font-medium text-on-surface-variant" title="{{ $lead['follow_up_at'] }}">
                                                        {{ $stage['id'] === 'new' ? 'Hạn liên hệ' : 'Hạn chăm sóc tiếp theo' }}: {{ $lead['follow_up_label'] }}
                                                    </div>
                                                </div>
                                            @endif

                                            @if ($stage['id'] === 'waiting_class')
                                                @can('student.assign_class')
                                                    <a href="{{ route('crm.waiting-list') }}" @click.stop class="flex w-full items-center justify-center gap-xs rounded-lg bg-primary-container py-2 font-body-medium text-body-medium text-white shadow-sm transition-all hover:brightness-110">
                                                        <span class="material-symbols-outlined text-[18px]">assignment_turned_in</span>Gán lớp
                                                    </a>
                                                @endcan
                                            @elseif ($stagePermissions['canForward'] && $stage['next'] && ! in_array($stage['next'], $stagePermissions['closed'], true))
                                                <button
                                                    type="button"
                                                    @click.stop="moveToNextStage({{ (int) $lead['id'] }}, @js($lead['name']))"
                                                    class="w-full rounded-lg bg-primary-container py-2 font-body-medium text-body-medium text-white shadow-sm transition-all hover:brightness-110"
                                                    title="Chuyển sang: {{ $stagePermissions['labels'][$stage['next']] }}"
                                                >Sang bước tiếp theo</button>
                                            @endif

                                            @if (in_array($stage['id'], \App\Models\CrmCustomer::CLOSABLE_STAGES, true) && $stagePermissions['canConvert'])
                                                <a href="{{ route('crm.closing-wizard', ['customer_id' => $lead['id']]) }}" @click.stop
                                                   class="flex w-full items-center justify-center gap-xs rounded-lg border border-primary-container/40 py-1.5 font-body-medium text-body-small text-primary transition-colors hover:bg-primary-container/10">
                                                    <span class="material-symbols-outlined text-[16px]">how_to_reg</span>Chốt &amp; Xếp lớp
                                                </a>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="px-xs py-md text-center font-caption text-caption text-on-surface-variant/70">Chưa có khách</p>
                            @endforelse
                        </div>

                        @can('lead.create')
                            @if ($stage['id'] === 'new')
                                <a href="{{ route('crm.customers.create') }}" class="flex w-full items-center justify-center gap-xs rounded-lg border-2 border-dashed border-outline-variant py-sm font-body-small text-body-small font-bold text-on-surface-variant transition hover:border-primary-container/50 hover:text-primary">
                                    <span class="material-symbols-outlined text-[18px]">add</span>
                                    <span>Thêm khách mới</span>
                                </a>
                            @endif
                        @endcan
                    </div>
                @endforeach
            </div>
        </div>

        @if ($stagePermissions['canForward'] || $stagePermissions['canBackward'])
            <!-- Sửa giai đoạn (A6): CM tiến 1 bước; chỉ Admin lùi bước (bắt buộc lý do); Thất bại bắt buộc lý do -->
            <div x-show="stageEdit.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-md" role="dialog" aria-modal="true">
                <div class="w-full max-w-md space-y-sm rounded-xl bg-surface-container-lowest p-lg font-body-small text-body-small shadow-level-3" @click.outside="stageEdit.open = false">
                    <h3 class="font-h3 text-h3 text-on-surface">Sửa giai đoạn: <span x-text="stageEdit.name"></span></h3>
                    <p class="text-on-surface-variant">Hiện tại: <strong x-text="permissions.labels[stageEdit.from]"></strong>. Học vụ / Quản lý cơ sở chỉ chuyển tiến 1 bước; chỉ Admin được lùi bước. Mọi thay đổi được lưu vào lịch sử khách.</p>
                    @if ($stagePermissions['canBackward'])
                        <p class="font-semibold text-error">Lùi giai đoạn: bắt buộc nhập lý do (không áp dụng cho khách đã chốt).</p>
                    @endif
                    <select x-model="stageEdit.target" class="w-full rounded-lg border-outline-variant font-body-base text-body-base" aria-label="Giai đoạn mới">
                        <template x-for="option in stageEditOptions()" :key="option.value">
                            <option :value="option.value" x-text="option.label"></option>
                        </template>
                    </select>
                    <textarea x-show="stageEditNeedsReason()" x-model="stageEdit.reason" rows="3" :placeholder="stageEdit.target === 'lost' ? 'Lý do thất bại (bắt buộc)' : 'Lý do lùi giai đoạn (bắt buộc)'" class="w-full rounded-lg border-outline-variant font-body-base text-body-base"></textarea>
                    <div class="flex justify-end gap-sm">
                        <button type="button" @click="stageEdit.open = false" class="rounded-lg border border-outline-variant px-md py-sm font-body-medium text-body-medium">Hủy</button>
                        <button type="button" @click="submitStageEdit()" :disabled="!stageEdit.target || (stageEditNeedsReason() && !stageEdit.reason.trim())" class="rounded-lg bg-primary-container px-md py-sm font-body-medium text-body-medium text-white disabled:opacity-50">Lưu giai đoạn</button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script>
        function crmKanban(permissions) {
            return {
                permissions: permissions,
                draggedCard: null,
                stageEdit: { open: false, customerId: null, name: '', from: '', target: '', reason: '' },
                toast: {
                    show: false,
                    message: '',
                    type: 'success',
                    timer: null
                },

                showToast(message, type = 'success') {
                    if (this.toast.timer) clearTimeout(this.toast.timer);
                    this.toast.message = message;
                    this.toast.type = type;
                    this.toast.show = true;
                    this.toast.timer = setTimeout(() => {
                        this.toast.show = false;
                    }, 3500);
                },

                openLeadDetails(url) {
                    window.location.href = url;
                },

                indexOf(stage) {
                    return this.permissions.order.indexOf(stage);
                },

                // CM: chỉ tiến đúng 1 bước (không kéo vào Chờ xếp lớp / Đã chốt). Admin: được lùi (kèm lý do).
                transitionType(sourceStage, targetStage) {
                    const from = this.indexOf(sourceStage);
                    const to = this.indexOf(targetStage);
                    if (from < 0 || to < 0 || from === to) return null;
                    if (to === from + 1 && this.permissions.canForward && !this.permissions.closed.includes(targetStage)) return 'forward';
                    if (to < from && this.permissions.canBackward && !this.permissions.closed.includes(sourceStage)) return 'backward';
                    return null;
                },

                // Lựa chọn trong modal "Sửa giai đoạn" theo quyền (A6).
                stageEditOptions() {
                    const from = this.stageEdit.from;
                    const options = [];
                    const next = this.permissions.order[this.indexOf(from) + 1];
                    if (this.permissions.canForward && next && !this.permissions.closed.includes(next)) {
                        options.push({ value: next, label: 'Tiến 1 bước → ' + this.permissions.labels[next] });
                    }
                    if (this.permissions.canBackward && !this.permissions.closed.includes(from)) {
                        this.permissions.order.slice(0, Math.max(0, this.indexOf(from))).reverse()
                            .forEach(stage => options.push({ value: stage, label: 'Lùi về ← ' + this.permissions.labels[stage] }));
                    }
                    if (this.permissions.canForward && !this.permissions.closed.includes(from)) {
                        options.push({ value: 'lost', label: 'Chuyển sang Thất bại' });
                    }
                    return options;
                },

                stageEditNeedsReason() {
                    return this.stageEdit.target === 'lost' || this.indexOf(this.stageEdit.target) < this.indexOf(this.stageEdit.from);
                },

                openStageEdit(customerId, name, fromStage, target = null) {
                    this.stageEdit = { open: true, customerId, name, from: fromStage, target: '', reason: '' };
                    const options = this.stageEditOptions();
                    this.stageEdit.target = target || (options[0] ? options[0].value : '');
                },

                async submitStageEdit() {
                    const { customerId, target, reason } = this.stageEdit;
                    if (!target || (this.stageEditNeedsReason() && !reason.trim())) return;
                    const payload = target === 'lost' ? { stage: 'lost', lost_reason: reason } : { stage: target, reason: reason };
                    const ok = await this.postStage(customerId, payload);
                    if (ok) this.stageEdit.open = false;
                },

                onDragStart(event, customerId, stageId, name) {
                    this.draggedCard = { customerId, stageId, name, element: event.target };
                    event.target.classList.add('opacity-40', 'scale-95');
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', String(customerId));
                },

                onDragEnd(event) {
                    if (this.draggedCard && this.draggedCard.element) {
                        this.draggedCard.element.classList.remove('opacity-40', 'scale-95');
                    }
                    document.querySelectorAll('.kanban-column').forEach(col => {
                        col.classList.remove('ring-2', 'ring-primary-container', 'bg-orange-50/40');
                    });
                },

                onDragOver(event, targetStageId) {
                    if (!this.draggedCard) return;
                    if (!this.transitionType(this.draggedCard.stageId, targetStageId)) {
                        event.dataTransfer.dropEffect = 'none';
                        return;
                    }
                    event.dataTransfer.dropEffect = 'move';
                    event.currentTarget.classList.add('ring-2', 'ring-primary-container', 'bg-orange-50/40');
                },

                onDragLeave(event) {
                    event.currentTarget.classList.remove('ring-2', 'ring-primary-container', 'bg-orange-50/40');
                },

                async onDrop(event, targetStageId) {
                    event.preventDefault();
                    event.currentTarget.classList.remove('ring-2', 'ring-primary-container', 'bg-orange-50/40');
                    if (!this.draggedCard) return;

                    const { customerId, stageId: sourceStageId, name } = this.draggedCard;
                    if (sourceStageId === targetStageId) return;

                    if (['waiting_class', 'won'].includes(targetStageId)) {
                        this.showToast('Hãy dùng Chốt & Xếp lớp (hoặc Gán lớp) để chốt Lead.', 'error');
                        return;
                    }

                    const type = this.transitionType(sourceStageId, targetStageId);
                    if (type === 'backward') {
                        this.openStageEdit(customerId, name, sourceStageId, targetStageId);
                        return;
                    }
                    if (type !== 'forward') {
                        this.showToast(this.permissions.canForward
                            ? 'Chỉ được chuyển tiến 1 bước sang giai đoạn kế tiếp.'
                            : 'Bạn không có quyền chuyển giai đoạn Lead.', 'error');
                        return;
                    }

                    await this.postStage(customerId, { stage: targetStageId });
                },

                async postStage(customerId, payload) {
                    try {
                        const response = await fetch(`/crm/customers/${customerId}/stage`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify(payload)
                        });
                        const data = await response.json();
                        if (response.ok && data.success) {
                            this.showToast(data.message || 'Đã chuyển giai đoạn thành công!', 'success');
                            setTimeout(() => window.location.reload(), 400);
                            return true;
                        }
                        this.showToast(data.message || 'Không thể chuyển giai đoạn!', 'error');
                    } catch (err) {
                        this.showToast('Đã xảy ra lỗi kết nối khi chuyển giai đoạn!', 'error');
                    }
                    return false;
                },

                async moveToNextStage(customerId, customerName) {
                    try {
                        const response = await fetch(`/crm/customers/${customerId}/next-stage`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json'
                            }
                        });
                        const data = await response.json();
                        if (response.ok && data.success) {
                            this.showToast(`Đã chuyển ${customerName} sang giai đoạn: ${data.stage_label}!`, 'success');
                            setTimeout(() => window.location.reload(), 400);
                        } else {
                            this.showToast(data.message || 'Không thể chuyển tiếp giai đoạn!', 'error');
                        }
                    } catch (err) {
                        this.showToast('Đã xảy ra lỗi khi bấm chuyển giai đoạn!', 'error');
                    }
                }
            };
        }
    </script>
</x-app-layout>
