{{-- Mockup: ui-full-tinh-nang-menglish/hoc-phi-va-hoa-don-ui-mockup/duyet-phieu-thu-hoc-phi --}}
<x-app-layout title="Duyệt phiếu thu học phí" hide-errors>
    <x-ui.page-header title="Duyệt phiếu thu học phí" description="Kiểm tra, đối chiếu chứng từ và phê duyệt các phiếu thu học phí & phụ thu từ nhân viên tư vấn/học vụ">
        <x-slot:breadcrumbs>
            <a href="{{ route('tuition.students') }}" class="hover:text-primary">Học phí &amp; Hóa đơn</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>Duyệt phiếu thu</span>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    @include('tuition.partials.errors')

    {{-- Tự làm mới 90 giây/lần, trừ khi đang mở hộp thoại (x-ui.modal: open-modal / modal-closed) hoặc đang gõ. --}}
    <div class="mx-auto max-w-[1520px] space-y-5" x-data="{ openModals: 0 }"
         x-on:open-modal.window="openModals++" x-on:modal-closed.window="openModals = Math.max(0, openModals - 1)"
         x-init="setInterval(() => { if (! openModals && document.visibilityState === 'visible' && ! document.querySelector('input:focus, textarea:focus')) window.location.reload(); }, 90000)">

        @if (isset($errors) && $errors->any())
            <x-ui.alert type="error" title="Vui lòng kiểm tra các lỗi sau:">
                <ul class="list-inside list-disc pl-5">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        {{-- BEGIN: HeaderPanel (Thống kê & Bộ lọc) --}}
        <header class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
            <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h2 class="flex items-center gap-3 text-lg font-bold tracking-tight text-on-surface">
                        <span>Hàng đợi duyệt</span>
                        <x-ui.badge color="warning" pill>{{ $pendingCount }} phiếu chờ xử lý</x-ui.badge>
                    </h2>
                    <p class="mt-1 text-xs text-on-surface-variant">
                        Kiểm tra, đối chiếu chứng từ và phê duyệt các phiếu thu học phí &amp; phụ thu từ nhân viên tư vấn/học vụ
                    </p>
                </div>

                {{-- 3 Thẻ chỉ số Counter --}}
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <x-ui.stat-card label="Chờ duyệt" :value="$pendingCount.' phiếu'" tone="warning" icon="schedule"
                                    :hint="'('.number_format((float) $pendingTotal, 0, ',', '.').' VNĐ)'" />
                    <x-ui.stat-card label="Đã duyệt hôm nay" :value="$approvedTodayCount.' phiếu'" tone="success" icon="check_circle" />
                    <x-ui.stat-card label="Đã từ chối hôm nay" :value="$rejectedTodayCount.' phiếu'" tone="error" icon="cancel" />
                </div>
            </div>

            {{-- Form Lọc & Tìm kiếm --}}
            <form method="GET" action="{{ route('tuition.receipts.approve') }}" class="flex flex-wrap items-center justify-between gap-3 border-t border-surface-container-highest pt-4">
                <div class="flex w-full flex-wrap items-center gap-2.5 lg:w-auto">
                    {{-- Branch Selector --}}
                    <div class="min-w-[160px]">
                        <x-ui.select name="branch_id" onchange="this.form.submit()" aria-label="Cơ sở" class="cursor-pointer">
                            <option value="all">Tất cả Cơ sở</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Payment Method Selector --}}
                    <div class="min-w-[150px]">
                        <x-ui.select name="payment_method" onchange="this.form.submit()" aria-label="Hình thức" class="cursor-pointer">
                            <option value="all">Hình thức: Tất cả</option>
                            <option value="transfer" {{ request('payment_method') === 'transfer' || request('payment_method') === 'ck' ? 'selected' : '' }}>Chuyển khoản</option>
                            <option value="cash" {{ request('payment_method') === 'cash' ? 'selected' : '' }}>Tiền mặt</option>
                        </x-ui.select>
                    </div>

                    {{-- Status tabs --}}
                    @php $statusFilter = request('status', $pendingCount > 0 ? 'pending' : 'all'); @endphp
                    <x-ui.tabs>
                        <x-ui.tab :href="route('tuition.receipts.approve', array_merge(request()->except('status'), ['status' => 'pending']))" :active="$statusFilter === 'pending'">Chờ duyệt ({{ $pendingCount }})</x-ui.tab>
                        <x-ui.tab :href="route('tuition.receipts.approve', array_merge(request()->except('status'), ['status' => 'approved']))" :active="request('status') === 'approved'">Đã duyệt</x-ui.tab>
                        <x-ui.tab :href="route('tuition.receipts.approve', array_merge(request()->except('status'), ['status' => 'rejected']))" :active="request('status') === 'rejected'">Từ chối</x-ui.tab>
                        <x-ui.tab :href="route('tuition.receipts.approve', array_merge(request()->except('status'), ['status' => 'all']))" :active="$statusFilter === 'all'">Tất cả</x-ui.tab>
                    </x-ui.tabs>
                </div>

                {{-- Search Input --}}
                <div class="relative w-full lg:w-80">
                    <x-ui.input name="q" icon="search" :value="request('q')" placeholder="Tìm theo tên học viên, mã phiếu..." class="pr-8" />
                    @if (request('q'))
                        <a href="{{ route('tuition.receipts.approve', request()->except('q')) }}" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant/70 hover:text-on-surface-variant" aria-label="Xóa từ khóa">
                            <span class="material-symbols-outlined text-base">close</span>
                        </a>
                    @endif
                </div>
            </form>
        </header>

        {{-- BEGIN: MasterDetailGrid (5 Cột danh sách hàng đợi + 7 Cột chi tiết kiểm tra) --}}
        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-12">
            {{-- CỘT TRÁI (5 Cột): Danh sách hàng đợi --}}
            <section class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm lg:col-span-5">
                <div class="flex items-center justify-between border-b border-surface-container-highest bg-surface-container-low p-4">
                    <div class="flex items-center gap-2">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-on-surface">Hàng đợi phiếu thu</h3>
                        <x-ui.badge color="warning" pill :dot="false" class="font-code">{{ $pendingReceipts->count() }} phiếu</x-ui.badge>
                    </div>
                    <span class="flex items-center gap-1 text-[11px] text-on-surface-variant/70">
                        <span class="material-symbols-outlined text-xs">autorenew</span>
                        Tự động làm mới (90 giây)
                    </span>
                </div>

                <div class="custom-scrollbar max-h-[820px] divide-y divide-surface-container-highest overflow-y-auto">
                    @forelse ($pendingReceipts as $rc)
                        @php
                            $isSelected = $selectedReceipt && $selectedReceipt->id === $rc->id;
                            $student = $rc->tuition?->student ?? $rc->student;
                            $branch = $student?->branch;
                            $className = $rc->tuition?->classModel?->name ?? $student?->currentClass?->name ?? 'Lớp học';
                        @endphp
                        <a href="{{ route('tuition.receipts.approve', array_merge(request()->all(), ['selected_id' => $rc->id])) }}" class="relative block p-4 transition {{ $isSelected ? 'border-l-4 border-primary-container bg-primary-container/5 shadow-xs' : 'border-l-4 border-transparent hover:bg-surface-container-low' }}">
                            <div class="mb-1.5 flex items-start justify-between gap-2">
                                <div class="flex items-center gap-1.5">
                                    <span class="rounded px-2 py-0.5 font-code text-xs font-bold {{ $isSelected ? 'border border-primary-container/40 bg-surface-container-lowest text-on-surface' : 'border border-surface-container-highest bg-surface-container text-on-surface-variant' }}">
                                        {{ $rc->receipt_number }}
                                    </span>
                                    @if ($rc->payment_method === 'cash')
                                        <x-ui.badge color="success" :dot="false"><span class="material-symbols-outlined text-xs">payments</span>Tiền mặt</x-ui.badge>
                                    @else
                                        <x-ui.badge color="secondary" :dot="false"><span class="material-symbols-outlined text-xs">account_balance</span>Chuyển khoản</x-ui.badge>
                                    @endif
                                </div>
                                <span class="text-[11px] font-medium text-on-surface-variant/70">{{ $rc->created_at->diffForHumans() }}</span>
                            </div>

                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="flex items-center gap-1 text-xs font-bold text-on-surface">
                                        <span>{{ $student?->name ?? 'Học viên' }}</span>
                                        <span class="font-code text-[11px] font-normal text-on-surface-variant/70">({{ $student?->code ?? 'HV' }})</span>
                                    </h4>
                                    <p class="mt-0.5 flex items-center gap-1.5 text-[11px] text-on-surface-variant">
                                        <span>{{ $className }}</span>
                                        @if ($rc->surcharge_amount > 0)
                                            <x-ui.badge color="warning" :dot="false">+ Phụ thu</x-ui.badge>
                                        @endif
                                    </p>
                                </div>
                                <div class="text-right">
                                    <div class="font-code text-sm font-bold {{ $isSelected ? 'text-primary' : 'text-on-surface' }}">{{ number_format((float) $rc->amount, 0, ',', '.') }} đ</div>
                                    <div class="text-[10px] text-on-surface-variant/70">{{ $branch?->name ?? 'Trụ sở chính' }}</div>
                                </div>
                            </div>

                            <div class="mt-2.5 flex items-center justify-between border-t border-surface-container-highest pt-2 text-[11px] text-on-surface-variant">
                                <span class="flex max-w-[240px] items-center gap-1 truncate">
                                    <span class="material-symbols-outlined text-xs text-on-surface-variant/70">person</span>
                                    Người tạo: <strong class="text-on-surface-variant">{{ $rc->creator?->name ?? 'CM' }}</strong>
                                </span>

                                @if ($rc->proof_image)
                                    <span class="flex items-center gap-0.5 text-[10px] text-on-surface-variant"><span class="material-symbols-outlined text-xs">attach_file</span>Có minh chứng</span>
                                @elseif ($rc->paper_invoice_number)
                                    <span class="text-[10px] text-on-surface-variant">Biên lai số {{ $rc->paper_invoice_number }}</span>
                                @endif
                                @if ($isSelected)
                                    <span class="flex items-center gap-0.5 text-[11px] font-bold text-primary">
                                        Đang xem
                                        <span class="material-symbols-outlined text-xs">chevron_right</span>
                                    </span>
                                @elseif ($rc->status !== 'pending')
                                    <x-ui.badge :color="$rc->status_color" :dot="false">{{ $rc->status_label }}</x-ui.badge>
                                @endif
                            </div>
                        </a>
                    @empty
                        <x-ui.empty-state icon="task" title="Không có phiếu thu nào theo bộ lọc đã chọn." />
                    @endforelse
                </div>
            </section>

            {{-- CỘT PHẢI (7 Cột): Chi tiết phiếu thu & Thao tác duyệt --}}
            <section class="flex min-w-0 flex-col gap-5 lg:col-span-7">
                @if ($selectedReceipt)
                    @php
                        $st = $selectedReceipt->tuition?->student ?? $selectedReceipt->student;
                        $tClass = $selectedReceipt->tuition?->classModel?->name ?? $st?->currentClass?->name ?? 'Chưa gắn lớp';
                        $branchName = $st?->branch?->name ?? 'Chưa gán chi nhánh';
                    @endphp

                    @if (($sepayWarnings ?? collect())->isNotEmpty())
                        <x-ui.alert type="warning" title="Có thể trùng giao dịch SePay đã tự động gạch nợ">
                            <ul class="list-disc space-y-0.5 pl-md">
                                @foreach ($sepayWarnings as $tx)
                                    <li>
                                        SePay #{{ $tx->sepay_id }} — {{ number_format((float) $tx->transfer_amount, 0, ',', '.') }} đ
                                        ngày {{ $tx->transaction_date?->format('d/m/Y H:i') }}
                                        @if ($tx->receipt) (phiếu {{ $tx->receipt->receipt_number }}) @endif
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-xs">Đối chiếu sao kê trước khi duyệt. Nếu đúng là khoản chuyển khác, tick xác nhận trong hộp thoại duyệt.</p>
                        </x-ui.alert>
                    @endif

                    <div class="flex flex-col overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                        {{-- Detail Header --}}
                        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-surface-container-highest bg-surface-container-low p-5 lg:p-6">
                            <div>
                                <div class="flex items-center gap-2.5">
                                    <h2 class="text-lg font-bold text-on-surface">Chi tiết phiếu thu: <span class="font-code text-primary">{{ $selectedReceipt->receipt_number }}</span></h2>
                                    @if ($selectedReceipt->status === 'approved')
                                        <x-ui.badge color="success" pill>Đã duyệt (HĐ: {{ $selectedReceipt->invoice_number ?? 'Auto' }})</x-ui.badge>
                                    @elseif ($selectedReceipt->status !== 'pending')
                                        <x-ui.badge :color="$selectedReceipt->status_color" pill :dot="false">{{ $selectedReceipt->status_label }}</x-ui.badge>
                                    @else
                                        <x-ui.badge color="warning" pill>Chờ duyệt</x-ui.badge>
                                    @endif
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-on-surface-variant">
                                    <span>Thời gian gửi: <strong>{{ $selectedReceipt->created_at?->format('H:i - d/m/Y') ?? now()->format('H:i - d/m/Y') }}</strong></span>
                                    <span>•</span>
                                    <span>Người lập: <strong>{{ $selectedReceipt->creator?->name ?? 'Chưa cập nhật' }}</strong> ({{ $branchName }})</span>
                                </div>
                            </div>

                            @if ($selectedReceipt->student_tuition_id)
                                <x-ui.button variant="secondary" size="sm" icon="print" :href="route('crm.tuition-bill', $selectedReceipt->student_tuition_id)" target="_blank">Xem trước mẫu in</x-ui.button>
                            @endif
                        </div>

                        <div class="flex flex-col gap-6 p-5 lg:p-6">
                            {{-- KHỐI 1: Thông tin học viên & Lớp học --}}
                            <div class="rounded-xl border border-surface-container-highest bg-surface-container-low/40 p-4 lg:p-5">
                                <div class="mb-3.5 flex items-center gap-2">
                                    <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-container/10 text-xs font-bold text-primary">
                                        <span class="material-symbols-outlined text-base">person</span>
                                    </div>
                                    <h3 class="text-sm font-bold uppercase tracking-wide text-on-surface">1. Thông tin học viên &amp; Phụ huynh</h3>
                                </div>

                                <div class="grid grid-cols-1 gap-4 text-xs md:grid-cols-3">
                                    <div class="space-y-0.5 rounded-lg border border-surface-container-highest bg-surface-container-lowest p-3.5">
                                        <span class="mb-0.5 block text-xs uppercase text-on-surface-variant/70">Học viên</span>
                                        <div class="text-sm font-bold text-on-surface">{{ $st?->name ?? 'Học viên' }}</div>
                                        <span class="font-code text-xs font-semibold text-primary">{{ $st?->code ?? 'HV' }}</span>
                                    </div>
                                    <div class="space-y-0.5 rounded-lg border border-surface-container-highest bg-surface-container-lowest p-3.5">
                                        <span class="mb-0.5 block text-xs uppercase text-on-surface-variant/70">Lớp học hiện tại</span>
                                        <div class="text-sm font-bold text-on-surface">{{ $tClass }}</div>
                                        <span class="text-xs text-on-surface-variant">{{ $branchName }}</span>
                                    </div>
                                    <div class="space-y-0.5 rounded-lg border border-surface-container-highest bg-surface-container-lowest p-3.5">
                                        <span class="mb-0.5 block text-xs uppercase text-on-surface-variant/70">Người nộp tiền (Phụ huynh)</span>
                                        <div class="text-sm font-bold text-on-surface">{{ $selectedReceipt->payer_name ?: ($st?->parent_name ?: $st?->name) }}</div>
                                        <span class="font-code text-xs font-semibold text-on-surface-variant">{{ $selectedReceipt->payer_phone ?: ($st?->parent_phone ?: $st?->phone) }}</span>
                                    </div>
                                </div>
                            </div>

                            {{-- KHỐI 2: Chi tiết nguồn tiền & Bảng kê tài chính --}}
                            <div class="rounded-xl border border-surface-container-highest p-4 lg:p-5">
                                <div class="mb-3.5 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-tertiary/10 text-xs font-bold text-tertiary">
                                            <span class="material-symbols-outlined text-base">calculate</span>
                                        </div>
                                        <h3 class="text-sm font-bold uppercase tracking-wide text-on-surface">2. Bảng kê chi tiết các khoản thu</h3>
                                    </div>
                                    <span class="text-xs text-on-surface-variant">
                                        Phương thức: <strong class="font-semibold text-secondary">{{ $selectedReceipt->payment_method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản Ngân hàng' }}</strong>
                                    </span>
                                </div>

                                <x-ui.data-table min-width="480px">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Khoản mục</th>
                                                <th>Nội dung diễn giải</th>
                                                <th class="text-right">Số tiền</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td class="font-semibold">
                                                    Học phí
                                                    @if ($selectedReceipt->tuition?->due_date)
                                                        <span class="block text-[11px] font-normal text-on-surface-variant">(Hạn {{ $selectedReceipt->tuition->due_date->format('d/m/Y') }})</span>
                                                    @endif
                                                </td>
                                                <td class="text-on-surface-variant">
                                                    {{ $selectedReceipt->tuition?->fee_label ?? 'Không gắn khoản học phí (chỉ phụ thu)' }}
                                                    (Đã miễn giảm {{ number_format((float) ($selectedReceipt->discount_amount ?? 0), 0, ',', '.') }} đ)
                                                </td>
                                                <td><x-ui.money :value="$selectedReceipt->tuitionPortion()" suffix="đ" class="font-bold" /></td>
                                            </tr>

                                            @if (($selectedReceipt->surcharge_amount ?? 0) > 0)
                                                <tr class="bg-warning-container/40">
                                                    <td class="flex items-center gap-1.5 font-semibold text-on-warning-container">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-warning"></span>
                                                        Phụ thu phát sinh
                                                    </td>
                                                    <td class="text-on-warning-container">
                                                        {{ $selectedReceipt->surcharge_reason ?: '—' }}
                                                    </td>
                                                    <td class="text-right font-code font-bold text-on-warning-container">
                                                        + {{ number_format((float) $selectedReceipt->surcharge_amount, 0, ',', '.') }} đ
                                                    </td>
                                                </tr>
                                            @endif
                                        </tbody>
                                        <tfoot class="border-t border-primary-container/30 bg-primary-container/5">
                                            <tr>
                                                <td class="text-xs font-bold" colspan="2">
                                                    TỔNG SỐ TIỀN THỰC THU
                                                </td>
                                                <td class="text-right">
                                                    <span class="font-code text-base font-bold text-primary">{{ number_format((float) $selectedReceipt->amount, 0, ',', '.') }} VNĐ</span>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </x-ui.data-table>

                                {{-- Metadata & Notes --}}
                                <div class="mt-4 grid grid-cols-1 gap-3 text-xs md:grid-cols-2">
                                    <div class="space-y-1 rounded-lg border border-surface-container-highest bg-surface-container-low p-3.5">
                                        <span class="block text-[10px] font-bold uppercase text-on-surface-variant">Trạng thái đối soát &amp; Hóa đơn VAT</span>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-ui.badge :color="$reconciliation['tone'] ?? 'neutral'">{{ $reconciliation['label'] ?? '—' }}</x-ui.badge>
                                            @if ($selectedReceipt->is_vat_invoice)
                                                <x-ui.badge color="info" :dot="false">Yêu cầu hóa đơn đỏ (VAT)</x-ui.badge>
                                            @else
                                                <x-ui.badge color="neutral" :dot="false">Không yêu cầu hóa đơn đỏ</x-ui.badge>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="space-y-1 rounded-lg border border-surface-container-highest bg-surface-container-low p-3.5">
                                        <span class="block text-[10px] font-bold uppercase text-on-surface-variant">Ghi chú từ nhân viên tạo phiếu (CM)</span>
                                        <p class="text-xs italic leading-relaxed text-on-surface-variant">
                                            {{ $selectedReceipt->notes ? '"'.$selectedReceipt->notes.'"' : 'Không có ghi chú.' }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {{-- KHỐI 3: Minh chứng chuyển khoản (UNC) & Đối chiếu --}}
                            <div class="rounded-xl border border-surface-container-highest p-4 lg:p-5">
                                <div class="mb-3.5 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-secondary/10 text-xs font-bold text-secondary">
                                            <span class="material-symbols-outlined text-base">receipt</span>
                                        </div>
                                        <div>
                                            <h3 class="text-sm font-bold uppercase tracking-wide text-on-surface">3. Minh chứng chuyển khoản (UNC) / Biên lai</h3>
                                            <p class="text-xs text-on-surface-variant/70">Đối chiếu mã giao dịch và số tài khoản nhận</p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-1.5 rounded-lg border border-surface-container-highest bg-surface-container p-1">
                                        <x-ui.button variant="ghost" size="sm" icon="zoom_in" x-on:click="$dispatch('open-modal', 'zoom-proof')" title="Phóng to" aria-label="Phóng to" />
                                        @if ($selectedReceipt->proof_image)
                                            <x-ui.button variant="ghost" size="sm" icon="download" :href="$selectedReceipt->proof_image" target="_blank" download title="Tải ảnh gốc" aria-label="Tải ảnh gốc" />
                                        @endif
                                    </div>
                                </div>

                                <div class="flex flex-col items-center gap-5 rounded-xl border border-inverse-surface bg-inverse-surface p-5 md:flex-row">
                                    {{-- Proof Image Frame --}}
                                    <div class="group/img relative flex h-52 w-full shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-lg border border-white/10 bg-white/5 md:w-64" x-on:click="$dispatch('open-modal', 'zoom-proof')">
                                        @if ($selectedReceipt->proof_image)
                                            <img src="{{ $selectedReceipt->proof_image }}" alt="Minh chứng" class="h-full w-full object-contain" />
                                        @else
                                            <div class="flex h-full w-full flex-col items-center justify-center gap-2 text-xs text-inverse-on-surface/70">
                                                <span class="material-symbols-outlined text-3xl text-inverse-on-surface/60">image_not_supported</span>
                                                <span class="font-bold text-inverse-on-surface">Chưa có minh chứng</span>
                                                <span class="px-3 text-center text-[11px] text-inverse-on-surface/60">Người lập chưa đính kèm ảnh chuyển khoản / biên lai.</span>
                                            </div>
                                        @endif
                                        <div class="absolute inset-0 flex items-center justify-center gap-1 bg-black/40 text-xs font-bold text-white opacity-0 transition group-hover/img:opacity-100">
                                            <span class="material-symbols-outlined text-base">zoom_in</span> Bấm để phóng to
                                        </div>
                                    </div>

                                    {{-- Proof Match Details --}}
                                    <div class="w-full flex-1 space-y-2.5 text-xs">
                                        <div class="space-y-2 rounded-lg border border-white/10 bg-white/5 p-3.5">
                                            <div class="flex items-center justify-between">
                                                <span class="text-inverse-on-surface/70">Mã tham chiếu:</span>
                                                <span class="font-code font-bold text-warning-container">{{ $selectedReceipt->transaction_code ?: ($selectedReceipt->paper_invoice_number ?: '—') }}</span>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-inverse-on-surface/70">Tài khoản thụ hưởng:</span>
                                                <span class="font-medium text-inverse-on-surface">{{ $beneficiaryAccount ? $beneficiaryAccount->account_number.' ('.$beneficiaryAccount->bank_name.')' : '—' }}</span>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-inverse-on-surface/70">Thời gian giao dịch:</span>
                                                <span class="font-code text-inverse-on-surface">{{ $selectedReceipt->payment_date?->format('H:i - d/m/Y') ?? '—' }}</span>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-inverse-on-surface/70">Trạng thái đối soát:</span>
                                                <span @class(['font-bold flex items-center gap-1 text-xs', 'text-tertiary-fixed-dim' => ($reconciliation['tone'] ?? '') === 'success', 'text-error-container' => ($reconciliation['tone'] ?? '') === 'error', 'text-warning-container' => ! in_array($reconciliation['tone'] ?? '', ['success', 'error'], true)])>
                                                    <span class="material-symbols-outlined text-sm">{{ ($reconciliation['tone'] ?? '') === 'success' ? 'verified' : 'pending' }}</span>
                                                    {{ $reconciliation['label'] ?? 'Cần đối chiếu thủ công' }}
                                                </span>
                                            </div>
                                        </div>

                                        <div class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/20 p-3 text-xs text-warning-container">
                                            <span class="material-symbols-outlined mt-0.5 shrink-0 text-base text-warning-container">info</span>
                                            <span>
                                                {{ $reconciliation['detail'] ?? '' }}
                                                @if ($selectedReceipt->proof_image)
                                                    Vui lòng đối chiếu minh chứng với sao kê ngân hàng / quỹ tiền mặt: số tiền <strong>{{ number_format((float) $selectedReceipt->amount, 0, ',', '.') }} đ</strong> trước khi duyệt.
                                                @else
                                                    Chưa có minh chứng. Chỉ duyệt khi đã xác nhận nhận đủ <strong>{{ number_format((float) $selectedReceipt->amount, 0, ',', '.') }} đ</strong> trên sao kê / quỹ tiền mặt.
                                                @endif
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- KHỐI 4: Thanh tác vụ phê duyệt --}}
                        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-surface-container-highest bg-surface-container-low p-4 lg:p-5">
                            <div class="flex items-center gap-1.5 text-xs text-on-surface-variant">
                                <span class="material-symbols-outlined text-base text-on-surface-variant/70">info</span>
                                <span>Thao tác duyệt sẽ lập tức hạch toán số dư công nợ của học viên và cấp số hóa đơn.</span>
                            </div>

                            <div class="flex items-center gap-3">
                                @if ($selectedReceipt->status === 'pending')
                                    <x-ui.button variant="danger-text" icon="close" x-on:click="$dispatch('open-modal', 'reject-receipt')">Từ chối phiếu thu</x-ui.button>
                                    <x-ui.button icon="check" x-on:click="$dispatch('open-modal', 'approve-receipt')">Duyệt phiếu thu ({{ number_format((float) $selectedReceipt->amount, 0, ',', '.') }} VNĐ)</x-ui.button>
                                @elseif ($selectedReceipt->status === 'approved')
                                    <x-ui.badge color="success" pill>Đã duyệt bởi {{ $selectedReceipt->approver?->name ?? 'Admin' }}</x-ui.badge>
                                @elseif (in_array($selectedReceipt->status, \App\Models\TuitionReceipt::EDITABLE_STATUSES, true)
                                    && (auth()->id() === $selectedReceipt->creator_id || auth()->user()?->isSuperAdmin()))
                                    <x-ui.badge :color="$selectedReceipt->status_color" :dot="false">{{ $selectedReceipt->status_label }}</x-ui.badge>
                                    <x-ui.button variant="secondary" icon="edit" :href="route('tuition.receipts.edit', $selectedReceipt->id)">Sửa phiếu</x-ui.button>
                                    {{-- Người lập gửi duyệt lại (giữ nguyên số liệu; sửa chi tiết qua màn Sửa phiếu) --}}
                                    <form method="POST" action="{{ route('tuition.receipts.update', $selectedReceipt->id) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="amount" value="{{ (float) $selectedReceipt->amount }}">
                                        <input type="hidden" name="tuition_amount" value="{{ $selectedReceipt->tuitionPortion() }}">
                                        <input type="hidden" name="discount_amount" value="{{ (float) $selectedReceipt->discount_amount }}">
                                        <input type="hidden" name="surcharge_amount" value="{{ (float) $selectedReceipt->surcharge_amount }}">
                                        <input type="hidden" name="surcharge_reason" value="{{ $selectedReceipt->surcharge_reason }}">
                                        <input type="hidden" name="payment_method" value="{{ $selectedReceipt->payment_method }}">
                                        <input type="hidden" name="submit_action" value="submit">
                                        <x-ui.button type="submit" icon="send">Gửi duyệt lại</x-ui.button>
                                    </form>
                                @else
                                    <x-ui.badge :color="$selectedReceipt->status_color" :dot="false">{{ $selectedReceipt->status_label }}</x-ui.badge>
                                @endif
                            </div>
                        </footer>
                    </div>

                    {{-- MODAL 1: Xác nhận Duyệt phiếu thu --}}
                    <x-ui.modal name="approve-receipt" title="Xác nhận duyệt phiếu thu" max-width="md">
                        <p class="mb-3 font-code text-xs text-on-surface-variant">Mã: {{ $selectedReceipt->receipt_number }}</p>
                        <form id="approve-receipt-form" action="{{ route('tuition.receipts.approve.action', $selectedReceipt->id) }}" method="POST" class="space-y-3 text-xs leading-relaxed text-on-surface-variant">
                            @csrf
                            <p>
                                Bạn có chắc chắn muốn duyệt phiếu thu <strong class="font-code text-on-surface">{{ $selectedReceipt->receipt_number }}</strong> với tổng số tiền <strong class="font-code text-sm font-bold text-primary">{{ number_format((float) $selectedReceipt->amount, 0, ',', '.') }} VNĐ</strong> cho học viên <strong class="text-on-surface">{{ $st?->name }}</strong>?
                            </p>
                            @if (($sepayWarnings ?? collect())->isNotEmpty())
                                <label class="flex w-full items-start gap-2 rounded-xl border border-warning/30 bg-warning-container p-2.5 text-[11px] text-on-warning-container">
                                    <input type="checkbox" name="confirm_not_duplicate" value="1" class="mt-0.5 rounded text-primary focus:ring-primary-container">
                                    <span>Xác nhận không trùng giao dịch SePay: tôi đã đối chiếu sao kê, đây là một khoản chuyển khác.</span>
                                </label>
                            @endif
                        </form>
                        <x-slot:footer>
                            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'approve-receipt')">Hủy bỏ</x-ui.button>
                            <x-ui.button type="submit" icon="check" form="approve-receipt-form">Xác nhận phê duyệt</x-ui.button>
                        </x-slot:footer>
                    </x-ui.modal>

                    {{-- MODAL 2: Từ chối phiếu thu (Nhập lý do) --}}
                    <x-ui.modal name="reject-receipt" title="Từ chối duyệt phiếu thu" max-width="md">
                        <p class="mb-3 font-code text-xs text-on-surface-variant">Phiếu: {{ $selectedReceipt->receipt_number }} • Học viên: {{ $st?->name }}</p>
                        <form id="reject-receipt-form" action="{{ route('tuition.receipts.reject.action', $selectedReceipt->id) }}" method="POST" class="space-y-3">
                            @csrf
                            <x-ui.textarea name="rejection_reason" label="Lý do từ chối duyệt (Bắt buộc)" required rows="3" placeholder="Ví dụ: Ảnh chụp ủy nhiệm chi bị mất góc mã tham chiếu, số tiền chuyển khoản không khớp với phiếu..." />

                            <x-ui.alert type="error">
                                <span class="text-[11px] leading-relaxed"><strong>Thông báo hệ thống:</strong> Phiếu thu này sẽ chuyển về trạng thái <strong>"Bị từ chối"</strong> kèm thông báo lý do từ chối gửi trả lại nhân viên phụ trách <strong>{{ $selectedReceipt->creator?->name ?? 'CM' }}</strong> để bổ sung minh chứng.</span>
                            </x-ui.alert>
                        </form>
                        <x-slot:footer>
                            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'reject-receipt')">Quay lại</x-ui.button>
                            <x-ui.button variant="danger" type="submit" icon="close" form="reject-receipt-form">Xác nhận từ chối</x-ui.button>
                        </x-slot:footer>
                    </x-ui.modal>

                    {{-- MODAL 3: Phóng to minh chứng --}}
                    <x-ui.modal name="zoom-proof" :title="'Minh chứng đối soát: '.$selectedReceipt->receipt_number" max-width="3xl">
                        <div class="flex min-h-[300px] items-center justify-center">
                            @if ($selectedReceipt->proof_image)
                                <img src="{{ $selectedReceipt->proof_image }}" alt="Minh chứng" class="max-h-[70vh] rounded-lg object-contain" />
                            @else
                                <x-ui.empty-state icon="receipt_long" title="Chưa có minh chứng" />
                            @endif
                        </div>
                    </x-ui.modal>
                @else
                    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-12 shadow-sm">
                        <x-ui.empty-state icon="receipt_long" title="Chưa chọn phiếu thu nào"
                                          description="Vui lòng bấm chọn một phiếu thu từ danh sách chờ duyệt bên trái để kiểm tra chi tiết và đối chiếu chứng từ." />
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
