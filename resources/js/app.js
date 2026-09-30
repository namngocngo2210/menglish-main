import './bootstrap';

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';

import './components/remote-modal';
import attachmentUploader from './modules/attachment-uploader';
import createReceiptManager from './modules/receipt-form';
import ticketReply from './modules/ticket-reply';
import { confirmDialog, registerFormConfirm } from './modules/confirm-dialog';
import { registerFormSubmitGuard } from './modules/form-submit-guard';
import { registerRowLinks } from './modules/row-link';
import { formatMoney } from './modules/money';
import { registerSearchableSelects } from './modules/searchable-select';

Alpine.plugin(collapse);
Alpine.plugin(focus);

// Component dùng chung trang ↔ modal htmx (không dùng <script> inline trong view).
Alpine.data('attachmentUploader', attachmentUploader);
Alpine.data('createReceiptManager', createReceiptManager);
Alpine.data('ticketReply', ticketReply);

window.Alpine = Alpine;
// Hộp xác nhận chung: form dùng data-confirm, JS / Alpine gọi await window.confirmDialog({...}).
window.confirmDialog = confirmDialog;
// Định dạng tiền dùng trong Alpine (x-text="formatMoney(v)"), cùng kiểu App\Support\Money.
window.formatMoney = formatMoney;
registerFormConfirm();
registerFormSubmitGuard();
// Dòng bảng <tr data-href> bấm được (danh sách → modal / trang chi tiết).
registerRowLinks();
// Dropdown lọc có ô tìm kiếm (Tom Select, kiểu select2).
registerSearchableSelects();

Alpine.start();
