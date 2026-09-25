<x-app-layout>
    @include('crm.partials.header-tabs')

    {{-- Mockup crm-ui-mockup/khach-hang-chot-thanh-cong: (1) Chờ xếp lớp (Cần xử lý gấp) + Gán lớp, (2) bộ lọc Chi nhánh / Lớp học / Tìm kiếm,
         (3) Khách đã có lớp + Tải báo cáo chi tiết + phân trang. A6: không có "Hủy chốt". --}}
    <div class="flex flex-col gap-lg">
        @if (session('status'))
            <x-ui.alert type="success">
                <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ session('status') }}</span>
                    @if (session('bill_url'))
                        <x-ui.button size="sm" icon="receipt_long" :href="session('bill_url')" target="_blank">Xem phiếu thu &amp; mã VietQR</x-ui.button>
                    @endif
                </div>
            </x-ui.alert>
        @endif
        @if (session('temporary_password'))
            <x-ui.alert type="warning" title="Tài khoản học viên vừa tạo — chỉ hiển thị một lần">
                <div>Email: <span class="font-code font-semibold">{{ session('student_account_email') }}</span></div>
                <div>Mật khẩu tạm: <span class="font-code font-semibold">{{ session('temporary_password') }}</span></div>
                <div class="font-caption text-caption">Yêu cầu học viên đổi mật khẩu ngay lần đăng nhập đầu tiên.</div>
            </x-ui.alert>
        @endif

        @include('crm.partials.waiting-class-table')

        @include('crm.partials.list-filters', ['dateLabel' => 'Ngày chốt', 'searchPlaceholder' => 'Nhập tên hoặc số điện thoại...'])

        <div class="grid grid-cols-2 gap-md lg:grid-cols-4">
            <x-ui.stat-card label="Tổng khách đã chốt" :value="number_format($totalCount, 0, ',', '.').' học viên'" icon="how_to_reg" tone="primary" />
            <x-ui.stat-card label="Tổng giá trị hợp đồng" :value="number_format($totalContractAmount, 0, ',', '.').' ₫'" icon="description" />
            <x-ui.stat-card label="Thực thu đã duyệt" :value="number_format($totalCollectedAmount, 0, ',', '.').' ₫'" icon="payments" tone="success" />
            <x-ui.stat-card label="Công nợ còn lại" :value="number_format($totalDebtAmount, 0, ',', '.').' ₫'" icon="account_balance_wallet" tone="warning" />
        </div>

        <x-ui.data-table min-width="980px">
            <x-slot:header>
                <div class="flex items-center gap-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Khách đã có lớp</h2>
                    <span class="rounded-full bg-primary-container/10 px-sm py-0.5 font-code text-caption font-bold text-primary">{{ number_format($wonCustomers->total(), 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <x-ui.button variant="secondary" size="sm" icon="download" :href="request()->fullUrlWithQuery(['export' => 'xlsx', 'page' => null])">Tải báo cáo chi tiết</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" :href="request()->fullUrlWithQuery(['export' => 'csv', 'page' => null])">CSV</x-ui.button>
                </div>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Họ tên</th>
                        <th>Số điện thoại</th>
                        <th>Chi nhánh</th>
                        <th>Lớp học</th>
                        <th>Thời điểm chốt</th>
                        <th>Tình trạng thu</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($wonCustomers as $wc)
                        @php
                            $wcClass = $wc->convertedStudent?->currentClass;
                            $tuition = $wc->convertedStudent?->tuition;
                            $pendingAmount = $tuition?->receipts?->where('status', 'pending')->sum('amount') ?? 0;
                            $wcEnrollment = $wc->convertedStudent?->enrollments?->where('customer_id', $wc->id)->sortByDesc('id')->first();
                        @endphp
                        <tr>
                            <td class="whitespace-nowrap">
                                <a href="{{ route('crm.customers.show', $wc->id) }}" class="font-body-medium text-body-medium text-on-surface hover:text-primary">{{ $wc->name }}</a>
                                <div class="font-caption text-caption text-on-surface-variant">{{ $wc->course_interest ?: '—' }} · Sale: {{ $wc->assignedUser?->name ?? 'Chưa phân công' }}</div>
                            </td>
                            <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ $wc->phone }}</td>
                            <td class="whitespace-nowrap"><span class="rounded bg-surface-container-high px-sm py-0.5 font-body-small text-body-small text-on-surface-variant">{{ $wc->branch?->name ?? '—' }}</span></td>
                            <td class="whitespace-nowrap">
                                <div class="flex items-center gap-sm">
                                    <span class="h-2 w-2 rounded-full {{ $wcClass ? 'bg-tertiary' : 'bg-outline-variant' }}"></span>
                                    <span class="font-body-medium text-body-medium text-on-surface">{{ $wcClass?->name ?? 'Chưa xếp lớp' }}</span>
                                </div>
                                @if ($wcClass)<div class="pl-md font-code text-caption text-on-surface-variant">{{ $wcClass->code }}</div>@endif
                            </td>
                            <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ $wc->converted_at?->format('H:i d/m/Y') ?? '—' }}</td>
                            <td class="whitespace-nowrap">
                                <span class="inline-block rounded-full border px-sm py-0.5 text-[11px] font-bold {{ $pendingAmount > 0 ? 'bg-amber-50 text-amber-700 border-amber-200' : ($tuition?->status_badge ?? 'bg-surface-container-low text-on-surface-variant border-outline-variant') }}">
                                    {{ $pendingAmount > 0 ? 'Chờ đối soát '.number_format($pendingAmount).'đ' : ($tuition?->status_label ?? 'Chưa có học phí') }}
                                    @if ($tuition && $tuition->debt_amount > 0) · Còn {{ number_format(max(0, $tuition->debt_amount - $pendingAmount)) }}đ @endif
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-sm">
                                    @if ($wcEnrollment && ! $wcEnrollment->confirmed_at)
                                        @can('student.assign_class')
                                            <x-ui.button variant="secondary" size="sm" icon="verified_user" :href="route('crm.confirmations', ['search' => $wc->convertedStudent?->code])">Xác nhận chính thức</x-ui.button>
                                        @else
                                            <span class="font-body-small text-body-small font-semibold text-amber-700">Chờ xác nhận</span>
                                        @endcan
                                    @elseif ($wcEnrollment?->confirmed_at)
                                        <span class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-tertiary"><span class="material-symbols-outlined text-[16px]">check_circle</span>Đã là học viên</span>
                                    @endif
                                    <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('crm.customers.show', $wc->id)" title="Xem hồ sơ" aria-label="Xem hồ sơ" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-ui.empty-state icon="search_off" title="Không có khách đã chốt phù hợp bộ lọc" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>
                <x-ui.pagination :paginator="$wonCustomers" unit="kết quả" />
            </x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
