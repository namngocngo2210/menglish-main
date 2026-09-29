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
        <x-ui.modal name="new-big-test" title="Tạo đợt thi Big Test (bản nháp)" max-width="md" :show="$errors->hasAny(['title', 'class_id', 'test_type', 'scheduled_at', 'room'])">
            <form id="new-big-test-form" action="{{ route('syllabus.big-tests.store') }}" method="POST" class="space-y-3 p-md">
                @csrf
                <x-ui.input name="title" label="Tên đợt thi" required placeholder="Final Big Test #09 (Cuối khóa)" />
                <x-ui.select name="class_id" label="Lớp thi" required :options="$classes->pluck('name', 'id')" />
                <x-ui.select name="test_type" label="Loại kỳ thi" :options="['midterm' => 'Giữa kỳ (Mid-term)', 'final' => 'Cuối khóa (Final)']" />
                <x-ui.input type="datetime-local" name="scheduled_at" label="Thời gian thi" required :value="now()->addDays(7)->format('Y-m-d\TH:i')" />
                <x-ui.input name="room" label="Phòng thi" required placeholder="VD: Phòng Lab 201" />
                <p class="font-caption text-caption text-on-surface-variant">Đợt thi tự gắn với <strong>chặng đang mở</strong> của lớp (Big Test cuối chặng). Khi kết quả của cả lớp được duyệt và gửi phụ huynh, chặng đóng và chặng kế tiếp tự mở.</p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'new-big-test')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="new-big-test-form">Lưu bản nháp</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan

    {{-- Mockup 01_Web_Admin/06: danh sách order đề — bấm dòng → chi tiết & phân phối trong modal (?order=; đóng modal thì bỏ query). --}}
    @php($listQuery = array_filter(['order_search' => $orderSearch, 'orders_page' => request('orders_page')]) + ['order_status' => (string) $orderStatus])
    <div class="space-y-6" x-data="{ stageUrl: '', stageTest: '', stageOptions: {}, stageValue: '' }">
        <x-ui.data-table min-width="880px">
            <x-slot:header>
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary">pending_actions</span>Yêu cầu chờ duyệt
                    <x-ui.badge color="primary" :dot="false" pill>{{ $pendingOrders }}</x-ui.badge>
                </h2>
                <form method="GET" class="flex items-center gap-2">
                    <x-ui.input name="order_search" icon="search" :value="$orderSearch" placeholder="Tìm tên lớp..." />
                    <x-ui.select name="order_status" :value="$orderStatus" placeholder="Tất cả" :options="\App\Models\BigTestOrder::STATUS_LABELS" onchange="this.form.submit()" aria-label="Trạng thái order" />
                </form>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Lớp</th>
                        <th>Chặng · Loại đề</th>
                        <th>Giáo viên</th>
                        <th>Ngày thi</th>
                        <th>Hạn xử lý</th>
                        <th>Trạng thái</th>
                        <th class="text-right"><span class="sr-only">Thao tác</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        @php($detailUrl = route('syllabus.big-tests.distribution', ['order' => $order->id] + $listQuery))
                        <tr data-href="{{ $detailUrl }}" @class(['cursor-pointer', 'bg-primary-fixed/30' => $selectedOrder?->id === $order->id])>
                            <td>
                                <a href="{{ $detailUrl }}" class="font-semibold text-on-surface hover:text-primary">{{ $order->classModel?->name }}{{ $order->classModel?->code ? ' - '.$order->classModel->code : '' }}</a>
                                <span class="block font-caption text-caption text-on-surface-variant">Order {{ $order->created_at->diffForHumans() }}</span>
                            </td>
                            <td>{{ $order->stage_label }} · {{ $order->type_label }}</td>
                            <td>{{ $order->teacher?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap font-code text-body-small">{{ $order->exam_date?->format('d/m/Y') ?? 'chưa chốt' }}</td>
                            <td @class(['whitespace-nowrap font-code text-body-small', 'font-semibold text-error' => $order->isOverdue()])>{{ $order->due_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="whitespace-nowrap">
                                @if ($order->isOverdue())
                                    <x-ui.badge color="error">Trễ hạn</x-ui.badge>
                                @elseif ($order->isSlaWarning())
                                    <x-ui.badge color="warning">Cảnh báo SLA</x-ui.badge>
                                @else
                                    <x-ui.badge :color="$order->status_color">{{ $order->status_label }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="text-right"><x-ui.button variant="secondary" size="sm" icon="visibility" :href="$detailUrl">Xem</x-ui.button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-ui.empty-state icon="inbox" title="Không có order đề nào" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$orders" :options="[]" unit="order" /></x-slot:footer>
        </x-ui.data-table>

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
                                <span class="block font-caption text-caption {{ $bt->stage ? 'text-on-surface-variant' : 'text-warning' }}">{{ $bt->stage?->label ?? 'Chưa gắn chặng' }}</span>
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
                                                     @click="stageUrl = {{ \Illuminate\Support\Js::from(route('syllabus.big-tests.stage', $bt->id)) }}; stageTest = {{ \Illuminate\Support\Js::from($bt->code.' · '.$bt->classModel?->name) }}; stageOptions = {{ \Illuminate\Support\Js::from($stageOptions[$bt->class_id] ?? []) }}; stageValue = {{ \Illuminate\Support\Js::from((string) ($bt->syllabus_stage_id ?? '')) }}; $dispatch('open-modal', 'big-test-stage')">Gắn chặng</x-ui.button>
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
                    <x-ui.select label="Chặng" name="syllabus_stage_id" x-model="stageValue" placeholder="— Không gắn chặng —">
                        <template x-for="(label, id) in stageOptions" :key="id">
                            <option :value="String(id)" x-text="label" :selected="String(id) === stageValue"></option>
                        </template>
                    </x-ui.select>
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'big-test-stage')">Hủy</x-ui.button>
                    <x-ui.button type="submit" form="big-test-stage-form" icon="save">Lưu</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>

    {{-- Chi tiết & phân phối order đề: mở sẵn khi URL có ?order=; đóng → bỏ order khỏi thanh địa chỉ. --}}
    @if ($selectedOrder)
        @php($oc = $selectedOrder->classModel)
        @php($reviewing = $canReview && ! in_array($selectedOrder->status, ['approved', 'rejected'], true))
        <x-ui.modal name="order-detail" :title="'Order đề '.$selectedOrder->code" max-width="3xl" show
                    :dismiss-url="route('syllabus.big-tests.distribution', $listQuery)"
                    x-data="{ link: {{ \Illuminate\Support\Js::from((string) old('test_link', '')) }}, rejecting: {{ $errors->has('rejection_reason') ? 'true' : 'false' }} }">
            <div class="space-y-lg">
                <div class="space-y-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="rounded bg-surface-container-high px-sm py-0.5 font-label text-label text-on-surface-variant">CLASS ID: {{ $oc?->code ?? '—' }}</span>
                        <x-ui.badge :color="$selectedOrder->status_color">{{ $selectedOrder->status_label }}</x-ui.badge>
                    </div>
                    <h3 class="font-h2 text-h2 text-on-surface">{{ $oc?->name }}{{ $oc?->code ? ' - '.$oc->code : '' }}</h3>
                    <p class="font-body-small text-body-small text-on-surface-variant">{{ collect([$oc?->course?->name, $oc?->branch?->name])->filter()->implode(' · ') ?: '—' }}. Đề {{ $selectedOrder->type_label }} cho {{ $selectedOrder->stage_label }}.</p>
                    <dl class="grid grid-cols-1 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md sm:grid-cols-3">
                        <div>
                            <dt class="font-label text-label uppercase text-on-surface-variant">Ngày thi dự kiến</dt>
                            <dd class="mt-xs font-code text-body-small text-on-surface">{{ $selectedOrder->exam_date?->format('d/m/Y') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-label text-label uppercase text-on-surface-variant">Giáo viên</dt>
                            <dd class="mt-xs font-body-medium text-body-small text-on-surface">{{ $selectedOrder->teacher?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-label text-label uppercase text-on-surface-variant">Hạn xử lý</dt>
                            <dd @class(['mt-xs font-code text-body-small', 'font-semibold text-error' => $selectedOrder->isOverdue(), 'text-on-surface' => ! $selectedOrder->isOverdue()])>{{ $selectedOrder->due_date?->format('d/m/Y') ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>

                <div>
                    <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Yêu cầu từ Giáo viên</p>
                    <p class="whitespace-pre-line rounded-lg border-l-4 border-primary-container bg-surface-container-low p-md font-body-small text-body-small italic text-on-surface">{{ $selectedOrder->note ? '"'.$selectedOrder->note.'"' : 'Không có ghi chú.' }}</p>
                </div>

                @if ($selectedOrder->status === 'approved')
                    <x-ui.alert type="success" class="space-y-1 font-body-small text-body-small">
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
                    </x-ui.alert>
                @elseif ($selectedOrder->status === 'rejected')
                    <x-ui.alert type="error" class="font-body-small text-body-small">
                        <p class="font-semibold">Đã từ chối bởi {{ $selectedOrder->reviewer?->name }} lúc {{ $selectedOrder->reviewed_at?->format('H:i d/m/Y') }}</p>
                        <p class="mt-1">Lý do: {{ $selectedOrder->rejection_reason }}</p>
                    </x-ui.alert>
                @elseif ($reviewing)
                    <div class="space-y-md">
                        <p class="font-label text-label uppercase text-on-surface-variant">Phân phối đề</p>
                        <form id="approve-order-form" method="POST" action="{{ route('syllabus.big-tests.orders.approve', $selectedOrder->id) }}" class="space-y-md" x-show="! rejecting">
                            @csrf
                            <x-ui.input type="url" name="test_link" label="Link đề Big Test (Folder lớp)" icon="link" x-model="link" required placeholder="Dán link Google Drive hoặc OneDrive tại đây..." />
                            <x-ui.input type="url" name="speaking_link" label="Link phần Speaking (GV xem sau khi phân phối)" icon="record_voice_over" placeholder="Link riêng phần Speaking (tùy chọn)..." />
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
                                        <p class="font-caption text-caption text-on-surface-variant sm:col-span-2">Hệ thống tạo đợt Big Test gắn <strong>chặng đang mở</strong> của lớp và phân phối luôn để giáo viên nhập kết quả.</p>
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
                            <x-ui.alert type="error" class="font-body-small text-body-small" :title="$selectedOrder->isOverdue() ? 'Cảnh báo: Phân phối trễ hạn SLA' : 'Cảnh báo: Sắp hết hạn SLA'">
                                <p>Hệ thống ghi nhận cần hoàn thành phân phối trước {{ \App\Models\BigTestOrder::LEAD_DAYS }} ngày so với lịch thi (Hạn chót: {{ $selectedOrder->due_date->format('d/m/Y') }}). Vui lòng ưu tiên xử lý ngay.</p>
                            </x-ui.alert>
                        @endif

                        <form id="reject-order-form" method="POST" action="{{ route('syllabus.big-tests.orders.reject', $selectedOrder->id) }}" x-show="rejecting" x-cloak>
                            @csrf
                            <x-ui.textarea name="rejection_reason" id="reject-order-reason" label="Lý do từ chối" required rows="3" />
                        </form>
                    </div>
                @endif
            </div>

            @if ($reviewing)
                <x-slot:footer>
                    <div x-show="! rejecting" class="flex w-full flex-wrap items-center justify-between gap-sm">
                        <x-ui.button variant="danger-text" icon="close" @click="rejecting = true; $nextTick(() => document.getElementById('reject-order-reason')?.focus())">Từ chối yêu cầu</x-ui.button>
                        <div class="flex flex-wrap items-center gap-sm">
                            <x-ui.button variant="secondary" icon="visibility" x-bind:disabled="! link" @click="link && window.open(link, '_blank', 'noopener')">Xem trước tệp</x-ui.button>
                            <x-ui.button type="submit" form="approve-order-form" icon="send">Phê duyệt &amp; Phân phối</x-ui.button>
                        </div>
                    </div>
                    <div x-show="rejecting" x-cloak class="flex flex-wrap justify-end gap-sm">
                        <x-ui.button variant="secondary" @click="rejecting = false">Quay lại</x-ui.button>
                        <x-ui.button type="submit" form="reject-order-form" variant="danger" icon="close">Xác nhận từ chối</x-ui.button>
                    </div>
                </x-slot:footer>
            @endif
        </x-ui.modal>
    @endif
</x-app-layout>
