import test from 'node:test';
import assert from 'node:assert/strict';
import { groupTodoItems, defaultTodoGroup, groupCareRows } from './todoGroups.js';

test('groups tuition, care, approvals and other tasks in a stable order', () => {
    const items = [
        { title: 'Việc khác' },
        { title: 'Gọi lại khách hàng', count: 2 },
        { title: 'Nhắc thu học phí', count: 4 },
        { title: 'Phiếu thu quá hạn', when: 'Quá hạn' },
        { title: 'Chăm sóc học viên' },
        { title: 'Việc cần làm', meta: 'Thu phí tháng này' },
    ];
    const groups = groupTodoItems(items);
    assert.deepEqual(groups.map(({ label, count }) => [label, count]), [
        ['Học phí', 6], ['Chăm sóc', 3], ['Khác', 1],
    ]);
    assert.equal(groups[0].items[0], items[2]);
    assert.equal(groups[1].items[0], items[1]);
    assert.equal(items.length, 6);
});

test('approval queues take precedence over tuition and care keywords', () => {
    const items = [
        { title: 'Phiếu thu chờ duyệt', action: 'Duyệt', count: 6 },
        { title: 'Học phí', action: 'Xác nhận' },
        { title: 'Thu phí', when: 'Đang chờ' },
        { title: 'Chăm sóc', when: 'Chờ duyệt' },
    ];
    assert.deepEqual(groupTodoItems(items).map(({ label, count }) => [label, count]), [['Duyệt', 9]]);
});

test('tuition content in a care title still belongs to tuition unless awaiting approval', () => {
    assert.equal(groupTodoItems([{ title: 'Chăm sóc học phí quá hạn' }])[0].label, 'Học phí');
});

test('matches Vietnamese text case insensitively and handles null counts', () => {
    const groups = groupTodoItems([
        { title: '  NHẮC HỌC PHÍ', count: null },
        { title: ' GỌI LẠI khách', count: 0 },
        { meta: 'THU PHÍ', count: 3 },
        { title: 'Chăm sóc tháng đầu (Buổi 1)', action: 'xác nhận' },
    ]);
    assert.deepEqual(groups.map(({ label, count }) => [label, count]), [['Học phí', 4], ['Chăm sóc', 0], ['Duyệt', 1]]);
});

test('selects the first group containing overdue work, otherwise the first group', () => {
    const groups = groupTodoItems([
        { title: 'Học phí', tone: 'today' },
        { title: 'Chăm sóc học viên', tone: 'overdue' },
        { title: 'Việc khác', tone: 'overdue' },
    ]);
    assert.equal(defaultTodoGroup(groups), groups[1].id);
    assert.equal(defaultTodoGroup(groupTodoItems([{ title: 'Việc khác' }])), 'other');
    assert.deepEqual(groupTodoItems([]), []);
    assert.equal(defaultTodoGroup([]), null);
});

test('collapses only consecutive first-month care rows and preserves each original item', () => {
    const items = [
        { title: 'Chăm sóc tháng đầu (Buổi 1): An', when: 'Hạn 30/10', href: '/an' },
        { title: 'Chăm sóc tháng đầu (Buổi 2): Bình', when: 'Hạn 02/10', href: '/binh' },
        { title: 'Gọi lại Chi', when: 'Hôm nay' },
        { title: 'Chăm sóc tháng đầu (Buổi 1): Dung', when: 'Hạn 03/11' },
    ];
    const rows = groupCareRows(items);
    assert.deepEqual(rows.map((row) => [row.care, row.items.length]), [[true, 2], [false, 1], [true, 1]]);
    assert.equal(rows[0].when, 'Hạn 02/10');
    assert.equal(rows[0].items[0], items[0]);
    assert.deepEqual(rows.flatMap((row) => row.items), items);
});

test('large care groups retain all rows and show a supplied due label when no date exists', () => {
    const items = Array.from({ length: 6 }, (_, i) => ({ title: `Chăm sóc tháng đầu (Buổi ${i + 1})`, when: 'Hôm nay' }));
    const [row] = groupCareRows(items);
    assert.equal(row.items.length, 6);
    assert.equal(row.when, 'Hôm nay');
    assert.deepEqual(groupCareRows([]), []);
});
