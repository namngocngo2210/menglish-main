{{--
    Nhập học phí từ Excel — stepper + nội dung 3 bước (Tải file → Xem trước → Kết quả). Dùng chung cho trang đầy đủ (tuition/import)
    và modal ($asModal: nút hành động nằm ở footer x-ui.modal-frame, form có id tiền tố "modal-").
    Biến: $step, $branches, $preview, $result, $validCount, $errorCount, $asModal.
--}}
{{-- Stepper --}}
<ol class="flex items-center justify-center gap-md rounded-xl bg-surface-container-low p-md">
    @foreach ([1 => 'Tải file', 2 => 'Xem trước', 3 => 'Kết quả'] as $n => $label)
        <li class="flex items-center gap-sm">
            <span @class([
                'flex h-8 w-8 items-center justify-center rounded-full border-2 font-body-medium',
                'border-primary-container text-primary' => $n === $step,
                'border-tertiary bg-tertiary text-white' => $n < $step,
                'border-outline-variant text-on-surface-variant' => $n > $step,
            ])>{{ $n < $step ? '✓' : $n }}</span>
            <span @class(['font-body-medium', 'text-primary' => $n === $step, 'text-on-surface-variant' => $n !== $step])>{{ $label }}</span>
            @if ($n < 3)<span class="hidden h-px w-16 bg-outline-variant sm:block" aria-hidden="true"></span>@endif
        </li>
    @endforeach
</ol>

@if ($step === 1)
    <form id="{{ $asModal ? 'modal-' : '' }}tuition-import-form" method="POST" action="{{ route('tuition.import.store') }}" enctype="multipart/form-data"
          @class(['space-y-md', 'rounded-xl border border-outline-variant bg-surface-container-lowest p-lg' => ! $asModal])
          x-data="{ fileName: '' }">
        @csrf
        <x-ui.select name="branch_id" :id="$asModal ? 'modal-tuition-import-branch' : null" label="Chọn chi nhánh" :options="$branches->pluck('name', 'id')" placeholder="-- Vui lòng chọn chi nhánh --" required />

        <label class="flex cursor-pointer flex-col items-center justify-center gap-sm rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low p-xl text-center hover:border-primary-container"
               @dragover.prevent @drop.prevent="$refs.file.files = $event.dataTransfer.files; fileName = $event.dataTransfer.files[0]?.name || ''">
            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-primary-fixed text-primary">
                <span class="material-symbols-outlined text-[32px]" aria-hidden="true">upload_file</span>
            </span>
            <span class="font-h3 text-h3 text-on-surface">Kéo thả file vào đây hoặc nhấn để chọn</span>
            <span class="font-body-small text-body-small text-on-surface-variant">Hỗ trợ .xlsx, .xls, .csv (tối đa 10MB, 1.000 dòng)</span>
            <input type="file" name="excel_file" x-ref="file" accept=".xlsx,.xls,.csv" class="sr-only" @change="fileName = $event.target.files[0]?.name || ''">
            <span x-show="fileName" x-cloak class="rounded-lg bg-tertiary-fixed/40 px-sm py-xs font-body-small text-body-small text-on-tertiary-fixed-variant" x-text="'Đã chọn: ' + fileName"></span>
        </label>

        <x-ui.alert type="info" title="Cột trong file mẫu">
            {{ implode(', ', \App\Services\TuitionImportService::TEMPLATE_HEADINGS) }}.
            <ul class="mt-xs list-disc space-y-0.5 pl-md">
                <li>Học viên chưa có hồ sơ học phí: bắt buộc "Học phí niêm yết" và "Hạn đóng" (dd/mm/yyyy).</li>
                <li>Học viên đã có hồ sơ: chỉ nhập "Số tiền đã đóng" — không ghi đè giá trị hợp đồng.</li>
                <li>Hình thức: <code>tien_mat</code>, <code>chuyen_khoan</code> (bắt buộc mã giao dịch, không trùng), <code>pos</code>.</li>
            </ul>
        </x-ui.alert>

        @error('excel_file')
            <p class="font-body-small text-body-small text-error" role="alert">{{ $message }}</p>
        @enderror

        @unless ($asModal)
            <div class="flex justify-end gap-sm">
                <x-ui.button variant="secondary" :href="route('tuition.students')">Hủy</x-ui.button>
                <x-ui.button type="submit" icon="preview">Kiểm tra &amp; xem trước</x-ui.button>
            </div>
        @endunless
    </form>
