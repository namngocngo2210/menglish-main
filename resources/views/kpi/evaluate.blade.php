{{-- Mockup: roundcuoi 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/04_kpi_thang (bảng 6 nhóm / 15 mục) + 03_tong_hop_kpi_danh_gia_thang --}}
<x-app-layout>
    @php
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $money = fn ($v) => number_format((float) $v, 0, ',', '.');
        $total = (float) ($evaluation?->total_score ?? 0);
        [$grade, $gradeLabel, $gradeRange] = \App\Models\KpiEvaluation::gradeFor($total);
        $canConfirm = auth()->user()->can('kpi.confirm') && ! ($isSelf ?? false);
        $itemFund = fn ($c) => $weightTotal > 0 ? $fund * (float) $c->weight / $weightTotal : 0;
        $jsItems = $criteria->map(fn ($c) => [
            'id' => $c->id,
            'fund' => round($itemFund($c)),
            'score' => $scores->get($c->id)?->score !== null ? (float) $scores->get($c->id)->score : null,
            'critical' => (bool) $scores->get($c->id)?->critical_error,
        ])->keyBy('id');
        $periodValue = sprintf('%04d-%02d', $year, $month);
        $periodOptions = collect(range(0, 11))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => 'Tháng '.now()->startOfMonth()->subMonths($i)->format('m / Y')])
            ->put($periodValue, 'Tháng '.sprintf('%02d / %04d', $month, $year))->sortKeysDesc();
    @endphp

    <x-ui.page-header :title="'KPI tháng — '.$staff->name"
                      :description="($isAcademicStaff ? 'Học vụ' : \App\Helpers\AclHelper::roleLabel((string) $staff->getRoleNames()->first())).' — Xem và cập nhật hiệu suất công việc hàng tháng.'">
        <x-slot:breadcrumbs>
            <a href="{{ route('kpi.monthly', ['month' => $month, 'year' => $year]) }}" class="hover:text-primary">Tổng hợp KPI & Đánh giá tháng</a>
            <span aria-hidden="true">/</span><span>{{ $staff->name }}</span>
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form method="GET" class="mb-lg flex flex-wrap items-end gap-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm"
          x-data @change="
            const f = $el; const staff = f.querySelector('[name=staff]').value;
            window.location.href = @js(url('kpi/evaluate')) + '/' + staff + '?period=' + f.querySelector('[name=period]').value;">
        <x-ui.select name="staff" label="Nhân sự" :options="$staffOptions->pluck('name', 'id')" :value="$staff->id" />
        <x-ui.select name="period" label="Kỳ đánh giá" :options="$periodOptions" :value="$periodValue" />
        @if ($evaluation)
            <x-ui.badge :color="$evaluation->status === 'confirmed' ? 'success' : 'warning'">
                {{ $evaluation->status === 'confirmed' ? 'Đã chốt KPI tháng' : 'Bản nháp — chưa chốt' }}
            </x-ui.badge>
        @else
            <x-ui.badge color="neutral">Chưa đánh giá</x-ui.badge>
        @endif
    </form>

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif
    @if ($isSelf ?? false)
        <x-ui.alert type="warning" title="Không tự chấm KPI" class="mb-md">Bạn đang xem phiếu KPI của chính mình — việc chấm điểm do cấp quản lý thực hiện.</x-ui.alert>
    @endif

    @if ($criteria->isEmpty())
        <x-ui.empty-state icon="tune" title="Chưa có chỉ số KPI" description="Vui lòng cấu hình chỉ số KPI (6 nhóm / 15 mục) trước.">
            <x-ui.button :href="route('kpi.criteria')" icon="tune">Cấu hình chỉ số</x-ui.button>
        </x-ui.empty-state>
    @else
        <form method="POST" action="{{ route('kpi.evaluate.store', $staff->id) }}" class="space-y-lg"
              x-data="{
                items: @js($jsItems),
                pct(it) { return it.critical ? 0 : Math.max(0, Math.min(100, parseFloat(it.score) || 0)); },
                money(it) { return Math.round(it.fund * this.pct(it) / 100); },
                get totalMoney() { return Object.values(this.items).reduce((s, it) => s + this.money(it), 0); },
                fmt(n) { return new Intl.NumberFormat('vi-VN').format(n); },
              }">
            @csrf
            <input type="hidden" name="month" value="{{ $month }}">
            <input type="hidden" name="year" value="{{ $year }}">

            <x-ui.alert type="info">
                @if ($isAcademicStaff)
                    KPI Học vụ tính lương tự động: <strong>quỹ {{ $money($fund) }}đ × điểm KPI tổng</strong> (mục chưa chấm tính 0%). Chỉ phiếu <strong>đã chốt</strong> được dùng khi tính lương.
                @else
                    Điểm KPI tổng = Σ(% đạt × trọng số) / Σ trọng số các mục đang áp dụng.
                @endif
                Bật "Lỗi nghiêm trọng" tại một mục sẽ tự động đưa % đạt của mục đó về 0%.
            </x-ui.alert>

            <x-ui.data-table min-width="1000px">
                <table>
                    <thead>
                        <tr>
                            <th>Mã</th>
                            <th>Tiêu chí</th>
                            <th class="text-right">Quỹ (VNĐ)</th>
                            <th>Ngưỡng 100</th>
                            <th>Ngưỡng 50</th>
                            <th>Thực tế</th>
                            <th class="text-center">% Đạt</th>
                            <th class="text-right">Tiền KPI</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($criteria->groupBy(fn ($c) => $c->group_name ?: 'Chưa phân nhóm') as $groupName => $groupItems)
                            <tr class="bg-surface-container-low">
                                <td colspan="8" class="font-body-semibold text-body-semibold text-on-surface">{{ $loop->iteration }}. {{ $groupName }}</td>
                            </tr>
                            @foreach ($groupItems as $cr)
                                @php $item = $scores->get($cr->id); @endphp
                                <tr>
                                    <td class="font-code text-code">{{ $cr->code ?: '—' }}</td>
                                    <td>
                                        <p class="font-medium text-on-surface">{{ $cr->name }}</p>
                                        <label class="mt-xs inline-flex items-center gap-xs font-caption text-caption text-error">
                                            <input type="checkbox" name="critical[{{ $cr->id }}]" value="1" x-model="items[{{ $cr->id }}].critical" @disabled(! $canConfirm)
                                                   class="rounded border-outline-variant text-error focus:ring-error/30">
                                            Lỗi nghiêm trọng
                                        </label>
                                        @if ($cr->description)<p class="font-caption text-caption text-on-surface-variant">{{ $cr->description }}</p>@endif
                                    </td>
                                    <td class="text-right"><x-ui.money :value="$itemFund($cr)" suffix="" /></td>
                                    <td class="font-body-small text-body-small">{{ $cr->threshold_full ?: ($cr->target ?: '—') }}</td>
                                    <td class="font-body-small text-body-small">{{ $cr->threshold_half ?: '—' }}</td>
                                    <td>
                                        <input type="text" name="actual[{{ $cr->id }}]" value="{{ $item?->actual }}" placeholder="VD: 95%" aria-label="Thực tế {{ $cr->code }}" @disabled(! $canConfirm)
                                               class="w-28 rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small">
                                    </td>
                                    <td class="text-center">
                                        <span class="inline-flex items-center gap-xs">
                                            <input type="number" name="score[{{ $cr->id }}]" x-model="items[{{ $cr->id }}].score" :disabled="items[{{ $cr->id }}].critical"
                                                   value="{{ $item?->score !== null ? rtrim(rtrim(number_format($item->score, 2, '.', ''), '0'), '.') : '' }}"
                                                   min="0" max="100" step="1" placeholder="0-100" aria-label="% đạt {{ $cr->code }}" @disabled(! $canConfirm)
                                                   class="w-20 rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs text-center font-mono font-semibold">%
                                        </span>
                                    </td>
                                    <td class="text-right font-mono font-semibold" x-text="fmt(money(items[{{ $cr->id }}]))">{{ $money($item ? $itemFund($cr) * (float) $item->score / 100 : 0) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                        <tr class="bg-primary-fixed/30">
                            <td colspan="7" class="text-right font-body-semibold text-body-semibold">Tổng tiền KPI dự tính:</td>
                            <td class="text-right font-mono font-bold text-primary"><span x-text="fmt(totalMoney)">{{ $money($fund * $total / 100) }}</span> ₫</td>
                        </tr>
                    </tbody>
                </table>
            </x-ui.data-table>

            <div class="grid grid-cols-1 gap-lg lg:grid-cols-3">
                {{-- Chi tiết điểm KPI theo nhóm (mockup 03) --}}
                <x-ui.data-table class="lg:col-span-2">
                    <x-slot:header><h3 class="font-h3 text-h3 text-on-surface">Chi tiết điểm KPI theo nhóm</h3></x-slot:header>
                    <table>
                        <thead><tr><th>Nhóm KPI</th><th class="text-center">Số tiêu chí</th><th class="text-right">Quỹ KPI (VNĐ)</th><th class="text-right">Tiền đạt (VNĐ)</th><th class="text-right">% Đạt</th></tr></thead>
                        <tbody>
                            @foreach ($groupSummary as $groupName => $row)
                                <tr>
                                    <td>{{ $groupName }}</td>
                                    <td class="text-center font-mono">{{ $row['count'] }}</td>
                                    <td class="text-right"><x-ui.money :value="$row['fund']" suffix="" /></td>
                                    <td class="text-right"><x-ui.money :value="$row['earned']" suffix="" /></td>
                                    <td class="text-right font-mono">{{ $fmt($row['percent']) }}%</td>
                                </tr>
                            @endforeach
                            <tr class="font-semibold">
                                <td>Tổng cộng</td>
                                <td class="text-center font-mono">{{ $groupSummary->sum('count') }}</td>
                                <td class="text-right"><x-ui.money :value="$groupSummary->sum('fund')" suffix="" /></td>
                                <td class="text-right"><x-ui.money :value="$groupSummary->sum('earned')" suffix="" /></td>
                                <td class="text-right font-mono">{{ $fmt($total) }}%</td>
                            </tr>
                        </tbody>
                    </table>
                    <x-slot:footer><p class="p-sm font-caption text-caption text-on-surface-variant">Số liệu theo phiếu đã lưu{{ $evaluation?->evaluator ? ' — người đánh giá: '.$evaluation->evaluator->name : '' }}.</p></x-slot:footer>
                </x-ui.data-table>

                <div class="space-y-md">
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                        <h3 class="font-h3 text-h3 text-on-surface">Xếp loại tháng</h3>
                        @if ($evaluation)
                            <div class="mt-sm flex items-center gap-md">
                                <span class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-container font-h1 text-h1 text-white">{{ $grade }}</span>
                                <div>
                                    <p class="font-body-semibold text-body-semibold">{{ $gradeLabel }}</p>
                                    <p class="font-body-small text-body-small text-on-surface-variant">Tổng KPI đạt: {{ $fmt($total) }}%</p>
                                </div>
                            </div>
                            <div class="mt-sm h-2 w-full overflow-hidden rounded-full bg-surface-container"><div class="h-2 rounded-full bg-primary-container" style="width: {{ min(100, max(0, $total)) }}%"></div></div>
                            <p class="mt-xs flex justify-between font-caption text-caption text-on-surface-variant"><span>0%</span><span>Tiêu chuẩn {{ $gradeLabel }}: {{ $gradeRange }}</span><span>100%</span></p>
                        @else
                            <p class="mt-sm font-body-small text-body-small text-on-surface-variant">Chưa đánh giá tháng này.</p>
                        @endif
                    </section>
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                        <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-warning" aria-hidden="true">warning</span>Cảnh báo hiệu suất</h3>
                        <div class="mt-sm grid grid-cols-2 gap-sm">
                            <div class="rounded-lg bg-warning/10 p-sm">
                                <p class="flex items-center gap-xs font-caption text-caption text-on-warning-container"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">trending_down</span>Mức cảnh báo (≤50%)</p>
                                <p class="font-h2 text-h2 text-warning">{{ $warnings['low'] }}</p>
                                <p class="font-caption text-caption text-on-warning-container">Tiêu chí cần chú ý</p>
                            </div>
                            <div class="rounded-lg bg-error-container p-sm">
                                <p class="flex items-center gap-xs font-caption text-caption text-on-error-container"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">cancel</span>Không đạt (0%)</p>
                                <p class="font-h2 text-h2 text-error">{{ $warnings['zero'] }}</p>
                                <p class="font-caption text-caption text-on-error-container">Tiêu chí bỏ lỡ</p>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                <h3 class="font-h3 text-h3 text-on-surface">Đánh giá &amp; Nhận xét từ Quản lý</h3>
                <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                    <x-ui.textarea name="strengths" label="Điểm tốt" rows="3" :value="$evaluation?->strengths" placeholder="Nhập các điểm tích cực..." :disabled="! $canConfirm" />
                    <x-ui.textarea name="improvements" label="Điểm cần cải thiện" rows="3" :value="$evaluation?->improvements" placeholder="Nhập các điểm cần khắc phục..." :disabled="! $canConfirm" />
                    <x-ui.textarea name="next_actions" label="Hành động tháng sau" rows="3" :value="$evaluation?->next_actions" placeholder="Mục tiêu hoặc kế hoạch cụ thể cho tháng tới..." :disabled="! $canConfirm" />
                </div>
                <x-ui.textarea name="comment" label="Nhận xét tổng quan" rows="2" :value="$evaluation?->comment" :disabled="! $canConfirm" />
            </section>

            @if ($canConfirm)
                <div class="flex flex-wrap items-center justify-end gap-sm">
                    <x-ui.button type="submit" variant="secondary" name="action" value="draft" icon="save">Lưu nháp</x-ui.button>
                    <x-ui.button type="submit" name="action" value="confirm" icon="lock">Chốt KPI tháng &amp; Lưu đánh giá</x-ui.button>
                </div>
            @endif
        </form>
    @endif
</x-app-layout>
