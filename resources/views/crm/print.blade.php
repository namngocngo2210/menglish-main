<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hồ sơ khách hàng {{ $customer->code }} — {{ $customer->name }}</title>
    {{-- Bản in độc lập (không phụ thuộc bundle CSS) — khổ A4. --}}
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Be Vietnam Pro', 'Segoe UI', Arial, sans-serif; color: #161c27; font-size: 12px; line-height: 1.5; margin: 0; background: #fff; }
        .page { max-width: 780px; margin: 0 auto; padding: 16px; }
        header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #a23f00; padding-bottom: 8px; margin-bottom: 12px; }
        h1 { font-size: 18px; margin: 0; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #a23f00; margin: 16px 0 6px; border-bottom: 1px solid #e1bfb2; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 6px; vertical-align: top; text-align: left; }
        .info td:first-child { color: #594137; width: 32%; }
        .grid td { border: 1px solid #e8eeff; }
        .muted { color: #594137; }
        .log th { background: #f0f4ff; border: 1px solid #e8eeff; font-size: 11px; }
        .log td { border: 1px solid #e8eeff; font-size: 11px; white-space: pre-line; }
        .actions { margin: 12px 0; text-align: right; }
        .actions button { padding: 6px 14px; border-radius: 8px; border: 1px solid #a23f00; background: #f5691a; color: #fff; font-weight: 600; cursor: pointer; }
        @media print { .actions { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
<div class="page">
    <div class="actions"><button type="button" onclick="window.print()">In hồ sơ</button></div>
    <header>
        <div>
            <h1>HỒ SƠ KHÁCH HÀNG TUYỂN SINH</h1>
            <div class="muted">MEnglish · {{ $customer->branch?->name ?? 'Chưa gán cơ sở' }}</div>
        </div>
        <div style="text-align:right">
            <strong>{{ $customer->code }}</strong><br>
            <span class="muted">In lúc {{ now()->format('d/m/Y H:i') }}</span>
        </div>
    </header>

    <h2>Thông tin khách hàng</h2>
    <table class="info">
        <tr><td>Họ tên</td><td><strong>{{ $customer->name }}</strong></td></tr>
        <tr><td>Số điện thoại</td><td>{{ $customer->phone }}</td></tr>
        <tr><td>Phụ huynh</td><td>{{ $customer->parent_name ?: '—' }}{{ $customer->parent_phone ? ' · '.$customer->parent_phone : '' }}</td></tr>
        <tr><td>Email</td><td>{{ $customer->email ?: '—' }}</td></tr>
        <tr><td>Ngày sinh / Giới tính</td><td>{{ $customer->dob?->format('d/m/Y') ?? '—' }} / {{ $customer->gender ?: '—' }}</td></tr>
        <tr><td>Địa chỉ</td><td>{{ $customer->address ?: '—' }}</td></tr>
        <tr><td>Nguồn</td><td>{{ $customer->source ?: '—' }}</td></tr>
        <tr><td>Khóa học quan tâm</td><td>{{ $customer->course_interest ?: '—' }}</td></tr>
        <tr><td>Giai đoạn</td><td>{{ $customer->stage_label }}{{ $customer->stage === 'lost' && $customer->lost_reason ? ' — '.$customer->lost_reason : '' }}</td></tr>
        <tr><td>Sales phụ trách</td><td>{{ $customer->assignedUser?->name ?? 'Chưa phân công' }}</td></tr>
        <tr><td>Giá trị hợp đồng</td><td>{{ number_format((float) $customer->deal_value, 0, ',', '.') }}đ</td></tr>
        @if ($customer->convertedStudent)
            <tr><td>Hồ sơ học viên</td><td>{{ $customer->convertedStudent->code }} · Lớp: {{ $customer->convertedStudent->currentClass?->name ?? 'Chờ xếp lớp' }}</td></tr>
        @endif
        @if ($customer->notes)
            <tr><td>Ghi chú nhu cầu</td><td>{{ $customer->notes }}</td></tr>
        @endif
    </table>

    <h2>Kết quả test đầu vào</h2>
    @if ($latestSubmission)
        @php($fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.'))
        <table class="grid">
            @if ($rubric && ! $rubric['legacy'])
                <tr>
                    <td>Đề: <strong>{{ $latestSubmission->test?->title ?? '—' }}</strong></td>
                    <td>Khối: {{ $rubric['grade_group_label'] }}</td>
                    <td>Nghe: {{ $fmt($latestSubmission->listening_score) }}/{{ $rubric['max']['listening'] }}</td>
                    <td>Đọc &amp; Viết: {{ $fmt($latestSubmission->reading_writing_score) }}/{{ $rubric['max']['reading_writing'] }}</td>
                    <td>Nói: {{ $fmt($latestSubmission->speaking_score) }}/{{ $rubric['max']['speaking'] }}</td>
                    <td>Tổng: <strong>{{ $fmt($rubric['total']) }}/{{ $rubric['max_total'] }}</strong></td>
                </tr>
                <tr>
                    <td colspan="3">Lớp đề xuất theo thang điểm: {{ $rubric['suggested_class'] ?? \App\Services\PlacementRubricService::noRubricNotice() }}</td>
                    <td colspan="3">Lớp xếp: <strong>{{ $rubric['chosen_class'] ?? '—' }}</strong></td>
                </tr>
                @foreach (\App\Services\PlacementRubricService::SKILLS as $skill => $label)
                    @if (filled($rubric['comments'][$skill]))
                        <tr><td colspan="6"><strong>{{ $label }}:</strong> {{ $rubric['comments'][$skill] }}</td></tr>
                    @endif
                @endforeach
            @else
                <tr>
                    <td>Đề: <strong>{{ $latestSubmission->test?->title ?? '—' }}</strong></td>
                    <td>Nghe: {{ $fmt($latestSubmission->listening_score) }}</td>
                    <td>Đọc: {{ $fmt($latestSubmission->reading_score) }}</td>
                    <td>Viết: {{ $fmt($latestSubmission->writing_score) }}</td>
                    <td>Nói: {{ $fmt($latestSubmission->speaking_score) }}</td>
                    <td>Tổng: <strong>{{ $latestSubmission->overall_score ?? '—' }}</strong></td>
                </tr>
            @endif
        </table>
    @else
        <p class="muted">Chưa có kết quả test.</p>
    @endif

    <h2>Nhật ký chăm sóc ({{ $customer->histories->count() }})</h2>
    <table class="log">
        <thead><tr><th style="width:110px">Thời gian</th><th style="width:120px">Người thực hiện</th><th>Nội dung</th></tr></thead>
        <tbody>
            @forelse ($customer->histories as $history)
                <tr>
                    <td>{{ $history->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $history->user?->name ?? 'Hệ thống' }}</td>
                    <td>{{ $history->content }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">Chưa có nhật ký.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
