{{--
    Bảng lương Full-time theo công thức Q3 (A6 25/09/2026) — dùng cho các tab GV Full-time / Học thuật / Học vụ & Vận hành.
    Đặt bên trong <x-ui.data-table>. Tham số: $records, $emptyText, $avatarClass, $showCommission (bool), $showRenewal (bool).
    Lương CB + KPI + (hoa hồng) + (thưởng tái tục) + phụ cấp tự do − BHXH − Công đoàn − thuế TNCN − phạt & trừ khác.
--}}
<table class="text-xs">
    <thead>
        <tr>
            <th>Nhân sự</th>
            <th class="text-right">Lương cơ bản</th>
            <th class="text-right">KPI</th>
            @if ($showCommission)<th class="text-right">Hoa hồng</th>@endif
            @if ($showRenewal)<th class="text-right">Thưởng tái tục</th>@endif
            <th class="text-right">Phụ cấp tự do</th>
            <th class="text-right">BHXH</th>
            <th class="text-right">Công đoàn</th>
            <th class="text-right">Thuế TNCN</th>
            <th class="text-right">Phạt & trừ khác</th>
            <th class="text-right font-black">Thực lĩnh</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($records as $r)
            <tr>
                <td class="font-bold">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full {{ $avatarClass }} flex items-center justify-center font-bold text-xs">
                            {{ Str::substr($r->user?->name ?? 'N', 0, 1) }}
                        </div>
                        <div>
                            <a href="{{ route('payroll.records.show', $r->id) }}" class="text-xs font-bold text-on-surface hover:text-primary hover:underline" title="Xem phiếu lương">{{ $r->user?->name }}</a>
                            <p class="text-[10px] text-on-surface-variant/70 font-mono">{{ $r->user?->email }} · {{ $r->salary_role_label }}</p>
                        </div>
                    </div>
                </td>
                <td class="text-right font-mono font-semibold">
                    {{ number_format((float) $r->base_salary + (float) $r->teaching_salary) }}đ
                    @if ((int) $r->teaching_sessions > 0)
                        <p class="text-[10px] text-on-surface-variant/70">{{ (int) $r->teaching_sessions }} buổi dạy</p>
                    @endif
                </td>
                <td class="text-right font-mono text-warning font-semibold">
                    {{ number_format($r->kpi_bonus) }}đ
                    @if ($r->kpi_source === 'academic_kpi')
                        <p class="text-[10px] text-on-surface-variant/70">{{ $r->kpi_score !== null ? rtrim(rtrim(number_format((float) $r->kpi_score, 2), '0'), '.').'% × quỹ' : 'chưa chấm KPI' }}</p>
                    @elseif ($r->kpi_source === 'manual')
                        <p class="text-[10px] text-on-surface-variant/70">{{ $r->kpi_manual_amount !== null ? 'nhập tay' : 'chưa nhập' }}</p>
                    @endif
                </td>
                @if ($showCommission)
                    <td class="text-right font-mono text-tertiary font-semibold">
                        {{ number_format($r->commission_bonus) }}đ
                        @if ((float) $r->commission_deferred > 0)
                            <p class="text-[10px] text-warning font-semibold">Hoãn {{ number_format($r->commission_deferred) }}đ</p>
                        @endif
                    </td>
                @endif
                @if ($showRenewal)
                    <td class="text-right font-mono text-tertiary font-semibold">{{ number_format($r->renew_bonus) }}đ</td>
                @endif
                <td class="text-right font-mono">{{ number_format((float) $r->allowance + (float) $r->other_bonus) }}đ</td>
                <td class="text-right font-mono text-error">-{{ number_format($r->insurance_deduction) }}đ</td>
                <td class="text-right font-mono text-error">-{{ number_format($r->union_deduction) }}đ</td>
                <td class="text-right font-mono text-error">-{{ number_format($r->tax_deduction) }}đ</td>
                <td class="text-right font-mono text-error">-{{ number_format((float) $r->penalty_deduction + (float) $r->commission_clawback + (float) $r->other_deduction + (float) $r->foreign_teacher_deduction) }}đ</td>
                <td class="text-right font-mono font-black text-primary text-sm">{{ number_format($r->net_salary) }}đ</td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ 9 + ($showCommission ? 1 : 0) + ($showRenewal ? 1 : 0) }}"><x-ui.empty-state :title="$emptyText" /></td>
            </tr>
        @endforelse
    </tbody>
</table>
