<x-app-layout hide-errors>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('tuition.students') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span>{{ $editingReceipt ? 'Sửa phiếu thu học phí' : 'Lập phiếu thu học phí' }}</span>
                    </h1>
                    <p class="text-xs text-gray-500">Quy trình lập, đối soát thanh toán và xuất hóa đơn/biên lai học viên</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border {{ $editingReceipt ? $editingReceipt->status_badge : 'bg-amber-50 text-amber-700 border-amber-200' }}">
                    <span class="material-symbols-outlined text-[14px] mr-1">edit_document</span>
                    {{ $editingReceipt ? $editingReceipt->status_label : 'Phiếu mới' }}
                </span>
                <div class="bg-white px-3 py-1 rounded-xl border border-gray-200 text-xs shadow-xs">
                    <span class="text-gray-400 font-semibold uppercase text-[10px]">Mã phiếu:</span>
                    <span class="font-mono font-bold text-primary ml-1">{{ $nextReceiptNumber }}</span>
                </div>
            </div>
        </div>
    </x-slot>

    @include('tuition.partials.errors')

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
                'fee_items' => $t->fee_items ?? [],
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
    @endphp

    <div class="max-w-5xl mx-auto pb-28" x-data="createReceiptManager(@js($tuitionsJson), @js($studentsJson), @js((string) $initialTuitionId), @js((string) $initialStudentId), @js($defaultBankJson), @js($editingJson))">
        <form action="{{ $editingReceipt ? route('tuition.receipts.update', $editingReceipt->id) : route('tuition.receipts.store') }}" method="POST" enctype="multipart/form-data" id="receiptForm" class="space-y-6">
            @csrf
            @if ($editingReceipt)
                @method('PUT')
                <input type="hidden" name="remove_proof" :value="proofRemoved ? 1 : 0">
            @endif

            <!-- Banners thông báo -->
            @if (isset($errors) && $errors->any())
                <div class="bg-rose-50 border-l-4 border-rose-500 p-4 rounded-r-xl shadow-xs text-xs text-rose-800 space-y-1">
                    <div class="font-bold flex items-center gap-1.5 text-rose-900 text-sm">
                        <span class="material-symbols-outlined text-rose-600 text-base">error</span>
                        Vui lòng kiểm tra các thông tin sau:
                    </div>
                    <ul class="list-disc list-inside space-y-0.5 pl-5">
                        @foreach ($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($recentRejection)
                <div class="bg-rose-50 border-l-4 border-rose-500 p-4 rounded-r-xl shadow-xs flex items-start gap-3">
                    <span class="material-symbols-outlined text-rose-600 text-xl shrink-0 mt-0.5">error</span>
                    <div>
                        <h4 class="text-xs font-bold text-rose-900 uppercase">Lý do từ chối gần nhất (Phiếu: {{ $recentRejection->receipt_number }})</h4>
                        <p class="text-xs text-rose-800 mt-0.5 leading-relaxed">{{ $recentRejection->rejection_reason }}</p>
                        @if (! $editingReceipt && in_array($recentRejection->status, \App\Models\TuitionReceipt::EDITABLE_STATUSES, true)
                            && ((int) $recentRejection->creator_id === (int) auth()->id() || auth()->user()?->hasRole('admin')))
                            <a href="{{ route('tuition.receipts.edit', $recentRejection->id) }}" class="mt-1.5 inline-flex items-center gap-1 text-xs font-bold text-rose-700 underline">
                                <span class="material-symbols-outlined text-sm">edit</span> Sửa phiếu bị trả về &amp; gửi duyệt lại
                            </a>
                        @endif
                    </div>
                </div>
            @endif

            <div class="bg-blue-50/70 border-l-4 border-blue-500 p-4 rounded-r-xl shadow-xs flex items-start gap-3">
                <span class="material-symbols-outlined text-blue-600 text-xl shrink-0 mt-0.5">info</span>
                <div class="text-xs text-blue-900 space-y-0.5">
                    <h4 class="font-bold">Quy định lập phiếu thu học phí &amp; phụ thu (Chuẩn 11/09/2026)</h4>
                    <p class="text-blue-800">Khoản phụ thu luôn hoạt động độc lập và không loại trừ lẫn nhau với học phí. Hệ thống cho phép: <strong>Học phí + Phụ thu</strong>, hoặc chỉ thu riêng <strong>Học phí</strong>, hoặc chỉ thu riêng <strong>Phụ thu</strong>.</p>
                </div>
            </div>

            <!-- Khối 1: Chọn Học viên & Hồ sơ Học phí -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <!-- Chọn học viên & Hồ sơ -->
                <div class="p-4 md:p-5 border-b border-slate-100 bg-slate-50/50">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                1. Chọn Hồ sơ Học phí đến hạn <span class="text-rose-500">*</span>
                            </label>
                            <select name="student_tuition_id" x-model="selectedTuitionId" @change="onTuitionChange()" @disabled($editingReceipt) class="w-full text-xs font-bold rounded-xl border-slate-200 focus:border-primary-container focus:ring-primary-container/20 text-slate-900 py-2.5 px-3">
                                <option value="">-- Thu riêng phụ thu (Không gắn hồ sơ học phí) --</option>
                                <template x-for="t in tuitions" :key="t.id">
                                    <option :value="t.id" x-text="t.student_name + ' (' + t.student_code + ') - ' + t.class_name + ' · Nợ: ' + formatVND(t.debt_amount)"></option>
                                </template>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Học viên được ghi nhận <span class="text-rose-500">*</span>
                            </label>
                            <select name="student_id" x-model="selectedStudentId" @change="onStudentChange()" required @disabled($editingReceipt) class="w-full text-xs font-medium rounded-xl border-slate-200 focus:border-primary-container focus:ring-primary-container/20 text-slate-800 py-2.5 px-3">
                                <template x-for="s in students" :key="s.id">
                                    <option :value="s.id" x-text="s.name + ' (' + s.code + ') · ' + s.class_name + ' (' + s.branch_name + ')'"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Thẻ tóm tắt thông tin học viên -->
                <div class="p-5 border-b border-slate-100 bg-white">
                    <div class="flex items-center gap-4 flex-wrap sm:flex-nowrap">
                        <div class="w-14 h-14 rounded-2xl bg-orange-100 text-primary flex items-center justify-center shrink-0 shadow-xs">
                            <span class="material-symbols-outlined text-3xl">person</span>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 flex-grow text-xs">
                            <div>
                                <span class="text-slate-400 font-semibold uppercase text-[10px] block">Học viên</span>
                                <span class="text-sm font-bold text-slate-900" x-text="currentStudent?.name || '—'"></span>
                            </div>
                            <div>
                                <span class="text-slate-400 font-semibold uppercase text-[10px] block">Mã học viên</span>
                                <span class="font-mono font-bold text-primary text-xs" x-text="currentStudent?.code || '—'"></span>
                            </div>
                            <div>
                                <span class="text-slate-400 font-semibold uppercase text-[10px] block">Lớp học hiện tại</span>
                                <span class="font-medium text-slate-800 text-xs" x-text="currentStudent?.class_name || '—'"></span>
                            </div>
                            <div>
                                <span class="text-slate-400 font-semibold uppercase text-[10px] block">Trạng thái</span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-pink-50 text-pink-600 border border-pink-200" x-text="currentStudent?.status_label || '—'"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Thanh tùy chọn: Khoản học phí đến hạn -->
                <div class="p-4 px-5 bg-slate-50/70 border-b border-slate-200/80 flex flex-col md:flex-row items-start md:items-center justify-between gap-3">
                    <div class="flex items-center gap-3 flex-wrap">
                        <div class="flex items-center gap-1.5 text-xs font-bold text-slate-800">
                            <span class="material-symbols-outlined text-primary text-base">event_available</span>
                            <span>Khoản học phí đến hạn:</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-primary">Tùy chọn</span>
                        </div>

                        <template x-if="!skipTuition && currentTuition">
                            <div class="bg-white px-3 py-1 rounded-lg border border-primary-container/60 text-slate-800 text-xs flex items-center gap-1.5 shadow-2xs">
                                <span class="material-symbols-outlined text-emerald-600 text-sm">check_circle</span>
                                <span>Đã chọn: <strong x-text="'Học phí đợt ' + (currentTuition.receipt_count + 1) + ' - ' + currentTuition.class_name"></strong></span>
                            </div>
                        </template>

                        <template x-if="skipTuition || !currentTuition">
                            <div class="bg-amber-50 px-3 py-1 rounded-lg border border-amber-200 text-amber-900 text-xs flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-amber-600 text-sm">info</span>
                                <span>Đang bỏ qua khoản học phí (Lập phiếu chỉ thu riêng Phụ thu)</span>
                            </div>
                        </template>
                    </div>

                    <div class="flex items-center gap-2">
                        <template x-if="!skipTuition && currentTuition">
                            <button type="button" @click="toggleSkipTuition(true)" class="px-3 py-1 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-100 text-xs font-medium transition flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">close</span>
                                Bỏ qua khoản học phí (để chỉ thu phụ thu)
                            </button>
                        </template>
                        <template x-if="skipTuition && currentTuition">
                            <button type="button" @click="toggleSkipTuition(false)" class="px-3 py-1 rounded-lg border border-primary-container text-primary hover:bg-orange-50 text-xs font-bold transition flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">add</span>
                                Bật lại khoản học phí
                            </button>
                        </template>
                    </div>
                </div>

                <div class="px-5 py-2 bg-white border-b border-slate-100 flex items-center gap-1.5 text-[11px] text-slate-500 italic">
                    <span class="material-symbols-outlined text-sm text-primary">lightbulb</span>
                    <span>Có thể bỏ qua khoản học phí để lập phiếu chỉ thu riêng phụ thu. Khi bỏ qua, khối thông tin số buổi và bảng kê học phí bên dưới sẽ tự động ẩn.</span>
                </div>

                <!-- Khối số buổi & Bảng kê học phí (Chỉ hiển thị khi KHÔNG bỏ qua) -->
                <div x-show="!skipTuition && currentTuition" x-transition class="space-y-0">
                    <!-- Thống kê 4 ô buổi học -->
                    <div x-show="currentTuition?.total_sessions" class="grid grid-cols-2 md:grid-cols-4 border-b border-slate-200 text-center divide-x divide-slate-100 text-xs">
                        <div class="p-4">
                            <span class="text-slate-400 font-semibold uppercase text-[10px] block mb-1">Tổng số buổi</span>
                            <span class="text-lg font-bold text-slate-900" x-text="currentTuition?.total_sessions ?? '—'"></span>
                        </div>
                        <div class="p-4">
                            <span class="text-slate-400 font-semibold uppercase text-[10px] block mb-1">Đã học</span>
                            <span class="text-lg font-bold text-emerald-600" x-text="currentTuition?.attended_sessions ?? '—'"></span>
                        </div>
                        <div class="p-4">
                            <span class="text-slate-400 font-semibold uppercase text-[10px] block mb-1">Số buổi còn tồn</span>
                            <span class="text-lg font-bold text-primary" x-text="currentTuition?.remaining_sessions ?? '—'"></span>
                        </div>
                        <div class="p-4">
                            <span class="text-slate-400 font-semibold uppercase text-[10px] block mb-1">Trạng thái học</span>
                            <span class="text-sm font-bold" :class="currentTuition?.is_deferred ? 'text-blue-600' : 'text-slate-700'" x-text="currentTuition?.is_deferred ? 'Đang bảo lưu' : (currentStudent?.status_label || '—')"></span>
                        </div>
                    </div>

                    <!-- Bảng kê chi tiết khoản thu học phí -->
                    <div class="p-5 bg-slate-50/50">
                        <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-primary text-base">receipt_long</span>
                            Bảng kê chi tiết khoản thu học phí
                        </h4>

                        <div class="space-y-2 text-xs">
                            <div class="flex justify-between py-1.5 border-b border-dashed border-slate-200">
                                <span class="text-slate-600" x-text="'Học phí khóa / lớp ' + (currentTuition?.class_name || '—')"></span>
                                <span class="font-mono font-bold text-slate-900" x-text="formatVND(currentTuition?.total_amount || 0)"></span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-dashed border-slate-200" x-show="currentTuition?.other_fees > 0">
                                <span class="text-slate-600">Phí học liệu &amp; khoản thu khác</span>
                                <span class="font-mono font-bold text-slate-900" x-text="formatVND(currentTuition?.other_fees || 0)"></span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-dashed border-slate-200" x-show="currentTuition?.discount_amount > 0">
                                <span class="text-slate-600">Ưu đãi trên hợp đồng</span>
                                <span class="font-mono font-bold text-emerald-700" x-text="'-' + formatVND(currentTuition?.discount_amount || 0)"></span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-dashed border-slate-200">
                                <span class="text-slate-600">Đã nộp / Còn nợ</span>
                                <span class="font-mono font-bold text-slate-900" x-text="formatVND(currentTuition?.paid_amount || 0) + ' / ' + formatVND(currentTuition?.debt_amount || 0)"></span>
                            </div>
                        </div>

                        <!-- Giảm trừ & Tổng học phí -->
                        <div class="mt-4 pt-4 border-t border-slate-200 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                            <div class="w-full md:w-80 space-y-1">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center justify-between">
                                    <span>Số tiền giảm trừ (VNĐ)</span>
                                    <span class="text-[10px] text-slate-400 font-normal lowercase italic">không vượt tổng trước giảm</span>
                                </label>
                                <div class="relative">
                                    <input type="number" name="discount_amount" x-model.number="discountAmount" @input="recalc()" min="0" :max="tuitionSubtotal" class="w-full h-10 rounded-xl border border-slate-200 focus:border-primary-container focus:ring-primary-container/20 text-xs font-mono font-bold px-3 pr-12 text-slate-800" placeholder="0" />
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-mono">VNĐ</span>
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-1 w-full md:w-auto text-xs">
                                <div class="flex items-baseline gap-4">
                                    <span class="text-slate-500 font-medium">Tổng trước giảm:</span>
                                    <span class="font-mono font-bold text-slate-800" x-text="formatVND(tuitionSubtotal)"></span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <label for="collectAmount" class="text-slate-500 font-medium">Thu đợt này (để trống = thu hết):</label>
                                    <input id="collectAmount" type="number" min="0" :max="tuitionSubtotal" x-model="collectAmount" class="w-36 h-9 rounded-xl border border-slate-200 text-xs font-mono font-bold px-2 text-right" placeholder="Toàn bộ" />
                                </div>
                                <div class="flex items-baseline gap-4">
                                    <span class="text-primary font-bold uppercase tracking-wider">TỔNG PHẢI THU (HỌC PHÍ):</span>
                                    <span class="font-mono text-base font-bold text-primary" x-text="formatVND(tuitionAmountAfterDiscount)"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Khối 2: Phụ thu (Phí phát sinh ngoài học phí) - Tùy chọn độc lập -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-4 px-5 bg-slate-50/70 border-b border-slate-200/80 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-xl">add_shopping_cart</span>
                        <h3 class="text-sm font-bold text-slate-900">Phụ thu (Phí phát sinh ngoài học phí)</h3>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-primary border border-orange-200">
                            Tùy chọn độc lập - Chuẩn 11/09/2026
                        </span>
                    </div>
                    <span class="text-[11px] text-slate-400 italic">Mới cập nhật</span>
                </div>

                <div class="p-5 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Số tiền phụ thu (VNĐ)
                            </label>
                            <div class="relative">
                                <input type="number" name="surcharge_amount" x-model.number="surchargeAmount" @input="recalc()" min="0" step="10000" class="w-full h-11 rounded-xl border border-slate-200 focus:border-primary-container focus:ring-primary-container/20 text-xs font-mono font-bold px-3 pr-12 text-slate-900" placeholder="Nhập số tiền > 0..." />
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-mono">VNĐ</span>
                            </div>

                            <!-- Gợi ý số tiền nhanh -->
                            <div class="flex items-center gap-1.5 mt-2">
                                <span class="text-[11px] text-slate-400">Gợi ý nhanh:</span>
                                <button type="button" @click="setSurcharge(50000)" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-orange-50 hover:text-primary text-[11px] font-medium text-slate-600 transition">50.000đ</button>
                                <button type="button" @click="setSurcharge(100000)" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-orange-50 hover:text-primary text-[11px] font-medium text-slate-600 transition">100.000đ</button>
                                <button type="button" @click="setSurcharge(150000)" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-orange-50 hover:text-primary text-[11px] font-medium text-slate-600 transition">150.000đ</button>
                                <button type="button" @click="setSurcharge(200000)" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-orange-50 hover:text-primary text-[11px] font-medium text-slate-600 transition">200.000đ</button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Lý do phụ thu <span class="text-rose-500" x-show="surchargeAmount > 0">*</span>
                            </label>
                            <input type="text" name="surcharge_reason" x-model="surchargeReason" placeholder="Ví dụ: Phụ thu giáo trình in ấn bổ sung, đồng phục, thẻ học viên..." class="w-full h-11 rounded-xl border border-slate-200 focus:border-primary-container focus:ring-primary-container/20 text-xs px-3 text-slate-800" />
                            <p class="text-[11px] text-slate-400 italic mt-1.5">* Bắt buộc nhập lý do khi có nhập số tiền phụ thu.</p>
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-blue-50/60 border border-blue-100 flex items-start gap-2.5 text-xs text-blue-900">
                        <span class="material-symbols-outlined text-blue-600 text-base shrink-0 mt-0.5">info</span>
                        <span>Khoản phụ thu luôn hoạt động độc lập và không loại trừ lẫn nhau với học phí. Hệ thống cho phép: <strong>Học phí + Phụ thu</strong>, hoặc chỉ thu riêng <strong>Học phí</strong>, hoặc chỉ thu riêng <strong>Phụ thu</strong>.</span>
                    </div>
                </div>
            </div>

            <!-- Khối 3: TỔNG THỰC THU CỦA PHIẾU NÀY (Học phí + Phụ thu) -->
            <div class="bg-white p-5 rounded-2xl border-2 border-primary-container/50 shadow-sm">
                <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-primary uppercase tracking-wider">TỔNG THỰC THU CỦA PHIẾU NÀY</span>
                        <div class="flex items-center gap-3 text-xs text-slate-600 flex-wrap">
                            <span>Học phí cần thu: <strong class="text-slate-900 font-mono" x-text="formatVND(tuitionAmountAfterDiscount)"></strong></span>
                            <span class="text-slate-400">+</span>
                            <span>Tiền phụ thu: <strong class="text-primary font-mono" x-text="'+' + formatVND(surchargeAmount)"></strong></span>
                        </div>
                        <div>
                            <template x-if="isValidReceipt">
                                <span class="text-[11px] text-emerald-700 font-medium flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm text-emerald-600">check_circle</span>
                                    Hợp lệ: Đã có ít nhất 1 nguồn tiền (Chọn học phí HOẶC nhập phụ thu > 0) để Gửi duyệt.
                                </span>
                            </template>
                            <template x-if="!isValidReceipt">
                                <span class="text-[11px] text-rose-600 font-medium flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm text-rose-500">warning</span>
                                    Chưa hợp lệ: Cần chọn khoản học phí hoặc nhập số tiền phụ thu > 0.
                                </span>
                            </template>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-3 w-full md:w-auto justify-end border-t md:border-t-0 pt-3 md:pt-0 border-slate-100">
                        <span class="text-xs font-bold text-slate-700 uppercase">TỔNG THỰC THU:</span>
                        <span class="text-2xl md:text-3xl font-bold font-mono text-primary" x-text="formatVND(totalAmount)"></span>
                    </div>
                </div>
            </div>

            <!-- Hidden input for amount and tuition amount -->
            <input type="hidden" name="amount" :value="totalAmount" />
            <input type="hidden" name="tuition_amount" :value="tuitionAmountAfterDiscount" />

            <!-- Khối 4: 2 Cột: Hình thức thu tiền & Thông tin bổ sung -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                <!-- Cột trái: Hình thức thu tiền -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-slate-900 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-primary text-base">payments</span>
                            Hình thức thu tiền
                        </h3>
                        <span class="text-xs text-slate-400 italic">CM chọn phương thức</span>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <label :class="paymentMethod === 'transfer' ? 'border-primary-container bg-orange-50/60 ring-1 ring-primary-container' : 'border-slate-200 hover:bg-slate-50'" class="relative flex items-center justify-center p-3.5 border rounded-xl cursor-pointer transition">
                            <input type="radio" name="payment_method" value="transfer" x-model="paymentMethod" class="sr-only" />
                            <div class="flex flex-col items-center">
                                <span class="material-symbols-outlined mb-1" :class="paymentMethod === 'transfer' ? 'text-primary' : 'text-slate-400'">account_balance</span>
                                <span class="text-xs font-bold" :class="paymentMethod === 'transfer' ? 'text-primary' : 'text-slate-700'">Chuyển khoản</span>
                            </div>
                        </label>

                        <label :class="paymentMethod === 'cash' ? 'border-primary-container bg-orange-50/60 ring-1 ring-primary-container' : 'border-slate-200 hover:bg-slate-50'" class="relative flex items-center justify-center p-3.5 border rounded-xl cursor-pointer transition">
                            <input type="radio" name="payment_method" value="cash" x-model="paymentMethod" class="sr-only" />
                            <div class="flex flex-col items-center">
                                <span class="material-symbols-outlined mb-1" :class="paymentMethod === 'cash' ? 'text-primary' : 'text-slate-400'">payments</span>
                                <span class="text-xs font-bold" :class="paymentMethod === 'cash' ? 'text-primary' : 'text-slate-700'">Tiền mặt</span>
                            </div>
                        </label>

                        <label :class="paymentMethod === 'pos' ? 'border-primary-container bg-orange-50/60 ring-1 ring-primary-container' : 'border-slate-200 hover:bg-slate-50'" class="relative flex items-center justify-center p-3.5 border rounded-xl cursor-pointer transition">
                            <input type="radio" name="payment_method" value="pos" x-model="paymentMethod" class="sr-only" />
                            <div class="flex flex-col items-center">
                                <span class="material-symbols-outlined mb-1" :class="paymentMethod === 'pos' ? 'text-primary' : 'text-slate-400'">credit_card</span>
                                <span class="text-xs font-bold" :class="paymentMethod === 'pos' ? 'text-primary' : 'text-slate-700'">Quẹt thẻ POS</span>
                            </div>
                        </label>
                    </div>

                    <!-- Giao diện Chuyển khoản -->
                    <div x-show="paymentMethod === 'transfer'" x-transition class="space-y-3 pt-2">
                        <div class="bg-blue-50 p-3 rounded-xl flex items-start gap-2.5 border border-blue-100 text-xs text-blue-900">
                            <span class="material-symbols-outlined text-blue-600 text-base shrink-0 mt-0.5">sync</span>
                            <div>
                                <p class="font-bold">Trạng thái đối soát: Tạm thu</p>
                                <p class="text-blue-800 text-[11px] mt-0.5">Hệ thống tự động ghi nhận &amp; khớp giao dịch khi phụ huynh chuyển khoản đúng cú pháp (Nội dung CK: <strong x-text="transferMemo"></strong>).</p>
                            </div>
                        </div>

                        <!-- Card tài khoản ngân hàng mặc định -->
                        <div x-show="bank" class="border border-slate-200 rounded-xl p-3.5 bg-slate-50/50 space-y-3 text-xs">
                            <div class="flex items-center justify-between border-b border-slate-200/80 pb-2">
                                <h5 class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Tài khoản ngân hàng nhận học phí</h5>
                                <span class="text-[10px] text-slate-400 italic" x-text="bank?.scope"></span>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-4 items-center">
                                <div class="flex-grow space-y-2 w-full">
                                    <div class="flex justify-between border-b border-dashed border-slate-200 pb-1">
                                        <span class="text-slate-500">Ngân hàng</span>
                                        <span class="font-bold text-slate-900" x-text="bank?.bank_name"></span>
                                    </div>
                                    <div class="flex justify-between border-b border-dashed border-slate-200 pb-1">
                                        <span class="text-slate-500">Số tài khoản</span>
                                        <span class="font-mono font-bold text-primary" x-text="bank?.account_number"></span>
                                    </div>
                                    <div class="flex justify-between border-b border-dashed border-slate-200 pb-1">
                                        <span class="text-slate-500">Chủ tài khoản</span>
                                        <span class="font-bold uppercase text-slate-900" x-text="bank?.account_holder"></span>
                                    </div>
                                    <div class="flex justify-between items-center pt-0.5">
                                        <span class="text-slate-500">Nội dung CK</span>
                                        <span class="font-mono font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded text-[11px]" x-text="transferMemo"></span>
                                    </div>
                                    <input type="hidden" name="transfer_memo" :value="transferMemo">
                                </div>

                                <!-- Dynamic VietQR Code -->
                                <div class="flex flex-col items-center gap-1 shrink-0">
                                    <div class="w-28 h-28 bg-white border-2 border-primary-container/20 p-1 rounded-xl shadow-xs overflow-hidden flex items-center justify-center">
                                        <img :src="vietQrUrl" alt="VietQR Thanh toán" class="w-full h-full object-contain" />
                                    </div>
                                    <span class="text-[10px] text-slate-400 italic">Quét VietQR tự điền số tiền</span>
                                </div>
                            </div>

                            <div class="px-2.5 py-1.5 bg-slate-100 rounded-lg text-[11px] text-slate-500 italic flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-xs text-slate-400">lock</span>
                                <span>Tài khoản lấy theo hợp đồng / chi nhánh của học viên; CM không đổi được trên màn hình này.</span>
                            </div>
                        </div>
                        @if (! $defaultBank)
                            <div x-show="!bank" class="border border-amber-200 rounded-xl p-3.5 bg-amber-50 text-xs text-amber-800 flex items-start gap-2">
                                <span class="material-symbols-outlined text-amber-600 text-base shrink-0">warning</span>
                                <span><strong>Chưa cấu hình tài khoản ngân hàng</strong> đang hoạt động để nhận học phí. Mã VietQR sẽ không được tạo — vui lòng liên hệ Kế toán/Admin cấu hình tài khoản trước khi hướng dẫn phụ huynh chuyển khoản.</span>
                            </div>
                        @endif

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Mã tham chiếu / Mã giao dịch ngân hàng (nếu có)</label>
                            <input type="text" name="transaction_code" x-model="transactionCode" placeholder="Ví dụ: FT232981354789..." class="w-full text-xs font-mono rounded-xl border border-slate-200 px-3 py-2 focus:border-primary-container focus:ring-primary-container/20" />
                        </div>
                    </div>

                    <!-- Giao diện Tiền mặt -->
                    <div x-show="paymentMethod === 'cash'" x-transition class="space-y-3 pt-2">
                        <div class="p-3.5 rounded-xl border border-slate-200 bg-slate-50 space-y-1 text-xs">
                            <label class="font-bold text-slate-700 uppercase tracking-wider flex items-center justify-between">
                                <span>Số phiếu / Số hóa đơn giấy thu tiền mặt (nếu có)</span>
                                <span class="text-[10px] text-slate-400 lowercase font-normal italic">áp dụng khi viết biên lai tay</span>
                            </label>
                            <input type="text" name="paper_invoice_number" placeholder="Ví dụ: HĐG-0824/PTM-042..." class="w-full h-10 rounded-xl border border-slate-200 px-3 text-xs font-mono font-bold focus:border-primary-container focus:ring-primary-container/20 bg-white" />
                        </div>
                    </div>
                </div>

                <!-- Cột phải: Thông tin bổ sung -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                    <h3 class="text-sm font-bold text-slate-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-base">description</span>
                        Thông tin bổ sung
                    </h3>

                    <!-- Toggle Hóa đơn VAT -->
                    <div class="flex items-center justify-between p-3.5 bg-slate-50/70 rounded-xl border border-slate-200">
                        <div class="flex items-center gap-2.5">
                            <span class="material-symbols-outlined text-slate-500 text-lg">receipt</span>
                            <div>
                                <span class="text-xs font-bold text-slate-900 block">Yêu cầu xuất hóa đơn đỏ (VAT)</span>
                                <span class="text-[11px] text-slate-400">Xuất theo thông tin doanh nghiệp/cá nhân</span>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="is_vat_invoice" value="1" class="sr-only peer" @checked(old('is_vat_invoice', $editingReceipt?->is_vat_invoice)) />
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-container"></div>
                        </label>
                    </div>

                    <!-- Người nộp tiền & SĐT -->
                    <div class="grid grid-cols-2 gap-3 text-xs">
                        <div class="space-y-1">
                            <label class="font-bold text-slate-700 uppercase tracking-wider block">Người nộp tiền</label>
                            <input type="text" name="payer_name" x-model="payerName" placeholder="Họ và tên người nộp..." class="w-full h-10 rounded-xl border border-slate-200 focus:border-primary-container focus:ring-primary-container/20 px-3 text-xs font-medium text-slate-800" />
                        </div>
                        <div class="space-y-1">
                            <label class="font-bold text-slate-700 uppercase tracking-wider block">Số điện thoại</label>
                            <input type="tel" name="payer_phone" x-model="payerPhone" placeholder="09xxxxxxxx..." class="w-full h-10 rounded-xl border border-slate-200 focus:border-primary-container focus:ring-primary-container/20 px-3 text-xs font-mono text-slate-800" />
                        </div>
                    </div>

                    <!-- Ghi chú nội bộ -->
                    <div class="space-y-1 text-xs">
                        <label class="font-bold text-slate-700 uppercase tracking-wider block">Ghi chú nội bộ</label>
                        <textarea name="notes" rows="3" placeholder="Nhập ghi chú quan trọng cho bộ phận kế toán và quản lý lớp..." class="w-full rounded-xl border border-slate-200 focus:border-primary-container focus:ring-primary-container/20 p-3 text-xs text-slate-800">{{ old('notes', $editingReceipt?->notes ?? 'Phụ huynh nộp thanh toán học phí & phụ thu qua cổng MEnglish.') }}</textarea>
                    </div>
                </div>
            </div>

            <!-- Khối 5: Minh chứng thanh toán (*) -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-base">upload_file</span>
                        Minh chứng thanh toán <span class="text-rose-500" x-show="proofRequired">*</span>
                    </h3>
                    <span class="text-xs font-medium flex items-center gap-1" :class="proofRequired ? 'text-amber-700' : 'text-slate-400'">
                        <span class="material-symbols-outlined text-xs" x-text="proofRequired ? 'warning' : 'info'"></span>
                        <span x-text="proofRequired ? 'Bắt buộc khi gửi duyệt: ủy nhiệm chi / ảnh chuyển khoản / biên lai POS' : 'Tiền mặt: không bắt buộc minh chứng'"></span>
                    </span>
                </div>

                <!-- Drag & Drop Zone -->
                <div class="border-2 border-dashed border-slate-300 rounded-2xl p-6 flex flex-col items-center justify-center text-center cursor-pointer hover:bg-slate-50/50 transition relative" @click="$refs.fileInput.click()">
                    <input type="file" name="proof_image" x-ref="fileInput" @change="handleFileSelected($event)" accept="image/*,.pdf" class="hidden" />
                    
                    <div class="w-14 h-14 bg-orange-50 rounded-2xl flex items-center justify-center mb-2.5 text-primary">
                        <span class="material-symbols-outlined text-2xl">cloud_upload</span>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-slate-800">Kéo thả hoặc <span class="text-primary underline">chọn tệp</span> để tải lên ủy nhiệm chi/biên lai chuyển khoản</p>
                        <p class="text-[11px] text-slate-400 mt-1">Hỗ trợ: JPG, PNG, PDF (Tối đa 5MB) - Đảm bảo rõ ràng thông tin giao dịch &amp; mã tham chiếu</p>
                    </div>
                </div>

                <!-- Preview file đã chọn -->
                <template x-if="proofPreviewUrl">
                    <div class="space-y-1.5 pt-2">
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Minh chứng đã đính kèm</p>
                        <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl border border-slate-200">
                            <div class="w-16 h-16 rounded-lg bg-slate-200 overflow-hidden shrink-0 border border-slate-300 relative group">
                                <img :src="proofPreviewUrl" alt="Minh chứng" class="w-full h-full object-cover" x-show="!proofIsPdf" />
                                <span x-show="proofIsPdf" class="w-full h-full flex items-center justify-center font-bold text-xs text-slate-500">PDF</span>
                            </div>
                            <div class="flex-grow text-xs space-y-0.5">
                                <span class="font-bold text-emerald-700 flex items-center gap-1 text-[11px]">
                                    <span class="material-symbols-outlined text-xs">verified</span>
                                    Đã tải lên tệp minh chứng
                                </span>
                                <p class="text-slate-600 font-mono text-[11px]" x-text="proofFileName"></p>
                                <p class="text-[10px] text-slate-400" x-text="proofFileSize"></p>
                            </div>
                            <button type="button" @click="clearProof()" class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50 transition" title="Xóa tệp">
                                <span class="material-symbols-outlined text-base">delete</span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Footer cố định dưới đáy màn hình -->
            <div class="fixed bottom-0 left-0 right-0 h-20 bg-white border-t border-slate-200/80 shadow-[0_-4px_12px_rgba(0,0,0,0.06)] px-4 md:px-8 z-40">
                <div class="max-w-5xl mx-auto h-full flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <a href="{{ $editingReceipt ? route('tuition.receipts.approve', ['selected_id' => $editingReceipt->id]) : route('tuition.students') }}" class="px-4 py-2.5 rounded-xl border border-slate-300 text-slate-600 font-semibold text-xs hover:bg-slate-50 transition">
                            Hủy bỏ
                        </a>
                        <button type="submit" name="submit_action" value="draft" class="px-4 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-bold text-xs hover:bg-slate-50 transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base">drafts</span>
                            Lưu nháp
                        </button>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="button" disabled class="hidden sm:inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl border border-slate-200 text-slate-400 font-medium text-xs bg-slate-50 cursor-not-allowed" title="Chỉ xuất biên lai khi phiếu ở trạng thái Đã duyệt">
                            <span class="material-symbols-outlined text-base">print</span>
                            Xuất biên lai
                        </button>
                        <button type="submit" name="submit_action" value="submit" :disabled="!isValidReceipt" :class="isValidReceipt ? 'bg-primary-container hover:bg-primary-hover text-white shadow-md' : 'bg-slate-200 text-slate-400 cursor-not-allowed'" class="px-6 py-2.5 rounded-xl font-bold text-xs transition flex items-center gap-2">
                            <span class="material-symbols-outlined text-base">save</span>
                            <span>{{ $editingReceipt ? 'Lưu & Gửi duyệt lại' : 'Lưu phiếu thu & Gửi duyệt' }} (<span class="font-mono" x-text="formatVND(totalAmount)"></span>)</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        function createReceiptManager(tuitions, students, initialTuitionId, initialStudentId, defaultBank, editing) {
            return {
                tuitions: tuitions || [],
                students: students || [],
                selectedTuitionId: initialTuitionId || '',
                selectedStudentId: initialStudentId || '',
                currentTuition: null,
                currentStudent: null,
                editing: editing,

                skipTuition: false,
                discountAmount: editing ? editing.discount_amount : 0,
                collectAmount: editing ? String(editing.tuition_amount) : '',
                surchargeAmount: editing ? editing.surcharge_amount : 0,
                surchargeReason: editing ? (editing.surcharge_reason || '') : '',
                paymentMethod: editing ? editing.payment_method : 'transfer',
                transactionCode: editing ? (editing.transaction_code || '') : '',
                payerName: '',
                payerPhone: '',

                defaultBank: defaultBank,

                proofPreviewUrl: editing && editing.proof_image ? editing.proof_image : null,
                proofFileName: editing && editing.proof_image ? editing.proof_image.split('/').pop() : '',
                proofFileSize: '',
                proofIsPdf: !!(editing && editing.proof_image && editing.proof_image.toLowerCase().endsWith('.pdf')),
                proofRemoved: false,

                get bank() {
                    return (this.currentTuition && this.currentTuition.bank) ? this.currentTuition.bank : this.defaultBank;
                },

                get proofRequired() {
                    return ['transfer', 'vietqr', 'pos'].includes(this.paymentMethod);
                },

                init() {
                    if (this.editing && !this.selectedTuitionId) {
                        // Phiếu chỉ thu phụ thu: giữ nguyên, không tự gắn hồ sơ học phí.
                        this.skipTuition = true;
                        this.currentStudent = this.students.find(s => String(s.id) === String(this.selectedStudentId)) || null;
                        this.payerName = this.editing.payer_name || '';
                        this.payerPhone = this.editing.payer_phone || '';
                    } else if (this.selectedTuitionId) {
                        this.onTuitionChange();
                    } else if (this.selectedStudentId) {
                        this.onStudentChange();
                    } else if (this.tuitions.length > 0) {
                        this.selectedTuitionId = this.tuitions[0].id;
                        this.onTuitionChange();
                    }
                },

                onTuitionChange() {
                    if (!this.selectedTuitionId) {
                        this.currentTuition = null;
                        this.skipTuition = true;
                        return;
                    }

                    const t = this.tuitions.find(item => String(item.id) === String(this.selectedTuitionId));
                    if (t) {
                        this.currentTuition = t;
                        this.selectedStudentId = t.student_id;
                        this.currentStudent = this.students.find(s => String(s.id) === String(t.student_id)) || null;
                        this.skipTuition = false;
                        this.payerName = (this.editing && this.editing.payer_name) || t.student_parent_name || t.student_name;
                        this.payerPhone = (this.editing && this.editing.payer_phone) || t.student_parent_phone || t.student_phone;
                    }
                },

                onStudentChange() {
                    const s = this.students.find(item => String(item.id) === String(this.selectedStudentId));
                    if (s) {
                        this.currentStudent = s;
                        this.payerName = s.parent_name || s.name;
                        this.payerPhone = s.parent_phone || s.phone;
                        // Find matching tuition if any
                        const matchingT = this.tuitions.find(t => String(t.student_id) === String(s.id));
                        if (matchingT) {
                            this.selectedTuitionId = matchingT.id;
                            this.currentTuition = matchingT;
                        }
                    }
                },

                toggleSkipTuition(val) {
                    this.skipTuition = val;
                },

                setSurcharge(val) {
                    this.surchargeAmount = val;
                    if (!this.surchargeReason) {
                        this.surchargeReason = 'Phụ thu giáo trình & học liệu bổ sung';
                    }
                },

                get tuitionSubtotal() {
                    if (this.skipTuition || !this.currentTuition) return 0;
                    return (parseFloat(this.currentTuition.debt_amount) > 0)
                        ? parseFloat(this.currentTuition.debt_amount)
                        : (parseFloat(this.currentTuition.total_amount) + parseFloat(this.currentTuition.other_fees));
                },

                get tuitionAmountAfterDiscount() {
                    if (this.skipTuition || !this.currentTuition) return 0;
                    const sub = this.tuitionSubtotal;
                    const disc = parseFloat(this.discountAmount) || 0;
                    const max = Math.max(0, sub - disc);
                    // Thu một phần công nợ: nhập số tiền thu đợt này (không vượt phần còn phải thu).
                    if (this.collectAmount !== '' && this.collectAmount !== null && !isNaN(parseFloat(this.collectAmount))) {
                        return Math.min(max, Math.max(0, parseFloat(this.collectAmount)));
                    }
                    return max;
                },

                get totalAmount() {
                    const t = this.tuitionAmountAfterDiscount;
                    const s = parseFloat(this.surchargeAmount) || 0;
                    return t + s;
                },

                get isValidReceipt() {
                    const hasMoney = this.totalAmount > 0;
                    const surchargeValid = (this.surchargeAmount <= 0) || (this.surchargeReason && this.surchargeReason.trim().length > 0);
                    return hasMoney && surchargeValid;
                },

                removeVietnameseTones(str) {
                    if (!str) return '';
                    return str.normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .replace(/đ/g, 'd').replace(/Đ/g, 'D')
                        .replace(/[^a-zA-Z0-9]/g, '');
                },

                get transferMemo() {
                    const code = (this.currentStudent?.code || 'HS000001').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    let name = this.removeVietnameseTones(this.currentStudent?.name || 'HOCVIEN').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    let cls = this.removeVietnameseTones(this.currentTuition?.class_name || this.currentStudent?.class_name || 'LOP').toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 7);
                    let cn = this.removeVietnameseTones(this.currentStudent?.branch_name || 'BD').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    if (!cn.startsWith('CN')) {
                        cn = 'CN' + cn;
                    }
                    const phone = (this.currentStudent?.phone || this.currentStudent?.parent_phone || '').replace(/[^0-9]/g, '');
                    const tail = phone ? phone.slice(-3) : '888';
                    return `${code} ${name} ${cls} ${cn} ${tail}`;
                },

                get vietQrUrl() {
                    const bank = this.bank;
                    if (!bank || !bank.bank_code || !bank.account_number) return '';
                    const memo = this.transferMemo;
                    const amt = this.totalAmount > 0 ? this.totalAmount : 0;
                    return 'https://img.vietqr.io/image/' + encodeURIComponent(bank.bank_code) + '-' + encodeURIComponent(bank.account_number) + '-compact2.png?amount=' + amt + '&addInfo=' + encodeURIComponent(memo) + '&accountName=' + encodeURIComponent(bank.account_holder || '');
                },

                handleFileSelected(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    this.proofFileName = file.name;
                    this.proofFileSize = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                    this.proofIsPdf = !file.type.startsWith('image/');
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            this.proofPreviewUrl = e.target.result;
                        };
                        reader.readAsDataURL(file);
                    } else {
                        this.proofPreviewUrl = 'pdf';
                    }
                },

                clearProof() {
                    this.proofRemoved = true;
                    this.proofPreviewUrl = null;
                    this.proofFileName = '';
                    this.proofFileSize = '';
                    if (this.$refs.fileInput) {
                        this.$refs.fileInput.value = '';
                    }
                },

                recalc() {
                    // Reactive triggers
                },

                formatVND(val) {
                    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
                }
            };
        }
    </script>
</x-app-layout>
