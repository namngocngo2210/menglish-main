<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">dynamic_form</span>
                    Duyệt &amp; phân phối đề Big Test
                </h1>
                <p class="text-xs text-gray-500">Xử lý order đề của giáo viên (link đề, hạn xử lý) và phân phối đợt thi Big Test cho lớp.</p>
            </div>
            @can('syllabus.manage')
                <x-ui.button icon="add_circle" x-data @click="$dispatch('open-modal', 'new-big-test')">Tạo đợt Big Test mới</x-ui.button>
            @endcan
        </div>
    </x-slot>

    @php($canReview = auth()->user()->can('syllabus.approve_adjustment'))

    @can('syllabus.manage')
        <x-ui.modal name="new-big-test" title="Tạo đợt thi Big Test (bản nháp)" max-width="md">
            <form id="new-big-test-form" action="{{ route('syllabus.big-tests.store') }}" method="POST" class="space-y-3 p-md">
                @csrf
                <x-ui.input name="title" label="Tên đợt thi" required placeholder="Final Big Test #09 (Cuối khóa)" />
                <x-ui.select name="class_id" label="Lớp thi" required :options="$classes->pluck('name', 'id')" />
                <x-ui.select name="test_type" label="Loại kỳ thi" :options="['midterm' => 'Giữa kỳ (Mid-term)', 'final' => 'Cuối khóa (Final)']" />
                <x-ui.input type="datetime-local" name="scheduled_at" label="Thời gian thi" required :value="now()->addDays(7)->format('Y-m-d\TH:i')" />
                <x-ui.input name="room" label="Phòng thi" required placeholder="VD: Phòng Lab 201" />
                <p class="text-[11px] text-gray-500">Đợt thi tự gắn với <strong>chặng đang mở</strong> của lớp (Big Test cuối chặng). Khi kết quả được duyệt và gửi phụ huynh, chặng đóng và chặng kế tiếp tự mở.</p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'new-big-test')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="new-big-test-form">Lưu bản nháp</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan

    <div class="space-y-6">
        {{-- Order đề của giáo viên --}}
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div class="lg:col-span-5 min-w-0">
                <x-ui.data-table>
                    <x-slot:header>
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-[20px]">pending_actions</span>
                            <h2 class="text-sm font-bold text-gray-900">Order đề của giáo viên</h2>
                            <x-ui.badge color="warning">{{ $pendingOrders }} chờ duyệt</x-ui.badge>
                        </div>
                        <form method="GET">
                            <x-ui.select name="order_status" :value="$orderStatus" placeholder="Tất cả" :options="\App\Models\BigTestOrder::STATUS_LABELS" onchange="this.form.submit()" />
                        </form>
                    </x-slot:header>
                    <table>
                        <thead><tr><th>Lớp / Chặng</th><th>Hạn xử lý</th></tr></thead>
                        <tbody>
                            @forelse ($orders as $order)
                                <tr class="{{ $selectedOrder?->id === $order->id ? 'bg-orange-50/60' : '' }}">
                                    <td>
                                        <a href="{{ route('syllabus.big-tests.distribution', array_filter(['order' => $order->id, 'order_status' => $orderStatus, 'orders_page' => request('orders_page')])) }}" class="block">
                                            <p class="font-bold text-gray-900 text-xs">{{ $order->classModel?->name }} · {{ $order->type_label }}</p>
                                            <p class="text-[11px] text-gray-500">{{ $order->stage_name }} — GV. {{ $order->teacher?->name ?? '—' }}</p>
                                            <p class="text-[11px] text-gray-400">Thi: {{ $order->exam_date?->format('d/m/Y') ?? 'chưa chốt' }} · Order: {{ $order->created_at->diffForHumans() }}</p>
                                        </a>
                                    </td>
                                    <td class="whitespace-nowrap text-xs">
                                        <span class="font-mono {{ $order->isOverdue() ? 'text-rose-600 font-bold' : 'text-gray-700' }}">{{ $order->due_date?->format('d/m/Y') ?? '—' }}</span>
                                        <div><x-ui.badge :color="$order->isOverdue() ? 'error' : $order->status_color">{{ $order->isOverdue() ? 'Trễ hạn' : $order->status_label }}</x-ui.badge></div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2"><x-ui.empty-state icon="inbox" title="Không có order đề nào" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <x-slot:footer><x-ui.pagination :paginator="$orders" :options="[]" unit="order" /></x-slot:footer>
                </x-ui.data-table>
            </div>

            <div class="lg:col-span-7 min-w-0">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    @if (! $selectedOrder)
                        <x-ui.empty-state icon="assignment" title="Chọn một order để xem chi tiết" />
                    @else
                        <div class="p-5 border-b border-gray-100 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-mono text-gray-400">{{ $selectedOrder->code }}</p>
                                <h2 class="text-sm font-bold text-gray-900">{{ $selectedOrder->classModel?->name }} — {{ $selectedOrder->type_label }}</h2>
                                <p class="text-xs text-gray-500">{{ $selectedOrder->classModel?->course?->name }}</p>
                            </div>
                            <x-ui.badge :color="$selectedOrder->status_color">{{ $selectedOrder->status_label }}</x-ui.badge>
                        </div>
                        <div class="p-5 space-y-4 text-xs">
                            <dl class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <div><dt class="text-[10px] font-bold text-gray-400 uppercase">Giáo viên</dt><dd class="font-semibold text-gray-800">{{ $selectedOrder->teacher?->name ?? '—' }}</dd></div>
                                <div><dt class="text-[10px] font-bold text-gray-400 uppercase">Chặng</dt><dd class="font-semibold text-gray-800">{{ $selectedOrder->stage_name }}</dd></div>
                                <div><dt class="text-[10px] font-bold text-gray-400 uppercase">Ngày thi dự kiến</dt><dd class="font-mono text-gray-800">{{ $selectedOrder->exam_date?->format('d/m/Y') ?? '—' }}</dd></div>
                                <div><dt class="text-[10px] font-bold text-gray-400 uppercase">Hạn xử lý</dt><dd class="font-mono {{ $selectedOrder->isOverdue() ? 'text-rose-600 font-bold' : 'text-gray-800' }}">{{ $selectedOrder->due_date?->format('d/m/Y') ?? '—' }}</dd></div>
                            </dl>

                            <div>
                                <p class="font-bold text-gray-800 mb-1">Yêu cầu từ giáo viên</p>
                                <div class="bg-orange-50/30 border border-orange-200/60 rounded-xl p-3 text-gray-700 whitespace-pre-line">{{ $selectedOrder->note ?: 'Không có ghi chú.' }}</div>
                            </div>

                            @if ($selectedOrder->isOverdue())
                                <x-ui.alert type="warning">Order đã quá hạn xử lý ({{ $selectedOrder->due_date->format('d/m/Y') }}) — đề cần phân phối trước ngày thi {{ \App\Models\BigTestOrder::LEAD_DAYS }} ngày.</x-ui.alert>
                            @endif

                            @if ($selectedOrder->status === 'approved')
                                <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-emerald-800 space-y-1">
                                    <p class="font-bold">Đã duyệt bởi {{ $selectedOrder->reviewer?->name }} lúc {{ $selectedOrder->reviewed_at?->format('H:i d/m/Y') }}</p>
                                    <a href="{{ $selectedOrder->test_link }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold underline">
                                        <span class="material-symbols-outlined text-[16px]">link</span>Link đề
                                    </a>
                                </div>
                            @elseif ($selectedOrder->status === 'rejected')
                                <div class="bg-rose-50 border border-rose-200 rounded-xl p-3 text-rose-800">
                                    <p class="font-bold">Đã từ chối bởi {{ $selectedOrder->reviewer?->name }} lúc {{ $selectedOrder->reviewed_at?->format('H:i d/m/Y') }}</p>
                                    <p class="mt-1">Lý do: {{ $selectedOrder->rejection_reason }}</p>
                                </div>
                            @elseif ($canReview)
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 border-t border-gray-100 pt-4">
                                    <form method="POST" action="{{ route('syllabus.big-tests.orders.approve', $selectedOrder->id) }}" class="space-y-2">
                                        @csrf
                                        <x-ui.input type="url" name="test_link" label="Link đề Big Test (folder lớp)" required placeholder="https://drive.google.com/..." />
                                        @php($classTests = $bigTests->getCollection()->where('class_id', $selectedOrder->class_id))
                                        @if ($classTests->isNotEmpty())
                                            <x-ui.select name="big_test_id" label="Gắn vào đợt thi (tùy chọn)" placeholder="-- Không gắn --" :options="$classTests->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->title])" />
                                        @endif
                                        <x-ui.button type="submit" icon="send" class="w-full">Phê duyệt &amp; phân phối</x-ui.button>
                                    </form>
                                    <form method="POST" action="{{ route('syllabus.big-tests.orders.reject', $selectedOrder->id) }}" class="space-y-2">
                                        @csrf
                                        <x-ui.textarea name="rejection_reason" label="Lý do từ chối" required rows="3" />
                                        <x-ui.button type="submit" variant="danger" icon="close" class="w-full">Từ chối yêu cầu</x-ui.button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Đợt thi Big Test --}}
        <x-ui.data-table min-width="900px">
            <x-slot:header>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">event_note</span>
                    <h2 class="text-sm font-bold text-gray-900">Đợt thi Big Test</h2>
                </div>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Mã kỳ thi</th>
                        <th>Tên kỳ thi</th>
                        <th>Lớp thi</th>
                        <th>Thời gian &amp; Địa điểm</th>
                        <th>Giám thị</th>
                        <th>Mật mã thi</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bigTests as $bt)
                        <tr>
                            <td class="font-mono font-bold text-gray-900">{{ $bt->code }}</td>
                            <td class="font-bold text-gray-900">{{ $bt->title }}</td>
                            <td class="font-semibold text-primary">{{ $bt->classModel?->name }}@if ($bt->stage)<span class="block text-[10px] font-normal text-gray-500">{{ $bt->stage->label }}</span>@endif</td>
                            <td>
                                <div>{{ $bt->scheduled_at ? $bt->scheduled_at->format('d/m/Y H:i') : '—' }}</div>
                                <div class="text-[10px] text-gray-400">{{ $bt->room }}</div>
                            </td>
                            <td>{{ $bt->proctor?->name ?? '—' }}</td>
                            <td class="font-mono font-bold text-emerald-600">{{ $bt->passcodeVisibleTo(auth()->user()) ? $bt->passcode : '••••••' }}</td>
                            <td class="text-right">
                                <div class="flex justify-end items-center gap-2">
                                    @if (! $bt->is_distributed)
                                        @if ($canReview)
                                            <form method="POST" action="{{ route('syllabus.big-tests.approve', $bt->id) }}">@csrf
                                                <x-ui.button type="submit" variant="secondary" size="sm" icon="task_alt">Duyệt &amp; phân phối</x-ui.button>
                                            </form>
                                        @else
                                            <x-ui.badge color="warning">Chờ duyệt đề</x-ui.badge>
                                        @endif
                                    @else
                                        <x-ui.badge color="success">Đã phân phối</x-ui.badge>
                                    @endif
                                    <x-ui.button variant="ghost" size="sm" :href="route('syllabus.big-tests.results', $bt->id)">Bảng điểm</x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-ui.empty-state icon="event_busy" title="Chưa có kỳ thi Big Test nào" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$bigTests" unit="đợt thi" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
