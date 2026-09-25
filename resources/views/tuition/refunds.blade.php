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
                        Khất Nợ / Hoàn Phí / Xé Lẻ &amp; Chuyển Nhượng Học Phí
                    </h1>
                    <p class="text-xs text-gray-500">Xử lý xé lẻ số buổi học thừa, chuyển nhượng số dư sang học viên khác và lưu nhật ký đối soát tài chính</p>
                </div>
            </div>
        </div>
    </x-slot>

    @include('tuition.partials.errors')

    <div class="max-w-5xl mx-auto space-y-6" x-data="refundTransferManager()">
        <form action="{{ route('tuition.refunds.store') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
            @csrf
            
            <!-- Select Action Type Tabs -->
            <div>
                <label class="block text-xs font-bold text-gray-900 uppercase tracking-wider mb-2">1. Chọn loại nghiệp vụ xử lý</label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <button type="button" @click="actionType = 'transfer'" class="p-3.5 rounded-2xl border text-xs font-bold transition flex items-center justify-center gap-2 cursor-pointer" :class="actionType === 'transfer' ? 'border-primary-container bg-orange-50/70 text-primary shadow-xs ring-1 ring-primary-container' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                        <span class="material-symbols-outlined text-base">swap_horiz</span>
                        <span>Xé lẻ &amp; Chuyển nhượng số dư</span>
                    </button>
                    <button type="button" @click="actionType = 'refund'" class="p-3.5 rounded-2xl border text-xs font-bold transition flex items-center justify-center gap-2 cursor-pointer" :class="actionType === 'refund' ? 'border-primary-container bg-orange-50/70 text-primary shadow-xs ring-1 ring-primary-container' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                        <span class="material-symbols-outlined text-base">undo</span>
                        <span>Hoàn trả học phí (Rút tiền)</span>
                    </button>
                    <button type="button" @click="actionType = 'extension'" class="p-3.5 rounded-2xl border text-xs font-bold transition flex items-center justify-center gap-2 cursor-pointer" :class="actionType === 'extension' ? 'border-primary-container bg-orange-50/70 text-primary shadow-xs ring-1 ring-primary-container' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                        <span class="material-symbols-outlined text-base">update</span>
                        <span>Xin gia hạn / Bảo lưu công nợ</span>
                    </button>
                </div>
            </div>

            <input type="hidden" name="type" :value="actionType" />
            <input type="hidden" name="total_paid" :value="totalPaid" />
            <input type="hidden" name="attended_lessons" :value="attendedLessons" />
            <input type="hidden" name="admin_fee" :value="adminFee" />
            <input type="hidden" name="refund_amount" :value="finalActionAmount" />

            <!-- Form fields -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <!-- Source Student -->
                <div>
                    <label class="block font-semibold text-gray-700 mb-1">
                        Học viên nguồn (Chuyển / Hoàn phí) <span class="text-rose-500">*</span>
                    </label>
                    <select name="student_id" x-model="selectedStudentId" @change="updateStudentInfo($event)" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold focus:border-primary-container focus:ring-primary-container">
                        @foreach ($students as $st)
                            <option value="{{ $st->id }}" data-paid="{{ $st->tuition?->paid_amount ?? 12500000 }}">
                                {{ $st->code }} - {{ $st->name }} ({{ $st->currentClass?->name ?? 'Chưa gán lớp' }}) · Đã nộp: {{ number_format($st->tuition?->paid_amount ?? 0) }}đ
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Target Student (Only for Transfer) -->
                <div x-show="actionType === 'transfer'">
                    <label class="block font-semibold text-gray-700 mb-1">
                        Học viên thụ hưởng (Nhận chuyển nhượng) <span class="text-rose-500">*</span>
                    </label>
                    <select name="target_student_id" class="w-full text-xs rounded-xl border border-emerald-300 bg-emerald-50/40 p-2.5 font-bold focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">-- Chọn học viên nhận chuyển nhượng --</option>
                        @foreach ($students as $st)
                            <option value="{{ $st->id }}">
                                {{ $st->code }} - {{ $st->name }} ({{ $st->currentClass?->name ?? 'Chưa gán lớp' }}) · Còn nợ: {{ number_format($st->tuition?->debt_amount ?? 0) }}đ
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Total Paid -->
                <div :class="actionType !== 'transfer' ? '' : 'md:col-span-2'">
                    <label class="block font-semibold text-gray-700 mb-1">Tổng học phí đã đóng ban đầu (VNĐ)</label>
                    <input type="number" x-model.number="totalPaid" class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5" />
                </div>

                <!-- Calculation Box for Transfer (Xé lẻ số buổi thừa) -->
                <template x-if="actionType === 'transfer'">
                    <div class="md:col-span-2 p-4 bg-emerald-50/50 rounded-2xl border border-emerald-200/80 space-y-3">
                        <div class="font-bold text-emerald-950 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-emerald-600 text-base">calculate</span>
                            <span>Bảng tính Xé Lẻ Số Buổi &amp; Giá Trị Chuyển Nhượng:</span>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                            <div>
                                <span class="text-gray-500 block mb-1">Tổng số buổi khóa:</span>
                                <input type="number" x-model.number="totalLessons" min="1" class="w-full text-xs rounded-lg border border-gray-200 p-2 font-bold font-mono bg-white" />
                            </div>
                            <div>
                                <span class="text-gray-500 block mb-1">Số buổi đã học:</span>
                                <input type="number" x-model.number="attendedLessons" min="0" class="w-full text-xs rounded-lg border border-gray-200 p-2 font-bold font-mono bg-white" />
                            </div>
                            <div>
                                <span class="text-gray-500 block mb-1 font-semibold text-emerald-800">Số buổi còn thừa:</span>
                                <div class="font-mono font-black text-emerald-700 text-sm mt-1" x-text="remainingLessons + ' buổi'"></div>
                            </div>
                            <div>
                                <span class="text-gray-500 block mb-1 font-semibold text-emerald-800">Đơn giá/buổi:</span>
                                <div class="font-mono font-bold text-gray-700 text-xs mt-1" x-text="new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(pricePerLesson)"></div>
                            </div>
                        </div>
                        <div class="pt-2 border-t border-emerald-200/60 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                            <span class="text-gray-600">Tổng tiền chuyển nhượng sang học viên nhận (Tự động trừ công nợ người nhận):</span>
                            <span class="font-mono font-black text-emerald-700 text-base" x-text="new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(finalActionAmount)"></span>
                        </div>
                    </div>
                </template>

                <!-- Calculation Box for Refund -->
                <template x-if="actionType === 'refund'">
                    <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-3 p-4 bg-orange-50/40 rounded-2xl border border-orange-200">
                        <div>
                            <span class="text-gray-500 block mb-1">Số buổi đã học:</span>
                            <input type="number" x-model.number="attendedLessons" class="w-full text-xs rounded-lg border border-gray-200 p-2 font-bold bg-white" />
                        </div>
                        <div>
                            <span class="text-gray-500 block mb-1">Phí quản trị hoàn hủy (10%):</span>
                            <div class="font-mono font-bold text-gray-800 text-sm mt-1" x-text="new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(adminFee)"></div>
                        </div>
                        <div>
                            <span class="text-gray-500 block mb-1 font-bold text-primary">Số tiền hoàn thực tế:</span>
                            <div class="font-mono font-extrabold text-primary text-base mt-1" x-text="new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(finalActionAmount)"></div>
                        </div>
                    </div>
                </template>

                <div class="md:col-span-2">
                    <label class="block font-semibold text-gray-700 mb-1">Lý do &amp; Căn cứ phê duyệt <span class="text-rose-500">*</span></label>
                    <textarea name="reason" rows="3" required placeholder="Ghi rõ lý do (Ví dụ: Học viên bận công tác, chuyển nhượng 8 buổi học thừa sang cho bạn Nguyễn Văn B học lớp IELTS Intensive)..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <button type="submit" class="px-6 py-2.5 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-base">send</span>
                    <span>Gửi yêu cầu phê duyệt vào CSDL</span>
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
                                    @else
                                        <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-bold">Gia hạn / Khất nợ</span>
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
        function refundTransferManager() {
            return {
                actionType: 'transfer',
                selectedStudentId: '{{ $students->first()?->id ?? '' }}',
                totalPaid: 12500000,
                totalLessons: 24,
                attendedLessons: 4,

                get remainingLessons() {
                    return Math.max(0, this.totalLessons - this.attendedLessons);
                },

                get pricePerLesson() {
                    return this.totalLessons > 0 ? (this.totalPaid / this.totalLessons) : 0;
                },

                get adminFee() {
                    if (this.actionType === 'refund') {
                        return this.totalPaid * 0.1;
                    }
                    return 0;
                },

                get finalActionAmount() {
                    if (this.actionType === 'transfer') {
                        return Math.round(this.pricePerLesson * this.remainingLessons);
                    }
                    if (this.actionType === 'refund') {
                        const feeUsed = this.pricePerLesson * this.attendedLessons;
                        return Math.max(0, Math.round(this.totalPaid - feeUsed - this.adminFee));
                    }
                    return this.totalPaid;
                },

                updateStudentInfo(e) {
                    const opt = e.target.options[e.target.selectedIndex];
                    const paid = parseFloat(opt.getAttribute('data-paid') || 12500000);
                    if (paid > 0) {
                        this.totalPaid = paid;
                    }
                }
            };
        }
    </script>
</x-app-layout>
