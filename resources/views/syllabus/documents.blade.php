<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">folder_shared</span>
                    Quản lý tài liệu giáo trình
                </h1>
                <p class="text-xs text-gray-500">Tải lên file giáo trình, slide, audio, video theo chặng và chọn đối tượng được xem.</p>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" icon="edit_document" :href="route('syllabus.builder')">Soạn syllabus (Bước #2)</x-ui.button>
                <x-ui.button icon="menu_book" :href="route('syllabus.teacher-view')">Xem như giáo viên (Bước #4)</x-ui.button>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 1])

    @php($canUpload = auth()->user()->can('syllabus.upload'))

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        @if ($canUpload)
        <section class="lg:col-span-4 flex flex-col gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                    <span class="material-symbols-outlined text-primary">upload_file</span>
                    <h2 class="text-sm font-bold text-gray-900">Tải lên tài liệu mới</h2>
                </div>

                @if ($curriculums->isEmpty())
                    <x-ui.empty-state icon="library_add" title="Chưa có giáo trình" description="Tạo giáo trình ở màn Soạn syllabus trước khi tải tài liệu.">
                        <x-ui.button icon="add" :href="route('syllabus.builder')">Tạo giáo trình</x-ui.button>
                    </x-ui.empty-state>
                @else
                <form action="{{ route('syllabus.documents.store') }}" method="POST" enctype="multipart/form-data" class="space-y-3.5">
                    @csrf
                    <x-ui.select name="curriculum_id" label="Giáo trình" required placeholder="-- Chọn giáo trình --"
                                 :options="$curriculums->mapWithKeys(fn ($c) => [$c->id => $c->title.' ('.$c->code.' · '.$c->version.')'])" />
                    <x-ui.input name="title" label="Tên tài liệu" required placeholder="IELTS Reading Masterclass - Student Book" />
                    <x-ui.input name="stage_name" label="Chặng học" placeholder="Chặng 1 (0 - 3.0: Xây dựng nền tảng)" />

                    <x-ui.field label="File tài liệu" name="file" required hint="PDF, Word, PowerPoint, Excel, ảnh, audio (mp3/wav/m4a), video (mp4/mov/webm). Tối đa 100 MB.">
                        <input type="file" name="file" required
                               accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp,.mp3,.wav,.m4a,.ogg,.mp4,.mov,.webm,.m4v"
                               class="block w-full text-xs text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-primary hover:file:bg-orange-100 border border-dashed border-gray-300 rounded-xl p-2" />
                    </x-ui.field>

                    <x-ui.field label="Đối tượng được xem" hint="Admin, Học thuật, Học vụ luôn xem được.">
                        <div class="grid grid-cols-2 gap-2 bg-gray-50 p-3 rounded-xl border border-gray-100 text-xs">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="visible_to_teachers" value="1" @checked(old('visible_to_teachers')) class="rounded border-gray-300 text-primary focus:ring-primary-container h-4 w-4" />
                                <span class="font-medium text-gray-700">Giáo viên</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="visible_to_assistants" value="1" @checked(old('visible_to_assistants')) class="rounded border-gray-300 text-primary focus:ring-primary-container h-4 w-4" />
                                <span class="font-medium text-gray-700">Trợ giảng</span>
                            </label>
                            <label class="col-span-2 flex items-center gap-2 cursor-pointer pt-2 border-t border-gray-200">
                                <input type="checkbox" name="downloadable" value="1" @checked(old('downloadable')) class="rounded border-gray-300 text-primary focus:ring-primary-container h-4 w-4" />
                                <span class="font-medium text-gray-700">Cho phép GV/TG tải về (bỏ chọn = chỉ xem trực tuyến)</span>
                            </label>
                        </div>
                    </x-ui.field>

                    <div class="pt-2 border-t border-gray-100">
                        <x-ui.button type="submit" icon="save" class="w-full">Lưu tài liệu giáo trình</x-ui.button>
                    </div>
                </form>
                @endif
            </div>
        </section>
        @endif

        <section class="{{ $canUpload ? 'lg:col-span-8' : 'lg:col-span-12' }} flex flex-col gap-4 min-w-0">
            <x-ui.data-table min-width="720px">
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">library_books</span>
                        <h2 class="text-sm font-bold text-gray-900">Danh sách tài liệu</h2>
                        <x-ui.badge>{{ $documents->total() }} tài liệu</x-ui.badge>
                    </div>
                    <form method="GET" class="flex flex-wrap items-center gap-2">
                        <x-ui.select name="curriculum_id" placeholder="Tất cả giáo trình" :options="$curriculums->pluck('title', 'id')" onchange="this.form.submit()" />
                        <x-ui.input name="search" icon="search" :value="request('search')" placeholder="Tìm tài liệu..." />
                    </form>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Tên tài liệu</th>
                            <th>Chặng học</th>
                            <th>Đối tượng xem</th>
                            <th>Quyền truy cập</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documents as $doc)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-orange-50 text-primary flex items-center justify-center shrink-0">
                                            <span class="material-symbols-outlined text-[20px]">{{ $doc->icon }}</span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-bold text-gray-900 line-clamp-1 text-xs">{{ $doc->title }}</p>
                                            <p class="text-[11px] text-gray-400 mt-0.5">
                                                {{ $doc->curriculum?->title }} • <span class="font-mono uppercase">{{ $doc->extension }}</span> • <span class="font-mono">{{ $doc->size_human }}</span>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">{{ $doc->stage_name ?: '—' }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($doc->audience_labels as $label)
                                            <x-ui.badge :color="$loop->first ? 'info' : 'primary'" :dot="false">{{ $label }}</x-ui.badge>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($doc->downloadable)
                                        <x-ui.badge color="success">Có thể tải</x-ui.badge>
                                    @else
                                        <x-ui.badge color="error">Chỉ xem online</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('syllabus.teacher-view', ['document' => $doc->id])" title="Xem tài liệu" />
                                        <x-ui.button variant="ghost" size="sm" icon="download" :href="route('syllabus.documents.file', ['id' => $doc->id, 'download' => 1])" title="Tải về" />
                                        @if ($canUpload)
                                            <form method="POST" action="{{ route('syllabus.documents.destroy', $doc->id) }}" data-confirm="Xóa tài liệu {{ $doc->title }}? File sẽ bị xóa khỏi máy chủ.">
                                                @csrf @method('DELETE')
                                                <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa tài liệu" />
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-ui.empty-state icon="folder_off" title="Chưa có tài liệu nào" description="Tài liệu được tải lên sẽ hiển thị tại đây." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$documents" unit="tài liệu" /></x-slot:footer>
            </x-ui.data-table>
        </section>
    </div>
</x-app-layout>
