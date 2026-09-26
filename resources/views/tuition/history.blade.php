{{-- Mockup: ui-full-tinh-nang-menglish/hoc-phi-va-hoa-don-ui-mockup/lich-su-thu-hoc-phi --}}
<x-app-layout title="Lịch sử thu học phí">
    @php
        $money = fn ($v) => number_format((float) $v, 0, ',', '.');
        $methodIcon = ['transfer' => 'account_balance', 'vietqr' => 'qr_code_2', 'cash' => 'payments', 'pos' => 'credit_card'];
        $rows = $receipts->getCollection()->map(function ($rc) use ($templates) {
            $student = $rc->tuition?->student ?? $rc->student;
            $series = $rc->invoice_number ? \Illuminate\Support\Str::beforeLast($rc->invoice_number, '-') : null;

            return [
                'id' => $rc->id,
                'receipt_number' => $rc->receipt_number,
                'invoice_number' => $rc->invoice_number,
                'template_code' => $series ? ($templates[$series] ?? null) : null,
                'series' => $series,
                'student_name' => $student?->name,
                'student_code' => $student?->code,
                'student_phone' => $rc->payer_phone ?: $student?->phone,
                'payer_name' => $rc->payer_name ?: $student?->name,
                'fee_label' => $rc->tuition?->fee_label ?? 'Phụ thu (không gắn khoản học phí)',
                'class_name' => $rc->tuition?->classModel?->name ?? $student?->currentClass?->name,
                'branch_name' => $rc->tuition?->branch?->name ?? $student?->branch?->name,
                'amount' => (float) $rc->amount,
                'surcharge' => (float) $rc->surcharge_amount,
                'surcharge_reason' => $rc->surcharge_reason,
                'method' => \App\Models\TuitionReceipt::METHOD_LABELS[$rc->payment_method] ?? $rc->payment_method,
                'transaction_code' => $rc->transaction_code,
                'status' => $rc->status,
                'status_label' => $rc->status_label,
                'payment_date' => $rc->payment_date?->format('d/m/Y'),
                'created_at' => $rc->created_at?->format('d/m/Y - H:i'),
                'creator_name' => $rc->creator?->name,
                'approver_name' => $rc->approver?->name,
                'notes' => $rc->notes,
                'rejection_reason' => $rc->rejection_reason,
                'proof' => $rc->proof_image,
                'proof_is_pdf' => $rc->proof_image && str_ends_with(strtolower($rc->proof_image), '.pdf'),
                'approve_url' => route('tuition.receipts.approve', ['selected_id' => $rc->id, 'status' => 'all']),
            ];
        })->keyBy('id');
    @endphp

    <x-ui.page-header title="Lịch sử thu học phí"
                      :description="$student ? 'Học viên: '.$student->name.' • Mã HV: '.$student->code : 'Tra cứu phiếu thu đã lập, trạng thái duyệt, đối soát và in phiếu thu.'">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="download" :href="route('tuition.history.export', request()->query())">Xuất Excel</x-ui.button>
            @can('tuition.create')
                <x-ui.button icon="add" :href="route('tuition.receipts.create', $student ? ['student_id' => $student->id] : [])">Tải lên biên lai mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <form method="GET" action="{{ route('tuition.history') }}" class="mb-md flex flex-wrap items-end gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
        @if ($filters['student_id'])<input type="hidden" name="student_id" value="{{ $filters['student_id'] }}">@endif
        <label class="block min-w-[220px] flex-1">
            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Tìm kiếm</span>
            <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Mã phiếu, số HĐ, mã GD, tên / mã học viên..." class="w-full rounded-lg border-outline-variant font-body-base text-body-base" />
        </label>
        <label class="block">
            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Trạng thái</span>
            <select name="status" class="rounded-lg border-outline-variant font-body-base text-body-base">
                <option value="">Tất cả</option>
                @foreach (['draft' => 'Bản nháp', 'pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Bị từ chối', 'cancelled' => 'Đã hủy hóa đơn'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Hình thức</span>
            <select name="method" class="rounded-lg border-outline-variant font-body-base text-body-base">
                <option value="">Tất cả</option>
                @foreach (\App\Models\TuitionReceipt::METHOD_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($filters['method'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Từ ngày</span>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="rounded-lg border-outline-variant font-code text-code" />
        </label>
        <label class="block">
            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Đến ngày</span>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="rounded-lg border-outline-variant font-code text-code" />
        </label>
        <label class="block">
            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Khoản thu</span>
            <select name="kind" class="rounded-lg border-outline-variant font-body-base text-body-base">
                <option value="all" @selected($filters['kind'] === 'all')>Tất cả khoản thu</option>
                <option value="renewal" @selected($filters['kind'] === 'renewal')>Chỉ khoản thu tái tục</option>
            </select>
        </label>
        <x-ui.button type="submit" icon="filter_list">Lọc</x-ui.button>
        @if (request()->hasAny(['search', 'status', 'method', 'from', 'to', 'kind', 'student_id']))
            <x-ui.button variant="ghost" icon="restart_alt" :href="route('tuition.history')">Xóa lọc</x-ui.button>
        @endif
    </form>

    @if ($filters['kind'] === 'renewal')
        <x-ui.alert type="info" class="mb-md">Danh sách chỉ hiển thị các khoản thu tái tục — khoản phí đăng ký ban đầu xem tại hồ sơ CRM của học viên.</x-ui.alert>
    @endif

    <div x-data="{ detail: null, printing: null, rows: @js($rows), open(id) { this.detail = this.rows[id]; }, print(id) { this.printing = this.rows[id]; this.$nextTick(() => window.print()); } }">
        <x-ui.data-table min-width="1100px">
            <table>
                <thead>
                    <tr>
                        <th>Mã phiếu</th>
                        <th>Số HĐĐT</th>
                        <th>Học viên</th>
                        <th>Khoản thu</th>
                        <th class="text-right">Số tiền</th>
                        <th>Hình thức</th>
                        <th>Trạng thái</th>
                        <th>Ngày thu</th>
                        <th>Đối soát</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($receipts as $rc)
                        @php $st = $rc->tuition?->student ?? $rc->student; @endphp
                        <tr>
                            <td class="whitespace-nowrap font-code text-code text-primary">{{ $rc->receipt_number }}</td>
                            <td class="whitespace-nowrap font-code text-code">{{ $rc->invoice_number ?? '—' }}</td>
                            <td>
                                <a href="{{ route('tuition.history', ['student_id' => $st?->id]) }}" class="font-body-medium hover:text-primary">{{ $st?->name ?? '—' }}</a>
                                <div class="font-code text-caption text-on-surface-variant">{{ $st?->code }}</div>
                            </td>
                            <td>
                                {{ $rc->tuition?->fee_label ?? 'Phụ thu' }}
                                @if ((float) $rc->surcharge_amount > 0)
                                    <div class="font-caption text-caption text-on-surface-variant">+ Phụ thu {{ $money($rc->surcharge_amount) }}đ</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right font-code text-code {{ $rc->amount < 0 ? 'text-error' : '' }}">{{ $money($rc->amount) }} VNĐ</td>
                            <td class="whitespace-nowrap">
                                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">{{ $methodIcon[$rc->payment_method] ?? 'payments' }}</span>{{ \App\Models\TuitionReceipt::METHOD_LABELS[$rc->payment_method] ?? $rc->payment_method }}</span>
                            </td>
                            <td><x-ui.badge :color="$rc->status_color">{{ $rc->status_label }}</x-ui.badge></td>
                            <td class="whitespace-nowrap font-code text-code">{{ $rc->payment_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="whitespace-nowrap">
                                @if ($rc->status === 'approved' && $rc->invoice_number)
                                    <span class="font-body-medium text-tertiary">Chính thức</span>
                                @elseif ($rc->status === 'cancelled')
                                    <span class="text-error">Đã hủy HĐ</span>
                                @else
                                    <span class="text-on-surface-variant">-</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <x-ui.button size="sm" variant="ghost" icon="visibility" @click="open({{ $rc->id }})" title="Xem chi tiết" aria-label="Xem chi tiết" />
                                <x-ui.button size="sm" variant="ghost" icon="print" @click="print({{ $rc->id }})" title="In phiếu thu" aria-label="In phiếu thu" />
                                @if (in_array($rc->status, \App\Models\TuitionReceipt::EDITABLE_STATUSES, true)
                                    && ((int) $rc->creator_id === (int) auth()->id() || auth()->user()?->hasRole('admin')))
                                    <x-ui.button size="sm" variant="ghost" icon="edit" :href="route('tuition.receipts.edit', $rc->id)" title="Sửa phiếu nháp / bị trả về rồi gửi duyệt lại" aria-label="Sửa phiếu" />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><x-ui.empty-state icon="receipt_long" title="Chưa có phiếu thu phù hợp" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>
                <x-ui.pagination :paginator="$receipts" unit="phiếu thu" />
            </x-slot:footer>
        </x-ui.data-table>

        {{-- Chi tiết phiếu thu (ngăn bên phải) --}}
        <div x-show="detail" x-cloak class="fixed inset-0 z-50 flex justify-end print:hidden" role="dialog" aria-modal="true" aria-label="Chi tiết phiếu thu">
            <div class="absolute inset-0 bg-on-surface/40" @click="detail = null"></div>
            <aside class="relative flex h-full w-full max-w-md flex-col overflow-y-auto bg-surface-container-lowest shadow-level-3">
                <header class="flex items-center justify-between border-b border-surface-container p-md">
                    <h3 class="flex items-center gap-sm font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary" aria-hidden="true">receipt_long</span>Chi tiết Phiếu thu</h3>
                    <x-ui.button variant="ghost" icon="close" @click="detail = null" aria-label="Đóng" />
                </header>
                <template x-if="detail">
                    <div class="space-y-md p-md">
                        <dl class="grid grid-cols-2 gap-sm font-body-small text-body-small">
                            <dt class="text-on-surface-variant">Mã phiếu</dt><dd class="font-code text-primary" x-text="detail.receipt_number"></dd>
                            <dt class="text-on-surface-variant">Số HĐĐT</dt><dd class="font-code" x-text="detail.invoice_number || '—'"></dd>
                            <dt class="text-on-surface-variant">Học viên</dt><dd x-text="(detail.student_name || '—') + (detail.student_code ? ' (' + detail.student_code + ')' : '')"></dd>
                            <dt class="text-on-surface-variant">Khoản thu</dt><dd x-text="detail.fee_label"></dd>
                            <dt class="text-on-surface-variant">Số tiền</dt><dd class="font-code" x-text="new Intl.NumberFormat('vi-VN').format(detail.amount) + ' VNĐ'"></dd>
                            <template x-if="detail.surcharge > 0"><dt class="text-on-surface-variant">Phụ thu</dt></template>
                            <template x-if="detail.surcharge > 0"><dd x-text="new Intl.NumberFormat('vi-VN').format(detail.surcharge) + ' VNĐ — ' + (detail.surcharge_reason || '')"></dd></template>
                            <dt class="text-on-surface-variant">Hình thức</dt><dd x-text="detail.method + (detail.transaction_code ? ' · ' + detail.transaction_code : '')"></dd>
                            <dt class="text-on-surface-variant">Ngày thu</dt><dd x-text="detail.payment_date || '—'"></dd>
                            <dt class="text-on-surface-variant">Ngày lập</dt><dd x-text="detail.created_at"></dd>
                            <dt class="text-on-surface-variant">Người lập</dt><dd x-text="detail.creator_name || '—'"></dd>
                            <dt class="text-on-surface-variant">Người duyệt</dt><dd x-text="detail.approver_name || '—'"></dd>
                            <dt class="text-on-surface-variant">Trạng thái</dt><dd x-text="detail.status_label"></dd>
                            <dt class="text-on-surface-variant">Ghi chú</dt><dd x-text="detail.notes || '—'"></dd>
                            <template x-if="detail.rejection_reason"><dt class="text-error">Lý do từ chối</dt></template>
                            <template x-if="detail.rejection_reason"><dd class="text-error" x-text="detail.rejection_reason"></dd></template>
                        </dl>
                        @can('tuition.approve')
                            <div x-show="detail.status === 'pending'" class="flex gap-sm">
                                <a :href="detail.approve_url" class="inline-flex flex-1 items-center justify-center gap-xs rounded-lg bg-primary-container px-md py-sm font-body-medium text-body-medium text-white shadow-sm hover:bg-primary"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">check</span>Phê duyệt phiếu</a>
                                <a :href="detail.approve_url" class="inline-flex flex-1 items-center justify-center gap-xs rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-medium text-body-medium text-on-surface shadow-sm hover:bg-surface-container-low"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">undo</span>Yêu cầu chỉnh sửa</a>
                            </div>
                        @endcan
                        <section class="space-y-sm">
                            <h4 class="flex items-center gap-xs font-label text-label uppercase text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">image</span>Minh chứng</h4>
                            <template x-if="detail.proof && ! detail.proof_is_pdf">
                                <a :href="detail.proof" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-outline-variant">
                                    <img :src="detail.proof" alt="Minh chứng thanh toán" class="max-h-72 w-full object-contain" />
                                    <span class="block bg-surface-container-low p-xs text-center font-caption text-caption text-primary">Phóng to</span>
                                </a>
                            </template>
                            <template x-if="detail.proof && detail.proof_is_pdf">
                                <a :href="detail.proof" target="_blank" rel="noopener" class="inline-flex items-center gap-xs text-primary hover:underline"><span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>Mở file PDF minh chứng</a>
                            </template>
                            <p x-show="! detail.proof" class="font-body-small text-body-small text-on-surface-variant">Phiếu không có file minh chứng.</p>
                        </section>
                    </div>
                </template>
            </aside>
        </div>

        {{-- Mẫu in phiếu thu (chỉ hiện khi in) --}}
        <div class="hidden print:block" id="printableReceipt">
            <template x-if="printing">
                <div class="space-y-6 p-8 text-gray-900">
                    <div class="flex items-start justify-between border-b border-gray-200 pb-4">
                        <div>
                            <div class="text-xs font-black uppercase tracking-wider">{{ \App\Support\CenterInfo::name() ?? 'MENGLISH' }}</div>
                            <div class="text-[11px] text-gray-500"><span x-text="printing.branch_name || ''"></span>@if (\App\Support\CenterInfo::phone()) · Hotline: {{ \App\Support\CenterInfo::phone() }}@endif</div>
                            @if (\App\Support\CenterInfo::website() || \App\Support\CenterInfo::taxCode())
                                <div class="text-[10px] text-gray-400">{{ collect([\App\Support\CenterInfo::website() ? 'Website: '.\App\Support\CenterInfo::website() : null, \App\Support\CenterInfo::taxCode() ? 'MST: '.\App\Support\CenterInfo::taxCode() : null])->filter()->implode(' · ') }}</div>
                            @endif
                        </div>
                        <div class="text-right font-mono text-xs">
                            <div x-show="printing.template_code">Mẫu số: <span x-text="printing.template_code"></span></div>
                            <div x-show="printing.series">Ký hiệu: <span x-text="printing.series"></span></div>
                            <div>Số HĐĐT: <span x-text="printing.invoice_number || 'Chưa cấp'"></span></div>
                        </div>
                    </div>
                    <div class="text-center">
                        <h2 class="text-xl font-black uppercase">Phiếu thu học phí</h2>
                        <p class="text-xs">Số phiếu: <strong x-text="printing.receipt_number"></strong> · Ngày thu: <span x-text="printing.payment_date || printing.created_at"></span></p>
                        <p x-show="printing.status !== 'approved'" class="mt-1 text-xs font-bold uppercase text-rose-700" x-text="'Phiếu ' + printing.status_label.toLowerCase() + ' — không có giá trị thanh toán'"></p>
                    </div>
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between"><span>Người nộp tiền:</span><strong x-text="printing.payer_name || '—'"></strong></div>
                        <div class="flex justify-between"><span>Học viên:</span><strong x-text="(printing.student_name || '—') + ' (' + (printing.student_code || '') + ')'"></strong></div>
                        <div class="flex justify-between"><span>Số điện thoại:</span><span x-text="printing.student_phone || '—'"></span></div>
                        <div class="flex justify-between"><span>Khoản thu:</span><span x-text="printing.fee_label"></span></div>
                        <div class="flex justify-between"><span>Lớp học:</span><span x-text="printing.class_name || '—'"></span></div>
                        <div class="flex justify-between"><span>Hình thức:</span><span x-text="printing.method"></span></div>
                        <div class="flex justify-between border-t pt-2"><strong>Số tiền thực thu:</strong><strong x-text="new Intl.NumberFormat('vi-VN').format(printing.amount) + ' VNĐ'"></strong></div>
                        <div class="flex justify-between"><span>Nội dung:</span><span x-text="printing.notes || ''"></span></div>
                    </div>
                    <div class="grid grid-cols-3 gap-4 pt-4 text-center text-xs">
                        <div class="space-y-12"><div class="font-bold">Người nộp tiền</div><div class="italic text-gray-400">(Ký &amp; ghi rõ họ tên)</div></div>
                        <div class="space-y-12"><div class="font-bold">Người lập phiếu</div><div x-text="printing.creator_name || ''"></div></div>
                        <div class="space-y-12"><div class="font-bold">Kế toán / Thủ quỹ</div><div x-text="printing.approver_name || ''"></div></div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <style>
        @media print {
            body * { visibility: hidden; }
            #printableReceipt, #printableReceipt * { visibility: visible; }
            #printableReceipt { position: absolute; inset: 0; }
        }
    </style>
</x-app-layout>
