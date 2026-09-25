<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('tuition.students') }}" class="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-500 hover:text-slate-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-slate-900 tracking-tight flex items-center gap-2.5">
                        <span>Duyệt hủy hóa đơn</span>
                        <span class="text-xs font-semibold px-2.5 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-full inline-flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                            {{ $pendingCount }} yêu cầu chờ xử lý
                        </span>
                        <span class="text-[11px] font-medium bg-slate-100 text-slate-600 px-2 py-0.5 rounded border border-slate-200">
                            Chỉ Admin phê duyệt
                        </span>
                    </h1>
                    <p class="text-xs text-slate-500 mt-0.5">Kiểm soát và phê duyệt các yêu cầu hủy hóa đơn thu học phí &amp; phụ thu từ Học vụ / CM. Chống thất thoát và nhảy số hóa đơn tự ý.</p>
                </div>
            </div>

            <button type="button" onclick="document.getElementById('newCancelModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl shadow-xs transition">
                <span class="material-symbols-outlined text-[18px]">add_circle</span>
                <span>Tạo yêu cầu hủy HĐ</span>
            </button>
        </div>
    </x-slot>

    <div class="max-w-[1520px] mx-auto space-y-5" x-data="{ showConfirmModal: false, showRejectModal: false, zoomProof: false }">

        @if (isset($errors) && $errors->any())
            <div class="p-4 bg-rose-50 border-l-4 border-rose-500 rounded-r-xl text-xs text-rose-800 space-y-1 shadow-xs">
                <div class="font-bold flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-rose-600 text-base">error</span>
                    Vui lòng kiểm tra các lỗi:
                </div>
                <ul class="list-disc list-inside pl-5">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Header Panel: Thống kê & Báo cáo nhanh -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
            <div>
                <h2 class="text-base font-bold text-slate-900">Báo cáo kiểm toán dải số hóa đơn &amp; yêu cầu hủy</h2>
                <p class="text-xs text-slate-500">Mọi thao tác duyệt hủy đều kích hoạt cơ chế trừ lùi doanh thu và hoàn trả công nợ học viên tự động</p>
            </div>

            <!-- Thẻ tóm tắt chỉ số -->
            <div class="flex items-center gap-3 text-xs">
                <div class="bg-amber-50/80 border border-amber-200/80 px-4 py-2.5 rounded-xl text-right">
                    <div class="text-[10px] font-bold text-amber-700 uppercase">Chờ duyệt hủy</div>
                    <div class="text-lg font-bold text-amber-900">{{ $pendingCount }} <span class="text-xs font-normal text-amber-700">hóa đơn</span></div>
                </div>
                <div class="bg-emerald-50/80 border border-emerald-200/80 px-4 py-2.5 rounded-xl text-right">
                    <div class="text-[10px] font-bold text-emerald-700 uppercase">Đã duyệt hủy (Tháng này)</div>
                    <div class="text-lg font-bold text-emerald-900">{{ $approvedMonthCount }} <span class="text-xs font-normal text-emerald-700">hóa đơn</span></div>
                </div>
                <div class="bg-slate-50 border border-slate-200 px-4 py-2.5 rounded-xl text-right">
                    <div class="text-[10px] font-bold text-slate-600 uppercase">Đã từ chối</div>
                    <div class="text-lg font-bold text-slate-800">{{ $rejectedCount }} <span class="text-xs font-normal text-slate-500">yêu cầu</span></div>
                </div>
            </div>
        </div>

        <!-- Banner cảnh báo nghiệp vụ quan trọng -->
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600 mt-0.5 text-xl">info</span>
            <div class="text-xs text-blue-900 space-y-1">
                <div class="font-bold text-blue-950">Quy tắc nghiệp vụ kiểm soát số hóa đơn &amp; hoàn tác công nợ (Cập nhật 11/09/2026):</div>
                <div class="grid md:grid-cols-2 gap-x-6 gap-y-1 text-blue-800">
                    <p>• <strong>Bảo toàn dải số:</strong> Số hóa đơn giấy đã hủy vẫn tính là <em>đã sử dụng</em> trong dải số, tuyệt đối không được cấp phát hay tái sử dụng lại.</p>
                    <p>• <strong>Tự động hoàn tác công nợ:</strong> Khi duyệt hủy phiếu từng ở trạng thái <em>"Đã duyệt"</em>, hệ thống sẽ tự động trừ lùi số tiền đã thu và hoàn trả công nợ còn lại của học viên.</p>
                </div>
            </div>
        </div>

        <!-- Thanh lọc & tìm kiếm -->
        <form method="GET" action="{{ route('tuition.invoices.cancellations') }}" class="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-xl border border-slate-200 text-xs">
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-72">
                    <span class="material-symbols-outlined absolute left-3 top-2 text-slate-400 text-base pointer-events-none">search</span>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Tìm theo mã phiếu, số hóa đơn, học viên..." class="pl-9 pr-8 py-2 text-xs border border-slate-200 rounded-lg w-full focus:ring-primary-container focus:border-primary-container" />
                    @if (request('q'))
                        <a href="{{ route('tuition.invoices.cancellations', request()->except('q')) }}" class="absolute right-2.5 top-2 text-slate-400 hover:text-slate-600">
                            <span class="material-symbols-outlined text-base">close</span>
                        </a>
                    @endif
                </div>

                <select name="branch_id" onchange="this.form.submit()" class="text-xs border border-slate-200 rounded-lg px-3 py-2 bg-white text-slate-700 cursor-pointer">
                    <option value="all">Tất cả cơ sở</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </select>

                <select name="status" onchange="this.form.submit()" class="text-xs border border-slate-200 rounded-lg px-3 py-2 bg-white text-slate-700 font-bold cursor-pointer">
                    <option value="pending" {{ request('status', 'pending') === 'pending' ? 'selected' : '' }}>Trạng thái: Chờ duyệt hủy ({{ $pendingCount }})</option>
                    <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Đã duyệt hủy</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Đã từ chối hủy</option>
                    <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>Tất cả</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('tuition.invoices.cancellations') }}" class="inline-flex items-center gap-1 px-3 py-2 text-xs font-medium text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition">
                    <span class="material-symbols-outlined text-sm">refresh</span> Làm mới
                </a>
            </div>
        </form>

        <!-- Bố cục Master - Detail: Danh sách chờ duyệt (5 cột) & Chi tiết đối soát (7 cột) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            <!-- CỘT TRÁI (5 Cột): Danh sách yêu cầu chờ duyệt -->
            <div class="lg:col-span-5 space-y-4 min-w-0">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-2">
                        <span>Danh sách yêu cầu hủy</span>
                        <span class="bg-amber-100 text-amber-800 text-xs px-2 py-0.5 rounded-full font-semibold font-mono">{{ $cancellations->count() }}</span>
                    </h3>
                    <span class="text-[11px] text-slate-500">Xếp theo thời gian gửi gần nhất</span>
                </div>

                <div class="space-y-3 max-h-[820px] overflow-y-auto pr-1">
                    @forelse ($cancellations as $can)
                        @php
                            $isSelected = $selectedCancellation && $selectedCancellation->id === $can->id;
                            $st = $can->student ?? $can->receipt?->tuition?->student ?? $can->receipt?->student;
                        @endphp
                        <a href="{{ route('tuition.invoices.cancellations', array_merge(request()->all(), ['selected_id' => $can->id])) }}" class="block bg-white rounded-2xl p-4 shadow-sm relative cursor-pointer transition border {{ $isSelected ? 'border-primary-container ring-2 ring-primary-container/20 shadow-md' : 'border-slate-200 hover:border-slate-300' }}">
                            @if ($isSelected)
                                <div class="absolute -left-1 top-6 bottom-6 w-1 bg-primary-container rounded-r"></div>
                            @endif

                            <div class="flex items-start justify-between gap-2 pb-2.5 border-b border-slate-100">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-slate-900 text-xs font-mono">{{ $can->invoice_number }}</span>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                            Hóa đơn giấy
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        Mã phiếu thu: <strong class="text-slate-700 font-mono">{{ $can->receipt?->receipt_number ?? 'PT-Trực tiếp' }}</strong>
                                    </div>
                                </div>
                                <div class="text-right">
                                    @if ($isSelected)
                                        <span class="text-[11px] font-bold text-primary bg-orange-50 px-2.5 py-0.5 rounded-full border border-orange-200">
                                            Đang xem
                                        </span>
                                    @elseif ($can->status === 'approved')
                                        <span class="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                            Đã duyệt hủy
                                        </span>
                                    @elseif ($can->status === 'rejected')
                                        <span class="text-[10px] font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded-full border border-rose-200">
                                            Đã từ chối
                                        </span>
                                    @else
                                        <span class="text-[10px] font-medium text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200">
                                            Chờ duyệt
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="py-2.5 space-y-1.5 text-xs">
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500">Học viên:</span>
                                    <span class="font-bold text-slate-900">{{ $st?->name ?? '—' }} <span class="text-slate-400 font-normal font-mono">({{ $st?->code ?? 'HV' }})</span></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500">Số tiền trên hóa đơn:</span>
                                    <span class="font-bold font-mono text-xs text-slate-900">{{ number_format($can->amount) }} VNĐ</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500">Người yêu cầu hủy:</span>
                                    <span class="font-medium text-slate-700">{{ $can->requester?->name ?? 'Lê Thị Bích (Học vụ)' }}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500">Thời điểm gửi yêu cầu:</span>
                                    <span class="text-slate-600 font-mono text-[11px]">{{ $can->created_at->format('H:i - d/m/Y') }}</span>
                                </div>
                            </div>

                            <div class="mt-1 p-2.5 bg-rose-50/70 border border-rose-100 rounded-xl">
                                <div class="text-[10px] font-bold text-rose-800 flex items-center gap-1 mb-0.5">
                                    <span class="material-symbols-outlined text-xs">error</span> Lý do hủy từ Học vụ:
                                </div>
                                <p class="text-xs text-rose-900 line-clamp-2 italic">
                                    "{{ $can->reason }}"
                                </p>
                            </div>
                        </a>
                    @empty
                        <div class="bg-white rounded-2xl p-8 border border-slate-200 text-center text-slate-400 text-xs space-y-2">
                            <span class="material-symbols-outlined text-3xl text-slate-300">task</span>
                            <p>Không có yêu cầu hủy hóa đơn nào.</p>
                        </div>
                    @endforelse
                </div>

                <div class="p-3 bg-amber-50/60 border border-amber-200/60 rounded-xl text-xs text-amber-800 flex items-center gap-2">
                    <span class="material-symbols-outlined text-amber-600 text-sm">lock</span>
                    <span>Hệ thống tự động khóa không cho tạo yêu cầu thứ 2 đối với phiếu đang có yêu cầu chờ duyệt.</span>
                </div>
            </div>

            <!-- CỘT PHẢI (7 Cột): Chi tiết phiếu thu & Thao tác phê duyệt của Admin -->
            <div class="lg:col-span-7 space-y-5 min-w-0">
                @if ($selectedCancellation)
                    @php
                        $st = $selectedCancellation->student ?? $selectedCancellation->receipt?->tuition?->student ?? $selectedCancellation->receipt?->student;
                        $rc = $selectedCancellation->receipt;
                        $className = $rc?->tuition?->classModel?->name ?? $st?->currentClass?->name ?? 'IELTS Foundation 02';
                        $branchName = $st?->branch?->name ?? 'Cơ sở Cầu Giấy';
                    @endphp

                    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 md:p-6 space-y-5">
                        <!-- Tiêu đề chi tiết & Trạng thái kép -->
                        <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-slate-200">
                            <div>
                                <div class="flex items-center gap-2.5">
                                    <h3 class="text-base font-bold text-slate-900">Chi tiết yêu cầu hủy: <span class="font-mono text-rose-600">{{ $selectedCancellation->invoice_number }}</span></h3>
                                    @if ($selectedCancellation->status === 'approved')
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                            Đã duyệt hủy
                                        </span>
                                    @elseif ($selectedCancellation->status === 'rejected')
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800 border border-rose-200">
                                            Đã từ chối hủy
                                        </span>
                                    @else
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800 border border-rose-200">
                                            Đang yêu cầu hủy
                                        </span>
                                    @endif
                                </div>
                                <p class="text-xs text-slate-500 mt-1">
                                    Gắn với phiếu thu: <strong class="text-slate-800 font-mono">{{ $rc?->receipt_number ?? 'PT-Gốc' }}</strong> • Tạo ngày {{ $selectedCancellation->created_at->format('d/m/Y') }} bởi <span class="font-medium text-slate-700">{{ $selectedCancellation->requester?->name ?? 'Lê Thị Bích (Học vụ)' }}</span>
                                </p>
                            </div>

                            <!-- Trạng thái kép rõ ràng -->
                            <div class="flex flex-col items-end gap-1 text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="text-slate-500">Trạng thái phiếu thu:</span>
                                    <span class="font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200 text-[11px]">
                                        Đã duyệt (MH3)
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-slate-500">Trạng thái hóa đơn:</span>
                                    <span class="font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded border border-amber-200 text-[11px]">
                                        {{ $selectedCancellation->status === 'approved' ? 'Đã duyệt hủy' : ($selectedCancellation->status === 'rejected' ? 'Bị từ chối' : 'Chờ duyệt hủy') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Khối 1: Lý do yêu cầu hủy (Nổi bật nhất) -->
                        <div class="p-4 bg-rose-50 border border-rose-200 rounded-xl space-y-2">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2 text-rose-900 font-bold text-xs uppercase">
                                    <span class="material-symbols-outlined text-rose-600 text-base">report_problem</span>
                                    Thông tin yêu cầu hủy hóa đơn
                                </div>
                                <span class="text-[11px] text-rose-700 font-medium">Bắt buộc xem xét kỹ</span>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs pt-1">
                                <div>
                                    <span class="text-rose-700 text-[11px]">Người gửi yêu cầu:</span>
                                    <div class="font-bold text-slate-900 mt-0.5">{{ $selectedCancellation->requester?->name ?? 'Lê Thị Bích' }} (Học vụ - {{ $branchName }})</div>
                                </div>
                                <div>
                                    <span class="text-rose-700 text-[11px]">Thời gian gửi:</span>
                                    <div class="font-bold text-slate-900 mt-0.5 font-mono">{{ $selectedCancellation->created_at->format('H:i:s - d/m/Y') }}</div>
                                </div>
                            </div>

                            <div class="pt-2 border-t border-rose-200/60 text-xs">
                                <span class="font-bold text-rose-900 block mb-1">Nội dung giải trình lý do hủy (*):</span>
                                <p class="text-slate-800 bg-white p-3 rounded-lg border border-rose-200 leading-relaxed italic">
                                    "{{ $selectedCancellation->reason }}"
                                </p>
                            </div>
                        </div>

                        <!-- Khối 2: Tác động tài chính & Hoàn tác công nợ tự động (R-11) -->
                        <div class="p-4 bg-amber-50/70 border border-amber-200 rounded-xl space-y-2 text-xs">
                            <div class="flex items-center gap-2 text-amber-950 font-bold text-xs uppercase">
                                <span class="material-symbols-outlined text-amber-600 text-base">sync_alt</span>
                                Hệ thống sẽ tự động thực hiện khi Admin bấm "Duyệt hủy hóa đơn":
                            </div>
                            <ul class="text-amber-900 space-y-1.5 pl-5 list-disc leading-relaxed">
                                <li>
                                    <strong>Trừ lùi công nợ (Revert):</strong> Vì phiếu <code>{{ $rc?->receipt_number ?? 'Gốc' }}</code> trước đó đã ở trạng thái <em>"Đã duyệt"</em>, hệ thống sẽ tự động trừ <strong>{{ number_format($selectedCancellation->amount) }} VNĐ</strong> khỏi <code>tong_da_thu</code> và cộng ngược <strong>{{ number_format($selectedCancellation->amount) }} VNĐ</strong> vào <code>tong_con_lai</code> của học viên {{ $st?->name }}.
                                </li>
                                <li>
                                    <strong>Khóa số hóa đơn:</strong> Số hóa đơn <code>{{ $selectedCancellation->invoice_number }}</code> chuyển thành <em>"Đã hủy"</em>, giữ nguyên lịch sử kiểm toán trong dải số và <strong>không được tái sử dụng</strong>.
                                </li>
                                <li>
                                    <strong>Ghi nhật ký hệ thống:</strong> Lưu vết người duyệt là <em>{{ Auth::user()?->name ?? 'Admin' }}</em> cùng thời điểm thực thi.
                                </li>
                            </ul>
                        </div>

                        <!-- Khối 3: Thông tin chi tiết phiếu thu gốc bị hủy -->
                        <div class="space-y-3">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400">Thông tin phiếu thu gốc</h4>

                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 bg-slate-50 p-4 rounded-xl border border-slate-200 text-xs">
                                <div>
                                    <span class="text-slate-400 text-[10px] uppercase block">Học viên</span>
                                    <span class="font-bold text-slate-900">{{ $st?->name ?? '—' }}</span>
                                    <span class="text-[11px] text-slate-500 font-mono block">Mã: {{ $st?->code ?? 'HV' }}</span>
                                </div>
                                <div>
                                    <span class="text-slate-400 text-[10px] uppercase block">Lớp học hiện tại</span>
                                    <span class="font-semibold text-slate-800">{{ $className }}</span>
                                    <span class="text-[11px] text-slate-500 block">{{ $branchName }}</span>
                                </div>
                                <div>
                                    <span class="text-slate-400 text-[10px] uppercase block">Người nộp tiền</span>
                                    <span class="font-semibold text-slate-800">{{ $rc?->payer_name ?: ($st?->parent_name ?: $st?->name) }}</span>
                                    <span class="text-[11px] text-slate-500 font-mono block">{{ $rc?->payer_phone ?: ($st?->parent_phone ?: $st?->phone) }}</span>
                                </div>
                                <div>
                                    <span class="text-slate-400 text-[10px] uppercase block">Hình thức thu</span>
                                    <span class="font-semibold text-slate-900 flex items-center gap-1 mt-0.5">
                                        <span class="material-symbols-outlined text-sm text-slate-600">payments</span>
                                        {{ ($rc?->payment_method === 'cash') ? 'Tiền mặt' : 'Chuyển khoản' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-slate-400 text-[10px] uppercase block">Số hóa đơn giấy</span>
                                    <span class="font-bold text-rose-700 font-mono">{{ $selectedCancellation->invoice_number }}</span>
                                </div>
                                <div>
                                    <span class="text-slate-400 text-[10px] uppercase block">Hóa đơn đỏ (VAT)</span>
                                    <span class="font-medium text-slate-600">{{ $rc?->is_vat_invoice ? 'Yêu cầu VAT' : 'Không yêu cầu' }}</span>
                                </div>
                            </div>

                            <!-- Bảng kê các khoản tiền trên hóa đơn -->
                            <div class="border border-slate-200 rounded-xl overflow-hidden text-xs">
                                <table class="w-full text-left">
                                    <thead class="bg-slate-100/80 text-slate-700 border-b border-slate-200 font-semibold text-[11px]">
                                        <tr>
                                            <th class="py-2.5 px-3">Khoản mục</th>
                                            <th class="py-2.5 px-3">Nội dung chi tiết</th>
                                            <th class="py-2.5 px-3 text-right">Số tiền</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @if ($rc && $rc->tuition_amount > 0)
                                            <tr>
                                                <td class="py-2.5 px-3 font-semibold text-slate-800">Học phí đào tạo</td>
                                                <td class="py-2.5 px-3 text-slate-600">Khóa {{ $className }} (Đã giảm trừ: {{ number_format($rc->discount_amount ?? 0) }} đ)</td>
                                                <td class="py-2.5 px-3 text-right font-mono font-semibold text-slate-900">{{ number_format($rc->tuition_amount) }} VNĐ</td>
                                            </tr>
                                        @endif
                                        @if ($rc && $rc->surcharge_amount > 0)
                                            <tr>
                                                <td class="py-2.5 px-3 font-semibold text-primary flex items-center gap-1">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-primary-container"></span> Phụ thu phát sinh
                                                </td>
                                                <td class="py-2.5 px-3 text-slate-600">{{ $rc->surcharge_reason ?: 'Phụ thu giáo trình & học liệu' }}</td>
                                                <td class="py-2.5 px-3 text-right font-mono font-semibold text-primary">+{{ number_format($rc->surcharge_amount) }} VNĐ</td>
                                            </tr>
                                        @endif
                                        <tr class="bg-slate-50/80 font-bold">
                                            <td colspan="2" class="py-3 px-3 text-slate-900 text-xs">TỔNG SỐ TIỀN TRÊN HÓA ĐƠN:</td>
                                            <td class="py-3 px-3 text-right text-sm font-mono text-primary">{{ number_format($selectedCancellation->amount) }} VNĐ</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Xem trước ảnh hóa đơn bị hỏng/viết sai -->
                            <div class="border border-slate-200 rounded-xl p-4 space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-slate-800 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-slate-500 text-base">image</span>
                                        Ảnh chụp minh chứng hóa đơn hỏng / gạch chéo hủy:
                                    </span>
                                    <span class="text-slate-400 font-mono text-[11px]">{{ $selectedCancellation->proof_image ? basename($selectedCancellation->proof_image) : 'hoadon_gachcheo_huy.jpg' }}</span>
                                </div>

                                <div class="relative bg-slate-900 rounded-xl p-4 flex items-center justify-center text-slate-300 min-h-[160px] border border-slate-800 overflow-hidden">
                                    @if ($selectedCancellation->proof_image)
                                        <img src="{{ $selectedCancellation->proof_image }}" alt="Minh chứng hủy" class="max-h-56 object-contain rounded-lg cursor-pointer" @click="zoomProof = true" />
                                    @else
                                        <div class="text-center space-y-2">
                                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-slate-800 text-slate-400">
                                                <span class="material-symbols-outlined text-2xl">receipt_long</span>
                                            </div>
                                            <div class="text-xs">
                                                <div class="font-mono text-amber-400 font-bold">HÓA ĐƠN SỐ: {{ $selectedCancellation->invoice_number }} [ĐÃ GẠCH CHÉO HỦY]</div>
                                                <div class="text-slate-400 text-[11px]">Học viên: {{ $st?->name }} • Đã gạch chéo 3 liên • Chữ ký xác nhận người viết</div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Khu vực hành động của Admin (Có xác nhận lần 2) -->
                        <div class="pt-4 border-t border-slate-200 space-y-3">
                            <div class="text-[11px] text-slate-500 flex items-center justify-between">
                                <span>Quyền thực hiện: <strong class="text-slate-700">{{ Auth::user()?->name ?? 'Admin' }} (Admin)</strong></span>
                                <span class="text-slate-400">Hệ thống ghi nhận thời điểm thao tác chính xác vào Audit Log</span>
                            </div>

                            @if ($selectedCancellation->status === 'pending')
                                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 flex flex-col sm:flex-row items-center justify-between gap-4">
                                    <div class="space-y-0.5">
                                        <div class="text-xs font-bold text-slate-900 flex items-center gap-1.5">
                                            <span class="material-symbols-outlined text-amber-500 text-base">verified_user</span>
                                            Xác nhận xử lý yêu cầu hủy hóa đơn
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            Thao tác Duyệt sẽ hoàn tác công nợ học viên và khóa vĩnh viễn số hóa đơn này.
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2.5 w-full sm:w-auto">
                                        <button type="button" @click="showRejectModal = true" class="flex-1 sm:flex-initial inline-flex items-center justify-center gap-1 px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-100 rounded-xl transition shadow-2xs">
                                            <span class="material-symbols-outlined text-base text-slate-500">close</span>
                                            Từ chối hủy
                                        </button>

                                        <button type="button" @click="showConfirmModal = true" class="flex-1 sm:flex-initial inline-flex items-center justify-center gap-1 px-5 py-2 text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 active:bg-rose-800 rounded-xl transition shadow-sm">
                                            <span class="material-symbols-outlined text-base">delete_forever</span>
                                            Duyệt hủy hóa đơn
                                        </button>
                                    </div>
                                </div>
                            @elseif ($selectedCancellation->status === 'approved')
                                <div class="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-xs text-emerald-800 flex items-center justify-between">
                                    <span class="font-bold flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-base">check_circle</span>
                                        Hóa đơn đã được Admin phê duyệt hủy và hoàn tác công nợ.
                                    </span>
                                    <span class="text-[11px] text-emerald-700 font-mono">{{ $selectedCancellation->updated_at->format('d/m/Y H:i') }}</span>
                                </div>
                            @else
                                <div class="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-800 flex items-center justify-between">
                                    <span class="font-bold flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-base">cancel</span>
                                        Yêu cầu hủy đã bị Admin từ chối: {{ $selectedCancellation->rejection_reason }}
                                    </span>
                                    <span class="text-[11px] text-rose-700 font-mono">{{ $selectedCancellation->updated_at->format('d/m/Y H:i') }}</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- MODAL XÁC NHẬN LẦN 2 KHI DUYỆT HỦY -->
                    <div x-show="showConfirmModal" x-cloak x-transition class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
                        <div class="bg-white max-w-md w-full rounded-2xl shadow-2xl border border-slate-200 overflow-hidden" @click.away="showConfirmModal = false">
                            <div class="p-6 space-y-4">
                                <div class="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center mx-auto">
                                    <span class="material-symbols-outlined text-3xl">warning</span>
                                </div>

                                <div class="text-center space-y-1">
                                    <h3 class="text-base font-bold text-slate-900">Xác nhận duyệt hủy hóa đơn?</h3>
                                    <p class="text-xs text-slate-500">
                                        Hành động này là quyết định cuối cùng của Admin và không thể hoàn tác.
                                    </p>
                                </div>

                                <div class="bg-slate-50 rounded-xl p-3 text-xs space-y-1.5 border border-slate-200">
                                    <div class="flex justify-between">
                                        <span class="text-slate-500">Số hóa đơn hủy:</span>
                                        <strong class="text-slate-900 font-mono">{{ $selectedCancellation->invoice_number }}</strong>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-500">Số tiền hủy &amp; hoàn công nợ:</span>
                                        <strong class="text-rose-600 font-mono font-bold">{{ number_format($selectedCancellation->amount) }} VNĐ</strong>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-500">Học viên hưởng revert:</span>
                                        <strong class="text-slate-900">{{ $st?->name }} ({{ $st?->code }})</strong>
                                    </div>
                                </div>

                                <div class="text-[11px] text-amber-800 bg-amber-50 p-2.5 rounded-lg border border-amber-200">
                                    ⚠️ <strong>Lưu ý:</strong> Số hóa đơn <code>{{ $selectedCancellation->invoice_number }}</code> sẽ bị đánh dấu <strong>Đã hủy</strong> và nằm lại trong dải số, không cấp lại cho bất kỳ ai.
                                </div>
                            </div>

                            <form action="{{ route('tuition.invoices.cancellations.approve', $selectedCancellation->id) }}" method="POST" class="bg-slate-100 px-6 py-4 flex items-center justify-end gap-2.5 border-t border-slate-200">
                                @csrf
                                <button type="button" @click="showConfirmModal = false" class="px-4 py-2 text-xs font-bold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg">
                                    Quay lại kiểm tra
                                </button>
                                <button type="submit" class="px-5 py-2 text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 rounded-lg shadow-sm">
                                    Xác nhận duyệt hủy ngay
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL TỪ CHỐI DUYỆT HỦY -->
                    <div x-show="showRejectModal" x-cloak x-transition class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
                        <div class="bg-white max-w-md w-full rounded-2xl shadow-2xl border border-slate-200 overflow-hidden" @click.away="showRejectModal = false">
                            <div class="p-6 space-y-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0">
                                        <span class="material-symbols-outlined text-xl">close</span>
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-bold text-slate-900">Từ chối duyệt hủy hóa đơn</h3>
                                        <span class="text-xs text-slate-500 font-mono">{{ $selectedCancellation->invoice_number }}</span>
                                    </div>
                                </div>

                                <form action="{{ route('tuition.invoices.cancellations.reject', $selectedCancellation->id) }}" method="POST" class="space-y-3 text-xs">
                                    @csrf
                                    <div>
                                        <label class="block font-bold text-slate-700 mb-1">Lý do từ chối yêu cầu hủy <span class="text-rose-500">*</span></label>
                                        <textarea name="rejection_reason" required rows="3" placeholder="Nhập lý do chi tiết (ví dụ: Hóa đơn chưa có chữ ký xác nhận của phụ huynh, thông tin hợp lệ không cần hủy)..." class="w-full text-xs rounded-xl border border-slate-300 p-2.5"></textarea>
                                    </div>

                                    <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                                        <button type="button" @click="showRejectModal = false" class="px-4 py-2 rounded-lg border text-xs font-bold text-slate-600 hover:bg-slate-50">Hủy</button>
                                        <button type="submit" class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-xs font-bold shadow-sm">Xác nhận từ chối</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-12 text-center text-slate-400 space-y-3">
                        <span class="material-symbols-outlined text-4xl text-slate-300">receipt_long</span>
                        <h3 class="text-sm font-bold text-slate-700">Chưa chọn yêu cầu hủy nào</h3>
                        <p class="text-xs text-slate-400">Vui lòng bấm chọn một yêu cầu hủy hóa đơn từ danh sách bên trái để đối soát chứng từ và đưa ra quyết định duyệt.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- MODAL TẠO YÊU CẦU HỦY MỚI (Từ Header) -->
    <div id="newCancelModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-slate-200">
            <div class="flex justify-between items-center pb-2 border-b border-slate-100">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-rose-600">cancel_presentation</span>
                    <h3 class="font-bold text-sm text-slate-900">Yêu Cầu Hủy Hóa Đơn Thu Tiền</h3>
                </div>
                <button type="button" onclick="document.getElementById('newCancelModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>

            <form action="{{ route('tuition.invoices.cancellations.store') }}" method="POST" enctype="multipart/form-data" class="space-y-3 text-xs">
                @csrf
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Số Hóa đơn / Biên lai cần hủy <span class="text-rose-500">*</span></label>
                    <input type="text" name="invoice_number" placeholder="Ví dụ: HĐ-0824/PTM-042..." required class="w-full text-xs rounded-xl border border-slate-200 p-2.5 font-mono font-bold text-slate-900" />
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Số tiền trên hóa đơn (VNĐ) <span class="text-rose-500">*</span></label>
                    <input type="number" name="amount" placeholder="13500000" required class="w-full text-xs rounded-xl border border-slate-200 p-2.5 font-mono font-bold text-rose-600" />
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Đính kèm ảnh hóa đơn hỏng / gạch chéo</label>
                    <input type="file" name="proof_image" accept="image/*,.pdf" class="w-full text-xs border border-slate-200 rounded-xl p-2 bg-slate-50" />
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Lý do giải trình hủy hóa đơn <span class="text-rose-500">*</span></label>
                    <textarea name="reason" rows="3" placeholder="Nhập lý do chi tiết (ví dụ: Viết sai tên phụ huynh, rách liên đỏ khi xé hóa đơn giao khách)..." required class="w-full text-xs rounded-xl border border-slate-200 p-2.5"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" onclick="document.getElementById('newCancelModal').classList.add('hidden')" class="px-4 py-2 rounded-xl border text-xs font-bold text-slate-600 hover:bg-slate-50">Hủy bỏ</button>
                    <button type="submit" class="px-5 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl shadow-sm">Gửi yêu cầu hủy</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
