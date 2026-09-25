<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('tuition.students') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">add_card</span>
                    Lập Phiếu Thu Học Phí (Create Receipt Voucher)
                </h1>
                <p class="text-xs text-gray-500">Tạo biên lai xác nhận thu tiền từ học viên qua tiền mặt hoặc chuyển khoản</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-6" x-data="{
        amount: 12500000,
        paymentMethod: 'transfer',
        receiptNumber: 'PT-2026-0889',
    }">
        <form action="{{ route('tuition.receipts.approve') }}" method="GET" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
            @csrf

            <!-- Section 1: Thông tin người nộp -->
            <div>
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-4 pb-2 border-b border-gray-100 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-base">person</span>
                    1. Thông tin học viên &amp; Lớp học
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Mã hoặc Tên Học viên <span class="text-rose-500">*</span></label>
                        <select class="w-full text-xs rounded-xl border border-gray-200 p-2.5">
                            <option>HV-1092 - Vũ Thị Minh Hằng (0983 234 567)</option>
                            <option>HV-1093 - Nguyễn Đình Trọng (0912 345 678)</option>
                            <option>HV-1094 - Trần Hồng Sơn (0945 999 888)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Lớp áp dụng thu phí</label>
                        <input type="text" readonly value="IELTS 6.5 - B2408" class="w-full text-xs bg-gray-50 rounded-xl border border-gray-200 p-2.5 text-gray-700 font-semibold" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Chi nhánh thu</label>
                        <select class="w-full text-xs rounded-xl border border-gray-200 p-2.5">
                            <option>Cơ sở 1 - Cầu Giấy</option>
                            <option>Cơ sở 2 - Hai Bà Trưng</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Người lập phiếu</label>
                        <input type="text" readonly value="{{ Auth::user()?->name ?? 'Admin Hệ thống' }}" class="w-full text-xs bg-gray-50 rounded-xl border border-gray-200 p-2.5 text-gray-700 font-semibold" />
                    </div>
                </div>
            </div>

            <!-- Section 2: Chi tiết khoản thu -->
            <div>
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-4 pb-2 border-b border-gray-100 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-base">payments</span>
                    2. Chi tiết số tiền &amp; Hình thức thanh toán
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Số tiền thực nộp (VNĐ) <span class="text-rose-500">*</span></label>
                        <input type="number" x-model.number="amount" required class="w-full text-sm font-bold font-mono text-primary rounded-xl border border-gray-200 p-2.5" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Phương thức thanh toán <span class="text-rose-500">*</span></label>
                        <select x-model="paymentMethod" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold">
                            <option value="transfer">Chuyển khoản Ngân hàng (VietQR)</option>
                            <option value="cash">Tiền mặt tại quầy</option>
                            <option value="pos">Quẹt thẻ POS / Thẻ tín dụng</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Mã giao dịch / Mã tham chiếu</label>
                        <input type="text" placeholder="Ví dụ: FT2608149882" class="w-full text-xs font-mono rounded-xl border border-gray-200 p-2.5" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Ngày thu tiền</label>
                        <input type="date" value="{{ date('Y-m-d') }}" class="w-full text-xs rounded-xl border border-gray-200 p-2.5" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="block font-semibold text-gray-700 mb-1">Nội dung / Ghi chú phiếu thu</label>
                        <textarea rows="2" placeholder="Thu học phí đợt 1 / Cọc giữ chỗ lớp IELTS..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5"></textarea>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <a href="{{ route('tuition.students') }}" class="px-4 py-2 border border-gray-200 text-xs font-semibold text-gray-700 rounded-xl hover:bg-gray-50">
                    Hủy bỏ
                </a>
                <button type="submit" class="px-6 py-2.5 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">print</span>
                    <span>Tạo Phiếu Thu &amp; Xuất Biên Lai</span>
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
