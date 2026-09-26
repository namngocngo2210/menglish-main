{{-- Mockup: ui-full-tinh-nang-menglish/hoc-phi-va-hoa-don-ui-mockup/lap-phieu-thu-hoc-phi
     Mở từ dòng học viên / khoản học phí (tuition_id, student_id có sẵn) → modal 4xl (htmx); "Lập phiếu thu mới" (lập tự do)
     và mở thẳng URL → trang riêng. Lưu xong trong modal: đóng + toast + "tuition-receipts-changed" làm mới danh sách. --}}
@php
    $tuitionsJson = $tuitions->map(function($t) {
        return [
            'id' => $t->id,
            'student_id' => $t->student_id,
            'student_name' => $t->student?->name ?? 'Học viên',
            'student_code' => $t->student?->code ?? 'HV',
            'student_phone' => $t->student?->phone ?? '',
            'student_parent_name' => $t->student?->parent_name ?? $t->student?->name ?? '',
            'student_parent_phone' => $t->student?->parent_phone ?? $t->student?->phone ?? '',
            'class_name' => $t->classModel?->name ?? 'Chưa xếp lớp',
            'branch_name' => $t->student?->branch?->name ?? 'Trụ sở chính',
            'total_amount' => (float)($t->total_amount ?? 0),
            'discount_amount' => (float)($t->discount_amount ?? 0),
            'other_fees' => (float)($t->other_fees ?? 0),
            'paid_amount' => (float)($t->paid_amount ?? 0),
            'debt_amount' => (float)($t->debt_amount ?? 0),
            'final_amount' => (float)($t->final_amount ?? 0),
            // Số buổi thật (khóa học / lịch lớp + điểm danh); không đủ dữ liệu -> null, view ẩn khối số buổi.
            'total_sessions' => $tuitionMeta[$t->id]['sessions']['total'] ?? null,
            'attended_sessions' => $tuitionMeta[$t->id]['sessions']['attended'] ?? null,
            'remaining_sessions' => $tuitionMeta[$t->id]['sessions']['remaining'] ?? null,
            'is_deferred' => $t->student?->status === 'deferred',
            'bank' => $tuitionMeta[$t->id]['bank'] ?? null,
            'fee_items' => collect($t->fee_items ?? [])->map(fn ($i) => ['name' => (string) ($i['name'] ?? 'Khoản thu khác'), 'amount' => (float) ($i['amount'] ?? 0)])->values(),
            // Học viên đã chuyển sang lớp khác so với lớp của khoản học phí → banner "Học viên vừa chuyển lớp mới".
            'class_changed' => $t->class_id && $t->student?->current_class_id && (int) $t->class_id !== (int) $t->student->current_class_id,
            'current_class_name' => $t->student?->currentClass?->name,
            'receipt_count' => $t->receipts ? $t->receipts->count() : 0,
        ];
    });

    $studentsJson = $students->map(function($s) {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'code' => $s->code,
            'phone' => $s->phone ?? '',
            'parent_name' => $s->parent_name ?? $s->name,
            'parent_phone' => $s->parent_phone ?? $s->phone ?? '',
            'class_name' => $s->currentClass?->name ?? 'Chưa xếp lớp',
            'branch_name' => $s->branch?->name ?? 'Trụ sở chính',
            'status_label' => $s->status_label,
        ];
    });

    $editingJson = $editingReceipt ? [
        'id' => $editingReceipt->id,
        'discount_amount' => (float) $editingReceipt->discount_amount,
        'surcharge_amount' => (float) $editingReceipt->surcharge_amount,
        'surcharge_reason' => $editingReceipt->surcharge_reason,
        'tuition_amount' => $editingReceipt->tuitionPortion(),
        'payment_method' => $editingReceipt->payment_method === 'vietqr' ? 'transfer' : $editingReceipt->payment_method,
        'transaction_code' => $editingReceipt->transaction_code,
        'payer_name' => $editingReceipt->payer_name,
        'payer_phone' => $editingReceipt->payer_phone,
        'proof_image' => $editingReceipt->proof_image,
    ] : null;
    $defaultBankJson = $defaultBank ? [
        'bank_code' => $defaultBank->bank_code,
        'bank_name' => $defaultBank->bank_name,
        'account_number' => $defaultBank->account_number,
        'account_holder' => $defaultBank->account_holder,
        'scope' => 'Tài khoản mặc định hệ thống',
    ] : null;

    if ($editingReceipt) {
        $initialTuitionId = $editingReceipt->student_tuition_id ?? '';
        $initialStudentId = $editingReceipt->student_id ?? $editingReceipt->tuition?->student_id ?? '';
    } else {
        $initialTuitionId = $selectedTuition?->id ?? ($tuitions->first()?->id ?? '');
        $initialStudentId = $selectedStudent?->id ?? ($selectedTuition?->student_id ?? ($students->first()?->id ?? ''));
    }
    // Trạng thái Alpine của form (resources/js/modules/receipt-form.js) — gắn vào phần tử bọc cả form lẫn nút gửi.
    $receiptState = 'createReceiptManager('.implode(', ', array_map(fn ($v) => \Illuminate\Support\Js::from($v), [
        $tuitionsJson, $studentsJson, (string) $initialTuitionId, (string) $initialStudentId, $defaultBankJson, $editingJson,
    ])).')';
