// Chạy: npm run test:js (node --test, không cần thư viện test).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { groupSchedule } from './schedule.js';

test('gộp các buổi cùng giờ thành một dòng', () => {
    assert.deepEqual(groupSchedule('Thứ 3 18:00-19:30; Thứ 5 18:00-19:30'), [{ days: 'Thứ 3, Thứ 5', time: '18:00–19:30' }]);
});

test('khác giờ hoặc khác phòng thì tách dòng', () => {
    assert.deepEqual(groupSchedule('Thứ 7 08:00-09:30 (P.201); Chủ nhật 08:00-09:30 (P.202); Thứ 2 17:30-19:00 (P.201)'), [
        { days: 'Thứ 7', time: '08:00–09:30 (P.201)' },
        { days: 'Chủ nhật', time: '08:00–09:30 (P.202)' },
        { days: 'Thứ 2', time: '17:30–19:00 (P.201)' },
    ]);
});

test('chuỗi tự do hoặc rỗng', () => {
    assert.deepEqual(groupSchedule('Sáng thứ 7 hàng tuần'), [{ days: 'Sáng thứ 7 hàng tuần', time: '' }]);
    assert.deepEqual(groupSchedule(''), []);
    assert.deepEqual(groupSchedule(null), []);
});
