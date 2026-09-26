{{-- Mockup: ui-full-tinh-nang-menglish/hoc-phi-va-hoa-don-ui-mockup/duyet-huy-hoa-don --}}
<x-app-layout title="Duyệt hủy hóa đơn" hide-errors>
    {{-- Duyệt / từ chối hủy hóa đơn theo quyền invoice.approve_cancel (mặc định chỉ Admin; Admin cấp thêm ở màn Vai trò / Phân quyền cá nhân). --}}
    @php $canApproveCancel = (bool) auth()->user()?->can('invoice.approve_cancel'); @endphp
    <x-ui.page-header title="Duyệt hủy hóa đơn" description="Kiểm soát và phê duyệt các yêu cầu hủy hóa đơn thu học phí & phụ thu từ Học vụ / CM. Chống thất thoát và nhảy số hóa đơn tự ý.">
        <x-slot:breadcrumbs>
            <a href="{{ route('tuition.students') }}" class="hover:text-primary">Học phí &amp; Hóa đơn</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>Duyệt hủy hóa đơn</span>
            <x-ui.badge color="warning" class="ml-sm">{{ $pendingCount }} yêu cầu chờ xử lý</x-ui.badge>
            <x-ui.badge color="neutral" :dot="false">Admin phê duyệt (theo phân quyền)</x-ui.badge>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @can('invoice.request_cancel')
                <x-ui.button variant="danger" icon="add_circle" x-on:click="$dispatch('open-modal', 'new-cancel')">Tạo yêu cầu hủy HĐ</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('tuition.partials.errors')

    <div class="mx-auto max-w-[1520px] space-y-5" x-data="{ zoomProof: false }">

        @if (isset($errors) && $errors->any())
            <x-ui.alert type="error" title="Vui lòng kiểm tra các lỗi:">
                <ul class="list-inside list-disc pl-5">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        {{-- Header Panel: Thống kê & Báo cáo nhanh --}}
        <div class="flex flex-col gap-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-base font-bold text-on-surface">Báo cáo kiểm toán dải số hóa đơn &amp; yêu cầu hủy</h2>
                <p class="text-xs text-on-surface-variant">Mọi thao tác duyệt hủy đều kích hoạt cơ chế trừ lùi doanh thu và hoàn trả công nợ học viên tự động</p>
            </div>

            {{-- Thẻ tóm tắt chỉ số --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <x-ui.stat-card label="Chờ duyệt hủy" :value="$pendingCount.' hóa đơn'" tone="warning" />
                <x-ui.stat-card label="Đã duyệt hủy (Tháng này)" :value="$approvedMonthCount.' hóa đơn'" tone="success" />
                <x-ui.stat-card label="Đã từ chối" :value="$rejectedCount.' yêu cầu'" />
            </div>
        </div>

        {{-- Banner cảnh báo nghiệp vụ quan trọng --}}
        <x-ui.alert type="info" title="Quy tắc nghiệp vụ kiểm soát số hóa đơn & hoàn tác công nợ (Cập nhật 11/09/2026):">
            <div class="grid gap-x-6 gap-y-1 text-xs md:grid-cols-2">
                <p>• <strong>Bảo toàn dải số:</strong> Số hóa đơn giấy đã hủy vẫn tính là <em>đã sử dụng</em> trong dải số, tuyệt đối không được cấp phát hay tái sử dụng lại.</p>
                <p>• <strong>Tự động hoàn tác công nợ:</strong> Khi duyệt hủy phiếu từng ở trạng thái <em>"Đã duyệt"</em>, hệ thống sẽ tự động trừ lùi số tiền đã thu và hoàn trả công nợ còn lại của học viên.</p>
            </div>
        </x-ui.alert>

        {{-- Thanh lọc & tìm kiếm --}}
        <form method="GET" action="{{ route('tuition.invoices.cancellations') }}" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-72">
                    <x-ui.input name="q" icon="search" :value="request('q')" placeholder="Tìm theo mã phiếu, số hóa đơn, học viên..." class="pr-8" />
                    @if (request('q'))
                        <a href="{{ route('tuition.invoices.cancellations', request()->except('q')) }}" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant/70 hover:text-on-surface-variant" aria-label="Xóa từ khóa">
                            <span class="material-symbols-outlined text-base">close</span>
                        </a>
                    @endif
                </div>

                <x-ui.select name="branch_id" onchange="this.form.submit()" aria-label="Cơ sở" class="cursor-pointer">
                    <option value="all">Tất cả cơ sở</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select name="status" onchange="this.form.submit()" aria-label="Trạng thái" class="cursor-pointer font-bold">
                    <option value="pending" {{ request('status', 'pending') === 'pending' ? 'selected' : '' }}>Trạng thái: Chờ duyệt hủy ({{ $pendingCount }})</option>
                    <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Đã duyệt hủy</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Đã từ chối hủy</option>
                    <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>Tất cả</option>
                </x-ui.select>
            </div>

            <div class="flex items-center gap-2">
                <x-ui.button variant="ghost" icon="refresh" :href="route('tuition.invoices.cancellations', request()->query())">Làm mới</x-ui.button>
                <x-ui.button variant="secondary" icon="download" :href="route('tuition.invoices.cancellations.export', request()->query())">Xuất danh sách</x-ui.button>
            </div>
        </form>

        {{-- Bố cục Master - Detail: Danh sách chờ duyệt (5 cột) & Chi tiết đối soát (7 cột) --}}
        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-12">
            {{-- CỘT TRÁI (5 Cột): Danh sách yêu cầu chờ duyệt --}}
            <div class="min-w-0 space-y-4 lg:col-span-5">
                <div class="flex items-center justify-between">
                    <h3 class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-on-surface">
                        <span>Danh sách yêu cầu hủy</span>
                        <x-ui.badge color="warning" pill :dot="false" class="font-code">{{ $cancellations->count() }}</x-ui.badge>
                    </h3>
                    <span class="text-[11px] text-on-surface-variant">Xếp theo thời gian gửi gần nhất</span>
                </div>

                <div class="max-h-[820px] space-y-3 overflow-y-auto pr-1">
                    @forelse ($cancellations as $can)
                        @php
                            $isSelected = $selectedCancellation && $selectedCancellation->id === $can->id;
                            $st = $can->student ?? $can->receipt?->tuition?->student ?? $can->receipt?->student;
                        @endphp
                        <a href="{{ route('tuition.invoices.cancellations', array_merge(request()->all(), ['selected_id' => $can->id])) }}" class="relative block cursor-pointer rounded-2xl border bg-surface-container-lowest p-4 shadow-sm transition {{ $isSelected ? 'border-primary-container shadow-md ring-2 ring-primary-container/20' : 'border-surface-container-highest hover:border-outline-variant' }}">
                            @if ($isSelected)
                                <div class="absolute -left-1 bottom-6 top-6 w-1 rounded-r bg-primary-container"></div>
                            @endif

                            <div class="flex items-start justify-between gap-2 border-b border-surface-container-highest pb-2.5">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-code text-xs font-bold text-on-surface">{{ $can->invoice_number }}</span>
                                        <x-ui.badge color="error" :dot="false">Hóa đơn giấy</x-ui.badge>
                                    </div>
                                    <div class="mt-0.5 text-[11px] text-on-surface-variant">
                                        Mã phiếu thu: <strong class="font-code text-on-surface-variant">{{ $can->receipt?->receipt_number ?? 'PT-Trực tiếp' }}</strong>
                                    </div>
                                </div>
                                <div class="text-right">
                                    @if ($isSelected)
                                        <x-ui.badge color="primary" pill>Đang xem</x-ui.badge>
                                    @elseif ($can->status === 'approved')
                                        <x-ui.badge color="success" pill>Đã duyệt hủy</x-ui.badge>
                                    @elseif ($can->status === 'rejected')
                                        <x-ui.badge color="error" pill>Đã từ chối</x-ui.badge>
                                    @else
                                        <x-ui.badge color="warning" pill>Chờ duyệt</x-ui.badge>
                                    @endif
                                </div>
                            </div>

                            <div class="space-y-1.5 py-2.5 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-on-surface-variant">Học viên:</span>
                                    <span class="font-bold text-on-surface">{{ $st?->name ?? '—' }} <span class="font-code font-normal text-on-surface-variant/70">({{ $st?->code ?? 'HV' }})</span></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-on-surface-variant">Số tiền trên hóa đơn:</span>
                                    <x-ui.money :value="(float) $can->amount" suffix="VNĐ" class="font-bold" />
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-on-surface-variant">Người yêu cầu hủy:</span>
                                    <span class="font-medium text-on-surface-variant">{{ $can->requester?->name ?? 'Chưa cập nhật' }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-on-surface-variant">Thời điểm gửi yêu cầu:</span>
                                    <span class="font-code text-[11px] text-on-surface-variant">{{ $can->created_at->format('H:i - d/m/Y') }}</span>
                                </div>
                            </div>

                            <div class="mt-1 rounded-xl border border-error/20 bg-error/5 p-2.5">
                                <div class="mb-0.5 flex items-center gap-1 text-[10px] font-bold text-on-error-container">
                                    <span class="material-symbols-outlined text-xs">error</span> Lý do hủy từ Học vụ:
                                </div>
                                <p class="line-clamp-2 text-xs italic text-on-error-container">
                                    "{{ $can->reason }}"
                                </p>
                            </div>
                        </a>
                    @empty
                        <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest">
                            <x-ui.empty-state icon="task" title="Không có yêu cầu hủy hóa đơn nào." />
                        </div>
                    @endforelse
                </div>

                <x-ui.alert type="warning">
                    <span class="text-xs">Hệ thống tự động khóa không cho tạo yêu cầu thứ 2 đối với phiếu đang có yêu cầu chờ duyệt.</span>
                </x-ui.alert>
            </div>

            {{-- CỘT PHẢI (7 Cột): Chi tiết phiếu thu & Thao tác phê duyệt của Admin --}}
            <div class="min-w-0 space-y-5 lg:col-span-7">
                @if ($selectedCancellation)
                    @php
                        $st = $selectedCancellation->student ?? $selectedCancellation->receipt?->tuition?->student ?? $selectedCancellation->receipt?->student;
                        $rc = $selectedCancellation->receipt;
                        $className = $rc?->tuition?->classModel?->name ?? $st?->currentClass?->name ?? 'Chưa gắn lớp';
                        $branchName = $st?->branch?->name ?? 'Chưa gán chi nhánh';
                    @endphp

                    <div class="space-y-5 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm md:p-6">
                        {{-- Tiêu đề chi tiết & Trạng thái kép --}}
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-surface-container-highest pb-4">
                            <div>
                                <div class="flex items-center gap-2.5">
                                    <h3 class="text-base font-bold text-on-surface">Chi tiết yêu cầu hủy: <span class="font-code text-error">{{ $selectedCancellation->invoice_number }}</span></h3>
                                    @if ($selectedCancellation->status === 'approved')
                                        <x-ui.badge color="success" pill>Đã duyệt hủy</x-ui.badge>
                                    @elseif ($selectedCancellation->status === 'rejected')
                                        <x-ui.badge color="error" pill>Đã từ chối hủy</x-ui.badge>
                                    @else
                                        <x-ui.badge color="error" pill>Đang yêu cầu hủy</x-ui.badge>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-on-surface-variant">
                                    Gắn với phiếu thu: <strong class="font-code text-on-surface">{{ $rc?->receipt_number ?? '—' }}</strong> • Tạo ngày {{ $selectedCancellation->created_at->format('d/m/Y') }} bởi <span class="font-medium text-on-surface-variant">{{ $selectedCancellation->requester?->name ?? 'Chưa cập nhật' }}</span>
                                </p>
                            </div>

                            {{-- Trạng thái kép rõ ràng --}}
                            <div class="flex flex-col items-end gap-1 text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="text-on-surface-variant">Trạng thái phiếu thu:</span>
                                    <x-ui.badge :color="$rc?->status_color ?? 'neutral'" :dot="false">{{ $rc?->status_label ?? 'Không xác định phiếu thu' }}</x-ui.badge>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-on-surface-variant">Trạng thái hóa đơn:</span>
                                    <x-ui.badge color="warning" :dot="false">{{ $selectedCancellation->status === 'approved' ? 'Đã duyệt hủy' : ($selectedCancellation->status === 'rejected' ? 'Bị từ chối' : 'Chờ duyệt hủy') }}</x-ui.badge>
                                </div>
                            </div>
                        </div>

                        {{-- Khối 1: Lý do yêu cầu hủy (Nổi bật nhất) --}}
                        <div class="space-y-2 rounded-xl border border-error/30 bg-error-container/40 p-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2 text-xs font-bold uppercase text-on-error-container">
                                    <span class="material-symbols-outlined text-base text-error">report_problem</span>
                                    Thông tin yêu cầu hủy hóa đơn
                                </div>
                                <span class="text-[11px] font-medium text-error">Bắt buộc xem xét kỹ</span>
                            </div>

                            <div class="grid grid-cols-1 gap-3 pt-1 text-xs md:grid-cols-2">
                                <div>
                                    <span class="text-[11px] text-error">Người gửi yêu cầu:</span>
                                    <div class="mt-0.5 font-bold text-on-surface">{{ $selectedCancellation->requester?->name ?? 'Chưa cập nhật' }} (Học vụ - {{ $branchName }})</div>
                                </div>
                                <div>
                                    <span class="text-[11px] text-error">Thời gian gửi:</span>
                                    <div class="mt-0.5 font-code font-bold text-on-surface">{{ $selectedCancellation->created_at->format('H:i:s - d/m/Y') }}</div>
                                </div>
                            </div>

                            <div class="border-t border-error/20 pt-2 text-xs">
                                <span class="mb-1 block font-bold text-on-error-container">Nội dung giải trình lý do hủy (*):</span>
                                <p class="rounded-lg border border-error/30 bg-surface-container-lowest p-3 italic leading-relaxed text-on-surface">
                                    "{{ $selectedCancellation->reason }}"
                                </p>
                            </div>
                        </div>

                        {{-- Khối 2: Tác động tài chính & Hoàn tác công nợ tự động (R-11) --}}
                        <x-ui.alert type="warning" title='Hệ thống sẽ tự động thực hiện khi Admin bấm "Duyệt hủy hóa đơn":'>
                            <ul class="list-disc space-y-1.5 pl-5 text-xs leading-relaxed">
                                <li>
                                    <strong>Trừ lùi công nợ (Revert):</strong> Vì phiếu <code>{{ $rc?->receipt_number ?? 'Gốc' }}</code> trước đó đã ở trạng thái <em>"Đã duyệt"</em>, hệ thống sẽ tự động trừ <strong>{{ number_format((float) ($selectedCancellation->amount), 0, ',', '.') }} VNĐ</strong> khỏi <code>tong_da_thu</code> và cộng ngược <strong>{{ number_format((float) ($selectedCancellation->amount), 0, ',', '.') }} VNĐ</strong> vào <code>tong_con_lai</code> của học viên {{ $st?->name }}.
                                </li>
                                <li>
                                    <strong>Khóa số hóa đơn:</strong> Số hóa đơn <code>{{ $selectedCancellation->invoice_number }}</code> chuyển thành <em>"Đã hủy"</em>, giữ nguyên lịch sử kiểm toán trong dải số và <strong>không được tái sử dụng</strong>.
                                </li>
                                <li>
                                    <strong>Ghi nhật ký hệ thống:</strong> Lưu vết người duyệt là <em>{{ Auth::user()?->name ?? 'Admin' }}</em> cùng thời điểm thực thi.
                                </li>
                            </ul>
                        </x-ui.alert>

                        {{-- Khối 3: Thông tin chi tiết phiếu thu gốc bị hủy --}}
                        <div class="space-y-3">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-on-surface-variant/70">Thông tin phiếu thu gốc</h4>

                            <div class="grid grid-cols-2 gap-3 rounded-xl border border-surface-container-highest bg-surface-container-low p-4 text-xs sm:grid-cols-3">
                                <div>
                                    <span class="block text-[10px] uppercase text-on-surface-variant/70">Học viên</span>
                                    <span class="font-bold text-on-surface">{{ $st?->name ?? '—' }}</span>
                                    <span class="block font-code text-[11px] text-on-surface-variant">Mã: {{ $st?->code ?? 'HV' }}</span>
                                </div>
                                <div>
                                    <span class="block text-[10px] uppercase text-on-surface-variant/70">Lớp học hiện tại</span>
                                    <span class="font-semibold text-on-surface">{{ $className }}</span>
                                    <span class="block text-[11px] text-on-surface-variant">{{ $branchName }}</span>
                                </div>
                                <div>
                                    <span class="block text-[10px] uppercase text-on-surface-variant/70">Người nộp tiền</span>
                                    <span class="font-semibold text-on-surface">{{ $rc?->payer_name ?: ($st?->parent_name ?: $st?->name) }}</span>
                                    <span class="block font-code text-[11px] text-on-surface-variant">{{ $rc?->payer_phone ?: ($st?->parent_phone ?: $st?->phone) }}</span>
                                </div>
                                <div>
                                    <span class="block text-[10px] uppercase text-on-surface-variant/70">Hình thức thu</span>
                                    <span class="mt-0.5 flex items-center gap-1 font-semibold text-on-surface">
                                        <span class="material-symbols-outlined text-sm text-on-surface-variant">payments</span>
                                        {{ ($rc?->payment_method === 'cash') ? 'Tiền mặt' : 'Chuyển khoản' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="block text-[10px] uppercase text-on-surface-variant/70">Số hóa đơn giấy</span>
                                    <span class="font-code font-bold text-error">{{ $selectedCancellation->invoice_number }}</span>
                                </div>
                                <div>
                                    <span class="block text-[10px] uppercase text-on-surface-variant/70">Hóa đơn đỏ (VAT)</span>
                                    <span class="font-medium text-on-surface-variant">{{ $rc?->is_vat_invoice ? 'Yêu cầu VAT' : 'Không yêu cầu' }}</span>
                                </div>
                            </div>

                            {{-- Bảng kê các khoản tiền trên hóa đơn --}}
                            <x-ui.data-table>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Khoản mục</th>
                                            <th>Nội dung chi tiết</th>
                                            <th class="text-right">Số tiền</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @if ($rc && $rc->tuition_amount > 0)
                                            <tr>
                                                <td class="font-semibold">Học phí đào tạo</td>
                                                <td class="text-on-surface-variant">Khóa {{ $className }} (Đã giảm trừ: {{ number_format((float) ($rc->discount_amount ?? 0), 0, ',', '.') }} đ)</td>
                                                <td><x-ui.money :value="(float) $rc->tuition_amount" suffix="VNĐ" class="font-semibold" /></td>
                                            </tr>
                                        @endif
                                        @if ($rc && $rc->surcharge_amount > 0)
                                            <tr>
                                                <td class="flex items-center gap-1 font-semibold text-primary">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-primary-container"></span> Phụ thu phát sinh
                                                </td>
                                                <td class="text-on-surface-variant">{{ $rc->surcharge_reason ?: 'Phụ thu giáo trình & học liệu' }}</td>
                                                <td class="text-right font-code font-semibold text-primary">+{{ number_format((float) ($rc->surcharge_amount), 0, ',', '.') }} VNĐ</td>
                                            </tr>
                                        @endif
                                        <tr class="bg-surface-container-low font-bold">
                                            <td colspan="2" class="font-bold">TỔNG SỐ TIỀN TRÊN HÓA ĐƠN:</td>
                                            <td class="text-right font-code font-bold text-primary">{{ number_format((float) ($selectedCancellation->amount), 0, ',', '.') }} VNĐ</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </x-ui.data-table>

                            {{-- Xem trước ảnh hóa đơn bị hỏng/viết sai --}}
                            <div class="space-y-2 rounded-xl border border-surface-container-highest p-4">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="flex items-center gap-1.5 font-bold text-on-surface">
                                        <span class="material-symbols-outlined text-base text-on-surface-variant">image</span>
                                        Ảnh chụp minh chứng hóa đơn hỏng / gạch chéo hủy:
                                    </span>
                                    <span class="font-code text-[11px] text-on-surface-variant/70">{{ $selectedCancellation->proof_image ? basename($selectedCancellation->proof_image) : 'Không có tệp' }}</span>
                                </div>

                                <div class="relative flex min-h-[160px] items-center justify-center overflow-hidden rounded-xl border border-inverse-surface bg-inverse-surface p-4 text-inverse-on-surface">
                                    @if ($selectedCancellation->proof_image)
                                        <img src="{{ $selectedCancellation->proof_image }}" alt="Minh chứng hủy" class="max-h-56 cursor-pointer rounded-lg object-contain" @click="zoomProof = true" />
                                    @else
                                        <div class="space-y-2 text-center">
                                            <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-inverse-on-surface/70">
                                                <span class="material-symbols-outlined text-2xl">image_not_supported</span>
                                            </div>
                                            <div class="text-xs text-inverse-on-surface/70">Người yêu cầu không đính kèm ảnh hóa đơn hỏng / gạch chéo. Đối chiếu bản giấy trước khi duyệt.</div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Khu vực hành động của Admin (Có xác nhận lần 2) --}}
                        <div class="space-y-3 border-t border-surface-container-highest pt-4">
                            <div class="flex items-center justify-between text-[11px] text-on-surface-variant">
                                <span>Quyền thực hiện: <strong class="text-on-surface-variant">Duyệt hủy hóa đơn</strong> (mặc định Admin)@if ($canApproveCancel) — {{ Auth::user()->name }}@endif</span>
                                <span class="text-on-surface-variant/70">Hệ thống ghi nhận thời điểm thao tác chính xác vào Audit Log</span>
                            </div>

                            @if ($selectedCancellation->status === 'pending' && ! $canApproveCancel)
                                <x-ui.alert type="warning">
                                    Yêu cầu đang chờ Admin (hoặc người được cấp quyền duyệt hủy hóa đơn) phê duyệt.
                                </x-ui.alert>
                            @elseif ($selectedCancellation->status === 'pending')
                                <div class="flex flex-col items-center justify-between gap-4 rounded-xl border border-surface-container-highest bg-surface-container-low p-4 sm:flex-row">
                                    <div class="space-y-0.5">
                                        <div class="flex items-center gap-1.5 text-xs font-bold text-on-surface">
                                            <span class="material-symbols-outlined text-base text-warning">verified_user</span>
                                            Xác nhận xử lý yêu cầu hủy hóa đơn
                                        </div>
                                        <div class="text-[11px] text-on-surface-variant">
                                            Thao tác Duyệt sẽ hoàn tác công nợ học viên và khóa vĩnh viễn số hóa đơn này.
                                        </div>
                                    </div>

                                    <div class="flex w-full items-center gap-2.5 sm:w-auto">
                                        <x-ui.button variant="secondary" icon="close" class="flex-1 sm:flex-initial" x-on:click="$dispatch('open-modal', 'reject-cancellation')">Từ chối hủy</x-ui.button>
                                        <x-ui.button variant="danger" icon="delete_forever" class="flex-1 sm:flex-initial" x-on:click="$dispatch('open-modal', 'confirm-cancellation')">Duyệt hủy hóa đơn</x-ui.button>
                                    </div>
                                </div>
                            @elseif ($selectedCancellation->status === 'approved')
                                <x-ui.alert type="success">
                                    <div class="flex items-center justify-between gap-sm">
                                        <span class="font-bold">Hóa đơn đã được Admin phê duyệt hủy và hoàn tác công nợ.</span>
                                        <span class="font-code text-[11px]">{{ $selectedCancellation->updated_at->format('d/m/Y H:i') }}</span>
                                    </div>
                                </x-ui.alert>
                            @else
                                <x-ui.alert type="error">
                                    <div class="flex items-center justify-between gap-sm">
                                        <span class="font-bold">Yêu cầu hủy đã bị Admin từ chối: {{ $selectedCancellation->rejection_reason }}</span>
                                        <span class="font-code text-[11px]">{{ $selectedCancellation->updated_at->format('d/m/Y H:i') }}</span>
                                    </div>
                                </x-ui.alert>
                            @endif
                        </div>
                    </div>

                    {{-- MODAL XÁC NHẬN LẦN 2 KHI DUYỆT HỦY --}}
                    <x-ui.modal name="confirm-cancellation" title="Xác nhận duyệt hủy hóa đơn?" max-width="md">
                        <div class="space-y-4">
                            <p class="text-xs text-on-surface-variant">
                                Hành động này là quyết định cuối cùng của Admin và không thể hoàn tác.
                            </p>

                            <div class="space-y-1.5 rounded-xl border border-surface-container-highest bg-surface-container-low p-3 text-xs">
                                <div class="flex justify-between">
                                    <span class="text-on-surface-variant">Số hóa đơn hủy:</span>
                                    <strong class="font-code text-on-surface">{{ $selectedCancellation->invoice_number }}</strong>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-on-surface-variant">Số tiền hủy &amp; hoàn công nợ:</span>
                                    <strong class="font-code font-bold text-error">{{ number_format((float) ($selectedCancellation->amount), 0, ',', '.') }} VNĐ</strong>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-on-surface-variant">Học viên hưởng revert:</span>
                                    <strong class="text-on-surface">{{ $st?->name }} ({{ $st?->code }})</strong>
                                </div>
                            </div>

                            <x-ui.alert type="warning">
                                <span class="text-xs"><strong>Lưu ý:</strong> Số hóa đơn <code>{{ $selectedCancellation->invoice_number }}</code> sẽ bị đánh dấu <strong>Đã hủy</strong> và nằm lại trong dải số, không cấp lại cho bất kỳ ai.</span>
                            </x-ui.alert>
                        </div>
                        <form id="confirm-cancellation-form" action="{{ route('tuition.invoices.cancellations.approve', $selectedCancellation->id) }}" method="POST">
                            @csrf
                        </form>
                        <x-slot:footer>
                            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'confirm-cancellation')">Quay lại kiểm tra</x-ui.button>
                            <x-ui.button variant="danger" type="submit" form="confirm-cancellation-form">Xác nhận duyệt hủy ngay</x-ui.button>
                        </x-slot:footer>
                    </x-ui.modal>

                    {{-- MODAL TỪ CHỐI DUYỆT HỦY --}}
                    <x-ui.modal name="reject-cancellation" title="Từ chối duyệt hủy hóa đơn" max-width="md">
                        <p class="mb-3 font-code text-xs text-on-surface-variant">{{ $selectedCancellation->invoice_number }}</p>
                        <form id="reject-cancellation-form" action="{{ route('tuition.invoices.cancellations.reject', $selectedCancellation->id) }}" method="POST" class="space-y-3">
                            @csrf
                            <x-ui.textarea name="rejection_reason" label="Lý do từ chối yêu cầu hủy" required rows="3" placeholder="Nhập lý do chi tiết (ví dụ: Hóa đơn chưa có chữ ký xác nhận của phụ huynh, thông tin hợp lệ không cần hủy)..." />
                        </form>
                        <x-slot:footer>
                            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'reject-cancellation')">Hủy</x-ui.button>
                            <x-ui.button type="submit" form="reject-cancellation-form">Xác nhận từ chối</x-ui.button>
                        </x-slot:footer>
                    </x-ui.modal>
                @else
                    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-12 shadow-sm">
                        <x-ui.empty-state icon="receipt_long" title="Chưa chọn yêu cầu hủy nào"
                                          description="Vui lòng bấm chọn một yêu cầu hủy hóa đơn từ danh sách bên trái để đối soát chứng từ và đưa ra quyết định duyệt." />
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- MODAL TẠO YÊU CẦU HỦY MỚI (Từ Header) --}}
    <x-ui.modal name="new-cancel" title="Yêu Cầu Hủy Hóa Đơn Thu Tiền" max-width="md">
        <form id="new-cancel-form" action="{{ route('tuition.invoices.cancellations.store') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <x-ui.input name="invoice_number" label="Số Hóa đơn / Biên lai cần hủy" placeholder="Ví dụ: C26MEN-0001001" required class="font-code font-bold"
                        hint="Nhập đúng số HĐĐT của phiếu thu đã duyệt; hệ thống tự đối chiếu phiếu thu và hoàn tác công nợ khi được duyệt hủy." />
            <x-ui.field label="Số tiền trên hóa đơn (VNĐ)" for="cancel_amount" required>
                <x-ui.input type="number" name="amount" id="cancel_amount" placeholder="13500000" required class="font-code font-bold text-error" />
                @error('amount') <p class="font-caption text-caption text-error">Số tiền phải khớp giá trị hóa đơn. {{ $message }}</p> @enderror
            </x-ui.field>
            <x-ui.field label="Đính kèm ảnh hóa đơn hỏng / gạch chéo" name="proof_image" for="cancel_proof_image">
                <input type="file" id="cancel_proof_image" name="proof_image" accept="image/*,.pdf" class="w-full rounded-lg border border-surface-container-highest bg-surface-container-low p-2 text-xs" />
            </x-ui.field>
            <x-ui.textarea name="reason" id="cancel_reason" label="Lý do giải trình hủy hóa đơn" rows="3" placeholder="Nhập lý do chi tiết (ví dụ: Viết sai tên phụ huynh, rách liên đỏ khi xé hóa đơn giao khách)..." required />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'new-cancel')">Hủy bỏ</x-ui.button>
            <x-ui.button variant="danger" type="submit" form="new-cancel-form">Gửi yêu cầu hủy</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-app-layout>
