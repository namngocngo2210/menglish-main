<x-app-layout>
    <x-ui.page-header title="Quy trình Chốt & Xếp lớp" description="Chốt khách → tạo học viên, tài khoản, học phí → xếp lớp (hoặc Chờ xếp lớp) → thu phí đăng ký" :back="route('crm.pipeline')">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="account_balance" :href="route('system-config.bank-accounts')" target="_blank" title="Cài đặt tài khoản ngân hàng thụ hưởng & SePay">Cài đặt STK &amp; SePay</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="max-w-4xl mx-auto space-y-6" x-data="closingWizard()">
        @if ($errors->any())
            <x-ui.alert type="error" title="Không thể hoàn tất chốt khách:">
                <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif
        @if ($bankAccounts->isEmpty())
            <x-ui.alert type="warning" class="font-semibold">
                Chưa có tài khoản ngân hàng hoạt động. Bạn vẫn có thể thu tiền mặt/POS; chuyển khoản sẽ cần cấu hình tài khoản trước.
            </x-ui.alert>
        @endif
        @if ($customers->isEmpty())
            <x-ui.alert type="info" class="font-semibold">
                Chưa có khách nào sẵn sàng chốt (Đang tư vấn, Đã test hoặc Gửi kết quả).
            </x-ui.alert>
        @endif
        @if ($classes->isEmpty())
            <x-ui.alert type="warning" class="font-semibold">
                Không còn lớp đang học / sắp khai giảng nào còn chỗ. Bạn vẫn chốt được với "Xếp lớp sau" — học viên vào danh sách Chờ xếp lớp.
            </x-ui.alert>
        @endif
        {{-- Wizard Step Indicator --}}
        <div class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
            <div class="flex items-center gap-3 cursor-pointer" @click="step = 1">
                <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs" :class="step >= 1 ? 'bg-primary-container text-white' : 'bg-surface-container text-on-surface-variant'">1</div>
                <div class="hidden sm:block text-left">
                    <div class="text-xs font-bold text-on-surface">Xác nhận Chốt</div>
                    <div class="text-[10px] text-on-surface-variant/70">Khách &amp; khóa đăng ký</div>
                </div>
            </div>
            <div class="h-0.5 w-12 bg-surface-container-high"></div>

            <div class="flex items-center gap-3 cursor-pointer" @click="step = 2">
                <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs" :class="step >= 2 ? 'bg-primary-container text-white' : 'bg-surface-container text-on-surface-variant'">2</div>
                <div class="hidden sm:block text-left">
                    <div class="text-xs font-bold text-on-surface">Học phí &amp; Ưu đãi</div>
                    <div class="text-[10px] text-on-surface-variant/70">Thu trước &amp; Thu khác</div>
                </div>
            </div>
            <div class="h-0.5 w-12 bg-surface-container-high"></div>

            <div class="flex items-center gap-3 cursor-pointer" @click="step = 3">
                <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs" :class="step >= 3 ? 'bg-primary-container text-white' : 'bg-surface-container text-on-surface-variant'">3</div>
                <div class="hidden sm:block text-left">
                    <div class="text-xs font-bold text-on-surface">Danh sách lớp</div>
                    <div class="text-[10px] text-on-surface-variant/70">Lớp đề xuất / Xếp lớp sau</div>
                </div>
            </div>
            <div class="h-0.5 w-12 bg-surface-container-high"></div>

            <div class="flex items-center gap-3 cursor-pointer" @click="step = 4">
                <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs" :class="step >= 4 ? 'bg-tertiary text-white' : 'bg-surface-container text-on-surface-variant'">4</div>
                <div class="hidden sm:block text-left">
                    <div class="text-xs font-bold text-on-surface">Chốt &amp; Thu phí</div>
                    <div class="text-[10px] text-on-surface-variant/70">VietQR, Quẹt thẻ &amp; Bill</div>
                </div>
            </div>
        </div>

        <form action="{{ route('crm.closing-wizard.store') }}" method="POST">
            @csrf
            {{-- Hidden Form Inputs for Backend Submission --}}
            <input type="hidden" name="customer_id" :value="customerId" />
            <input type="hidden" name="class_id" :value="assignLater ? '' : classId" />
            <input type="hidden" name="course_id" :value="courseId" />
            <input type="hidden" name="fee_paid_at_closing" :value="feePaid ? 1 : 0" />
            <input type="hidden" name="course_name" :value="courseName" />
            <input type="hidden" name="base_tuition" :value="baseTuition" />
            <input type="hidden" name="discount" :value="discount" />
            <input type="hidden" name="promotion_id" :value="selectedPromotionId" />
            <input type="hidden" name="other_fees" :value="otherFees" />
            <input type="hidden" name="fee_items" :value="JSON.stringify(feeItems)" />
            <input type="hidden" name="prepaid_amount" :value="prepaidAmount" />
            <input type="hidden" name="paid_amount" :value="feePaid ? paidAmount : 0" />
            <input type="hidden" name="payment_method" :value="paymentMethod" />
            <input type="hidden" name="split_cash_amount" :value="splitCash" />
            <input type="hidden" name="split_transfer_amount" :value="splitTransfer" />
            <input type="hidden" name="split_pos_amount" :value="splitPos" />
            <input type="hidden" name="bank_account_id" :value="selectedBankAccountId" />
            {{-- transfer_memo được server sinh từ mã học viên thật sau khi tạo hồ sơ, không lấy từ client --}}

            {{-- ═════════════════════════════════════════════════════════════════
                 BƯỚC 1: CHỌN KHÁCH HÀNG LEAD
                 ═════════════════════════════════════════════════════════════════ --}}
            <div x-show="step === 1" class="space-y-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="flex items-center gap-sm border-b border-surface-container-highest pb-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary-container text-base">person_search</span>
                    Bước 1: Xác nhận Chốt khách
                </h2>

                <div class="space-y-4">
                    <x-ui.field label="Khách cần chốt" for="closing_customer" :required="true">
                        <x-ui.select id="closing_customer" class="font-bold" x-on:change="updateCustomer($event)">
                            @foreach ($customers as $c)
                                <option
                                    value="{{ $c->id }}" 
                                    @selected($loop->first)
                                    data-name="{{ $c->name }}" 
                                    data-phone="{{ $c->phone }}" 
                                    data-parent="{{ $c->parent_name ?? '' }}"
                                    data-code="{{ $c->code }}"
                                    data-branch="{{ $c->branch?->code ?? 'BD' }}"
                                    data-branch-id="{{ $c->branch_id }}"
                                    data-stage="{{ $c->stage_label }}"
                                    data-level="{{ $c->level_label }}"
                                    data-level-keys="{{ implode('|', $c->level_keys) }}"
                                >
                                    {{ $c->name }} ({{ $c->code }} - {{ $c->phone }}) · {{ $c->course_interest ?? 'Chưa chọn khóa' }} · {{ $c->stage_label }}
                                </option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    {{-- Thẻ khách theo mockup: tên, trạng thái học phí, SĐT, giai đoạn, trình độ --}}
                    <div class="rounded-xl border border-surface-container-highest bg-surface-container-low p-md" x-show="customerId" data-customer-summary>
                        <div class="flex items-start gap-md">
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary-fixed text-primary">
                                <span class="material-symbols-outlined">person</span>
                            </div>
                            <div class="min-w-0 flex-1 space-y-xs">
                                <div class="flex flex-wrap items-center justify-between gap-sm">
                                    <h2 class="font-h2 text-h2 text-on-surface" x-text="customerName"></h2>
                                    {{-- Trạng thái thật ở bước này: khách chưa đóng học phí (thu / hẹn thu được chọn ở Bước 4). --}}
                                    <span class="inline-flex items-center gap-xs rounded-full border border-outline-variant bg-surface-container-lowest px-sm py-0.5 font-body-small text-body-small text-on-surface-variant" data-fee-status>
                                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">schedule</span>Chưa đóng học phí đăng ký
                                    </span>
                                </div>
                                <p class="flex flex-wrap items-center gap-sm font-body-small text-body-small text-on-surface-variant">
                                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">phone</span><span class="font-code" x-text="customerPhone"></span></span>
                                    <span>|</span>
                                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">analytics</span>Giai đoạn: <span x-text="customerStage"></span></span>
                                    <span>|</span>
                                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">school</span>Trình độ: <span x-text="customerLevel || 'Chưa có kết quả test'"></span></span>
                                </p>
                            </div>
                        </div>
                        {{-- Chỉ hiện khi đã bỏ chọn "Đã đóng học phí" ở Bước 4 rồi quay lại --}}
                        <div x-show="!feePaid" x-cloak class="mt-md flex items-start gap-sm rounded-lg border-l-4 border-warning bg-warning-container p-sm font-body-small text-body-small text-on-warning-container">
                            <span class="material-symbols-outlined text-warning">info</span>
                            <div><p class="font-semibold">Chưa hoàn thành phí đăng ký</p><p>Hệ thống sẽ tự động tạo nhắc việc thu phí sau khi Chốt.</p></div>
                        </div>
                        <div class="mt-md flex items-center gap-sm font-body-small text-body-small text-on-surface-variant">
                            <span class="material-symbols-outlined text-secondary">upgrade</span>
                            <p>Khi Chốt, hồ sơ khách sẽ được nâng cấp thành tài khoản học viên chính thức.</p>
                        </div>
                    </div>

                    <x-ui.field label="Khóa học đăng ký" for="closing_course">
                        {{-- JS (courseTuition) tìm select[x-model="courseId"] — giữ nguyên thuộc tính x-model. --}}
                        <x-ui.select id="closing_course" x-model="courseId" x-on:change="updateCourse($event)" class="font-bold !text-primary-container">
                            @foreach ($courses as $crs)
                                <option value="{{ $crs->id }}" data-name="{{ $crs->name }}" data-tuition="{{ (float) $crs->tuition_fee }}">{{ $crs->name }} (Học phí niêm yết: {{ number_format($crs->tuition_fee) }}đ)</option>
                            @endforeach
                        </x-ui.select>
                        <p class="mt-1 text-[11px] text-on-surface-variant">Khi chọn lớp ở Bước 3, khóa học lấy theo lớp. Khi "Xếp lớp sau", học phí tính theo giá niêm yết của khóa này (trừ ưu đãi).</p>
                    </x-ui.field>
                </div>

                <div class="flex items-center justify-end pt-4 border-t border-surface-container-highest">
                    <x-ui.button x-on:click="step = 2">
                        <span>Tiếp tục: Tính học phí &amp; Ưu đãi</span>
                        <span class="material-symbols-outlined text-base">arrow_forward</span>
                    </x-ui.button>
                </div>
            </div>

            {{-- ═════════════════════════════════════════════════════════════════
                 BƯỚC 2: HỌC PHÍ, ƯU ĐÃI (CÓ NÚT TẠO MỚI), THU TRƯỚC, THU KHÁC
                 ═════════════════════════════════════════════════════════════════ --}}
            <div x-show="step === 2" class="space-y-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <div class="flex items-center justify-between pb-2 border-b border-surface-container-highest">
                    <h2 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container text-base">percent</span>
                        Bước 2: Học phí, Ưu đãi, Thu trước &amp; Thu khác
                    </h2>
                    @can('promotion.manage')
                    <x-ui.button variant="secondary" size="sm" icon="add_circle" class="!border-primary-container/30 !bg-primary-container/10 font-bold !text-primary-container hover:!bg-primary-container/20"
                                 x-on:click="showCreatePromoModal = true; $dispatch('open-modal', 'closing-create-promo')">
                        <span>Tạo mới ưu đãi</span>
                    </x-ui.button>
                    @endcan
                </div>

                {{-- Dòng 1: Học phí niêm yết & Chọn ưu đãi --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.input type="number" id="closing_base_tuition" label="Học phí niêm yết (VNĐ)" required x-model.number="baseTuition" readonly
                                class="cursor-not-allowed !bg-surface-container-low font-mono font-bold" />

                    <div>
                        <label class="block text-xs font-semibold text-on-surface-variant mb-1 flex items-center justify-between">
                            <span>Chương trình Ưu đãi / Voucher</span>
                            <span class="text-[10px] text-on-surface-variant/70">Chọn hoặc nhập trực tiếp</span>
                        </label>
                        <x-ui.select x-model="selectedPromotionId" x-on:change="applyPromotion($event)" placeholder="-- Tùy chỉnh / Không áp dụng --" class="font-semibold">
                            <template x-for="p in availablePromotions" :key="p.id">
                                <option :value="p.id" x-text="p.name + ' (' + (p.type === 'percent' ? p.value + '%' : new Intl.NumberFormat('vi-VN').format(p.value) + 'đ') + ')'"></option>
                            </template>
                        </x-ui.select>
                    </div>
                </div>

                {{-- Dòng 2: Chiết khấu tiền, Thu khác, Thu trước --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <x-ui.input type="number" id="closing_discount" label="Tiền Ưu đãi giảm trừ (VNĐ)" x-model.number="discount" readonly placeholder="0"
                                class="cursor-not-allowed !bg-surface-container-low font-mono font-bold !text-error" />

                    <div>
                        <label class="block text-xs font-semibold text-on-surface-variant mb-1 flex items-center justify-between">
                            <span>Khoản thu khác (VNĐ)</span>
                            <span class="text-[10px] text-secondary font-bold" x-text="feeItems.length + ' mục đã chọn'"></span>
                        </label>
                        <x-ui.input type="number" x-model.number="otherFees" readonly placeholder="0"
                                    class="cursor-not-allowed !bg-surface-container-low font-mono font-bold !text-secondary" />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-on-surface-variant mb-1 flex items-center justify-between">
                            <span>Thu trước (VNĐ)</span>
                            <span class="text-[10px] text-on-surface-variant/70">Đã đóng trước</span>
                        </label>
                        <x-ui.input type="number" x-model.number="prepaidAmount" placeholder="0" class="font-mono font-bold !text-warning" />
                    </div>
                </div>

                {{-- Bóc tách chi tiết các khoản Thu khác (Đồng phục, balo, học liệu, phụ phí...) --}}
                <div class="p-4 bg-surface-container-low rounded-2xl border border-surface-container-highest space-y-3">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div>
                            <span class="text-xs font-bold text-on-surface flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-secondary text-base">receipt_long</span>
                                Bóc tách chi tiết các khoản Thu khác (Hiển thị trên Hóa đơn phụ huynh)
                            </span>
                            <p class="text-[11px] text-on-surface-variant mt-0.5">Phụ huynh muốn nhìn rõ từng khoản mục cần tính tiền trong phiếu báo học phí.</p>
                        </div>
                    </div>

                    {{-- Chọn từ Danh mục Hàng hóa & Vật phẩm --}}
                    <div class="flex flex-col sm:flex-row items-center gap-2 pt-1">
                        <div class="w-full sm:flex-1">
                            <x-ui.select
                                x-on:change="if ($event.target.value) { const parts = $event.target.value.split('|||'); addPresetItem(parseInt(parts[0], 10), parts[1], parseInt(parts[2], 10)); $event.target.value = ''; }"
                                class="font-semibold"
                            >
                                <option value="">-- Chọn nhanh từ Danh mục Hàng hóa &amp; Thu khác ({{ isset($merchandiseItems) ? $merchandiseItems->count() : 0 }} mặt hàng) --</option>
                                @if (isset($merchandiseItems))
                                    @foreach ($merchandiseItems as $mItem)
                                        <option value="{{ $mItem->id }}|||{{ $mItem->name }}|||{{ (int)$mItem->price }}">
                                             [{{ $mItem->category_label }}] {{ $mItem->name }} ({{ $mItem->formatted_price }})
                                        </option>
                                    @endforeach
                                @endif
                            </x-ui.select>
                        </div>
                        <a href="{{ route('merchandise.index') }}" target="_blank" class="text-[11px] font-bold text-secondary hover:text-secondary hover:underline flex items-center gap-0.5 shrink-0" title="Mở quản lý danh mục hàng hóa trong tab mới">
                            <span class="material-symbols-outlined text-sm">open_in_new</span>
                            <span>Quản lý danh mục</span>
                        </a>
                    </div>

                    {{-- Danh sách các mục đã thêm --}}
                    <template x-if="feeItems.length > 0">
                        <div class="space-y-2 pt-2 border-t border-surface-container-highest">
                            <template x-for="(item, idx) in feeItems" :key="idx">
                                <div class="flex items-center gap-2 bg-surface-container-lowest p-2 rounded-xl border border-surface-container-highest shadow-2xs">
                                    <div class="flex-1">
                                        <input type="text" x-model="item.name" readonly class="w-full text-xs font-semibold text-on-surface rounded-lg border-surface-container-highest p-1.5 bg-surface-container-low" />
                                    </div>
                                    <div class="w-36">
                                        <div class="relative">
                                            <input type="number" x-model.number="item.amount" readonly class="w-full text-xs font-mono font-bold text-right text-secondary rounded-lg border-surface-container-highest p-1.5 pr-7 bg-surface-container-low" />
                                            <span class="absolute right-2 top-1.5 text-[10px] text-on-surface-variant/70 font-bold">đ</span>
                                        </div>
                                    </div>
                                    <x-ui.button variant="danger-text" size="sm" icon="delete" x-on:click="removeItem(idx)" title="Xóa mục này" aria-label="Xóa mục này" />
                                </div>
                            </template>
                            <div class="flex justify-between items-center text-xs px-1 text-on-surface-variant">
                                <span>Tổng cộng các mục thu khác:</span>
                                <span class="font-mono font-bold text-secondary" x-text="formatVND(otherFees)"></span>
                            </div>
                        </div>
                    </template>
                    <template x-if="feeItems.length === 0">
                        <div class="text-[11px] text-on-surface-variant/70 italic py-1">
                            Chưa chọn mục thu khác nào (hoặc nhập trực tiếp vào ô Khoản thu khác ở trên).
                        </div>
                    </template>
                </div>

                {{-- Bảng tổng hợp thành tiền theo đúng công thức --}}
                <div class="bg-surface-container-low rounded-2xl p-4 border border-surface-container-highest space-y-2 text-xs">
                    <div class="flex justify-between items-center text-on-surface-variant">
                        <span>Học phí niêm yết:</span>
                        <span class="font-mono font-semibold" x-text="formatVND(baseTuition)"></span>
                    </div>
                    <div class="flex justify-between items-center text-error">
                        <span>- Giảm trừ ưu đãi:</span>
                        <span class="font-mono font-semibold" x-text="'- ' + formatVND(discount)"></span>
                    </div>
                    <div class="flex justify-between items-center text-secondary">
                        <span>+ Thu khác (Giáo trình / Phụ phí):</span>
                        <span class="font-mono font-semibold" x-text="'+ ' + formatVND(otherFees)"></span>
                    </div>
                    <div class="flex justify-between items-center font-bold text-on-surface pt-2 border-t border-surface-container-highest">
                        <span>= Tổng giá trị hợp đồng (Thành tiền):</span>
                        <span class="font-mono text-sm text-primary-container" x-text="formatVND(contractTotal)"></span>
                    </div>
                    <div class="flex justify-between items-center text-warning pt-1">
                        <span>- Thu trước (đã thanh toán trước):</span>
                        <span class="font-mono font-semibold" x-text="'- ' + formatVND(prepaidAmount)"></span>
                    </div>
                    <div class="flex justify-between items-center font-black text-tertiary pt-2 border-t border-surface-container-highest text-sm">
                        <span>Số tiền thực thu cần nộp đợt này:</span>
                        <span class="font-mono text-base" x-text="formatVND(amountDue)"></span>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-surface-container-highest">
                    <x-ui.button variant="secondary" x-on:click="step = 1">
                        Quay lại
                    </x-ui.button>
                    <x-ui.button x-on:click="paidAmount = amountDue; syncSplitAmounts(); step = 3">
                        <span>Tiếp tục: Xếp lớp</span>
                        <span class="material-symbols-outlined text-base">arrow_forward</span>
                    </x-ui.button>
                </div>
            </div>

            {{-- ═════════════════════════════════════════════════════════════════
                 BƯỚC 3: XẾP LỚP & BÀN GIAO HỌC VIÊN
                 ═════════════════════════════════════════════════════════════════ --}}
            <div x-show="step === 3" class="space-y-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="flex items-center gap-sm border-b border-surface-container-highest pb-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary-container text-base">meeting_room</span>
                    Bước 3: Lớp học phù hợp đề xuất
                </h2>
                <p class="-mt-md font-body-small text-body-small text-on-surface-variant" x-show="customerLevel">Dựa trên trình độ <strong x-text="customerLevel"></strong> của học viên</p>

                <div class="space-y-4">
                    <div class="flex flex-wrap gap-3 text-xs font-semibold">
                        <label class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer" :class="!assignLater ? 'border-primary-container bg-primary-container/10 text-primary-container' : 'border-surface-container-highest text-on-surface-variant'">
                            <input type="radio" name="class_mode" value="class" :checked="!assignLater" @change="setAssignLater(false)" @disabled($classes->isEmpty()) />
                            <span>Chọn lớp</span>
                        </label>
                        <label class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer" :class="assignLater ? 'border-primary-container bg-primary-container/10 text-primary-container' : 'border-surface-container-highest text-on-surface-variant'">
                            <input type="radio" name="class_mode" value="later" :checked="assignLater" @change="setAssignLater(true)" />
                            <span class="flex flex-col"><span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">event_busy</span>Xếp lớp sau</span><span class="text-[10px] font-normal">Khách sẽ xuất hiện trong mục "Chờ xếp lớp"</span></span>
                        </label>
                    </div>

                    <x-ui.alert type="warning" x-show="assignLater" x-cloak class="text-xs">
                        Học viên vẫn được tạo hồ sơ, tài khoản và học phí (theo khóa <strong x-text="courseName"></strong>), nhưng chưa ghi danh vào lớp. Khách chuyển sang <strong>Chờ xếp lớp</strong>; Học vụ gán lớp sau ở mục "Chờ xếp lớp".
                    </x-ui.alert>

                    <div x-show="!assignLater">
                        <x-ui.field label="Chọn lớp đang học hoặc sắp khai giảng (còn chỗ)" for="closing_class" :required="true">
                        {{-- JS (setAssignLater / selectClassCard) tìm select[data-class-select]. --}}
                        <x-ui.select id="closing_class" data-class-select="1" class="font-bold !text-primary-container" x-on:change="updateClass($event)">
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
                                    {{ $cl->name }} ({{ $cl->code }}){{ $cl->status === 'upcoming' ? ' · Sắp khai giảng' : '' }} · Cơ sở: {{ $cl->branch?->name }} · Sĩ số: {{ $cl->active_enrollments_count }}/{{ $cl->max_capacity }} · Lịch học: {{ $cl->schedule_text }}
                                </option>
                            @endforeach
                        </x-ui.select>
                        </x-ui.field>

                        @if ($classes->isNotEmpty())
                            {{-- Thẻ gợi ý lớp: còn chỗ + ngưỡng khai giảng --}}
                            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3" data-class-suggestions>
                                @foreach ($classes as $cl)
                                    <button type="button" @click="selectClassCard('{{ $cl->id }}')"
                                        class="text-left p-3 rounded-xl border transition text-xs space-y-1"
                                        :class="String(classId) === '{{ $cl->id }}' ? 'border-primary-container bg-primary-container/10 ring-1 ring-primary-container' : 'border-surface-container-highest hover:border-primary-container/60 bg-surface-container-lowest'">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-bold text-on-surface">{{ $cl->name }}</span>
                                            @if ($cl->status === 'upcoming')
                                                <x-ui.badge color="info" :pill="true" :dot="false" class="font-bold">Sắp khai giảng{{ $cl->start_date ? ' '.$cl->start_date->format('d/m') : '' }}</x-ui.badge>
                                            @else
                                                <x-ui.badge color="success" :pill="true" :dot="false" class="font-bold">Đang học</x-ui.badge>
                                            @endif
                                        </div>
                                        <div class="font-code text-[10px] text-on-surface-variant/70">{{ $cl->code }}</div>
                                        <span x-show="levelMatches(@js($cl->level_haystack))" x-cloak class="inline-block rounded bg-tertiary/10 px-1.5 py-0.5 text-[10px] font-bold text-tertiary">Phù hợp trình độ</span>
                                        <div class="text-on-surface-variant">{{ $cl->course?->name ?? 'Chưa gán khóa' }} · {{ $cl->branch?->name }}</div>
                                        <div class="flex items-center gap-1 text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">calendar_today</span>Lịch học: {{ $cl->schedule_text ?: 'Chưa có lịch' }}</div>
                                        <div class="flex items-center gap-1 text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">account_circle</span>Giáo viên: {{ $cl->teacher?->name ?? 'Chưa phân công' }}</div>
                                        @php
                                            $cap = $cl->max_capacity > 0 ? $cl->max_capacity : max((int) $cl->min_students, $cl->active_enrollments_count, 1);
                                            $fill = min(100, (int) round($cl->active_enrollments_count / max(1, $cap) * 100));
                                        @endphp
                                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-container-high"><div class="h-full rounded-full {{ $cl->needed_to_open > 0 ? 'bg-warning' : 'bg-tertiary' }}" style="width: {{ $fill }}%"></div></div>
                                        <div class="text-on-surface-variant">Số học viên hiện có: <span class="font-semibold text-on-surface">{{ $cl->active_enrollments_count }} / {{ $cl->max_capacity > 0 ? $cl->max_capacity : '∞' }}</span> (ngưỡng khai giảng {{ (int) $cl->min_students }})</div>
                                        <div class="flex flex-wrap items-center gap-2 pt-1">
                                            <span class="font-semibold text-on-surface">Sĩ số {{ $cl->active_enrollments_count }}/{{ $cl->max_capacity > 0 ? $cl->max_capacity : '∞' }}</span>
                                            <span class="text-on-surface-variant/70">·</span>
                                            <span class="font-semibold {{ ($cl->remaining_seats ?? 99) <= 2 ? 'text-error' : 'text-tertiary' }}">Còn {{ $cl->remaining_seats ?? 'không giới hạn' }} chỗ</span>
                                        </div>
                                        @if ($cl->status === 'upcoming')
                                            @if ($cl->needed_to_open > 0)
                                                <div class="text-warning font-semibold">Cần thêm {{ $cl->needed_to_open }} học viên để khai giảng (ngưỡng {{ (int) $cl->min_students }})</div>
                                            @else
                                                <div class="text-tertiary font-semibold">Đã đủ ngưỡng khai giảng ({{ (int) $cl->min_students }} học viên)</div>
                                            @endif
                                        @endif
                                        <div class="pt-1 text-right font-semibold" :class="String(classId) === '{{ $cl->id }}' ? 'text-primary' : 'text-on-surface-variant/70'">
                                            <span x-text="String(classId) === '{{ $cl->id }}' ? 'Đã chọn lớp này' : 'Chọn lớp này'"></span>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                </div>

                <div class="flex items-center justify-between pt-4 border-t border-surface-container-highest">
                    <x-ui.button variant="secondary" x-on:click="step = 2">
                        Quay lại
                    </x-ui.button>
                    <x-ui.button x-on:click="step = 4; syncSplitAmounts()">
                        <span>Tiếp tục: Xác nhận &amp; Thu tiền</span>
                        <span class="material-symbols-outlined text-base">arrow_forward</span>
                    </x-ui.button>
                </div>
            </div>

            {{-- ═════════════════════════════════════════════════════════════════
                 BƯỚC 4: XÁC NHẬN, CHỌN PHƯƠNG THỨC THANH TOÁN (KẾT HỢP) & VIETQR
                 ═════════════════════════════════════════════════════════════════ --}}
            <div x-show="step === 4" class="space-y-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <div class="flex items-center justify-between pb-2 border-b border-surface-container-highest">
                    <h2 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-tertiary text-base">verified</span>
                        Bước 4: Xác nhận Hợp đồng, Chọn Phương thức Thanh toán &amp; Xuất Phiếu thu
                    </h2>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    {{-- Cột Trái: Tóm tắt hợp đồng & Phương thức thanh toán (7 cols) --}}
                    <div class="lg:col-span-7 bg-surface-container-low rounded-2xl p-5 border border-surface-container-highest space-y-4 text-xs">
                        <h3 class="font-bold text-on-surface text-xs uppercase tracking-wider flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-on-surface-variant text-sm">receipt_long</span>
                            <span>Tóm tắt hợp đồng đào tạo</span>
                        </h3>
                        
                        <div class="space-y-2 pt-1 border-b border-surface-container-highest pb-3">
                            <div class="flex justify-between py-1">
                                <span class="text-on-surface-variant">Học viên:</span>
                                <span class="font-bold text-on-surface" x-text="customerName + ' (' + studentCodePreview + ')'"></span>
                            </div>
                            <div class="flex justify-between py-1">
                                <span class="text-on-surface-variant">Số điện thoại:</span>
                                <span class="font-mono font-semibold text-on-surface" x-text="customerPhone"></span>
                            </div>
                            <div class="flex justify-between py-1">
                                <span class="text-on-surface-variant">Lớp học:</span>
                                <span class="font-bold text-primary-container" x-text="className"></span>
                            </div>
                            <div class="flex justify-between py-1">
                                <span class="text-on-surface-variant">Tổng giá trị hợp đồng:</span>
                                <span class="font-mono font-bold text-on-surface" x-text="formatVND(contractTotal)"></span>
                            </div>
                            <template x-if="feeItems && feeItems.length > 0">
                                <div class="p-2 bg-secondary/10 border border-secondary/30 rounded-xl space-y-1 my-1 text-[11px]">
                                    <div class="font-bold text-secondary flex justify-between">
                                        <span>Bao gồm Thu khác:</span>
                                        <span class="font-mono" x-text="formatVND(otherFees)"></span>
                                    </div>
                                    <div class="space-y-0.5 pl-1">
                                        <template x-for="(item, idx) in feeItems" :key="idx">
                                            <div class="flex justify-between text-on-surface-variant">
                                                <span x-text="'• ' + (item.name || 'Mục khác')"></span>
                                                <span class="font-mono font-semibold" x-text="formatVND(item.amount)"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <template x-if="prepaidAmount > 0">
                                <div class="flex justify-between py-1 text-warning">
                                    <span>Đã thu trước:</span>
                                    <span class="font-mono font-bold" x-text="formatVND(prepaidAmount)"></span>
                                </div>
                            </template>
                            <div class="flex justify-between py-1 text-tertiary font-bold">
                                <span>Cần thanh toán đợt 1:</span>
                                <span class="font-mono text-sm" x-text="formatVND(amountDue)"></span>
                            </div>
                        </div>

                        <label class="flex items-center gap-2 p-3 rounded-xl border border-tertiary/30 bg-tertiary/10 text-xs font-bold text-tertiary cursor-pointer">
                            <input type="checkbox" x-model="feePaid" @change="onFeePaidChange()" class="rounded border-tertiary/30 text-tertiary" />
                            <span>Đã đóng học phí đăng ký</span>
                        </label>
                        <p x-show="!feePaid" x-cloak class="text-[11px] text-warning">Chưa thu tiền: hệ thống tạo task "Nhắc thu học phí" cho người phụ trách khách (hạn 3 ngày).</p>

                        {{-- Số tiền thực thu đợt 1 --}}
                        <div x-show="feePaid">
                            <label class="block text-xs font-bold text-on-surface mb-1">
                                Số tiền thu thực tế đợt 1 (VNĐ) <span class="text-error">*</span>
                            </label>
                            <x-ui.input type="number" id="closing_paid_amount" x-model.number="paidAmount" x-on:input="syncSplitAmounts()" aria-label="Số tiền thu thực tế đợt 1"
                                        class="font-mono font-black !text-tertiary" />
                        </div>

                        {{-- Chọn Tài khoản Ngân hàng từ Cấu hình --}}
                        <div x-show="needsBankAccount" x-cloak>
                            <label class="block text-xs font-bold text-on-surface mb-1 flex items-center justify-between">
                                <span>Tài khoản Ngân hàng nhận tiền <span class="text-error">*</span></span>
                                <a href="{{ route('system-config.bank-accounts') }}" target="_blank" class="text-[10px] text-primary-container hover:underline font-normal">Đổi STK trong Admin &rarr;</a>
                            </label>
                            <x-ui.select x-model="selectedBankAccountId" class="font-semibold" aria-label="Tài khoản Ngân hàng nhận tiền">
                                <template x-for="bank in bankAccounts" :key="bank.id">
                                    <option :value="bank.id" x-text="bank.bank_name + ' - ' + bank.account_number + ' (' + bank.account_holder + ')'"></option>
                                </template>
                            </x-ui.select>
                        </div>

                        {{-- CHỌN PHƯƠNG THỨC THANH TOÁN (HỖ TRỢ KẾT HỢP NHIỀU PHƯƠNG THỨC) --}}
                        <div class="space-y-3 pt-2 border-t border-surface-container-highest">
                            <div>
                                <label class="block text-xs font-bold text-on-surface mb-1.5">Phương thức thanh toán giao dịch</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border bg-surface-container-lowest cursor-pointer transition text-xs font-semibold" :class="paymentMethod === 'transfer' ? 'border-primary-container text-primary-container bg-primary-container/10' : 'border-surface-container-highest text-on-surface-variant'">
                                        <input type="radio" name="pay_mode" value="transfer" x-model="paymentMethod" class="text-primary-container focus:ring-primary-container" />
                                        <span>Chuyển khoản (VietQR)</span>
                                    </label>

                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border bg-surface-container-lowest cursor-pointer transition text-xs font-semibold" :class="paymentMethod === 'cash' ? 'border-primary-container text-primary-container bg-primary-container/10' : 'border-surface-container-highest text-on-surface-variant'">
                                        <input type="radio" name="pay_mode" value="cash" x-model="paymentMethod" class="text-primary-container focus:ring-primary-container" />
                                        <span>Tiền mặt tại quầy</span>
                                    </label>

                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border bg-surface-container-lowest cursor-pointer transition text-xs font-semibold" :class="paymentMethod === 'pos' ? 'border-primary-container text-primary-container bg-primary-container/10' : 'border-surface-container-highest text-on-surface-variant'">
                                        <input type="radio" name="pay_mode" value="pos" x-model="paymentMethod" class="text-primary-container focus:ring-primary-container" />
                                        <span>Quẹt thẻ máy POS</span>
                                    </label>

                                    <label class="flex items-center gap-2 p-2.5 rounded-xl border bg-surface-container-lowest cursor-pointer transition text-xs font-semibold" :class="paymentMethod === 'split' ? 'border-secondary text-secondary bg-secondary/10 ring-1 ring-secondary' : 'border-surface-container-highest text-on-surface-variant'">
                                        <input type="radio" name="pay_mode" value="split" x-model="paymentMethod" class="text-secondary focus:ring-secondary" />
                                        <span>Kết hợp (Cash + Bank + POS)</span>
                                    </label>
                                </div>
                            </div>

                            {{-- Form chia nhỏ tiền khi chọn phương thức kết hợp --}}
                            <div x-show="paymentMethod === 'split'" x-cloak class="p-4 bg-secondary/10 border border-secondary/30 rounded-xl space-y-3">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-secondary flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm">call_split</span>
                                        Phân bổ số tiền thanh toán kết hợp
                                    </span>
                                    <span class="text-[10px] font-bold" :class="splitDiff === 0 ? 'text-tertiary' : 'text-error'" x-text="splitDiff === 0 ? '✓ Đã khớp 100%' : 'Chênh lệch: ' + formatVND(splitDiff)"></span>
                                </div>

                                <div class="grid grid-cols-3 gap-2">
                                    <x-ui.input type="number" id="closing_splitCash" label="Tiền mặt (Cash)" x-model.number="splitCash" placeholder="0" class="font-mono font-bold" />

                                    <x-ui.input type="number" id="closing_splitTransfer" label="Chuyển khoản (QR)" x-model.number="splitTransfer" placeholder="0" class="font-mono font-bold !text-primary-container" />

                                    <x-ui.input type="number" id="closing_splitPos" label="Quẹt thẻ POS" x-model.number="splitPos" placeholder="0" class="font-mono font-bold !text-secondary" />
                                </div>
                                <p class="text-[10px] text-on-surface-variant">Mã VietQR bên cạnh sẽ tự động sinh theo đúng số tiền chuyển khoản (<strong x-text="formatVND(effectiveTransferAmount)"></strong>).</p>
                            </div>
                        </div>

                        {{-- Ghi chú hóa đơn --}}
                        <x-ui.input name="bill_notes" label="Ghi chú trên Phiếu thu / Hóa đơn" x-model="billNotes" placeholder="Ghi chú thêm về học viên, phụ huynh hoặc cam kết..." />
                    </div>

                    {{-- Cột Phải: VietQR Code Box & Cấu trúc Nội dung Chuyển tiền (5 cols) --}}
                    <div class="lg:col-span-5 bg-surface-container-lowest rounded-2xl p-5 border border-primary-container/30 shadow-xs space-y-4 text-center flex flex-col items-center">
                        <div x-show="needsBankAccount" class="w-full flex items-center justify-between pb-2 border-b border-surface-container-highest">
                            <div class="text-xs font-black text-on-surface flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-primary-container text-lg">qr_code_scanner</span>
                                <span>MÃ VIETQR CHUYỂN KHOẢN</span>
                            </div>
                            <x-ui.badge color="success" :pill="true" :dot="false" class="font-bold">
                                Chuẩn NAPAS 247
                            </x-ui.badge>
                        </div>

                        {{-- VietQR Image with Dynamic Amount & Bank Details --}}
                        <div x-show="needsBankAccount" class="relative bg-surface-container-lowest p-2.5 rounded-2xl border-2 border-primary-container/20 shadow-md group">
                            <img 
                                :src="vietQrUrl" 
                                alt="Mã VietQR Chuyển khoản" 
                                class="w-48 h-48 sm:w-52 sm:h-52 object-contain rounded-lg"
                                loading="lazy"
                            />
                        </div>

                        {{-- Bank & Memo Details with Copy Buttons --}}
                        <div x-show="needsBankAccount" class="w-full bg-surface-container-low rounded-xl p-3 text-left space-y-2 text-[11px] border border-surface-container-highest">
                            <div class="flex justify-between items-center">
                                <span class="text-on-surface-variant">Ngân hàng:</span>
                                <span class="font-bold text-on-surface text-right truncate max-w-[170px]" x-text="selectedBank.bank_name"></span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span class="text-on-surface-variant">Số tài khoản:</span>
                                <div class="flex items-center gap-1">
                                    <span class="font-mono font-black text-on-surface" x-text="selectedBank.account_number"></span>
                                    <button 
                                        type="button" 
                                        @click="copyText(cleanAccountNumber, 'acc')" 
                                        class="p-0.5 rounded hover:bg-surface-container-high text-on-surface-variant hover:text-on-surface transition"
                                        title="Sao chép STK"
                                    >
                                        <span class="material-symbols-outlined text-sm" x-text="copiedField === 'acc' ? 'check' : 'content_copy'"></span>
                                    </button>
                                </div>
                            </div>

                            <div class="flex justify-between items-center">
                                <span class="text-on-surface-variant">Chủ tài khoản:</span>
                                <span class="font-bold text-on-surface text-right uppercase text-[10px] truncate max-w-[170px]" x-text="selectedBank.account_holder"></span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span class="text-on-surface-variant">Số tiền QR:</span>
                                <span class="font-mono font-black text-primary-container text-xs" x-text="formatVND(effectiveTransferAmount)"></span>
                            </div>

                            {{-- NỘI DUNG CHUYỂN KHOẢN THEO CẤU TRÚC: Mã hs + ten học sinh + tenlop + CN + xxx --}}
                            <div class="pt-1.5 border-t border-surface-container-highest space-y-1">
                                <div class="flex justify-between items-center">
                                    <span class="text-on-surface-variant font-bold">Nội dung CK (Cấu trúc chuẩn):</span>
                                    <button 
                                        type="button" 
                                        @click="copyText(transferMemo, 'memo')" 
                                        class="text-xs text-primary-container hover:text-primary font-bold inline-flex items-center gap-0.5"
                                        title="Sao chép nội dung CK"
                                    >
                                        <span class="material-symbols-outlined text-sm" x-text="copiedField === 'memo' ? 'check' : 'content_copy'"></span>
                                        <span x-text="copiedField === 'memo' ? 'Đã chép' : 'Chép'"></span>
                                    </button>
                                </div>
                                <div class="p-2 bg-primary-container/10 border border-primary-container/30 rounded-lg font-mono font-bold text-xs text-primary break-all select-all text-left" x-text="transferMemo"></div>
                                <p class="text-[10px] text-on-surface-variant/70">Nội dung <strong>dự kiến</strong> — khi chốt, hệ thống sinh lại theo mã học viên thật (HV-...) trên phiếu thu VietQR.</p>
                            </div>
                        </div>

                        {{-- Action Buttons: Tải QR & Xem Mẫu Bill --}}
                        <div class="w-full flex items-center gap-2">
                            <x-ui.button variant="secondary" size="sm" icon="download" x-show="needsBankAccount" x-on:click="downloadVietQr()" class="flex-1 font-bold">
                                <span>Tải mã QR</span>
                            </x-ui.button>

                            <div x-show="!needsBankAccount" class="flex-1 py-2 px-3 bg-secondary/10 text-secondary text-xs font-bold rounded-xl border border-secondary/30">
                                Không phát sinh VietQR cho phương thức này
                            </div>

                            {{-- Nút Xem & In Thông báo nộp học phí --}}
                            <x-ui.button size="sm" icon="print" x-on:click="openBillModal()" class="flex-1 font-bold">
                                <span>Xem &amp; In Bill</span>
                            </x-ui.button>
                        </div>
                    </div>
                </div>

                {{-- Footer Bước 4 --}}
                <div class="flex items-center justify-between pt-4 border-t border-surface-container-highest">
                    <x-ui.button variant="secondary" x-on:click="step = 3">
                        Quay lại
                    </x-ui.button>
                    <x-ui.button type="submit" variant="success" icon="check_circle" x-bind:disabled="!customerId || (!assignLater && !classId) || (assignLater && !courseId) || (feePaid && paidAmount <= 0 && prepaidAmount <= 0) || (needsBankAccount && !selectedBankAccountId) || (paymentMethod === 'split' && splitDiff !== 0)">
                        <span>Hoàn tất Chốt Deal, Xếp Lớp &amp; Xuất Phiếu Thu</span>
                    </x-ui.button>
                </div>
            </div>
        </form>

        {{-- ═════════════════════════════════════════════════════════════════
             MODAL: TẠO MỚI ƯU ĐÃI (BỔ SUNG ƯU ĐÃI NHANH TẠI CHỖ)
             ═════════════════════════════════════════════════════════════════ --}}
        <x-ui.modal name="closing-create-promo" title="Tạo Mới Chương Trình Ưu Đãi / Voucher" max-width="md">
            <div class="space-y-3.5">
                <x-ui.input id="promo_name" label="Tên chương trình ưu đãi" required x-model="newPromo.name" placeholder="Voucher khai giảng / Ưu đãi bạn mới" class="font-bold" />

                <div class="grid grid-cols-2 gap-3">
                    <x-ui.field label="Loại giảm giá" for="promo_type">
                        <x-ui.select id="promo_type" x-model="newPromo.type" class="font-semibold">
                            <option value="fixed">Số tiền cố định (VNĐ)</option>
                            <option value="percent">Phần trăm (%)</option>
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.input type="number" id="promo_value" label="Giá trị" required x-model.number="newPromo.value" placeholder="1000000 hoặc 10" class="font-mono font-bold" />
                </div>

                <x-ui.textarea id="promo_description" label="Mô tả / Điều kiện áp dụng" x-model="newPromo.description" rows="2" placeholder="Áp dụng cho học viên đăng ký sớm..." />
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.select x-model="newPromo.branch_id" placeholder="Mọi cơ sở" aria-label="Cơ sở áp dụng">
                        @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                    </x-ui.select>
                    <x-ui.select x-model="newPromo.course_id" placeholder="Mọi khóa học" aria-label="Khóa học áp dụng">
                        @foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach
                    </x-ui.select>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <x-ui.input type="datetime-local" x-model="newPromo.starts_at" title="Bắt đầu" aria-label="Bắt đầu" />
                    <x-ui.input type="datetime-local" x-model="newPromo.ends_at" title="Kết thúc" aria-label="Kết thúc" />
                    <x-ui.input type="number" min="1" x-model.number="newPromo.usage_limit" placeholder="Lượt dùng" aria-label="Lượt dùng" />
                </div>
            </div>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="showCreatePromoModal = false; $dispatch('close-modal', 'closing-create-promo')">Hủy</x-ui.button>
                <x-ui.button x-on:click="saveNewPromotion()">Lưu &amp; Áp Dụng Ngay</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        {{-- ═════════════════════════════════════════════════════════════════
             MODAL: XEM TRƯỚC VÀ IN THÔNG BÁO NỘP HỌC PHÍ (BILL GIAO DỊCH)
             ═════════════════════════════════════════════════════════════════ --}}
        <x-ui.modal name="closing-bill-preview" title="Xem trước Thông Báo Nộp Học Phí (Chuẩn A4)" max-width="4xl">
                {{-- Modal Body: Exact User Bill Template (mẫu in — giữ màu/inline style gốc) --}}
                <div class="bg-surface-container-lowest text-on-surface" id="printableBillArea">
                    <div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.4;">
                        {{-- HEADER --}}
                        <div style="display: flex; align-items: flex-start; margin-bottom: 20px;">
                            <div style="width: 65px; margin-right: 18px; flex-shrink: 0;">
                                <div style="width: 60px; height: 60px; border-radius: 10px; background: linear-gradient(135deg, #ea580c, #c2410c); display: flex; align-items: center; justify-content: center; color: white; font-weight: 900; font-size: 24px;">
                                    M
                                </div>
                            </div>
                            <div>
                                <div style="font-size: 16px; font-weight: 700; margin-bottom: 4px; color: #c2410c;">
                                    {{ \App\Support\CenterInfo::name() }}
                                </div>
                                <div style="line-height: 1.5; font-size: 13px; color: #333;">
                                    @include('partials.center-info-lines')
                                </div>
                            </div>
                        </div>

                        {{-- TITLE --}}
                        <div style="text-align: center; margin: 10px 0 16px 0;">
                            <h2 style="margin: 0; font-size: 21px; font-weight: 700; color: #111;">THÔNG BÁO NỘP HỌC PHÍ</h2>
                            <div style="margin-top: 4px; font-size: 13px; color: #666;">
                                Ngày {{ date('d') }} tháng {{ date('m') }} năm {{ date('Y') }}
                            </div>
                        </div>

                        {{-- INFORMATION TABLE --}}
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
                                    <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;">Thu trước:</td>
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

                        {{-- PAYMENT NOTE --}}
                        <div style="margin: 10px 0 8px 0; font-size: 13px; font-style: italic; color: #333;" x-text="needsBankAccount ? 'Thông tin chuyển khoản của giao dịch:' : 'Giao dịch được ghi nhận theo phương thức tiền mặt/POS, không phát sinh VietQR.'">
                        </div>

                        <div x-show="needsBankAccount">
                        {{-- BANK TABLE --}}
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

                        {{-- TRANSFER CONTENT --}}
                        <div style="margin-top: 10px; font-size: 14px; padding: 8px 12px; background: #fff7ed; border: 1px solid #ffedd5; border-radius: 6px;">
                            <strong>Nội dung chuyển tiền :</strong>
                            <strong style="color: #c2410c; margin-left: 6px;" x-text="transferMemo"></strong>
                        </div>

                        {{-- COMPANY NOTE --}}
                        <div style="margin-top: 10px; font-size: 13px; line-height: 1.4;">
                            <strong>Ghi chú:</strong>
                            <div>Tk công ty. Quý phụ huynh vui lòng giữ nguyên nội dung chuyển tiền để hệ thống tự động ghi nhận gạch nợ.</div>
                        </div>

                        {{-- QR CODE --}}
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
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="showBillModal = false; $dispatch('close-modal', 'closing-bill-preview')">Đóng</x-ui.button>
                <x-ui.button size="sm" icon="print" x-on:click="printBill()" class="font-bold"><span>In Ngay</span></x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>

    <script>
        function closingWizard() {
            return {
                step: 1,
                customerId: @js($customers->first()?->id ?? ''),
                customerName: @js($customers->first()?->name ?? ''),
                customerPhone: @js($customers->first()?->phone ?? ''),
                customerBranch: @js($customers->first()?->branch?->code ?? 'BD'),
                customerBranchId: @js((string) ($customers->first()?->branch_id ?? '')),
                customerStage: @js($customers->first()?->stage_label ?? ''),
                customerLevel: @js($customers->first()?->level_label ?? ''),
                customerLevelKeys: @js($customers->first()?->level_keys ?? []),
                // Mã học viên do hệ thống sinh khi chốt — không đoán trước.
                studentCodePreview: 'mã HV sinh khi chốt',
                courseName: @js($classes->first()?->course?->name ?? ''),
                classId: @js($classes->first()?->id ?? ''),
                assignLater: @js($classes->isEmpty()),
                feePaid: true,
                className: @js($classes->first()?->name ?? ''),
                classBranch: @js($classes->first()?->branch?->code ?? 'BD'),
                classBranchId: @js($classes->first()?->branch_id ?? ''),
                courseId: @js((string) ($classes->first()?->course_id ?? $courses->first()?->id ?? '')),

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
                    return this.feePaid && this.paymentMethod === 'transfer'
                        || (this.paymentMethod === 'split' && Number(this.splitTransfer || 0) > 0);
                },

                get availablePromotions() {
                    return this.promotionsList.filter((promotion) =>
                        (!promotion.branch_id || String(promotion.branch_id) === String(this.assignLater ? this.customerBranchId : this.classBranchId))
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
                        window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Vui lòng nhập tên chương trình ưu đãi và giá trị giảm!', type: 'error' } }));
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
                            window.dispatchEvent(new CustomEvent('close-modal', { detail: 'closing-create-promo' }));
                            this.newPromo = { name: '', type: 'fixed', value: 0, description: '', branch_id: '', course_id: '', starts_at: '', ends_at: '', usage_limit: '' };
                            window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Đã tạo và áp dụng ưu đãi "' + data.promotion.name + '" thành công!', type: 'success' } }));
                        } else {
                            window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Có lỗi xảy ra khi tạo ưu đãi.', type: 'error' } }));
                        }
                    })
                    .catch(err => {
                        window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Lỗi kết nối máy chủ: ' + err.message, type: 'error' } }));
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
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'closing-bill-preview' }));
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

                init() {
                    if (this.assignLater) this.$nextTick(() => this.setAssignLater(true));
                },

                courseTuition(courseId) {
                    const opt = document.querySelector(`select[x-model="courseId"] option[value="${courseId}"]`);
                    return opt ? { name: opt.getAttribute('data-name') || '', tuition: parseFloat(opt.getAttribute('data-tuition') || 0) } : null;
                },

                updateCourse(e) {
                    this.courseId = e.target.value;
                    if (this.assignLater) this.applyCourseTuition();
                },

                applyCourseTuition() {
                    const course = this.courseTuition(this.courseId);
                    if (!course) return;
                    this.courseName = course.name;
                    this.baseTuition = course.tuition;
                    if (!this.availablePromotions.some((promotion) => String(promotion.id) === String(this.selectedPromotionId))) {
                        this.selectedPromotionId = '';
                        this.discount = 0;
                    }
                    this.applyPromotion({ target: { value: this.selectedPromotionId } });
                    this.paidAmount = this.feePaid ? this.amountDue : 0;
                },

                setAssignLater(value) {
                    this.assignLater = value;
                    if (value) {
                        this.className = 'Xếp lớp sau';
                        this.applyCourseTuition();
                    } else {
                        const select = document.querySelector('select[data-class-select]');
                        if (select) this.updateClass({ target: select });
                    }
                },

                onFeePaidChange() {
                    this.paidAmount = this.feePaid ? this.amountDue : 0;
                    this.syncSplitAmounts();
                },

                updateCustomer(e) {
                    const opt = e.target.options[e.target.selectedIndex];
                    this.customerId = opt.value;
                    this.customerName = opt.getAttribute('data-name');
                    this.customerPhone = opt.getAttribute('data-phone');
                    this.customerBranch = opt.getAttribute('data-branch') || 'BD';
                    this.customerBranchId = opt.getAttribute('data-branch-id') || '';
                    this.customerStage = opt.getAttribute('data-stage') || '';
                    this.customerLevel = opt.getAttribute('data-level') || '';
                    this.customerLevelKeys = (opt.getAttribute('data-level-keys') || '').split('|').filter(Boolean);
                    this.paidAmount = this.feePaid ? this.amountDue : 0;
                },

                levelMatches(haystack) {
                    return this.customerLevelKeys.length > 0 && this.customerLevelKeys.some((key) => String(haystack || '').includes(key));
                },
                
                selectClassCard(id) {
                    const select = document.querySelector('select[data-class-select]');
                    if (!select) return;
                    select.value = String(id);
                    this.updateClass({ target: select });
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
