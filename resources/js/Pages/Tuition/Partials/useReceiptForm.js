/**
 * Trạng thái form Lập / Sửa phiếu thu học phí (chuyển từ Alpine createReceiptManager).
 * Tham số: danh sách khoản học phí, học viên, id chọn sẵn, TK ngân hàng mặc định, phiếu đang sửa (props của Tuition/ReceiptForm).
 * Nội dung CK và mã VietQR giữ nguyên cách tính cũ (nội dung CK do server sinh theo App\Support\TransferMemo).
 */
import { computed, reactive } from 'vue';

export function useReceiptForm({
    tuitions = [],
    students = [],
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

    const state = reactive({
        selectedTuitionId: initialTuitionId || '',
        selectedStudentId: initialStudentId || '',
        currentTuition: null,
        currentStudent: null,

        skipTuition: false,
        discountAmount: editing ? editing.discount_amount : 0,
        collectAmount: editing ? String(editing.tuition_amount) : '',
        surchargeLines: initialLines,
        // Phụ thu khác (ngoài hàng hóa trong danh mục) — bắt buộc lý do.
        surchargeAmount: editing ? Math.max(0, editing.surcharge_amount - linesTotal(initialLines)) : 0,
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

    const bank = computed(() => (state.currentTuition && state.currentTuition.bank ? state.currentTuition.bank : defaultBank));

    // Chi nhánh ghi nhận phiếu (như TuitionReceipt::resolveBranchId): theo hợp đồng, fallback học viên.
    const branchId = computed(() => (state.selectedTuitionId && state.currentTuition?.branch_id) || state.currentStudent?.branch_id || null);
    const stockOf = (itemId) => (branchId.value ? (stockByBranch[branchId.value]?.[itemId] ?? 0) : null);

    // Tiền mặt ở chi nhánh có dải hóa đơn giấy: hệ thống cấp số, người lập ghi số đó lên hóa đơn giấy + tải ảnh.
    const issuedPaperInvoice = editing?.issued_paper_invoice || null;
    const paperMode = computed(() => state.paymentMethod === 'cash' && (!!issuedPaperInvoice || (branchId.value !== null && branchId.value in paperInvoiceNext)));
    const paperNumber = computed(() => (paperMode.value ? issuedPaperInvoice || paperInvoiceNext[branchId.value] || '' : null));
    const proofRequired = computed(() => ['transfer', 'vietqr', 'pos'].includes(state.paymentMethod) || paperMode.value);

    function onTuitionChange() {
        if (!state.selectedTuitionId) {
            state.currentTuition = null;
            state.skipTuition = true;
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
        }
    }

    function onStudentChange() {
        const s = students.find((item) => String(item.id) === String(state.selectedStudentId));
        if (s) {
            state.currentStudent = s;
            state.payerName = s.parent_name || s.name;
            state.payerPhone = s.parent_phone || s.phone;
            const matching = tuitions.find((t) => String(t.student_id) === String(s.id));
            if (matching) {
                state.selectedTuitionId = matching.id;
                state.currentTuition = matching;
            }
        }
    }

    // Khởi tạo như init() cũ.
    if (editing && !state.selectedTuitionId) {
        // Phiếu chỉ thu phụ thu: giữ nguyên, không tự gắn hồ sơ học phí.
        state.skipTuition = true;
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

    const tuitionSubtotal = computed(() => {
        if (state.skipTuition || !state.currentTuition) return 0;
        const t = state.currentTuition;
        return parseFloat(t.debt_amount) > 0 ? parseFloat(t.debt_amount) : parseFloat(t.total_amount) + parseFloat(t.other_fees);
    });

    const tuitionAmountAfterDiscount = computed(() => {
        if (state.skipTuition || !state.currentTuition) return 0;
        const disc = parseFloat(state.discountAmount) || 0;
        const max = Math.max(0, tuitionSubtotal.value - disc);
        // Thu một phần công nợ: nhập số tiền thu đợt này (không vượt phần còn phải thu).
        if (state.collectAmount !== '' && state.collectAmount !== null && !isNaN(parseFloat(state.collectAmount))) {
            return Math.min(max, Math.max(0, parseFloat(state.collectAmount)));
        }
        return max;
    });

    const itemsTotal = computed(() => linesTotal(state.surchargeLines));
    const surchargeTotal = computed(() => itemsTotal.value + (parseFloat(state.surchargeAmount) || 0));
    const totalAmount = computed(() => tuitionAmountAfterDiscount.value + surchargeTotal.value);
    const collectedItemsJson = computed(() => JSON.stringify(state.surchargeLines.map((line) => ({ id: line.id, quantity: parseInt(line.quantity) || 1 }))));

    function addItem(id) {
        if (!id || !itemById(id)) return;
        const existing = state.surchargeLines.find((line) => String(line.id) === String(id));
        if (existing) existing.quantity = (parseInt(existing.quantity) || 0) + 1;
        else state.surchargeLines.push({ id: Number(id), quantity: 1 });
    }
    const removeItem = (index) => state.surchargeLines.splice(index, 1);

    const isValidReceipt = computed(() => {
        const hasMoney = totalAmount.value > 0;
        const surchargeValid = state.surchargeAmount <= 0 || (state.surchargeReason && state.surchargeReason.trim().length > 0);
        const linesValid = state.surchargeLines.every((line) => parseInt(line.quantity) >= 1);
        return !!(hasMoney && surchargeValid && linesValid);
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
        tuitionSubtotal,
        tuitionAmountAfterDiscount,
        totalAmount,
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
