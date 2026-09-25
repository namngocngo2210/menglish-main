<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('crm.pipeline') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">route</span>
                        Quy trình Chốt hợp đồng &amp; Xếp lớp (Closing Wizard)
                    </h1>
                    <p class="text-xs text-gray-500">4 bước liên thông từ CRM Lead -> Tạo Học viên -> Bàn giao Lớp học -> Lập Học phí &amp; Xuất Phiếu thu</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('system-config.bank-accounts') }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold shadow-xs transition" title="Cài đặt tài khoản ngân hàng thụ hưởng & SePay">
                    <span class="material-symbols-outlined text-sm text-[#ea580c]">account_balance</span>
                    <span>Cài đặt STK &amp; SePay</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-6" x-data="closingWizard()">
        @if ($errors->any())
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                <div class="font-bold mb-1">Không thể hoàn tất chốt Lead:</div>
                <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        @if ($bankAccounts->isEmpty())
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 font-semibold">
                Chưa có tài khoản ngân hàng hoạt động. Bạn vẫn có thể thu tiền mặt/POS; chuyển khoản sẽ cần cấu hình tài khoản trước.
            </div>
        @endif
        @if ($customers->isEmpty())
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 font-semibold">
                Chưa có Lead nào ở trạng thái sẵn sàng chốt (đã học thử, chờ lớp hoặc chờ thanh toán).
            </div>
        @endif
        @if ($classes->isEmpty())
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 font-semibold">
                Không còn lớp đang hoạt động và còn chỗ. Hãy mở lớp hoặc điều chỉnh sĩ số trước khi chốt Lead.
            </div>
        @endif
        <!-- Wizard Step Indicator -->
        <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-xs flex items-center justify-between">
            <div class="flex items-center gap-3 cursor-pointer" @click="step = 1">
                <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs" :class="step >= 1 ? 'bg-[#ea580c] text-white' : 'bg-gray-100 text-gray-500'">1</div>
                <div class="hidden sm:block text-left">
                    <div class="text-xs font-bold text-gray-900">Chọn Khách hàng Lead</div>
                    <div class="text-[10px] text-gray-400">Từ Database CRM</div>
                </div>
            </div>
            <div class="h-0.5 w-12 bg-gray-200"></div>

            <div class="flex items-center gap-3 cursor-pointer" @click="step = 2">
                <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs" :class="step >= 2 ? 'bg-[#ea580c] text-white' : 'bg-gray-100 text-gray-500'">2</div>
                <div class="hidden sm:block text-left">
                    <div class="text-xs font-bold text-gray-900">Học phí &amp; Ưu đãi</div>
                    <div class="text-[10px] text-gray-400">Thu trước &amp; Thu khác</div>
                </div>
            </div>
            <div class="h-0.5 w-12 bg-gray-200"></div>

            <div class="flex items-center gap-3 cursor-pointer" @click="step = 3">
                <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs" :class="step >= 3 ? 'bg-[#ea580c] text-white' : 'bg-gray-100 text-gray-500'">3</div>
                <div class="hidden sm:block text-left">
                    <div class="text-xs font-bold text-gray-900">Xếp lớp &amp; Lịch học</div>
                    <div class="text-[10px] text-gray-400">Lớp học khả dụng</div>
                </div>
            </div>
            <div class="h-0.5 w-12 bg-gray-200"></div>

            <div class="flex items-center gap-3 cursor-pointer" @click="step = 4">
                <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs" :class="step >= 4 ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-500'">4</div>
                <div class="hidden sm:block text-left">
                    <div class="text-xs font-bold text-gray-900">Chốt Deal &amp; Thu tiền</div>
                    <div class="text-[10px] text-gray-400">VietQR, Quẹt thẻ &amp; Bill</div>
                </div>
            </div>
        </div>

        <form action="{{ route('crm.closing-wizard.store') }}" method="POST">
            @csrf
            <!-- Hidden Form Inputs for Backend Submission -->
            <input type="hidden" name="customer_id" :value="customerId" />
            <input type="hidden" name="class_id" :value="classId" />
            <input type="hidden" name="course_name" :value="courseName" />
            <input type="hidden" name="base_tuition" :value="baseTuition" />
            <input type="hidden" name="discount" :value="discount" />
            <input type="hidden" name="promotion_id" :value="selectedPromotionId" />
            <input type="hidden" name="other_fees" :value="otherFees" />
            <input type="hidden" name="fee_items" :value="JSON.stringify(feeItems)" />
            <input type="hidden" name="prepaid_amount" :value="prepaidAmount" />
            <input type="hidden" name="paid_amount" :value="paidAmount" />
            <input type="hidden" name="payment_method" :value="paymentMethod" />
            <input type="hidden" name="split_cash_amount" :value="splitCash" />
            <input type="hidden" name="split_transfer_amount" :value="splitTransfer" />
            <input type="hidden" name="split_pos_amount" :value="splitPos" />
            <input type="hidden" name="bank_account_id" :value="selectedBankAccountId" />
            {{-- transfer_memo được server sinh từ mã học viên thật sau khi tạo hồ sơ, không lấy từ client --}}

            <!-- ═════════════════════════════════════════════════════════════════
                 BƯỚC 1: CHỌN KHÁCH HÀNG LEAD
                 ═════════════════════════════════════════════════════════════════ -->
            <div x-show="step === 1" class="bg-white rounded-2xl p-6 border border-gray-200 shadow-xs space-y-6">
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider pb-2 border-b border-gray-100 flex items-center gap-2">
                    <span class="material-symbols-outlined text-[#ea580c] text-base">person_search</span>
                    Bước 1: Chọn Khách hàng Lead trong Pipeline
                </h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Chọn Khách hàng cần chốt hợp đồng <span class="text-rose-500">*</span></label>
                        <select class="w-full text-xs font-bold rounded-xl border border-gray-200 p-2.5 focus:border-[#ea580c] focus:ring-[#ea580c]" @change="updateCustomer($event)">
                            @foreach ($customers as $c)
                                <option
                                    value="{{ $c->id }}" 
                                    @selected($loop->first)
                                    data-name="{{ $c->name }}" 
                                    data-phone="{{ $c->phone }}" 
                                    data-parent="{{ $c->parent_name ?? '' }}"
                                    data-code="{{ $c->code }}"
                                    data-branch="{{ $c->branch?->code ?? 'BD' }}"
                                    data-tuition="{{ $c->deal_value > 0 ? $c->deal_value : 12500000 }}"
                                >
                                    {{ $c->name }} ({{ $c->code }} - {{ $c->phone }}) · {{ $c->course_interest ?? 'Chưa chọn khóa' }} · {{ $c->stage_label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Khóa học đăng ký</label>
                        <select x-model="courseName" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold text-[#ea580c] focus:border-[#ea580c] focus:ring-[#ea580c]">
                            @foreach ($courses as $crs)
                                <option value="{{ $crs->name }}">{{ $crs->name }} (Học phí niêm yết: {{ number_format($crs->tuition_fee) }}đ)</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-end pt-4 border-t border-gray-100">
                    <button type="button" @click="step = 2" class="px-6 py-2.5 bg-[#ea580c] hover:bg-[#c2410c] text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center gap-1.5">
                        <span>Tiếp tục: Tính học phí &amp; Ưu đãi</span>
                        <span class="material-symbols-outlined text-base">arrow_forward</span>
                    </button>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════════════════
                 BƯỚC 2: HỌC PHÍ, ƯU ĐÃI (CÓ NÚT TẠO MỚI), THU TRƯỚC, THU KHÁC
                 ═════════════════════════════════════════════════════════════════ -->
            <div x-show="step === 2" class="bg-white rounded-2xl p-6 border border-gray-200 shadow-xs space-y-6">
                <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                    <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#ea580c] text-base">percent</span>
                        Bước 2: Học phí, Ưu đãi, Thu trước &amp; Thu khác
                    </h2>
                    @can('promotion.manage')
                    <button 
                        type="button" 
                        @click="showCreatePromoModal = true" 
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-orange-50 hover:bg-orange-100 text-[#ea580c] text-xs font-bold transition border border-orange-200"
                    >
                        <span class="material-symbols-outlined text-sm">add_circle</span>
                        <span>Tạo mới ưu đãi</span>
                    </button>
                    @endcan
                </div>

                <!-- Dòng 1: Học phí niêm yết & Chọn ưu đãi -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">
                            Học phí niêm yết (VNĐ) <span class="text-rose-500">*</span>
                        </label>
                        <input 
                            type="number" 
                            x-model.number="baseTuition" 
                            readonly
                            class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5 bg-gray-50 cursor-not-allowed"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1 flex items-center justify-between">
                            <span>Chương trình Ưu đãi / Voucher</span>
                            <span class="text-[10px] text-gray-400">Chọn hoặc nhập trực tiếp</span>
                        </label>
                        <select 
                            x-model="selectedPromotionId" 
                            @change="applyPromotion($event)" 
                            class="w-full text-xs font-semibold rounded-xl border border-gray-200 p-2.5 focus:border-[#ea580c] focus:ring-[#ea580c]"
                        >
                            <option value="">-- Tùy chỉnh / Không áp dụng --</option>
                            <template x-for="p in availablePromotions" :key="p.id">
                                <option :value="p.id" x-text="p.name + ' (' + (p.type === 'percent' ? p.value + '%' : new Intl.NumberFormat('vi-VN').format(p.value) + 'đ') + ')'"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <!-- Dòng 2: Chiết khấu tiền, Thu khác, Thu trước -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">
                            Tiền Ưu đãi giảm trừ (VNĐ)
                        </label>
                        <input 
                            type="number" 
                            x-model.number="discount" 
                            readonly
                            class="w-full text-xs font-mono font-bold text-rose-600 rounded-xl border border-gray-200 p-2.5 bg-gray-50 cursor-not-allowed"
                            placeholder="0"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1 flex items-center justify-between">
                            <span>Khoản thu khác (VNĐ)</span>
                            <span class="text-[10px] text-blue-600 font-bold" x-text="feeItems.length + ' mục đã chọn'"></span>
                        </label>
                        <input 
                            type="number" 
                            x-model.number="otherFees" 
                            readonly
                            class="w-full text-xs font-mono font-bold text-blue-600 rounded-xl border border-gray-200 p-2.5 bg-gray-50 cursor-not-allowed"
                            placeholder="0"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1 flex items-center justify-between">
                            <span>Thu trước (VNĐ)</span>
                            <span class="text-[10px] text-gray-400">Đã cọc / Đóng trước</span>
                        </label>
                        <input 
                            type="number" 
                            x-model.number="prepaidAmount" 
                            class="w-full text-xs font-mono font-bold text-amber-600 rounded-xl border border-gray-200 p-2.5 focus:border-[#ea580c] focus:ring-[#ea580c]" 
                            placeholder="0"
                        />
                    </div>
                </div>

                <!-- Bóc tách chi tiết các khoản Thu khác (Đồng phục, balo, học liệu, phụ phí...) -->
                <div class="p-4 bg-slate-50/90 rounded-2xl border border-slate-200/90 space-y-3">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div>
                            <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-blue-600 text-base">receipt_long</span>
                                Bóc tách chi tiết các khoản Thu khác (Hiển thị trên Hóa đơn phụ huynh)
                            </span>
                            <p class="text-[11px] text-slate-500 mt-0.5">Phụ huynh muốn nhìn rõ từng khoản mục cần tính tiền trong phiếu báo học phí.</p>
                        </div>
                    </div>

                    <!-- Chọn từ Danh mục Hàng hóa & Vật phẩm -->
                    <div class="flex flex-col sm:flex-row items-center gap-2 pt-1">
                        <div class="w-full sm:flex-1">
                            <select 
                                @change="if ($event.target.value) { const parts = $event.target.value.split('|||'); addPresetItem(parseInt(parts[0], 10), parts[1], parseInt(parts[2], 10)); $event.target.value = ''; }"
                                class="w-full text-xs font-semibold rounded-xl border border-blue-200 bg-white py-2 px-3 text-slate-700 focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                            >
                                <option value="">-- Chọn nhanh từ Danh mục Hàng hóa &amp; Thu khác ({{ isset($merchandiseItems) ? $merchandiseItems->count() : 0 }} mặt hàng) --</option>
                                @if (isset($merchandiseItems))
                                    @foreach ($merchandiseItems as $mItem)
                                        <option value="{{ $mItem->id }}|||{{ $mItem->name }}|||{{ (int)$mItem->price }}">
                                             [{{ $mItem->category_label }}] {{ $mItem->name }} ({{ $mItem->formatted_price }})
                                        </option>
                                    @endforeach
                                @endif
                            </select>
                        </div>
                        <a href="{{ route('merchandise.index') }}" target="_blank" class="text-[11px] font-bold text-blue-600 hover:text-blue-800 hover:underline flex items-center gap-0.5 shrink-0" title="Mở quản lý danh mục hàng hóa trong tab mới">
                            <span class="material-symbols-outlined text-sm">open_in_new</span>
                            <span>Quản lý danh mục</span>
                        </a>
                    </div>

                    <!-- Danh sách các mục đã thêm -->
                    <template x-if="feeItems.length > 0">
                        <div class="space-y-2 pt-2 border-t border-slate-200/80">
                            <template x-for="(item, idx) in feeItems" :key="idx">
                                <div class="flex items-center gap-2 bg-white p-2 rounded-xl border border-slate-200 shadow-2xs">
                                    <div class="flex-1">
                                        <input type="text" x-model="item.name" readonly class="w-full text-xs font-semibold text-slate-800 rounded-lg border-gray-200 p-1.5 bg-gray-50" />
                                    </div>
                                    <div class="w-36">
                                        <div class="relative">
                                            <input type="number" x-model.number="item.amount" readonly class="w-full text-xs font-mono font-bold text-right text-blue-600 rounded-lg border-gray-200 p-1.5 pr-7 bg-gray-50" />
                                            <span class="absolute right-2 top-1.5 text-[10px] text-gray-400 font-bold">đ</span>
                                        </div>
                                    </div>
                                    <button type="button" @click="removeItem(idx)" class="p-1 text-slate-400 hover:text-rose-600 rounded-md hover:bg-rose-50 transition" title="Xóa mục này">
                                        <span class="material-symbols-outlined text-[18px]">delete</span>
                                    </button>
                                </div>
                            </template>
                            <div class="flex justify-between items-center text-xs px-1 text-slate-600">
                                <span>Tổng cộng các mục thu khác:</span>
                                <span class="font-mono font-bold text-blue-700" x-text="formatVND(otherFees)"></span>
                            </div>
                        </div>
                    </template>
                    <template x-if="feeItems.length === 0">
                        <div class="text-[11px] text-slate-400 italic py-1">
                            Chưa chọn mục thu khác nào (hoặc nhập trực tiếp vào ô Khoản thu khác ở trên).
                        </div>
                    </template>
                </div>

                <!-- Bảng tổng hợp thành tiền theo đúng công thức -->
                <div class="bg-gray-50/80 rounded-2xl p-4 border border-gray-200/80 space-y-2 text-xs">
                    <div class="flex justify-between items-center text-gray-600">
                        <span>Học phí niêm yết:</span>
                        <span class="font-mono font-semibold" x-text="formatVND(baseTuition)"></span>
                    </div>
                    <div class="flex justify-between items-center text-rose-600">
                        <span>- Giảm trừ ưu đãi:</span>
                        <span class="font-mono font-semibold" x-text="'- ' + formatVND(discount)"></span>
                    </div>
                    <div class="flex justify-between items-center text-blue-600">
                        <span>+ Thu khác (Giáo trình / Phụ phí):</span>
                        <span class="font-mono font-semibold" x-text="'+ ' + formatVND(otherFees)"></span>
                    </div>
                    <div class="flex justify-between items-center font-bold text-gray-900 pt-2 border-t border-gray-200">
                        <span>= Tổng giá trị hợp đồng (Thành tiền):</span>
                        <span class="font-mono text-sm text-[#ea580c]" x-text="formatVND(contractTotal)"></span>
                    </div>
                    <div class="flex justify-between items-center text-amber-700 pt-1">
                        <span>- Thu trước (Đã cọc / thanh toán trước):</span>
                        <span class="font-mono font-semibold" x-text="'- ' + formatVND(prepaidAmount)"></span>
                    </div>
                    <div class="flex justify-between items-center font-black text-emerald-700 pt-2 border-t border-gray-200 text-sm">
                        <span>Số tiền thực thu cần nộp đợt này:</span>
                        <span class="font-mono text-base" x-text="formatVND(amountDue)"></span>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <button type="button" @click="step = 1" class="px-4 py-2 border border-gray-200 text-xs font-semibold text-gray-700 rounded-xl hover:bg-gray-50 transition">
                        Quay lại
                    </button>
                    <button type="button" @click="paidAmount = amountDue; syncSplitAmounts(); step = 3" class="px-6 py-2.5 bg-[#ea580c] hover:bg-[#c2410c] text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center gap-1.5">
                        <span>Tiếp tục: Xếp lớp</span>
                        <span class="material-symbols-outlined text-base">arrow_forward</span>
                    </button>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════════════════
                 BƯỚC 3: XẾP LỚP & BÀN GIAO HỌC VIÊN
                 ═════════════════════════════════════════════════════════════════ -->
            <div x-show="step === 3" class="bg-white rounded-2xl p-6 border border-gray-200 shadow-xs space-y-6">
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider pb-2 border-b border-gray-100 flex items-center gap-2">
                    <span class="material-symbols-outlined text-[#ea580c] text-base">meeting_room</span>
                    Bước 3: Chọn Lớp học &amp; Bàn giao Học viên
                </h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Chọn Lớp học đang mở tiếp nhận <span class="text-rose-500">*</span></label>
                        <select class="w-full text-xs font-bold rounded-xl border border-gray-200 p-2.5 text-[#ea580c] focus:border-[#ea580c] focus:ring-[#ea580c]" @change="updateClass($event)">
                            @foreach ($classes as $cl)
                                <option 
                                    value="{{ $cl->id }}"
                                    data-name="{{ $cl->name }}"
                                    data-branch="{{ $cl->branch?->code ?? 'BD' }}"
                                    data-branch-id="{{ $cl->branch_id }}"
                                    data-course-id="{{ $cl->course_id }}"
                                    data-schedule="{{ $cl->schedule_text }}"
                                    data-course="{{ $cl->course?->name }}"
                                    data-tuition="{{ (float) ($cl->tuition_fee > 0 ? $cl->tuition_fee : ($cl->course?->tuition_fee ?? 0)) }}"
                                >
                                    {{ $cl->name }} ({{ $cl->code }}) · Cơ sở: {{ $cl->branch?->name }} · Sĩ số: {{ $cl->active_enrollments_count }}/{{ $cl->max_capacity }} · Lịch học: {{ $cl->schedule_text }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="p-4 bg-orange-50/60 rounded-xl border border-orange-200 text-xs text-orange-900 space-y-1">
                        <div class="font-bold flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">info</span>
                            Quy trình bàn giao học vụ tự động:
                        </div>
                        <ul class="list-disc list-inside space-y-0.5 text-gray-600 pl-1 text-[11px]">
                            <li>Tạo hồ sơ học viên chính thức trong phân hệ Học vụ</li>
                            <li>Gán học viên vào danh sách sĩ số lớp đã chọn</li>
                            <li>Tự động kích hoạt tài khoản Cổng Học Sinh / Phụ Huynh</li>
                        </ul>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <button type="button" @click="step = 2" class="px-4 py-2 border border-gray-200 text-xs font-semibold text-gray-700 rounded-xl hover:bg-gray-50 transition">
                        Quay lại
                    </button>
                    <button type="button" @click="step = 4; syncSplitAmounts()" class="px-6 py-2.5 bg-[#ea580c] hover:bg-[#c2410c] text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center gap-1.5">
                        <span>Tiếp tục: Xác nhận &amp; Thu tiền</span>
                        <span class="material-symbols-outlined text-base">arrow_forward</span>
                    </button>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════════════════
                 BƯỚC 4: XÁC NHẬN, CHỌN PHƯƠNG THỨC THANH TOÁN (KẾT HỢP) & VIETQR
                 ═════════════════════════════════════════════════════════════════ -->
            <div x-show="step === 4" class="bg-white rounded-2xl p-6 border border-gray-200 shadow-xs space-y-6">
                <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                    <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-emerald-600 text-base">verified</span>
                        Bước 4: Xác nhận Hợp đồng, Chọn Phương thức Thanh toán &amp; Xuất Phiếu thu
                    </h2>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    <!-- Cột Trái: Tóm tắt hợp đồng & Phương thức thanh toán (7 cols) -->
                    <div class="lg:col-span-7 bg-[#f8fafc] rounded-2xl p-5 border border-gray-200/90 space-y-4 text-xs">
                        <h3 class="font-bold text-gray-900 text-xs uppercase tracking-wider flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-gray-500 text-sm">receipt_long</span>
                            <span>Tóm tắt hợp đồng đào tạo</span>
                        </h3>
                        
                        <div class="space-y-2 pt-1 border-b border-gray-200/70 pb-3">
                            <div class="flex justify-between py-1">
                                <span class="text-gray-500">Học viên:</span>
                                <span class="font-bold text-gray-900" x-text="customerName + ' (' + studentCodePreview + ')'"></span>
                            </div>
                            <div class="flex justify-between py-1">
                                <span class="text-gray-500">Số điện thoại:</span>
                                <span class="font-mono font-semibold text-gray-800" x-text="customerPhone"></span>
                            </div>
                            <div class="flex justify-between py-1">
                                <span class="text-gray-500">Lớp học:</span>
                                <span class="font-bold text-[#ea580c]" x-text="className"></span>
                            </div>
                            <div class="flex justify-between py-1">
                                <span class="text-gray-500">Tổng giá trị hợp đồng:</span>
                                <span class="font-mono font-bold text-gray-900" x-text="formatVND(contractTotal)"></span>
                            </div>
                            <template x-if="feeItems && feeItems.length > 0">
                                <div class="p-2 bg-blue-50/80 border border-blue-100 rounded-xl space-y-1 my-1 text-[11px]">
                                    <div class="font-bold text-blue-900 flex justify-between">
                                        <span>Bao gồm Thu khác:</span>
                                        <span class="font-mono" x-text="formatVND(otherFees)"></span>
                                    </div>
                                    <div class="space-y-0.5 pl-1">
                                        <template x-for="(item, idx) in feeItems" :key="idx">
                                            <div class="flex justify-between text-slate-600">
                                                <span x-text="'• ' + (item.name || 'Mục khác')"></span>
                                                <span class="font-mono font-semibold" x-text="formatVND(item.amount)"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <template x-if="prepaidAmount > 0">
                                <div class="flex justify-between py-1 text-amber-700">
                                    <span>Đã thu trước (Cọc):</span>
                                    <span class="font-mono font-bold" x-text="formatVND(prepaidAmount)"></span>
                                </div>
                            </template>
                            <div class="flex justify-between py-1 text-emerald-700 font-bold">
                                <span>Cần thanh toán đợt 1:</span>
                                <span class="font-mono text-sm" x-text="formatVND(amountDue)"></span>
                            </div>
                        </div>

                        <!-- Số tiền thực thu đợt 1 -->
                        <div>
                            <label class="block text-xs font-bold text-gray-800 mb-1">
                                Số tiền thu thực tế đợt 1 (VNĐ) <span class="text-rose-500">*</span>
                            </label>
                            <input 
                                type="number" 
                                x-model.number="paidAmount" 
                                @input="syncSplitAmounts()"
                                class="w-full text-sm font-mono font-black text-emerald-600 rounded-xl border border-gray-200 p-2.5 focus:border-[#ea580c] focus:ring-[#ea580c] bg-white" 
                            />
                        </div>

                        <!-- Chọn Tài khoản Ngân hàng từ Cấu hình -->
                        <div x-show="needsBankAccount" x-cloak>
                            <label class="block text-xs font-bold text-gray-800 mb-1 flex items-center justify-between">
                                <span>Tài khoản Ngân hàng nhận tiền <span class="text-rose-500">*</span></span>
                                <a href="{{ route('system-config.bank-accounts') }}" target="_blank" class="text-[10px] text-[#ea580c] hover:underline font-normal">Đổi STK trong Admin &rarr;</a>
                            </label>
                            <select 
                                x-model="selectedBankAccountId" 
                                class="w-full text-xs font-semibold rounded-xl border border-gray-200 p-2.5 focus:border-[#ea580c] focus:ring-[#ea580c] bg-white"
                            >
                                <template x-for="bank in bankAccounts" :key="bank.id">
                                    <option :value="bank.id" x-text="bank.bank_name + ' - ' + bank.account_number + ' (' + bank.account_holder + ')'"></option>
                                </template>
                            </select>
                        </div>

                        <!-- CHỌN PHƯƠNG THỨC THANH TOÁN (HỖ TRỢ KẾT HỢP NHIỀU PHƯƠNG THỨC) -->
                        <div class="space-y-3 pt-2 border-t border-gray-200/70">
                            <div>
                                <label class="block text-xs font-bold text-gray-800 mb-1.5">Phương thức thanh toán giao dịch</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border bg-white cursor-pointer transition text-xs font-semibold" :class="paymentMethod === 'transfer' ? 'border-[#ea580c] text-[#ea580c] bg-orange-50/30' : 'border-gray-200 text-gray-700'">
                                        <input type="radio" name="pay_mode" value="transfer" x-model="paymentMethod" class="text-[#ea580c] focus:ring-[#ea580c]" />
                                        <span>Chuyển khoản (VietQR)</span>
                                    </label>

                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border bg-white cursor-pointer transition text-xs font-semibold" :class="paymentMethod === 'cash' ? 'border-[#ea580c] text-[#ea580c] bg-orange-50/30' : 'border-gray-200 text-gray-700'">
                                        <input type="radio" name="pay_mode" value="cash" x-model="paymentMethod" class="text-[#ea580c] focus:ring-[#ea580c]" />
                                        <span>Tiền mặt tại quầy</span>
                                    </label>

                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border bg-white cursor-pointer transition text-xs font-semibold" :class="paymentMethod === 'pos' ? 'border-[#ea580c] text-[#ea580c] bg-orange-50/30' : 'border-gray-200 text-gray-700'">
                                        <input type="radio" name="pay_mode" value="pos" x-model="paymentMethod" class="text-[#ea580c] focus:ring-[#ea580c]" />
                                        <span>Quẹt thẻ máy POS</span>
                                    </label>

                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border bg-white cursor-pointer transition text-xs font-semibold" :class="paymentMethod === 'split' ? 'border-purple-600 text-purple-700 bg-purple-50/40 ring-1 ring-purple-600' : 'border-gray-200 text-gray-700'">
                                        <input type="radio" name="pay_mode" value="split" x-model="paymentMethod" class="text-purple-600 focus:ring-purple-600" />
                                        <span>Kết hợp (Cash + Bank + POS)</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Form chia nhỏ tiền khi chọn phương thức kết hợp -->
                            <div x-show="paymentMethod === 'split'" x-cloak class="p-4 bg-purple-50/70 border border-purple-200 rounded-xl space-y-3">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-purple-900 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm">call_split</span>
                                        Phân bổ số tiền thanh toán kết hợp
                                    </span>
                                    <span class="text-[10px] font-bold" :class="splitDiff === 0 ? 'text-emerald-700' : 'text-rose-600'" x-text="splitDiff === 0 ? '✓ Đã khớp 100%' : 'Chênh lệch: ' + formatVND(splitDiff)"></span>
                                </div>

                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">Tiền mặt (Cash)</label>
                                        <input type="number" x-model.number="splitCash" class="w-full text-xs font-mono font-bold rounded-lg border border-purple-200 p-2 focus:border-purple-600 bg-white" placeholder="0" />
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">Chuyển khoản (QR)</label>
                                        <input type="number" x-model.number="splitTransfer" class="w-full text-xs font-mono font-bold rounded-lg border border-purple-200 p-2 focus:border-purple-600 bg-white text-[#ea580c]" placeholder="0" />
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">Quẹt thẻ POS</label>
                                        <input type="number" x-model.number="splitPos" class="w-full text-xs font-mono font-bold rounded-lg border border-purple-200 p-2 focus:border-purple-600 bg-white text-blue-600" placeholder="0" />
                                    </div>
                                </div>
                                <p class="text-[10px] text-gray-500">Mã VietQR bên cạnh sẽ tự động sinh theo đúng số tiền chuyển khoản (<strong x-text="formatVND(effectiveTransferAmount)"></strong>).</p>
                            </div>
                        </div>

                        <!-- Ghi chú hóa đơn -->
                        <div>
                            <label class="block text-xs font-bold text-gray-800 mb-1">Ghi chú trên Phiếu thu / Hóa đơn</label>
                            <input type="text" name="bill_notes" x-model="billNotes" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-[#ea580c] focus:ring-[#ea580c] bg-white" placeholder="Ghi chú thêm về học viên, phụ huynh hoặc cam kết..." />
                        </div>
                    </div>

                    <!-- Cột Phải: VietQR Code Box & Cấu trúc Nội dung Chuyển tiền (5 cols) -->
                    <div class="lg:col-span-5 bg-white rounded-2xl p-5 border border-orange-200/80 shadow-xs space-y-4 text-center flex flex-col items-center">
                        <div x-show="needsBankAccount" class="w-full flex items-center justify-between pb-2 border-b border-gray-100">
                            <div class="text-xs font-black text-gray-900 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[#ea580c] text-lg">qr_code_scanner</span>
                                <span>MÃ VIETQR CHUYỂN KHOẢN</span>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                Chuẩn NAPAS 247
                            </span>
                        </div>

                        <!-- VietQR Image with Dynamic Amount & Bank Details -->
                        <div x-show="needsBankAccount" class="relative bg-white p-2.5 rounded-2xl border-2 border-[#ea580c]/20 shadow-md group">
                            <img 
                                :src="vietQrUrl" 
                                alt="Mã VietQR Chuyển khoản" 
                                class="w-48 h-48 sm:w-52 sm:h-52 object-contain rounded-lg"
                                loading="lazy"
                            />
                        </div>

                        <!-- Bank & Memo Details with Copy Buttons -->
                        <div x-show="needsBankAccount" class="w-full bg-gray-50/80 rounded-xl p-3 text-left space-y-2 text-[11px] border border-gray-200/80">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-500">Ngân hàng:</span>
                                <span class="font-bold text-gray-900 text-right truncate max-w-[170px]" x-text="selectedBank.bank_name"></span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span class="text-gray-500">Số tài khoản:</span>
                                <div class="flex items-center gap-1">
                                    <span class="font-mono font-black text-gray-900" x-text="selectedBank.account_number"></span>
                                    <button 
                                        type="button" 
                                        @click="copyText(cleanAccountNumber, 'acc')" 
                                        class="p-0.5 rounded hover:bg-gray-200 text-gray-500 hover:text-gray-800 transition"
                                        title="Sao chép STK"
                                    >
                                        <span class="material-symbols-outlined text-sm" x-text="copiedField === 'acc' ? 'check' : 'content_copy'"></span>
                                    </button>
                                </div>
                            </div>

                            <div class="flex justify-between items-center">
                                <span class="text-gray-500">Chủ tài khoản:</span>
                                <span class="font-bold text-gray-900 text-right uppercase text-[10px] truncate max-w-[170px]" x-text="selectedBank.account_holder"></span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span class="text-gray-500">Số tiền QR:</span>
                                <span class="font-mono font-black text-[#ea580c] text-xs" x-text="formatVND(effectiveTransferAmount)"></span>
                            </div>

                            <!-- NỘI DUNG CHUYỂN KHOẢN THEO CẤU TRÚC: Mã hs + ten học sinh + tenlop + CN + xxx -->
                            <div class="pt-1.5 border-t border-gray-200/80 space-y-1">
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700 font-bold">Nội dung CK (Cấu trúc chuẩn):</span>
                                    <button 
                                        type="button" 
                                        @click="copyText(transferMemo, 'memo')" 
                                        class="text-xs text-[#ea580c] hover:text-[#c2410c] font-bold inline-flex items-center gap-0.5"
                                        title="Sao chép nội dung CK"
                                    >
                                        <span class="material-symbols-outlined text-sm" x-text="copiedField === 'memo' ? 'check' : 'content_copy'"></span>
                                        <span x-text="copiedField === 'memo' ? 'Đã chép' : 'Chép'"></span>
                                    </button>
                                </div>
                                <div class="p-2 bg-orange-50/80 border border-orange-200 rounded-lg font-mono font-bold text-xs text-orange-900 break-all select-all text-left" x-text="transferMemo"></div>
                                <p class="text-[10px] text-gray-400">Nội dung <strong>dự kiến</strong> — khi chốt, hệ thống sinh lại theo mã học viên thật (HV-...) trên phiếu thu VietQR.</p>
                            </div>
                        </div>

                        <!-- Action Buttons: Tải QR & Xem Mẫu Bill -->
                        <div class="w-full flex items-center gap-2">
                            <button
                                x-show="needsBankAccount"
                                type="button" 
                                @click="downloadVietQr()"
                                class="flex-1 py-2 px-3 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold rounded-xl transition inline-flex items-center justify-center gap-1 border border-gray-200"
                            >
                                <span class="material-symbols-outlined text-base">download</span>
                                <span>Tải mã QR</span>
                            </button>

                            <div x-show="!needsBankAccount" class="flex-1 py-2 px-3 bg-blue-50 text-blue-700 text-xs font-bold rounded-xl border border-blue-200">
                                Không phát sinh VietQR cho phương thức này
                            </div>

                            <!-- Nút Xem & In Thông báo nộp học phí -->
                            <button 
                                type="button" 
                                @click="openBillModal()" 
                                class="flex-1 py-2 px-3 bg-[#ea580c] hover:bg-[#c2410c] text-white text-xs font-bold rounded-xl shadow-xs transition inline-flex items-center justify-center gap-1"
                            >
                                <span class="material-symbols-outlined text-base">print</span>
                                <span>Xem &amp; In Bill</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Footer Bước 4 -->
                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <button type="button" @click="step = 3" class="px-4 py-2 border border-gray-200 text-xs font-semibold text-gray-700 rounded-xl hover:bg-gray-50 transition">
                        Quay lại
                    </button>
                    <button type="submit" :disabled="!customerId || !classId || (needsBankAccount && !selectedBankAccountId) || (paymentMethod === 'split' && splitDiff !== 0)" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-xs font-bold rounded-xl shadow-md transition flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">check_circle</span>
                        <span>Hoàn tất Chốt Deal, Xếp Lớp &amp; Xuất Phiếu Thu</span>
                    </button>
                </div>
            </div>
        </form>

        <!-- ═════════════════════════════════════════════════════════════════
             MODAL: TẠO MỚI ƯU ĐÃI (BỔ SUNG ƯU ĐÃI NHANH TẠI CHỖ)
             ═════════════════════════════════════════════════════════════════ -->
        <div x-show="showCreatePromoModal" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 backdrop-blur-xs">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
                <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                    <h3 class="font-bold text-sm text-gray-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[#ea580c] text-lg">card_giftcard</span>
                        <span>Tạo Mới Chương Trình Ưu Đãi / Voucher</span>
                    </h3>
                    <button type="button" @click="showCreatePromoModal = false" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <div class="space-y-3.5">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Tên chương trình ưu đãi <span class="text-rose-500">*</span></label>
                        <input type="text" x-model="newPromo.name" placeholder="Voucher khai giảng / Ưu đãi bạn mới" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold focus:border-[#ea580c] focus:ring-[#ea580c]" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Loại giảm giá</label>
                            <select x-model="newPromo.type" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-[#ea580c] focus:ring-[#ea580c]">
                                <option value="fixed">Số tiền cố định (VNĐ)</option>
                                <option value="percent">Phần trăm (%)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Giá trị <span class="text-rose-500">*</span></label>
                            <input type="number" x-model.number="newPromo.value" placeholder="1000000 hoặc 10" class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5 focus:border-[#ea580c] focus:ring-[#ea580c]" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Mô tả / Điều kiện áp dụng</label>
                        <textarea x-model="newPromo.description" rows="2" placeholder="Áp dụng cho học viên đăng ký sớm..." class="w-full text-xs rounded-xl border border-gray-200 p-2 focus:border-[#ea580c] focus:ring-[#ea580c]"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <select x-model="newPromo.branch_id" class="w-full text-xs rounded-xl border-gray-200">
                            <option value="">Mọi cơ sở</option>
                            @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                        </select>
                        <select x-model="newPromo.course_id" class="w-full text-xs rounded-xl border-gray-200">
                            <option value="">Mọi khóa học</option>
                            @foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <input type="datetime-local" x-model="newPromo.starts_at" class="w-full text-xs rounded-xl border-gray-200" title="Bắt đầu" />
                        <input type="datetime-local" x-model="newPromo.ends_at" class="w-full text-xs rounded-xl border-gray-200" title="Kết thúc" />
                        <input type="number" min="1" x-model.number="newPromo.usage_limit" placeholder="Lượt dùng" class="w-full text-xs rounded-xl border-gray-200" />
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                        <button type="button" @click="showCreatePromoModal = false" class="px-4 py-2 rounded-xl border text-xs font-semibold text-gray-600 hover:bg-gray-50">Hủy</button>
                        <button type="button" @click="saveNewPromotion()" class="px-5 py-2 bg-[#ea580c] hover:bg-[#c2410c] text-white text-xs font-bold rounded-xl shadow-xs transition">Lưu &amp; Áp Dụng Ngay</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═════════════════════════════════════════════════════════════════
             MODAL: XEM TRƯỚC VÀ IN THÔNG BÁO NỘP HỌC PHÍ (BILL GIAO DỊCH)
             ═════════════════════════════════════════════════════════════════ -->
        <div x-show="showBillModal" x-cloak class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-3 sm:p-6 backdrop-blur-xs overflow-y-auto">
            <div class="bg-white rounded-2xl max-w-4xl w-full max-h-[95vh] flex flex-col shadow-2xl overflow-hidden">
                <!-- Modal Header -->
                <div class="p-4 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#ea580c]">print</span>
                        <h3 class="font-bold text-sm text-gray-900">Xem trước Thông Báo Nộp Học Phí (Chuẩn A4)</h3>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="printBill()" class="px-3.5 py-1.5 bg-[#ea580c] text-white rounded-lg text-xs font-bold shadow-xs hover:bg-[#c2410c] flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">print</span>
                            <span>In Ngay</span>
                        </button>
                        <button type="button" @click="showBillModal = false" class="p-1 text-gray-400 hover:text-gray-600 rounded-lg">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                <!-- Modal Body: Exact User Bill Template -->
                <div class="p-6 overflow-y-auto flex-1 bg-white text-gray-900" id="printableBillArea">
                    <div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.4;">
                        <!-- HEADER -->
                        <div style="display: flex; align-items: flex-start; margin-bottom: 20px;">
                            <div style="width: 65px; margin-right: 18px; flex-shrink: 0;">
                                <div style="width: 60px; height: 60px; border-radius: 10px; background: linear-gradient(135deg, #ea580c, #c2410c); display: flex; align-items: center; justify-content: center; color: white; font-weight: 900; font-size: 24px;">
                                    M
                                </div>
                            </div>
                            <div>
                                <div style="font-size: 16px; font-weight: 700; margin-bottom: 4px; color: #c2410c;">
                                    MENGLISH - MEDUCATION
                                </div>
                                <div style="line-height: 1.5; font-size: 13px; color: #333;">
                                    <div>Địa chỉ: CS1: 15/172 Phố Ngọc Hà - Ba Đình</div>
                                    <div>CS2: 23/209 Phố Đội Cấn - Ba Đình</div>
                                    <div>CS3: 24/55 Hoàng Hoa Thám - Ba Đình</div>
                                    <div>Điện thoại: 0975996986</div>
                                </div>
                            </div>
                        </div>

                        <!-- TITLE -->
                        <div style="text-align: center; margin: 10px 0 16px 0;">
                            <h2 style="margin: 0; font-size: 21px; font-weight: 700; color: #111;">THÔNG BÁO NỘP HỌC PHÍ</h2>
                            <div style="margin-top: 4px; font-size: 13px; color: #666;">
                                Ngày {{ date('d') }} tháng {{ date('m') }} năm {{ date('Y') }}
                            </div>
                        </div>

                        <!-- INFORMATION TABLE -->
                        <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 30%; font-weight: 700; background: #fafafa;">Họ tên:</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 70%;">
                                    <strong x-text="customerName"></strong>
                                    <strong style="margin-left: 20px; color: #ea580c;" x-text="'Mã: ' + studentCodePreview"></strong>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;">Lớp :</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px;" x-text="className"></td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;">Thời gian học:</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px;">Từ ngày: {{ date('01/m/Y') }} đến {{ \Carbon\Carbon::now()->addMonths(3)->format('d/m/Y') }}</td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;">Ca học:</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px;">24 buổi / khóa học</td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;">Học phí:</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px;" x-text="formatVND(baseTuition)"></td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;">Ưu đãi:</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; color: #16a34a; font-weight: bold;" x-text="formatVND(discount)"></td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;">Thu khác:</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px;">
                                    <div style="font-weight: 700;" x-text="formatVND(otherFees)"></div>
                                    <template x-if="feeItems && feeItems.length > 0">
                                        <div style="margin-top: 6px; padding: 6px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px;">
                                            <div style="font-weight: 700; color: #475569; margin-bottom: 2px; font-size: 11px; text-transform: uppercase;">Chi tiết các khoản thu khác:</div>
                                            <template x-for="(item, idx) in feeItems" :key="idx">
                                                <div style="display: flex; justify-content: space-between; padding: 2px 0; color: #334155;">
                                                    <span x-text="'• ' + (item.name || 'Mục khác')"></span>
                                                    <span style="font-family: monospace; font-weight: 600; color: #0284c7;" x-text="formatVND(item.amount)"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </td>
                            </tr>
                            <template x-if="prepaidAmount > 0">
                                <tr>
                                    <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;">Thu trước (Đã cọc):</td>
                                    <td style="border: 1px solid #d5d5d5; padding: 8px 10px; color: #2563eb; font-weight: bold;" x-text="'- ' + formatVND(prepaidAmount)"></td>
                                </tr>
                            </template>
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;">Thành tiền:</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; font-size: 15px; color: #ea580c;" x-text="formatVND(amountDue)"></td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;">Ghi chú:</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px;" x-text="billNotes || 'Học viên hoàn thành thủ tục nhập học theo quy định của trung tâm.'"></td>
                            </tr>
                        </table>

                        <!-- PAYMENT NOTE -->
                        <div style="margin: 10px 0 8px 0; font-size: 13px; font-style: italic; color: #333;" x-text="needsBankAccount ? 'Thông tin chuyển khoản của giao dịch:' : 'Giao dịch được ghi nhận theo phương thức tiền mặt/POS, không phát sinh VietQR.'">
                        </div>

                        <div x-show="needsBankAccount">
                        <!-- BANK TABLE -->
                        <table style="width: 100%; border-collapse: collapse; margin-top: 6px;">
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 32%; font-weight: 700; background: #fafafa;">Chủ tài khoản</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 25%; font-weight: 700; font-family: monospace;">Số tài khoản</td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 43%;">Ngân hàng</td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px;" x-text="selectedBank.account_holder"></td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-family: monospace; font-weight: 700;" x-text="selectedBank.account_number"></td>
                                <td style="border: 1px solid #d5d5d5; padding: 8px 10px;" x-text="selectedBank.bank_name"></td>
                            </tr>
                        </table>

                        <!-- TRANSFER CONTENT -->
                        <div style="margin-top: 10px; font-size: 14px; padding: 8px 12px; background: #fff7ed; border: 1px solid #ffedd5; border-radius: 6px;">
                            <strong>Nội dung chuyển tiền :</strong>
                            <strong style="color: #c2410c; margin-left: 6px;" x-text="transferMemo"></strong>
                        </div>

                        <!-- COMPANY NOTE -->
                        <div style="margin-top: 10px; font-size: 13px; line-height: 1.4;">
                            <strong>Ghi chú:</strong>
                            <div>Tk công ty. Quý phụ huynh vui lòng giữ nguyên nội dung chuyển tiền để hệ thống tự động ghi nhận gạch nợ.</div>
                        </div>

                        <!-- QR CODE -->
                        <div style="margin-top: 20px; display: flex; align-items: center; gap: 16px; padding: 12px; border: 1px dashed #fdba74; border-radius: 10px; background: #fffaf5; width: fit-content;">
                            <img :src="vietQrUrl" alt="Mã QR thanh toán" style="width: 150px; height: 150px; object-fit: contain; background: white; padding: 4px; border-radius: 6px;" />
                            <div style="font-size: 12px; line-height: 1.5; color: #475569;">
                                <h4 style="margin: 0 0 4px 0; color: #ea580c; font-size: 13px;">Quét mã VietQR chuyển khoản nhanh 24/7</h4>
                                <div>Mở ứng dụng ngân hàng bất kỳ để quét mã.</div>
                                <div>Số tiền và nội dung đã được điền sẵn chính xác 100%.</div>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function closingWizard() {
            return {
                step: 1,
                customerId: @js($customers->first()?->id ?? ''),
                customerName: @js($customers->first()?->name ?? ''),
                customerPhone: @js($customers->first()?->phone ?? ''),
                customerBranch: @js($customers->first()?->branch?->code ?? 'BD'),
                studentCodePreview: 'HS' + String({{ \App\Models\Student::count() + 1 }}).padStart(6, '0'),
                courseName: @js($classes->first()?->course?->name ?? ''),
                classId: @js($classes->first()?->id ?? ''),
                className: @js($classes->first()?->name ?? '4M2 T3T6'),
                classBranch: @js($classes->first()?->branch?->code ?? 'BD'),
                classBranchId: @js($classes->first()?->branch_id ?? ''),
                courseId: @js($classes->first()?->course_id ?? ''),

                // Financial fields
                baseTuition: {{ (float) (($classes->first()?->tuition_fee ?? 0) > 0 ? $classes->first()?->tuition_fee : ($classes->first()?->course?->tuition_fee ?? 0)) }},
                discount: 0,
                otherFees: 0,
                feeItems: [],
                prepaidAmount: 0,
                paidAmount: {{ (float) (($classes->first()?->tuition_fee ?? 0) > 0 ? $classes->first()?->tuition_fee : ($classes->first()?->course?->tuition_fee ?? 0)) }},
                
                // Promotions
                promotionsList: @json($promotions),
                selectedPromotionId: '',
                showCreatePromoModal: false,
                newPromo: {
                    name: '',
                    type: 'fixed',
                    value: 0,
                    description: '',
                    branch_id: '',
                    course_id: '',
                    starts_at: '',
                    ends_at: '',
                    usage_limit: '',
                },

                // Payment methods
                paymentMethod: 'transfer',
                splitCash: 0,
                splitTransfer: 0,
                splitPos: 0,
                billNotes: '',

                // Banks & VietQR
                bankAccounts: @json($bankAccounts),
                selectedBankAccountId: '{{ $bankAccounts->where('is_default_vietqr', true)->first()?->id ?? $bankAccounts->first()?->id ?? '' }}',
                copiedField: '',
                showBillModal: false,

                // Computed: Tổng thành tiền hợp đồng = Học phí - Giảm trừ + Thu khác
                get contractTotal() {
                    return Math.max(0, (this.baseTuition || 0) - (this.discount || 0) + (this.otherFees || 0));
                },

                // Computed: Số tiền thực tế cần thanh toán sau khi trừ thu trước (cọc)
                get amountDue() {
                    return Math.max(0, this.contractTotal - (this.prepaidAmount || 0));
                },

                // Computed: Số tiền chuyển khoản thực tế dùng để sinh VietQR
                get effectiveTransferAmount() {
                    if (this.paymentMethod === 'split') {
                        return Math.max(0, parseInt(this.splitTransfer || 0));
                    }
                    if (this.paymentMethod === 'transfer') {
                        return Math.max(0, parseInt(this.paidAmount || 0));
                    }
                    return 0;
                },

                get needsBankAccount() {
                    return this.paymentMethod === 'transfer'
                        || (this.paymentMethod === 'split' && Number(this.splitTransfer || 0) > 0);
                },

                get availablePromotions() {
                    return this.promotionsList.filter((promotion) =>
                        (!promotion.branch_id || String(promotion.branch_id) === String(this.classBranchId))
                        && (!promotion.course_id || String(promotion.course_id) === String(this.courseId))
                    );
                },

                // Computed: Chênh lệch chia tiền split
                get splitDiff() {
                    const totalSplit = (this.splitCash || 0) + (this.splitTransfer || 0) + (this.splitPos || 0);
                    return totalSplit - (this.paidAmount || 0);
                },

                // Selected Bank Object
                get selectedBank() {
                    return this.bankAccounts.find((b) => String(b.id) === String(this.selectedBankAccountId)) 
                        || this.bankAccounts[0] 
                        || { bank_code: '', bank_name: '', account_number: '', account_holder: '' };
                },

                get cleanAccountNumber() {
                    return (this.selectedBank.account_number || '').replace(/\s+/g, '');
                },

                // Cấu trúc nội dung CK: Mã hs + ten học sinh + tenlop + CN + xxx
                get transferMemo() {
                    const code = (this.studentCodePreview || 'HS000001').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    
                    // Chuyển tên học sinh sang không dấu, viết liền
                    let name = this.removeVietnameseTones(this.customerName || 'HOCVIEN').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    
                    // Lớp: lấy tên ngắn không dấu
                    let cls = this.removeVietnameseTones(this.className || 'LOP').toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 7);
                    
                    // Mã chi nhánh
                    let cn = (this.classBranch || this.customerBranch || 'BD').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    if (!cn.startsWith('CN')) {
                        cn = 'CN' + cn;
                    }

                    // Đuôi 3 số nhận diện
                    const tail = (this.customerPhone ? this.customerPhone.slice(-3) : '888').replace(/[^0-9]/g, '');

                    return `${code} ${name} ${cls} ${cn} ${tail}`;
                },

                get vietQrUrl() {
                    if (!this.needsBankAccount || this.effectiveTransferAmount <= 0 || !this.selectedBank.account_number || !this.selectedBank.bank_code) return '';
                    const bankCode = encodeURIComponent(this.selectedBank.bank_code);
                    const accNum = encodeURIComponent(this.cleanAccountNumber);
                    const amount = this.effectiveTransferAmount;
                    const memo = encodeURIComponent(this.transferMemo);
                    const accName = encodeURIComponent(this.selectedBank.account_holder || '');
                    return `https://img.vietqr.io/image/${bankCode}-${accNum}-compact2.png?amount=${amount}&addInfo=${memo}&accountName=${accName}`;
                },

                formatVND(num) {
                    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(num || 0);
                },

                removeVietnameseTones(str) {
                    str = str.replace(/à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ/g, "a");
                    str = str.replace(/è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ/g, "e");
                    str = str.replace(/ì|í|ị|ỉ|ĩ/g, "i");
                    str = str.replace(/ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ/g, "o");
                    str = str.replace(/ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ/g, "u");
                    str = str.replace(/ỳ|ý|ỵ|ỷ|ỹ/g, "y");
                    str = str.replace(/đ/g, "d");
                    str = str.replace(/À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ/g, "A");
                    str = str.replace(/È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ/g, "E");
                    str = str.replace(/Ì|Í|Ị|Ỉ|Ĩ/g, "I");
                    str = str.replace(/Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ/g, "O");
                    str = str.replace(/Ù|Ú|Ụ|Ủ|Ũ|Ư|Ừ|Ứ|Ự|Ử|Ữ/g, "U");
                    str = str.replace(/Ỳ|Ý|Ỵ|Ỷ|Ỹ/g, "Y");
                    str = str.replace(/Đ/g, "D");
                    return str;
                },

                syncSplitAmounts() {
                    if (this.paymentMethod === 'split') {
                        // Nếu chưa chia, mặc định chia 50% tiền mặt, 50% chuyển khoản
                        if (this.splitCash === 0 && this.splitTransfer === 0 && this.splitPos === 0) {
                            const half = Math.floor(this.paidAmount / 2);
                            this.splitCash = half;
                            this.splitTransfer = this.paidAmount - half;
                            this.splitPos = 0;
                        }
                    }
                },

                addPresetItem(id, name, amount) {
                    if (this.feeItems.some((item) => Number(item.id) === Number(id))) return;
                    this.feeItems.push({ id: id, name: name, amount: parseInt(amount) });
                    this.recalculateOtherFees();
                },

                removeItem(idx) {
                    this.feeItems.splice(idx, 1);
                    this.recalculateOtherFees();
                },

                recalculateOtherFees() {
                    this.otherFees = this.feeItems.reduce((acc, item) => acc + (parseFloat(item.amount) || 0), 0);
                    this.paidAmount = this.amountDue;
                },

                applyPromotion(e) {
                    const promoId = e.target.value;
                    if (!promoId) {
                        this.discount = 0;
                        this.paidAmount = this.amountDue;
                        return;
                    }
                    const promo = this.promotionsList.find((p) => String(p.id) === String(promoId));
                    if (promo) {
                        if (promo.type === 'percent') {
                            let disc = (this.baseTuition * promo.value) / 100;
                            if (promo.max_discount_amount && disc > promo.max_discount_amount) {
                                disc = promo.max_discount_amount;
                            }
                            this.discount = Math.round(disc);
                        } else {
                            this.discount = Math.min(this.baseTuition, parseFloat(promo.value));
                        }
                        this.paidAmount = this.amountDue;
                    }
                },

                saveNewPromotion() {
                    if (!this.newPromo.name || !this.newPromo.value) {
                        alert('Vui lòng nhập tên chương trình ưu đãi và giá trị giảm!');
                        return;
                    }

                    fetch('/crm/promotions/store', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(this.newPromo)
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.promotion) {
                            this.promotionsList.push(data.promotion);
                            this.selectedPromotionId = data.promotion.id;

                            // Tự động tính ưu đãi (giới hạn bởi max_discount_amount như phía server)
                            if (data.promotion.type === 'percent') {
                                let disc = (this.baseTuition * data.promotion.value) / 100;
                                if (data.promotion.max_discount_amount && disc > parseFloat(data.promotion.max_discount_amount)) {
                                    disc = parseFloat(data.promotion.max_discount_amount);
                                }
                                this.discount = Math.round(disc);
                            } else {
                                this.discount = Math.min(this.baseTuition, parseFloat(data.promotion.value));
                            }
                            this.paidAmount = this.amountDue;
                            this.showCreatePromoModal = false;
                            this.newPromo = { name: '', type: 'fixed', value: 0, description: '', branch_id: '', course_id: '', starts_at: '', ends_at: '', usage_limit: '' };
                            alert('Đã tạo và áp dụng ưu đãi "' + data.promotion.name + '" thành công!');
                        } else {
                            alert('Có lỗi xảy ra khi tạo ưu đãi.');
                        }
                    })
                    .catch(err => {
                        alert('Lỗi kết nối máy chủ: ' + err.message);
                    });
                },

                copyText(text, fieldName) {
                    navigator.clipboard.writeText(text).then(() => {
                        this.copiedField = fieldName;
                        setTimeout(() => {
                            if (this.copiedField === fieldName) this.copiedField = '';
                        }, 2000);
                    });
                },

                downloadVietQr() {
                    const url = this.vietQrUrl;
                    if (!url) return;
                    const filename = `VietQR_${this.customerPhone || 'HocVien'}_${this.effectiveTransferAmount}d.png`;
                    fetch(url)
                        .then(res => res.blob())
                        .then(blob => {
                            const blobUrl = window.URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = blobUrl;
                            a.download = filename;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            window.URL.revokeObjectURL(blobUrl);
                        })
                        .catch(() => window.open(url, '_blank'));
                },

                openBillModal() {
                    this.showBillModal = true;
                },

                printBill() {
                    const printContent = document.getElementById('printableBillArea').innerHTML;
                    const printWindow = window.open('', '_blank');
                    printWindow.document.write(`
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <title>In Thông Báo Nộp Học Phí</title>
                            <style>
                                @page { size: A4 portrait; margin: 10mm; }
                                body { font-family: Arial, sans-serif; padding: 20px; }
                                table { width: 100%; border-collapse: collapse; }
                            </style>
                        </head>
                        <body>
                            ${printContent}
                        </body>
                        </html>
                    `);
                    printWindow.document.close();
                    printWindow.focus();
                    setTimeout(() => {
                        printWindow.print();
                        printWindow.close();
                    }, 400);
                },

                updateCustomer(e) {
                    const opt = e.target.options[e.target.selectedIndex];
                    this.customerId = opt.value;
                    this.customerName = opt.getAttribute('data-name');
                    this.customerPhone = opt.getAttribute('data-phone');
                    this.customerBranch = opt.getAttribute('data-branch') || 'BD';
                    this.paidAmount = this.amountDue;
                },
                
                updateClass(e) {
                    const opt = e.target.options[e.target.selectedIndex];
                    this.classId = opt.value;
                    this.className = opt.getAttribute('data-name') || opt.text;
                    this.classBranch = opt.getAttribute('data-branch') || 'BD';
                    this.classBranchId = opt.getAttribute('data-branch-id') || '';
                    this.courseId = opt.getAttribute('data-course-id') || '';
                    this.courseName = opt.getAttribute('data-course') || '';
                    if (!this.availablePromotions.some((promotion) => String(promotion.id) === String(this.selectedPromotionId))) {
                        this.selectedPromotionId = '';
                        this.discount = 0;
                    }
                    this.baseTuition = parseFloat(opt.getAttribute('data-tuition') || 0);
                    this.applyPromotion({ target: { value: this.selectedPromotionId } });
                    this.paidAmount = this.amountDue;
                }
            };
        }
    </script>
</x-app-layout>
