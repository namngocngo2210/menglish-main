{{--
    Nhập khách từ Excel — bước 2 (xem trước + lỗi từng dòng). Dùng chung cho trang đầy đủ và modal ($asModal).
    Biến: $preview (mảng: file_name, branch_name, assigned_user_name, rows), $asModal (bool, tuỳ chọn).
    Trong modal: 2 form rỗng "modal-crm-import-confirm" / "modal-crm-import-cancel", nút gửi ở footer x-ui.modal-frame.
--}}
@php
    $asModal = $asModal ?? false;
    $rows = collect($preview['rows']);
    $validCount = $rows->filter(fn ($r) => empty($r['errors']))->count();
    $errorCount = $rows->count() - $validCount;
@endphp
<div class="space-y-md" x-data="{ show: 'all' }">
    <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
        <x-ui.stat-card label="Tổng số dòng" :value="$rows->count()" icon="table_rows" />
        <x-ui.stat-card label="Hợp lệ — sẽ nhập" :value="$validCount" tone="success" icon="check_circle" />
        <x-ui.stat-card label="Lỗi — bỏ qua" :value="$errorCount" tone="error" icon="error" />
    </div>

    {{-- Lỗi hiện NGAY khi xem trước (trước khi bấm Nhập), theo thứ tự dòng trong file, để sửa file rồi tải lại. --}}
    @if ($errorCount > 0)
        @php $errorRows = $rows->filter(fn ($r) => ! empty($r['errors']))->sortBy('line')->values(); @endphp
        <div class="rounded-xl border border-error/30 border-l-4 border-l-error bg-error-container/40 p-md" data-import-errors>
            <div class="flex flex-wrap items-start justify-between gap-sm">
                <div>
                    <h3 class="flex items-center gap-xs font-body-base text-body-base font-bold text-error">
                        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">error</span>
                        {{ $errorCount }} dòng lỗi sẽ bị bỏ qua nếu nhập bây giờ
                    </h3>
                    <p class="font-body-small text-body-small text-on-surface-variant">Nên sửa các dòng này trong file Excel rồi chọn "Hủy, chọn file khác" để tải lại, trước khi bấm Nhập.</p>
                </div>
                <x-ui.button variant="secondary" size="sm" icon="download" :href="route('crm.import.errors')" hx-boost="false">Tải các dòng lỗi (Excel)</x-ui.button>
            </div>
            <ul class="mt-sm max-h-64 space-y-1 overflow-y-auto font-body-small text-body-small">
                @foreach ($errorRows as $row)
                    <li class="flex flex-wrap gap-x-sm">
                        <span class="font-code font-semibold text-error">Dòng {{ $row['line'] }}</span>
                        <span class="font-semibold text-on-surface">{{ ($row['data']['name'] ?? null) ?: '(không có tên)' }}@if (filled($row['data']['phone'] ?? null)) · <span class="font-code">{{ $row['data']['phone'] }}</span>@endif</span>
                        <span class="text-on-surface-variant">— {{ implode('; ', $row['errors']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-ui.data-table min-width="1000px">
        <x-slot:header>
            <div>
                <h2 class="font-h3 text-h3 text-on-surface">Xem trước: {{ $preview['file_name'] }}</h2>
                <p class="text-caption text-on-surface-variant">Chi nhánh: <strong>{{ $preview['branch_name'] }}</strong> · Người phụ trách mặc định: <strong>{{ $preview['assigned_user_name'] }}</strong></p>
            </div>
            @if ($asModal)
                <form id="modal-crm-import-confirm" method="POST" action="{{ route('crm.import.store') }}">@csrf</form>
                <form id="modal-crm-import-cancel" method="POST" action="{{ route('crm.import.store') }}">@csrf<input type="hidden" name="cancel" value="1"></form>
            @else
                <form method="POST" action="{{ route('crm.import.store') }}" class="flex items-center gap-sm">
                    @csrf
                    <x-ui.button type="submit" name="cancel" value="1" variant="ghost">Hủy</x-ui.button>
                    <x-ui.button type="submit" icon="upload" :disabled="$validCount === 0">{{ $errorCount > 0 ? "Bỏ qua {$errorCount} dòng lỗi, nhập {$validCount} khách" : "Nhập {$validCount} khách hợp lệ" }}</x-ui.button>
                </form>
            @endif
        </x-slot:header>
        @if ($errorCount > 0)
            <div class="flex flex-wrap gap-xs px-md pt-sm" role="group" aria-label="Lọc dòng xem trước">
                @foreach (['all' => 'Tất cả ('.$rows->count().')', 'error' => 'Dòng lỗi ('.$errorCount.')', 'valid' => 'Hợp lệ ('.$validCount.')'] as $key => $label)
                    <button type="button" @click="show = '{{ $key }}'" :class="show === '{{ $key }}' ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface-variant'"
                            class="rounded-full border px-sm py-1 font-body-small text-body-small font-semibold">{{ $label }}</button>
                @endforeach
            </div>
        @endif
        <table>
            <thead>
                <tr>
                    <th>Dòng</th>
                    <th>Họ tên</th>
                    <th>SĐT</th>
                    <th>Phụ huynh</th>
                    <th>Email</th>
                    <th>Nguồn</th>
                    <th>Khóa quan tâm</th>
                    <th>Người phụ trách</th>
                    <th>Kết quả kiểm tra</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr @class(['!bg-error-container [&>td:first-child]:border-l-4 [&>td:first-child]:border-error' => ! empty($row['errors'])])
                        x-show="show === 'all' || show === '{{ empty($row['errors']) ? 'valid' : 'error' }}'">
                        <td class="font-code">{{ $row['line'] }}</td>
                        <td class="font-semibold">{{ $row['data']['name'] ?? '—' }}</td>
                        <td class="font-code">{{ $row['data']['phone'] ?? '—' }}</td>
                        <td>{{ $row['data']['parent_name'] ?? '' }}<div class="font-code text-caption">{{ $row['data']['parent_phone'] ?? '' }}</div></td>
                        <td>{{ $row['data']['email'] ?? '' }}</td>
                        <td>{{ $row['data']['source'] ?? '' }}</td>
                        <td>{{ $row['data']['course_interest'] ?? '' }}</td>
                        <td>{{ ($row['data']['owner_name'] ?? null) ?: $preview['assigned_user_name'] }}</td>
                        <td>
                            @if (empty($row['errors']))
                                <x-ui.badge color="success">Hợp lệ</x-ui.badge>
                            @else
                                <ul class="list-disc pl-md text-caption text-error">
                                    @foreach ($row['errors'] as $error)<li>{{ $error }}</li>@endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.data-table>
</div>
