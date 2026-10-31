const sourceIcons = {
    receipt: 'receipt_long',
    invoice_cancellation: 'cancel',
    refund: 'currency_exchange',
    payment_report: 'payments',
    enrollment: 'school',
    crm_confirmation: 'person_check',
    crm_branch_transfer: 'swap_horiz',
    syllabus_proposal: 'menu_book',
    syllabus_adjustment: 'edit_note',
    big_test_order: 'quiz',
    work_task: 'task_alt',
    class_report: 'assignment',
    staff_attendance_request: 'schedule',
};

export function approvalIcon(key) {
    return Object.hasOwn(sourceIcons, key) ? sourceIcons[key] : 'fact_check';
}

// Pass the reference time explicitly so thresholds are deterministic and testable.
export function approvalAgeTone(createdAt, now) {
    const created = createdAt ? Date.parse(createdAt) : NaN;
    const hours = (now - created) / 3_600_000;
    if (hours >= 72) return 'error';
    if (hours >= 24) return 'warning';
    return 'neutral';
}

export function splitApprovalItems(items, limit = 3) {
    return { first: items.slice(0, limit), rest: items.slice(limit) };
}
