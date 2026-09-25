{{--
    Phiếu lương bản in / lưu PDF ("In phiếu lương"). Ẩn trên màn hình, chỉ hiện khi in: phần còn lại của trang bị ẩn
    bằng CSS in (không phụ thuộc layout). Dùng chung cho màn phiếu lương (Kế toán) và "Lương của tôi".
    Biến: $record (PayrollRecord), $period (PayrollPeriod), $variant (payslipVariant), $sessionCount (tuỳ chọn)
--}}
@php
    $pm = fn ($v) => number_format((float) $v, 0, ',', '.');
    $locked = $period->isLocked();
@endphp
<style>
    #payslip-print { display: none; }
    @media print {
        @page { size: A4 portrait; margin: 14mm; }
        body * { visibility: hidden !important; }
        #payslip-print, #payslip-print * { visibility: visible !important; }
        #payslip-print { display: block !important; position: absolute; inset: 0 auto auto 0; width: 100%; color: #000; background: #fff; font-size: 12px; }
        #payslip-print table { width: 100%; border-collapse: collapse; }
        #payslip-print th, #payslip-print td { border: 1px solid #999; padding: 4px 6px; vertical-align: top; }
        #payslip-print .num { text-align: right; font-family: 'JetBrains Mono', monospace; white-space: nowrap; }
        #payslip-print .muted { color: #555; font-size: 10px; }
        #payslip-print .sign td { border: none; text-align: center; height: 80px; }
    }
</style>
<section id="payslip-print" aria-hidden="true">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
        <div>
            <strong style="font-size:14px">{{ \App\Support\CenterInfo::name() }}</strong>
            @if (\App\Support\CenterInfo::phone())<div class="muted">ĐT: {{ \App\Support\CenterInfo::phone() }}</div>@endif
        </div>
        <div style="text-align:right" class="muted">In lúc {{ now()->format('H:i d/m/Y') }}</div>
    </div>
    <h1 style="text-align:center;font-size:18px;font-weight:700;margin:6px 0">PHIẾU LƯƠNG THÁNG {{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}/{{ $period->year }}</h1>
    <p style="text-align:center;margin:0 0 10px">{{ $variant['title'] }}@unless ($locked) — <strong>BẢN TẠM TÍNH (chưa duyệt)</strong>@endunless</p>

    <table style="margin-bottom:10px">
        <tr><td style="width:25%">Họ và tên</td><td><strong>{{ $record->user?->name }}</strong></td><td style="width:20%">Mã nhân viên</td><td>{{ $record->user?->employee_code ?: '—' }}</td></tr>
        <tr><td>Loại nhân sự</td><td>{{ $variant['type'] }}</td><td>Kỳ lương</td><td>{{ $period->start_date->format('d/m/Y') }} – {{ $period->end_date->format('d/m/Y') }}</td></tr>
        <tr><td>Chi nhánh</td><td>{{ $record->user?->branch?->name ?? '—' }}</td><td>Trạng thái</td><td>{{ $period->status_label }}</td></tr>
    </table>

    <table style="margin-bottom:10px">
        <thead><tr><th style="text-align:left">Khoản thu nhập</th><th class="num" style="width:28%">Số tiền (đ)</th></tr></thead>
        <tbody>
            @foreach ($record->earningLines() as $line)
                <tr><td>{{ $line['label'] }}@if ($line['hint'])<div class="muted">{{ $line['hint'] }}</div>@endif</td><td class="num">{{ $pm($line['amount']) }}</td></tr>
            @endforeach
            <tr><td><strong>Tổng thu nhập</strong></td><td class="num"><strong>{{ $pm($record->gross_income) }}</strong></td></tr>
        </tbody>
    </table>

    <table style="margin-bottom:10px">
        <thead><tr><th style="text-align:left">Khoản khấu trừ</th><th class="num" style="width:28%">Số tiền (đ)</th></tr></thead>
        <tbody>
            @foreach ($record->deductionLines() as $line)
                <tr><td>{{ $line['label'] }}@if ($line['hint'] ?? null)<div class="muted">{{ $line['hint'] }}</div>@endif</td><td class="num">-{{ $pm($line['amount']) }}</td></tr>
            @endforeach
            <tr><td><strong>Tổng khấu trừ</strong></td><td class="num"><strong>-{{ $pm($record->total_deductions) }}</strong></td></tr>
        </tbody>
    </table>

    <table style="margin-bottom:10px">
        <tr><td style="font-size:14px"><strong>THỰC NHẬN</strong></td><td class="num" style="width:28%;font-size:14px"><strong>{{ $pm($record->net_salary) }} đ</strong></td></tr>
    </table>
    @if ($record->adjustment_notes)
        <p class="muted">Ghi chú: {{ $record->adjustment_notes }}</p>
    @endif

    <table class="sign" style="margin-top:16px">
        <tr>
            <td><strong>Người lập</strong><div class="muted">(Kế toán)</div></td>
            <td><strong>Giám đốc duyệt</strong></td>
            <td><strong>Người nhận</strong><div class="muted">{{ $record->user?->name }}</div></td>
        </tr>
    </table>
</section>
