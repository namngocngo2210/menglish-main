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
<div class="space-y-md">
    <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
        <x-ui.stat-card label="Tổng số dòng" :value="$rows->count()" icon="table_rows" />
        <x-ui.stat-card label="Hợp lệ — sẽ nhập" :value="$validCount" tone="success" icon="check_circle" />
        <x-ui.stat-card label="Lỗi — bỏ qua" :value="$errorCount" tone="error" icon="error" />
    </div>

    <x-ui.data-table min-width="1000px">
        <x-slot:header>
            <div>
                <h2 class="font-h3 text-h3 text-on-surface">Xem trước: {{ $preview['file_name'] }}</h2>
                <p class="text-caption text-on-surface-variant">Chi nhánh: <strong>{{ $preview['branch_name'] }}</strong> · Sales phụ trách: <strong>{{ $preview['assigned_user_name'] }}</strong></p>
            </div>
            @if ($asModal)
                <form id="modal-crm-import-confirm" method="POST" action="{{ route('crm.import.store') }}">@csrf</form>
                <form id="modal-crm-import-cancel" method="POST" action="{{ route('crm.import.store') }}">@csrf<input type="hidden" name="cancel" value="1"></form>
            @else
                <form method="POST" action="{{ route('crm.import.store') }}" class="flex items-center gap-sm">
                    @csrf
                    <x-ui.button type="submit" name="cancel" value="1" variant="ghost">Hủy</x-ui.button>
                    <x-ui.button type="submit" icon="upload" :disabled="$validCount === 0">Nhập {{ $validCount }} khách hợp lệ</x-ui.button>
                </form>
            @endif
        </x-slot:header>
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
                    <th>Kết quả kiểm tra</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr @class(['bg-error-container/30' => ! empty($row['errors'])])>
                        <td class="font-code">{{ $row['line'] }}</td>
                        <td class="font-semibold">{{ $row['data']['name'] ?? '—' }}</td>
                        <td class="font-code">{{ $row['data']['phone'] ?? '—' }}</td>
                        <td>{{ $row['data']['parent_name'] ?? '' }}<div class="font-code text-caption">{{ $row['data']['parent_phone'] ?? '' }}</div></td>
                        <td>{{ $row['data']['email'] ?? '' }}</td>
                        <td>{{ $row['data']['source'] ?? '' }}</td>
                        <td>{{ $row['data']['course_interest'] ?? '' }}</td>
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
