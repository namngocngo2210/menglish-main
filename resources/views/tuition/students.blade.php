{{-- Mockup: ui-full-tinh-nang-menglish/hoc-phi-va-hoa-don-ui-mockup/danh-sach-hoc-vien-thu-phi
     "Lập phiếu thu" ở từng dòng → modal 4xl (học viên + khoản nợ chọn sẵn); "Lập phiếu thu mới" (lập tự do) vẫn mở trang riêng.
     Lưu phiếu xong → "tuition-receipts-changed" tải lại #tuition-list (giữ bộ lọc, trang hiện tại). --}}
<x-app-layout title="Công nợ học viên">
    {{-- Nút "Nhập Excel" / "Lập phiếu thu" nằm ở thanh tab workspace Học phí (SidebarMenu). --}}
    {{-- Sổ công nợ: tra cứu mọi khoản học phí. Đôn đốc khoản quá hạn / sắp đến hạn làm ở "Quá hạn & Nhắc phí". --}}
    <x-ui.page-header title="Công nợ học viên" description="Sổ toàn bộ khoản học phí: đã thu, còn nợ, hạn nộp và trạng thái của từng học viên.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="notifications_active" :href="route('tuition.overdue')">Xử lý quá hạn &amp; nhắc phí</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form method="GET" action="{{ route('tuition.students') }}" class="mb-lg grid grid-cols-1 gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md md:grid-cols-12 md:items-end">
        <div class="md:col-span-3">
            <x-ui.select name="branch_id" label="Chi nhánh" placeholder="Tất cả chi nhánh" onchange="this.form.submit()" :options="$branches->pluck('name', 'id')" />
        </div>
        <div class="md:col-span-3">
            <x-ui.select name="class_id" label="Lớp học" placeholder="Tất cả lớp học" onchange="this.form.submit()">
                @foreach ($classes as $cl)
                    <option value="{{ $cl->id }}" @selected((string) request('class_id') === (string) $cl->id)>{{ $cl->name }} ({{ $cl->code }})</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="md:col-span-4">
            <x-ui.input type="search" name="search" label="Tìm kiếm học sinh" icon="search" :value="request('search')" placeholder="Họ tên hoặc mã học sinh..." />
        </div>
        <div class="flex gap-sm md:col-span-2">
            <x-ui.button type="submit" variant="secondary" icon="filter_list" class="flex-1">Lọc</x-ui.button>
            @if (request()->hasAny(['branch_id', 'class_id', 'search', 'status', 'type']))
                <x-ui.button variant="secondary" icon="restart_alt" :href="route('tuition.students')" aria-label="Xóa bộ lọc" />
            @endif
        </div>
    </form>

    <div class="mb-xl grid grid-cols-2 gap-md lg:grid-cols-4">
        <x-ui.stat-card label="Tổng học phí phải thu" :value="number_format($stats['final'], 0, ',', '.').'đ'" icon="request_quote" />
        <x-ui.stat-card label="Đã thực thu" :value="number_format($stats['paid'], 0, ',', '.').'đ'" tone="success" icon="savings" />
        <x-ui.stat-card label="Công nợ còn lại" :value="number_format($stats['debt'], 0, ',', '.').'đ'" tone="warning" icon="account_balance_wallet" />
        <x-ui.stat-card label="Học viên quá hạn" :value="$stats['overdue'].' học viên'" tone="error" icon="report" />
    </div>

    <div id="tuition-list" class="space-y-xl"
         hx-get="{{ route('tuition.students', request()->query()) }}" hx-trigger="tuition-receipts-changed from:body" hx-select="#tuition-list" hx-swap="outerHTML" hx-disinherit="*">
        {{-- Toàn bộ khoản học phí (sổ công nợ) --}}
        <section id="all-tuitions" class="space-y-md">
            <div class="flex flex-wrap items-center gap-sm border-l-4 border-outline pl-sm">
                <h2 class="font-h2 text-h2 uppercase text-on-surface">Toàn bộ khoản học phí</h2>
                <form method="GET" action="{{ route('tuition.students') }}#all-tuitions" class="ml-auto flex items-center gap-sm">
                    @foreach (['branch_id', 'class_id', 'search'] as $keep)
                        @if (request($keep))<input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">@endif
                    @endforeach
                    <x-ui.select name="status" onchange="this.form.submit()" aria-label="Trạng thái công nợ" placeholder="Tất cả trạng thái"
                                 :options="['paid' => 'Đã hoàn thành', 'partial' => 'Đang nợ (Đã cọc)', 'overdue' => 'Quá hạn', 'unpaid' => 'Chưa nộp']" />
                </form>
            </div>
            <x-ui.data-table min-width="900px">
                <table>
                    <thead>
                        <tr>
                            <th>Học viên</th>
                            <th>Lớp học · Cơ sở</th>
                            <th>Khoản thu</th>
                            <th class="text-right">Tổng học phí</th>
                            <th class="text-right">Đã nộp</th>
                            <th class="text-right">Còn nợ</th>
                            <th>Hạn nộp</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tuitions as $t)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <div class="font-body-medium">{{ $t->student?->name }}</div>
                                    <div class="font-code text-caption text-on-surface-variant"><x-ui.code :value="$t->student?->code" /> · {{ $t->student?->phone }}</div>
                                </td>
                                <td>
                                    <div class="text-primary">{{ $t->classModel?->name ?? 'Chưa gán lớp' }}</div>
                                    <div class="font-caption text-caption text-on-surface-variant">{{ $t->branch?->name ?? '—' }}</div>
                                </td>
                                <td>{{ $t->fee_label }}</td>
                                <td class="whitespace-nowrap text-right font-code">{{ number_format((float) $t->final_amount, 0, ',', '.') }}đ</td>
                                <td class="whitespace-nowrap text-right font-code text-tertiary">{{ number_format((float) $t->paid_amount, 0, ',', '.') }}đ</td>
                                <td class="whitespace-nowrap text-right font-code {{ $t->debt_amount > 0 ? 'text-error' : 'text-on-surface-variant' }}">{{ number_format((float) $t->debt_amount, 0, ',', '.') }}đ</td>
                                <td class="whitespace-nowrap font-code text-code">{{ $t->due_date?->format('d/m/Y') ?? '—' }}</td>
                                <td><x-ui.badge :color="$t->status_color">{{ $t->status_label }}</x-ui.badge></td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($t->debt_amount > 0)
                                        @can('tuition.create')
                                            <x-ui.button size="sm" variant="ghost" icon="payments" :href="route('tuition.receipts.create', ['tuition_id' => $t->id])" modal="4xl" title="Lập phiếu thu" aria-label="Lập phiếu thu" />
                                        @endcan
                                    @else
                                        <span class="inline-flex items-center text-tertiary" title="Đã tất toán">
                                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">verified</span><span class="sr-only">Đã tất toán</span>
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9"><x-ui.empty-state icon="payments" title="Không tìm thấy khoản học phí nào" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer>
                    <x-ui.pagination :paginator="$tuitions" unit="khoản học phí" />
                </x-slot:footer>
            </x-ui.data-table>
        </section>

        {{-- Nhóm quá hạn / sắp đến hạn: thu gọn, việc đôn đốc chính nằm ở màn "Quá hạn & Nhắc phí". --}}
        <details class="group rounded-xl border border-outline-variant bg-surface-container-lowest">
            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-sm p-md">
                <span class="material-symbols-outlined text-[20px] text-on-surface-variant transition group-open:rotate-90" aria-hidden="true">chevron_right</span>
                <span class="font-body-medium text-body-medium text-on-surface">Khoản quá hạn &amp; sắp đến hạn</span>
                <x-ui.badge color="error" pill :dot="false">{{ $overdueTuitions->count() }} quá hạn</x-ui.badge>
                <x-ui.badge color="warning" pill :dot="false">{{ $upcoming->total() }} sắp đến hạn</x-ui.badge>
                <a href="{{ route('tuition.overdue') }}" class="ml-auto font-body-small text-body-small text-primary hover:underline">Mở màn Quá hạn &amp; Nhắc phí →</a>
            </summary>
            <div class="space-y-xl border-t border-outline-variant p-md">
                @include('tuition.partials.due-groups')
            </div>
        </details>
    </div>
</x-app-layout>
