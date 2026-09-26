{{--
    Nhập học phí từ Excel — 3 bước theo mockup "Nhập danh sách hàng loạt": Tải file → Xem trước → Kết quả.
    Mở từ nút "Nhập Excel" (Danh sách thu phí) → modal (htmx): redirect sau mỗi bước về tuition.import được trình duyệt đi theo
    (giữ HX-Request) nên cả 3 bước hiện trong cùng modal (bước 2–3 nới rộng 4xl). Mở thẳng URL → trang đầy đủ như cũ.
--}}
@php
    $step = $result ? 3 : ($preview ? 2 : 1);
    $validCount = $preview ? collect($preview['rows'])->filter(fn ($r) => empty($r['errors']))->count() : 0;
    $errorCount = $preview ? count($preview['rows']) - $validCount : 0;
@endphp
@if ($asModal)
    <x-ui.modal-frame title="Nhập học phí hàng loạt" cancel="Đóng" :size="$step > 1 ? '4xl' : null"
                      description="Nhập hồ sơ học phí và các khoản đã đóng từ Excel / CSV. Khoản đã đóng tạo phiếu thu chờ Kế toán duyệt.">
        @if (session('status'))
            <x-ui.alert type="success" class="mb-md">{{ session('status') }}</x-ui.alert>
        @endif
        <div class="space-y-lg">
            @include('tuition.import._steps')
        </div>
        <x-slot:footer>
            @if ($step === 1)
                <x-ui.button variant="secondary" icon="download" :href="route('tuition.import.template')" hx-boost="false">Tải file mẫu (.xlsx)</x-ui.button>
                <x-ui.button type="submit" form="modal-tuition-import-form" icon="preview">Kiểm tra &amp; xem trước</x-ui.button>
            @elseif ($step === 2)
                <x-ui.button variant="secondary" :href="route('tuition.import')">Chọn file khác</x-ui.button>
                <x-ui.button type="submit" form="modal-tuition-import-confirm" icon="cloud_done" :disabled="$validCount === 0">Nhập {{ $validCount }} dòng hợp lệ</x-ui.button>
            @else
                <x-ui.button variant="secondary" icon="upload_file" :href="route('tuition.import')">Nhập file khác</x-ui.button>
                @if ($result['receipts'] > 0)
                    <x-ui.button variant="secondary" icon="fact_check" :href="route('tuition.receipts.approve', ['status' => 'pending'])" hx-boost="false">Duyệt phiếu thu</x-ui.button>
                @endif
                <x-ui.button icon="list" :href="route('tuition.students')" hx-boost="false">Danh sách thu phí</x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.modal-frame>
@else
    <x-app-layout title="Nhập học phí từ Excel">
        <x-ui.page-header title="Nhập học phí hàng loạt" description="Nhập hồ sơ học phí và các khoản đã đóng từ file Excel (.xlsx) hoặc CSV. Khoản đã đóng tạo phiếu thu chờ Kế toán duyệt.">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="download" :href="route('tuition.import.template')">Tải file mẫu (.xlsx)</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <div class="mx-auto max-w-5xl space-y-lg">
            @include('tuition.import._steps')
        </div>
    </x-app-layout>
@endif
