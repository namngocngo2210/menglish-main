<script setup>
/**
 * Thang điểm & nhận xét tự động (Cambridge YLE Starters - Movers): bộ mô phỏng tính điểm + 4 bảng thang điểm theo khối.
 * Bộ mô phỏng dùng chung cấu hình với form chấm điểm (PlacementRubricService::clientConfig) — không lặp lại thang điểm trong JS.
 * Nội dung 4 bảng là văn bản quy chuẩn tĩnh (giữ nguyên như bản Blade).
 */
import { computed, ref } from 'vue';

defineOptions({ layout: { title: 'Thang điểm & nhận xét tự động' } });

const props = defineProps({
    config: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
    noRubricNotice: { type: String, default: '' },
});

const activeTab = ref('tab2');
const khoiKey = ref('khoi_2_3');
const scoreL = ref(11);
const scoreR = ref(12);
const scoreS = ref(8);

function band(rubric, skill, score) {
    let text = '';
    (rubric.bands[skill] || []).forEach((b) => {
        if (score >= b.min) text = b.text;
    });
    return text || '—';
}

// Tương đương recalc() của bản Alpine: kẹp điểm trong [0, tối đa], tra lớp theo tổng điểm, sinh nhận xét.
const result = computed(() => {
    const r = props.config.groups[khoiKey.value];
    if (!r) return { totalScore: 0, maxTotal: 0, placementCourse: '', generatedComment: '' };
    const clamp = (v, max) => Math.min(Math.max(parseFloat(v) || 0, 0), max);
    const l = clamp(scoreL.value, r.max.listening);
    const rw = clamp(scoreR.value, r.max.reading_writing);
    const s = clamp(scoreS.value, r.max.speaking);
    const totalScore = Math.round((l + rw + s) * 10) / 10;
    const hit = (r.placements || []).find((p) => (p.lt !== null ? totalScore < p.lt : p.lte !== null ? totalScore <= p.lte : true));
    const placementCourse = hit ? hit.class : 'Chưa có lớp tương ứng — Học vụ chọn lớp thủ công';
    return {
        totalScore,
        maxTotal: r.max.listening + r.max.reading_writing + r.max.speaking,
        placementCourse,
        generatedComment:
            `【Kỹ năng Nghe】: ${band(r, 'listening', l)}\n` +
            `【Kỹ năng Đọc & Viết】: ${band(r, 'reading_writing', rw)}\n` +
            `【Kỹ năng Nói】: ${band(r, 'speaking', s)}\n` +
            `【Đề xuất Xếp lớp】: ${placementCourse}`,
    };
});

const tabButtons = [
    { key: 'tab1', icon: 'looks_one', label: 'Khối 1 - 2 (Starters)' },
    { key: 'tab2', icon: 'looks_two', label: 'Khối 2 lên 3 (Starters)' },
    { key: 'tab3', icon: 'looks_3', label: 'Khối 3 lên 4 (Movers)' },
    { key: 'tab4', icon: 'looks_4', label: 'Khối 4 lên 5 (Movers)' },
];

