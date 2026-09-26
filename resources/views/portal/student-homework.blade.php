<x-app-layout>
    <x-ui.page-header title="Học tập & Nộp bài tập" icon="upload_file" :back="route('students.index')">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="video_library" :href="route('portal.teacher.submissions')">Cổng GV: Xem bài nộp của lớp</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    

    <div class="max-w-4xl mx-auto space-y-6" x-data="{
        uploadType: 'video',
        uploadTitle: 'Quay video bài học',
        studentId: '{{ $student?->id ?? 1 }}',
        editId: null,
        editNotes: '',
        openUpload(type, title) {
            this.uploadType = type;
            this.uploadTitle = title;
            this.$dispatch('open-modal', 'homework-upload');
        },
        openEdit(id, notes) {
            this.editId = id;
            this.editNotes = notes;
            this.$dispatch('open-modal', 'homework-edit');
        }
    }">
        {{-- Student Switcher Bar (For Testing / Admin Viewing) --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined">account_circle</span>
                </div>
                <div>
                    <span class="text-xs font-bold text-on-surface-variant uppercase tracking-wider block">Tài khoản học viên:</span>
                    <strong class="text-sm text-on-surface">{{ $student?->name ?? '—' }}</strong>
                    <span class="text-xs text-on-surface-variant font-mono">({{ $student?->code ?? '—' }})</span>
                </div>
            </div>

            <x-ui.select inline-label="Đổi học viên:"
                         onchange="window.location.href = '{{ route('portal.student.homework') }}/' + this.value">
                @foreach($students as $st)
                    <option value="{{ $st->id }}" {{ ($student && $student->id === $st->id) ? 'selected' : '' }}>
                        {{ $st->name }} ({{ $st->currentClass?->name ?? 'Chưa xếp lớp' }})
                    </option>
                @endforeach
            </x-ui.select>
        </div>

        {{-- Student Mobile Frame --}}
        <div class="max-w-[420px] mx-auto bg-surface-container-lowest rounded-3xl border border-surface-container-highest shadow-xl overflow-hidden pb-8">
            {{-- Decorative Header Area --}}
            <div class="w-full h-[140px] bg-gradient-to-br from-primary-container to-primary-container/60 p-6 flex flex-col justify-end text-white relative">
                <div class="absolute top-3 right-3 bg-surface-container-lowest/20 backdrop-blur-xs px-2.5 py-1 rounded-full text-[10px] font-bold tracking-wider uppercase">
                    MENGLISH LMS
                </div>
                <h1 class="text-2xl font-bold tracking-tight text-white drop-shadow-sm">Học tập của tôi</h1>
                <p class="text-xs text-white/80 mt-0.5">Lớp: {{ $student?->currentClass?->name ?? 'Chưa xếp lớp' }}</p>
            </div>

            {{-- Subtab Switcher: Nộp bài tập / Luyện phát âm --}}
            <div class="flex items-center border-b border-surface-container-highest bg-surface-container-low px-3 pt-2">
                <a href="{{ route('portal.student.homework', ['studentId' => $student?->id]) }}"
                   class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-primary-container text-primary font-bold text-xs">
                    <span class="material-symbols-outlined text-[16px]">assignment</span>
                    <span>Nộp bài tập</span>
                </a>
                <a href="{{ route('portal.student.pronunciation', ['studentId' => $student?->id]) }}"
                   class="flex items-center gap-1.5 px-4 py-2 border-b-2 border-transparent text-on-surface-variant hover:text-on-surface font-semibold text-xs transition">
                    <span class="material-symbols-outlined text-[16px]">mic</span>
                    <span>Luyện phát âm AI</span>
                </a>
            </div>

            <div class="px-4 flex flex-col gap-6 mt-4 pb-20">
                {{-- 1. BÁO CÁO BUỔI HỌC --}}
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-xl">insights</span>
                        <h2 class="text-base font-bold text-on-surface">Nhận xét buổi học</h2>
                    </div>

                    @forelse($remarks as $item)
                        @php $r = $item['remark']; @endphp
                        <div class="bg-surface-container-lowest rounded-2xl p-4 border border-surface-container-highest shadow-2xs flex flex-col gap-3">
                            <div class="flex justify-between items-start">
                                <p class="text-[11px] text-on-surface-variant/70 font-medium">Ngày {{ $item['date']?->format('d/m/Y') }}</p>
                                <x-ui.badge color="success" :pill="true">Đã nhận xét</x-ui.badge>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach(['monsters' => ['Monsters', 'hotel_class', 'text-warning'], 'grammar' => ['Ngữ pháp', 'psychology', 'text-secondary'], 'attitude' => ['Tinh thần', 'mood', 'text-tertiary'], 'result' => ['Kết quả', 'checklist', 'text-purple-500']] as $key => [$label, $icon, $tone])
                                    @if(filled($r[$key] ?? null))
                                        <div class="bg-surface-container-low rounded-xl p-2.5 flex items-center gap-2 border border-surface-container-highest">
                                            <span class="material-symbols-outlined {{ $tone }} text-lg">{{ $icon }}</span>
                                            <div>
                                                <p class="text-[10px] text-on-surface-variant/70 font-medium">{{ $label }}</p>
                                                <p class="text-xs font-bold text-on-surface">{{ $r[$key] }}</p>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                            @if(filled($r['comment'] ?? null))
                                <div class="border-t border-surface-container-highest pt-3">
                                    <p class="text-[11px] text-on-surface-variant mb-1 flex items-center gap-1 font-bold">
                                        <span class="material-symbols-outlined text-[15px] text-primary">edit_note</span>
                                        Nhận xét chi tiết từ giáo viên
                                    </p>
                                    <p class="text-xs text-on-surface-variant leading-relaxed bg-primary-container/10 p-2.5 rounded-xl border border-primary-container/30">{{ $r['comment'] }}</p>
                                </div>
                            @endif
                        </div>
                    @empty
                        <x-ui.empty-state class="bg-surface-container-low rounded-2xl border border-dashed border-surface-container-highest" icon="insights" title="Chưa có nhận xét buổi học nào." />
                    @endforelse
                </section>

                {{-- 2. BẢNG ĐIỂM --}}
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-xl">school</span>
                        <h2 class="text-base font-bold text-on-surface">Bảng điểm</h2>
                    </div>
                    <div class="flex flex-col gap-2">
                        @forelse($miniTests as $mt)
                            <div class="bg-surface-container-lowest rounded-xl p-3.5 border border-surface-container-highest shadow-2xs flex items-center justify-between">
                                <div>
                                    <h4 class="text-xs font-bold text-on-surface">{{ $mt->name }}</h4>
                                    <p class="text-[10px] text-on-surface-variant/70 font-mono">Ngày thi: {{ $mt->test_date?->format('d/m/Y') }}</p>
                                </div>
                                <div class="text-lg font-black text-primary font-mono">{{ rtrim(rtrim(number_format((float) $mt->score, 2, '.', ''), '0'), '.') }}<span class="text-xs text-on-surface-variant/70">/{{ rtrim(rtrim(number_format((float) $mt->max_score, 2, '.', ''), '0'), '.') }}</span></div>
                            </div>
                        @empty
                        @endforelse
                        @foreach($bigTestResults as $bt)
                            <div class="bg-surface-container-lowest rounded-xl p-3.5 border border-surface-container-highest shadow-2xs flex items-center justify-between">
                                <div>
                                    <h4 class="text-xs font-bold text-on-surface">{{ $bt->bigTest?->title ?? 'Big Test' }}</h4>
                                    <p class="text-[10px] text-on-surface-variant/70 font-mono">Ngày thi: {{ $bt->bigTest?->scheduled_at?->format('d/m/Y') ?? '—' }}</p>
                                </div>
                                @if($bt->is_absent)
                                    <x-ui.badge color="neutral" :pill="true">Vắng thi</x-ui.badge>
                                @else
                                    <div class="text-lg font-black text-primary font-mono">{{ $bt->overall_score }}</div>
                                @endif
                            </div>
                        @endforeach
                        @if($miniTests->isEmpty() && $bigTestResults->isEmpty())
                            <x-ui.empty-state class="bg-surface-container-low rounded-xl border border-dashed border-surface-container-highest" icon="school" title="Chưa có điểm kiểm tra nào." />
                        @endif
                    </div>
                </section>

                {{-- 3. BÀI TẬP VỀ NHÀ (INTERACTIVE SUBMISSION) --}}
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-xl">assignment</span>
                        <h2 class="text-base font-bold text-on-surface">Bài tập về nhà</h2>
                    </div>

                    {{-- Bài tập giáo viên giao gần nhất --}}
                    @if($latestHomework)
                        <x-ui.alert type="error" :title="$latestHomework->title">
                            @if($latestHomework->description)
                                <p class="text-[11px] leading-normal">{{ $latestHomework->description }}</p>
                            @endif
                            <p class="text-[10px] mt-1">{{ $latestHomework->classModel?->name }} · Hạn nộp: {{ $latestHomework->due_date?->format('d/m/Y') ?? 'Không giới hạn' }}</p>
                        </x-ui.alert>
                    @endif

                    @php
                        $homeworkCategories = [
                            'video' => [
                                'title' => 'Quay video bài học',
                                'icon' => 'videocam',
                                'color' => 'text-primary',
                                'btn_text' => 'Tải lên video',
                                'is_quiz' => false,
                            ],
                            'vocabulary' => [
                                'title' => 'Viết từ vựng & Chụp ảnh',
                                'icon' => 'edit_document',
                                'color' => 'text-secondary',
                                'btn_text' => 'Tải lên bài viết',
                                'is_quiz' => false,
                            ],
                            'workbook' => [
                                'title' => 'Làm Workbook bài tập',
                                'icon' => 'menu_book',
                                'color' => 'text-secondary',
                                'btn_text' => 'Tải lên Workbook',
                                'is_quiz' => false,
                            ],
                            'extra_book' => [
                                'title' => 'Sách bổ trợ',
                                'icon' => 'library_books',
                                'color' => 'text-tertiary',
                                'btn_text' => 'Tải lên bài làm',
                                'is_quiz' => false,
                            ],
                            'bgd_book' => [
                                'title' => 'Sách Bộ Giáo dục',
                                'icon' => 'import_contacts',
                                'color' => 'text-purple-600',
                                'btn_text' => 'Tải lên bài làm',
                                'is_quiz' => false,
                            ],
                            'quiz' => [
                                'title' => 'Làm Quiz trực tuyến',
                                'icon' => 'quiz',
                                'color' => 'text-warning',
                                'btn_text' => 'Nộp kết quả Quiz',
                                'is_quiz' => true,
                            ],
                        ];
                    @endphp

                    {{-- Progress Bar --}}
                    <div class="flex items-center justify-between mt-1">
                        <p class="text-xs font-bold text-on-surface">Đã hoàn thành: {{ $completedCount }}/6 hạng mục</p>
                        <div class="w-1/3 bg-surface-container rounded-full h-2 overflow-hidden">
                            <div class="bg-tertiary h-full rounded-full transition-all duration-300" style="width: {{ round(($completedCount / 6) * 100) }}%"></div>
                        </div>
                    </div>

                    {{-- Homework Cards List (Real DB Integration) --}}
                    <div class="flex flex-col gap-2.5">
                        @foreach($homeworkCategories as $key => $cat)
                            @php
                                $sub = $submissionsByType[$key] ?? null;
                            @endphp
                            <div class="bg-surface-container-lowest rounded-2xl p-3.5 border border-surface-container-highest shadow-2xs flex flex-col gap-2">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined {{ $cat['color'] }} text-lg">{{ $cat['icon'] }}</span>
                                        <h4 class="text-xs font-bold text-on-surface">{{ $cat['title'] }}</h4>
                                    </div>
                                    @if($sub)
                                        <x-ui.badge color="success" :pill="true" :dot="false">
                                            <span class="material-symbols-outlined text-[12px]">check_circle</span> Đã nộp
                                        </x-ui.badge>
                                    @else
                                        <x-ui.badge color="error" :pill="true">
                                            Chưa nộp
                                        </x-ui.badge>
                                    @endif
                                </div>

                                @if($sub)
                                    <div class="space-y-1 bg-surface-container-low p-2.5 rounded-xl text-xs border border-surface-container-highest">
                                        <div class="flex items-center justify-between text-[10px] text-on-surface-variant">
                                            <span>Nộp lúc: <strong class="font-mono text-on-surface-variant">{{ $sub->data['submitted_at'] ?? $sub->created_at->format('d/m/Y H:i') }}</strong></span>
                                            @if($sub->status === 'reviewed')
                                                <x-ui.badge color="success" :dot="false">{{ filled($sub->data['score'] ?? null) ? 'Đã chấm: '.$sub->data['score'] : 'Giáo viên đã xem' }}</x-ui.badge>
                                            @else
                                                <span class="text-warning font-medium">Chờ giáo viên chấm</span>
                                            @endif
                                        </div>

                                        @if(!empty($sub->data['attachment_path']))
                                            <div class="pt-1">
                                                <a href="{{ $sub->data['attachment_path'] }}" target="_blank" class="inline-flex items-center gap-1 text-[11px] text-primary font-bold hover:underline">
                                                    <span class="material-symbols-outlined text-[14px]">attachment</span>
                                                    <span>{{ $sub->data['attachment_name'] ?? 'Xem tệp đính kèm' }}</span>
                                                </a>
                                            </div>
                                        @endif

                                        @if(!empty($sub->data['notes']))
                                            <p class="text-[11px] text-on-surface-variant italic">"{{ $sub->data['notes'] }}"</p>
                                        @endif

                                        @if(!empty($sub->data['feedback']))
                                            <div class="bg-secondary/10 text-secondary p-2 rounded-lg text-[11px] mt-1 border border-secondary/30">
                                                <strong>Nhận xét của GV:</strong> {{ $sub->data['feedback'] }}
                                            </div>
                                        @endif
                                    </div>

                                    {{-- CRUD Actions: Sửa ghi chú / Nộp lại & Hủy nộp --}}
                                    <div class="flex items-center gap-2 pt-1">
                                        <x-ui.button variant="secondary" size="sm" icon="edit" class="flex-1"
                                                     x-on:click="openEdit({{ $sub->id }}, '{{ addslashes($sub->data['notes'] ?? '') }}')">
                                            Sửa / Nộp lại
                                        </x-ui.button>
                                        <form action="{{ route('portal.student.homework.destroy', $sub->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn hủy bài nộp này không?');">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete" title="Hủy nộp" aria-label="Hủy nộp" />
                                        </form>
                                    </div>
                                @else
                                    <div class="flex flex-col gap-1.5 pt-1">
                                        <x-ui.button icon="upload" class="w-full"
                                                     x-on:click="openUpload('{{ $key }}', '{{ $cat['title'] }}')">
                                            {{ $cat['btn_text'] }}
                                        </x-ui.button>
                                        @if($cat['is_quiz'])
                                            <a href="https://quizizz.com" target="_blank" class="inline-flex items-center justify-center gap-1 text-secondary font-bold text-xs hover:underline mt-0.5">
                                                <span>Mở link Quiz trực tuyến</span>
                                                <span class="material-symbols-outlined text-[14px]">open_in_new</span>
                                            </a>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- 4. LỘ TRÌNH HỌC TẬP --}}
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-xl">map</span>
                        <h2 class="text-base font-bold text-on-surface">Lộ trình học tập</h2>
                    </div>
                    <div class="bg-surface-container-lowest rounded-2xl p-3 border border-surface-container-highest shadow-2xs">
                        <div class="p-4 bg-surface-container-low rounded-xl flex flex-col items-center text-center space-y-2">
                            <div class="w-12 h-12 rounded-full bg-primary-container/10 text-primary flex items-center justify-center">
                                <span class="material-symbols-outlined text-[28px]">trending_up</span>
                            </div>
                            <h3 class="text-xs font-bold text-on-surface">Lộ trình mục tiêu: IELTS 6.5</h3>
                            <p class="text-[11px] text-on-surface-variant max-w-[280px]">Đang học Chặng 2 (Intermediate). Đạt 65% thời lượng chương trình.</p>
                            <div class="w-full bg-surface-container-high rounded-full h-2 overflow-hidden mt-2">
                                <div class="bg-primary-container h-full rounded-full" style="width: 65%"></div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Bottom Navigation Bar Component --}}
            @include('portal.partials.bottom-nav', ['activeTab' => 'learning', 'student' => $student])
        </div>

        {{-- Upload Modal (Submits real homework record) --}}
        <x-ui.modal name="homework-upload" title="Nộp bài tập" max-width="md">
            <p class="mb-4 flex items-center gap-2 text-sm font-bold text-on-surface">
                <span class="material-symbols-outlined text-primary">cloud_upload</span>
                <span x-text="'Nộp bài: ' + uploadTitle"></span>
            </p>

            <form id="homework-upload-form" action="{{ route('portal.student.homework.submit') }}" method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                @csrf
                <input type="hidden" name="student_id" :value="studentId">
                <input type="hidden" name="homework_type" :value="uploadType">

                <x-ui.field label="Chọn tệp tin bài làm (Video / Ảnh / PDF)" name="attachment" for="homework-upload-attachment">
                    <div class="border-2 border-dashed border-outline-variant hover:border-primary-container rounded-xl p-6 text-center cursor-pointer bg-surface-container-low transition">
                        <span class="material-symbols-outlined text-[36px] text-on-surface-variant/70 block mb-1">attach_file</span>
                        <span class="text-xs text-on-surface-variant block">Kéo thả hoặc bấm để chọn tệp tải lên</span>
                        <span class="text-[10px] text-on-surface-variant/70 block mt-1">Hỗ trợ MP4, MOV, PNG, JPG, PDF (tối đa 50MB)</span>
                        <input type="file" id="homework-upload-attachment" name="attachment" class="mt-3 text-xs w-full text-center">
                    </div>
                </x-ui.field>

                <x-ui.textarea id="homework-upload-notes" name="notes" label="Ghi chú gửi thầy cô (Tùy chọn)" rows="3" placeholder="Nhập ghi chú cho bài nộp..." />
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'homework-upload')">
                    Hủy
                </x-ui.button>
                <x-ui.button type="submit" form="homework-upload-form" icon="send">
                    <span>Xác nhận nộp bài</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
        {{-- Edit Modal (Updates existing homework submission) --}}
        <x-ui.modal name="homework-edit" title="Chỉnh sửa / Cập nhật bài nộp" max-width="md">
            <form id="homework-edit-form" :action="'{{ url('portal/student-homework') }}/' + editId + '/update'" method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                @csrf
                <x-ui.field label="Cập nhật lại tệp tin bài làm (Nếu muốn thay thế tệp cũ)" name="attachment" for="homework-edit-attachment"
                            hint="Để trống nếu muốn giữ nguyên tệp tin đã tải lên trước đó.">
                    <input type="file" id="homework-edit-attachment" name="attachment" class="w-full text-xs p-2 border border-outline-variant rounded-lg bg-surface-container-low">
                </x-ui.field>

                <x-ui.textarea id="homework-edit-notes" name="notes" label="Ghi chú gửi thầy cô" x-model="editNotes" rows="3" />
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'homework-edit')">
                    Hủy
                </x-ui.button>
                <x-ui.button type="submit" form="homework-edit-form" icon="save">
                    <span>Lưu cập nhật</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
