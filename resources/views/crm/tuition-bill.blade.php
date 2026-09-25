<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông báo nộp học phí - {{ $student->name ?? 'Học viên' }} ({{ $student->code ?? '' }})</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px;
            background: #f3f3f3;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            font-size: 15px;
        }

        .action-bar {
            max-width: 1200px;
            margin: 0 auto 16px auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: background 0.2s;
        }

        .btn-primary {
            background: #ea580c;
            color: #fff;
        }
        .btn-primary:hover {
            background: #c2410c;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #1e293b;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .invoice {
            width: 100%;
            max-width: 1200px;
            min-height: 1400px;
            margin: 0 auto;
            padding: 25px 30px 50px;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        /* =========================
           HEADER
        ========================== */
        .header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 25px;
        }

        .logo {
            width: 75px;
            margin-right: 18px;
            flex-shrink: 0;
        }

        .logo img, .logo svg {
            display: block;
            width: 68px;
            height: 68px;
            object-fit: contain;
        }

        .company {
            padding-top: 0;
        }

        .company-name {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 5px;
            color: #c2410c;
            letter-spacing: 0.5px;
        }

        .company-info {
            line-height: 1.55;
            font-size: 14px;
            color: #333;
        }

        /* =========================
           TITLE
        ========================== */
        .document-title {
            text-align: center;
            margin-top: 5px;
            margin-bottom: 18px;
        }

        .document-title h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #111;
        }

        .document-date {
            margin-top: 6px;
            font-size: 14px;
            color: #555;
        }

        /* =========================
           INFORMATION TABLE
        ========================== */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table {
            margin-top: 12px;
        }

        .info-table td {
            border: 1px solid #d5d5d5;
            padding: 10px 12px;
            height: 40px;
            vertical-align: middle;
        }

        .info-table .label {
            width: 30%;
            font-weight: 700;
            background: #fafafa;
        }

        .info-table .value {
            width: 70%;
        }

        .info-table .amount {
            font-weight: 700;
            color: #ea580c;
            font-size: 16px;
        }

        /* =========================
           NOTE
        ========================== */
        .payment-note {
            margin: 12px 0 10px;
            font-size: 14px;
            font-style: italic;
            color: #333;
        }

        /* =========================
           BANK TABLE
        ========================== */
        .bank-table {
            margin-top: 6px;
        }

        .bank-table td {
            border: 1px solid #d5d5d5;
            padding: 12px 10px;
            height: 48px;
            vertical-align: middle;
        }

        .bank-table .bank-label {
            width: 32%;
            font-weight: 700;
            background: #fafafa;
        }

        .bank-table .account-number {
            width: 22%;
            font-weight: 700;
            font-family: monospace;
            font-size: 16px;
        }

        .bank-table .bank-name {
            width: 46%;
        }

        /* =========================
           TRANSFER CONTENT
        ========================== */
        .transfer-content {
            margin-top: 12px;
            font-size: 15px;
            padding: 10px 14px;
            background: #fff7ed;
            border: 1px solid #ffedd5;
            border-radius: 6px;
        }

        .transfer-content strong {
            font-weight: 700;
            color: #c2410c;
            font-family: monospace;
            font-size: 16px;
        }

        .company-note {
            margin-top: 14px;
            line-height: 1.5;
            font-size: 14px;
        }

        .company-note strong {
            display: block;
            margin-bottom: 3px;
        }

        /* =========================
           QR
        ========================== */
        .qr-wrapper {
            margin-top: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 15px;
            border: 1px dashed #fdba74;
            border-radius: 12px;
            background: #fffaf5;
            width: fit-content;
        }

        .qr-wrapper img {
            width: 180px;
            height: 180px;
            object-fit: contain;
            border-radius: 8px;
            background: #fff;
            padding: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .qr-desc {
            font-size: 13px;
            line-height: 1.5;
            color: #475569;
        }

        .qr-desc h4 {
            margin: 0 0 6px 0;
            color: #ea580c;
            font-size: 14px;
        }

        /* =========================
           PRINT
        ========================== */
        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .action-bar {
                display: none !important;
            }

            .invoice {
                width: 100%;
                max-width: none;
                min-height: auto;
                padding: 10px 20px;
                box-shadow: none;
            }

            @page {
                size: A4 portrait;
                margin: 8mm 10mm;
            }
        }

        /* =========================
           RESPONSIVE
        ========================== */
        @media (max-width: 700px) {
            body {
                padding: 10px;
            }

            .invoice {
                padding: 20px 15px 40px;
            }

            .header {
                flex-direction: row;
            }

            .company-name {
                font-size: 16px;
            }

            .company-info {
                font-size: 12px;
            }

            .document-title h1 {
                font-size: 19px;
            }

            .info-table .label,
            .bank-table .bank-label {
                width: 35%;
            }

            .info-table td,
            .bank-table td {
                padding: 8px 6px;
                font-size: 13px;
            }

            .qr-wrapper {
                flex-direction: column;
                align-items: flex-start;
            }

            .qr-wrapper img {
                width: 150px;
                height: 150px;
            }
        }
    </style>
