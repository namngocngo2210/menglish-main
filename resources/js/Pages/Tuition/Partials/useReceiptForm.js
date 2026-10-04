/**
 * Trạng thái form Lập / Sửa phiếu thu học phí (chuyển từ Alpine createReceiptManager).
 * Tham số: danh sách khoản học phí, học viên, id chọn sẵn, TK ngân hàng mặc định, phiếu đang sửa (props của Tuition/ReceiptForm).
 * Nội dung CK và mã VietQR giữ nguyên cách tính cũ (nội dung CK do server sinh theo App\Support\TransferMemo).
 * Giảm trừ: chọn ưu đãi có sẵn (số tiền giảm tự tính, server tính lại) hoặc nhập tay kèm lý do (ca đặc biệt).
 * Tiền phiếu tính theo sổ buổi (quote của khoản học phí / học viên, xem App\Services\Tuition\SessionLedger):
 * số buổi thu × đơn giá + học liệu + thi + khác − giảm + phụ thu — công thức ở @/lib/sessionReceipt.
 */
import { computed, reactive, watch } from 'vue';
import { promotionApplies, promotionDiscount } from '@/lib/promotion';
import { calculateSessionReceipt, sessionValue } from '@/lib/sessionReceipt';

export function useReceiptForm({
    tuitions = [],
    students = [],
    promotions = [],
    initialTuitionId = '',
    initialStudentId = '',
    defaultBank = null,
    editing = null,
    merchandiseItems = [],
    stockByBranch = {},
    paperInvoiceNext = {},
}) {
    const itemById = (id) => merchandiseItems.find((m) => String(m.id) === String(id)) || null;
    // Hàng hóa ở phần Phụ thu (sách, đồng phục...): giá theo danh mục, xuất kho chi nhánh khi phiếu được duyệt.
    const initialLines = (editing?.surcharge_items || []).filter((line) => itemById(line.id)).map((line) => ({ id: line.id, quantity: line.quantity }));
    const linesTotal = (lines) => lines.reduce((sum, line) => sum + (itemById(line.id)?.price || 0) * (parseInt(line.quantity) || 0), 0);
    // Phiếu lập theo sổ buổi đang sửa: số buổi, thi, khác, phụ thu đã nhập. Phiếu cũ (theo số tiền): phụ thu khác = phụ thu − hàng hóa.
    const editingSession = editing?.session || null;

    const state = reactive({
        selectedTuitionId: initialTuitionId || '',
        selectedStudentId: initialStudentId || '',
        currentTuition: null,
        currentStudent: null,

        skipTuition: false,
        discountAmount: editing && !editing.promotion_id ? editing.discount_amount : 0,
        promotionId: editing?.promotion_id ? String(editing.promotion_id) : '',
        discountReason: editing?.discount_reason || '',
        // Số buổi thu đợt này: mặc định = Buổi cần thu của sổ buổi (được thu ít hơn để đóng từng phần).
        sessionCount: editingSession ? editingSession.session_count : null,
        examFee: editingSession ? editingSession.exam_fee : 0,
        otherFee: editingSession ? editingSession.other_fee : 0,
        otherFeeReason: editingSession?.other_fee_reason || '',
        surchargeLines: initialLines,
        // Phụ thu (ngoài học phí, học liệu, thi, khác) — bắt buộc lý do.
        surchargeAmount: editingSession ? editingSession.extra : editing ? Math.max(0, editing.surcharge_amount - linesTotal(initialLines)) : 0,
        surchargeReason: editing ? editing.surcharge_reason || '' : '',
        paymentMethod: editing ? editing.payment_method : 'transfer',
        transactionCode: editing ? editing.transaction_code || '' : '',
        payerName: '',
        payerPhone: '',

        proofPreviewUrl: editing && editing.proof_image ? editing.proof_image : null,
        proofFileName: editing && editing.proof_image ? editing.proof_image.split('/').pop() : '',
        proofFileSize: '',
        proofIsPdf: !!(editing && editing.proof_image && editing.proof_image.toLowerCase().endsWith('.pdf')),
        proofRemoved: false,
    });

    // Ưu đãi áp dụng được cho khoản học phí (ưu đãi phiếu đang sửa đã chọn vẫn giữ được dù đã hết hạn).
    const promotionFits = (p, t) => !!t && (promotionApplies(p, t.branch_id, t.course_id) || String(p.id) === String(editing?.promotion_id ?? ''));

    const bank = computed(() => (state.currentTuition && state.currentTuition.bank ? state.currentTuition.bank : defaultBank));

    // Chi nhánh ghi nhận phiếu (như TuitionReceipt::resolveBranchId): theo hợp đồng, fallback học viên.
    const branchId = computed(() => (state.selectedTuitionId && state.currentTuition?.branch_id) || state.currentStudent?.branch_id || null);
    const stockOf = (itemId) => (branchId.value ? (stockByBranch[branchId.value]?.[itemId] ?? 0) : null);

    // Tiền mặt ở chi nhánh có dải hóa đơn giấy: hệ thống cấp số, người lập ghi đúng nội dung thu lên tờ hóa đơn mang số đó + tải ảnh.
    const issuedPaperInvoice = editing?.issued_paper_invoice || null;
    const paperMode = computed(() => state.paymentMethod === 'cash' && (!!issuedPaperInvoice || (branchId.value !== null && branchId.value in paperInvoiceNext)));
    const paperNumber = computed(() => (paperMode.value ? issuedPaperInvoice || paperInvoiceNext[branchId.value] || '' : null));
    const proofRequired = computed(() => ['transfer', 'vietqr', 'pos'].includes(state.paymentMethod) || paperMode.value);

    function onTuitionChange() {
        if (!state.selectedTuitionId) {
            // Không chọn khoản học phí: thu theo sổ buổi của học viên (khoản đang nợ hoặc khóa kế tiếp).
            state.currentTuition = null;
            return;
        }
        const t = tuitions.find((item) => String(item.id) === String(state.selectedTuitionId));
        if (t) {
            state.currentTuition = t;
            state.selectedStudentId = t.student_id;
            state.currentStudent = students.find((s) => String(s.id) === String(t.student_id)) || null;
            state.skipTuition = false;
            state.payerName = (editing && editing.payer_name) || t.student_parent_name || t.student_name;
            state.payerPhone = (editing && editing.payer_phone) || t.student_parent_phone || t.student_phone;
            const promo = promotions.find((p) => String(p.id) === String(state.promotionId));
            if (promo && !promotionFits(promo, t)) state.promotionId = '';
        }
    }

    function onStudentChange() {
        const s = students.find((item) => String(item.id) === String(state.selectedStudentId));
        if (s) {
            state.currentStudent = s;
            state.payerName = s.parent_name || s.name;
            state.payerPhone = s.parent_phone || s.phone;
            const matching = tuitions.find((t) => String(t.student_id) === String(s.id));
            state.selectedTuitionId = matching ? matching.id : '';
            state.currentTuition = matching || null;
        }
    }

    // Khởi tạo như init() cũ.
    if (editing && !state.selectedTuitionId) {
        // Phiếu chỉ thu phụ thu: giữ nguyên, không tự gắn hồ sơ học phí. Phiếu thu buổi khóa kế tiếp: vẫn tính theo sổ buổi.
        state.skipTuition = !(editingSession && editingSession.session_count > 0);
        state.currentStudent = students.find((s) => String(s.id) === String(state.selectedStudentId)) || null;
        state.payerName = editing.payer_name || '';
        state.payerPhone = editing.payer_phone || '';
    } else if (state.selectedTuitionId) {
        onTuitionChange();
    } else if (state.selectedStudentId) {
        onStudentChange();
    } else if (tuitions.length > 0) {
        state.selectedTuitionId = tuitions[0].id;
        onTuitionChange();
    }

    /** Sổ buổi + gợi ý thu của khoản học phí đang chọn (không chọn khoản → của học viên). Bỏ qua học phí → null. */
    const quote = computed(() => (state.skipTuition ? null : (state.selectedTuitionId && state.currentTuition?.quote) || state.currentStudent?.quote || null));
    const canCollectSessions = computed(() => !!quote.value && quote.value.mode !== 'none');
    const maxSessions = computed(() => (canCollectSessions.value ? quote.value.max_sessions : 0));
    /** Số buổi thu (đã chặn trong khoảng 0 … tối đa). */
    const sessions = computed(() => {
        if (!canCollectSessions.value) return 0;
        const n = parseInt(state.sessionCount);
        return Math.min(maxSessions.value, Math.max(0, isNaN(n) ? 0 : n));
    });
    // Đổi khoản học phí / học viên → số buổi về mặc định "Buổi cần thu" (phiếu đang sửa giữ số buổi đã lưu lần đầu).
    let keepEditingSessions = !!editingSession;
    watch(
        quote,
        (q) => {
            if (keepEditingSessions) {
                keepEditingSessions = false;
                return;
            }
            state.sessionCount = q && q.mode !== 'none' ? q.suggested : 0;
        },
        { immediate: true },
    );

    /** Tiền học phí theo buổi (gốc tính ưu đãi). */
    const tuitionSubtotal = computed(() => sessionValue(quote.value, sessions.value));

    // Phạm vi ưu đãi khi chưa có khoản học phí: cơ sở / khóa của lớp đang học.
    const quoteScope = computed(() => (state.currentStudent ? { branch_id: state.currentStudent.branch_id, course_id: state.currentStudent.course_id } : null));
    const availablePromotions = computed(() => (state.skipTuition || sessions.value <= 0 ? [] : promotions.filter((p) => promotionFits(p, (state.selectedTuitionId && state.currentTuition) || quoteScope.value))));
    const selectedPromotion = computed(() => availablePromotions.value.find((p) => String(p.id) === String(state.promotionId)) ?? null);
    /** Số tiền giảm của phiếu (chỉ trên tiền buổi): theo ưu đãi đã chọn, không chọn ưu đãi thì lấy số nhập tay. */
    const discountValue = computed(() => {
        if (sessions.value <= 0) return 0;
        const disc = selectedPromotion.value ? promotionDiscount(selectedPromotion.value, tuitionSubtotal.value) : Math.max(0, parseFloat(state.discountAmount) || 0);
        return Math.min(disc, tuitionSubtotal.value);
    });
    /** Nhập tay số tiền giảm (không theo ưu đãi có sẵn) → bắt buộc lý do. */
    const needsDiscountReason = computed(() => !selectedPromotion.value && discountValue.value > 0);

    const itemsTotal = computed(() => linesTotal(state.surchargeLines));
    const calc = computed(() =>
        calculateSessionReceipt(quote.value, {
            sessions: sessions.value,
            itemsTotal: itemsTotal.value,
            examFee: parseFloat(state.examFee) || 0,
            otherFee: parseFloat(state.otherFee) || 0,
            extra: parseFloat(state.surchargeAmount) || 0,
            discount: discountValue.value,
        }),
    );
    /** Tổng phải thu (tong_phai_thu) = tổng trước giảm − giảm. */
    const tuitionAmountAfterDiscount = computed(() => calc.value.totalDue);
    const surchargeTotal = computed(() => calc.value.extra);
    const totalAmount = computed(() => calc.value.amount);

    // Nội dung thu Học vụ ghi lên hóa đơn giấy (khớp từng dòng với phiếu): học phí, hàng hóa phụ thu, phụ thu khác.
    const paperInvoiceContent = computed(() => {
        const lines = [];
        const c = calc.value;
        const tuitionPart = c.sessionValue - c.discount;
        if (tuitionPart > 0) {
            const name = state.currentStudent?.name || state.currentTuition?.student_name || '';
            const className = quote.value?.class_name;
            lines.push({ label: `Học phí ${c.sessionCount} buổi ${name}${className ? ' - lớp ' + className : ''}`.replace(/\s+/g, ' ').trim(), amount: tuitionPart });
        }
        if (c.feeDue > 0) lines.push({ label: 'Học liệu còn nợ lúc chốt', amount: c.feeDue });
        for (const line of state.surchargeLines) {
            const item = itemById(line.id);
            if (item) lines.push({ label: `${item.name} x${parseInt(line.quantity) || 0}`, amount: item.price * (parseInt(line.quantity) || 0) });
        }
        if (c.examFee > 0) lines.push({ label: 'Phí thi', amount: c.examFee });
        if (c.otherFee > 0) lines.push({ label: state.otherFeeReason?.trim() || 'Khoản thu khác', amount: c.otherFee });
        if (c.extra > 0) lines.push({ label: state.surchargeReason?.trim() || 'Phụ thu', amount: c.extra });
        return lines;
    });
    const collectedItemsJson = computed(() => JSON.stringify(state.surchargeLines.map((line) => ({ id: line.id, quantity: parseInt(line.quantity) || 1 }))));

    function addItem(id) {
        if (!id || !itemById(id)) return;
        const existing = state.surchargeLines.find((line) => String(line.id) === String(id));
        if (existing) existing.quantity = (parseInt(existing.quantity) || 0) + 1;
        else state.surchargeLines.push({ id: Number(id), quantity: 1 });
    }
    const removeItem = (index) => state.surchargeLines.splice(index, 1);

    const isValidReceipt = computed(() => {
        const hasMoney = totalAmount.value >= 1000;
        const surchargeValid = state.surchargeAmount <= 0 || (state.surchargeReason && state.surchargeReason.trim().length > 0);
        const otherValid = !(parseFloat(state.otherFee) > 0) || state.otherFeeReason.trim().length > 0;
        const discountValid = !needsDiscountReason.value || state.discountReason.trim().length > 0;
        const linesValid = state.surchargeLines.every((line) => parseInt(line.quantity) >= 1);
        return !!(hasMoney && surchargeValid && otherValid && discountValid && linesValid);
    });

    // Nội dung CK do server sinh theo mẫu chung (tên + mã học sinh + lớp), xem App\Support\TransferMemo.
    const transferMemo = computed(() => state.currentTuition?.transfer_memo || state.currentStudent?.transfer_memo || '');

    const vietQrUrl = computed(() => {
        const b = bank.value;
        if (!b || !b.bank_code || !b.account_number) return '';
        const memo = transferMemo.value;
        const amt = totalAmount.value > 0 ? totalAmount.value : 0;
        return 'https://img.vietqr.io/image/' + encodeURIComponent(b.bank_code) + '-' + encodeURIComponent(b.account_number) + '-compact2.png?amount=' + amt + '&addInfo=' + encodeURIComponent(memo) + '&accountName=' + encodeURIComponent(b.account_holder || '');
    });

    function handleFileSelected(event) {
        const file = event.target.files[0];
        if (!file) return;
        state.proofFileName = file.name;
        state.proofFileSize = (file.size / 1024 / 1024).toFixed(2) + ' MB';
        state.proofIsPdf = !file.type.startsWith('image/');
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => {
                state.proofPreviewUrl = e.target.result;
            };
            reader.readAsDataURL(file);
        } else {
            state.proofPreviewUrl = 'pdf';
        }
    }

    function clearProof(fileInput) {
        state.proofRemoved = true;
        state.proofPreviewUrl = null;
        state.proofFileName = '';
        state.proofFileSize = '';
        if (fileInput) fileInput.value = '';
    }

    return reactive({
        state,
        tuitions,
        students,
        editing,
        bank,
        proofRequired,
        quote,
        canCollectSessions,
        maxSessions,
        sessions,
        calc,
        tuitionSubtotal,
        availablePromotions,
        selectedPromotion,
        discountValue,
        needsDiscountReason,
        tuitionAmountAfterDiscount,
        totalAmount,
        paperInvoiceContent,
        merchandiseItems,
        itemById,
        itemsTotal,
        surchargeTotal,
        collectedItemsJson,
        addItem,
        removeItem,
        branchId,
        stockOf,
        paperMode,
        paperNumber,
        issuedPaperInvoice,
        isValidReceipt,
        transferMemo,
        vietQrUrl,
        onTuitionChange,
        onStudentChange,
        handleFileSelected,
        clearProof,
        toggleSkipTuition: (val) => (state.skipTuition = val),
        // Gợi ý nhanh chỉ điền số tiền phụ thu khác; lý do người lập phải tự nhập.
        setSurcharge: (val) => (state.surchargeAmount = val),
    });
}
