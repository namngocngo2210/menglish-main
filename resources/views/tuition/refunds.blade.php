<x-app-layout hide-errors>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('tuition.students') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">currency_exchange</span>
                        Xử lý khất nợ / bảo lưu / hoàn tiền &amp; chuyển nhượng
                    </h1>
                    <p class="text-xs text-gray-500">Quản lý các yêu cầu tài chính phát sinh trong quá trình học: khất nợ, bảo lưu, hoàn phí, chuyển nhượng buổi dư</p>
                </div>
            </div>
        </div>
    </x-slot>

    @include('tuition.partials.errors')

    <div class="max-w-5xl mx-auto space-y-6" x-data="refundTransferManager(@js($studentFinance), @js((string) ($students->first()?->id ?? '')), @js((float) $adminFeePercent))">
        {{-- Đánh dấu khất nợ (mockup hoan-tien-va-khat-no): dời hạn đóng, vẫn giữ lịch học; duyệt xong tạm dừng nhắc nợ tới hạn mới. --}}
        <form action="{{ route('tuition.refunds.store') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-4">
            @csrf
            <input type="hidden" name="type" value="extension" />
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">event_busy</span>
                <h2 class="text-sm font-bold text-gray-900">Đánh dấu khất nợ</h2>
            </div>
            <x-ui.alert type="info">Tiếp tục quy trình nhắc nợ chuẩn, không khóa lịch học của học viên. Khi được duyệt, hạn đóng được dời sang ngày mới và nhắc nợ tạm dừng tới ngày đó.</x-ui.alert>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                <div class="md:col-span-2">
                    <label class="block font-semibold text-gray-700 mb-1">Học viên đang nợ <span class="text-rose-500">*</span></label>
                    <select name="student_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold focus:border-primary-container focus:ring-primary-container">
                        @foreach ($students->filter(fn ($st) => (float) ($st->tuition?->debt_amount ?? 0) > 0) as $st)
                            <option value="{{ $st->id }}">{{ $st->code }} - {{ $st->name }} · Còn nợ {{ number_format((float) $st->tuition->debt_amount, 0, ',', '.') }}đ · Hạn {{ $st->tuition->due_date?->format('d/m/Y') ?? 'chưa đặt' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-gray-700 mb-1">Hạn đóng mới <span class="text-rose-500">*</span></label>
                    <input type="date" name="extended_due_date" value="{{ old('extended_due_date') }}" min="{{ now()->addDay()->toDateString() }}" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-mono" />
                </div>
                <div class="md:col-span-3">
                    <label class="block font-semibold text-gray-700 mb-1">Lý do khất nợ (bắt buộc)</label>
                    <textarea name="reason" rows="2" required placeholder="Nhập chi tiết lý do học viên xin gia hạn thời gian nộp học phí..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container"></textarea>
                </div>
            </div>
            <div class="flex justify-end">
                <x-ui.button type="submit" icon="event_available">Xác nhận khất nợ</x-ui.button>
            </div>
        </form>

        <form action="{{ route('tuition.refunds.store') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
            @csrf

            <!-- Select Action Type Tabs -->
            <div>
                <label class="block text-xs font-bold text-gray-900 uppercase tracking-wider mb-2">Tạo yêu cầu xử lý nghỉ giữa khóa</label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <button type="button" @click="actionType = 'transfer'" class="p-3.5 rounded-2xl border text-xs font-bold transition flex items-center justify-center gap-2 cursor-pointer" :class="actionType === 'transfer' ? 'border-primary-container bg-orange-50/70 text-primary shadow-xs ring-1 ring-primary-container' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                        <span class="material-symbols-outlined text-base">swap_horiz</span>
                        <span>Chuyển nhượng buổi dư</span>
                    </button>
                    <button type="button" @click="actionType = 'refund'" class="p-3.5 rounded-2xl border text-xs font-bold transition flex items-center justify-center gap-2 cursor-pointer" :class="actionType === 'refund' ? 'border-primary-container bg-orange-50/70 text-primary shadow-xs ring-1 ring-primary-container' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                        <span class="material-symbols-outlined text-base">undo</span>
                        <span>Hoàn trả học phí</span>
                    </button>
                    <button type="button" @click="actionType = 'deferral'" class="p-3.5 rounded-2xl border text-xs font-bold transition flex items-center justify-center gap-2 cursor-pointer" :class="actionType === 'deferral' ? 'border-primary-container bg-orange-50/70 text-primary shadow-xs ring-1 ring-primary-container' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                        <span class="material-symbols-outlined text-base">pause_circle</span>
                        <span>Bảo lưu</span>
                    </button>
                </div>
            </div>

            <input type="hidden" name="type" :value="actionType" />
            <input type="hidden" name="total_paid" :value="basis.paid" />
            <input type="hidden" name="attended_lessons" :value="attendedLessons" />
            <input type="hidden" name="admin_fee" :value="actionType === 'refund' ? adminFee : 0" />

            <!-- Tóm tắt học viên (số liệu thật từ hợp đồng & điểm danh) -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div class="md:col-span-2">
                    <label class="block font-semibold text-gray-700 mb-1">Học viên nguồn <span class="text-rose-500">*</span></label>
                    <select name="student_id" x-model="selectedStudentId" @change="resetFromBasis()" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold focus:border-primary-container focus:ring-primary-container">
                        @foreach ($students as $st)
                            <option value="{{ $st->id }}">{{ $st->code }} - {{ $st->name }} ({{ $st->currentClass?->name ?? 'Chưa gán lớp' }}) · Đã nộp: {{ number_format((float) ($st->tuition?->paid_amount ?? 0), 0, ',', '.') }}đ</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2 grid grid-cols-2 md:grid-cols-4 gap-3 p-4 rounded-2xl bg-slate-50 border border-slate-200">
                    <div>
                        <span class="text-gray-500 block mb-1 uppercase text-[10px] font-bold">Đã thu</span>
                        <span class="font-mono font-bold text-gray-900" x-text="money(basis.paid)"></span>
                    </div>
                    <div>
                        <span class="text-gray-500 block mb-1 uppercase text-[10px] font-bold">Giá trị hợp đồng</span>
                        <span class="font-mono font-bold text-gray-900" x-text="money(basis.contract)"></span>
                    </div>
                    <div>
                        <span class="text-gray-500 block mb-1 uppercase text-[10px] font-bold">Buổi đã học / Tổng</span>
                        <span class="font-mono font-bold text-gray-900" x-text="basis.total_sessions ? (basis.attended_sessions + ' / ' + basis.total_sessions) : 'Chưa có dữ liệu buổi'"></span>
                    </div>
                    <div>
                        <span class="text-gray-500 block mb-1 uppercase text-[10px] font-bold">Buổi dư</span>
                        <span class="font-mono font-bold text-primary" x-text="basis.total_sessions ? (remainingLessons + ' buổi') : '—'"></span>
                    </div>
                    <template x-if="!basis.has_tuition">
                        <p class="col-span-full text-amber-700 font-semibold">Học viên chưa có hồ sơ học phí — không thể hoàn / chuyển nhượng / bảo lưu.</p>
                    </template>
                </div>

                <!-- Target Student (Only for Transfer) -->
                <div x-show="actionType === 'transfer'" class="md:col-span-2">
                    <label class="block font-semibold text-gray-700 mb-1">Học viên nhận chuyển nhượng <span class="text-rose-500">*</span></label>
                    <select name="target_student_id" :disabled="actionType !== 'transfer'" class="w-full text-xs rounded-xl border border-emerald-300 bg-emerald-50/40 p-2.5 font-bold focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">-- Chọn học viên nhận chuyển nhượng --</option>
                        @foreach ($students as $st)
                            <option value="{{ $st->id }}">{{ $st->code }} - {{ $st->name }} ({{ $st->currentClass?->name ?? 'Chưa gán lớp' }}) · Còn nợ: {{ number_format((float) ($st->tuition?->debt_amount ?? 0), 0, ',', '.') }}đ</option>
                        @endforeach
                    </select>
                </div>

                <!-- Bảng tính hoàn phí / chuyển nhượng -->
                <div x-show="actionType !== 'deferral'" class="md:col-span-2 p-4 rounded-2xl border space-y-3" :class="actionType === 'transfer' ? 'bg-emerald-50/50 border-emerald-200/80' : 'bg-orange-50/40 border-orange-200'">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div>
                            <span class="text-gray-500 block mb-1">Số buổi đã học (theo điểm danh):</span>
                            <input type="number" x-model.number="attendedLessons" min="0" class="w-full text-xs rounded-lg border border-gray-200 p-2 font-bold font-mono bg-white" />
                        </div>
                        <div>
                            <span class="text-gray-500 block mb-1">Đơn giá / buổi:</span>
                            <div class="font-mono font-bold text-gray-700 mt-1" x-text="money(unitPrice)"></div>
                        </div>
                        <div>
                            <span class="text-gray-500 block mb-1">Giá trị buổi còn lại:</span>
                            <div class="font-mono font-bold text-gray-700 mt-1" x-text="money(remainingValue)"></div>
                        </div>
                        <div x-show="actionType === 'refund'">
                            <span class="text-gray-500 block mb-1" x-text="'Phí quản trị (' + feePercent + '%):'"></span>
                            <div class="font-mono font-bold text-gray-800 mt-1" x-text="money(adminFee)"></div>
                        </div>
                    </div>
                    <div class="pt-2 border-t border-gray-200/70 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <label class="text-gray-600 font-semibold" for="refundAmount" x-text="actionType === 'transfer' ? 'Số tiền chuyển nhượng (cấn trừ công nợ người nhận):' : 'Số tiền hoàn thực tế:'"></label>
                        <div class="flex items-center gap-2">
                            <input id="refundAmount" type="number" name="refund_amount" x-model.number="refundAmount" min="0" :max="basis.paid" :disabled="actionType === 'deferral'" class="w-44 text-right text-sm rounded-lg border border-gray-200 p-2 font-bold font-mono bg-white" />
                            <button type="button" @click="refundAmount = suggestedAmount" class="px-2 py-1 rounded-lg border border-gray-200 bg-white text-[11px] font-semibold hover:bg-gray-50" title="Tính lại theo chính sách">Theo chính sách</button>
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-500">Đề xuất theo chính sách: <strong class="font-mono" x-text="money(suggestedAmount)"></strong>. Có thể điều chỉnh trước khi gửi duyệt; khi duyệt hệ thống chặn số tiền vượt số đã nộp.</p>
                </div>

                <!-- Bảo lưu -->
                <div x-show="actionType === 'deferral'" class="md:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-3 p-4 rounded-2xl border border-blue-200 bg-blue-50/40">
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Bảo lưu từ ngày <span class="text-rose-500">*</span></label>
                        <input type="date" name="defer_from" value="{{ old('defer_from', now()->toDateString()) }}" :disabled="actionType !== 'deferral'" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-mono" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Đến ngày (học lại từ ngày kế tiếp) <span class="text-rose-500">*</span></label>
                        <input type="date" name="defer_to" value="{{ old('defer_to') }}" :disabled="actionType !== 'deferral'" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-mono" />
                    </div>
                    <p class="sm:col-span-2 text-[11px] text-blue-900">Khi được duyệt: học viên chuyển trạng thái <strong>Bảo lưu</strong>, đóng băng <strong x-text="basis.total_sessions ? remainingLessons + ' buổi còn lại' : 'số buổi còn lại'"></strong> và công nợ <strong class="font-mono" x-text="money(basis.debt)"></strong>; nhắc nợ tạm dừng tới hết ngày bảo lưu.</p>
                </div>

                <div class="md:col-span-2">
                    <label class="block font-semibold text-gray-700 mb-1">Lý do &amp; Căn cứ phê duyệt <span class="text-rose-500">*</span></label>
                    <textarea name="reason" rows="3" required placeholder="Nhập chi tiết nguyên nhân học viên dừng học / chuyển nhượng / hoàn phí..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <button type="submit" :disabled="!basis.has_tuition" class="px-6 py-2.5 bg-primary-container hover:bg-primary-hover disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-base">send</span>
                    <span>Gửi yêu cầu phê duyệt</span>
                </button>
            </div>
        </form>

        <!-- Audit Log Requests List -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 font-bold text-xs text-gray-900 uppercase tracking-wider flex items-center justify-between">
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-base">history_edu</span>
                    Nhật Ký Thao Tác &amp; Hồ Sơ Xử Lý Học Phí
                </span>
                <span class="text-[11px] font-normal text-gray-400 font-mono">{{ count($refundRequests) }} bản ghi</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Thời gian</th>
                            <th class="py-3 px-4">Học viên nguồn</th>
                            <th class="py-3 px-4">Loại yêu cầu</th>
                            <th class="py-3 px-4">Học viên thụ hưởng</th>
                            <th class="py-3 px-4 text-right">Số tiền</th>
                            <th class="py-3 px-4">Lý do &amp; Căn cứ</th>
                            <th class="py-3 px-4">Người duyệt</th>
                            <th class="py-3 px-4">Trạng thái</th>
                            <th class="py-3 px-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($refundRequests as $rq)
                            <tr class="hover:bg-orange-50/10 transition">
                                <td class="py-3.5 px-4 font-mono text-gray-500 text-[11px] whitespace-nowrap">
                                    {{ $rq->created_at->format('d/m/Y H:i') }}
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-gray-900">{{ $rq->student?->name }}</div>
                                    <div class="text-[10px] text-gray-400 font-mono">{{ $rq->student?->code }} ({{ $rq->student?->currentClass?->name ?? '—' }})</div>
                                </td>
                                <td class="py-3.5 px-4 font-semibold">
                                    @if ($rq->type === 'transfer')
                                        <span class="px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 border border-indigo-200 text-[10px] font-bold">Chuyển nhượng</span>
                                    @elseif ($rq->type === 'refund')
                                        <span class="px-2 py-0.5 rounded-md bg-rose-50 text-rose-700 border border-rose-200 text-[10px] font-bold">Hoàn phí</span>
                                    @elseif ($rq->type === 'deferral')
                                        <span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-bold">Bảo lưu</span>
                                        <div class="text-[10px] text-gray-500 font-mono mt-1">{{ $rq->defer_from?->format('d/m/Y') }} – {{ $rq->defer_to?->format('d/m/Y') }}</div>
                                    @else
                                        <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-bold">Khất nợ</span>
                                        @if ($rq->extended_due_date)
                                            <div class="text-[10px] text-gray-500 font-mono mt-1">Hạn mới {{ $rq->extended_due_date->format('d/m/Y') }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="py-3.5 px-4">
                                    @if ($rq->targetStudent)
                                        <div class="font-bold text-emerald-800">{{ $rq->targetStudent->name }}</div>
                                        <div class="text-[10px] text-gray-400 font-mono">{{ $rq->targetStudent->code }}</div>
                                    @else
                                        <span class="text-gray-400 font-mono text-[11px]">—</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono font-black text-rose-600 text-sm whitespace-nowrap">
                                    {{ number_format($rq->refund_amount) }}đ
                                </td>
                                <td class="py-3.5 px-4 text-gray-600 max-w-[220px] truncate" title="{{ $rq->reason }}">
                                    {{ $rq->reason }}
                                </td>
                                <td class="py-3.5 px-4 text-gray-700 text-[11px]">
                                    {{ $rq->approver?->name ?? '—' }}
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @if ($rq->status === 'approved')
                                        <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-bold border border-emerald-200 flex items-center gap-1 w-fit">
                                            <span class="material-symbols-outlined text-[12px]">check_circle</span>
                                            <span>Đã duyệt</span>
                                        </span>
                                    @elseif ($rq->status === 'rejected')
                                        <span class="px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 text-[10px] font-bold border border-rose-200 flex items-center gap-1 w-fit">
                                            <span class="material-symbols-outlined text-[12px]">cancel</span>
                                            <span>Đã từ chối</span>
                                        </span>
                                    @else
                                        <span class="px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 text-[10px] font-bold border border-amber-200 flex items-center gap-1 w-fit">
                                            <span class="material-symbols-outlined text-[12px]">hourglass_empty</span>
                                            <span>Chờ duyệt</span>
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    @if ($rq->status === 'pending')
                                        <div class="flex items-center justify-end gap-1">
                                            <form action="{{ route('tuition.refunds.approve', $rq->id) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-lg shadow-xs transition cursor-pointer">
                                                    Duyệt
                                                </button>
                                            </form>
                                            <form action="{{ route('tuition.refunds.reject', $rq->id) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="px-2.5 py-1 border border-gray-200 hover:bg-gray-100 text-gray-600 font-semibold text-xs rounded-lg transition cursor-pointer">
                                                    Từ chối
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-gray-400 font-mono text-[11px]">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-8 text-gray-400 text-xs">Chưa có hồ sơ hoàn phí / chuyển nhượng nào trong CSDL.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function refundTransferManager(finance, initialStudentId, feePercent) {
            const empty = { has_tuition: false, paid: 0, contract: 0, debt: 0, total_sessions: null, attended_sessions: 0, remaining_sessions: null, unit_price: 0 };
            return {
                finance: finance || {},
                actionType: 'transfer',
                selectedStudentId: initialStudentId,
                extensionStudentId: '',
                feePercent: feePercent,
                attendedLessons: 0,
                refundAmount: 0,

                init() {
                    this.resetFromBasis();
                    this.$watch('actionType', () => { this.refundAmount = this.suggestedAmount; });
                },

                get basis() {
                    return this.finance[this.selectedStudentId] || empty;
                },

                // Làm lại số liệu theo hợp đồng thật của học viên được chọn.
                resetFromBasis() {
                    this.attendedLessons = this.basis.attended_sessions || 0;
                    this.$nextTick(() => { this.refundAmount = this.suggestedAmount; });
                },

                get totalLessons() {
                    return this.basis.total_sessions || 0;
                },

                get remainingLessons() {
                    return Math.max(0, this.totalLessons - (this.attendedLessons || 0));
                },

                get unitPrice() {
                    return this.totalLessons > 0 ? (this.basis.contract / this.totalLessons) : 0;
                },

                // Giá trị còn lại = đã nộp − giá trị các buổi đã học (không âm).
                get remainingValue() {
                    const used = Math.min(this.basis.paid, this.unitPrice * (this.attendedLessons || 0));
                    return Math.max(0, Math.round(this.basis.paid - used));
                },

                get adminFee() {
                    return Math.round(this.remainingValue * this.feePercent / 100);
                },

                get suggestedAmount() {
                    if (this.actionType === 'transfer') {
                        return Math.min(this.remainingValue, Math.round(this.unitPrice * this.remainingLessons));
                    }
                    if (this.actionType === 'refund') {
                        return Math.max(0, this.remainingValue - this.adminFee);
                    }
                    return 0;
                },

                money(v) {
                    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(v || 0);
                },
            };
        }
    </script>
</x-app-layout>
