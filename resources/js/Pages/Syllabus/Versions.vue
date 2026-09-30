<script setup>
/**
 * Duyệt đề xuất sửa giáo trình: danh sách đề xuất — bấm dòng → chi tiết trong hộp thoại (?proposal=; đóng thì bỏ query).
 * Mockup 01_Web_Admin/05: Thông tin chung + Nội dung thay đổi | Trạng thái phê duyệt (phản hồi, lịch sử, Phê duyệt / Từ chối).
 */
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import GetForm from '@/Components/Syllabus/GetForm.vue';

defineOptions({ layout: { title: 'Đề xuất sửa giáo trình' } });

const props = defineProps({
    proposals: { type: Object, required: true },
    selected: { type: Object, default: null },
    pendingCount: { type: Number, default: 0 },
    statusOptions: { type: Array, default: () => [] },
    listUrl: { type: String, required: true },
    canReview: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
});

const open = ref(!!props.selected);
watch(() => props.selected?.id, (id) => (open.value = !!id));

// Một ô phản hồi dùng cho cả hai nút: "Phê duyệt" gửi form chứa ô, "Từ chối" gửi form riêng kèm cùng nội dung.
const reviewNote = ref('');
</script>

<template>
    <UiPageHeader title="Đề xuất sửa giáo trình" :back="route('syllabus.documents')">
        <template #breadcrumbs>
            <span>Quản lý giáo trình</span>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            <span class="font-semibold text-on-surface">Đề xuất sửa giáo trình</span>
        </template>
        <template #actions>
            <UiButton variant="secondary" icon="edit_attributes" :href="route('syllabus.teacher-propose')">Gửi đề xuất</UiButton>
        </template>
    </UiPageHeader>

    <UiDataTable min-width="760px">
        <template #header>
            <div class="flex items-center gap-2">
                <h2 class="font-h3 text-h3 text-on-surface">Đề xuất</h2>
                <UiBadge color="warning">{{ pendingCount }} chờ duyệt</UiBadge>
            </div>
            <GetForm>
                <UiSelect name="status" placeholder="Tất cả trạng thái" :options="statusOptions" aria-label="Lọc trạng thái" />
            </GetForm>
        </template>
        <table>
            <thead>
                <tr>
                    <th>Giáo trình</th>
                    <th>Buổi học / Unit</th>
                    <th>Người đề xuất</th>
                    <th>Gửi lúc</th>
                    <th>Trạng thái</th>
                    <th class="text-right"><span class="sr-only">Thao tác</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in proposals.data" :key="p.id" :data-href="p.detail_url" :class="['cursor-pointer', { 'bg-primary-fixed/30': selected?.id === p.id }]">
                    <td><Link :href="p.detail_url" class="font-semibold text-on-surface hover:text-primary">{{ p.curriculum }}</Link></td>
                    <td class="text-on-surface-variant">{{ p.target_label }}</td>
                    <td>{{ p.proposer ?? '—' }}</td>
                    <td class="whitespace-nowrap font-code text-body-small">{{ p.created_at }}</td>
                    <td class="whitespace-nowrap"><UiBadge :color="p.status_color">{{ p.status_label }}</UiBadge></td>
                    <td class="text-right"><UiButton variant="secondary" size="sm" icon="visibility" :href="p.detail_url">Xem</UiButton></td>
                </tr>
                <tr v-if="!proposals.data.length">
                    <td colspan="6"><UiEmptyState icon="inbox" title="Chưa có đề xuất nào" description="Giáo viên gửi đề xuất từ Xin duyệt › Đề xuất sửa giáo trình." /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="proposals" :options="[]" unit="đề xuất" /></template>
    </UiDataTable>

    <UiModal v-if="selected" :show="open" :title="`Chi tiết đề xuất #${selected.id} · ${selected.curriculum ?? ''}`" max-width="4xl" :dismiss-url="listUrl" @close="open = false">
        <div class="grid grid-cols-1 gap-lg lg:grid-cols-3">
            <!-- Thông tin + nội dung thay đổi -->
            <div class="min-w-0 space-y-lg lg:col-span-2">
                <section class="space-y-md">
                    <div class="flex items-center gap-sm">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">info</span>
                        <h3 class="font-h3 text-h3 text-on-surface">Thông tin chung</h3>
                    </div>
                    <dl class="grid grid-cols-1 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md md:grid-cols-2">
                        <div>
                            <dt class="font-label text-label uppercase text-on-surface-variant">Giáo trình</dt>
                            <dd class="mt-xs text-on-surface">{{ selected.curriculum }} ({{ selected.curriculum_version }})</dd>
                        </div>
                        <div>
                            <dt class="font-label text-label uppercase text-on-surface-variant">Buổi học/Unit cần sửa</dt>
                            <dd class="mt-xs text-on-surface">{{ selected.target_label }}</dd>
                        </div>
                        <div class="md:col-span-2">
                            <dt class="font-label text-label uppercase text-on-surface-variant">Người đề xuất</dt>
                            <dd class="mt-xs flex items-center gap-sm">
                                <UiAvatar :name="selected.proposer" size="sm" />
                                <span>
                                    <span class="block font-body-medium text-body-medium text-on-surface">{{ selected.proposer ?? '—' }}</span>
                                    <span class="block font-caption text-caption text-on-surface-variant">{{ selected.proposer_roles || '—' }}</span>
                                </span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="space-y-md">
                    <div class="flex items-center gap-sm">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">edit_document</span>
                        <h3 class="font-h3 text-h3 text-on-surface">Nội dung thay đổi chi tiết</h3>
                        <UiBadge v-if="selected.proposal_type" class="ml-auto">{{ selected.proposal_type }}</UiBadge>
                    </div>
                    <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                        <div>
                            <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Nội dung cũ</p>
                            <div class="min-h-[72px] whitespace-pre-line rounded-lg border border-error/20 bg-error/5 p-md font-body-small text-body-small text-on-surface">{{ selected.old_content || '—' }}</div>
                        </div>
                        <div>
                            <p class="mb-xs font-label text-label uppercase text-primary">Nội dung mới đề xuất</p>
                            <div class="min-h-[72px] whitespace-pre-line rounded-lg border border-tertiary/20 bg-tertiary/5 p-md font-body-small text-body-small font-medium text-on-surface">{{ selected.new_content }}</div>
                        </div>
                    </div>
                    <div>
                        <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Lý do thay đổi</p>
                        <div class="whitespace-pre-line rounded-lg border border-outline-variant p-md font-body-small text-body-small text-on-surface">{{ selected.reason || '—' }}</div>
                    </div>
                    <a v-if="selected.attachment_name" :href="route('syllabus.proposals.attachment', selected.id)" class="inline-flex items-center gap-1.5 font-body-small text-body-small font-semibold text-primary hover:underline">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">attach_file</span>{{ selected.attachment_name }}
                    </a>
                </section>
            </div>

            <!-- Trạng thái & phê duyệt -->
            <section class="min-w-0 space-y-lg rounded-lg border border-outline-variant bg-surface-container-low p-md">
                <div class="flex items-center gap-sm">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">verified_user</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Trạng thái phê duyệt</h3>
                </div>
                <div>
                    <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Trạng thái hiện tại</p>
                    <UiBadge :color="selected.status_color">{{ selected.status_label }}</UiBadge>
                </div>
                <div>
                    <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Người phê duyệt</p>
                    <div class="flex items-center gap-sm">
                        <template v-if="selected.reviewer">
                            <UiAvatar :name="selected.reviewer" size="sm" />
                            <div>
                                <p class="font-body-medium text-body-medium text-on-surface">{{ selected.reviewer }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">Ban Học thuật</p>
                            </div>
                        </template>
                        <template v-else>
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-surface-container-high text-on-surface-variant"><span class="material-symbols-outlined text-[18px]">person</span></span>
                            <div>
                                <p class="font-body-medium text-body-medium text-on-surface">Chưa phân công</p>
                                <p class="font-caption text-caption text-on-surface-variant">Ban Học thuật</p>
                            </div>
                        </template>
                    </div>
                </div>

                <template v-if="canReview && selected.status === 'pending'">
                    <UiForm id="proposal-review-form" :action="route('syllabus.proposals.approve', selected.id)" method="post">
                        <UiTextarea v-model="reviewNote" name="review_note" label="Phản hồi từ người duyệt" rows="4" placeholder="Nhập lý do phê duyệt hoặc từ chối đề xuất này..." hint="Bắt buộc khi từ chối." />
                    </UiForm>
                    <UiForm id="proposal-reject-form" :action="route('syllabus.proposals.reject', selected.id)" method="post" class="hidden">
                        <input type="hidden" name="review_note" :value="reviewNote" />
                    </UiForm>
                </template>
                <div v-else-if="selected.review_note">
                    <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Phản hồi từ người duyệt</p>
                    <div class="whitespace-pre-line rounded-lg border border-outline-variant bg-surface-container-lowest p-md font-body-small text-body-small text-on-surface">{{ selected.review_note }}</div>
                </div>

                <div>
                    <p class="mb-sm font-label text-label uppercase text-on-surface-variant">Lịch sử xử lý</p>
                    <ol class="relative space-y-md border-l-2 border-outline-variant pl-md">
                        <li class="relative">
                            <span class="absolute -left-[23px] top-1 h-3 w-3 rounded-full bg-primary-container ring-2 ring-white"></span>
                            <p class="font-body-medium text-body-small font-semibold text-on-surface">Đã tạo đề xuất</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ selected.created_at }} - {{ selected.proposer }}</p>
                        </li>
                        <li class="relative">
                            <span :class="['absolute -left-[23px] top-1 h-3 w-3 rounded-full ring-2 ring-white', selected.status === 'pending' ? 'bg-outline-variant' : selected.status === 'approved' ? 'bg-tertiary' : 'bg-error']"></span>
                            <template v-if="selected.status === 'pending'">
                                <p class="font-body-medium text-body-small text-on-surface-variant">Đang chờ xử lý</p>
                                <p class="font-caption text-caption text-on-surface-variant">Hệ thống đang chờ phê duyệt...</p>
                            </template>
                            <template v-else>
                                <p class="font-body-medium text-body-small font-semibold text-on-surface">{{ selected.status_label }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ selected.reviewed_at }} - {{ selected.reviewer }}</p>
                            </template>
                        </li>
                    </ol>
                </div>

                <template v-if="selected.status === 'approved'">
                    <!-- Chưa tự áp nội dung vào buổi học (chưa có quyết định BA) — Học thuật cập nhật tay ở màn Soạn syllabus. -->
                    <UiAlert type="info">Nội dung được duyệt chưa tự áp vào giáo trình — Học thuật cập nhật buổi học ở màn Soạn syllabus.</UiAlert>
                    <UiButton v-if="selected.edit_lesson_url && canManage" variant="secondary" icon="edit" class="w-full" :href="selected.edit_lesson_url">Mở buổi để cập nhật</UiButton>
                </template>
            </section>
        </div>

        <template v-if="canReview && selected.status === 'pending'" #footer>
            <UiButton type="submit" form="proposal-reject-form" variant="danger-text" icon="close">Từ chối</UiButton>
            <UiButton type="submit" form="proposal-review-form" icon="check_circle">Phê duyệt</UiButton>
        </template>
    </UiModal>
</template>
