<script setup>
defineOptions({ layout: { title: 'Cấu hình phạt việc lặp' } });

defineProps({ series: { type: Object, required: true } });
</script>

<template>
    <UiPageHeader title="Cấu hình phạt việc lặp" :back="route('tasks.index')" description="Một mức phạt cố định cho mỗi việc lặp. Nhập 0 để chạy tiếp mà không phạt quá hạn." />
    <UiAlert type="info" class="mb-md">
        Việc chưa cấu hình tạm dừng sinh lượt kế tiếp. Khi lưu, nếu không còn lượt đang mở, hệ thống tạo một lượt có ngày hạn từ hôm nay trở đi, không tạo bù các kỳ đã bỏ lỡ.
    </UiAlert>
    <UiDataTable min-width="900px">
        <table>
            <thead>
                <tr>
                    <th>Tiêu đề</th>
                    <th>Người nhận</th>
                    <th>Tần suất</th>
                    <th>Trạng thái</th>
                    <th>Mức phạt hiện tại</th>
                    <th>Cấu hình mức phạt</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="item in series.data" :key="item.id">
                    <td class="font-semibold">{{ item.title }}</td>
                    <td>{{ item.assignee ?? 'Chưa phân công' }}</td>
                    <td class="whitespace-nowrap">{{ item.frequency }}</td>
                    <td><UiBadge :color="item.configured ? 'success' : 'warning'">{{ item.configured ? 'Đang chạy' : 'Tạm dừng – chưa cấu hình' }}</UiBadge></td>
                    <td class="whitespace-nowrap font-code">{{ item.penalty_amount === null ? '—' : formatMoney(item.penalty_amount) }}</td>
                    <td>
                        <UiForm :action="route('tasks.series.update', item.id)" method="post" :error-bag="`series-${item.id}`" class="flex items-start gap-sm">
                            <UiInput
                                :id="`series-amount-${item.id}`"
                                name="penalty_amount"
                                :bag="`series-${item.id}`"
                                label="Số tiền phạt (VNĐ)"
                                type="number"
                                :aria-label="`Số tiền phạt cho ${item.title}`"
                                :value="item.penalty_amount"
                                required
                                min="0"
                                max="100000000"
                                step="0.01"
                                suffix="đ"
                                class="min-w-[10rem]"
                            />
                            <UiButton type="submit" icon="save">Lưu</UiButton>
                        </UiForm>
                    </td>
                </tr>
                <tr v-if="!series.data.length">
                    <td colspan="6"><UiEmptyState icon="repeat" title="Chưa có việc lặp" description="Tạo việc lặp để cấu hình mức phạt tại đây." /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="series" unit="việc lặp" /></template>
    </UiDataTable>
</template>
