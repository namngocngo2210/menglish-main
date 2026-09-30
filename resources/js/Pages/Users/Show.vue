<script setup>
/**
 * Chi tiết nhân sự. Sửa thông tin / Tải lên HĐ mới (modal 3xl, mở sẵn tab Hợp đồng & Lương), Phân quyền chi tiết (modal 4xl),
 * Gán vai trò (modal md) đều mở trong modal; lưu xong trang tự cập nhật tại chỗ.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    user: { type: Object, required: true },
    canViewSensitive: { type: Boolean, default: false },
    teachingClasses: { type: Array, default: () => [] },
});

defineOptions({ layout: { title: 'Chi tiết nhân sự' } });

const page = usePage();
const empty = 'Chưa cập nhật';
const hidden = 'Không có quyền xem';
const firstError = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);
const hasMoney = (v) => v !== null && v !== undefined && parseFloat(v) > 0;
const isBlank = (v) => v === null || v === undefined || String(v).trim() === '';
const money = computed(() => [
    ['Lương cơ bản', props.user.base_salary],
    ['Thù lao giờ dạy', props.user.hourly_rate],
]);
</script>

<template>
    <div id="user-detail">
        <UiPageHeader :title="user.name" :back="route('users.index')">
            <template #badges>
                <UiBadge v-if="user.locked" color="error">Vô hiệu hóa</UiBadge>
                <UiBadge v-else color="success">Đang hoạt động</UiBadge>
            </template>
            <template #meta><span class="break-all font-code">{{ user.employee_code }} · {{ user.email }} · {{ user.branch_name ?? 'Chưa gán chi nhánh' }}</span></template>
            <template v-if="canAny('user.update', 'permission.override')" #actions>
                <UiButton v-if="can('user.update')" variant="secondary" icon="edit" :href="route('users.edit', user.id)" modal="3xl">Sửa thông tin</UiButton>
                <UiButton v-if="can('permission.override')" icon="admin_panel_settings" :href="route('users.permissions.edit', user.id)" modal="4xl">Phân quyền chi tiết</UiButton>
            </template>
        </UiPageHeader>

        <div class="space-y-lg">
            <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>

            <!-- Thẻ tóm tắt -->
            <section class="flex flex-col gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg md:flex-row md:items-center md:justify-between">
                <div class="flex min-w-0 items-center gap-md">
                    <UiAvatar :name="user.name" size="lg" />
                    <div class="min-w-0 space-y-xs">
                        <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ user.primary_role_label ?? 'Người dùng' }}</p>
                        <div class="flex flex-wrap items-center gap-x-md gap-y-xs font-body-small text-body-small text-on-surface-variant">
                            <span class="inline-flex min-w-0 items-center gap-xs"><span class="material-symbols-outlined shrink-0 text-[16px]" aria-hidden="true">mail</span><span class="break-all">{{ user.email }}</span></span>
                            <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">call</span><span :class="user.phone ? 'font-code' : 'italic'">{{ user.phone ?? empty }}</span></span>
                            <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">apartment</span>{{ user.branch_name ?? 'Chưa gán chi nhánh' }}</span>
                        </div>
                    </div>
                </div>
                <dl class="flex min-w-0 items-start gap-lg border-t border-surface-container pt-md md:shrink-0 md:border-l md:border-t-0 md:pl-lg md:pt-0">
                    <div class="min-w-0">
                        <dt class="font-label text-label uppercase text-on-surface-variant">Mã NV</dt>
                        <dd class="break-all font-code font-semibold text-on-surface">{{ user.employee_code }}</dd>
                    </div>
                    <div>
                        <dt class="whitespace-nowrap font-label text-label uppercase text-on-surface-variant">Lương cơ bản</dt>
                        <dd>
                            <span v-if="!canViewSensitive" class="italic text-on-surface-variant">Ẩn</span>
                            <UiMoney v-else-if="hasMoney(user.base_salary)" :value="user.base_salary" align="left" tone="primary" class="font-semibold" />
                            <span v-else class="font-body-small text-body-small italic text-on-surface-variant">{{ empty }}</span>
                        </dd>
                    </div>
                </dl>
            </section>

            <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-3">
                <div class="space-y-lg lg:col-span-2">
                    <!-- Hồ sơ nhân sự -->
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                        <header class="flex items-center justify-between gap-sm border-b border-surface-container px-lg py-md">
                            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">badge</span>Hồ sơ người dùng</h2>
                            <UiButton v-if="can('user.update')" variant="ghost" size="sm" icon="edit" :href="route('users.edit', { user: user.id, tab: 'profile' })" modal="3xl">Cập nhật hồ sơ</UiButton>
                        </header>
                        <dl class="grid grid-cols-1 gap-x-lg gap-y-md p-lg font-body-small text-body-small sm:grid-cols-2">
                            <div v-for="([label, value, code], i) in user.profile" :key="label" :class="i === user.profile.length - 1 ? 'sm:col-span-2' : ''">
                                <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">{{ label }}</dt>
                                <dd v-if="value === false" class="italic text-on-surface-variant">{{ hidden }}</dd>
                                <dd v-else-if="isBlank(value)" class="italic text-on-surface-variant">{{ empty }}</dd>
                                <dd v-else :class="['font-medium text-on-surface', code ? 'font-code' : '']">{{ value }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Hợp đồng lao động -->
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                        <header class="flex items-center justify-between gap-sm border-b border-surface-container px-lg py-md">
                            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-secondary" aria-hidden="true">contract</span>Hợp đồng lao động</h2>
                            <UiBadge :color="user.contract_status[1]" :dot="false">{{ user.contract_status[0] }}</UiBadge>
                        </header>
                        <dl class="grid grid-cols-2 gap-x-lg gap-y-md p-lg font-body-small text-body-small md:grid-cols-3">
                            <div>
                                <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">Loại hợp đồng</dt>
                                <dd :class="user.contract_type ? 'font-medium text-on-surface' : 'italic text-on-surface-variant'">{{ user.contract_type ?? empty }}</dd>
                            </div>
                            <div v-for="[label, amount] in money" :key="label">
                                <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">{{ label }}</dt>
                                <dd>
                                    <span v-if="!canViewSensitive" class="italic text-on-surface-variant">{{ hidden }}</span>
                                    <UiMoney v-else-if="hasMoney(amount)" :value="amount" align="left" class="font-semibold" />
                                    <span v-else class="italic text-on-surface-variant">{{ empty }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">Ngày bắt đầu</dt>
                                <dd :class="user.contract_start_date ? 'font-code text-on-surface' : 'italic text-on-surface-variant'">{{ user.contract_start_date ?? empty }}</dd>
                            </div>
                            <div>
                                <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">Ngày kết thúc</dt>
                                <dd :class="user.contract_end_date ? 'font-code text-on-surface' : 'italic text-on-surface-variant'">{{ user.contract_end_date ?? empty }}</dd>
                            </div>
                            <div>
                                <dt class="mb-xs font-label text-label uppercase text-on-surface-variant">Thời hạn còn lại</dt>
                                <dd v-if="user.contract_remaining" :class="['font-semibold', user.contract_remaining[1]]">{{ user.contract_remaining[0] }}</dd>
                                <dd v-else class="italic text-on-surface-variant">{{ empty }}</dd>
                            </div>
                        </dl>
                        <footer class="flex flex-col gap-sm border-t border-surface-container px-lg py-md sm:flex-row sm:items-center sm:justify-between">
                            <span v-if="user.has_contract_file" class="inline-flex items-center gap-xs font-body-small text-body-small text-on-surface"><span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">description</span>Đã có file hợp đồng</span>
                            <span v-else class="inline-flex items-center gap-xs font-body-small text-body-small italic text-on-surface-variant"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">description</span>Chưa có file hợp đồng</span>
                            <div class="flex flex-wrap gap-sm">
                                <UiButton v-if="user.has_contract_file" variant="secondary" size="sm" icon="download" :href="route('users.contract.download', user.id)" native>Tải hợp đồng</UiButton>
                                <UiButton v-if="can('user.update')" variant="secondary" size="sm" icon="upload_file" :href="route('users.edit', { user: user.id, tab: 'salary' })" modal="3xl">{{ user.has_contract_file ? 'Tải lên HĐ mới' : 'Tải lên hợp đồng' }}</UiButton>
                            </div>
                        </footer>
                    </section>
                </div>

                <div class="space-y-lg">
                    <!-- Vai trò, kiêm nhiệm & lớp phụ trách -->
                    <section id="user-roles-card" class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                        <header class="flex items-center justify-between gap-sm border-b border-surface-container px-lg py-md">
                            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-secondary" aria-hidden="true">co_present</span>Kiêm nhiệm &amp; lớp</h2>
                            <UiButton v-if="can('user.assign_role')" variant="ghost" size="sm" icon="add" :href="route('users.roles.edit', user.id)" modal="md">Thêm</UiButton>
                        </header>
                        <div class="space-y-md p-lg">
                            <div class="flex flex-wrap gap-xs">
                                <UiBadge color="primary" :dot="false" title="Vai trò chính">{{ user.primary_role_short ?? 'Người dùng' }}</UiBadge>
                                <UiBadge v-for="extra in user.extra_roles" :key="extra" color="secondary" :dot="false">Kiêm nhiệm: {{ extra }}</UiBadge>
                                <span v-if="!user.extra_roles.length" class="font-caption text-caption italic text-on-surface-variant">Không kiêm nhiệm vai trò khác</span>
                            </div>

                            <ul class="space-y-sm">
                                <li v-for="tc in teachingClasses" :key="tc.id" class="flex items-center justify-between gap-sm rounded-lg bg-surface-container-low px-md py-sm">
                                    <div class="min-w-0">
                                        <p class="truncate font-body-small text-body-small font-semibold text-on-surface" :title="`${tc.code} — ${tc.name}`">{{ tc.code }} — {{ tc.name }}</p>
                                        <p class="font-code text-caption text-on-surface-variant">{{ tc.start_date }} – {{ tc.end_date }}</p>
                                    </div>
                                    <UiBadge color="secondary" :dot="false" class="shrink-0">{{ tc.role }}</UiBadge>
                                </li>
                                <li v-if="!teachingClasses.length" class="py-sm text-center font-body-small text-body-small italic text-on-surface-variant">Chưa phụ trách lớp nào.</li>
                            </ul>

                            <p class="flex items-start gap-xs font-caption text-caption text-on-surface-variant">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span>
                                Kiêm nhiệm không phát sinh thêm lương cơ bản; tính theo giờ dạy thực tế.
                            </p>
                        </div>
                    </section>

                    <!-- Thao tác tài khoản -->
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                        <header class="border-b border-surface-container px-lg py-md">
                            <h2 class="font-h3 text-h3 text-on-surface">Thao tác tài khoản</h2>
                        </header>
                        <div class="flex flex-col gap-sm p-lg">
                            <UiButton v-if="can('user.update')" variant="secondary" icon="edit" :href="route('users.edit', user.id)" modal="3xl" class="w-full">Sửa thông tin</UiButton>
                            <UiButton v-if="can('user.assign_role')" variant="secondary" icon="badge" :href="route('users.roles.edit', user.id)" modal="md" class="w-full">Gán vai trò chức vụ</UiButton>
                            <UiButton v-if="can('permission.override')" variant="secondary" icon="admin_panel_settings" :href="route('users.permissions.edit', user.id)" modal="4xl" class="w-full">Phân quyền chi tiết</UiButton>
                            <UiForm v-if="can('user.reset_password')" :action="route('users.reset-password', user.id)" method="post" :confirm="`Đặt lại mật khẩu cho ${user.name}?`">
                                <UiButton type="submit" variant="secondary" icon="key" class="w-full">Đặt lại mật khẩu</UiButton>
                            </UiForm>
                            <UiForm v-if="can('user.lock')" :action="user.locked ? route('users.unlock', user.id) : route('users.lock', user.id)" method="post" :confirm="`${user.locked ? 'Kích hoạt lại' : 'Vô hiệu hóa'} tài khoản ${user.name}?`">
                                <UiButton type="submit" :variant="user.locked ? 'secondary' : 'danger-text'" :icon="user.locked ? 'check_circle' : 'block'" class="w-full">
                                    {{ user.locked ? 'Kích hoạt lại tài khoản' : 'Vô hiệu hóa tài khoản' }}
                                </UiButton>
                            </UiForm>
                            <p v-if="!canAny('user.update', 'user.assign_role', 'permission.override', 'user.reset_password', 'user.lock')" class="py-sm text-center font-body-small text-body-small italic text-on-surface-variant">Bạn chỉ có quyền xem thông tin.</p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</template>
