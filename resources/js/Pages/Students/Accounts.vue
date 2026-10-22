<script setup>
/**
 * Tài khoản học viên (docs/tai-khoan-hoc-vien.md): học viên trong phạm vi (mặc định đang học), lọc cơ sở / lớp / có tài khoản
 * hay chưa; chọn nhiều dòng → "Tạo tài khoản" (chưa có) hoặc "Cấp lại mật khẩu tạm" (đã có). Sau mỗi lô có nút tải file
 * đăng nhập (.xlsx) — chỉ tải được một lần, mật khẩu tạm không hiện trên màn hình.
 */
import { computed, ref } from 'vue';

defineOptions({ layout: { title: 'Tài khoản học viên' } });

const props = defineProps({
    students: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statusOptions: { type: Array, default: () => [] },
    branchOptions: { type: Array, default: () => [] },
    classOptions: { type: Array, default: () => [] },
    download: { type: Object, default: null },
});

const accountOptions = [
    { value: 'none', label: 'Chưa có tài khoản' },
    { value: 'has', label: 'Đã có tài khoản' },
];

const selected = ref([]);
const selectedRows = computed(() => props.students.data.filter((s) => selected.value.includes(s.id)));
const createCount = computed(() => selectedRows.value.filter((s) => !s.login).length);
const resetCount = computed(() => selectedRows.value.filter((s) => s.login).length);
const allSelected = computed(() => props.students.data.length > 0 && selected.value.length === props.students.data.length);
function toggleAll(event) {
    selected.value = event.target.checked ? props.students.data.map((s) => s.id) : [];
}
const idsFor = (withAccount) => selectedRows.value.filter((s) => !!s.login === withAccount).map((s) => s.id);
</script>

<template>
    <UiPageHeader title="Tài khoản học viên" description="Tạo tài khoản cổng học viên, cấp lại mật khẩu tạm và tải file đăng nhập để gửi phụ huynh." :back="route('students.index')" back-label="Danh sách học viên" />

    <UiAlert v-if="download" type="success" class="mb-md" data-account-download>
        <div class="flex flex-wrap items-center justify-between gap-sm">
            <span>Có thông tin đăng nhập của <strong>{{ download.count }}</strong> học viên chờ tải. File chỉ tải được <strong>một lần</strong>.</span>
            <UiButton icon="download" :href="route('students.accounts.download')" native>Tải file đăng nhập (.xlsx)</UiButton>
        </div>
    </UiAlert>

    <UiFilterBar :action="route('students.accounts.index')" search="search" placeholder="Tìm tên hoặc mã học viên...">
        <UiSelect name="status" label="Trạng thái" :options="statusOptions" :value="filters.status" />
        <UiSelect name="account" label="Tài khoản" :options="accountOptions" :value="filters.account" placeholder="Tất cả" />
        <UiSelect name="branch_id" label="Chi nhánh" :options="branchOptions" :value="filters.branch_id" placeholder="Tất cả chi nhánh" />
        <UiSelect name="class_id" label="Lớp học" :options="classOptions" :value="filters.class_id" placeholder="Tất cả các lớp" />
    </UiFilterBar>

    <UiDataTable min-width="820px">
        <template #header>
            <h3 class="font-h3 text-h3 text-on-surface">Học viên <span class="font-body-small text-body-small text-on-surface-variant">({{ students.total }})</span></h3>
            <div class="flex flex-wrap items-center gap-sm">
                <UiForm :action="route('students.accounts.store')" method="post" @success="selected = []">
                    <input type="hidden" name="action" value="create" />
                    <input v-for="id in idsFor(false)" :key="id" type="hidden" name="student_ids[]" :value="id" />
                    <UiButton type="submit" icon="person_add" data-account-create v-bind="createCount === 0 ? { disabled: true } : {}">
                        Tạo tài khoản<span v-show="createCount > 0">&nbsp;({{ createCount }})</span>
                    </UiButton>
                </UiForm>
                <UiForm :action="route('students.accounts.store')" method="post" @success="selected = []">
                    <input type="hidden" name="action" value="reset" />
                    <input v-for="id in idsFor(true)" :key="id" type="hidden" name="student_ids[]" :value="id" />
                    <UiButton type="submit" variant="secondary" icon="lock_reset" data-account-reset v-bind="resetCount === 0 ? { disabled: true } : {}">
                        Cấp lại mật khẩu tạm<span v-show="resetCount > 0">&nbsp;({{ resetCount }})</span>
                    </UiButton>
                </UiForm>
            </div>
        </template>
        <table>
            <thead>
                <tr>
                    <th class="w-10"><input type="checkbox" aria-label="Chọn tất cả học viên trên trang" :checked="allSelected" class="rounded border-outline-variant text-primary-container" @change="toggleAll" /></th>
                    <th>Mã học viên</th>
                    <th>Họ tên</th>
                    <th>Lớp hiện tại</th>
                    <th>Chi nhánh</th>
                    <th>Tên đăng nhập</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="st in students.data" :key="st.id">
                    <td><input v-model="selected" type="checkbox" :value="st.id" :aria-label="`Chọn ${st.name}`" class="rounded border-outline-variant text-primary-container" /></td>
                    <td class="font-mono">{{ st.code }}</td>
                    <td class="font-semibold text-on-surface">{{ st.name }}</td>
                    <td>{{ st.class ?? '—' }}</td>
                    <td>{{ st.branch ?? '—' }}</td>
                    <td>
                        <span v-if="st.login" class="font-mono">{{ st.login }}</span>
                        <UiBadge v-else color="warning">Chưa có tài khoản</UiBadge>
                    </td>
                </tr>
                <tr v-if="!students.data.length">
                    <td colspan="6">
                        <UiEmptyState icon="manage_accounts" title="Không có học viên phù hợp" description="Danh sách chỉ gồm học viên thuộc chi nhánh / lớp bạn được phân quyền. Thử đổi bộ lọc." />
                    </td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="students" unit="học viên" /></template>
    </UiDataTable>
</template>
