// Chạy: npm run test:js (node --test, không cần thư viện test).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { alertCount, attentionGroups, classTable, isQuiet, leftoverDetails, quietSummary, shiftDate, submitStatus, timeLeft } from './dailyReport.js';

const operations = {
    title: 'Vận hành lớp học',
    items: [
        { key: 'classes_planned', label: 'Số lớp kế hoạch', value: 2, details: [] },
        {
            key: 'classes_list',
            label: 'Danh sách lớp trong ngày',
            value: null,
            details: [
                { text: 'ST1 · 08:00', tag: 'Đã dạy', tone: 'success', ref: 1, class: 'ST1' },
                { text: 'FL1 · 18:00', tag: 'Chưa điểm danh', tone: 'warning', ref: 2, class: 'FL1' },
            ],
        },
        {
            key: 'teachers',
            label: 'Số giáo viên',
            value: 1,
            details: [
                { text: 'Cô Lan · ST1', tag: 'Chưa check-in', tone: 'warning', ref: 1 },
                { text: 'Cô Lan · FL1', tag: 'Chưa check-in', tone: 'warning', ref: 2 },
            ],
        },
        { key: 'assistants', label: 'Số trợ giảng', value: 1, details: [{ text: 'TA · trễ 5 phút', tag: 'Đi muộn', tone: 'warning' }] },
    ],
};
const sessions = { title: 'Chất lượng buổi học', items: [{ key: 'low', label: 'Phụ huynh chấm thấp', value: null, details: [{ text: 'MV2', tag: '2 sao', tone: 'error' }] }] };

test('gom việc cần chú ý theo khối + nhãn, lỗi lên trước', () => {
    const groups = attentionGroups({ operations, sessions });
    assert.deepEqual(groups.map((g) => [g.tag, g.count, g.tone]), [
        ['2 sao', 1, 'error'],
        ['Chưa check-in', 2, 'warning'],
        ['Chưa điểm danh', 1, 'warning'],
        ['Đi muộn', 1, 'warning'],
    ]);
    assert.equal(alertCount(operations), 4);
});

test('bảng lớp ghép dòng GV / trợ giảng theo buổi, dòng không có buổi để lại', () => {
    const rows = classTable(operations);
    assert.equal(rows.length, 2);
    assert.deepEqual(rows[1].teacherIssues.map((d) => d.text), ['Cô Lan · FL1']);
    assert.deepEqual(leftoverDetails(operations.items[3], true).map((d) => d.tag), ['Đi muộn']);
    assert.deepEqual(leftoverDetails(operations.items[2], true), []);
});

test('báo cáo cũ không có ref thì không dựng bảng', () => {
    const old = { items: [{ key: 'classes_list', details: [{ text: 'ST1', tag: 'Đã dạy' }] }] };
    assert.equal(classTable(old), null);
});

test('khối toàn số 0 thì thu gọn, tóm tắt một dòng', () => {
    const enrollment = { items: [{ label: 'Bổ trợ', value: '0', details: [] }, { label: 'Test', value: '0/0', details: [] }, { label: 'Ghi chú', value: null, details: [] }] };
    assert.equal(isQuiet(enrollment), true);
    assert.equal(quietSummary(enrollment), 'Bổ trợ 0 · Test 0/0');
    assert.equal(isQuiet(operations), false);
});

test('trạng thái nộp', () => {
    const now = new Date('2026-10-27T10:00:00+07:00');
    assert.equal(submitStatus({ deadlineAt: '2026-10-28T09:00:00+07:00', now }).key, 'open');
    assert.equal(submitStatus({ deadlineAt: '2026-10-27T09:00:00+07:00', now }).key, 'overdue');
    assert.equal(submitStatus({ submittedAt: 'x', late: true, now }).key, 'late');
    assert.equal(submitStatus({ submittedAt: 'x', review: { status: 'returned' }, now }).key, 'returned');
    assert.equal(timeLeft('2026-10-28T09:00:00+07:00', now), 'còn 23 giờ 0 phút');
    assert.equal(timeLeft('2026-10-27T09:00:00+07:00', now), null);
});

test('lùi / tiến ngày', () => {
    assert.equal(shiftDate('2026-11-01', -1), '2026-10-31');
    assert.equal(shiftDate('2026-12-31', 1), '2027-01-01');
});