@elseif ($step === 2)
    <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
        <x-ui.stat-card label="Tổng số dòng" :value="count($preview['rows'])" icon="table_rows" />
        <x-ui.stat-card label="Dòng hợp lệ" :value="$validCount" tone="success" icon="check_circle" />
        <x-ui.stat-card label="Dòng lỗi (bỏ qua)" :value="$errorCount" tone="error" icon="error" />
    </div>

    <x-ui.data-table min-width="980px">
        <x-slot:header>
            <div>
                <h2 class="font-h3 text-h3">{{ $preview['file_name'] }}</h2>
                <p class="font-body-small text-body-small text-on-surface-variant">Chi nhánh: {{ $preview['branch_name'] }}</p>
            </div>
        </x-slot:header>
        <table>
            <thead>
                <tr>
                    <th>Dòng</th>
                    <th>Học viên</th>
                    <th>Hồ sơ học phí</th>
                    <th class="text-right">Phải thu</th>
                    <th>Hạn đóng</th>
                    <th class="text-right">Đã đóng</th>
                    <th>Hình thức / Mã GD</th>
                    <th>Kết quả kiểm tra</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($preview['rows'] as $row)
                    <tr @class(['bg-error-container/30' => ! empty($row['errors'])])>
                        <td class="font-code">{{ $row['line'] }}</td>
                        <td>
                            <div class="font-body-medium">{{ $row['student_name'] ?? '—' }}</div>
                            <div class="font-caption text-caption text-on-surface-variant">{{ $row['student_code'] }}</div>
                        </td>
                        <td>
                            @if ($row['creates_tuition'])
                                <x-ui.badge color="info">Tạo mới</x-ui.badge>
                                @if ($row['class_code'])<div class="font-caption text-caption">Lớp {{ $row['class_code'] }}</div>@endif
                            @else
                                <span class="text-on-surface-variant">Đã có</span>
                            @endif
                        </td>
                        <td><x-ui.money :value="$row['final_amount']" /></td>
                        <td class="font-code">{{ $row['due_date'] ? \Illuminate\Support\Carbon::parse($row['due_date'])->format('d/m/Y') : '—' }}</td>
                        <td><x-ui.money :value="$row['paid_amount'] ?: null" /></td>
                        <td class="font-caption text-caption">
                            {{ \App\Services\TuitionImportService::METHOD_LABELS[$row['payment_method']] ?? '—' }}
                            @if ($row['transaction_code'])<div class="font-code">{{ $row['transaction_code'] }}</div>@endif
                        </td>
                        <td>
                            @if (empty($row['errors']))
                                <x-ui.badge color="success">Hợp lệ</x-ui.badge>
                            @else
                                <ul class="list-disc space-y-0.5 pl-md font-caption text-caption text-error">
                                    @foreach ($row['errors'] as $error)<li>{{ $error }}</li>@endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.data-table>

    <form id="{{ $asModal ? 'modal-' : '' }}tuition-import-confirm" method="POST" action="{{ route('tuition.import.confirm') }}" class="flex flex-wrap items-center justify-between gap-sm">
        @csrf
        <input type="hidden" name="token" value="{{ $preview['token'] }}">
        <p class="font-body-small text-body-small text-on-surface-variant">Chỉ {{ $validCount }} dòng hợp lệ được nhập; dòng lỗi bị bỏ qua — sửa file rồi tải lên lại.</p>
        @unless ($asModal)
            <div class="flex gap-sm">
                <x-ui.button variant="secondary" :href="route('tuition.import')">Chọn file khác</x-ui.button>
                <x-ui.button type="submit" icon="cloud_done" :disabled="$validCount === 0">Nhập {{ $validCount }} dòng hợp lệ</x-ui.button>
            </div>
        @endunless
    </form>
@else
    <div @class(['space-y-md', 'rounded-xl border border-outline-variant bg-surface-container-lowest p-lg' => ! $asModal])>
        <h2 class="font-h3 text-h3">Kết quả nhập file {{ $result['file_name'] ?? '' }}</h2>
        <div class="grid grid-cols-1 gap-md sm:grid-cols-4">
            <x-ui.stat-card label="Hồ sơ học phí tạo mới" :value="$result['tuitions']" tone="success" />
            <x-ui.stat-card label="Phiếu thu chờ duyệt" :value="$result['receipts']" tone="primary" />
            <x-ui.stat-card label="Dòng lỗi bỏ qua" :value="$result['skipped'] ?? 0" tone="warning" />
            <x-ui.stat-card label="Lỗi khi ghi" :value="count($result['failed'])" tone="error" />
        </div>
        @if (! empty($result['failed']))
            <x-ui.alert type="error" title="Các dòng không ghi được">
                <ul class="list-disc pl-md">
                    @foreach ($result['failed'] as $failed)<li>Dòng {{ $failed['line'] }}: {{ $failed['error'] }}</li>@endforeach
                </ul>
            </x-ui.alert>
        @endif
        @unless ($asModal)
            <div class="flex flex-wrap justify-end gap-sm">
                <x-ui.button variant="secondary" icon="upload_file" :href="route('tuition.import')">Nhập file khác</x-ui.button>
                @if ($result['receipts'] > 0)
                    <x-ui.button variant="secondary" icon="fact_check" :href="route('tuition.receipts.approve', ['status' => 'pending'])">Duyệt phiếu thu</x-ui.button>
                @endif
                <x-ui.button icon="list" :href="route('tuition.students')">Danh sách thu phí</x-ui.button>
            </div>
        @endunless
    </div>
@endif
