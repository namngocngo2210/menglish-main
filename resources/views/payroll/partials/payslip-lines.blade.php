{{--
    Dòng cộng / trừ tự do có tên trên phiếu lương (phụ cấp mở rộng, thưởng khác, khấu trừ khác…).
    Dùng mảng Alpine `lines` chung của form phiếu lương; mỗi lần include chỉ hiển thị một loại ($kind).
    Biến: $kind (earning|deduction), $canEdit, $addLabel, $placeholder
--}}
@if ($canEdit)
    <datalist id="payslip-suggest-{{ $kind }}">
        @foreach ($kind === 'earning'
            ? ['Hỗ trợ thỏa thuận', 'Phụ cấp gửi xe', 'Thưởng khác', 'Phụ cấp ăn trưa', 'Phụ cấp xăng xe', 'Phụ cấp trách nhiệm', 'Lương giảng dạy', 'Hỗ trợ']
            : ['Tạm ứng', 'Vi phạm nội quy', 'Khấu trừ khác'] as $suggestion)
            <option value="{{ $suggestion }}"></option>
        @endforeach
    </datalist>
    <div class="space-y-sm">
        <template x-for="(line, i) in lines" :key="i">
            <template x-if="line.kind === @js($kind)">
                <div class="flex items-center gap-sm">
                    <input type="hidden" :name="`lines[${i}][kind]`" :value="line.kind">
                    <input type="text" :name="`lines[${i}][label]`" x-model="line.label" aria-label="Tên khoản" list="payslip-suggest-{{ $kind }}"
                           class="min-w-0 flex-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-xs font-body-medium text-body-medium">
                    <span class="relative w-40 shrink-0">
                        <input type="number" min="0" step="1000" :name="`lines[${i}][amount]`" x-model="line.amount" placeholder="0" aria-label="Số tiền"
                               class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-sm pr-lg text-right font-mono text-body-medium">
                        <span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant">đ</span>
                    </span>
                    <x-ui.button variant="danger-text" size="sm" icon="delete" aria-label="Xoá khoản" x-on:click="lines.splice(i, 1)" />
                </div>
            </template>
        </template>
        <div class="flex flex-wrap items-end gap-sm rounded-lg border border-dashed border-outline-variant p-sm"
             x-data="{ draft: { label: '', amount: '' } }">
            <div class="min-w-[180px] flex-1">
                <x-ui.input :label="$kind === 'earning' ? 'Tên khoản' : 'Lý do / Hạng mục'" id="payslip-draft-label-{{ $kind }}" x-model="draft.label" :placeholder="$placeholder" list="payslip-suggest-{{ $kind }}" />
            </div>
            <div class="w-40">
                <x-ui.input type="number" label="Số tiền (VNĐ)" id="payslip-draft-amount-{{ $kind }}" min="0" step="1000" x-model="draft.amount" placeholder="0" class="text-right font-mono" />
            </div>
            <x-ui.button variant="secondary" size="sm" icon="add"
                         x-on:click="if (draft.label.trim() !== '') { lines.push({ kind: @js($kind), label: draft.label.trim(), amount: draft.amount }); draft = { label: '', amount: '' } }">{{ $addLabel }}</x-ui.button>
        </div>
    </div>
@else
    @php $readLines = collect($record->manualLines($kind)); @endphp
    @if ($readLines->isNotEmpty())
        <ul class="space-y-xs font-body-medium text-body-medium">
            @foreach ($readLines as $line)
                <li class="flex justify-between"><span>{{ $line['label'] }}</span><span class="font-mono {{ $kind === 'deduction' ? 'text-error' : '' }}">{{ $kind === 'deduction' ? '-' : '+' }}{{ number_format($line['amount'], 0, ',', '.') }}</span></li>
            @endforeach
        </ul>
    @endif
@endif
