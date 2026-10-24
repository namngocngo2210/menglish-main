// Chạy: npm run test:js (node --test, không cần thư viện test).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { groupPracticeItems } from './practiceGroups.js';

const item = (position, skill, extra = {}) => ({ position, skill, instruction: null, passage: null, ...extra });

test('chia phần theo kỹ năng liền nhau', () => {
    const sections = groupPracticeItems([item(1, 'Ngữ âm'), item(2, 'Ngữ âm'), item(3, 'Viết')]);
    assert.deepEqual(sections.map((s) => s.skill), ['Ngữ âm', 'Viết']);
    assert.deepEqual(sections[0].blocks.flatMap((b) => b.items.map((i) => i.position)), [1, 2]);
});

test('ngữ liệu và yêu cầu chỉ hiện một lần cho các câu liền nhau dùng chung', () => {
    const passage = { content: 'My [school] is big.', image_url: null };
    const sections = groupPracticeItems([
        item(1, 'Đọc hiểu', { instruction: 'Read', passage }),
        item(2, 'Đọc hiểu', { instruction: 'Read', passage: { ...passage } }),
        item(3, 'Đọc hiểu', { instruction: 'Read', passage: { content: 'Other', image_url: null } }),
        item(4, 'Đọc hiểu', { instruction: 'Choose' }),
        item(5, 'Đọc hiểu', { instruction: 'Choose' }),
    ]);
    const blocks = sections[0].blocks;
    assert.deepEqual(blocks.map((b) => b.items.map((i) => i.position)), [[1, 2], [3], [4, 5]]);
    assert.equal(blocks[0].passage.content, 'My [school] is big.');
    assert.equal(blocks[2].passage, null);
    assert.equal(blocks[2].instruction, 'Choose');
});

test('danh sách rỗng', () => {
    assert.deepEqual(groupPracticeItems([]), []);
    assert.deepEqual(groupPracticeItems(null), []);
});
