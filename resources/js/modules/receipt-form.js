/**
 * Alpine.data('createReceiptManager') — form Lập / Sửa phiếu thu học phí (tuition/receipts/_form.blade.php).
 * Chuyển từ <script> inline sang module để chạy được khi form được htmx đổ vào modal (script inline trong
 * nội dung swap không ổn định). Tham số: danh sách khoản học phí, học viên, id chọn sẵn, TK ngân hàng mặc định, phiếu đang sửa.
 */
export default function createReceiptManager(tuitions, students, initialTuitionId, initialStudentId, defaultBank, editing) {
    return {
        tuitions: tuitions || [],
        students: students || [],
        selectedTuitionId: initialTuitionId || '',
        selectedStudentId: initialStudentId || '',
        currentTuition: null,
        currentStudent: null,
        editing: editing,

        skipTuition: false,
        discountAmount: editing ? editing.discount_amount : 0,
        collectAmount: editing ? String(editing.tuition_amount) : '',
        surchargeAmount: editing ? editing.surcharge_amount : 0,
        surchargeReason: editing ? (editing.surcharge_reason || '') : '',
        paymentMethod: editing ? editing.payment_method : 'transfer',
        transactionCode: editing ? (editing.transaction_code || '') : '',
        payerName: '',
        payerPhone: '',

        defaultBank: defaultBank,

        proofPreviewUrl: editing && editing.proof_image ? editing.proof_image : null,
        proofFileName: editing && editing.proof_image ? editing.proof_image.split('/').pop() : '',
        proofFileSize: '',
        proofIsPdf: !!(editing && editing.proof_image && editing.proof_image.toLowerCase().endsWith('.pdf')),
        proofRemoved: false,

        get bank() {
            return (this.currentTuition && this.currentTuition.bank) ? this.currentTuition.bank : this.defaultBank;
        },

        get proofRequired() {
            return ['transfer', 'vietqr', 'pos'].includes(this.paymentMethod);
        },

        init() {
            if (this.editing && !this.selectedTuitionId) {
                // Phiếu chỉ thu phụ thu: giữ nguyên, không tự gắn hồ sơ học phí.
                this.skipTuition = true;
                this.currentStudent = this.students.find(s => String(s.id) === String(this.selectedStudentId)) || null;
                this.payerName = this.editing.payer_name || '';
                this.payerPhone = this.editing.payer_phone || '';
            } else if (this.selectedTuitionId) {
                this.onTuitionChange();
            } else if (this.selectedStudentId) {
                this.onStudentChange();
            } else if (this.tuitions.length > 0) {
                this.selectedTuitionId = this.tuitions[0].id;
                this.onTuitionChange();
            }
        },

        onTuitionChange() {
            if (!this.selectedTuitionId) {
                this.currentTuition = null;
                this.skipTuition = true;
                return;
            }

            const t = this.tuitions.find(item => String(item.id) === String(this.selectedTuitionId));
            if (t) {
                this.currentTuition = t;
                this.selectedStudentId = t.student_id;
                this.currentStudent = this.students.find(s => String(s.id) === String(t.student_id)) || null;
                this.skipTuition = false;
                this.payerName = (this.editing && this.editing.payer_name) || t.student_parent_name || t.student_name;
                this.payerPhone = (this.editing && this.editing.payer_phone) || t.student_parent_phone || t.student_phone;
            }
        },

        onStudentChange() {
            const s = this.students.find(item => String(item.id) === String(this.selectedStudentId));
            if (s) {
                this.currentStudent = s;
                this.payerName = s.parent_name || s.name;
                this.payerPhone = s.parent_phone || s.phone;
                // Find matching tuition if any
                const matchingT = this.tuitions.find(t => String(t.student_id) === String(s.id));
                if (matchingT) {
                    this.selectedTuitionId = matchingT.id;
                    this.currentTuition = matchingT;
                }
            }
        },

        toggleSkipTuition(val) {
            this.skipTuition = val;
        },

        // Gợi ý nhanh chỉ điền số tiền; lý do phụ thu người lập phải tự nhập (không điền sẵn nội dung).
        setSurcharge(val) {
            this.surchargeAmount = val;
        },

        get tuitionSubtotal() {
            if (this.skipTuition || !this.currentTuition) return 0;
            return (parseFloat(this.currentTuition.debt_amount) > 0)
                ? parseFloat(this.currentTuition.debt_amount)
                : (parseFloat(this.currentTuition.total_amount) + parseFloat(this.currentTuition.other_fees));
        },

        get tuitionAmountAfterDiscount() {
            if (this.skipTuition || !this.currentTuition) return 0;
            const sub = this.tuitionSubtotal;
            const disc = parseFloat(this.discountAmount) || 0;
            const max = Math.max(0, sub - disc);
            // Thu một phần công nợ: nhập số tiền thu đợt này (không vượt phần còn phải thu).
            if (this.collectAmount !== '' && this.collectAmount !== null && !isNaN(parseFloat(this.collectAmount))) {
                return Math.min(max, Math.max(0, parseFloat(this.collectAmount)));
            }
            return max;
        },

        get totalAmount() {
            const t = this.tuitionAmountAfterDiscount;
            const s = parseFloat(this.surchargeAmount) || 0;
            return t + s;
        },

        get isValidReceipt() {
            const hasMoney = this.totalAmount > 0;
            const surchargeValid = (this.surchargeAmount <= 0) || (this.surchargeReason && this.surchargeReason.trim().length > 0);
            return hasMoney && surchargeValid;
        },

        removeVietnameseTones(str) {
            if (!str) return '';
            return str.normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/đ/g, 'd').replace(/Đ/g, 'D')
                .replace(/[^a-zA-Z0-9]/g, '');
        },

        get transferMemo() {
            const code = (this.currentStudent?.code || 'HS000001').toUpperCase().replace(/[^A-Z0-9]/g, '');
            let name = this.removeVietnameseTones(this.currentStudent?.name || 'HOCVIEN').toUpperCase().replace(/[^A-Z0-9]/g, '');
            let cls = this.removeVietnameseTones(this.currentTuition?.class_name || this.currentStudent?.class_name || 'LOP').toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 7);
            let cn = this.removeVietnameseTones(this.currentStudent?.branch_name || 'BD').toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (!cn.startsWith('CN')) {
                cn = 'CN' + cn;
            }
            const phone = (this.currentStudent?.phone || this.currentStudent?.parent_phone || '').replace(/[^0-9]/g, '');
            const tail = phone ? phone.slice(-3) : '888';
            return `${code} ${name} ${cls} ${cn} ${tail}`;
        },

        get vietQrUrl() {
            const bank = this.bank;
            if (!bank || !bank.bank_code || !bank.account_number) return '';
            const memo = this.transferMemo;
            const amt = this.totalAmount > 0 ? this.totalAmount : 0;
            return 'https://img.vietqr.io/image/' + encodeURIComponent(bank.bank_code) + '-' + encodeURIComponent(bank.account_number) + '-compact2.png?amount=' + amt + '&addInfo=' + encodeURIComponent(memo) + '&accountName=' + encodeURIComponent(bank.account_holder || '');
        },

        handleFileSelected(event) {
            const file = event.target.files[0];
            if (!file) return;
            this.proofFileName = file.name;
            this.proofFileSize = (file.size / 1024 / 1024).toFixed(2) + ' MB';
            this.proofIsPdf = !file.type.startsWith('image/');
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    this.proofPreviewUrl = e.target.result;
                };
                reader.readAsDataURL(file);
            } else {
                this.proofPreviewUrl = 'pdf';
            }
        },

        clearProof() {
            this.proofRemoved = true;
            this.proofPreviewUrl = null;
            this.proofFileName = '';
            this.proofFileSize = '';
            if (this.$refs.fileInput) {
                this.$refs.fileInput.value = '';
            }
        },

        recalc() {
            // Reactive triggers
        },

        formatVND(val) {
            return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
        }
    };
}
