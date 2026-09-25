<x-app-layout hide-errors>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('tuition.students') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-amber-600">upload_file</span>
                    Nhập Danh Sách Học Viên Hàng Loạt (Import Excel)
                </h1>
                <p class="text-xs text-gray-500">Tải lên file Excel danh sách học sinh và cấu hình thông tin học phí tự động</p>
            </div>
        </div>
    </x-slot>

    @include('tuition.partials.errors')

    <div class="max-w-4xl mx-auto mb-4 p-4 rounded-xl bg-amber-50 text-amber-800 border border-amber-200 text-sm flex items-center gap-2">
        <span class="material-symbols-outlined text-amber-600">construction</span>
        Chức năng nhập học phí từ Excel đang được phát triển — hiện chưa nạp được dữ liệu. Vui lòng nhập thủ công.
    </div>

    <div class="max-w-4xl mx-auto space-y-6" x-data="{
        fileName: '',
        fileUploaded: false,
    }">
        <form action="{{ route('tuition.import.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
            @csrf
            <!-- File upload box -->
            <div class="bg-white rounded-2xl border-2 border-dashed border-gray-300 p-8 text-center hover:border-primary-container transition cursor-pointer"
                 @dragover.prevent
                 @drop.prevent="fileName = 'Danh_sach_hoc_vien_T8_2026.xlsx'; fileUploaded = true"
                 @click="$refs.fileInput.click()">
                <input type="file" name="excel_file" x-ref="fileInput" class="hidden" @change="fileName = $event.target.files[0]?.name; fileUploaded = true" />

                <div class="w-16 h-16 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-3xl">cloud_upload</span>
                </div>
                <h3 class="text-sm font-bold text-gray-900 mb-1">Kéo thả file Excel (.xlsx, .csv) vào đây hoặc click để chọn</h3>
                <p class="text-xs text-gray-400 mb-4">Hỗ trợ định dạng .xlsx, .xls, dung lượng tối đa 10MB</p>

                <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-gray-200 transition">
                    <span class="material-symbols-outlined text-base">download</span>
                    <span>Tải file mẫu Excel chuẩn (Template)</span>
                </div>

                <template x-if="fileUploaded">
                    <div class="mt-4 p-3 bg-emerald-50 text-emerald-800 rounded-xl border border-emerald-200 inline-flex items-center gap-2 text-xs font-semibold">
                        <span class="material-symbols-outlined text-emerald-600">check_circle</span>
                        <span x-text="'Đã sẵn sàng tải lên: ' + fileName"></span>
                    </div>
                </template>
            </div>

            <!-- Submit Button -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100" x-show="fileUploaded">
                <a href="{{ route('tuition.students') }}" class="px-4 py-2 border border-gray-200 text-xs font-semibold text-gray-700 rounded-xl hover:bg-gray-50">
                    Hủy
                </a>
                <button type="submit" class="px-5 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">cloud_done</span>
                    <span>Tiến hành Import vào CSDL</span>
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