</head>

<body>

    <!-- TOP ACTION BAR -->
    <div class="action-bar">
        @can('lead.view')
        <a href="{{ route('crm.pipeline') }}" class="btn btn-secondary">
            &larr; Quay lại CRM Pipeline
        </a>
        @elsecan('tuition.view')
        <a href="{{ route('tuition.history') }}" class="btn btn-secondary">
            &larr; Quay lại Lịch sử thu phí
        </a>
        @endcan
        <div style="display: flex; gap: 8px;">
            <button onclick="window.print()" class="btn btn-primary">
                🖨️ In Thông Báo (Print A4)
            </button>
            @if($vietQrUrl)
            <a href="{{ $vietQrUrl }}" download="VietQR_{{ $student?->code ?? 'HocVien' }}.png" target="_blank" class="btn btn-secondary">
                📥 Tải Mã QR
            </a>
            @endif
        </div>
    </div>

    <div class="invoice">

        <!-- HEADER -->
        <div class="header">
            <div class="logo">
                <img src="{{ asset('images/menglish-logo.png') }}" alt="MENGLISH Logo" style="width: 75px; height: 75px; object-fit: contain;">
            </div>

            <div class="company">
                <div class="company-name">
                    MENGLISH - MEDUCATION
                </div>

                <div class="company-info">
                    <div>Địa chỉ: CS1: 15/172 Phố Ngọc Hà - Ba Đình</div>
                    <div>CS2: 23/209 Phố Đội Cấn - Ba Đình</div>
                    <div>CS3: 24/55 Hoàng Hoa Thám - Ba Đình</div>
                    <div>Điện thoại: 0975996986</div>
                </div>
            </div>
        </div>

        <!-- TITLE -->
        <div class="document-title">
            <h1>THÔNG BÁO NỘP HỌC PHÍ</h1>
            <div class="document-date">
                Ngày {{ date('d') }} tháng {{ date('m') }} năm {{ date('Y') }}
            </div>
        </div>

        <!-- STUDENT INFORMATION -->
        <table class="info-table">
            <tr>
                <td class="label">Họ tên:</td>
                <td class="value">
                    <strong>{{ $student?->name ?? '—' }}</strong>
                    <strong style="margin-left: 25px; color: #ea580c;">
                        Mã: {{ $student?->code ?? '—' }}
                    </strong>
                    @if(!empty($customer?->parent_name))
                        <span style="margin-left: 20px; color: #64748b;">(PH: {{ $customer->parent_name }})</span>
                    @endif
                </td>
            </tr>

            <tr>
                <td class="label">Lớp :</td>
                <td class="value">
                    <strong>{{ $class->name ?? 'Lớp học tiêu chuẩn' }}</strong>
                    @if(!empty($class->schedule_text))
                        <span> · {{ $class->schedule_text }}</span>
                    @endif
                </td>
            </tr>

            <tr>
                <td class="label">Thời gian học:</td>
                <td class="value">
                    Từ ngày: {{ $class->start_date ? \Carbon\Carbon::parse($class->start_date)->format('d/m/Y') : date('01/m/Y') }} 
                    đến {{ $class->end_date ? \Carbon\Carbon::parse($class->end_date)->format('d/m/Y') : \Carbon\Carbon::now()->addMonths(3)->format('d/m/Y') }}
                </td>
            </tr>

            <tr>
                <td class="label">Ca học:</td>
                <td class="value">
                    {{ $totalSessions ?? 24 }} buổi / khóa học
                </td>
            </tr>

            <tr>
                <td class="label">Học phí:</td>
                <td class="value">
                    {{ number_format($tuition->total_amount) }} VNĐ
                </td>
            </tr>

            <tr>
                <td class="label">Ưu đãi:</td>
                <td class="value" style="{{ $tuition->discount_amount > 0 ? 'color: #16a34a; font-weight: bold;' : '' }}">
                    {{ number_format($tuition->discount_amount) }} VNĐ
                    @if($promotion)
                        <span style="font-size: 12px; font-weight: normal; color: #475569;">({{ $promotion->name }})</span>
                    @endif
                </td>
            </tr>

            <tr>
                <td class="label">Thu khác:</td>
                <td class="value">
                    <div><strong>{{ number_format($tuition->other_fees ?? 0) }} VNĐ</strong></div>
                    @if(!empty($tuition->fee_items) && is_array($tuition->fee_items) && count($tuition->fee_items) > 0)
                        <div style="margin-top: 6px; padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px;">
                            <div style="font-weight: 700; color: #475569; margin-bottom: 4px; font-size: 11px; text-transform: uppercase;">Chi tiết các khoản thu khác:</div>
                            <table style="width: 100%; border-collapse: collapse;">
                                @foreach($tuition->fee_items as $item)
                                    <tr>
                                        <td style="border: none; padding: 2px 0; color: #334155; font-size: 13px;">• {{ $item['name'] ?? 'Mục khác' }}</td>
                                        <td style="border: none; padding: 2px 0; text-align: right; font-family: monospace; font-weight: 700; color: #0284c7; font-size: 13px;">{{ number_format($item['amount'] ?? 0) }} VNĐ</td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    @endif
                </td>
            </tr>

            @if(!empty($tuition->prepaid_amount) && $tuition->prepaid_amount > 0)
            <tr>
                <td class="label">Thu trước (Đã cọc/đóng trước):</td>
                <td class="value" style="color: #2563eb; font-weight: bold;">
                    - {{ number_format($tuition->prepaid_amount) }} VNĐ
                </td>
            </tr>
            @endif

            <tr>
                <td class="label">Tổng giá trị hợp đồng:</td>
                <td class="value amount">
                    {{ number_format($tuition->final_amount) }} VNĐ
                </td>
            </tr>

            <tr>
                <td class="label">Đã thanh toán (Các đợt):</td>
                <td class="value" style="color: #16a34a; font-weight: 700; font-size: 15px;">
                    {{ number_format($tuition->paid_amount) }} VNĐ
                </td>
            </tr>

            <tr>
                <td class="label">Còn lại cần nộp:</td>
                <td class="value" style="{{ $amountToPay > 0 ? 'color: #ea580c; font-weight: 800; font-size: 16px;' : 'color: #16a34a; font-weight: 700;' }}">
                    @if($amountToPay > 0)
                        {{ number_format($amountToPay) }} VNĐ <span style="font-size: 12px; font-weight: normal; color: #b45309;">(Chưa gồm khoản chờ đối soát)</span>
                    @elseif($pendingAmount > 0)
                        0 VNĐ <span style="font-size: 12px; font-weight: bold; color: #b45309; margin-left: 6px;">⏳ Đang chờ đối soát</span>
                    @else
                        0 VNĐ <span style="font-size: 12px; font-weight: bold; color: #16a34a; margin-left: 6px;">✓ Đã hoàn tất thanh toán</span>
                    @endif
                </td>
            </tr>

            @if($pendingAmount > 0)
            <tr>
                <td class="label">Khoản thu chờ duyệt:</td>
                <td class="value" style="color: #b45309; font-weight: 700;">{{ number_format($pendingAmount) }} VNĐ</td>
            </tr>
            @endif

            <tr>
                <td class="label">Ghi chú:</td>
                <td class="value">
                    {{ $tuition->notes ?? 'Học viên hoàn thành thủ tục nhập học theo quy định của trung tâm.' }}
                </td>
            </tr>
        </table>

        <!-- INSTALLMENTS & PAYMENT HISTORY (LỊCH SỬ THANH TOÁN CÁC ĐỢT) -->
        <div style="margin-top: 24px;">
            <div style="font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
                <span>LỊCH SỬ THANH TOÁN CÁC ĐỢT (TIẾN ĐỘ THU TIỀN)</span>
                @if(($tuition->debt_amount ?? 0) <= 0 && ($tuition->paid_amount ?? 0) > 0)
                    <span style="font-size: 12px; font-weight: 700; padding: 3px 10px; background: #dcfce7; color: #15803d; border-radius: 9999px; border: 1px solid #bbf7d0;">
                        ✓ ĐÃ HOÀN TẤT HỌC PHÍ (100%)
                    </span>
                @elseif($amountToPay > 0)
                    <span style="font-size: 12px; font-weight: 700; padding: 3px 10px; background: #fef3c7; color: #b45309; border-radius: 9999px; border: 1px solid #fde68a;">
                        ⏳ CÒN NỢ ĐỢT TIẾP THEO: {{ number_format($amountToPay) }} VNĐ
                    </span>
                @elseif($pendingAmount > 0)
                    <span style="font-size: 12px; font-weight: 700; padding: 3px 10px; background: #fef3c7; color: #b45309; border-radius: 9999px; border: 1px solid #fde68a;">⏳ CHỜ KẾ TOÁN/ADMIN ĐỐI SOÁT</span>
                @endif
            </div>

            <table style="width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 13px;">
                <thead>
                    <tr style="background: #f8fafc; color: #475569; font-weight: 700; text-align: left;">
                        <th style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 12%;">Đợt thu</th>
                        <th style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 16%;">Số phiếu / HĐĐT</th>
                        <th style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 12%;">Ngày thu</th>
                        <th style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 18%;">Hình thức</th>
                        <th style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 24%;">Nội dung / Khoản thu</th>
                        <th style="border: 1px solid #d5d5d5; padding: 8px 10px; width: 18%; text-align: right;">Số tiền</th>
                    </tr>
                </thead>
                <tbody>
                    @php $installmentIndex = 1; @endphp
                    @forelse($tuition->receipts as $receipt)
                        <tr>
                            <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; color: #ea580c;">
                                Đợt {{ $installmentIndex++ }}
                            </td>
                            <td style="border: 1px solid #d5d5d5; padding: 8px 10px; font-family: monospace;">
                                <strong>{{ $receipt->receipt_number }}</strong>
                                @if($receipt->invoice_number)
                                    <div style="font-size: 11px; color: #64748b;">HĐ: {{ $receipt->invoice_number }}</div>
                                @elseif($receipt->status === 'pending')
                                    <div style="font-size: 11px; color: #b45309;">Chờ đối soát</div>
                                @endif
                            </td>
                            <td style="border: 1px solid #d5d5d5; padding: 8px 10px;">
                                {{ $receipt->payment_date ? \Carbon\Carbon::parse($receipt->payment_date)->format('d/m/Y') : $receipt->created_at->format('d/m/Y') }}
                            </td>
                            <td style="border: 1px solid #d5d5d5; padding: 8px 10px;">
                                @if($receipt->payment_method === 'transfer')
                                    Chuyển khoản VietQR
                                @elseif($receipt->payment_method === 'cash')
                                    Tiền mặt tại quầy
                                @elseif($receipt->payment_method === 'pos')
                                    Quẹt thẻ POS
                                @elseif($receipt->payment_method === 'split')
                                    Kết hợp (TM + CK)
                                @else
                                    {{ ucfirst($receipt->payment_method) }}
                                @endif
                            </td>
                            <td style="border: 1px solid #d5d5d5; padding: 8px 10px; color: #334155;">
                                <div>{{ $receipt->notes ?: 'Thanh toán học phí đợt ' . ($installmentIndex - 1) }}</div>
                                @if(!empty($receipt->collected_items) && is_array($receipt->collected_items))
                                    <div style="margin-top: 3px; font-size: 11px; color: #0284c7;">
                                        Bao gồm: 
                                        @foreach($receipt->collected_items as $cItem)
                                            <span style="display: inline-block; background: #e0f2fe; padding: 1px 6px; border-radius: 4px; margin: 1px;">{{ $cItem['name'] ?? '' }} ({{ number_format($cItem['amount'] ?? 0) }}đ)</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td style="border: 1px solid #d5d5d5; padding: 8px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #16a34a;">
                                {{ number_format($receipt->amount) }} VNĐ
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="border: 1px solid #d5d5d5; padding: 12px; text-align: center; color: #94a3b8; font-style: italic;">
                                Chưa có giao dịch thanh toán nào được ghi nhận. Học viên cần nộp đợt 1.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr style="background: #fafafa; font-weight: 700;">
                        <td colspan="5" style="border: 1px solid #d5d5d5; padding: 8px 10px; text-align: right;">
                            Tổng cộng đã thanh toán:
                        </td>
                        <td style="border: 1px solid #d5d5d5; padding: 8px 10px; text-align: right; font-family: monospace; color: #16a34a; font-size: 14px;">
                            {{ number_format($tuition->paid_amount) }} VNĐ
                        </td>
                    </tr>
                    @if($amountToPay > 0)
                        <tr style="background: #fff7ed; font-weight: 700;">
                            <td colspan="5" style="border: 1px solid #d5d5d5; padding: 8px 10px; text-align: right; color: #c2410c;">
                                Số tiền còn lại cần nộp (Công nợ các đợt tiếp theo):
                            </td>
                            <td style="border: 1px solid #d5d5d5; padding: 8px 10px; text-align: right; font-family: monospace; color: #ea580c; font-size: 15px;">
                                {{ number_format($amountToPay) }} VNĐ
                            </td>
                        </tr>
                    @endif
                </tfoot>
            </table>
        </div>

        @if($amountToPay > 0 && $bankAccount)
        <!-- PAYMENT NOTE -->
        <div class="payment-note">
            Quý phụ huynh có thể nộp tiền mặt tại Trung tâm hoặc chuyển khoản theo thông tin sau:
        </div>

        <!-- BANK INFORMATION -->
        <table class="bank-table">
            <tr>
                <td class="bank-label">Chủ tài khoản</td>
                <td class="account-number">Số tài khoản</td>
                <td class="bank-name">Ngân hàng</td>
            </tr>
            <tr>
                <td>{{ $bankAccount->account_holder }}</td>
                <td class="account-number">{{ $bankAccount->account_number }}</td>
                <td>{{ $bankAccount->bank_name }}</td>
            </tr>
        </table>

        <!-- TRANSFER CONTENT -->
        <div class="transfer-content">
            <strong>Nội dung chuyển tiền :</strong>
            <span>{{ $transferMemo }}</span>
        </div>

        <!-- COMPANY NOTE -->
        <div class="company-note">
            <strong>Ghi chú:</strong>
            <div>Tk công ty. Quý phụ huynh vui lòng giữ nguyên nội dung chuyển khoản để hệ thống tự động gạch nợ nhanh nhất.</div>
        </div>

        <!-- QR CODE -->
        <div class="qr-wrapper">
            <img src="{{ $vietQrUrl }}" alt="QR thanh toán VietQR">
            <div class="qr-desc">
                <h4>Quét mã VietQR thanh toán nhanh 24/7 (Đợt tiếp theo)</h4>
                <div>Mở ứng dụng ngân hàng bất kỳ để quét mã.</div>
                <div>Số tiền còn lại <strong>{{ number_format($amountToPay) }}đ</strong> và nội dung <strong>{{ $transferMemo }}</strong> đã được tích hợp tự động.</div>
            </div>
        </div>
        @else
            @if(!empty($qrWarning))
            <div class="company-note" style="border-color: #fca5a5; background: #fef2f2; color: #991b1b;">
                <strong>⚠️ Chưa cấu hình tài khoản nhận tiền.</strong>
                <div>{{ $qrWarning }}</div>
            </div>
            @else
            <div class="company-note" style="border-color: #86efac; background: #f0fdf4; color: #166534;">
                <strong>{{ $pendingAmount > 0 ? '⏳ Khoản thu đang chờ đối soát.' : '✓ Học viên đã hoàn tất học phí.' }}</strong>
                <div>Bill này không phát sinh mã thanh toán mới.</div>
            </div>
            @endif
        @endif

    </div>

</body>
</html>
