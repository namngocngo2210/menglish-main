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
            <x-ui.button variant="secondary" icon="download" :href="route('tuition.invoices.cancellations.export', request()->query())">Xuất danh sách</x-ui.button>
            @can('invoice.request_cancel')
                {{-- Việc chính của màn là duyệt → nút tạo yêu cầu là nút viền; màu đỏ chỉ ở nút xác nhận trong hộp thoại. --}}
                <x-ui.button variant="secondary" icon="add_circle" x-on:click="$dispatch('open-modal', 'new-cancel')">Yêu cầu hủy HĐ</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($selectedCancellation || old('_modal') === 'new-cancel')
        @include('tuition.partials.errors')
    @endunless

    <div class="mx-auto max-w-[1520px] space-y-5">

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
        <x-ui.filter-bar :action="route('tuition.invoices.cancellations')" search="q" placeholder="Tìm theo mã phiếu, số hóa đơn, học viên..." class="!mb-0">
            <x-ui.select name="branch_id" label="Cơ sở" onchange="this.form.submit()">
                <option value="all">Tất cả cơ sở</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="status" label="Trạng thái" onchange="this.form.submit()">
                <option value="pending" {{ request('status', 'pending') === 'pending' ? 'selected' : '' }}>Trạng thái: Chờ duyệt hủy ({{ $pendingCount }})</option>
                <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Đã duyệt hủy</option>
                <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Đã từ chối hủy</option>
                <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>Tất cả</option>
            </x-ui.select>
        </x-ui.filter-bar>

        {{-- Danh sách yêu cầu hủy: bấm dòng → chi tiết mở trong modal (?selected_id=; đóng modal thì bỏ query). --}}
        <x-ui.data-table min-width="960px" sticky="last">
            <x-slot:header>
                <div class="flex items-center gap-2">
                    <h3 class="font-h3 text-h3 text-on-surface">Danh sách yêu cầu hủy</h3>
                    <x-ui.badge color="warning" pill :dot="false" class="font-code">{{ $cancellations->count() }}</x-ui.badge>
                </div>
                <span class="text-xs text-on-surface-variant">Mới gửi xếp trước. Phiếu đang có yêu cầu chờ duyệt không tạo thêm yêu cầu thứ 2.</span>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Số hóa đơn</th>
                        <th>Phiếu thu</th>
                        <th>Học viên</th>
                        <th class="text-right">Số tiền</th>
                        <th>Lý do hủy</th>
                        <th>Người yêu cầu · Gửi lúc</th>
                        <th>Trạng thái</th>
                        <th class="text-right"><span class="sr-only">Thao tác</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cancellations as $can)
                        @php
                            $st = $can->student ?? $can->receipt?->tuition?->student ?? $can->receipt?->student;
                            $detailUrl = route('tuition.invoices.cancellations', array_merge(request()->except('selected_id'), ['selected_id' => $can->id]));
                        @endphp
                        <tr data-href="{{ $detailUrl }}" @class(['cursor-pointer', 'bg-primary-container/5' => $selectedCancellation?->id === $can->id])>
                            <td class="whitespace-nowrap">
                                <a href="{{ $detailUrl }}" class="font-code font-semibold text-on-surface hover:text-primary">{{ $can->invoice_number }}</a>
                            </td>
                            <td class="whitespace-nowrap font-code text-on-surface-variant">
                                @if ($can->receipt)<x-ui.code :value="$can->receipt->receipt_number" />@else PT-Trực tiếp @endif
                            </td>
                            <td class="min-w-[10rem]">
                                <div class="font-semibold">{{ $st?->name ?? '—' }}</div>
                                <x-ui.code :value="$st?->code" class="font-code text-xs text-on-surface-subtle" />
                            </td>
                            <td class="whitespace-nowrap text-right"><x-ui.money :value="(float) $can->amount" class="font-bold" /></td>
                            <td class="max-w-[260px]"><p class="line-clamp-2 text-on-surface-variant" title="{{ $can->reason }}">{{ $can->reason }}</p></td>
                            <td>
                                <div>{{ $can->requester?->name ?? '—' }}</div>
                                <div class="whitespace-nowrap text-xs text-on-surface-subtle" title="{{ $can->created_at->format('H:i d/m/Y') }}">{{ $can->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($can->status === 'approved')
                                    <x-ui.badge color="success" :dot="false">Đã duyệt hủy</x-ui.badge>
                                @elseif ($can->status === 'rejected')
                                    <x-ui.badge color="error" :dot="false">Đã từ chối</x-ui.badge>
                                @else
                                    <x-ui.badge color="warning" :dot="false">Chờ duyệt</x-ui.badge>
                                @endif
                            </td>
                            <td class="text-right">
                                <x-ui.button variant="secondary" size="sm" icon="visibility" :href="$detailUrl">Xem</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8"><x-ui.empty-state icon="task" title="Không có yêu cầu hủy hóa đơn nào." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>
    </div>

    @if ($selectedCancellation)
        @php
            $st = $selectedCancellation->student ?? $selectedCancellation->receipt?->tuition?->student ?? $selectedCancellation->receipt?->student;
            $rc = $selectedCancellation->receipt;
            $className = $rc?->tuition?->classModel?->name ?? $st?->currentClass?->name ?? 'Chưa gắn lớp';
            $branchName = $st?->branch?->name ?? 'Chưa gán chi nhánh';
        @endphp

        {{-- MODAL CHI TIẾT: mở sẵn khi URL có selected_id; đóng → bỏ selected_id khỏi thanh địa chỉ. --}}
        <x-ui.modal name="cancellation-detail" :title="'Yêu cầu hủy hóa đơn '.$selectedCancellation->invoice_number" max-width="4xl" show
                    :dismiss-url="route('tuition.invoices.cancellations', request()->except('selected_id'))">
            <div class="space-y-5" data-cancellation-detail="{{ $selectedCancellation->id }}">
                @include('tuition.partials.errors')

                {{-- Tiêu đề chi tiết & Trạng thái kép --}}
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-surface-container-highest pb-4">
                    <div>
                        <div class="flex items-center gap-2.5">
                            @if ($selectedCancellation->status === 'approved')
                                <x-ui.badge color="success" pill>Đã duyệt hủy</x-ui.badge>
                            @elseif ($selectedCancellation->status === 'rejected')
                                <x-ui.badge color="error" pill>Đã từ chối hủy</x-ui.badge>
                            @else
                                <x-ui.badge color="error" pill>Đang yêu cầu hủy</x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-on-surface-variant">
                            Gắn với phiếu thu: <strong class="font-code text-on-surface"><x-ui.code :value="$rc?->receipt_number" /></strong> • Tạo ngày {{ $selectedCancellation->created_at->format('d/m/Y') }} bởi <span class="font-medium text-on-surface-variant">{{ $selectedCancellation->requester?->name ?? 'Chưa cập nhật' }}</span>
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
                        <span class="text-xs font-medium text-error">Bắt buộc xem xét kỹ</span>
                    </div>

                    <div class="grid grid-cols-1 gap-3 pt-1 text-xs md:grid-cols-2">
                        <div>
                            <span class="text-xs text-error">Người gửi yêu cầu:</span>
                            <div class="mt-0.5 font-bold text-on-surface">{{ $selectedCancellation->requester?->name ?? 'Chưa cập nhật' }} (Học vụ - {{ $branchName }})</div>
                        </div>
                        <div>
                            <span class="text-xs text-error">Thời gian gửi:</span>
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
                            <strong>Trừ lùi công nợ (Revert):</strong> Vì phiếu <code>{{ $rc?->receipt_number ?? 'Gốc' }}</code> trước đó đã ở trạng thái <em>"Đã duyệt"</em>, hệ thống sẽ tự động trừ <strong>{{ \App\Support\Money::format((float) ($selectedCancellation->amount)) }}</strong> khỏi <code>tong_da_thu</code> và cộng ngược <strong>{{ \App\Support\Money::format((float) ($selectedCancellation->amount)) }}</strong> vào <code>tong_con_lai</code> của học viên {{ $st?->name }}.
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
                    <h4 class="text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Thông tin phiếu thu gốc</h4>

                    <div class="grid grid-cols-2 gap-3 rounded-xl border border-surface-container-highest bg-surface-container-low p-4 text-xs sm:grid-cols-3">
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Học viên</span>
                            <span class="font-bold text-on-surface">{{ $st?->name ?? '—' }}</span>
                            <span class="block font-code text-xs text-on-surface-variant">Mã: <x-ui.code :value="$st?->code" /></span>
                        </div>
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Lớp học hiện tại</span>
                            <span class="font-semibold text-on-surface">{{ $className }}</span>
                            <span class="block text-xs text-on-surface-variant">{{ $branchName }}</span>
                        </div>
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Người nộp tiền</span>
                            <span class="font-semibold text-on-surface">{{ $rc?->payer_name ?: ($st?->parent_name ?: $st?->name) }}</span>
                            <span class="block font-code text-xs text-on-surface-variant">{{ $rc?->payer_phone ?: ($st?->parent_phone ?: $st?->phone) }}</span>
                        </div>
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Hình thức thu</span>
                            <span class="mt-0.5 flex items-center gap-1 font-semibold text-on-surface">
                                <span class="material-symbols-outlined text-sm text-on-surface-variant">payments</span>
                                {{ ($rc?->payment_method === 'cash') ? 'Tiền mặt' : 'Chuyển khoản' }}
                            </span>
                        </div>
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Số hóa đơn giấy</span>
                            <span class="font-code font-bold text-error">{{ $selectedCancellation->invoice_number }}</span>
                        </div>
                        <div>
                            <span class="block text-xs uppercase text-on-surface-subtle">Hóa đơn đỏ (VAT)</span>
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
                                        <td class="text-on-surface-variant">Khóa {{ $className }} (Đã giảm trừ: {{ \App\Support\Money::format((float) ($rc->discount_amount ?? 0)) }})</td>
                                        <td><x-ui.money :value="(float) $rc->tuition_amount" class="font-semibold" /></td>
                                    </tr>
                                @endif
                                @if ($rc && $rc->surcharge_amount > 0)
                                    <tr>
                                        <td class="flex items-center gap-1 font-semibold text-primary">
                                            <span class="h-1.5 w-1.5 rounded-full bg-primary-container"></span> Phụ thu phát sinh
                                        </td>
                                        <td class="text-on-surface-variant">{{ $rc->surcharge_reason ?: 'Phụ thu giáo trình & học liệu' }}</td>
                                        <td class="text-right font-code font-semibold text-primary">+{{ \App\Support\Money::format((float) ($rc->surcharge_amount)) }}</td>
                                    </tr>
                                @endif
                                <tr class="bg-surface-container-low font-bold">
                                    <td colspan="2" class="font-bold">TỔNG SỐ TIỀN TRÊN HÓA ĐƠN:</td>
                                    <td class="text-right font-code font-bold text-primary">{{ \App\Support\Money::format((float) ($selectedCancellation->amount)) }}</td>
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
                            <span class="font-code text-xs text-on-surface-subtle">{{ $selectedCancellation->proof_image ? basename($selectedCancellation->proof_image) : 'Không có tệp' }}</span>
                        </div>

                        <div class="relative flex min-h-[160px] items-center justify-center overflow-hidden rounded-xl border border-inverse-surface bg-inverse-surface p-4 text-inverse-on-surface">
                            @if ($selectedCancellation->proof_image)
                                <img src="{{ $selectedCancellation->proof_image }}" alt="Minh chứng hủy" class="max-h-56 cursor-pointer rounded-lg object-contain" x-on:click="$dispatch('open-modal', 'zoom-cancel-proof')" />
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

                {{-- Quyền duyệt & kết quả xử lý (nút Duyệt / Từ chối ở chân modal, có xác nhận lần 2) --}}
                <div class="space-y-3 border-t border-surface-container-highest pt-4">
                    <div class="flex items-center justify-between text-xs text-on-surface-variant">
                        <span>Quyền thực hiện: <strong class="text-on-surface-variant">Duyệt hủy hóa đơn</strong> (mặc định Admin)@if ($canApproveCancel) — {{ Auth::user()->name }}@endif</span>
                        <span class="text-on-surface-subtle">Hệ thống ghi nhận thời điểm thao tác chính xác vào Audit Log</span>
                    </div>

                    @if ($selectedCancellation->status === 'pending' && ! $canApproveCancel)
                        <x-ui.alert type="warning">
                            Yêu cầu đang chờ Admin (hoặc người được cấp quyền duyệt hủy hóa đơn) phê duyệt.
                        </x-ui.alert>
                    @elseif ($selectedCancellation->status === 'approved')
                        <x-ui.alert type="success">
                            <div class="flex items-center justify-between gap-sm">
                                <span class="font-bold">Hóa đơn đã được Admin phê duyệt hủy và hoàn tác công nợ.</span>
                                <span class="font-code text-xs">{{ $selectedCancellation->updated_at->format('d/m/Y H:i') }}</span>
                            </div>
                        </x-ui.alert>
                    @elseif ($selectedCancellation->status === 'rejected')
                        <x-ui.alert type="error">
                            <div class="flex items-center justify-between gap-sm">
                                <span class="font-bold">Yêu cầu hủy đã bị Admin từ chối: {{ $selectedCancellation->rejection_reason }}</span>
                                <span class="font-code text-xs">{{ $selectedCancellation->updated_at->format('d/m/Y H:i') }}</span>
                            </div>
                        </x-ui.alert>
                    @endif
                </div>
            </div>

            @if ($selectedCancellation->status === 'pending' && $canApproveCancel)
                <x-slot:footer>
                    <p class="mr-auto hidden items-center gap-1.5 self-center text-xs text-on-surface-variant md:flex">
                        <span class="material-symbols-outlined text-base text-warning" aria-hidden="true">verified_user</span>
                        Duyệt sẽ hoàn tác công nợ và khóa vĩnh viễn số hóa đơn này.
                    </p>
                    <x-ui.button variant="secondary" icon="close" x-on:click="$dispatch('open-modal', 'reject-cancellation')">Từ chối hủy</x-ui.button>
                    <x-ui.button icon="delete_forever" x-on:click="$dispatch('open-modal', 'confirm-cancellation')">Duyệt hủy hóa đơn</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.modal>

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
                        <strong class="font-code font-bold text-error">{{ \App\Support\Money::format((float) ($selectedCancellation->amount)) }}</strong>
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

        {{-- Phóng to ảnh minh chứng --}}
        <x-ui.modal name="zoom-cancel-proof" :title="'Minh chứng hủy: '.$selectedCancellation->invoice_number" max-width="3xl">
            <div class="flex min-h-[300px] items-center justify-center">
                @if ($selectedCancellation->proof_image)
                    <img src="{{ $selectedCancellation->proof_image }}" alt="Minh chứng hủy" class="max-h-[70vh] rounded-lg object-contain" />
                @else
                    <x-ui.empty-state icon="receipt_long" title="Chưa có minh chứng" />
                @endif
            </div>
        </x-ui.modal>
    @endif

    {{-- MODAL TẠO YÊU CẦU HỦY MỚI (Từ Header) --}}
    <x-ui.modal name="new-cancel" title="Yêu cầu hủy hóa đơn thu tiền" max-width="md" :show="old('_modal') === 'new-cancel'">
        <form id="new-cancel-form" action="{{ route('tuition.invoices.cancellations.store') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="hidden" name="_modal" value="new-cancel">
            @if (old('_modal') === 'new-cancel')
                @include('tuition.partials.errors')
            @endif
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
