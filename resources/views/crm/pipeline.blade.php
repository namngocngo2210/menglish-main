<x-app-layout>
    @include('crm.partials.header-tabs')

    {{-- Lớp màu động của stage (CrmCustomer::stageStyle) — liệt kê để Tailwind quét:
         border-sky-500 bg-sky-50 text-sky-700 border-amber-500 bg-amber-50 text-amber-700 border-indigo-500 bg-indigo-50 text-indigo-700
         border-violet-500 bg-violet-50 text-violet-700 bg-violet-500 text-violet-600 border-purple-500 bg-purple-50 text-purple-700
         border-cyan-500 bg-cyan-50 text-cyan-700 bg-cyan-500 text-cyan-600 border-yellow-500 bg-yellow-50 text-yellow-700 bg-yellow-500 text-yellow-600
         border-emerald-500 bg-emerald-50 text-emerald-700 bg-sky-500 text-sky-600 bg-amber-500 text-amber-600 bg-indigo-500 text-indigo-600
         bg-purple-500 text-purple-600 bg-emerald-500 text-emerald-600 --}}
    <div class="space-y-4" x-data="crmKanban(@js($stagePermissions))">
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
            class="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-2xl shadow-xl border text-xs font-semibold"
            :class="toast.type === 'error' ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800'"
        >
            <span class="material-symbols-outlined text-lg" x-text="toast.type === 'error' ? 'error' : 'check_circle'"></span>
            <span x-text="toast.message"></span>
        </div>

        @unless ($stagePermissions['canForward'])
            <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-xs text-sky-800 flex items-center gap-2">
                <span class="material-symbols-outlined text-base">info</span>
                <span>Giai đoạn Lead do Học vụ / Quản lý cơ sở chuyển. Bạn vẫn cập nhật thông tin và ghi nhật ký chăm sóc trong hồ sơ Lead.</span>
            </div>
        @endunless

        @include('crm.partials.list-filters', ['dateLabel' => 'Ngày tạo'])

        <!-- Pipeline summary cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3">
            @foreach ($stages as $stage)
                <div class="bg-white rounded-2xl p-3.5 border border-gray-200 shadow-xs border-t-4 {{ $stage['color'] }}">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-600 truncate" title="{{ $stage['name'] }}">{{ $stage['name'] }}</span>
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full {{ $stage['bg_badge'] }}" id="summary-count-{{ $stage['id'] }}">{{ $stage['count'] }}</span>
                    </div>
                    <div class="mt-2 text-sm font-black text-gray-900 font-mono">{{ $stage['amount'] }}</div>
                </div>
            @endforeach
        </div>

        <!-- Kanban Board Columns -->
        <div class="flex gap-4 overflow-x-auto pb-6 items-start min-h-[calc(100vh-280px)]">
            @foreach ($stages as $index => $stage)
                <div 
                    class="kanban-column w-80 shrink-0 bg-slate-50 rounded-2xl p-3 border border-gray-200/90 flex flex-col gap-3 transition-colors duration-200"
                    data-stage-id="{{ $stage['id'] }}"
                    data-stage-index="{{ $index }}"
                    @dragover.prevent="onDragOver($event, @js($stage['id']))"
                    @dragleave="onDragLeave($event)"
                    @drop="onDrop($event, @js($stage['id']))"
                >
                    <!-- Column Header -->
                    <div class="flex items-center justify-between px-1">
                        <div class="flex items-center gap-2">
                            <h3 class="font-bold text-xs text-gray-900 uppercase tracking-wider">{{ $stage['name'] }}</h3>
                            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full {{ $stage['bg_badge'] }}" id="badge-count-{{ $stage['id'] }}">
                                {{ count($stage['leads']) }}
                            </span>
                        </div>
                        <span class="text-[11px] text-gray-400 font-mono font-bold">#{{ $index + 1 }}</span>
                    </div>

                    <!-- Cards list container -->
                    <div class="cards-container space-y-2.5 min-h-[120px]" id="column-cards-{{ $stage['id'] }}">
                        @foreach ($stage['leads'] as $lead)
                            @php($draggable = ($stagePermissions['canForward'] && $stage['next'] && ! in_array($stage['next'], $stagePermissions['closed'], true))
                                || ($stagePermissions['canBackward'] && $index > 0 && ! in_array($stage['id'], $stagePermissions['closed'], true)))
                            <div 
                                class="kanban-card bg-white rounded-xl p-3.5 border border-gray-200 shadow-xs hover:shadow-md hover:border-primary-container/50 transition duration-150 {{ $draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer' }} group relative"
                                draggable="{{ $draggable ? 'true' : 'false' }}"
                                data-customer-id="{{ $lead['id'] }}"
                                data-stage-id="{{ $stage['id'] }}"
                                data-stage-index="{{ $index }}"
                                @dragstart="onDragStart($event, {{ (int) $lead['id'] }}, @js($stage['id']), @js($lead['name']))"
                                @dragend="onDragEnd($event)"
                                @click="openLeadDetails(@js(route('crm.customers.show', $lead['id'])))"
                            >
                                <div class="flex items-start justify-between gap-2 mb-1.5">
                                    <div class="min-w-0">
                                        <h4 class="font-bold text-xs text-gray-900 group-hover:text-primary-container transition line-clamp-1">
                                            {{ $lead['name'] }}
                                        </h4>
                                        @if ($lead['parent_name'])
                                            <div class="text-[10px] text-gray-500 line-clamp-1">PH: {{ $lead['parent_name'] }}</div>
                                        @endif
                                    </div>
                                    <span class="text-[10px] text-gray-400 whitespace-nowrap shrink-0">{{ $lead['days'] }}</span>
                                </div>

                                @if ($lead['follow_up_status'] === 'overdue')
                                    <div class="mb-1.5"><x-ui.badge color="status-overdue" title="Hạn liên hệ: {{ $lead['follow_up_at'] }}">Quá hạn · {{ $lead['follow_up_at'] }}</x-ui.badge></div>
                                @elseif ($lead['follow_up_status'] === 'due_soon')
                                    <div class="mb-1.5"><x-ui.badge color="warning" title="Hạn liên hệ: {{ $lead['follow_up_at'] }}">Sắp hết hạn · {{ $lead['follow_up_at'] }}</x-ui.badge></div>
                                @endif

                                <div class="text-xs font-bold text-primary-container mb-2 flex items-center justify-between">
                                    <span>{{ $lead['tuition'] }}</span>
                                    <span class="text-[10px] font-mono font-normal text-gray-400">{{ $lead['code'] }}</span>
                                </div>

                                <div class="space-y-1.5 text-[11px] text-gray-600 mb-3">
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-[13px] text-gray-400">call</span>
                                        <span class="font-mono">{{ $lead['phone'] }}</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-[13px] text-gray-400">school</span>
                                        <span class="font-medium text-gray-800 line-clamp-1">{{ $lead['course'] }}</span>
                                    </div>

                                    <!-- Sale chăm sóc -->
                                    <div class="flex items-center gap-1.5 text-indigo-900 bg-indigo-50/80 px-2 py-1 rounded-lg border border-indigo-100/80 font-medium">
                                        <span class="material-symbols-outlined text-[14px] text-indigo-600">person</span>
                                        <span class="text-[10px] text-indigo-500 font-semibold">Sale:</span>
                                        <span class="text-indigo-950 font-bold truncate">{{ $lead['agent'] }}</span>
                                    </div>

                                    @isset($lead['score'])
                                        <div class="flex items-center gap-1.5 text-purple-700 bg-purple-50 px-2 py-0.5 rounded-md font-medium text-[10px]">
                                            <span class="material-symbols-outlined text-[12px]">quiz</span>
                                            <span>Điểm test: {{ $lead['score'] }}</span>
                                        </div>
                                    @endisset
                                    @if($lead['status'])
                                        <div class="px-2 py-1 rounded-lg text-[10px] font-bold {{ $lead['payment_status'] === 'paid' ? 'bg-emerald-50 text-emerald-700' : ($lead['payment_status'] === 'partial' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-700') }}">
                                            Học phí: {{ $lead['status'] }}
                                        </div>
                                    @endif
                                </div>

                                <div class="flex items-center justify-between gap-1 pt-2 border-t border-gray-100 text-[10px]">
                                    <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-600 font-medium truncate">{{ $lead['source'] }}</span>

                                    <div class="flex items-center gap-1 shrink-0">
                                        @if (in_array($stage['id'], \App\Models\CrmCustomer::CLOSABLE_STAGES, true) && $stagePermissions['canConvert'])
                                            <a href="{{ route('crm.closing-wizard', ['customer_id' => $lead['id']]) }}" @click.stop class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-orange-50 hover:bg-orange-600 text-orange-700 hover:text-white border border-orange-200 text-[11px] font-bold transition">
                                                <span>Chốt</span>
                                                <span class="material-symbols-outlined text-[14px]">payments</span>
                                            </a>
                                        @endif

                                        @if ($stage['id'] === 'waiting_class')
                                            @can('student.assign_class')
                                                <a href="{{ route('crm.customers.won') }}#waiting-class" @click.stop class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-yellow-50 hover:bg-yellow-600 text-yellow-800 hover:text-white border border-yellow-200 text-[11px] font-bold transition">
                                                    <span>Gán lớp</span>
                                                    <span class="material-symbols-outlined text-[14px]">assignment_turned_in</span>
                                                </a>
                                            @endcan
                                        @elseif ($stage['id'] === 'won')
                                            <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-bold flex items-center gap-0.5">
                                                <span class="material-symbols-outlined text-[12px]">check</span>
                                                <span>Đã chốt</span>
                                            </span>
                                        @elseif ($stagePermissions['canForward'] && $stage['next'] && ! in_array($stage['next'], $stagePermissions['closed'], true))
                                            <button
                                                type="button"
                                                @click.stop="moveToNextStage({{ (int) $lead['id'] }}, @js($lead['name']))"
                                                class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-600 text-emerald-700 hover:text-white border border-emerald-200 hover:border-emerald-600 text-[11px] font-bold transition shadow-xs cursor-pointer"
                                                title="Chuyển sang: {{ $stagePermissions['labels'][$stage['next']] }}"
                                            >
                                                <span>Tiếp theo</span>
                                                <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                            </button>
                                        @endif

                                        @if (($stagePermissions['canForward'] || $stagePermissions['canBackward']) && ! in_array($stage['id'], $stagePermissions['closed'], true))
                                            <button
                                                type="button"
                                                @click.stop="openStageEdit({{ (int) $lead['id'] }}, @js($lead['name']), @js($stage['id']))"
                                                class="inline-flex items-center px-1.5 py-1 rounded-lg bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 text-[11px] font-bold transition cursor-pointer"
                                                title="Sửa giai đoạn"
                                                aria-label="Sửa giai đoạn"
                                            >
                                                <span class="material-symbols-outlined text-[14px]">edit_note</span>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @can('lead.create')
                    @if ($stage['id'] === 'new')
                    <!-- Add button -->
                    <a href="{{ route('crm.customers.create') }}" class="w-full py-2.5 border-2 border-dashed border-gray-200 hover:border-primary-container/50 hover:bg-orange-50/40 rounded-xl text-xs font-bold text-gray-500 hover:text-primary-container transition flex items-center justify-center gap-1.5 bg-white/70">
                        <span class="material-symbols-outlined text-sm">add</span>
                        <span>Thêm deal mới</span>
                    </a>
                    @endif
                    @endcan
                </div>
            @endforeach
        </div>

        @if ($stagePermissions['canForward'] || $stagePermissions['canBackward'])
            <!-- Sửa giai đoạn (A6): CM tiến 1 bước; chỉ Admin lùi bước (bắt buộc lý do); Thất bại bắt buộc lý do -->
            <div x-show="stageEdit.open" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
                <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-3 text-xs" @click.outside="stageEdit.open = false">
                    <h3 class="font-bold text-sm">Sửa giai đoạn: <span x-text="stageEdit.name"></span></h3>
                    <p class="text-gray-500">Hiện tại: <strong x-text="permissions.labels[stageEdit.from]"></strong>. Học vụ / Quản lý cơ sở chỉ chuyển tiến 1 bước; chỉ Admin được lùi bước. Mọi thay đổi được lưu vào lịch sử khách.</p>
                    <select x-model="stageEdit.target" class="w-full rounded-xl border-gray-200 text-xs" aria-label="Giai đoạn mới">
                        <template x-for="option in stageEditOptions()" :key="option.value">
                            <option :value="option.value" x-text="option.label"></option>
                        </template>
                    </select>
                    <textarea x-show="stageEditNeedsReason()" x-model="stageEdit.reason" rows="3" :placeholder="stageEdit.target === 'lost' ? 'Lý do thất bại (bắt buộc)' : 'Lý do lùi giai đoạn (bắt buộc)'" class="w-full rounded-xl border-gray-200 text-xs"></textarea>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="stageEdit.open = false" class="px-3 py-2 border rounded-xl">Hủy</button>
                        <button type="button" @click="submitStageEdit()" :disabled="!stageEdit.target || (stageEditNeedsReason() && !stageEdit.reason.trim())" class="px-4 py-2 bg-primary-container disabled:opacity-50 text-white font-bold rounded-xl">Lưu giai đoạn</button>
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
