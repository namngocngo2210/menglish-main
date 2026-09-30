/**
 * Lỗi validate của một trường, cùng quy ước với <x-ui.field> cũ:
 *   - tên dạng mảng "items[0][name]" → khoá lỗi "items.0.name"
 *   - trong <UiForm> (Inertia <Form>): lỗi của chính form đó; ngoài form: lỗi chung của trang (props.errors)
 *   - bag: error bag có tên (vd. "updatePassword")
 */
import { computed, onBeforeUpdate, ref, useId } from 'vue';
import { usePage, useFormContext } from '@inertiajs/vue3';

export function errorKey(name) {
    if (!name) return null;
    return name.replace(/\[\]/g, '').replace(/\[/g, '.').replace(/\]/g, '').replace(/\.$/, '');
}

export function pageErrors(bag = null) {
    const errors = usePage().props.errors ?? {};
    return (bag ? errors[bag] : errors) ?? {};
}

export function useFieldError(props) {
    const form = useFormContext();
    const page = usePage();

    return computed(() => {
        if (props.error) return props.error;
        const key = errorKey(props.name);
        if (!key) return null;
        const own = form?.errors ?? {};
        const errors = Object.keys(own).length ? own : ((props.bag ? page.props.errors?.[props.bag] : page.props.errors) ?? {});
        const message = errors[key];
        return Array.isArray(message) ? message[0] : (message ?? null);
    });
}

/** id cho label/for: id truyền vào → theo name → tự sinh (useId, giống nhau giữa SSR và trình duyệt). Gọi trong setup. */
export function fieldId(props, attrs) {
    const auto = useId();
    if (attrs?.id) return attrs.id;
    if (props.name) return 'f_' + props.name.replace(/[^A-Za-z0-9_]/g, '_');
    return props.label ? 'f_' + auto.replace(/[^A-Za-z0-9_-]/g, '') : null;
}

/**
 * Giá trị ô nhập không dùng v-model (form HTML thường theo `name`). Vue gán lại thuộc tính `value` mỗi lần component
 * render lại (vd. vừa hiện lỗi validate) → nếu bám thẳng props.value, chữ người dùng đã gõ bị xoá. Trước mỗi lần render
 * lại, lấy giá trị đang có trên ô (DOM là nguồn đúng, kể cả sau form.reset() của Inertia); props.value đổi → dùng giá trị mới.
 *   const { value, bindEl } = useTypedValue(() => props.value);   <input :ref="bindEl" :value="value" …>
 */
export function useTypedValue(source) {
    let el = null;
    let last = source();
    const value = ref(last);
    onBeforeUpdate(() => {
        const next = source();
        if (next !== last) value.value = last = next;
        else if (el) value.value = el.value;
    });
    return { value, bindEl: (node) => (el = node) };
}
