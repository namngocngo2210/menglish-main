{{-- Mockup: ui-full-tinh-nang-menglish/hoc-phi-va-hoa-don-ui-mockup/danh-sach-hoc-vien-thu-phi
     "Lập phiếu thu" ở từng dòng → modal 4xl (học viên + khoản nợ chọn sẵn); "Lập phiếu thu mới" (lập tự do) vẫn mở trang riêng.
     Lưu phiếu xong → "tuition-receipts-changed" tải lại #tuition-list (giữ bộ lọc, trang hiện tại). --}}
<x-app-layout title="Danh sách thu phí">
    {{-- Nút "Nhập Excel" / "Lập phiếu thu" nằm ở thanh tab workspace Học phí (SidebarMenu). --}}
    <x-ui.page-header title="Danh sách học viên đến hạn thu phí" description="Theo dõi và quản lý công nợ học phí của học viên." />

    <form method="GET" action="{{ route('tuition.students') }}" class="mb-lg grid grid-cols-1 gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md md:grid-cols-12 md:items-end">
        <label class="block md:col-span-3">
            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Chi nhánh</span>
            <select name="branch_id" onchange="this.form.submit()" class="w-full rounded-lg border-outline-variant font-body-base text-body-base">
                <option value="">Tất cả chi nhánh</option>
                @foreach ($branches as $br)
                    <option value="{{ $br->id }}" @selected((string) request('branch_id') === (string) $br->id)>{{ $br->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block md:col-span-3">
            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Lớp học</span>
            <select name="class_id" onchange="this.form.submit()" class="w-full rounded-lg border-outline-variant font-body-base text-body-base">
                <option value="">Tất cả lớp học</option>
                @foreach ($classes as $cl)
                    <option value="{{ $cl->id }}" @selected((string) request('class_id') === (string) $cl->id)>{{ $cl->name }} ({{ $cl->code }})</option>
                @endforeach
            </select>
        </label>
        <label class="block md:col-span-4">
            <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Tìm kiếm học sinh</span>
            <span class="relative block">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Họ tên hoặc mã học sinh..." class="w-full rounded-lg border-outline-variant pr-10 font-body-base text-body-base" />
                <span class="material-symbols-outlined pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
            </span>
        </label>
        <div class="flex gap-sm md:col-span-2">
            <x-ui.button type="submit" icon="filter_list" class="flex-1">Lọc</x-ui.button>
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
        @include('tuition.partials.due-groups')

        {{-- Toàn bộ khoản học phí (sổ công nợ) --}}
        <section id="all-tuitions" class="space-y-md">
            <div class="flex flex-wrap items-center gap-sm border-l-4 border-outline pl-sm">
                <h2 class="font-h2 text-h2 uppercase text-on-surface">Toàn bộ khoản học phí</h2>
                <form method="GET" action="{{ route('tuition.students') }}#all-tuitions" class="ml-auto flex items-center gap-sm">
                    @foreach (['branch_id', 'class_id', 'search'] as $keep)
                        @if (request($keep))<input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">@endif
                    @endforeach
                    <select name="status" onchange="this.form.submit()" aria-label="Trạng thái công nợ" class="rounded-lg border-outline-variant py-xs font-body-small text-body-small">
                        <option value="">Tất cả trạng thái</option>
                        <option value="paid" @selected(request('status') === 'paid')>Đã hoàn thành</option>
                        <option value="partial" @selected(request('status') === 'partial')>Đang nợ (Đã cọc)</option>
                        <option value="overdue" @selected(request('status') === 'overdue')>Quá hạn</option>
                        <option value="unpaid" @selected(request('status') === 'unpaid')>Chưa nộp</option>
                    </select>
                </form>
            </div>
            <x-ui.data-table min-width="1020px">
                <table>
                    <thead>
                        <tr>
                            <th>Học viên</th>
                            <th>Lớp học</th>
                            <th>Khoản thu</th>
                            <th>Cơ sở</th>
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
                                    <div class="font-code text-caption text-on-surface-variant">{{ $t->student?->code }} · {{ $t->student?->phone }}</div>
                                </td>
                                <td class="whitespace-nowrap text-primary">{{ $t->classModel?->name ?? 'Chưa gán lớp' }}</td>
                                <td>{{ $t->fee_label }}</td>
                                <td class="whitespace-nowrap">{{ $t->branch?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap text-right font-code">{{ number_format((float) $t->final_amount, 0, ',', '.') }}đ</td>
                                <td class="whitespace-nowrap text-right font-code text-tertiary">{{ number_format((float) $t->paid_amount, 0, ',', '.') }}đ</td>
                                <td class="whitespace-nowrap text-right font-code {{ $t->debt_amount > 0 ? 'text-error' : 'text-on-surface-variant' }}">{{ number_format((float) $t->debt_amount, 0, ',', '.') }}đ</td>
                                <td class="whitespace-nowrap font-code text-code">{{ $t->due_date?->format('d/m/Y') ?? '—' }}</td>
                                <td><x-ui.badge :color="$t->status_color">{{ $t->status_label }}</x-ui.badge></td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($t->debt_amount > 0)
                                        @can('tuition.create')
                                            <x-ui.button size="sm" icon="payments" :href="route('tuition.receipts.create', ['tuition_id' => $t->id])" modal="4xl">Lập phiếu thu</x-ui.button>
                                        @endcan
                                    @else
                                        <span class="inline-flex items-center gap-xs font-body-small text-body-small text-tertiary">
                                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">verified</span> Đã tất toán
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10"><x-ui.empty-state icon="payments" title="Không tìm thấy khoản học phí nào" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer>
                    <x-ui.pagination :paginator="$tuitions" unit="khoản học phí" />
                </x-slot:footer>
            </x-ui.data-table>
        </section>
    </div>
</x-app-layout>
