<script setup>
/**
 * Mẫu "Thông báo nộp học phí" (chuẩn A4) trong màn Chốt & Xếp lớp — xem trước và in (mẫu in: giữ màu / inline style gốc).
 * Nhận toàn bộ số liệu đang nhập ở wizard qua prop `bill`; thông tin trung tâm (tên, địa chỉ cơ sở, điện thoại) qua `center`.
 * Nút "In Ngay" của trang lấy innerHTML của #printableBillArea để mở cửa sổ in.
 */
import { formatMoney } from '@/lib/format';

defineProps({
    bill: { type: Object, required: true },
    center: { type: Object, required: true },
    dates: { type: Object, required: true },
});
const vnd = (value) => formatMoney(value || 0);
const cellLabel = 'border: 1px solid #d5d5d5; padding: 8px 10px; font-weight: 700; background: #fafafa;';
const cell = 'border: 1px solid #d5d5d5; padding: 8px 10px;';
</script>

<template>
    <div id="printableBillArea" class="bg-surface-container-lowest text-on-surface">
        <div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.4">
            <!-- HEADER -->
            <div style="display: flex; align-items: flex-start; margin-bottom: 20px">
                <div style="width: 65px; margin-right: 18px; flex-shrink: 0">
                    <div style="width: 60px; height: 60px; border-radius: 10px; background: linear-gradient(135deg, #ea580c, #c2410c); display: flex; align-items: center; justify-content: center; color: white; font-weight: 900; font-size: 24px">M</div>
                </div>
                <div>
                    <div style="font-size: 16px; font-weight: 700; margin-bottom: 4px; color: #c2410c">{{ center.name }}</div>
                    <div style="line-height: 1.5; font-size: 13px; color: #333">
                        <div v-for="(branch, index) in center.branches" :key="index">{{ index === 0 ? 'Địa chỉ: ' : '' }}{{ branch.name }}: {{ branch.address }}</div>
                        <div v-if="center.phone">Điện thoại: {{ center.phone }}</div>
                    </div>
                </div>
            </div>

            <!-- TITLE -->
            <div style="text-align: center; margin: 10px 0 16px 0">
                <h2 style="margin: 0; font-size: 21px; font-weight: 700; color: #111">THÔNG BÁO NỘP HỌC PHÍ</h2>
                <div style="margin-top: 4px; font-size: 13px; color: #666">Ngày {{ dates.day }} tháng {{ dates.month }} năm {{ dates.year }}</div>
            </div>

            <!-- INFORMATION TABLE -->
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px">
                <tr>
                    <td :style="cellLabel + ' width: 30%;'">Họ tên:</td>
                    <td :style="cell + ' width: 70%;'">
                        <strong>{{ bill.customerName }}</strong>
                        <strong style="margin-left: 20px; color: #ea580c">Mã: {{ bill.studentCode }}</strong>
                    </td>
                </tr>
                <tr>
                    <td :style="cellLabel">Lớp :</td>
                    <td :style="cell">{{ bill.className }}</td>
                </tr>
                <tr>
                    <td :style="cellLabel">Thời gian học:</td>
                    <td :style="cell">Từ ngày: {{ dates.from }} đến {{ dates.to }}</td>
                </tr>
                <tr>
                    <td :style="cellLabel">Ca học:</td>
                    <td :style="cell">24 buổi / khóa học</td>
                </tr>
                <tr>
                    <td :style="cellLabel">Học phí:</td>
                    <td :style="cell">{{ vnd(bill.baseTuition) }}</td>
                </tr>
                <tr>
                    <td :style="cellLabel">Ưu đãi:</td>
                    <td :style="cell + ' color: #16a34a; font-weight: bold;'">{{ vnd(bill.discount) }}</td>
                </tr>
                <tr>
                    <td :style="cellLabel">Thu khác:</td>
                    <td :style="cell">
                        <div style="font-weight: 700">{{ vnd(bill.otherFees) }}</div>
                        <div v-if="bill.feeItems.length" style="margin-top: 6px; padding: 6px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px">
                            <div style="font-weight: 700; color: #475569; margin-bottom: 2px; font-size: 11px; text-transform: uppercase">Chi tiết các khoản thu khác:</div>
                            <div v-for="(item, idx) in bill.feeItems" :key="idx" style="display: flex; justify-content: space-between; padding: 2px 0; color: #334155">
                                <span>• {{ item.name || 'Mục khác' }}</span>
                                <span style="font-family: monospace; font-weight: 600; color: #0284c7">{{ vnd(item.amount) }}</span>
                            </div>
                        </div>
                    </td>
                </tr>
                <tr v-if="bill.prepaidAmount > 0">
                    <td :style="cellLabel">Thu trước:</td>
                    <td :style="cell + ' color: #2563eb; font-weight: bold;'">- {{ vnd(bill.prepaidAmount) }}</td>
                </tr>
                <tr>
                    <td :style="cellLabel">Thành tiền:</td>
                    <td :style="cell + ' font-weight: 700; font-size: 15px; color: #ea580c;'">{{ vnd(bill.amountDue) }}</td>
                </tr>
                <tr>
                    <td :style="cellLabel">Ghi chú:</td>
                    <td :style="cell">{{ bill.notes || 'Học viên hoàn thành thủ tục nhập học theo quy định của trung tâm.' }}</td>
                </tr>
            </table>

            <!-- PAYMENT NOTE -->
            <div style="margin: 10px 0 8px 0; font-size: 13px; font-style: italic; color: #333">
                {{ bill.needsBankAccount ? 'Thông tin chuyển khoản của giao dịch:' : 'Thu tiền mặt tại quầy theo hóa đơn giấy' + (bill.paperInvoiceNumber ? ' số ' + bill.paperInvoiceNumber : '') + ', không phát sinh VietQR.' }}
            </div>

            <div v-show="bill.needsBankAccount">
                <!-- BANK TABLE -->
                <table style="width: 100%; border-collapse: collapse; margin-top: 6px">
                    <tr>
                        <td :style="cellLabel + ' width: 32%;'">Chủ tài khoản</td>
                        <td :style="cell + ' width: 25%; font-weight: 700; font-family: monospace;'">Số tài khoản</td>
                        <td :style="cell + ' width: 43%;'">Ngân hàng</td>
                    </tr>
                    <tr>
                        <td :style="cell">{{ bill.bank.account_holder }}</td>
                        <td :style="cell + ' font-family: monospace; font-weight: 700;'">{{ bill.bank.account_number }}</td>
                        <td :style="cell">{{ bill.bank.bank_name }}</td>
                    </tr>
                </table>

                <!-- TRANSFER CONTENT -->
                <div style="margin-top: 10px; font-size: 14px; padding: 8px 12px; background: #fff7ed; border: 1px solid #ffedd5; border-radius: 6px">
                    <strong>Nội dung chuyển tiền :</strong>
                    <strong style="color: #c2410c; margin-left: 6px">{{ bill.transferMemo }}</strong>
                </div>

                <!-- COMPANY NOTE -->
                <div style="margin-top: 10px; font-size: 13px; line-height: 1.4">
                    <strong>Ghi chú:</strong>
                    <div>Tk công ty. Quý phụ huynh vui lòng giữ nguyên nội dung chuyển tiền để hệ thống tự động ghi nhận gạch nợ.</div>
                </div>

                <!-- QR CODE -->
                <div style="margin-top: 20px; display: flex; align-items: center; gap: 16px; padding: 12px; border: 1px dashed #fdba74; border-radius: 10px; background: #fffaf5; width: fit-content">
                    <img v-if="bill.vietQrUrl" :src="bill.vietQrUrl" alt="Mã QR thanh toán" style="width: 150px; height: 150px; object-fit: contain; background: white; padding: 4px; border-radius: 6px" />
                    <div style="font-size: 12px; line-height: 1.5; color: #475569">
                        <h4 style="margin: 0 0 4px 0; color: #ea580c; font-size: 13px">Quét mã VietQR chuyển khoản nhanh 24/7</h4>
                        <div>Mở ứng dụng ngân hàng bất kỳ để quét mã.</div>
                        <div>Số tiền và nội dung đã được điền sẵn chính xác 100%.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
