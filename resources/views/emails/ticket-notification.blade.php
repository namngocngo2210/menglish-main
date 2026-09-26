@php
    $ticketCode = is_object($ticket ?? null) ? ($ticket->code ?? '') : ($ticket['code'] ?? '');
    $ticketTitle = is_object($ticket ?? null) ? ($ticket->title ?? '') : ($ticket['title'] ?? '');
    $statusKey = is_object($ticket ?? null) ? ($ticket->status ?? 'info') : ($ticket['status'] ?? 'info');
    $statusLabel = is_object($ticket ?? null) ? ($ticket->status_label ?? 'Thông báo') : ($ticket['status_label'] ?? 'Thông báo');
    $priorityKey = is_object($ticket ?? null) ? ($ticket->priority ?? 'medium') : ($ticket['priority'] ?? 'medium');
    $priorityLabel = $priorityLabel ?? (is_object($ticket ?? null) ? ($ticket->priority_label ?? '') : ($ticket['priority_label'] ?? ''));
    $categoryLabel = $categoryLabel ?? (is_object($ticket ?? null) ? ($ticket->category_label ?? '') : ($ticket['category_label'] ?? ''));

    // Đảm bảo số tiền trong nội dung mail luôn dùng dấu chấm ngăn cách giá trị (chuẩn Việt Nam: 13.500.000)
    $formatMoneyDots = function($text) {
        if (!is_string($text)) return $text;
        return preg_replace_callback('/\b(\d{1,3}(?:,\d{3})+)\b/', function($matches) {
            return str_replace(',', '.', $matches[1]);
        }, $text);
    };

    $renderedTitle = $formatMoneyDots($subjectTitle ?? ($ticketCode ? "[#{$ticketCode}] {$ticketTitle}" : 'Thông báo hệ thống'));
    $renderedContent = $formatMoneyDots($content ?? '');
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $renderedTitle }}</title>
</head>
<body style="margin: 0; padding: 24px 12px; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; margin: 0 auto;">
        <tr>
            <td>
                {{-- Main Clean Card --}}
                <div style="background-color: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05); padding: 26px 24px;">
                    
                    {{-- 1. Tiêu đề là tiêu đề mail --}}
                    <h1 style="margin: 0 0 16px 0; font-size: 19px; font-weight: 800; color: #0f172a; line-height: 1.4; letter-spacing: -0.3px;">
                        {{ $renderedTitle }}
                    </h1>

                    {{-- 2. Nội dung trực tiếp (không rườm rà) --}}
                    <div style="font-size: 15px; color: #334155; line-height: 1.65; white-space: pre-wrap; margin-bottom: 22px;">@if(($type ?? '') === 'reply' && !empty($senderName))<strong style="color: #0f172a;">{{ $senderName }}:</strong> {{ $renderedContent }}@else{{ $renderedContent }}@endif</div>

                    {{-- Phân cách --}}
                    <div style="border-top: 1px solid #e2e8f0; margin: 22px 0 18px 0;"></div>

                    {{-- 3. Trạng thái, thông tin & hành động nằm ở DƯỚI --}}
                    <div style="background-color: #f8fafc; border-radius: 12px; padding: 16px; border: 1px solid #f1f5f9;">
                        
                        {{-- Badges trạng thái, mức độ ưu tiên, danh mục --}}
                        <div style="margin-bottom: 12px;">
                            {{-- Trạng thái --}}
                            <span style="display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 6px; margin-right: 6px;
                                @if(in_array($statusKey, ['resolved', 'approved', 'completed', 'success']))
                                    background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;
                                @elseif(in_array($statusKey, ['in_progress', 'processing']))
                                    background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;
                                @elseif(in_array($statusKey, ['closed', 'rejected', 'canceled']))
                                    background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;
                                @elseif(in_array($statusKey, ['overdue', 'urgent', 'danger']))
                                    background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;
                                @else
                                    background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a;
                                @endif
                            ">
                                ● {{ $statusLabel }}
                            </span>

                            {{-- Mức độ ưu tiên --}}
                            @if(!empty($priorityLabel))
                                <span style="display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 6px; margin-right: 6px; text-transform: uppercase;
                                    @if(str_contains(strtolower($priorityKey), 'urgent') || str_contains(strtolower($priorityKey), 'overdue'))
                                        background-color: #ffe4e6; color: #e11d48; border: 1px solid #fecdd3;
                                    @elseif(str_contains(strtolower($priorityKey), 'high'))
                                        background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a;
                                    @elseif(str_contains(strtolower($priorityKey), 'medium'))
                                        background-color: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd;
                                    @else
                                        background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;
                                    @endif
                                ">
                                    ⚡ {{ $priorityLabel }}
                                </span>
                            @endif

                            {{-- Danh mục --}}
                            @if(!empty($categoryLabel))
                                <span style="display: inline-block; font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 6px; background-color: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff;">
                                    📁 {{ $categoryLabel }}
                                </span>
                            @endif
                        </div>

                        {{-- Người gửi & Thời gian --}}
                        <div style="font-size: 12px; color: #64748b; margin-bottom: 14px; line-height: 1.5;">
                            👤 <strong>Người gửi:</strong> {{ $senderName ?? 'Hệ thống' }} &bull; 🕒 {{ now()->format('H:i d/m/Y') }}
                        </div>

                        {{-- Nút xem & xử lý --}}
                        <div>
                            <a href="{{ $actionUrl ?? url('/') }}" style="display: inline-block; background-color: #ea580c; color: #ffffff; text-decoration: none; padding: 9px 20px; border-radius: 8px; font-weight: 700; font-size: 13px;">
                                👉 {{ $actionText ?? ($ticketCode ? "Xem Ticket #{$ticketCode}" : "Xem chi tiết") }}
                            </a>
                        </div>
                    </div>

                </div>
            </td>
        </tr>
    </table>
</body>
</html>
