<script setup>
import { ref } from 'vue';
import { route } from '@/lib/route';

defineProps({ penalty: { type: Object, default: null } });
const emit = defineEmits(['close']);
const processing = ref(false);
</script>

<template>
    <UiModal :show="!!penalty" title="Chuyển phạt sang kỳ sau" max-width="md" :closeable="!processing" @close="emit('close')">
        <UiForm v-if="penalty" :key="penalty.id" :action="route('penalties.defer', penalty.id)" method="post" :preserve-scroll="true" class="space-y-md"
            @success="emit('close')" @finish="processing = false" @start="processing = true" #default="{ errors }">
            <p>Chuyển khoản phạt <strong>{{ penalty.code }}</strong> ({{ formatMoney(penalty.amount) }}) sang kỳ sau?</p>
            <p class="font-body-small text-body-small text-on-surface-variant">Biên bản vẫn giữ quyết định phạt. Sau khi chuyển, bấm “Đồng bộ &amp; Tính lại” ở kỳ hiện tại để cập nhật phiếu lương.</p>
            <UiAlert v-if="errors.penalty" type="error">{{ errors.penalty }}</UiAlert>
            <UiTextarea name="reason" label="Lý do chuyển kỳ" required maxlength="500" :rows="3" autofocus hint="Tối đa 500 ký tự. Lý do và người chuyển được lưu trên biên bản." />
            <div class="flex flex-wrap justify-end gap-sm">
                <UiButton variant="secondary" :disabled="processing" @click="emit('close')">Hủy</UiButton>
                <UiButton type="submit" icon="event_upcoming">Chuyển sang kỳ sau</UiButton>
            </div>
        </UiForm>
    </UiModal>
</template>
