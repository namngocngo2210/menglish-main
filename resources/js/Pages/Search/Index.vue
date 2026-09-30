<script setup>
/**
 * Tìm kiếm chung (ô tìm trên topbar): màn hình theo tên + khách CRM, học viên, lớp học theo tên / mã / SĐT.
 * Nhóm nào user không có quyền thì không có trong `searched` (GlobalSearchController).
 */
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Tìm kiếm' } });

const props = defineProps({
    term: { type: String, default: '' },
    searched: { type: Array, default: () => [] },
    screens: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
});

const tooShort = computed(() => [...props.term].length < 2);
const has = (group) => props.searched.includes(group);

function onSearch(event) {
    const q = new FormData(event.target).get('q');
    router.get(route('search'), q ? { q } : {});
}
</script>

<template>
    <UiPageHeader title="Tìm kiếm" description="Tìm màn hình theo tên, khách hàng, học viên và lớp học theo tên, mã hoặc số điện thoại (trong phạm vi bạn được xem)." />

    <form method="GET" :action="route('search')" role="search" class="mb-lg flex max-w-xl gap-sm" @submit.prevent="onSearch">
        <div class="flex-1">
            <UiInput :key="term" name="q" type="search" :value="term" icon="search" placeholder="Nhập tên, mã hoặc SĐT (ít nhất 2 ký tự)..." aria-label="Từ khóa tìm kiếm" />
        </div>
        <UiButton type="submit">Tìm</UiButton>
    </form>

    <div v-if="tooShort" class="rounded-xl border border-surface-container-highest bg-surface">
        <UiEmptyState icon="search" title="Nhập từ khóa để tìm kiếm" description="Từ khóa cần ít nhất 2 ký tự." />
    </div>
    <div v-else-if="!searched.length && !screens.length" class="rounded-xl border border-surface-container-highest bg-surface">
        <UiEmptyState icon="lock" title="Bạn chưa có quyền tìm kiếm" description="Tài khoản của bạn chưa được cấp quyền xem khách hàng, học viên hoặc lớp học." />
    </div>
    <div v-else-if="total === 0" class="rounded-xl border border-surface-container-highest bg-surface">
        <UiEmptyState icon="search_off" title="Không tìm thấy kết quả" :description="`Không có kết quả cho “${term}”. Thử từ khóa khác.`" />
    </div>
    <template v-else>
        <p class="mb-md font-body-medium text-body-medium text-on-surface-variant">Tìm thấy {{ total }} kết quả cho “<strong class="text-on-surface">{{ term }}</strong>”.</p>

        <div class="space-y-lg">
            <section v-if="screens.length" class="rounded-xl border border-surface-container-highest bg-surface p-md" data-search-screens>
                <h2 class="mb-sm font-h3 text-h3 text-on-surface">Màn hình ({{ screens.length }})</h2>
                <ul class="grid grid-cols-1 gap-xs sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="screen in screens" :key="screen.url + screen.title">
                        <Link :href="screen.url" class="flex items-center gap-sm rounded-lg px-sm py-xs font-body-medium text-body-medium text-primary hover:bg-surface-container-low">
                            <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">arrow_forward</span>
                            <span class="truncate">{{ screen.title }}</span>
                        </Link>
                    </li>
                </ul>
            </section>

            <UiDataTable v-if="has('customers') && customers.length">
                <template #header>
                    <h2 class="font-h3 text-h3 text-on-surface">Khách hàng CRM ({{ customers.length }})</h2>
                </template>
                <table>
                    <thead><tr><th>Khách hàng</th><th>SĐT</th><th>Cơ sở</th><th>Giai đoạn</th></tr></thead>
                    <tbody>
                        <tr v-for="customer in customers" :key="customer.id">
                            <td>
                                <Link :href="route('crm.customers.show', customer.id)" class="font-semibold text-primary hover:underline">{{ customer.name }}</Link>
                                <span class="block font-code text-caption text-on-surface-variant">{{ customer.code }}</span>
                            </td>
                            <td class="font-code">{{ customer.phone || '—' }}</td>
                            <td>{{ customer.branch ?? 'Chưa gán chi nhánh' }}</td>
                            <td>{{ customer.stage_label }}</td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>

            <UiDataTable v-if="has('students') && students.length">
                <template #header>
                    <h2 class="font-h3 text-h3 text-on-surface">Học viên ({{ students.length }})</h2>
                </template>
                <table>
                    <thead><tr><th>Học viên</th><th>SĐT</th><th>Lớp hiện tại</th><th>Trạng thái</th></tr></thead>
                    <tbody>
                        <tr v-for="student in students" :key="student.id">
                            <td>
                                <Link :href="route('students.show', student.id)" class="font-semibold text-primary hover:underline">{{ student.name }}</Link>
                                <span class="block font-code text-caption text-on-surface-variant">{{ student.code }}</span>
                            </td>
                            <td class="font-code">{{ student.phone || '—' }}</td>
                            <td>{{ student.class ?? 'Chưa xếp lớp' }}</td>
                            <td>{{ student.status_label }}</td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>

            <UiDataTable v-if="has('classes') && classes.length">
                <template #header>
                    <h2 class="font-h3 text-h3 text-on-surface">Lớp học ({{ classes.length }})</h2>
                </template>
                <table>
                    <thead><tr><th>Lớp</th><th>Cơ sở</th><th>Giáo viên</th><th>Trạng thái</th></tr></thead>
                    <tbody>
                        <tr v-for="klass in classes" :key="klass.id">
                            <td>
                                <Link :href="route('classes.show', klass.id)" class="font-semibold text-primary hover:underline">{{ klass.name }}</Link>
                                <span class="block font-code text-caption text-on-surface-variant">{{ klass.code }}</span>
                            </td>
                            <td>{{ klass.branch ?? 'Chưa gán chi nhánh' }}</td>
                            <td>{{ klass.teacher ?? 'Chưa phân công' }}</td>
                            <td>{{ klass.status_label }}</td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>
        </div>
    </template>
</template>
