<script setup>
/**
 * Mockup 03_Cong_Giao_Vien/09: Lịch sử đề xuất có lọc; form (giáo trình, buổi học tùy chọn, mô tả thay đổi)
 * mở bằng nút "Gửi đề xuất" (hộp thoại).
 */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import GetForm from '@/Components/Syllabus/GetForm.vue';

defineOptions({ layout: { title: 'Đề xuất sửa giáo trình' } });

const props = defineProps({
    proposals: { type: Object, required: true },
    curriculums: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    canPropose: { type: Boolean, default: false },
});

const page = usePage();
const proposalTypes = ['Sửa lỗi chính tả / ngữ pháp trong bài giảng', 'Cập nhật file audio / video bị lỗi', 'Thay đổi độ dài / thời gian bài tập', 'Bổ sung hoạt động / trò chơi tương tác', 'Khác'].map((t) => ({ value: t, label: t }));

const proposing = ref(false);
const curriculum = ref('');
const lessons = computed(() => props.curriculums.find((c) => String(c.id) === curriculum.value)?.lessons ?? []);
// Lỗi ở phần "Thông tin bổ sung" → mở sẵn khối này để người dùng thấy lỗi.
const extraErrors = computed(() => ['old_content', 'reason', 'attachment', 'proposal_type'].some((k) => page.props.errors?.[k]));

function sent() {
    proposing.value = false;
    curriculum.value = '';
}
</script>

<template>
    <UiPageHeader title="Đề xuất sửa giáo trình" description="Gửi đề xuất sửa lỗi hoặc nội dung giáo trình lên Ban Học thuật." :back="route('syllabus.teacher-view')">
        <template #actions>
            <UiButton variant="secondary" icon="checklist_rtl" :href="route('syllabus.versions')">Xem trạng thái đề xuất</UiButton>
            <UiButton variant="secondary" icon="speed" :href="route('syllabus.teacher-adjust')">Xin điều chỉnh tiến độ</UiButton>
            <UiButton v-if="canPropose" icon="edit_note" @click="proposing = true">Gửi đề xuất</UiButton>
        </template>
    </UiPageHeader>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="flex min-w-0 flex-col gap-4 lg:col-span-12">
            <UiDataTable min-width="640px">
                <template #header>
                    <div class="flex items-center gap-2">
                        <h2 class="font-h3 text-h3 text-on-surface">Lịch sử đề xuất</h2>
                        <UiBadge>{{ proposals.total }} đề xuất</UiBadge>
                    </div>
                    <GetForm class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px] text-on-surface-variant">filter_list</span>
                        <UiSelect name="status" placeholder="Lọc: tất cả trạng thái" :options="statusOptions" />
                    </GetForm>
                </template>
                <table>
                    <thead>
                        <tr>
                            <th>Giáo trình / Buổi</th>
                            <th>Nội dung đề xuất</th>
                            <th>Ngày gửi</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in proposals.data" :key="p.id">
                            <td>
                                <p class="font-body-medium text-body-medium text-on-surface">{{ p.curriculum }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ p.target }}</p>
                            </td>
                            <td class="max-w-xs">
                                <Link :href="route('syllabus.versions', { proposal: p.id })" class="line-clamp-2 text-on-surface hover:text-primary">{{ p.new_content }}</Link>
                            </td>
                            <td class="whitespace-nowrap font-mono text-on-surface-variant">{{ p.created_at }}</td>
                            <td class="whitespace-nowrap">
                                <UiBadge :color="p.status_color">{{ p.status_label }}</UiBadge>
                                <p v-if="p.status === 'rejected' && p.review_note" class="mt-1 max-w-[220px] whitespace-normal font-caption text-caption text-error">{{ p.review_note }}</p>
                            </td>
                        </tr>
                        <tr v-if="!proposals.data.length">
                            <td colspan="4"><UiEmptyState icon="edit_note" title="Bạn chưa gửi đề xuất nào" /></td>
                        </tr>
                    </tbody>
                </table>
                <template #footer><UiPagination :paginator="proposals" unit="đề xuất" /></template>
            </UiDataTable>
        </div>
    </div>

    <UiModal v-if="canPropose" :show="proposing" title="Gửi đề xuất sửa giáo trình" max-width="xl" @close="proposing = false">
        <UiForm id="new-proposal-form" :action="route('syllabus.proposals.store')" method="post" class="flex flex-col gap-md" reset-on-success @success="sent">
            <UiSelect id="propose_curriculum_id" v-model="curriculum" label="Chọn giáo trình" name="curriculum_id" required placeholder="Chọn giáo trình...">
                <option v-for="c in curriculums" :key="c.id" :value="String(c.id)" :selected="String(c.id) === curriculum">{{ c.label }}</option>
            </UiSelect>

            <UiSelect :key="curriculum" label="Chọn buổi học (Tùy chọn)" name="lesson_id" value="" hint="Bỏ trống nếu đề xuất áp dụng chung cho cả giáo trình." placeholder="Chọn buổi học..." :options="lessons" />

            <UiTextarea name="new_content" label="Mô tả thay đổi đề xuất" rows="5" required placeholder="Nhập chi tiết nội dung cần sửa đổi..." />

            <details class="group rounded-lg border border-outline-variant bg-surface-container-low" :open="extraErrors || undefined">
                <summary class="flex cursor-pointer list-none items-center justify-between px-md py-sm font-body-medium text-body-medium text-on-surface">
                    Thông tin bổ sung (tùy chọn)
                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant transition group-open:rotate-180">expand_more</span>
                </summary>
                <div class="flex flex-col gap-md border-t border-outline-variant p-md">
                    <UiSelect name="proposal_type" label="Loại đề xuất" value="" placeholder="-- Chọn loại --" :options="proposalTypes" />
                    <UiTextarea name="old_content" label="Nội dung hiện tại trong giáo trình" rows="2" />
                    <UiTextarea name="reason" label="Lý do thay đổi" rows="2" />
                    <UiField label="File đính kèm" name="attachment" hint="PDF, Word, PowerPoint, Excel, ảnh hoặc audio — tối đa 20 MB.">
                        <input type="file" name="attachment" class="block w-full rounded-xl border border-dashed border-outline-variant p-2 text-xs text-on-surface-variant file:mr-3 file:rounded-lg file:border-0 file:bg-primary-container/10 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-primary" />
                    </UiField>
                </div>
            </details>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="proposing = false">Hủy</UiButton>
            <UiButton type="submit" form="new-proposal-form" icon="send">Gửi đề xuất</UiButton>
        </template>
    </UiModal>
</template>
