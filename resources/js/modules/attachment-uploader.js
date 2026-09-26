/**
 * Alpine.data('attachmentUploader') — ô đính kèm của ticket (tạo ticket + ô trả lời):
 * chọn / kéo thả file vào input `x-ref="fileInput"`, dán ảnh từ clipboard (gửi base64 qua hidden `pasted_images`).
 * Dùng được cả trên trang và trong modal htmx (không phụ thuộc <script> inline); gỡ listener paste khi bị huỷ.
 */
export default function attachmentUploader() {
    return {
        isDragging: false,
        previews: [],
        pastedImages: [],
        onPaste: null,

        init() {
            this.onPaste = (e) => this.handlePaste(e);
            window.addEventListener('paste', this.onPaste);
        },

        destroy() {
            window.removeEventListener('paste', this.onPaste);
        },

        handlePaste(e) {
            const items = e.clipboardData?.items ?? [];
            for (const item of items) {
                if (!item.type.startsWith('image/')) continue;
                const blob = item.getAsFile();
                const reader = new FileReader();
                reader.onload = (event) => {
                    this.pastedImages.push(event.target.result);
                    this.previews.push({
                        name: 'Ảnh chụp màn hình (' + (this.previews.length + 1) + ')',
                        url: event.target.result,
                        isImage: true,
                        sizeFormatted: Math.round(blob.size / 1024) + ' KB',
                        isPasted: true,
                        pastedIndex: this.pastedImages.length - 1,
                    });
                };
                reader.readAsDataURL(blob);
            }
        },

        handleFileSelect(e) {
            this.processFiles(e.target.files);
        },

        handleDrop(e) {
            this.isDragging = false;
            this.$refs.fileInput.files = e.dataTransfer.files;
            this.processFiles(e.dataTransfer.files);
        },

        processFiles(files) {
            for (const file of files) {
                const isImage = file.type.startsWith('image/');
                this.previews.push({
                    name: file.name,
                    url: isImage ? URL.createObjectURL(file) : '',
                    isImage,
                    ext: file.name.split('.').pop(),
                    sizeFormatted: (file.size / 1024 / 1024).toFixed(2) + ' MB',
                    isPasted: false,
                });
            }
        },

        removeFile(index) {
            const item = this.previews[index];
            if (item?.isPasted) this.pastedImages.splice(item.pastedIndex, 1);
            this.previews.splice(index, 1);
        },
    };
}
