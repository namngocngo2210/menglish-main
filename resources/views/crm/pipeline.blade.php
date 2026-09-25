<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-4" x-data="crmKanban()">
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

        <!-- Pipeline summary cards -->
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
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
                    @dragover.prevent="onDragOver($event, {{ $index }})"
                    @dragleave="onDragLeave($event)"
                    @drop="onDrop($event, '{{ $stage['id'] }}', {{ $index }})"
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
                            <div 
                                class="kanban-card bg-white rounded-xl p-3.5 border border-gray-200 shadow-xs hover:shadow-md hover:border-primary-container/50 transition duration-150 cursor-grab active:cursor-grabbing group relative"
                                draggable="true"
                                data-customer-id="{{ $lead['id'] }}"
                                data-stage-id="{{ $stage['id'] }}"
                                data-stage-index="{{ $index }}"
                                @dragstart="onDragStart($event, {{ $lead['id'] }}, '{{ $stage['id'] }}', {{ $index }})"
                                @dragend="onDragEnd($event)"
                                @click="openLeadDetails('{{ route('crm.customers.show', $lead['id']) }}')"
                            >
                                <div class="flex items-start justify-between gap-2 mb-1.5">
                                    <h4 class="font-bold text-xs text-gray-900 group-hover:text-primary-container transition line-clamp-1">
                                        {{ $lead['name'] }}
                                    </h4>
                                    <span class="text-[10px] text-gray-400 whitespace-nowrap shrink-0">{{ $lead['days'] }}</span>
                                </div>

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

                                <div class="flex items-center justify-between pt-2 border-t border-gray-100 text-[10px]">
                                    <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-600 font-medium">{{ $lead['source'] }}</span>
                                    
                                    <!-- Action: Nút Tiếp theo (nếu chưa ở giai đoạn chốt cuối) -->
                                    @if ($stage['id'] === 'closing')
                                        @can('lead.convert')
                                        <a href="{{ route('crm.closing-wizard', ['customer_id' => $lead['id']]) }}" @click.stop class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-orange-50 hover:bg-orange-600 text-orange-700 hover:text-white border border-orange-200 text-[11px] font-bold transition">
                                            <span>Chốt Lead</span>
                                            <span class="material-symbols-outlined text-[14px]">payments</span>
                                        </a>
                                        @endcan
                                    @elseif (in_array($stage['id'], ['new', 'trial_completed', 'waiting_class'], true))
                                        <button 
                                            type="button" 
                                            @click.stop="moveToNextStage({{ $lead['id'] }}, '{{ $lead['name'] }}')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-600 text-emerald-700 hover:text-white border border-emerald-200 hover:border-emerald-600 text-[11px] font-bold transition shadow-xs cursor-pointer"
                                            title="Chuyển ngay sang giai đoạn tiếp theo"
                                        >
                                            <span>Tiếp theo</span>
                                            <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                        </button>
                                    @elseif ($stage['id'] === 'won')
                                        <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-bold flex items-center gap-0.5">
                                            <span class="material-symbols-outlined text-[12px]">check</span>
                                            <span>Đã chốt</span>
                                        </span>
                                    @else
                                        <a href="{{ route('crm.customers.show', $lead['id']) }}" @click.stop class="font-bold text-indigo-600 hover:underline">Mở hồ sơ để xử lý</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Add button -->
                    <a href="{{ route('crm.customers.create') }}" class="w-full py-2.5 border-2 border-dashed border-gray-200 hover:border-primary-container/50 hover:bg-orange-50/40 rounded-xl text-xs font-bold text-gray-500 hover:text-primary-container transition flex items-center justify-center gap-1.5 bg-white/70">
                        <span class="material-symbols-outlined text-sm">add</span>
                        <span>Thêm deal mới</span>
                    </a>
                </div>
            @endforeach
        </div>
    </div>

    <script>
        function crmKanban() {
            return {
                draggedCard: null,
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

                canTransition(sourceStage, targetStage) {
                    const allowed = {
                        new: ['consulting'],
                        trial_completed: ['closing'],
                        waiting_class: ['closing']
                    };
                    return (allowed[sourceStage] || []).includes(targetStage);
                },

                onDragStart(event, customerId, stageId, stageIndex) {
                    this.draggedCard = {
                        customerId: customerId,
                        stageId: stageId,
                        stageIndex: stageIndex,
                        element: event.target
                    };
                    event.target.classList.add('opacity-40', 'scale-95');
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', JSON.stringify({
                        customerId: customerId,
                        stageId: stageId,
                        stageIndex: stageIndex
                    }));
                },

                onDragEnd(event) {
                    if (this.draggedCard && this.draggedCard.element) {
                        this.draggedCard.element.classList.remove('opacity-40', 'scale-95');
                    }
                    document.querySelectorAll('.kanban-column').forEach(col => {
                        col.classList.remove('ring-2', 'ring-primary-container', 'bg-orange-50/40');
                    });
                },

                onDragOver(event, targetStageIndex) {
                    if (!this.draggedCard) return;

                    const targetStageId = event.currentTarget.dataset.stageId;

                    if (!this.canTransition(this.draggedCard.stageId, targetStageId)) {
                        event.dataTransfer.dropEffect = 'none';
                        return;
                    }

                    event.dataTransfer.dropEffect = 'move';
                    const col = event.currentTarget;
                    col.classList.add('ring-2', 'ring-primary-container', 'bg-orange-50/40');
                },

                onDragLeave(event) {
                    const col = event.currentTarget;
                    col.classList.remove('ring-2', 'ring-primary-container', 'bg-orange-50/40');
                },

                async onDrop(event, targetStageId, targetStageIndex) {
                    event.preventDefault();
                    const col = event.currentTarget;
                    col.classList.remove('ring-2', 'ring-primary-container', 'bg-orange-50/40');

                    if (!this.draggedCard) return;

                    const { customerId, stageId: sourceStageId, stageIndex: sourceStageIndex } = this.draggedCard;

                    // Không làm gì nếu thả cùng cột
                    if (sourceStageId === targetStageId) {
                        return;
                    }

                    if (targetStageId === 'won') {
                        this.showToast('Hãy dùng Closing Wizard để chốt Lead và tạo hồ sơ học viên.', 'error');
                        return;
                    }

                    if (!this.canTransition(sourceStageId, targetStageId)) {
                        this.showToast('Giai đoạn này cần thao tác nghiệp vụ cụ thể trong hồ sơ Lead.', 'error');
                        return;
                    }

                    // Gửi request cập nhật giai đoạn lên server
                    try {
                        const response = await fetch(`/crm/customers/${customerId}/stage`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                stage: targetStageId
                            })
                        });

                        const data = await response.json();

                        if (response.ok && data.success) {
                            this.showToast(data.message || 'Đã chuyển giai đoạn thành công!', 'success');
                            // Tải lại trang sau 300ms để đồng bộ toàn bộ tổng số tiền và số lượng thẻ
                            setTimeout(() => {
                                window.location.reload();
                            }, 400);
                        } else {
                            this.showToast(data.message || 'Không thể chuyển giai đoạn!', 'error');
                        }
                    } catch (err) {
                        this.showToast('Đã xảy ra lỗi kết nối khi chuyển giai đoạn!', 'error');
                    }
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
                            setTimeout(() => {
                                window.location.reload();
                            }, 400);
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
