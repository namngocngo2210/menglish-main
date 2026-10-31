const normalize = (value) => (value ?? '').normalize('NFC').trim().toLocaleLowerCase('vi');

function groupId(item) {
    const action = normalize(item.action);
    const when = normalize(item.when);
    // Approval queues must not be absorbed into tuition reminders.
    if (['duyệt', 'xác nhận'].includes(action) || /chờ duyệt|đang chờ/.test(when)) return 'approval';
    const title = normalize(item.title);
    if (/học phí|thu phí|phiếu thu.*quá hạn/.test(`${title} ${normalize(item.meta)}`)) return 'tuition';
    if (/^(chăm sóc|gọi lại)/.test(title)) return 'care';
    return 'other';
}

export function groupTodoItems(items) {
    const groups = [
        { id: 'tuition', label: 'Học phí', items: [], count: 0 },
        { id: 'care', label: 'Chăm sóc', items: [], count: 0 },
        { id: 'approval', label: 'Duyệt', items: [], count: 0 },
        { id: 'other', label: 'Khác', items: [], count: 0 },
    ];
    for (const item of items) {
        const group = groups.find((group) => group.id === groupId(item));
        group.items.push(item);
        group.count += item.count ?? 1;
    }
    return groups.filter((group) => group.items.length);
}

export function defaultTodoGroup(groups) {
    return (groups.find((group) => group.items.some((item) => item.tone === 'overdue')) ?? groups[0])?.id ?? null;
}

function dueOrder(item) {
    // Existing due labels use d/m; compare calendar dates without changing the label.
    const date = item.when?.match(/\b(\d{1,2})\/(\d{1,2})\b/);
    return date ? Number(date[2]) * 32 + Number(date[1]) : Infinity;
}

export function groupCareRows(items) {
    const rows = [];
    for (const item of items) {
        const care = /^chăm sóc tháng đầu\s*\(/.test(normalize(item.title));
        const previous = rows.at(-1);
        if (care && previous?.care) previous.items.push(item);
        else rows.push({ care, items: [item] });
    }
    return rows.map((row) => ({
        ...row,
        when: row.items.reduce((nearest, item) => dueOrder(item) < dueOrder(nearest) ? item : nearest).when,
    }));
}
