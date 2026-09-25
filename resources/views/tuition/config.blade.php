{{-- Cấu hình dải số hóa đơn theo chi nhánh (mockup: cauhinhhoadon-ui-mockup). --}}
<x-app-layout title="Cấu hình dải số hóa đơn">
    <x-ui.page-header title="Cấu hình dải số hóa đơn"
                      description="Quản lý và cấp phát dải số hóa đơn tài chính cho từng chi nhánh. Số đã cấp không bao giờ được cấp lại.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="account_balance" :href="route('system-config.bank-accounts')">Tài khoản ngân hàng</x-ui.button>
            <x-ui.button icon="add" href="#range-form">Thêm cấu hình mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-lg xl:grid-cols-3">
        {{-- Danh sách dải số --}}
        <div class="space-y-md xl:col-span-2">
            <x-ui.data-table min-width="760px">
                <table>
                    <thead>
                        <tr>
                            <th>Chi nhánh</th>
                            <th>Ký hiệu / Mẫu số</th>
                            <th>Dải số (đầu – cuối)</th>
                            <th>Số hiện tại</th>
                            <th>Số còn lại</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ranges as $range)
                            @php
                                $remaining = $range->remaining();
                                $low = $remaining !== null && $remaining > 0 && $remaining <= \App\Models\InvoiceConfiguration::LOW_REMAINING_THRESHOLD;
                                $pad = fn ($n) => str_pad((string) $n, \App\Models\InvoiceConfiguration::NUMBER_PAD, '0', STR_PAD_LEFT);
                            @endphp
                            <tr @class(['opacity-60' => ! $range->is_active])>
                                <td>
                                    <div class="font-body-medium text-body-medium">{{ $range->branch?->name ?? 'Dải mặc định (dùng chung)' }}</div>
                                    @unless ($range->branch_id)
                                        <div class="font-caption text-caption text-on-surface-variant">Dùng khi chi nhánh chưa có dải riêng / dải riêng đã hết</div>
                                    @endunless
                                </td>
                                <td>
                                    <div class="font-code text-code">{{ $range->series_code }}</div>
                                    <div class="font-caption text-caption text-on-surface-variant">Mẫu {{ $range->template_code }}</div>
                                </td>
                                <td class="font-code text-code whitespace-nowrap">{{ $pad($range->start_number ?? 1) }} – {{ $range->end_number ? $pad($range->end_number) : '∞' }}</td>
                                <td class="font-code text-code">
                                    {{ $pad($range->current_number) }}
                                    @if ($maxIssued[$range->id] ?? null)
                                        <div class="font-caption text-caption text-on-surface-variant">Đã cấp tới {{ $pad($maxIssued[$range->id]) }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($remaining === null)
                                        <span class="text-on-surface-variant">Không giới hạn</span>
                                    @elseif ($remaining === 0)
                                        <x-ui.badge color="error">Hết số</x-ui.badge>
                                    @elseif ($low)
                                        <span class="inline-flex items-center gap-xs font-body-medium text-error">
                                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">warning</span>{{ $remaining }}
                                            <span class="font-caption text-caption">Sắp hết số</span>
                                        </span>
                                    @else
                                        <span class="font-code text-code">{{ number_format($remaining, 0, ',', '.') }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($range->is_active)
                                        <x-ui.badge color="success" pill>Đang hiệu lực</x-ui.badge>
                                    @else
                                        <x-ui.badge color="neutral" pill>Đã ngừng dùng</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-xs">
                                        <x-ui.button variant="ghost" size="sm" icon="history" title="Số hóa đơn đã cấp gần đây" aria-label="Lịch sử cấp số" @click="$dispatch('open-modal', 'range-history-{{ $range->id }}')" />
                                        @can('invoice_range.manage')
                                            <x-ui.button variant="ghost" size="sm" icon="edit" title="Sửa dải số" aria-label="Sửa dải số" :href="route('tuition.config', ['edit' => $range->id]).'#range-form'" />
                                            <form method="POST" action="{{ route('tuition.config.ranges.toggle', $range->id) }}" class="inline">
                                                @csrf
                                                <x-ui.button type="submit" :variant="$range->is_active ? 'danger-text' : 'ghost'" size="sm"
                                                             :icon="$range->is_active ? 'block' : 'restart_alt'"
                                                             :title="$range->is_active ? 'Ngừng dùng dải số' : 'Dùng lại dải số'"
                                                             :aria-label="$range->is_active ? 'Ngừng dùng dải số' : 'Dùng lại dải số'" />
                                            </form>
                                        @endcan
                                    </div>
                                    <x-ui.modal :name="'range-history-'.$range->id" :title="'Số đã cấp gần đây — '.$range->series_code" max-width="md">
                                        <div class="space-y-xs text-left">
                                            @forelse ($recentInvoices[$range->id] ?? [] as $invoice)
                                                <div class="flex items-center justify-between border-b border-surface-container py-xs font-body-small text-body-small">
                                                    <span class="font-code">{{ $invoice->invoice_number }}</span>
                                                    <span class="text-on-surface-variant">{{ $invoice->status === 'cancelled' ? 'Đã hủy HĐ' : $invoice->updated_at?->format('d/m/Y H:i') }}</span>
                                                </div>
                                            @empty
                                                <p class="text-on-surface-variant">Dải này chưa cấp số nào.</p>
                                            @endforelse
                                        </div>
                                    </x-ui.modal>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <x-ui.empty-state icon="receipt_long" title="Chưa có dải số hóa đơn"
                                                      description="Hệ thống sẽ tự tạo dải mặc định C26MEN khi duyệt phiếu đầu tiên. Nên cấu hình dải riêng cho từng chi nhánh." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-ui.data-table>

            <x-ui.alert type="info" title="Chính sách cấp số hóa đơn">
                <ul class="list-disc space-y-xs pl-md">
                    <li>Khi duyệt phiếu thu, hệ thống lấy số từ dải đang hiệu lực của <strong>chi nhánh ghi nhận học phí</strong>; chi nhánh chưa có dải riêng hoặc dải đã hết thì lấy từ <strong>dải mặc định</strong>.</li>
                    <li>Dải số không được chồng lấn dải khác cùng ký hiệu. "Số hiện tại" là số kế tiếp sẽ cấp và không được lùi về số đã cấp.</li>
                    <li>Hóa đơn bị hủy vẫn giữ số (không cấp lại cho phiếu khác).</li>
                </ul>
            </x-ui.alert>
        </div>

        {{-- Form thêm / sửa dải số --}}
        @can('invoice_range.manage')
            <div id="range-form" class="h-fit overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest">
                <div class="border-b border-outline-variant bg-surface-container-low p-md">
                    <h2 class="font-h3 text-h3 text-on-surface">{{ $editing ? 'Sửa dải số '.$editing->series_code : 'Thêm cấu hình mới' }}</h2>
                    <p class="font-body-small text-body-small text-on-surface-variant">
                        {{ $editing ? ($editing->branch?->name ?? 'Dải mặc định (dùng chung)') : 'Nhập thông tin để cấp dải số hóa đơn mới.' }}
                    </p>
                </div>

                @if ($editing)
                    <form method="POST" action="{{ route('tuition.config.update') }}" class="space-y-md p-md">
                        @csrf
                        <input type="hidden" name="config_id" value="{{ $editing->id }}">
                        <div class="grid grid-cols-2 gap-sm">
                            <x-ui.input name="template_code" label="Mẫu số" :value="$editing->template_code" required />
                            <x-ui.input name="series_code" label="Ký hiệu" :value="$editing->series_code" required />
                            <x-ui.input name="start_number" type="number" min="1" label="Số bắt đầu" :value="$editing->start_number" required />
                            <x-ui.input name="end_number" type="number" min="1" label="Số kết thúc" :value="$editing->end_number" hint="Để trống = không giới hạn" />
                        </div>
                        <x-ui.input name="current_number" type="number" min="1" label="Số hiện tại (số kế tiếp sẽ cấp)" :value="$editing->current_number" required
                                    :hint="($maxIssued[$editing->id] ?? null) ? 'Đã cấp tới '.$maxIssued[$editing->id].' — chỉ được đặt từ '.(($maxIssued[$editing->id]) + 1).' trở lên.' : 'Chưa cấp số nào trong dải này.'" />
                        <div class="flex gap-sm">
                            <x-ui.button type="submit" icon="save" class="flex-1">Lưu thay đổi</x-ui.button>
                            <x-ui.button variant="secondary" :href="route('tuition.config')">Hủy</x-ui.button>
                        </div>
                    </form>
                @else
                    <form method="POST" action="{{ route('tuition.config.ranges.store') }}" class="space-y-md p-md">
                        @csrf
                        <x-ui.select name="branch_id" label="Chọn chi nhánh" placeholder="Dải mặc định (dùng chung)"
                                     :options="$branches->pluck('name', 'id')" />
                        <div class="grid grid-cols-2 gap-sm">
                            <x-ui.input name="template_code" label="Mẫu số" value="1/001" required />
                            <x-ui.input name="series_code" label="Ký hiệu" value="C26MEN" required />
                            <x-ui.input name="start_number" type="number" min="1" label="Số bắt đầu" placeholder="Ví dụ: 1" required />
                            <x-ui.input name="end_number" type="number" min="1" label="Số kết thúc" placeholder="Ví dụ: 1000" required />
                        </div>
                        <x-ui.alert type="info">Dải số này phải duy nhất trên hệ thống và không được chồng lấn với các dải số đã tồn tại của chi nhánh khác.</x-ui.alert>
                        <div class="flex gap-sm">
                            <x-ui.button type="submit" icon="save" class="flex-1">Lưu cấu hình</x-ui.button>
                            <x-ui.button type="reset" variant="secondary">Hủy</x-ui.button>
                        </div>
                    </form>
                @endif
            </div>
        @endcan
    </div>
</x-app-layout>
