{{-- Mockup: ui-full-tinh-nang-menglish/epic-7/danh-sach-bang-luong-theo-ky (bảng lương của một kỳ) --}}
<x-app-layout>
    @include('partials.data-confirm')
    @php
        [$statusColor, $statusText] = match ($period->status) {
            'paid' => ['secondary', 'Đã trả'],
            'approved' => ['success', 'Đã chốt'],
            default => ['info', 'Đang tính'],
        };
        $kpiColors = ['done' => 'success', 'pending' => 'error', 'na' => 'neutral'];
        $kpiIcons = ['done' => 'check', 'pending' => 'close', 'na' => 'remove'];
    @endphp

    <x-ui.page-header title="Danh sách bảng lương theo kỳ"
                      :description="'Quản lý và chốt lương giáo viên, nhân sự theo từng kỳ — '.$period->title.' ('.$period->code.', '.$period->start_date->format('d/m/Y').' – '.$period->end_date->format('d/m/Y').')'">
        <x-slot:breadcrumbs>
            <a href="{{ route('payroll.periods.index') }}" class="hover:text-primary">Kỳ lương</a>
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">chevron_right</span>
            <span>{{ $period->title }}</span>
            <x-ui.badge :color="$statusColor">{{ $statusText }}</x-ui.badge>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @can('payroll.calculate')
                @unless ($period->isLocked())
                    <form action="{{ route('payroll.periods.calculate', $period->id) }}" method="POST">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="sync">Đồng bộ &amp; Tính lại</x-ui.button>
                    </form>
                @endunless
            @endcan
            @can('payroll.approve')
                @unless ($period->isLocked())
                    <form action="{{ route('payroll.periods.approve', $period->id) }}" method="POST"
                          @if ($kpiPending->isNotEmpty()) data-confirm="Còn {{ $kpiPending->count() }} nhân sự chưa chốt KPI — KPI của họ sẽ tính 0đ. Vẫn chốt bảng lương?" @endif>
                        @csrf
                        <x-ui.button type="submit" icon="task_alt">Chốt bảng lương</x-ui.button>
                    </form>
                @endunless
            @endcan
            @can('payroll.mark_paid')
                @if ($period->status === 'approved')
                    <form action="{{ route('payroll.periods.mark-paid', $period->id) }}" method="POST">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="payments">Đánh dấu đã trả</x-ui.button>
                    </form>
                @elseif ($period->status !== 'paid')
                    <span title="Chỉ kỳ lương đã chốt mới đánh dấu đã trả"><x-ui.button variant="secondary" icon="payments" disabled>Đánh dấu đã trả</x-ui.button></span>
                @endif
            @endcan
            <x-ui.button variant="secondary" icon="download" :href="route('payroll.periods.export', $period->id)">Xuất Excel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-lg">
        @error('period')
            <x-ui.alert type="error">{{ $message }}</x-ui.alert>
        @enderror

        @if ($kpiPending->isNotEmpty() && ! $period->isLocked())
            <x-ui.alert type="error" dismissible
                        :title="'Chưa thể chốt bảng lương kỳ '.str_pad($period->month, 2, '0', STR_PAD_LEFT).'/'.$period->year.' do: Còn '.$kpiPending->count().' nhân sự chưa chốt KPI'">
                {{ $kpiPending->take(8)->map(fn ($r) => $r->user?->name)->filter()->implode(', ') }}{{ $kpiPending->count() > 8 ? '…' : '' }}.
                Chọn bậc KPI giữ HS / nhập KPI trên phiếu lương, hoặc chốt đánh giá KPI Học vụ tháng rồi bấm "Đồng bộ &amp; Tính lại".
                <a href="{{ request()->fullUrlWithQuery(['kpi' => 'pending', 'page' => null]) }}" class="font-semibold underline">Xem danh sách</a>
            </x-ui.alert>
        @endif

        {{-- Khối / loại nhân sự --}}
        <nav class="flex flex-wrap gap-sm border-b border-surface-container pb-sm" aria-label="Bảng lương theo khối">
            <x-ui.button icon="groups" size="sm" :href="route('payroll.periods.show', $period->id)">Toàn bộ / GV Part-time</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="work" :href="route('payroll.periods.fulltime', $period->id)">Giáo viên Full-time</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="school" :href="route('payroll.periods.academic', $period->id)">Khối Học thuật</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="support_agent" :href="route('payroll.periods.operations', $period->id)">Khối Học vụ &amp; Vận hành</x-ui.button>
        </nav>

        <div class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card label="Tổng chi quỹ lương" :value="number_format((float) $period->total_amount, 0, ',', '.').'đ'" tone="primary" icon="account_balance_wallet"
                            :hint="$period->records->count().' nhân sự nhận lương'" />
            <x-ui.stat-card label="Tổng buổi dạy (Part-time)" :value="$period->records->where('employee_type', 'parttime')->sum('teaching_sessions').' buổi'" icon="event_available"
                            :hint="$period->records->sum('actual_hours').' giờ chấm công hợp lệ'" />
            <x-ui.stat-card label="Tổng thưởng KPI / Giữ học sinh" :value="number_format((float) $period->records->sum('kpi_bonus'), 0, ',', '.').'đ'" tone="success" icon="trending_up"
                            hint="Giữ HS (PT) · KPI tự do · KPI Học vụ" />
            <x-ui.stat-card label="Tổng khấu trừ & Phạt" :value="'-'.number_format((float) $period->records->sum('total_deductions'), 0, ',', '.').'đ'" tone="error" icon="money_off"
                            hint="BHXH, Công đoàn, TNCN, phạt, thu hồi, khấu trừ tự do" />
        </div>

        <form method="GET" action="{{ route('payroll.periods.show', $period->id) }}" role="search"
              class="flex flex-wrap items-end gap-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
            <label class="flex flex-col gap-xs">
                <span class="font-label text-label uppercase tracking-wide text-on-surface-variant">Kỳ lương</span>
                <select onchange="window.location.href = this.value" aria-label="Chọn kỳ lương"
                        class="rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                    @foreach ($allPeriods as $p)
                        <option value="{{ route('payroll.periods.show', $p->id) }}" @selected($p->id === $period->id)>Tháng {{ str_pad($p->month, 2, '0', STR_PAD_LEFT) }}/{{ $p->year }} ({{ $p->code }})</option>
                    @endforeach
                </select>
            </label>
            <label class="relative min-w-[220px] flex-1">
                <span class="sr-only">Tìm nhân sự</span>
                <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                <input type="search" name="search" value="{{ $search }}" placeholder="Tìm giáo viên / nhân sự..."
                       class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-10 pr-md font-body-base text-body-base placeholder:text-on-surface-variant/60 focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
            </label>
            <x-ui.select name="type" :options="\App\Models\PayrollRecord::SALARY_ROLE_LABELS" placeholder="Mọi loại nhân sự" aria-label="Loại nhân sự" />
            <x-ui.select name="kpi" :options="['pending' => 'Chưa chốt KPI', 'done' => 'Đã chốt KPI']" placeholder="Mọi trạng thái KPI" aria-label="Trạng thái KPI" />
            <x-ui.button type="submit" variant="secondary" icon="filter_list">Lọc</x-ui.button>
        </form>

        <x-ui.data-table min-width="1500px">
            <x-slot:header>
                <h3 class="font-h3 text-h3 text-on-surface">Bảng lương từng nhân sự (Kỳ {{ $period->month }}/{{ $period->year }})</h3>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Tên giáo viên / nhân sự</th>
                        <th class="text-center">Loại</th>
                        <th>Trạng thái bảng lương</th>
                        <th>Trạng thái KPI</th>
                        <th class="text-right">Lương CB / Buổi dạy</th>
                        <th class="text-right">KPI</th>
                        <th class="text-right">Buổi GVNN</th>
                        <th class="text-right">Hoa hồng</th>
                        <th class="text-right">Tái tục</th>
                        <th class="text-right">Phụ cấp tự do</th>
                        <th class="text-right">BHXH + CĐ</th>
                        <th class="text-right">Thuế TNCN</th>
                        <th class="text-right">Phạt &amp; trừ khác</th>
                        <th class="text-right">Thực nhận</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $r)
                        @php [$kpiKey, $kpiLabel] = $r->kpi_state; @endphp
                        <tr x-data="{ openForeignModal: false }">
                            <td>
                                <div class="flex items-center gap-sm">
                                    <x-ui.avatar :name="$r->user?->name ?? 'U'" size="sm" />
                                    <div>
                                        <a href="{{ route('payroll.records.show', $r->id) }}" class="font-semibold text-on-surface hover:text-primary hover:underline" title="Xem phiếu lương">{{ $r->user?->name }}</a>
                                        <p class="font-caption text-caption text-on-surface-variant">{{ $r->user?->employee_code ?: $r->user?->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <x-ui.badge :color="$r->isPartTime() ? 'info' : 'neutral'">{{ $r->employee_type_label }}</x-ui.badge>
                                <p class="mt-xs font-caption text-caption text-on-surface-variant">{{ $r->salary_role_label }}</p>
                            </td>
                            <td>
                                <span class="inline-flex items-center gap-xs">
                                    <x-ui.badge :color="$statusColor">{{ $statusText }}</x-ui.badge>
                                    @if ($kpiKey === 'pending' && ! $period->isLocked())
                                        <span class="material-symbols-outlined text-[18px] text-error" title="Chưa chốt KPI" aria-label="Chưa chốt KPI">error</span>
                                    @endif
                                </span>
                            </td>
                            <td>
                                <span class="inline-flex items-center gap-xs font-body-small text-body-small {{ $kpiKey === 'done' ? 'text-tertiary' : ($kpiKey === 'pending' ? 'text-error' : 'text-on-surface-variant') }}">
                                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ $kpiIcons[$kpiKey] }}</span>{{ $kpiLabel }}
                                </span>
                            </td>
                            <td class="text-right font-mono">
                                @if ($r->isPartTime())
                                    <span class="text-tertiary">{{ number_format($r->teaching_salary) }}đ</span>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ (int) $r->teaching_sessions }} buổi</p>
                                @else
                                    {{ number_format((float) $r->base_salary + (float) $r->teaching_salary) }}đ
                                @endif
                            </td>
                            <td class="text-right font-mono">
                                {{ number_format($r->kpi_bonus) }}đ
                                @if ($r->kpi_source === 'retention')
                                    <p class="font-caption text-caption text-on-surface-variant">{{ (int) $r->retention_students }} HS × {{ $r->retention_tier !== null ? number_format($r->retention_tier) : 'chưa chọn bậc' }}</p>
                                @elseif ($r->kpi_source === 'academic_kpi')
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $r->kpi_score !== null ? rtrim(rtrim(number_format((float) $r->kpi_score, 2), '0'), '.').'% quỹ' : 'chưa chấm' }}</p>
                                @endif
                            </td>
                            <td class="text-right font-mono">{{ $r->isPartTime() ? number_format($r->foreign_session_pay).'đ' : '—' }}</td>
                            <td class="text-right font-mono text-tertiary">
                                {{ number_format($r->commission_bonus) }}đ
                                @if ((float) $r->commission_deferred > 0)
                                    <p class="font-caption text-caption font-semibold text-amber-700">Hoãn {{ number_format($r->commission_deferred) }}đ</p>
                                @endif
                            </td>
                            <td class="text-right font-mono">{{ number_format($r->renew_bonus) }}đ</td>
                            <td class="text-right font-mono">{{ number_format((float) $r->allowance + (float) $r->other_bonus) }}đ</td>
                            <td class="text-right font-mono text-error">-{{ number_format((float) $r->insurance_deduction + (float) $r->union_deduction) }}đ</td>
                            <td class="text-right font-mono text-error">-{{ number_format($r->tax_deduction) }}đ</td>
                            <td class="text-right font-mono text-error">-{{ number_format((float) $r->penalty_deduction + (float) $r->commission_clawback + (float) $r->other_deduction + (float) $r->foreign_teacher_deduction) }}đ</td>
                            <td class="text-right font-mono font-bold text-primary">{{ number_format($r->net_salary) }}đ</td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-xs">
                                    @if (! $period->isLocked() && $r->isPartTime())
                                        @can('payroll.edit')
                                            <x-ui.button variant="secondary" size="sm" @click="openForeignModal = true">Buổi GVNN</x-ui.button>
                                            <div x-show="openForeignModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 text-left">
                                                <div class="w-full max-w-md space-y-md rounded-xl bg-surface-container-lowest p-lg shadow-xl" @click.away="openForeignModal = false">
                                                    <div class="flex items-center justify-between border-b border-surface-container pb-sm">
                                                        <div>
                                                            <h3 class="font-h3 text-h3 text-on-surface">Lương buổi có GVNN</h3>
                                                            <p class="font-body-small text-body-small text-on-surface-variant">{{ $r->user?->name }}</p>
                                                        </div>
                                                        <x-ui.button variant="ghost" size="sm" icon="close" aria-label="Đóng" @click="openForeignModal = false" />
                                                    </div>
                                                    <form action="{{ route('payroll.records.update', $r->id) }}" method="POST" class="space-y-md">
                                                        @csrf
                                                        <x-ui.alert type="warning"><strong>Chờ BA chốt:</strong> cách tính lương buổi có GVNN chưa được xác nhận. Kế toán nhập tổng tiền cộng cho GV. Kỳ này có {{ (int) $r->foreign_teacher_sessions_count }} buổi GVNN cùng lớp.</x-ui.alert>
                                                        <x-ui.input type="number" name="foreign_session_pay" label="Số tiền (VNĐ)" :value="(int) $r->foreign_session_pay" min="0" step="1000" required />
                                                        <x-ui.textarea name="notes" label="Ghi chú" rows="2" :value="$r->adjustment_notes" placeholder="Ghi chú thêm..." />
                                                        <div class="flex justify-end gap-sm border-t border-surface-container pt-sm">
                                                            <x-ui.button variant="secondary" @click="openForeignModal = false">Hủy</x-ui.button>
                                                            <x-ui.button type="submit">Lưu</x-ui.button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        @endcan
                                    @endif
                                    <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('payroll.records.show', $r->id)">Chi tiết</x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="15"><x-ui.empty-state icon="payments" title="Chưa có chi tiết lương" :description="$search || $type || $kpi ? 'Không có nhân sự khớp bộ lọc.' : 'Bấm “Đồng bộ & Tính lại” để tính lương cho kỳ này.'" /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$records" unit="nhân sự" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
