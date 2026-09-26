<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('tickets.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-xl bg-orange-100 text-primary border border-orange-300 font-mono font-bold text-xs shadow-2xs">#{{ $ticket->code }}</span>
                        <h1 class="text-xl font-bold text-gray-900 tracking-tight">{{ $ticket->title }}</h1>
                    </div>
                    <p class="text-xs text-gray-500">Tạo bởi {{ $ticket->creator?->name }} vào lúc {{ $ticket->created_at->format('d/m/Y H:i') }} · {{ $ticket->category_label }}</p>
                </div>
            </div>

            {{-- Status & Assignee Quick Actions --}}
            <div class="flex items-center gap-2">
                <form action="{{ route('tickets.status.update', $ticket->id) }}" method="POST" class="flex items-center gap-1.5">
                    @csrf
                    <select name="status" onchange="this.form.submit()" class="text-xs font-bold rounded-xl border border-gray-200 p-2 {{ $ticket->status_badge }}">
                        <option value="open" {{ $ticket->status === 'open' ? 'selected' : '' }}>Mới tiếp nhận (Open)</option>
                        <option value="in_progress" {{ $ticket->status === 'in_progress' ? 'selected' : '' }}>Đang xử lý (In Progress)</option>
                        <option value="resolved" {{ $ticket->status === 'resolved' ? 'selected' : '' }}>Đã giải quyết (Resolved)</option>
                        <option value="closed" {{ $ticket->status === 'closed' ? 'selected' : '' }}>Đã đóng (Closed)</option>
                    </select>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6" x-data="{ lightboxOpen: false, lightboxImg: '' }">
        {{-- Main Conversation Stream --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Messages Timeline --}}
            <div class="space-y-4">
                @foreach ($ticket->messages->reverse() as $msg)
                    <div class="bg-white rounded-2xl border {{ $msg->is_internal_note ? 'border-amber-200 bg-amber-50/20' : 'border-gray-200' }} shadow-sm p-5 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-full bg-primary-container/10 text-primary font-bold text-xs flex items-center justify-center">
                                    {{ substr($msg->user?->name ?? 'U', 0, 1) }}
                                </div>
                                <div>
                                    <div class="font-bold text-xs text-gray-900 flex items-center gap-2">
                                        <span>{{ $msg->user?->name }}</span>
                                        @if ($msg->is_internal_note)
                                            <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800 text-[10px] font-bold">Ghi chú nội bộ</span>
                                        @endif
                                    </div>
                                    <div class="text-[10px] text-gray-400 font-mono">{{ $msg->created_at->format('d/m/Y H:i') }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="text-xs text-gray-800 leading-relaxed whitespace-pre-line pl-10">
                            {{ $msg->message }}
                        </div>

                        {{-- Attachments Display --}}
                        @if (!empty($msg->attachment_list))
                            <div class="pl-10 pt-2">
                                <div class="text-[11px] font-bold text-gray-600 mb-2 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[15px] text-primary">attach_file</span>
                                    <span>Tệp / Hình ảnh đính kèm ({{ count($msg->attachment_list) }}):</span>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                                    @foreach ($msg->attachment_list as $file)
                                        @php
                                            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                            $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
                                            $fileUrl = route('tickets.attachment', ['id' => $ticket->id, 'path' => $file]);
                                        @endphp
                                        @if ($isImg)
                                            <div class="group relative rounded-xl border border-gray-200 overflow-hidden bg-gray-50 hover:shadow-md transition cursor-pointer"
                                                 @click="lightboxImg = '{{ $fileUrl }}'; lightboxOpen = true">
                                                <div class="h-28 overflow-hidden bg-gray-100 flex items-center justify-center">
                                                    <img src="{{ $fileUrl }}" alt="Attachment" class="w-full h-full object-cover group-hover:scale-105 transition duration-300" />
                                                </div>
                                                <div class="p-1.5 bg-white flex items-center justify-between">
                                                    <span class="text-[10px] font-medium text-gray-700 truncate max-w-[120px]">{{ basename($file) }}</span>
                                                    <span class="material-symbols-outlined text-xs text-gray-400 group-hover:text-primary">zoom_in</span>
                                                </div>
                                            </div>
                                        @else
                                            <a href="{{ $fileUrl }}" target="_blank" class="flex items-center gap-2 p-2.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 transition shadow-sm group">
                                                <div class="w-8 h-8 rounded-lg bg-orange-50 text-primary flex items-center justify-center font-bold text-[10px] shrink-0">
                                                    {{ strtoupper($ext) }}
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-[11px] font-bold text-gray-800 truncate group-hover:text-primary">{{ basename($file) }}</div>
                                                    <div class="text-[9px] text-gray-400">Nhấn để tải về</div>
                                                </div>
                                                <span class="material-symbols-outlined text-sm text-gray-400 group-hover:text-primary">download</span>
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Reply Box with Drag & Drop Uploader --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5" x-data="replyUploader()">
                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-base">reply</span>
                    Gửi phản hồi / Cập nhật tiến độ
                </h3>
                <form action="{{ route('tickets.messages.store', $ticket->id) }}" method="POST" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <textarea name="message" rows="3" required placeholder="Nhập câu trả lời hoặc tiến độ giải quyết vấn đề..." class="w-full text-xs rounded-xl border border-gray-200 p-3 focus:border-primary-container focus:ring-primary-container"></textarea>

                    {{-- Compact Drag & Drop Upload Zone for Reply --}}
                    <div class="space-y-2">
                        <div 
                            class="border border-dashed rounded-xl p-3 text-center transition cursor-pointer flex items-center justify-center gap-2 bg-gray-50/50 hover:bg-orange-50/30"
                            :class="isDragging ? 'border-primary-container bg-orange-50/60 ring-2 ring-primary-container/20' : 'border-gray-300 hover:border-primary-container'"
                            @dragover.prevent="isDragging = true"
                            @dragleave.prevent="isDragging = false"
                            @drop.prevent="handleDrop($event)"
                            @click="$refs.replyFileInput.click()"
                        >
                            <input 
                                type="file" 
                                x-ref="replyFileInput" 
                                name="attachments[]" 
                                multiple 
                                accept="image/*,.pdf,.doc,.docx,.xlsx" 
                                class="hidden" 
                                @change="handleFileSelect($event)"
                            />
                            <input type="hidden" name="pasted_images" :value="JSON.stringify(pastedImages)" />

                            <span class="material-symbols-outlined text-base text-primary">add_photo_alternate</span>
                            <span class="text-[11px] text-gray-600 font-medium">Kéo thả ảnh hoặc <span class="text-primary underline">chọn ảnh</span> / Dán trực tiếp (Ctrl+V)</span>
                        </div>

                        {{-- Previews --}}
                        <template x-if="previews.length > 0">
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1">
                                <template x-for="(item, index) in previews" :key="index">
                                    <div class="relative group bg-white border border-gray-200 rounded-lg overflow-hidden shadow-sm p-1 flex flex-col">
                                        <div class="h-16 rounded bg-gray-100 overflow-hidden flex items-center justify-center relative">
                                            <template x-if="item.isImage">
                                                <img :src="item.url" class="w-full h-full object-cover" />
                                            </template>
                                            <template x-if="!item.isImage">
                                                <span class="text-[9px] uppercase font-bold text-gray-500" x-text="item.ext"></span>
                                            </template>
                                            <button 
                                                type="button" 
                                                @click.stop="removeFile(index)" 
                                                class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center opacity-90 hover:opacity-100 shadow transition"
                                            >
                                                <span class="material-symbols-outlined text-xs">close</span>
                                            </button>
                                        </div>
                                        <div class="font-medium text-[10px] text-gray-800 truncate px-0.5 mt-0.5" x-text="item.name"></div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        @if ($canPostInternal ?? false)
                            <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                                <input type="checkbox" name="is_internal_note" value="1" class="rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                                <span>Chỉ hiển thị nội bộ giữa các phòng ban</span>
                            </label>
                        @else
                            <span></span>
                        @endif
                        <button type="submit" class="px-4 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px]">send</span>
                            <span>Gửi phản hồi</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Sidebar Info --}}
        <div class="space-y-4">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-4 text-xs">
                <h3 class="font-bold text-gray-900 uppercase tracking-wider pb-2 border-b border-gray-100">
                    Thông tin Ticket
                </h3>

                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Danh mục:</span>
                        <span class="font-bold text-gray-900">{{ $ticket->category_label }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Mức độ ưu tiên:</span>
                        <span class="px-2 py-0.5 rounded-full border text-[10px] {{ $ticket->priority_badge }}">{{ strtoupper($ticket->priority) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Trạng thái:</span>
                        <span class="px-2 py-0.5 rounded-full border font-bold text-[10px] {{ $ticket->status_badge }}">{{ $ticket->status_label }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Người tạo:</span>
                        <span class="font-bold text-gray-900">{{ $ticket->creator?->name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Ngày tạo:</span>
                        <span class="font-mono text-gray-700">{{ $ticket->created_at->format('d/m/Y H:i') }}</span>
                    </div>
                </div>

                {{-- Reassign Staff Form --}}
                <div class="pt-3 border-t border-gray-100 space-y-2">
                    <label class="block font-bold text-gray-800">Người phụ trách xử lý:</label>
                    <form action="{{ route('tickets.assign', $ticket->id) }}" method="POST" class="space-y-2">
                        @csrf
                        <select name="assignee_id" class="w-full text-xs rounded-xl border border-gray-200 p-2">
                            <option value="">-- Chưa phân công --</option>
                            @foreach ($staffs as $staff)
                                <option value="{{ $staff->id }}" {{ $ticket->assignee_id == $staff->id ? 'selected' : '' }}>{{ $staff->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="w-full py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold rounded-lg text-xs transition">
                            Cập nhật Phân công
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Lightbox Modal for Attachment Zoom --}}
        <div x-show="lightboxOpen" 
             x-cloak 
             @click="lightboxOpen = false" 
             @keydown.escape.window="lightboxOpen = false"
             class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
            <div class="relative max-w-5xl max-h-[90vh] bg-transparent rounded-2xl overflow-hidden shadow-2xl flex flex-col items-center" @click.stop>
                <button @click="lightboxOpen = false" class="absolute top-3 right-3 w-9 h-9 rounded-full bg-black/60 text-white flex items-center justify-center hover:bg-black/80 transition z-10">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
                <img :src="lightboxImg" class="max-w-full max-h-[85vh] rounded-xl object-contain shadow-lg" />
                <div class="mt-2 text-center">
                    <a :href="lightboxImg" target="_blank" download class="inline-flex items-center gap-1.5 text-xs text-white bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-lg font-semibold transition">
                        <span class="material-symbols-outlined text-sm">download</span>
                        <span>Mở ảnh gốc trong tab mới</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function replyUploader() {
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
                                        name: 'Ảnh chụp dán (' + (this.previews.length + 1) + ')',
                                        url: base64,
                                        isImage: true,
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
                    this.$refs.replyFileInput.files = files;
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
