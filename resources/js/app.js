import './bootstrap';

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';

import './components/remote-modal';
import attachmentUploader from './modules/attachment-uploader';
import createReceiptManager from './modules/receipt-form';

Alpine.plugin(collapse);
Alpine.plugin(focus);

// Component dùng chung trang ↔ modal htmx (không dùng <script> inline trong view).
Alpine.data('attachmentUploader', attachmentUploader);
Alpine.data('createReceiptManager', createReceiptManager);

window.Alpine = Alpine;

Alpine.start();
