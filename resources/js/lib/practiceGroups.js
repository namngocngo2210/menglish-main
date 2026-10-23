/**
 * Chia các câu của một bài luyện tập để hiển thị (trang làm bài, xem lại bài):
 *   groupPracticeItems(items) → [{ skill, blocks: [{ key, instruction, passage, items: [...] }] }]
 * Phần = các câu liền nhau cùng kỹ năng; khối = các câu liền nhau cùng ngữ liệu (nội dung + ảnh) và cùng yêu cầu,
 * để ngữ liệu / yêu cầu chỉ hiện một lần phía trên nhóm câu.
 */
const passageKey = (passage) => (passage ? `${passage.content ?? ''}\u0000${passage.image_url ?? ''}` : '');

export function groupPracticeItems(items) {
    const sections = [];
    let lastBlockKey = null;

    for (const item of items ?? []) {
        let section = sections[sections.length - 1];
        if (!section || section.skill !== item.skill) {
            section = { skill: item.skill, blocks: [] };
            sections.push(section);
            lastBlockKey = null;
        }

        const key = `${passageKey(item.passage)}\u0001${item.instruction ?? ''}`;
        if (key !== lastBlockKey) {
            section.blocks.push({ key: `b${item.position}`, instruction: item.instruction ?? null, passage: item.passage ?? null, items: [] });
            lastBlockKey = key;
        }
        section.blocks[section.blocks.length - 1].items.push(item);
    }

    return sections;
}
