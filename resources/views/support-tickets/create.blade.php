<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('tickets.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">add_task</span>
                    Tạo Yêu Cầu Hỗ Trợ (Ticket)
                </h1>
                <p class="text-xs text-gray-500">Mô tả sự cố cần hỗ trợ hoặc gửi yêu cầu liên phòng ban</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto" x-data="ticketUploader()">
        <form action="{{ route('tickets.store') }}" method="POST" enctype="multipart/form-data" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
            @csrf

            <div class="space-y-4 text-xs">
                <div>
                    <label class="block font-semibold text-gray-700 mb-1">Tiêu đề sự cố / yêu cầu <span class="text-rose-500">*</span></label>
                    <input type="text" name="title" required placeholder="Ví dụ: Lỗi không xuất được hóa đơn điện tử cho học viên HV-0012" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold focus:border-primary-container focus:ring-primary-container" value="{{ old('title') }}">
                    <x-input-error :messages="$errors->get('title')" class="mt-1" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Phân loại danh mục <span class="text-rose-500">*</span></label>
                        <select name="category" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-primary-container focus:ring-primary-container">
                            <option value="technical_issue">Lỗi Hệ Thống / IT</option>
                            <option value="curriculum">Giáo Trình / Học Vụ</option>
                            <option value="tuition">Học Phí / Hóa Đơn</option>
                            <option value="customer_complaint">Khiếu Nại Học Viên</option>
                            <option value="other">Yêu Cầu Hỗ Trợ Khác</option>
                        </select>
                        <x-input-error :messages="$errors->get('category')" class="mt-1" />
                    </div>

                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Mức độ ưu tiên <span class="text-rose-500">*</span></label>
                        <select name="priority" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-primary-container focus:ring-primary-container">
                            <option value="low">Thấp (Low)</option>
                            <option value="medium" selected>Trung bình (Medium)</option>
                            <option value="high">Cao (High)</option>
                            <option value="urgent">Khẩn cấp (Urgent)</option>
                        </select>
                        <x-input-error :messages="$errors->get('priority')" class="mt-1" />
                    </div>

                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Phân công người xử lý</label>
                        <select name="assignee_id" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-primary-container">
                            <option value="">-- Để mở (Chưa gán) --</option>
                            @foreach ($staffs as $staff)
                                <option value="{{ $staff->id }}" @selected((string) old('assignee_id') === (string) $staff->id)>{{ $staff->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('assignee_id')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-gray-700 mb-1">Mô tả chi tiết sự cố / Nội dung yêu cầu <span class="text-rose-500">*</span></label>
                    <textarea name="description" rows="4" required placeholder="Mô tả cụ thể các bước tái hiện lỗi, đường dẫn URL bị lỗi hoặc yêu cầu nghiệp vụ cần xử lý..." class="w-full text-xs rounded-xl border border-gray-200 p-3 focus:border-primary-container focus:ring-primary-container">{{ old('description') }}</textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1" />
                </div>

                <!-- Drag and Drop Image Upload Zone -->
                <div class="space-y-2 pt-2">
                    <label class="block font-semibold text-gray-700">
                        Hình ảnh đính kèm minh chứng / Ảnh chụp màn hình lỗi
                    </label>
                    
                    <div 
                        class="border-2 border-dashed rounded-2xl p-6 text-center transition cursor-pointer flex flex-col items-center justify-center gap-2 relative bg-gray-50/50 hover:bg-orange-50/30"
                        :class="isDragging ? 'border-primary-container bg-orange-50/60 ring-2 ring-primary-container/20' : 'border-gray-300 hover:border-primary-container'"
                        @dragover.prevent="isDragging = true"
                        @dragleave.prevent="isDragging = false"
                        @drop.prevent="handleDrop($event)"
                        @click="$refs.fileInput.click()"
                    >
                        <input 
                            type="file" 
                            x-ref="fileInput" 
                            name="attachments[]" 
                            multiple 
                            accept="image/*,.pdf,.doc,.docx,.xlsx" 
                            class="hidden" 
                            @change="handleFileSelect($event)"
                        />
                        <input type="hidden" name="pasted_images" :value="JSON.stringify(pastedImages)" />

                        <div class="w-12 h-12 rounded-2xl bg-orange-100 text-primary flex items-center justify-center shadow-inner">
                            <span class="material-symbols-outlined text-2xl">cloud_upload</span>
                        </div>
                        <div class="space-y-1">
                            <p class="font-bold text-gray-800 text-xs">
                                Kéo thả ảnh chụp lỗi vào đây, hoặc <span class="text-primary underline">chọn từ thiết bị</span>
                            </p>
                            <p class="text-[11px] text-gray-500">
                                Hỗ trợ: PNG, JPG, GIF, WEBP hoặc tài liệu PDF/Excel (Tối đa 15MB/file)
                            </p>
                            <div class="inline-flex items-center gap-1 text-[10px] text-primary bg-primary-container/10 px-2.5 py-0.5 rounded-full font-semibold mt-1">
                                <span class="material-symbols-outlined text-[13px]">content_paste</span>
                                <span>Có thể dán trực tiếp ảnh từ Clipboard (Ctrl + V / Cmd + V)</span>
                            </div>
                        </div>
                    </div>

                    <!-- Previews of selected / pasted files -->
                    <template x-if="previews.length > 0">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-3">
                            <template x-for="(item, index) in previews" :key="index">
                                <div class="relative group bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm p-1.5 flex flex-col">
                                    <div class="h-24 rounded-lg bg-gray-100 overflow-hidden flex items-center justify-center relative">
                                        <template x-if="item.isImage">
                                            <img :src="item.url" class="w-full h-full object-cover" />
                                        </template>
                                        <template x-if="!item.isImage">
                                            <div class="flex flex-col items-center gap-1 text-gray-500">
                                                <span class="material-symbols-outlined text-2xl">draft</span>
                                                <span class="text-[9px] uppercase font-bold" x-text="item.ext"></span>
                                            </div>
                                        </template>
                                        <button 
                                            type="button" 
                                            @click.stop="removeFile(index)" 
                                            class="absolute top-1 right-1 w-6 h-6 rounded-full bg-rose-600 text-white flex items-center justify-center opacity-90 hover:opacity-100 shadow transition"
                                            title="Xóa tệp này"
                                        >
                                            <span class="material-symbols-outlined text-sm">close</span>
                                        </button>
                                    </div>
                                    <div class="mt-1 px-1">
                                        <div class="font-medium text-[11px] text-gray-800 truncate" x-text="item.name"></div>
                                        <div class="text-[10px] text-gray-400 font-mono" x-text="item.sizeFormatted"></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <a href="{{ route('tickets.index') }}" class="px-4 py-2 border border-gray-200 text-xs font-semibold text-gray-700 rounded-xl hover:bg-gray-50">
                    Hủy
                </a>
                <button type="submit" class="px-5 py-2.5 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">send</span>
                    <span>Tạo &amp; Gửi Ticket</span>
                </button>
            </div>
        </form>
    </div>

    <script>
        function ticketUploader() {
            return {
                isDragging: false,
                previews: [],
                pastedImages: [],
                init() {
                    window.addEventListener('paste', (e) => {
                        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
                        for (let item of items) {
                            if (item.type.indexOf('image') !== -1) {
                                const blob = item.getAsFile();
                                const reader = new FileReader();
                                reader.onload = (event) => {
                                    const base64 = event.target.result;
                                    this.pastedImages.push(base64);
                                    this.previews.push({
                                        name: 'Ảnh chụp màn hình (' + (this.previews.length + 1) + ')',
                                        url: base64,
                                        isImage: true,
                                        sizeFormatted: Math.round(blob.size / 1024) + ' KB',
                                        isPasted: true,
                                        pastedIndex: this.pastedImages.length - 1
                                    });
                                };
                                reader.readAsDataURL(blob);
                            }
                        }
                    });
                },
                handleFileSelect(e) {
                    const files = e.target.files;
                    this.processFiles(files);
                },
                handleDrop(e) {
                    this.isDragging = false;
                    const files = e.dataTransfer.files;
                    this.$refs.fileInput.files = files;
                    this.processFiles(files);
                },
                processFiles(files) {
                    for (let i = 0; i < files.length; i++) {
                        const file = files[i];
                        const isImage = file.type.startsWith('image/');
                        const ext = file.name.split('.').pop();
                        const url = isImage ? URL.createObjectURL(file) : '';
                        this.previews.push({
                            name: file.name,
                            url: url,
                            isImage: isImage,
                            ext: ext,
                            sizeFormatted: (file.size / 1024 / 1024).toFixed(2) + ' MB',
                            isPasted: false
                        });
                    }
                },
                removeFile(index) {
                    const item = this.previews[index];
                    if (item && item.isPasted) {
                        this.pastedImages.splice(item.pastedIndex, 1);
                    }
                    this.previews.splice(index, 1);
                }
            };
        }
    </script>
</x-app-layout>