// Mỗi bảng: tiêu đề, cấp độ, 3 cột (tiêu đề + độ rộng), 3 hàng × 3 ô [mức, mô tả], 3 mốc xếp lớp.
const tables = [
    {
        key: 'tab1',
        title: 'KHỐI 1 - 2 (Tổng điểm tối đa: 35)',
        level: 'Starters Level',
        heads: [['LISTENING /10', 'w-1/4'], ['READING AND WRITING /15', 'w-1/3'], ['SPEAKING /10', 'w-1/3']],
        rows: [
            [
                ['Mức 2 - 4 điểm:', 'Con bắt đầu hình thành kỹ năng nghe cơ bản, nhận diện được một số từ vựng đơn giản qua tranh, chưa quen xử lý bài nghe điền từ hoặc câu hỏi đa thông tin.'],
                ['Mức 1 - 5 điểm:', 'Chưa có nền từ vựng tốt, nhận diện một số từ đơn cơ bản, đọc câu chưa phân biệt được đúng sai.'],
                ['Mức 2 - 4 điểm:', 'Chưa hình thành kỹ năng nghe nói cơ bản, chỉ nắm bắt được 1-2 câu hỏi thông tin cá nhân đơn giản nhất.'],
            ],
            [
                ['Mức 5 - 7 điểm:', 'Đã có kỹ năng nghe cơ bản, nghe nhận diện từ vựng qua tranh, nhận diện từ khóa nhưng chưa theo kịp tốc độ bài nghe dài.'],
                ['Mức 6 - 10 điểm:', 'Nhận diện cơ bản từ vựng, cần củng cố nhớ chính tả. Đọc câu phân biệt được đúng sai ở mức đơn giản.'],
                ['Mức 5 - 7 điểm:', 'Nhận diện được các câu hỏi cơ bản theo tranh, phát âm tương đối rõ ràng, cần rèn luyện thêm sự tự tin khi mở rộng câu.'],
            ],
            [
                ['Mức 8 - 10 điểm:', 'Nghe tốt, nắm vững từ vựng các chủ đề đời sống, nhận diện thông tin chính xác từ bài hội thoại.'],
                ['Mức 11 - 15 điểm:', 'Vốn từ phong phú, nhớ chính tả tốt, đọc hiểu câu đơn hoàn chỉnh và xử lý bài tập linh hoạt.'],
                ['Mức 8 - 10 điểm:', 'Nói trôi chảy, phản xạ nhanh với các câu hỏi Starters, phát âm chuẩn và tự tin trả lời nguyên câu.'],
            ],
        ],
        placementTitle: 'Quyết định Xếp lớp tự động (Khối 1-2)',
        placements: [
            ['Tổng điểm < 10', 'PRE STARTERS (FAM 0)'],
            ['Tổng điểm 10 - 15', 'STARTERS (FAM 1 _ BÀI ĐẦU)'],
            ['Tổng điểm 16 - 25', 'STARTERS (FAM 1 _ BÀI 5 - 10)'],
        ],
    },
    {
        key: 'tab2',
        title: 'KHỐI 2 LÊN 3 (Tổng điểm tối đa: 40)',
        level: 'Starters Level',
        heads: [['LISTENING /15', 'w-1/3'], ['READING AND WRITING /15', 'w-1/3'], ['SPEAKING /10', 'w-1/3']],
        rows: [
            [
                ['Mức 1 - 6 điểm:', 'Kỹ năng nghe cơ bản, nhận diện từ khóa quen thuộc, chưa quen phân biệt thông tin đa chiều.'],
                ['Mức 1 - 5 điểm:', 'Nhận diện từ đơn lẻ, ngữ pháp cơ bản cần củng cố để viết câu và sắp xếp câu hoàn chỉnh.'],
                ['Mức 2 - 4 điểm:', 'Giao tiếp được các câu hỏi thông dụng nhất, cần nâng cao sự tự tin và vốn từ phản xạ.'],
            ],
            [
                ['Mức 6 - 10 điểm:', 'Nghe hiểu khá các bài nghe nhận diện, phân biệt được câu hỏi và nắm bắt thông tin 1 chiều.'],
                ['Mức 6 - 10 điểm:', 'Nhận diện từ vựng tốt, đọc hiểu câu đơn, cần trau dồi thêm kỹ năng sắp xếp trật tự từ.'],
                ['Mức 5 - 7 điểm:', 'Phát âm tốt, trả lời được các câu hỏi về tranh, phản xạ tự nhiên với giáo viên.'],
            ],
            [
                ['Mức 11 - 15 điểm:', 'Nghe tốt, phân biệt được thông tin gây nhiễu, bắt kịp tốc độ các đoạn hội thoại hoàn chỉnh.'],
                ['Mức 11 - 15 điểm:', 'Nền từ vựng và ngữ pháp vững vàng, đọc hiểu linh hoạt, làm bài viết và sắp xếp câu chuẩn xác.'],
                ['Mức 8 - 10 điểm:', 'Nói lưu loát, diễn đạt ý rõ ràng, tự tin trả lời câu dài và mô tả tranh sinh động.'],
            ],
        ],
        placementTitle: 'Quyết định Xếp lớp tự động (Khối 2 lên 3)',
        placements: [
            ['Tổng điểm 10 - 20', 'PRE STARTERS _ FAM 1 (DƯỚI U5)'],
            ['Tổng điểm 20 - 30', 'STARTERS (FAM 1 _ UNIT 6 - 10)'],
            ['Tổng điểm 30 - 40', 'STARTERS (FAM 1 _ UNIT 7 - 12)'],
        ],
    },
    {
        key: 'tab3',
        title: 'KHỐI 3 LÊN 4 (Tổng điểm tối đa: 45)',
        level: 'Movers Level',
        heads: [['LISTENING /15', 'w-1/3'], ['READING AND WRITING /20', 'w-1/3'], ['SPEAKING /10', 'w-1/3']],
        rows: [
            [
                ['Mức 1 - 6 điểm:', 'Nghe nhận diện từ khóa cơ bản, cần làm quen thêm với các dạng đề thi Cambridge Movers.'],
                ['Mức 1 - 7 điểm:', 'Nhận diện từ đơn lẻ, cần tăng cường ngữ pháp ứng dụng và kỹ năng viết đoạn văn ngắn.'],
                ['Mức 2 - 4 điểm:', 'Hiểu câu hỏi cơ bản, cần luyện phản xạ ghép câu và mô tả tranh so sánh điểm khác biệt.'],
            ],
            [
                ['Mức 6 - 10 điểm:', 'Bắt đầu nghe hiểu các câu ngắn 1 chiều, nắm được thông tin chính trong bài hội thoại.'],
                ['Mức 7 - 15 điểm:', 'Đọc hiểu câu ngắn và kết nối thông tin tốt, viết được các cụm từ hoàn chỉnh.'],
                ['Mức 5 - 7 điểm:', 'Phát âm rõ ràng, đủ từ vựng để trả lời câu hỏi, phản xạ giao tiếp tự tin.'],
            ],
            [
                ['Mức 11 - 15 điểm:', 'Nghe hiểu toàn diện level Movers, phân biệt thông tin gây nhiễu và ghi chép chính xác.'],
                ['Mức 15 - 20 điểm:', 'Nền từ vựng Movers phong phú, nắm vững cấu trúc câu phức và xử lý bài đọc hiểu xuất sắc.'],
                ['Mức 8 - 10 điểm:', 'Giao tiếp trôi chảy, mô tả tranh và so sánh sự khác biệt chi tiết, diễn đạt tự nhiên.'],
            ],
        ],
        placementTitle: 'Quyết định Xếp lớp tự động (Khối 3 lên 4)',
        placements: [
            ['Tổng điểm 10 - 20', 'FAM 2 (NỬA ĐẦU)'],
            ['Tổng điểm 20 - 35', 'FAM 2 (NỬA SAU)'],
            ['Tổng điểm 35 - 45', 'LUYỆN THI MOVERS'],
        ],
    },
    {
        key: 'tab4',
        title: 'KHỐI 4 LÊN 5 (Tổng điểm tối đa: 40)',
        level: 'Movers Level',
        heads: [['LISTENING /15', 'w-1/3'], ['READING AND WRITING /15', 'w-1/3'], ['SPEAKING /10', 'w-1/3']],
        rows: [
            [
                ['Mức 1 - 6 điểm:', 'Kỹ năng nghe mức hình thành cơ bản, cần rèn luyện thêm bài tập nghe chọn tranh và điền từ.'],
                ['Mức 1 - 5 điểm:', 'Nhận diện từ vựng đơn, cần củng cố cấu trúc câu và các thì căn bản.'],
                ['Mức 2 - 4 điểm:', 'Giao tiếp câu đơn, cần mở rộng câu và rèn luyện kể chuyện theo tranh.'],
            ],
            [
                ['Mức 6 - 10 điểm:', 'Nghe hiểu tốt các hội thoại thông thường, nhận diện đúng thông tin câu hỏi.'],
                ['Mức 6 - 10 điểm:', 'Nắm vững từ vựng trọng tâm, đọc hiểu trôi chảy đoạn văn ngắn.'],
                ['Mức 5 - 7 điểm:', 'Nói lưu loát, phát âm tốt, tự tin trình bày câu trả lời hoàn chỉnh.'],
            ],
            [
                ['Mức 11 - 15 điểm:', 'Kỹ năng nghe xuất sắc, xử lý nhanh các bẫy thông tin và nắm trọn vẹn ngữ cảnh.'],
                ['Mức 11 - 15 điểm:', 'Ngữ pháp vững vàng, vốn từ vựng phong phú, đọc hiểu nhanh và viết câu chính xác.'],
                ['Mức 8 - 10 điểm:', 'Phản xạ tự nhiên như người bản xứ, mô tả tranh sinh động và lập luận logic.'],
            ],
        ],
        placementTitle: 'Quyết định Xếp lớp tự động (Khối 4 lên 5)',
        placements: [
            ['Tổng điểm 10 - 20', 'FAM 2 (NỬA ĐẦU)'],
            ['Tổng điểm 20 - 30', 'FAM 2 (NỬA SAU)'],
            ['Tổng điểm 30 - 40', 'LUYỆN THI MOVERS'],
        ],
    },
];
</script>

