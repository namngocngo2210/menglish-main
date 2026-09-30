/**
 * Tiêu đề trang cho topbar: AppLayout cung cấp, <UiPageHeader> đặt (như <x-ui.page-header> đặt page_title cho layout Blade).
 * Render phía server: topbar vẽ trước nội dung nên dùng tiêu đề layout (defineOptions({ layout: { title } })) hoặc tiêu đề mặc định của workspace.
 */
import { inject } from 'vue';

export const PAGE_META = Symbol('pageMeta');

export function usePageMeta() {
    return inject(PAGE_META, null);
}
