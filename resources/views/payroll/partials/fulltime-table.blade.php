{{--
    Bảng lương Full-time theo công thức Q3 (A6 25/09/2026) — dùng cho các tab GV Full-time / Học thuật / Học vụ & Vận hành.
    Tham số: $records, $emptyText, $avatarClass, $showCommission (bool), $showRenewal (bool).
    Lương CB + KPI + (hoa hồng) + (thưởng tái tục) + phụ cấp tự do − BHXH − Công đoàn − thuế TNCN − phạt & trừ khác.
--}}
<div class="overflow-x-auto">
    <table class="w-full text-left border-collapse text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                <th class="py-3 px-4">Nhân sự</th>
                <th class="py-3 px-4 text-right">Lương cơ bản</th>
                <th class="py-3 px-4 text-right">KPI</th>
                @if ($showCommission)<th class="py-3 px-4 text-right">Hoa hồng</th>@endif
                @if ($showRenewal)<th class="py-3 px-4 text-right">Thưởng tái tục</th>@endif
                <th class="py-3 px-4 text-right">Phụ cấp tự do</th>
                <th class="py-3 px-4 text-right">BHXH</th>
                <th class="py-3 px-4 text-right">Công đoàn</th>
                <th class="py-3 px-4 text-right">Thuế TNCN</th>
                <th class="py-3 px-4 text-right">Phạt & trừ khác</th>
                <th class="py-3 px-4 text-right font-black">Thực lĩnh</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
            @forelse ($records as $r)
                <tr class="hover:bg-orange-50/20 transition">
                    <td class="py-3.5 px-4 font-bold text-gray-900">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full {{ $avatarClass }} flex items-center justify-center font-bold text-xs">
                                {{ Str::substr($r->user?->name ?? 'N', 0, 1) }}
                            </div>
                            <div>
                                <a href="{{ route('payroll.records.show', $r->id) }}" class="text-xs font-bold text-gray-900 hover:text-orange-600 hover:underline" title="Xem phiếu lương">{{ $r->user?->name }}</a>
                                <p class="text-[10px] text-gray-400 font-mono">{{ $r->user?->email }} · {{ $r->salary_role_label }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="py-3.5 px-4 text-right font-mono font-semibold">
                        {{ number_format((float) $r->base_salary + (float) $r->teaching_salary) }}đ
                        @if ((int) $r->teaching_sessions > 0)
                            <p class="text-[10px] text-gray-400">{{ (int) $r->teaching_sessions }} buổi dạy</p>
                        @endif
                    </td>
                    <td class="py-3.5 px-4 text-right font-mono text-amber-600 font-semibold">
                        {{ number_format($r->kpi_bonus) }}đ
                        @if ($r->kpi_source === 'academic_kpi')
                            <p class="text-[10px] text-gray-400">{{ $r->kpi_score !== null ? rtrim(rtrim(number_format((float) $r->kpi_score, 2), '0'), '.').'% × quỹ' : 'chưa chấm KPI' }}</p>
                        @elseif ($r->kpi_source === 'manual')
                            <p class="text-[10px] text-gray-400">{{ $r->kpi_manual_amount !== null ? 'nhập tay' : 'chưa nhập' }}</p>
                        @endif
                    </td>
                    @if ($showCommission)
                        <td class="py-3.5 px-4 text-right font-mono text-emerald-600 font-semibold">
                            {{ number_format($r->commission_bonus) }}đ
                            @if ((float) $r->commission_deferred > 0)
                                <p class="text-[10px] text-amber-700 font-semibold">Hoãn {{ number_format($r->commission_deferred) }}đ</p>
                            @endif
                        </td>
                    @endif
                    @if ($showRenewal)
                        <td class="py-3.5 px-4 text-right font-mono text-emerald-600 font-semibold">{{ number_format($r->renew_bonus) }}đ</td>
                    @endif
                    <td class="py-3.5 px-4 text-right font-mono">{{ number_format((float) $r->allowance + (float) $r->other_bonus) }}đ</td>
                    <td class="py-3.5 px-4 text-right font-mono text-rose-600">-{{ number_format($r->insurance_deduction) }}đ</td>
                    <td class="py-3.5 px-4 text-right font-mono text-rose-600">-{{ number_format($r->union_deduction) }}đ</td>
                    <td class="py-3.5 px-4 text-right font-mono text-rose-600">-{{ number_format($r->tax_deduction) }}đ</td>
                    <td class="py-3.5 px-4 text-right font-mono text-rose-600">-{{ number_format((float) $r->penalty_deduction + (float) $r->commission_clawback + (float) $r->other_deduction + (float) $r->foreign_teacher_deduction) }}đ</td>
                    <td class="py-3.5 px-4 text-right font-mono font-black text-orange-600 text-sm">{{ number_format($r->net_salary) }}đ</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ 9 + ($showCommission ? 1 : 0) + ($showRenewal ? 1 : 0) }}" class="text-center py-8 text-gray-400 text-xs">{{ $emptyText }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
