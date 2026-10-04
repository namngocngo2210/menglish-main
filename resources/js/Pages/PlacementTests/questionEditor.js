/**
 * Tiện ích soạn câu hỏi đề test đầu vào — dùng chung cho Create.vue và Edit.vue.
 */
import { ref } from 'vue';
import { firstError, postForm } from '@/lib/http';
import { route } from '@/lib/route';
import { toast } from '@/lib/toast';

/** 4 phương án trống A–D của câu trắc nghiệm. */
export function emptyOptions() {
    return ['A', 'B', 'C', 'D'].map((key) => ({ key, text: '' }));
}

/** Giống x-model.number của Alpine: chuỗi số → số, còn lại giữ nguyên. */
export function toNumber(value) {
    const number = parseFloat(value);
    return Number.isNaN(number) ? value : number;
}

/**
 * Gắn ô nhập (UiInput / UiTextarea) với một trường của câu hỏi như x-model, kể cả khi câu hỏi cũ chưa có trường đó
 * (không thêm khoá rỗng vào JSON khi người dùng chưa nhập).
 *   <UiInput v-bind="model(q, 'rubric_note')" />      <UiInput type="number" v-bind="model(q, 'points', toNumber)" />
 */
export function model(target, key, cast = (value) => value) {
    return {
        modelValue: target[key] ?? '',
        'onUpdate:modelValue': (value) => {
            target[key] = cast(value);
        },
    };
}

/**
 * "Tải file nghe (.mp3)" / "Tải ảnh lên": gửi file lên placement-tests.media.store, trả URL công khai
 * (thí sinh không đăng nhập vẫn nghe / xem được).
 */
export function useMediaUpload() {
    const uploading = ref(null);

    async function uploadMedia(event, kind, apply) {
        const file = event.target.files[0];
        if (!file) return;
        const data = new FormData();
        data.append('file', file);
        data.append('kind', kind);
        uploading.value = kind;
        try {
            const { ok, data: json } = await postForm(route('placement-tests.media.store'), data);
            if (!ok) throw new Error(firstError(json, 'Tải file thất bại'));
            apply(json.url);
            toast('Đã tải file lên.');
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            uploading.value = null;
            event.target.value = '';
        }
    }

    return { uploading, uploadMedia };
}
