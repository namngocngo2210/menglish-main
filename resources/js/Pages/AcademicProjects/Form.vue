<script setup>
/**
 * Tạo / sửa dự án học thuật (modal; mở thẳng URL → trang đầy đủ). Gồm thông tin chung, người phụ trách + thành viên
 * (ô tích có ô tìm), thời gian và nội dung họp thống nhất. Mốc tiến độ thêm ở trang chi tiết dự án.
 */
import { computed, ref } from 'vue';

defineOptions({ layout: (props) => ({ title: props.project ? 'Sửa dự án học thuật' : 'Tạo dự án học thuật' }) });

const props = defineProps({
    project: { type: Object, default: null },
    types: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    asModal: { type: Boolean, default: false },
});

const members = ref((props.project?.member_ids ?? []).map(String));
const ownerId = ref(props.project?.owner_id ? String(props.project.owner_id) : '');
const search = ref('');
const fold = (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLowerCase();
const shownStaff = computed(() => (search.value ? props.staff.filter((s) => fold(s.label).includes(fold(search.value))) : props.staff));
</script>

<template>
    <UiModalFrame
        :title="project ? `Sửa dự án ${project.code}` : 'Tạo dự án học thuật'"
        description="Sau khi tạo, thêm các mốc (deadline, khối lượng, người nhận) rồi bấm “Chốt tiến độ”."
        :action="project ? route('academic-projects.update', project.id) : route('academic-projects.store')"
        :method="project ? 'put' : 'post'"
        :back="project ? route('academic-projects.show', project.id) : route('academic-projects.index')"
        :submit-label="project ? 'Lưu dự án' : 'Tạo dự án'"
        size="2xl"
    >
        <div class="space-y-lg">
            <section class="space-y-md">
                <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                    <UiInput name="name" label="Tên dự án" required :value="project?.name" placeholder="VD: Soạn sách Tiếng Anh lớp 6" class="sm:col-span-2" />
                    <UiSelect name="type" label="Loại dự án" required :options="types" :value="project?.type ?? 'book'" />
                </div>
                <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                    <UiSelect v-model="ownerId" name="owner_id" label="Người phụ trách" required :options="staff" placeholder="-- Chọn người phụ trách --" searchable />
                    <UiDate name="start_date" label="Ngày bắt đầu" :value="project?.start_date" />
                    <UiDate name="deadline" label="Deadline dự án" :value="project?.deadline" />
                </div>
                <UiTextarea name="description" label="Mục tiêu / phạm vi" :rows="2" :value="project?.description" placeholder="VD: 12 unit, mỗi unit 8 trang, kèm audio và đáp án" />
            </section>

            <section class="space-y-sm">
                <div class="flex flex-wrap items-end justify-between gap-sm">
                    <div>
                        <h3 class="font-semibold text-on-surface">Thành viên tham gia</h3>
                        <p class="font-body-small text-body-small text-on-surface-variant">Đã chọn {{ members.length }} người. Người phụ trách và người nhận mốc tự được thêm.</p>
                    </div>
                    <UiInput v-model="search" icon="search" placeholder="Gõ để tìm…" aria-label="Tìm thành viên" class="w-full sm:w-56" />
                </div>
                <div class="grid max-h-56 grid-cols-1 gap-xs overflow-y-auto rounded-lg border border-outline-variant p-sm sm:grid-cols-2">
                    <UiCheckbox v-for="s in shownStaff" :key="s.value" v-model="members" name="member_ids[]" :value="String(s.value)" :label="s.label" />
                    <p v-if="!shownStaff.length" class="col-span-full py-sm text-center font-body-small text-body-small text-on-surface-variant">Không tìm thấy</p>
                </div>
                <!-- Thành viên đã chọn nhưng đang bị ô tìm ẩn vẫn phải gửi đi. -->
                <template v-for="id in members" :key="'keep' + id">
                    <input v-if="!shownStaff.some((s) => String(s.value) === id)" type="hidden" name="member_ids[]" :value="id" />
                </template>
            </section>

            <section class="space-y-md">
                <h3 class="font-semibold text-on-surface">Họp thống nhất</h3>
                <UiTextarea name="kickoff_notes" label="Nội dung đã thống nhất" :rows="4" :value="project?.kickoff_notes" placeholder="Phân công, chuẩn biên soạn, cách nghiệm thu, lịch họp định kỳ…" />
                <UiInput name="kickoff_link" type="url" label="Link biên bản họp" :value="project?.kickoff_link" placeholder="https://drive.google.com/…" />
            </section>
        </div>
    </UiModalFrame>
</template>
