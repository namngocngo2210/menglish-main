{{-- Form Lập / Sửa phiếu thu — dùng chung trang (tuition/create-receipt) và modal 4xl ($asModal: id tiền tố "modal-", nút ở footer modal).
     Phần tử cha phải gắn x-data = $receiptState (Alpine.data createReceiptManager — resources/js/modules/receipt-form.js)
     để nút gửi ở footer modal cùng phạm vi Alpine với form.
     Biến: như TuitionController::receiptForm + $tuitionsJson/$studentsJson… (tính ở tuition/create-receipt) + $asModal. --}}
@php $px = $asModal ? 'modal-' : ''; @endphp
<form action="{{ $editingReceipt ? route('tuition.receipts.update', $editingReceipt->id) : route('tuition.receipts.store') }}" method="POST" enctype="multipart/form-data"
      id="{{ $asModal ? 'modal-receipt-form' : 'receiptForm' }}" class="space-y-6">
    @csrf
    @if ($editingReceipt)
        @method('PUT')
        <input type="hidden" name="remove_proof" :value="proofRemoved ? 1 : 0">
    @endif

    {{-- Banners thông báo --}}
    @if (isset($errors) && $errors->any())
        <x-ui.alert type="error" title="Vui lòng kiểm tra các thông tin sau:">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    @if ($recentRejection)
        <x-ui.alert type="error" title="Lý do từ chối gần nhất (Phiếu: {{ $recentRejection->receipt_number }})">
            <p class="leading-relaxed">{{ $recentRejection->rejection_reason }}</p>
            @if (! $editingReceipt && in_array($recentRejection->status, \App\Models\TuitionReceipt::EDITABLE_STATUSES, true)
                && ((int) $recentRejection->creator_id === (int) auth()->id() || auth()->user()?->isSuperAdmin()))
                <a href="{{ route('tuition.receipts.edit', $recentRejection->id) }}" class="mt-1.5 inline-flex items-center gap-1 text-xs font-bold text-error underline">
                    <span class="material-symbols-outlined text-sm">edit</span> Sửa phiếu bị trả về &amp; gửi duyệt lại
                </a>
            @endif
        </x-ui.alert>
    @endif

    <x-ui.alert type="info" title="Học viên vừa chuyển lớp mới" x-show="currentTuition?.class_changed && !skipTuition" x-cloak>
        Khoản học phí này thuộc lớp <strong x-text="currentTuition?.class_name"></strong>, học viên đang học lớp <strong x-text="currentTuition?.current_class_name"></strong>. Hệ thống ghi nhận thay đổi lộ trình — chỉ cần thu phần còn thiếu của khoản học phí (công nợ hiện tại).
    </x-ui.alert>

    {{-- Khối 1: Chọn Học viên & Hồ sơ Học phí --}}
    <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm overflow-hidden">
        {{-- Chọn học viên & Hồ sơ --}}
        <div class="p-4 md:p-5 border-b border-surface-container-highest bg-surface-container-low/50">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-ui.field label="1. Chọn Hồ sơ Học phí đến hạn" name="student_tuition_id" :for="$px.'receipt_student_tuition_id'" required>
                    <x-ui.select name="student_tuition_id" :id="$px.'receipt_student_tuition_id'" x-model="selectedTuitionId" x-on:change="onTuitionChange()" :disabled="(bool) $editingReceipt"
                                 class="font-bold" placeholder="-- Thu riêng phụ thu (Không gắn hồ sơ học phí) --">
                        <template x-for="t in tuitions" :key="t.id">
                            <option :value="t.id" x-text="t.student_name + ' (' + t.student_code + ') - ' + t.class_name + ' · Nợ: ' + formatVND(t.debt_amount)"></option>
                        </template>
                    </x-ui.select>
                </x-ui.field>

                <x-ui.select name="student_id" :id="$px.'receipt_student_id'" label="Học viên được ghi nhận" required x-model="selectedStudentId" x-on:change="onStudentChange()" :disabled="(bool) $editingReceipt">
                    <template x-for="s in students" :key="s.id">
                        <option :value="s.id" x-text="s.name + ' (' + s.code + ') · ' + s.class_name + ' (' + s.branch_name + ')'"></option>
                    </template>
                </x-ui.select>
            </div>
        </div>

        {{-- Thẻ tóm tắt thông tin học viên --}}
        <div class="p-5 border-b border-surface-container-highest bg-surface-container-lowest">
            <div class="flex items-center gap-4 flex-wrap sm:flex-nowrap">
                <div class="w-14 h-14 rounded-2xl bg-primary-container/10 text-primary flex items-center justify-center shrink-0 shadow-xs">
                    <span class="material-symbols-outlined text-3xl">person</span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 flex-grow text-xs">
                    <div>
                        <span class="text-on-surface-variant/70 font-semibold uppercase text-[10px] block">Học viên</span>
                        <span class="text-sm font-bold text-on-surface" x-text="currentStudent?.name || '—'"></span>
                    </div>
                    <div>
                        <span class="text-on-surface-variant/70 font-semibold uppercase text-[10px] block">Mã học viên</span>
                        <span class="font-code font-bold text-primary text-xs" x-text="currentStudent?.code || '—'"></span>
                    </div>
                    <div>
                        <span class="text-on-surface-variant/70 font-semibold uppercase text-[10px] block">Lớp học hiện tại</span>
                        <span class="font-medium text-on-surface text-xs" x-text="currentStudent?.class_name || '—'"></span>
                    </div>
                    <div>
                        <span class="text-on-surface-variant/70 font-semibold uppercase text-[10px] block">Trạng thái</span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-secondary/10 text-secondary border border-secondary/30" x-text="currentStudent?.status_label || '—'"></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Thanh tùy chọn: Khoản học phí đến hạn --}}
        <div class="p-4 px-5 bg-surface-container-low/70 border-b border-surface-container-highest/80 flex flex-col md:flex-row items-start md:items-center justify-between gap-3">
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex items-center gap-1.5 text-xs font-bold text-on-surface">
                    <span class="material-symbols-outlined text-primary text-base">event_available</span>
                    <span>Khoản học phí đến hạn:</span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-primary-container/10 text-primary">Tùy chọn</span>
                </div>

                <template x-if="!skipTuition && currentTuition">
                    <div class="bg-surface-container-lowest px-3 py-1 rounded-lg border border-primary-container/60 text-on-surface text-xs flex items-center gap-1.5 shadow-2xs">
                        <span class="material-symbols-outlined text-tertiary text-sm">check_circle</span>
                        <span>Đã chọn: <strong x-text="'Học phí đợt ' + (currentTuition.receipt_count + 1) + ' - ' + currentTuition.class_name"></strong></span>
                    </div>
                </template>

                <template x-if="skipTuition || !currentTuition">
                    <div class="bg-warning-container px-3 py-1 rounded-lg border border-warning/30 text-on-warning-container text-xs flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-warning text-sm">info</span>
                        <span>Đang bỏ qua khoản học phí (Lập phiếu chỉ thu riêng Phụ thu)</span>
                    </div>
                </template>
            </div>

            <div class="flex items-center gap-2">
                <template x-if="!skipTuition && currentTuition">
                    <x-ui.button variant="secondary" size="sm" icon="close" x-on:click="toggleSkipTuition(true)">Bỏ qua khoản học phí (để chỉ thu phụ thu)</x-ui.button>
                </template>
                <template x-if="skipTuition && currentTuition">
                    <x-ui.button variant="secondary" size="sm" icon="add" x-on:click="toggleSkipTuition(false)" class="border-primary-container text-primary">Bật lại khoản học phí</x-ui.button>
                </template>
            </div>
        </div>

        <div class="px-5 py-2 bg-surface-container-lowest border-b border-surface-container-highest flex items-center gap-1.5 text-[11px] text-on-surface-variant italic">
            <span class="material-symbols-outlined text-sm text-primary">lightbulb</span>
            <span>Có thể bỏ qua khoản học phí để lập phiếu chỉ thu riêng phụ thu. Khi bỏ qua, khối thông tin số buổi và bảng kê học phí bên dưới sẽ tự động ẩn.</span>
        </div>

        {{-- Khối số buổi & Bảng kê học phí (Chỉ hiển thị khi KHÔNG bỏ qua) --}}
        <div x-show="!skipTuition && currentTuition" x-transition class="space-y-0">
            {{-- Thống kê 4 ô buổi học --}}
            <div x-show="currentTuition?.total_sessions" class="grid grid-cols-2 md:grid-cols-4 border-b border-surface-container-highest text-center divide-x divide-surface-container-highest text-xs">
                <div class="p-4">
                    <span class="text-on-surface-variant/70 font-semibold uppercase text-[10px] block mb-1">Tổng số buổi</span>
                    <span class="text-lg font-bold text-on-surface" x-text="currentTuition?.total_sessions ?? '—'"></span>
                </div>
                <div class="p-4">
                    <span class="text-on-surface-variant/70 font-semibold uppercase text-[10px] block mb-1">Đã học</span>
                    <span class="text-lg font-bold text-tertiary" x-text="currentTuition?.attended_sessions ?? '—'"></span>
                </div>
                <div class="p-4">
                    <span class="text-on-surface-variant/70 font-semibold uppercase text-[10px] block mb-1">Số buổi còn tồn</span>
                    <span class="text-lg font-bold text-primary" x-text="currentTuition?.remaining_sessions ?? '—'"></span>
                </div>
                <div class="p-4">
                    <span class="text-on-surface-variant/70 font-semibold uppercase text-[10px] block mb-1">Trạng thái học</span>
                    <span class="text-sm font-bold" :class="currentTuition?.is_deferred ? 'text-secondary' : 'text-on-surface-variant'" x-text="currentTuition?.is_deferred ? 'Đang bảo lưu' : (currentStudent?.status_label || '—')"></span>
                </div>
            </div>

            {{-- Bảng kê chi tiết khoản thu học phí --}}
            <div class="p-5 bg-surface-container-low/50">
                <h4 class="text-xs font-bold text-on-surface uppercase tracking-wider mb-3 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-base">receipt_long</span>
                    Bảng kê chi tiết khoản thu học phí
                </h4>

                <div class="space-y-2 text-xs">
                    <div class="flex justify-between py-1.5 border-b border-dashed border-surface-container-highest">
                        <span class="text-on-surface-variant" x-text="'Học phí khóa / lớp ' + (currentTuition?.class_name || '—')"></span>
                        <span class="font-code font-bold text-on-surface" x-text="formatVND(currentTuition?.total_amount || 0)"></span>
                    </div>
                    <template x-for="(item, idx) in (currentTuition?.fee_items || [])" :key="idx">
                        <div class="flex justify-between py-1.5 border-b border-dashed border-surface-container-highest">
                            <span class="text-on-surface-variant" x-text="item.name"></span>
                            <span class="font-code font-bold text-on-surface" x-text="formatVND(item.amount)"></span>
                        </div>
                    </template>
                    <div class="flex justify-between py-1.5 border-b border-dashed border-surface-container-highest" x-show="currentTuition?.other_fees > 0 && !(currentTuition?.fee_items || []).length">
                        <span class="text-on-surface-variant">Phí học liệu &amp; khoản thu khác</span>
                        <span class="font-code font-bold text-on-surface" x-text="formatVND(currentTuition?.other_fees || 0)"></span>
                    </div>
                    <div class="flex justify-between py-1.5 border-b border-dashed border-surface-container-highest" x-show="currentTuition?.discount_amount > 0">
                        <span class="text-on-surface-variant">Ưu đãi trên hợp đồng</span>
                        <span class="font-code font-bold text-tertiary" x-text="'-' + formatVND(currentTuition?.discount_amount || 0)"></span>
                    </div>
                    <div class="flex justify-between py-1.5 border-b border-dashed border-surface-container-highest">
                        <span class="text-on-surface-variant">Đã nộp / Còn nợ</span>
                        <span class="font-code font-bold text-on-surface" x-text="formatVND(currentTuition?.paid_amount || 0) + ' / ' + formatVND(currentTuition?.debt_amount || 0)"></span>
                    </div>
                </div>

                {{-- Giảm trừ & Tổng học phí --}}
                <div class="mt-4 pt-4 border-t border-surface-container-highest flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <x-ui.field label="Số tiền giảm trừ (VNĐ)" name="discount_amount" :for="$px.'discount_amount'" hint="Không vượt tổng trước giảm." class="w-full md:w-80">
                        <div class="relative">
                            <x-ui.input type="number" name="discount_amount" :id="$px.'discount_amount'" x-model.number="discountAmount" x-on:input="recalc()" min="0" x-bind:max="tuitionSubtotal" class="pr-12 font-code font-bold" placeholder="0" />
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-code text-on-surface-variant/70">VNĐ</span>
                        </div>
                    </x-ui.field>

                    <div class="flex flex-col items-end gap-1 w-full md:w-auto text-xs">
                        <div class="flex items-baseline gap-4">
                            <span class="text-on-surface-variant font-medium">Tổng trước giảm:</span>
                            <span class="font-code font-bold text-on-surface" x-text="formatVND(tuitionSubtotal)"></span>
                        </div>
                        <div class="flex items-center gap-3">
                            <label for="{{ $asModal ? 'modal-' : '' }}collectAmount" class="text-on-surface-variant font-medium">Thu đợt này (để trống = thu hết):</label>
                            <input id="{{ $asModal ? 'modal-' : '' }}collectAmount" type="number" min="0" :max="tuitionSubtotal" x-model="collectAmount" class="w-36 h-9 rounded-xl border border-surface-container-highest text-xs font-code font-bold px-2 text-right" placeholder="Toàn bộ" />
                        </div>
                        <div class="flex items-baseline gap-4">
                            <span class="text-primary font-bold uppercase tracking-wider">TỔNG PHẢI THU (HỌC PHÍ):</span>
                            <span class="font-code text-base font-bold text-primary" x-text="formatVND(tuitionAmountAfterDiscount)"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Khối 2: Phụ thu (Phí phát sinh ngoài học phí) - Tùy chọn độc lập --}}
    <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm overflow-hidden">
        <div class="p-4 px-5 bg-surface-container-low/70 border-b border-surface-container-highest/80 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-xl">add_shopping_cart</span>
                <h3 class="text-sm font-bold text-on-surface">Phụ thu (Phí phát sinh ngoài học phí)</h3>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-primary-container/10 text-primary border border-primary-container/30">
                    Tùy chọn độc lập - Chuẩn 11/09/2026
                </span>
            </div>
            <span class="text-[11px] text-on-surface-variant/70 italic">Mới cập nhật</span>
        </div>

        <div class="p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-ui.field label="Số tiền phụ thu (VNĐ)" name="surcharge_amount" :for="$px.'surcharge_amount'">
                    <div class="relative">
                        <x-ui.input type="number" name="surcharge_amount" :id="$px.'surcharge_amount'" x-model.number="surchargeAmount" x-on:input="recalc()" min="0" step="10000" class="pr-12 font-code font-bold" placeholder="Nhập số tiền > 0..." />
                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-code text-on-surface-variant/70">VNĐ</span>
                    </div>

                    {{-- Gợi ý số tiền nhanh --}}
                    <div class="flex flex-wrap items-center gap-1.5 mt-2">
                        <span class="text-[11px] text-on-surface-variant/70">Gợi ý nhanh:</span>
                        <x-ui.button variant="secondary" size="sm" x-on:click="setSurcharge(50000)">50.000đ</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" x-on:click="setSurcharge(100000)">100.000đ</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" x-on:click="setSurcharge(150000)">150.000đ</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" x-on:click="setSurcharge(200000)">200.000đ</x-ui.button>
                    </div>
                </x-ui.field>

                <div class="flex flex-col gap-xs">
                    <label for="{{ $px }}surcharge_reason" class="font-body-small text-body-small text-on-surface-variant">
                        Lý do phụ thu <span class="text-error" x-show="surchargeAmount > 0">*</span>
                    </label>
                    <x-ui.input name="surcharge_reason" :id="$px.'surcharge_reason'" x-model="surchargeReason" placeholder="Ví dụ: Phụ thu giáo trình in ấn bổ sung, đồng phục, thẻ học viên..." />
                    <p class="text-[11px] text-on-surface-variant/70 italic">* Bắt buộc nhập lý do khi có nhập số tiền phụ thu.</p>
                </div>
            </div>

            <x-ui.alert type="info">Khoản phụ thu luôn hoạt động độc lập và không loại trừ lẫn nhau với học phí. Hệ thống cho phép: <strong>Học phí + Phụ thu</strong>, hoặc chỉ thu riêng <strong>Học phí</strong>, hoặc chỉ thu riêng <strong>Phụ thu</strong>.</x-ui.alert>
        </div>
    </div>

    {{-- Khối 3: TỔNG THỰC THU CỦA PHIẾU NÀY (Học phí + Phụ thu) --}}
    <div class="bg-surface-container-lowest p-5 rounded-2xl border-2 border-primary-container/50 shadow-sm">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="space-y-1">
                <span class="text-xs font-bold text-primary uppercase tracking-wider">TỔNG THỰC THU CỦA PHIẾU NÀY</span>
                <div class="flex items-center gap-3 text-xs text-on-surface-variant flex-wrap">
                    <span>Học phí cần thu: <strong class="text-on-surface font-code" x-text="formatVND(tuitionAmountAfterDiscount)"></strong></span>
                    <span class="text-on-surface-variant/70">+</span>
                    <span>Tiền phụ thu: <strong class="text-primary font-code" x-text="'+' + formatVND(surchargeAmount)"></strong></span>
                </div>
                <div>
                    <template x-if="isValidReceipt">
                        <span class="text-[11px] text-tertiary font-medium flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm text-tertiary">check_circle</span>
                            Hợp lệ: Đã có ít nhất 1 nguồn tiền (Chọn học phí HOẶC nhập phụ thu > 0) để Gửi duyệt.
                        </span>
                    </template>
                    <template x-if="!isValidReceipt">
                        <span class="text-[11px] text-error font-medium flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm text-error">warning</span>
                            Chưa hợp lệ: Cần chọn khoản học phí hoặc nhập số tiền phụ thu > 0.
                        </span>
                    </template>
                </div>
            </div>

            <div class="flex items-baseline gap-3 w-full md:w-auto justify-end border-t md:border-t-0 pt-3 md:pt-0 border-surface-container-highest">
                <span class="text-xs font-bold text-on-surface-variant uppercase">TỔNG THỰC THU:</span>
                <span class="text-2xl md:text-3xl font-bold font-code text-primary" x-text="formatVND(totalAmount)"></span>
            </div>
        </div>
    </div>

    {{-- Hidden input for amount and tuition amount --}}
    <input type="hidden" name="amount" :value="totalAmount" />
    <input type="hidden" name="tuition_amount" :value="tuitionAmountAfterDiscount" />

    {{-- Khối 4: 2 Cột: Hình thức thu tiền & Thông tin bổ sung --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        {{-- Cột trái: Hình thức thu tiền --}}
        <div class="bg-surface-container-lowest p-5 rounded-2xl border border-surface-container-highest shadow-sm space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold text-on-surface flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-base">payments</span>
                    Hình thức thu tiền
                </h3>
                <span class="text-xs text-on-surface-variant/70 italic">CM chọn phương thức</span>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <label :class="paymentMethod === 'transfer' ? 'border-primary-container bg-primary-container/10 ring-1 ring-primary-container' : 'border-surface-container-highest hover:bg-surface-container-low'" class="relative flex items-center justify-center p-3.5 border rounded-xl cursor-pointer transition">
                    <input type="radio" name="payment_method" value="transfer" x-model="paymentMethod" class="sr-only" />
                    <div class="flex flex-col items-center">
                        <span class="material-symbols-outlined mb-1" :class="paymentMethod === 'transfer' ? 'text-primary' : 'text-on-surface-variant/70'">account_balance</span>
                        <span class="text-xs font-bold" :class="paymentMethod === 'transfer' ? 'text-primary' : 'text-on-surface-variant'">Chuyển khoản</span>
                    </div>
                </label>

                <label :class="paymentMethod === 'cash' ? 'border-primary-container bg-primary-container/10 ring-1 ring-primary-container' : 'border-surface-container-highest hover:bg-surface-container-low'" class="relative flex items-center justify-center p-3.5 border rounded-xl cursor-pointer transition">
                    <input type="radio" name="payment_method" value="cash" x-model="paymentMethod" class="sr-only" />
                    <div class="flex flex-col items-center">
                        <span class="material-symbols-outlined mb-1" :class="paymentMethod === 'cash' ? 'text-primary' : 'text-on-surface-variant/70'">payments</span>
                        <span class="text-xs font-bold" :class="paymentMethod === 'cash' ? 'text-primary' : 'text-on-surface-variant'">Tiền mặt</span>
                    </div>
                </label>

                <label :class="paymentMethod === 'pos' ? 'border-primary-container bg-primary-container/10 ring-1 ring-primary-container' : 'border-surface-container-highest hover:bg-surface-container-low'" class="relative flex items-center justify-center p-3.5 border rounded-xl cursor-pointer transition">
                    <input type="radio" name="payment_method" value="pos" x-model="paymentMethod" class="sr-only" />
                    <div class="flex flex-col items-center">
                        <span class="material-symbols-outlined mb-1" :class="paymentMethod === 'pos' ? 'text-primary' : 'text-on-surface-variant/70'">credit_card</span>
                        <span class="text-xs font-bold" :class="paymentMethod === 'pos' ? 'text-primary' : 'text-on-surface-variant'">Quẹt thẻ POS</span>
                    </div>
                </label>
            </div>

            {{-- Giao diện Chuyển khoản --}}
            <div x-show="paymentMethod === 'transfer'" x-transition class="space-y-3 pt-2">
                <x-ui.alert type="info" title="Trạng thái đối soát: Tạm thu">
                    Hệ thống tự động ghi nhận &amp; khớp giao dịch khi phụ huynh chuyển khoản đúng cú pháp (Nội dung CK: <strong x-text="transferMemo"></strong>).
                </x-ui.alert>

                {{-- Card tài khoản ngân hàng mặc định --}}
                <div x-show="bank" class="border border-surface-container-highest rounded-xl p-3.5 bg-surface-container-low/50 space-y-3 text-xs">
                    <div class="flex items-center justify-between border-b border-surface-container-highest/80 pb-2">
                        <h5 class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider">Tài khoản ngân hàng nhận học phí</h5>
                        <span class="text-[10px] text-on-surface-variant/70 italic" x-text="bank?.scope"></span>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-4 items-center">
                        <div class="flex-grow space-y-2 w-full">
                            <div class="flex justify-between border-b border-dashed border-surface-container-highest pb-1">
                                <span class="text-on-surface-variant">Ngân hàng</span>
                                <span class="font-bold text-on-surface" x-text="bank?.bank_name"></span>
                            </div>
                            <div class="flex justify-between border-b border-dashed border-surface-container-highest pb-1">
                                <span class="text-on-surface-variant">Số tài khoản</span>
                                <span class="font-code font-bold text-primary" x-text="bank?.account_number"></span>
                            </div>
                            <div class="flex justify-between border-b border-dashed border-surface-container-highest pb-1">
                                <span class="text-on-surface-variant">Chủ tài khoản</span>
                                <span class="font-bold uppercase text-on-surface" x-text="bank?.account_holder"></span>
                            </div>
                            <div class="flex justify-between items-center pt-0.5">
                                <span class="text-on-surface-variant">Nội dung CK</span>
                                <span class="font-code font-bold text-secondary bg-secondary/10 px-2 py-0.5 rounded text-[11px]" x-text="transferMemo"></span>
                            </div>
                            <input type="hidden" name="transfer_memo" :value="transferMemo">
                        </div>

                        {{-- Dynamic VietQR Code --}}
                        <div class="flex flex-col items-center gap-1 shrink-0">
                            <div class="w-28 h-28 bg-surface-container-lowest border-2 border-primary-container/20 p-1 rounded-xl shadow-xs overflow-hidden flex items-center justify-center">
                                <img :src="vietQrUrl" alt="VietQR Thanh toán" class="w-full h-full object-contain" />
                            </div>
                            <span class="text-[10px] text-on-surface-variant/70 italic">Quét VietQR tự điền số tiền</span>
                        </div>
                    </div>

                    <div class="px-2.5 py-1.5 bg-surface-container rounded-lg text-[11px] text-on-surface-variant italic flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xs text-on-surface-variant/70">lock</span>
                        <span>Tài khoản lấy theo hợp đồng / chi nhánh của học viên; CM không đổi được trên màn hình này.</span>
                    </div>
                </div>
                @if (! $defaultBank)
                    <x-ui.alert type="warning" x-show="!bank"><strong>Chưa cấu hình tài khoản ngân hàng</strong> đang hoạt động để nhận học phí. Mã VietQR sẽ không được tạo — vui lòng liên hệ Kế toán/Admin cấu hình tài khoản trước khi hướng dẫn phụ huynh chuyển khoản.</x-ui.alert>
                @endif

                <x-ui.input name="transaction_code" :id="$px.'transaction_code'" label="Mã tham chiếu / Mã giao dịch ngân hàng (nếu có)" x-model="transactionCode" placeholder="Ví dụ: FT232981354789..." class="font-code" />
            </div>

            {{-- Giao diện Tiền mặt --}}
            <div x-show="paymentMethod === 'cash'" x-transition class="space-y-3 pt-2">
                <div class="p-3.5 rounded-xl border border-surface-container-highest bg-surface-container-low">
                    <x-ui.input name="paper_invoice_number" :id="$px.'paper_invoice_number'" label="Số phiếu / Số hóa đơn giấy thu tiền mặt (nếu có)" hint="Áp dụng khi viết biên lai tay." placeholder="Ví dụ: HĐG-0824/PTM-042..." class="font-code font-bold" />
                </div>
            </div>
        </div>

        {{-- Cột phải: Thông tin bổ sung --}}
        <div class="bg-surface-container-lowest p-5 rounded-2xl border border-surface-container-highest shadow-sm space-y-4">
            <h3 class="text-sm font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-primary text-base">description</span>
                Thông tin bổ sung
            </h3>

            {{-- Toggle Hóa đơn VAT --}}
            <div class="flex items-center justify-between p-3.5 bg-surface-container-low/70 rounded-xl border border-surface-container-highest">
                <div class="flex items-center gap-2.5">
                    <span class="material-symbols-outlined text-on-surface-variant text-lg">receipt</span>
                    <div>
                        <span class="text-xs font-bold text-on-surface block">Yêu cầu xuất hóa đơn đỏ (VAT)</span>
                        <span class="text-[11px] text-on-surface-variant/70">Xuất theo thông tin doanh nghiệp/cá nhân</span>
                    </div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="is_vat_invoice" value="1" class="sr-only peer" @checked(old('is_vat_invoice', $editingReceipt?->is_vat_invoice)) />
                    <div class="w-11 h-6 bg-surface-container-high peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-surface-container-lowest after:border-outline-variant after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-container"></div>
                </label>
            </div>

            {{-- Người nộp tiền & SĐT --}}
            <div class="grid grid-cols-2 gap-3">
                <x-ui.input name="payer_name" :id="$px.'payer_name'" label="Người nộp tiền" x-model="payerName" placeholder="Họ và tên người nộp..." />
                <x-ui.input type="tel" name="payer_phone" :id="$px.'payer_phone'" label="Số điện thoại" x-model="payerPhone" placeholder="09xxxxxxxx..." class="font-code" />
            </div>

            {{-- Ghi chú nội bộ --}}
            <x-ui.textarea name="notes" :id="$px.'notes'" label="Ghi chú nội bộ" rows="3" :value="$editingReceipt?->notes" placeholder="Nhập ghi chú quan trọng cho bộ phận kế toán và quản lý lớp..." />
        </div>
    </div>

    {{-- Khối 5: Minh chứng thanh toán (*) --}}
    <div class="bg-surface-container-lowest p-5 rounded-2xl border border-surface-container-highest shadow-sm space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-primary text-base">upload_file</span>
                Minh chứng thanh toán <span class="text-error" x-show="proofRequired">*</span>
            </h3>
            <span class="text-xs font-medium flex items-center gap-1" :class="proofRequired ? 'text-warning' : 'text-on-surface-variant/70'">
                <span class="material-symbols-outlined text-xs" x-text="proofRequired ? 'warning' : 'info'"></span>
                <span x-text="proofRequired ? 'Bắt buộc khi gửi duyệt: ủy nhiệm chi / ảnh chuyển khoản / biên lai POS' : 'Tiền mặt: không bắt buộc minh chứng'"></span>
            </span>
        </div>

        {{-- Drag & Drop Zone --}}
        <div class="border-2 border-dashed border-outline-variant rounded-2xl p-6 flex flex-col items-center justify-center text-center cursor-pointer hover:bg-surface-container-low/50 transition relative" x-on:click="$refs.fileInput.click()">
            <input type="file" name="proof_image" x-ref="fileInput" x-on:change="handleFileSelected($event)" accept="image/*,.pdf" class="hidden" />
            
            <div class="w-14 h-14 bg-primary-container/10 rounded-2xl flex items-center justify-center mb-2.5 text-primary">
                <span class="material-symbols-outlined text-2xl">cloud_upload</span>
            </div>
            <div>
                <p class="text-xs font-bold text-on-surface">Kéo thả hoặc <span class="text-primary underline">chọn tệp</span> để tải lên ủy nhiệm chi/biên lai chuyển khoản</p>
                <p class="text-[11px] text-on-surface-variant/70 mt-1">Hỗ trợ: JPG, PNG, PDF (Tối đa 5MB) - Đảm bảo rõ ràng thông tin giao dịch &amp; mã tham chiếu</p>
            </div>
        </div>

        {{-- Preview file đã chọn --}}
        <template x-if="proofPreviewUrl">
            <div class="space-y-1.5 pt-2">
                <p class="text-[10px] font-bold text-on-surface-variant/70 uppercase tracking-wider">Minh chứng đã đính kèm</p>
                <div class="flex items-center gap-3 p-3 bg-surface-container-low rounded-xl border border-surface-container-highest">
                    <div class="w-16 h-16 rounded-lg bg-surface-container-high overflow-hidden shrink-0 border border-outline-variant relative group">
                        <img :src="proofPreviewUrl" alt="Minh chứng" class="w-full h-full object-cover" x-show="!proofIsPdf" />
                        <span x-show="proofIsPdf" class="w-full h-full flex items-center justify-center font-bold text-xs text-on-surface-variant">PDF</span>
                    </div>
                    <div class="flex-grow text-xs space-y-0.5">
                        <span class="font-bold text-tertiary flex items-center gap-1 text-[11px]">
                            <span class="material-symbols-outlined text-xs">verified</span>
                            Đã tải lên tệp minh chứng
                        </span>
                        <p class="text-on-surface-variant font-code text-[11px]" x-text="proofFileName"></p>
                        <p class="text-[10px] text-on-surface-variant/70" x-text="proofFileSize"></p>
                    </div>
                    <x-ui.button variant="danger-text" icon="delete" x-on:click="clearProof()" title="Xóa tệp" aria-label="Xóa tệp" />
                </div>
            </div>
        </template>
    </div>

    @unless ($asModal)
        {{-- Footer cố định dưới đáy màn hình --}}
        <div class="fixed bottom-0 left-0 right-0 h-20 bg-surface-container-lowest border-t border-surface-container-highest/80 shadow-[0_-4px_12px_rgba(0,0,0,0.06)] px-4 md:px-8 z-40">
            <div class="max-w-5xl mx-auto h-full flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <x-ui.button variant="secondary" :href="$editingReceipt ? route('tuition.receipts.approve', ['selected_id' => $editingReceipt->id]) : route('tuition.students')">Hủy bỏ</x-ui.button>
                    <x-ui.button type="submit" variant="secondary" icon="drafts" name="submit_action" value="draft">Lưu nháp</x-ui.button>
                </div>

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" icon="save" name="submit_action" value="submit" x-bind:disabled="!isValidReceipt" class="px-6 shadow-md">
                        <span>{{ $editingReceipt ? 'Lưu & Gửi duyệt lại' : 'Lưu phiếu thu & Gửi duyệt' }} (<span class="font-code" x-text="formatVND(totalAmount)"></span>)</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endunless
</form>
