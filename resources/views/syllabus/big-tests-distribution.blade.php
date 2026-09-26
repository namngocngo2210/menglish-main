<x-app-layout>
    <x-ui.page-header title="Duyệt & Phân phối đề Big Test" description="Quản lý yêu cầu ra đề từ giáo viên và phân phối tài liệu kiểm tra.">
        <x-slot:actions>
            @can('syllabus.manage')
                <x-ui.button icon="add_circle" x-data @click="$dispatch('open-modal', 'new-big-test')">Tạo đợt Big Test mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @php($canReview = auth()->user()->can('big_test.approve'))

    @can('syllabus.manage')
        <x-ui.modal name="new-big-test" title="Tạo đợt thi Big Test (bản nháp)" max-width="md">
            <form id="new-big-test-form" action="{{ route('syllabus.big-tests.store') }}" method="POST" class="space-y-3 p-md">
                @csrf
                <x-ui.input name="title" label="Tên đợt thi" required placeholder="Final Big Test #09 (Cuối khóa)" />
                <x-ui.select name="class_id" label="Lớp thi" required :options="$classes->pluck('name', 'id')" />
                <x-ui.select name="test_type" label="Loại kỳ thi" :options="['midterm' => 'Giữa kỳ (Mid-term)', 'final' => 'Cuối khóa (Final)']" />
                <x-ui.input type="datetime-local" name="scheduled_at" label="Thời gian thi" required :value="now()->addDays(7)->format('Y-m-d\TH:i')" />
                <x-ui.input name="room" label="Phòng thi" required placeholder="VD: Phòng Lab 201" />
                <p class="font-caption text-caption text-on-surface-variant">Đợt thi tự gắn với <strong>chặng đang mở</strong> của lớp (Big Test cuối chặng). Khi kết quả được duyệt và gửi phụ huynh, chặng đóng và chặng kế tiếp tự mở.</p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'new-big-test')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="new-big-test-form">Lưu bản nháp</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan

    {{-- Mockup 01_Web_Admin/06: danh sách yêu cầu (40%) | chi tiết & phân phối (60%). --}}
    <div class="space-y-6" x-data="{ stageUrl: '', stageTest: '', stageOptions: {}, stageValue: '' }">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <aside class="lg:col-span-2 min-w-0 bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm overflow-hidden flex flex-col">
                <div class="p-md border-b border-outline-variant bg-surface-container-low space-y-sm">
                    <h3 class="font-h3 text-h3 text-on-surface flex items-center gap-xs">
                        <span class="material-symbols-outlined text-primary">pending_actions</span>
                        Yêu cầu chờ duyệt
                        <span class="rounded-full bg-primary-container px-sm py-0.5 font-label text-label text-white">{{ $pendingOrders }}</span>
                    </h3>
                    <form method="GET" class="flex items-center gap-2">
                        <x-ui.input name="order_search" icon="search" :value="$orderSearch" placeholder="Tìm tên lớp..." class="flex-1" />
                        <x-ui.select name="order_status" :value="$orderStatus" placeholder="Tất cả" :options="\App\Models\BigTestOrder::STATUS_LABELS" onchange="this.form.submit()" />
                    </form>
                </div>
                <div class="flex-1 space-y-sm overflow-y-auto p-md max-h-[640px]">
                    @forelse ($orders as $order)
                        @php($active = $selectedOrder?->id === $order->id)
                        <a href="{{ route('syllabus.big-tests.distribution', array_filter(['order' => $order->id, 'order_status' => $orderStatus, 'order_search' => $orderSearch, 'orders_page' => request('orders_page')])) }}"
                           class="block rounded-lg border p-md transition-colors {{ $active ? 'bg-primary-container/10 border-primary ring-1 ring-primary/20' : 'bg-surface-container-lowest border-outline-variant hover:bg-surface-container-low' }}">
                            <div class="flex items-start justify-between gap-sm">
                                <span class="font-body-medium text-body-medium font-semibold text-on-surface">{{ $order->classModel?->name }}{{ $order->classModel?->code ? ' - '.$order->classModel->code : '' }}</span>
                                @if ($order->isOverdue())
                                    <x-ui.badge color="error">Trễ hạn</x-ui.badge>
                                @elseif ($order->isSlaWarning())
                                    <span class="inline-flex items-center gap-0.5 rounded bg-error/10 px-xs py-0.5 font-label text-label text-error"><span class="material-symbols-outlined text-[14px]">warning</span>Cảnh báo SLA</span>
                                @else
                                    <x-ui.badge :color="$order->status_color">{{ $order->status_label }}</x-ui.badge>
                                @endif
                            </div>
                            <div class="mt-sm grid grid-cols-2 gap-xs font-caption text-caption text-on-surface-variant">
                                <span class="flex items-center gap-xs"><span class="material-symbols-outlined text-[14px]">ads_click</span>{{ $order->stage_label }} · {{ $order->type_label }}</span>
                                <span class="flex items-center gap-xs"><span class="material-symbols-outlined text-[14px]">person</span>GV. {{ $order->teacher?->name ?? '—' }}</span>
                                <span class="flex items-center gap-xs"><span class="material-symbols-outlined text-[14px]">calendar_today</span>Thi: {{ $order->exam_date?->format('d/m/Y') ?? 'chưa chốt' }}</span>
                                <span class="flex items-center gap-xs"><span class="material-symbols-outlined text-[14px]">schedule</span>Order: {{ $order->created_at->diffForHumans() }}</span>
                            </div>
                            <p class="mt-xs font-caption text-caption {{ $order->isOverdue() ? 'text-error font-semibold' : 'text-on-surface-variant' }}">Hạn xử lý: {{ $order->due_date?->format('d/m/Y') ?? '—' }}</p>
                        </a>
                    @empty
                        <x-ui.empty-state icon="inbox" title="Không có order đề nào" />
                    @endforelse
                </div>
                <div class="border-t border-outline-variant"><x-ui.pagination :paginator="$orders" :options="[]" unit="order" /></div>
            </aside>

            <section class="lg:col-span-3 min-w-0 bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm overflow-hidden flex flex-col">
                @if (! $selectedOrder)
                    <x-ui.empty-state icon="assignment" title="Chọn một order để xem chi tiết" />
                @else
                    @php($oc = $selectedOrder->classModel)
                    <div class="p-lg border-b border-outline-variant space-y-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="rounded bg-surface-container-high px-sm py-0.5 font-label text-label text-on-surface-variant">CLASS ID: {{ $oc?->code ?? '—' }}</span>
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-caption text-caption text-on-surface-variant">{{ $selectedOrder->code }}</span>
                                <x-ui.badge :color="$selectedOrder->status_color">{{ $selectedOrder->status_label }}</x-ui.badge>
                            </div>
                        </div>
                        <h2 class="font-h2 text-h2 text-on-surface">{{ $oc?->name }}{{ $oc?->code ? ' - '.$oc->code : '' }}</h2>
                        <p class="font-body-small text-body-small text-on-surface-variant">{{ collect([$oc?->course?->name, $oc?->branch?->name])->filter()->implode(' · ') ?: '—' }}. Đề {{ $selectedOrder->type_label }} cho {{ $selectedOrder->stage_label }}.</p>
                        <div class="flex flex-wrap gap-md font-body-small text-body-small">
                            <span class="flex items-center gap-xs rounded-lg bg-surface-container-low px-sm py-xs"><span class="text-on-surface-variant">Ngày thi dự kiến</span><strong class="font-mono">{{ $selectedOrder->exam_date?->format('d/m/Y') ?? '—' }}</strong></span>
                            <span class="flex items-center gap-xs"><span class="material-symbols-outlined text-[18px] text-primary">person_pin</span>Giáo viên: {{ $selectedOrder->teacher?->name ?? '—' }}</span>
                            <span class="flex items-center gap-xs"><span class="material-symbols-outlined text-[18px] text-primary">verified_user</span>Chặng: {{ $selectedOrder->stage_label }}</span>
                        </div>
                    </div>

                    <div class="p-lg space-y-lg flex-1">
                        <div>
                            <h4 class="mb-sm flex items-center gap-xs font-body-medium text-body-medium font-semibold text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary">chat_bubble</span>Yêu cầu từ Giáo viên</h4>
                            <p class="whitespace-pre-line rounded-lg border-l-4 border-primary-container bg-surface-container-low p-md font-body-small text-body-small italic text-on-surface">{{ $selectedOrder->note ? '"'.$selectedOrder->note.'"' : 'Không có ghi chú.' }}</p>
                        </div>

                        @if ($selectedOrder->status === 'approved')
                            <div class="rounded-lg border border-tertiary/20 bg-tertiary/5 p-md font-body-small text-body-small text-on-surface space-y-1">
                                <p class="font-semibold">Đã duyệt bởi {{ $selectedOrder->reviewer?->name }} lúc {{ $selectedOrder->reviewed_at?->format('H:i d/m/Y') }}</p>
                                @if ($canReview)
                                    <a href="{{ $selectedOrder->test_link }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary underline"><span class="material-symbols-outlined text-[16px]">link</span>Link đề</a>
                                @endif
                                @if ($selectedOrder->speaking_link)
                                    <a href="{{ $selectedOrder->speaking_link }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary underline"><span class="material-symbols-outlined text-[16px]">record_voice_over</span>Phần Speaking</a>
                                @elseif (! $canReview)
                                    <p class="text-on-surface-variant">Học thuật chưa gửi link phần Speaking.</p>
                                @endif
                                @if ($selectedOrder->bigTest)
                                    <p>Đợt thi: <strong>{{ $selectedOrder->bigTest->code }}</strong> · {{ $selectedOrder->bigTest->scheduled_at?->format('H:i d/m/Y') }} · {{ $selectedOrder->bigTest->room }}</p>
                                @endif
                            </div>
                        @elseif ($selectedOrder->status === 'rejected')
                            <div class="rounded-lg border border-error/20 bg-error/5 p-md font-body-small text-body-small text-on-surface">
                                <p class="font-semibold">Đã từ chối bởi {{ $selectedOrder->reviewer?->name }} lúc {{ $selectedOrder->reviewed_at?->format('H:i d/m/Y') }}</p>
                                <p class="mt-1">Lý do: {{ $selectedOrder->rejection_reason }}</p>
                            </div>
                        @elseif ($canReview)
                            <div x-data="{ link: @js((string) old('test_link', '')), rejecting: @js($errors->has('rejection_reason')) }" class="space-y-md">
                                <h4 class="flex items-center gap-xs font-body-medium text-body-medium font-semibold text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary">folder_shared</span>Phân phối đề</h4>
                                <form id="approve-order-form" method="POST" action="{{ route('syllabus.big-tests.orders.approve', $selectedOrder->id) }}" class="space-y-md">
                                    @csrf
                                    <x-ui.field label="Link đề Big Test (Folder lớp)" name="test_link" required>
                                        <div class="relative">
                                            <span class="material-symbols-outlined pointer-events-none absolute left-sm top-1/2 -translate-y-1/2 text-[18px] text-on-surface-variant">link</span>
                                            <input type="url" name="test_link" x-model="link" required placeholder="Dán link Google Drive hoặc OneDrive tại đây..."
                                                   class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-xl pr-md font-body-base text-body-base focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                                        </div>
                                    </x-ui.field>
                                    <x-ui.field label="Link phần Speaking (GV xem sau khi phân phối)" name="speaking_link">
                                        <div class="relative">
                                            <span class="material-symbols-outlined pointer-events-none absolute left-sm top-1/2 -translate-y-1/2 text-[18px] text-on-surface-variant">record_voice_over</span>
                                            <input type="url" name="speaking_link" value="{{ old('speaking_link') }}" placeholder="Link riêng phần Speaking (tùy chọn)..."
                                                   class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-xl pr-md font-body-base text-body-base focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                                        </div>
                                    </x-ui.field>
                                    @if ($selectedOrder->test_type === 'big')
                                        @php($classTests = \App\Models\BigTest::where('class_id', $selectedOrder->class_id)->whereNull('results_completed_at')->latest('scheduled_at')->get())
                                        <div x-data="{ mode: @js(old('big_test_id') ? 'link' : 'create') }" class="space-y-md rounded-lg border border-outline-variant p-md" data-testid="order-big-test-mode">
                                            <p class="font-body-medium text-body-medium font-semibold text-on-surface">Đợt Big Test</p>
                                            <div class="flex flex-wrap gap-md font-body-small text-body-small">
                                                <label class="flex items-center gap-xs"><input type="radio" value="create" x-model="mode"> Tạo đợt thi mới</label>
                                                @if ($classTests->isNotEmpty())
                                                    <label class="flex items-center gap-xs"><input type="radio" value="link" x-model="mode"> Gắn đợt thi có sẵn</label>
                                                @endif
                                            </div>
                                            <div x-show="mode === 'create'" class="grid grid-cols-1 gap-md sm:grid-cols-2">
                                                <x-ui.input type="datetime-local" name="scheduled_at" label="Ngày giờ thi" x-bind:disabled="mode !== 'create'"
                                                            :value="old('scheduled_at', $selectedOrder->exam_date?->copy()->setTime(8, 0)->format('Y-m-d\TH:i'))" />
                                                <x-ui.input name="room" label="Phòng thi" :value="old('room', $oc?->room)" placeholder="VD: Phòng Lab 201" x-bind:disabled="mode !== 'create'" />
                                                <p class="sm:col-span-2 font-caption text-caption text-on-surface-variant">Hệ thống tạo đợt Big Test gắn <strong>chặng đang mở</strong> của lớp và phân phối luôn để giáo viên nhập kết quả.</p>
                                            </div>
                                            @if ($classTests->isNotEmpty())
                                                <div x-show="mode === 'link'" x-cloak>
                                                    <x-ui.select name="big_test_id" label="Gắn vào đợt thi" placeholder="-- Chọn đợt thi --" x-bind:disabled="mode !== 'link'" :value="old('big_test_id')"
                                                                 :options="$classTests->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->title.($t->scheduled_at ? ' · '.$t->scheduled_at->format('d/m/Y H:i') : '')])" />
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </form>

                                @if ($selectedOrder->isOverdue() || $selectedOrder->isSlaWarning())
                                    <div class="flex items-start gap-sm rounded-lg border border-error/30 bg-error/5 p-md">
                                        <span class="material-symbols-outlined text-[20px] text-error">warning</span>
                                        <div class="font-body-small text-body-small text-on-surface">
                                            <p class="font-semibold text-error">{{ $selectedOrder->isOverdue() ? 'Cảnh báo: Phân phối trễ hạn SLA' : 'Cảnh báo: Sắp hết hạn SLA' }}</p>
                                            <p>Hệ thống ghi nhận cần hoàn thành phân phối trước {{ \App\Models\BigTestOrder::LEAD_DAYS }} ngày so với lịch thi (Hạn chót: {{ $selectedOrder->due_date->format('d/m/Y') }}). Vui lòng ưu tiên xử lý ngay.</p>
                                        </div>
                                    </div>
                                @endif

                                <form id="reject-order-form" method="POST" action="{{ route('syllabus.big-tests.orders.reject', $selectedOrder->id) }}" x-show="rejecting" x-cloak>
                                    @csrf
                                    <x-ui.textarea name="rejection_reason" label="Lý do từ chối" required rows="3" />
                                </form>

                                <div class="flex flex-wrap items-center justify-between gap-sm border-t border-outline-variant pt-md">
                                    <div>
                                        <x-ui.button variant="danger-text" icon="close" x-show="! rejecting" @click="rejecting = true">Từ chối yêu cầu</x-ui.button>
                                        <div x-show="rejecting" x-cloak class="flex items-center gap-sm">
                                            <x-ui.button variant="secondary" @click="rejecting = false">Hủy</x-ui.button>
                                            <x-ui.button type="submit" form="reject-order-form" variant="danger" icon="close">Xác nhận từ chối</x-ui.button>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-sm" x-show="! rejecting">
                                        <x-ui.button variant="secondary" icon="visibility" x-bind:disabled="! link" @click="link && window.open(link, '_blank', 'noopener')">Xem trước tệp</x-ui.button>
                                        <x-ui.button type="submit" form="approve-order-form" icon="send">Phê duyệt &amp; Phân phối</x-ui.button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </section>
        </div>

        {{-- Đợt thi Big Test (gắn chặng) --}}
        <x-ui.data-table min-width="980px">
            <x-slot:header>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">event_note</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Đợt thi Big Test</h2>
                </div>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Mã kỳ thi</th>
                        <th>Tên kỳ thi</th>
                        <th>Lớp thi / Chặng</th>
                        <th>Thời gian &amp; Địa điểm</th>
                        <th>Giám thị</th>
                        <th>Mật mã thi</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bigTests as $bt)
                        <tr>
                            <td class="font-mono font-bold text-on-surface">{{ $bt->code }}</td>
                            <td class="font-semibold text-on-surface">
                                {{ $bt->title }}
                                <div class="flex flex-wrap gap-sm font-caption text-caption">
                                    @if ($bt->content_url && $bt->contentLinkVisibleTo(auth()->user()))
                                        <a href="{{ $bt->content_url }}" target="_blank" rel="noopener" class="text-primary hover:underline">Link đề</a>
                                    @endif
                                    @if ($bt->speakingLinkVisibleTo(auth()->user()))
                                        <a href="{{ $bt->speaking_url }}" target="_blank" rel="noopener" class="text-primary hover:underline">Phần Speaking</a>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="font-semibold text-primary">{{ $bt->classModel?->name }}</span>
                                <span class="block font-caption text-caption {{ $bt->stage ? 'text-on-surface-variant' : 'text-amber-700' }}">{{ $bt->stage?->label ?? 'Chưa gắn chặng' }}</span>
                            </td>
                            <td>
                                <div>{{ $bt->scheduled_at ? $bt->scheduled_at->format('d/m/Y H:i') : '—' }}</div>
                                <div class="font-caption text-caption text-on-surface-variant">{{ $bt->room }}</div>
                            </td>
                            <td>{{ $bt->proctor?->name ?? '—' }}</td>
                            <td class="font-mono font-bold text-tertiary">{{ $bt->passcodeVisibleTo(auth()->user()) ? $bt->passcode : '••••••' }}</td>
                            <td class="text-right">
                                <div class="flex justify-end items-center gap-2">
                                    @if ($canReview && $bt->class_id)
                                        <x-ui.button variant="ghost" size="sm" icon="flag" title="Gắn chặng cho đợt thi"
                                                     @click="stageUrl = @js(route('syllabus.big-tests.stage', $bt->id)); stageTest = @js($bt->code.' · '.$bt->classModel?->name); stageOptions = @js($stageOptions[$bt->class_id] ?? []); stageValue = @js((string) ($bt->syllabus_stage_id ?? '')); $dispatch('open-modal', 'big-test-stage')">Gắn chặng</x-ui.button>
                                    @endif
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

        @if ($canReview)
            <x-ui.modal name="big-test-stage" title="Gắn chặng cho đợt Big Test" max-width="md">
                <form id="big-test-stage-form" method="POST" :action="stageUrl" class="space-y-3 p-md">
                    @csrf
                    <p class="font-body-small text-body-small text-on-surface-variant">Đợt thi: <strong x-text="stageTest"></strong>. Big Test cuối chặng: khi kết quả được duyệt và gửi đủ phụ huynh, chặng đang mở tương ứng của lớp sẽ đóng và chặng kế tiếp tự mở.</p>
                    <x-ui.field label="Chặng" name="syllabus_stage_id">
                        <select name="syllabus_stage_id" x-model="stageValue" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                            <option value="">— Không gắn chặng —</option>
                            <template x-for="(label, id) in stageOptions" :key="id">
                                <option :value="String(id)" x-text="label" :selected="String(id) === stageValue"></option>
                            </template>
                        </select>
                    </x-ui.field>
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'big-test-stage')">Hủy</x-ui.button>
                    <x-ui.button type="submit" form="big-test-stage-form" icon="save">Lưu</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>
</x-app-layout>
