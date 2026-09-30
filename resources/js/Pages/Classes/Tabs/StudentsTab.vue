<script setup>
/** Trang lớp · Học viên: danh sách xếp lớp chính thức (SĐT chỉ hiện — và chỉ được gửi xuống — với người quản lý lớp). */
defineProps({
    klass: { type: Object, required: true },
    students: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});
</script>

<template>
    <UiDataTable>
        <template #header>
            <div>
                <h2 class="text-base font-bold text-on-surface">Danh sách học sinh</h2>
                <p class="text-xs text-on-surface-variant">Danh sách xếp lớp chính thức của lớp {{ klass.code }}</p>
            </div>
            <div class="flex items-center gap-2">
                <UiBadge color="primary" :dot="false" pill>{{ students.length }} học sinh</UiBadge>
                <UiButton v-if="canManage && can('student.view')" variant="secondary" size="sm" icon="how_to_reg" :href="route('students.enrollments')">Xác nhận nhập học</UiButton>
            </div>
        </template>
        <table class="text-xs">
            <thead>
                <tr>
                    <th class="w-12 text-center">STT</th>
                    <th>Họ và tên</th>
                    <th>Ngày sinh</th>
                    <th>Trường học</th>
                    <th>Địa chỉ</th>
                    <th>Tên phụ huynh</th>
                    <th v-if="canManage" class="font-bold text-primary">SĐT</th>
                    <th>Ghi chú</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(st, idx) in students" :key="st.id">
                    <td class="text-center font-bold text-on-surface-subtle">{{ idx + 1 }}</td>
                    <td>
                        <div class="font-bold text-on-surface">{{ st.name }}</div>
                        <div class="font-mono text-xs text-on-surface-subtle">{{ st.code ?? '—' }}</div>
                    </td>
                    <td class="font-mono text-on-surface-variant">{{ st.dob ?? '—' }}</td>
                    <td class="text-on-surface-variant">{{ st.target ?? '—' }}</td>
                    <td class="max-w-[200px] truncate text-on-surface-variant">{{ st.address ?? '—' }}</td>
                    <td class="font-medium text-on-surface">{{ st.parent_name ?? '—' }}</td>
                    <td v-if="canManage" class="font-mono font-bold text-on-surface">{{ st.phone ?? '—' }}</td>
                    <td class="text-on-surface-variant">{{ st.notes ?? '—' }}</td>
                </tr>
                <tr v-if="!students.length">
                    <td colspan="8"><UiEmptyState icon="group_off" title="Chưa có học sinh nào trong lớp." /></td>
                </tr>
            </tbody>
        </table>
    </UiDataTable>
</template>