<template>
    <UiPageHeader title="Thang điểm & nhận xét tự động" :back="route('placement-tests.index')" description="Hệ thống quy chuẩn điểm số, nhận xét theo từng kỹ năng và gợi ý xếp lớp chuẩn Cambridge YLE (Starters - Movers)">
        <template #actions>
            <div class="hidden items-center gap-2 rounded-xl border border-white/10 bg-inverse-surface px-3.5 py-1.5 text-white shadow-xs sm:flex">
                <span class="material-symbols-outlined text-[18px] text-warning/70">verified</span>
                <div>
                    <p class="text-xs font-bold uppercase text-inverse-on-surface/70">Phiên bản quy chuẩn</p>
                    <p class="text-xs font-bold text-white">Cambridge YLE Starter - Movers</p>
                </div>
            </div>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <!-- Header Banner -->
        <div class="flex flex-col items-start justify-between gap-4 rounded-2xl border border-inverse-surface bg-gradient-to-r from-inverse-surface via-inverse-surface to-on-secondary-fixed p-6 text-white shadow-md md:flex-row md:items-center">
            <div class="space-y-1">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-primary/70">
                    <span class="material-symbols-outlined text-[18px]">psychology</span>
                    MEnglish CRM Academic Engine
                </div>
                <h2 class="text-2xl font-black tracking-tight">Quy Chuẩn Đánh Giá Năng Lực Đầu Vào</h2>
                <p class="max-w-2xl text-xs leading-relaxed text-inverse-on-surface/80">
                    Chấm theo khối lớp: Tổng = Nghe + Đọc &amp; Viết + Nói (điểm thô), tra tổng điểm ra lớp đề xuất; nhận xét từng kỹ năng gợi ý theo băng điểm (người chấm sửa được). Nói luôn nhập tay.
                    Khối chưa có thang (lớp 5–9, IELTS, người đi làm, mầm non): {{ noRubricNotice }}.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <UiButton icon="add_circle" :href="route('placement-tests.create')">Tạo đề thi mới</UiButton>
            </div>
        </div>

        <!-- Interactive Score Simulator Card -->
        <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
            <div class="flex items-center justify-between border-b border-surface-container-highest pb-3">
                <div class="flex items-center gap-2 text-sm font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary">calculate</span>
                    <h3>Công cụ Tính Điểm &amp; Tạo Nhận Xét Trực Tiếp (Live Simulator)</h3>
                </div>
                <UiBadge color="primary" pill>Dùng đúng thang điểm hệ thống</UiBadge>
            </div>

            <div class="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2 md:grid-cols-4">
                <UiSelect v-model="khoiKey" label="Chọn Khối Lớp" :options="groups" class="font-semibold" />
                <UiInput v-model="scoreL" type="number" label="Điểm Nghe (Listening)" step="0.5" min="0" class="font-mono font-bold" />
                <UiInput v-model="scoreR" type="number" label="Điểm Đọc & Viết (R&W)" step="0.5" min="0" class="font-mono font-bold" />
                <UiInput v-model="scoreS" type="number" label="Điểm Speaking (Nói)" step="0.5" min="0" class="font-mono font-bold" />
            </div>

            <div class="space-y-3 rounded-xl border border-surface-container-highest bg-surface-container-low p-4">
                <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                    <span class="text-xs font-bold uppercase text-on-surface-variant">Kết quả &amp; Nhận xét Sinh Tự Động:</span>
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-medium text-on-surface-variant">Tổng điểm: <strong class="font-mono text-base font-black text-primary">{{ result.totalScore }}</strong> <span class="font-mono text-on-surface-subtle">{{ '/ ' + result.maxTotal }}</span></span>
                        <span class="rounded-full bg-primary-container px-3 py-1 text-xs font-bold text-white shadow-xs">{{ result.placementCourse }}</span>
                    </div>
                </div>
                <UiTextarea :model-value="result.generatedComment" rows="3" readonly aria-label="Nhận xét tự động" class="font-sans leading-relaxed" />
            </div>
        </div>

        <!-- Rubric Navigation Tabs -->
        <div class="space-y-4">
            <div class="flex flex-wrap gap-2 border-b border-surface-container-highest pb-2">
                <button
                    v-for="tab in tabButtons"
                    :key="tab.key"
                    type="button"
                    :class="[
                        'flex cursor-pointer items-center gap-1.5 rounded-xl border px-4 py-2 text-xs font-semibold transition-all',
                        activeTab === tab.key ? 'border-primary-container bg-primary-container font-bold text-white shadow-xs' : 'border-surface-container-highest bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low',
                    ]"
                    @click="activeTab = tab.key"
                >
                    <span class="material-symbols-outlined text-[16px]">{{ tab.icon }}</span> {{ tab.label }}
                </button>
            </div>

            <div v-for="table in tables" v-show="activeTab === table.key" :key="table.key" class="space-y-4">
                <div class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                    <div class="flex items-center justify-between bg-inverse-surface px-5 py-3 text-white">
                        <h3 class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide">
                            <span class="material-symbols-outlined text-[18px] text-primary">grade</span>
                            {{ table.title }}
                        </h3>
                        <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase text-inverse-on-surface/80">{{ table.level }}</span>
                    </div>
                    <div class="overflow-x-auto p-4">
                        <table class="w-full border-collapse border border-surface-container-highest text-xs">
                            <thead>
                                <tr class="bg-inverse-surface text-xs font-bold uppercase text-white">
                                    <th v-for="[head, width] in table.heads" :key="head" :class="['border border-white/10 p-3 text-left', width]">{{ head }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-container-highest bg-surface-container-lowest">
                                <tr v-for="(row, rowIndex) in table.rows" :key="rowIndex" :class="rowIndex === 1 ? 'bg-surface-container-low/60' : ''">
                                    <td v-for="([level, text], cellIndex) in row" :key="cellIndex" class="border border-surface-container-highest p-3 align-top">
                                        <div class="mb-1 font-bold text-primary">{{ level }}</div>
                                        {{ text }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-warning/30 bg-warning/5 p-4">
                        <h4 class="mb-2 flex items-center gap-1 text-xs font-bold uppercase text-on-warning-container">
                            <span class="material-symbols-outlined text-[16px]">map</span> {{ table.placementTitle }}
                        </h4>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div v-for="[range, course] in table.placements" :key="range" class="rounded-xl border border-warning/30 bg-surface-container-lowest p-3 text-center">
                                <span class="block text-xs font-bold text-on-surface-variant">{{ range }}</span>
                                <span class="text-xs font-black text-on-warning-container">{{ course }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
