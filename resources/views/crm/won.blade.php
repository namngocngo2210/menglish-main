<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-4">
        @if (session('status'))
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-start gap-2">
                    <span class="material-symbols-outlined text-emerald-600">check_circle</span>
                    <span>{{ session('status') }}</span>
                </div>
                @if (session('bill_url'))
                    <a href="{{ session('bill_url') }}" target="_blank" class="shrink-0 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition text-center">
                        Xem phiếu thu &amp; mã VietQR
                    </a>
                @endif
            </div>
        @endif
        @if (session('temporary_password'))
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                <div class="font-bold">Tài khoản học viên vừa tạo — chỉ hiển thị một lần</div>
                <div class="mt-1">Email: <span class="font-mono font-semibold">{{ session('student_account_email') }}</span></div>
                <div>Mật khẩu tạm: <span class="font-mono font-semibold">{{ session('temporary_password') }}</span></div>
                <div class="mt-1 text-xs">Yêu cầu học viên đổi mật khẩu ngay lần đăng nhập đầu tiên.</div>
            </div>
        @endif
        @include('crm.partials.waiting-class-table')

        @include('crm.partials.list-filters', ['dateLabel' => 'Ngày chốt', 'exportable' => true])

        <!-- Stats summary -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs font-medium text-gray-500">Tổng deal đã chốt</span>
                <div class="text-2xl font-extrabold text-gray-900 mt-1">{{ number_format($totalCount) }} học viên</div>
            </div>
            <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs font-medium text-gray-500">Tổng giá trị hợp đồng</span>
                <div class="text-2xl font-extrabold text-gray-900 mt-1">{{ number_format($totalContractAmount) }}đ</div>
            </div>
            <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs font-medium text-gray-500">Thực thu đã duyệt</span>
                <div class="text-2xl font-extrabold text-emerald-600 mt-1">{{ number_format($totalCollectedAmount) }}đ</div>
            </div>
            <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs font-medium text-gray-500">Công nợ còn lại</span>
                <div class="text-2xl font-extrabold text-amber-600 mt-1">{{ number_format($totalDebtAmount) }}đ</div>
            </div>
        </div>

        <!-- Won Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[960px]">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4 min-w-[180px] whitespace-nowrap">Họ tên &amp; SĐT</th>
                            <th class="py-3 px-4 min-w-[150px] whitespace-nowrap">Cơ sở &amp; Nguồn</th>
                            <th class="py-3 px-4 min-w-[160px] whitespace-nowrap">Khóa học đăng ký</th>
                            <th class="py-3 px-4 min-w-[150px] whitespace-nowrap">Lớp học</th>
                            <th class="py-3 px-4 min-w-[140px] whitespace-nowrap">Giá trị hợp đồng</th>
                            <th class="py-3 px-4 min-w-[130px] whitespace-nowrap">Tình trạng thu</th>
                            <th class="py-3 px-4 min-w-[140px] whitespace-nowrap">Sales phụ trách</th>
                            <th class="py-3 px-4 min-w-[120px] whitespace-nowrap">Ngày chốt</th>
                            <th class="py-3 px-4 text-right min-w-[160px] whitespace-nowrap">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($wonCustomers as $wc)
                            <tr class="hover:bg-emerald-50/20 transition">
                                <td class="py-3.5 px-4 font-medium whitespace-nowrap">
                                    <a href="{{ route('crm.customers.show', $wc->id) }}" class="font-bold text-gray-900 hover:text-primary transition whitespace-nowrap">{{ $wc->name }}</a>
                                    <div class="text-[11px] text-gray-400 font-mono whitespace-nowrap">{{ $wc->phone }}</div>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-semibold text-gray-900 whitespace-nowrap">{{ $wc->branch?->name ?? '—' }}</div>
                                    <div class="text-[11px] text-gray-400 whitespace-nowrap">{{ $wc->source }}</div>
                                </td>
                                <td class="py-3.5 px-4 font-semibold text-gray-900 whitespace-nowrap">{{ $wc->course_interest }}</td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @php($wcClass = $wc->convertedStudent?->currentClass)
                                    <div class="font-semibold text-gray-900">{{ $wcClass?->name ?? 'Chưa xếp lớp' }}</div>
                                    @if ($wcClass)<div class="text-[11px] text-gray-400 font-mono">{{ $wcClass->code }}</div>@endif
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-emerald-600 text-sm whitespace-nowrap">{{ number_format($wc->deal_value) }}đ</td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @php($tuition = $wc->convertedStudent?->tuition)
                                    @php($pendingAmount = $tuition?->receipts?->where('status', 'pending')->sum('amount') ?? 0)
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border whitespace-nowrap inline-block {{ $pendingAmount > 0 ? 'bg-amber-50 text-amber-700 border-amber-200' : ($tuition?->status_badge ?? 'bg-gray-50 text-gray-700 border-gray-200') }}">
                                        {{ $pendingAmount > 0 ? 'Chờ đối soát '.number_format($pendingAmount).'đ' : ($tuition?->status_label ?? 'Chưa có học phí') }}
                                        @if($tuition && $tuition->debt_amount > 0) · Còn {{ number_format(max(0, $tuition->debt_amount - $pendingAmount)) }}đ @endif
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 font-medium whitespace-nowrap">{{ $wc->assignedUser?->name ?? 'Chưa phân công' }}</td>
                                <td class="py-3.5 px-4 whitespace-nowrap font-mono text-gray-500">{{ $wc->converted_at?->format('d/m/Y') ?? '—' }}</td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    @php($wcEnrollment = $wc->convertedStudent?->enrollments?->where('customer_id', $wc->id)->sortByDesc('id')->first())
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($wcEnrollment && ! $wcEnrollment->confirmed_at)
                                            @can('student.assign_class')
                                                <a href="{{ route('crm.confirmations', ['search' => $wc->convertedStudent?->code]) }}" class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-orange-50 text-orange-700 border border-orange-200 font-bold">
                                                    <span class="material-symbols-outlined text-[14px]">verified_user</span>Xác nhận chính thức
                                                </a>
                                            @else
                                                <span class="text-orange-600 font-semibold">Chờ xác nhận</span>
                                            @endcan
                                        @elseif ($wcEnrollment?->confirmed_at)
                                            <span class="inline-flex items-center gap-1 text-emerald-700 font-semibold"><span class="material-symbols-outlined text-[14px]">check_circle</span>Đã là học viên</span>
                                        @endif
                                        <a href="{{ route('crm.customers.show', $wc->id) }}" class="text-primary hover:underline font-semibold whitespace-nowrap">Xem hồ sơ</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-6 text-gray-400 text-xs">Không có khách đã chốt phù hợp bộ lọc.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-ui.pagination :paginator="$wonCustomers" unit="khách" class="border-t border-gray-100" />
        </div>
    </div>
</x-app-layout>