@endphp
@if ($asModal)
    <x-ui.modal-frame :title="$editingReceipt ? 'Sửa phiếu thu học phí · '.$nextReceiptNumber : 'Lập phiếu thu học phí · '.$nextReceiptNumber"
                      description="Lập, đối soát thanh toán và gửi duyệt phiếu thu học phí / phụ thu." x-data="{{ $receiptState }}">
        @include('tuition.receipts._form')
        <x-slot:footer>
            <x-ui.button variant="secondary" type="submit" form="modal-receipt-form" name="submit_action" value="draft" icon="drafts">Lưu nháp</x-ui.button>
            <x-ui.button type="submit" form="modal-receipt-form" name="submit_action" value="submit" icon="save" x-bind:disabled="!isValidReceipt">
                {{ $editingReceipt ? 'Lưu & Gửi duyệt lại' : 'Lưu phiếu thu & Gửi duyệt' }} (<span class="font-code" x-text="formatVND(totalAmount)"></span>)
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
<x-app-layout :title="$editingReceipt ? 'Sửa phiếu thu học phí' : 'Lập phiếu thu học phí'" hide-errors>
    <x-ui.page-header :title="$editingReceipt ? 'Sửa phiếu thu học phí' : 'Lập phiếu thu học phí'"
                      description="Quy trình lập, đối soát thanh toán và xuất hóa đơn/biên lai học viên">
        <x-slot:breadcrumbs>
            <a href="{{ route('tuition.students') }}" class="hover:text-primary">Danh sách thu phí</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>{{ $editingReceipt ? 'Sửa phiếu thu' : 'Lập phiếu thu' }}</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @if ($editingReceipt)
                <x-ui.badge :color="$editingReceipt->status === 'rejected' ? 'error' : 'warning'">{{ $editingReceipt->status_label }}</x-ui.badge>
            @else
                <x-ui.badge color="warning">Bản nháp</x-ui.badge>
            @endif
            <span class="rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small">
                <span class="font-label text-label uppercase text-on-surface-variant">Mã phiếu:</span>
                <span class="ml-xs font-code text-code text-primary">{{ $nextReceiptNumber }}</span>
            </span>
        </x-slot:actions>
    </x-ui.page-header>

    @include('tuition.partials.errors')

    <div class="max-w-5xl mx-auto pb-28" x-data="{{ $receiptState }}">
        @include('tuition.receipts._form')
    </div>
</x-app-layout>
@endif
