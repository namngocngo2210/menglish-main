<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">receipt_long</span>
                    Lịch sử Giao dịch &amp; Biên lai Học phí (Database)
                </h1>
                <p class="text-xs text-gray-500">Tra cứu toàn bộ phiếu thu đã xuất, in phiếu thu A4/A5 và tình trạng tiền về tài khoản</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('tuition.config') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold shadow-xs transition">
                    <span class="material-symbols-outlined text-[18px]">settings</span>
                    <span>Cấu hình dải số HĐĐT</span>
                </a>
                <a href="{{ route('tuition.receipts.create') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition">
                    <span class="material-symbols-outlined text-[18px]">add_card</span>
                    <span>Lập Phiếu thu mới</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4" x-data="{
        showPrintModal: false,
        selectedReceipt: null,
        openPrintModal(rc) {
            this.selectedReceipt = rc;
            this.showPrintModal = true;
        },
        closePrintModal() {
            this.showPrintModal = false;
            this.selectedReceipt = null;
        },
        printVoucher() {
            window.print();
        }
    }">
        <!-- Receipts table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Số Phiếu thu</th>
                            <th class="py-3 px-4">Số HĐĐT</th>
                            <th class="py-3 px-4">Học viên</th>
                            <th class="py-3 px-4">Lớp học</th>
                            <th class="py-3 px-4 text-right">Số tiền thu</th>
                            <th class="py-3 px-4">Hình thức</th>
                            <th class="py-3 px-4">Mã GD / Ngân hàng</th>
                            <th class="py-3 px-4">Người lập</th>
                            <th class="py-3 px-4">Thời gian</th>
                            <th class="py-3 px-4">Trạng thái</th>
                            <th class="py-3 px-4 text-right">In phiếu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($receipts as $rc)
                            <tr class="hover:bg-orange-50/20 transition">
                                <td class="py-3.5 px-4 font-mono font-bold text-gray-900">{{ $rc->receipt_number }}</td>
                                <td class="py-3.5 px-4 font-mono font-semibold text-indigo-600">
                                    {{ $rc->invoice_number ?? 'HD-' . str_pad($rc->id, 6, '0', STR_PAD_LEFT) }}
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-gray-900">{{ $rc->tuition?->student?->name }}</div>
                                    <div class="text-[10px] text-gray-400 font-mono">{{ $rc->tuition?->student?->code }}</div>
                                </td>
                                <td class="py-3.5 px-4 font-semibold text-primary">{{ $rc->tuition?->classModel?->name ?? '—' }}</td>
                                <td class="py-3.5 px-4 text-right font-mono font-bold text-emerald-600 text-sm whitespace-nowrap">
                                    {{ number_format($rc->amount) }}đ
                                </td>
                                <td class="py-3.5 px-4">
                                    <span class="px-2 py-0.5 rounded-lg bg-gray-100 font-medium text-[11px]">
                                        {{ $rc->payment_method === 'transfer' ? 'Chuyển khoản VietQR' : ($rc->payment_method === 'cash' ? 'Tiền mặt' : 'Quẹt thẻ POS') }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-gray-600">{{ $rc->transaction_code ?? '—' }}</td>
                                <td class="py-3.5 px-4">{{ $rc->creator?->name ?? 'Admin' }}</td>
                                <td class="py-3.5 px-4 text-gray-500 font-mono text-[11px] whitespace-nowrap">{{ $rc->created_at->format('d/m/Y H:i') }}</td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $rc->status_badge }}">{{ $rc->status_label }}</span>
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <button 
                                        type="button" 
                                        @click="openPrintModal({
                                            receipt_number: '{{ $rc->receipt_number }}',
                                            invoice_number: '{{ $rc->invoice_number ?? 'HD-' . str_pad($rc->id, 6, '0', STR_PAD_LEFT) }}',
                                            student_name: '{{ addslashes($rc->tuition?->student?->name ?? '') }}',
                                            student_code: '{{ $rc->tuition?->student?->code ?? '' }}',
                                            student_phone: '{{ $rc->tuition?->student?->phone ?? '' }}',
                                            class_name: '{{ addslashes($rc->tuition?->classModel?->name ?? 'Lớp Tiếng Anh Chuẩn') }}',
                                            branch_name: '{{ addslashes($rc->tuition?->branch?->name ?? 'Cơ sở Cầu Giấy, Hà Nội') }}',
                                            amount: {{ (float) $rc->amount }},
                                            payment_method: '{{ $rc->payment_method === 'transfer' ? 'Chuyển khoản VietQR' : ($rc->payment_method === 'cash' ? 'Tiền mặt' : 'Quẹt thẻ POS') }}',
                                            creator_name: '{{ addslashes($rc->creator?->name ?? 'Thu ngân / Kế toán') }}',
                                            approver_name: '{{ addslashes($rc->approver?->name ?? 'Kế toán trưởng') }}',
                                            created_at: '{{ $rc->created_at->format('d/m/Y') }}',
                                            notes: '{{ addslashes($rc->notes ?? 'Thu học phí khóa học') }}'
                                        })"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-gray-100 hover:bg-primary hover:text-white text-gray-700 text-xs font-semibold transition cursor-pointer"
                                        title="Xem &amp; In Phiếu thu học phí chuẩn"
                                    >
                                        <span class="material-symbols-outlined text-[15px]">print</span>
                                        <span>In phiếu</span>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center py-8 text-gray-400 text-xs">Chưa có phiếu thu nào trong CSDL.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$receipts" />
        </div>

        <!-- PRINT RECEIPT MODAL (Mẫu Phiếu Thu Chuẩn A5/A4) -->
        <div 
            x-show="showPrintModal" 
            x-cloak 
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-gray-900/60 backdrop-blur-xs"
        >
            <div 
                @click.away="closePrintModal()" 
                class="bg-white rounded-3xl border border-gray-200 shadow-2xl w-full max-w-2xl overflow-hidden my-8"
            >
                <!-- Modal Top Header (Invisible on Print) -->
                <div class="p-4 bg-gray-50 border-b border-gray-100 flex items-center justify-between print:hidden">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">receipt_long</span>
                        <span class="text-sm font-bold text-gray-900">Xem &amp; In Phiếu Thu Học Phí (Mẫu In Chuẩn)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button 
                            type="button" 
                            @click="printVoucher()" 
                            class="px-4 py-1.5 bg-primary hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center gap-1.5 cursor-pointer"
                        >
                            <span class="material-symbols-outlined text-[16px]">print</span>
                            <span>In phiếu ngay (A4/A5)</span>
                        </button>
                        <button type="button" @click="closePrintModal()" class="p-1 text-gray-400 hover:text-gray-700 rounded-lg hover:bg-gray-200/50 transition">
                            <span class="material-symbols-outlined text-lg">close</span>
                        </button>
                    </div>
                </div>

                <!-- Printable Voucher Body -->
                <div class="p-8 space-y-6 text-gray-900 bg-white" id="printableReceipt">
                    <!-- Voucher Header -->
                    <div class="flex items-start justify-between border-b pb-4 border-gray-200">
                        <div>
                            <div class="text-xs font-black uppercase tracking-wider text-primary">HỆ THỐNG ANH NGỮ MENGLISH</div>
                            <div class="text-[11px] text-gray-500 font-medium">Trụ sở: Cầu Giấy, Hà Nội · Hotline: 1900 8899</div>
                            <div class="text-[10px] text-gray-400">Website: https://menglish.edu.vn · MST: 0109988234</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs font-mono font-bold text-gray-800">
                                Mẫu số: <span class="text-gray-600">01GTKT0/001</span>
                            </div>
                            <div class="text-xs font-mono font-bold text-gray-800">
                                Ký hiệu: <span class="text-gray-600">C26MEN</span>
                            </div>
                            <div class="text-xs font-mono font-bold text-indigo-700">
                                Số HĐĐT: <span x-text="selectedReceipt?.invoice_number"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Voucher Title -->
                    <div class="text-center space-y-1">
                        <h2 class="text-xl font-black tracking-tight text-gray-900 uppercase">PHIẾU THU HỌC PHÍ</h2>
                        <p class="text-xs text-gray-500 font-medium">
                            Số phiếu: <strong class="font-mono text-gray-800" x-text="selectedReceipt?.receipt_number"></strong> · 
                            Ngày lập: <span x-text="selectedReceipt?.created_at"></span>
                        </p>
                    </div>

                    <!-- Student & Payment Info -->
                    <div class="bg-gray-50/80 rounded-2xl p-4 border border-gray-200/80 space-y-2.5 text-xs">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Họ và tên người nộp / Học viên:</span>
                            <span class="font-bold text-gray-900" x-text="selectedReceipt?.student_name + ' (' + selectedReceipt?.student_code + ')'"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Số điện thoại liên hệ:</span>
                            <span class="font-mono font-semibold text-gray-800" x-text="selectedReceipt?.student_phone || '—'"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Khóa học / Lớp học:</span>
                            <span class="font-bold text-primary" x-text="selectedReceipt?.class_name"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Cơ sở đào tạo:</span>
                            <span class="font-medium text-gray-700" x-text="selectedReceipt?.branch_name"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Hình thức thanh toán:</span>
                            <span class="font-semibold text-gray-800" x-text="selectedReceipt?.payment_method"></span>
                        </div>
                        <div class="flex justify-between pt-2 border-t border-gray-200">
                            <span class="text-gray-700 font-bold">Số tiền thực thu (VNĐ):</span>
                            <span class="font-mono font-black text-emerald-700 text-base" x-text="new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(selectedReceipt?.amount || 0)"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Nội dung thu:</span>
                            <span class="italic text-gray-700" x-text="selectedReceipt?.notes"></span>
                        </div>
                    </div>

                    <!-- Signatures Section -->
                    <div class="grid grid-cols-3 gap-4 text-center text-xs pt-4">
                        <div class="space-y-12">
                            <div class="font-bold text-gray-800">Người nộp tiền</div>
                            <div class="text-[11px] text-gray-400 italic">(Ký &amp; ghi rõ họ tên)</div>
                        </div>
                        <div class="space-y-12">
                            <div class="font-bold text-gray-800">Người lập phiếu</div>
                            <div class="text-xs font-semibold text-gray-700" x-text="selectedReceipt?.creator_name"></div>
                        </div>
                        <div class="space-y-12">
                            <div class="font-bold text-gray-800">Kế toán trưởng / Thủ quỹ</div>
                            <div class="text-xs font-semibold text-gray-700" x-text="selectedReceipt?.approver_name"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
