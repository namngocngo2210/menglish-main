<x-app-layout>
    <x-ui.page-header :title="'Chỉnh Sửa Bộ Đề & Quản Lý Câu Hỏi: ' . $test->title" icon="edit_document" :back="route('placement-tests.index')">
        <x-slot:meta>Mã đề: <strong class="font-mono text-on-surface">{{ $test->code }}</strong> · Cập nhật cấu hình, audio, bài đọc, câu hỏi và đáp án chấm</x-slot:meta>
    </x-ui.page-header>

    @php
        // Đề chưa có câu hỏi: bắt đầu với 1 câu trống (không soạn sẵn nội dung mẫu / file nghe giả).
        $defaultQuestions = [[
            'id' => 1, 'skill' => 'reading', 'type' => 'multiple_choice', 'title' => '', 'audio_url' => '', 'passage' => '',
            'options' => [['key' => 'A', 'text' => ''], ['key' => 'B', 'text' => ''], ['key' => 'C', 'text' => ''], ['key' => 'D', 'text' => '']],
            'correct_answer' => 'A', 'points' => 1, 'explanation' => '',
        ]];

        $currentQuestions = old('questions', $test->questions ?? $defaultQuestions);
        if (empty($currentQuestions)) {
            $currentQuestions = $defaultQuestions;
        }
    @endphp

    <div class="max-w-5xl mx-auto space-y-6" x-data="testBuilder({
        questions: @json($currentQuestions)
    })" x-on:modal-closed.window="$event.detail === 'placement-question' && (showModal = false, editIndex = null)">
        <form action="{{ route('placement-tests.update', $test->id) }}" method="POST" @submit="syncBeforeSubmit($event)" class="space-y-6">
            @csrf
            @method('PUT')

            {{-- Hidden synchronized questions payload --}}
            <input type="hidden" name="questions" x-ref="questionsInput" :value="JSON.stringify(questions)" />
            <input type="hidden" name="questions_count" x-ref="questionsCountInput" :value="questions.length" />

            {{-- 1. General Test Info --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-6 space-y-4">
                <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider pb-2 border-b border-surface-container-highest flex items-center justify-between">
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-base">info</span>
                        1. Thông tin cấu hình bộ đề
                    </span>
                    <span class="text-xs font-normal text-on-surface-variant/70">Các trường đánh dấu <span class="text-error">*</span> là bắt buộc</span>
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                    <x-ui.input label="Mã đề thi (Code)" :value="$test->code" disabled class="font-mono font-bold" />
                    <x-ui.input type="number" name="duration_minutes" label="Thời gian làm bài (Phút)" :value="$test->duration_minutes" min="5" required class="font-mono font-bold" />
                    <x-ui.select name="target_level" label="Trình độ mục tiêu" required :value="$test->target_level" class="font-semibold" :options="[
                        'Tổng hợp A1 - B2' => 'Tổng hợp A1 - B2',
                        'IELTS Foundation (3.0 - 4.5)' => 'IELTS Foundation (3.0 - 4.5)',
                        'IELTS Intensive (5.0 - 6.5)' => 'IELTS Intensive (5.0 - 6.5)',
                        'IELTS Master (6.5 - 7.5+)' => 'IELTS Master (6.5 - 7.5+)',
                        'Giao tiếp Quốc tế B1 - B2' => 'Giao tiếp Quốc tế B1 - B2',
                    ]" />
                    <div class="md:col-span-3">
                        <x-ui.input name="title" label="Tiêu đề đề thi" :value="$test->title" required class="font-bold" />
                    </div>
                    <div class="md:col-span-3">
                        <x-ui.textarea name="description" label="Mô tả / Hướng dẫn thí sinh khi bắt đầu" rows="2" :value="$test->description" />
                    </div>
                    <div class="md:col-span-3 flex items-center gap-2 pt-1">
                        <input type="checkbox" name="is_active" id="is_active" value="1" {{ $test->is_active ? 'checked' : '' }} class="rounded border-outline-variant text-primary focus:ring-primary-container">
                        <label for="is_active" class="text-xs font-semibold text-on-surface">Đang kích hoạt đề thi (Hiển thị cho Lead / Thí sinh truy cập làm bài)</label>
                    </div>
                </div>
            </div>

            {{-- 2. Question Builder (Trình soạn thảo câu hỏi đa định dạng) --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-6 space-y-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-surface-container-highest">
                    <div>
                        <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-tertiary text-base">quiz</span>
                            2. Danh sách câu hỏi &amp; Kiểu bài thi
                            <span class="px-2 py-0.5 rounded-full bg-tertiary/10 text-tertiary border border-tertiary/30 font-mono text-xs font-bold" x-text="questions.length + ' câu hỏi'"></span>
                        </h2>
                        <p class="text-xs text-on-surface-variant mt-0.5">Tạo và cấu hình các dạng bài: Trắc nghiệm A/B/C/D, Audio Nghe, Đọc hiểu, Điền từ, Viết luận, Vấn đáp</p>
                    </div>

                    <x-ui.button variant="success" size="sm" icon="add_circle" x-on:click="openAddModal()">Thêm câu hỏi mới</x-ui.button>
                </div>

                {{-- Skill Filter Tabs --}}
                <div class="flex items-center gap-2 overflow-x-auto pb-1 text-xs">
                    <button 
                        type="button" 
                        @click="filterSkill = 'all'" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap cursor-pointer"
                        :class="filterSkill === 'all' ? 'bg-inverse-surface text-white' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high'"
                    >
                        Tất cả (<span x-text="questions.length"></span>)
                    </button>
                    <button 
                        type="button" 
                        @click="filterSkill = 'listening'" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap cursor-pointer flex items-center gap-1.5"
                        :class="filterSkill === 'listening' ? 'bg-secondary text-white' : 'bg-secondary/10 text-secondary hover:bg-secondary/20'"
                    >
                        <span class="material-symbols-outlined text-sm">headphones</span>
                        <span>Listening (<span x-text="questions.filter(q => q.skill === 'listening').length"></span>)</span>
                    </button>
                    <button 
                        type="button" 
                        @click="filterSkill = 'reading'" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap cursor-pointer flex items-center gap-1.5"
                        :class="filterSkill === 'reading' ? 'bg-info text-white' : 'bg-info/10 text-info hover:bg-info/20'"
                    >
                        <span class="material-symbols-outlined text-sm">menu_book</span>
                        <span>Reading (<span x-text="questions.filter(q => q.skill === 'reading').length"></span>)</span>
                    </button>
                    <button 
                        type="button" 
                        @click="filterSkill = 'grammar'" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap cursor-pointer flex items-center gap-1.5"
                        :class="filterSkill === 'grammar' ? 'bg-warning text-white' : 'bg-warning-container text-on-warning-container hover:bg-warning/20'"
                    >
                        <span class="material-symbols-outlined text-sm">spellcheck</span>
                        <span>Grammar &amp; Điền từ (<span x-text="questions.filter(q => q.skill === 'grammar').length"></span>)</span>
                    </button>
                    <button 
                        type="button" 
                        @click="filterSkill = 'writing'" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap cursor-pointer flex items-center gap-1.5"
                        :class="filterSkill === 'writing' ? 'bg-purple-600 text-white' : 'bg-purple-50 text-purple-700 hover:bg-purple-100'"
                    >
                        <span class="material-symbols-outlined text-sm">edit_note</span>
                        <span>Writing (<span x-text="questions.filter(q => q.skill === 'writing').length"></span>)</span>
                    </button>
                    <button 
                        type="button" 
                        @click="filterSkill = 'speaking'" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap cursor-pointer flex items-center gap-1.5"
                        :class="filterSkill === 'speaking' ? 'bg-error text-white' : 'bg-error/10 text-error hover:bg-error/20'"
                    >
                        <span class="material-symbols-outlined text-sm">mic</span>
                        <span>Speaking (<span x-text="questions.filter(q => q.skill === 'speaking').length"></span>)</span>
                    </button>
                </div>

                {{-- Questions List Container --}}
                <div class="space-y-3.5">
                    <template x-for="(q, idx) in filteredQuestions" :key="q.id || idx">
                        <div class="bg-surface-container-low/90 rounded-2xl p-4 border border-surface-container-highest/90 hover:border-tertiary/50 hover:bg-surface-container-lowest transition shadow-xs group">
                            <div class="flex items-start justify-between gap-3 mb-2.5">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="w-6 h-6 rounded-lg bg-inverse-surface text-white flex items-center justify-center font-mono font-bold text-xs" x-text="'#' + (idx + 1)"></span>
                                    
                                    {{-- Skill Badge --}}
                                    <span 
                                        class="px-2 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-wider"
                                        :class="{
                                            'bg-secondary/10 text-on-secondary-fixed border border-secondary/30': q.skill === 'listening',
                                            'bg-info/10 text-on-info-container border border-info/30': q.skill === 'reading',
                                            'bg-warning-container text-on-warning-container border border-warning/30': q.skill === 'grammar',
                                            'bg-purple-100 text-purple-800 border border-purple-200': q.skill === 'writing',
                                            'bg-error/10 text-on-error-container border border-error/30': q.skill === 'speaking'
                                        }"
                                        x-text="getSkillLabel(q.skill)"
                                    ></span>

                                    {{-- Type Badge --}}
                                    <span class="px-2 py-0.5 rounded-md bg-surface-container-high/70 text-on-surface-variant text-[10px] font-semibold" x-text="getTypeLabel(q.type)"></span>

                                    <span class="text-[11px] text-on-surface-variant/70 font-medium" x-text="'(' + (q.points || 1) + ' điểm)'"></span>
                                </div>

                                {{-- Action Buttons --}}
                                <div class="flex items-center gap-1 shrink-0">
                                    <x-ui.button variant="ghost" size="sm" icon="arrow_upward" x-on:click="moveUp(idx)" x-bind:disabled="idx === 0" title="Chuyển lên trên" aria-label="Chuyển lên trên" />
                                    <x-ui.button variant="ghost" size="sm" icon="arrow_downward" x-on:click="moveDown(idx)" x-bind:disabled="idx === filteredQuestions.length - 1" title="Chuyển xuống dưới" aria-label="Chuyển xuống dưới" />
                                    <x-ui.button variant="ghost" size="sm" icon="content_copy" x-on:click="duplicateQuestion(idx)" title="Nhân bản câu hỏi" aria-label="Nhân bản câu hỏi" />
                                    <x-ui.button variant="ghost" size="sm" icon="edit" x-on:click="editQuestion(idx)" title="Chỉnh sửa câu hỏi" aria-label="Chỉnh sửa câu hỏi" />
                                    <x-ui.button variant="danger-text" size="sm" icon="delete" x-on:click="deleteQuestion(idx)" title="Xóa câu hỏi" aria-label="Xóa câu hỏi" />
                                </div>
                            </div>

                            {{-- Question Title --}}
                            <div class="text-xs font-bold text-on-surface mb-2 leading-relaxed" x-text="q.title"></div>

                            {{-- Audio attachment preview if any --}}
                            <template x-if="q.audio_url">
                                <div class="mb-2.5 p-2 rounded-xl bg-secondary/5 border border-secondary/20 flex items-center gap-2 text-xs">
                                    <span class="material-symbols-outlined text-secondary text-base">volume_up</span>
                                    <span class="text-[11px] font-mono text-on-secondary-fixed truncate" x-text="'Audio MP3: ' + q.audio_url"></span>
                                </div>
                            </template>

                            {{-- Reading Passage preview if any --}}
                            <template x-if="q.passage">
                                <div class="mb-2.5 p-2.5 rounded-xl bg-secondary/5 border border-secondary/20 text-[11px] text-on-surface-variant italic line-clamp-2" x-text="'Đoạn văn: ' + q.passage"></div>
                            </template>

                            {{-- Multiple Choice Options preview --}}
                            <template x-if="q.type === 'multiple_choice' && q.options">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                    <template x-for="opt in q.options" :key="opt.key">
                                        <div 
                                            class="p-2 rounded-xl border flex items-center gap-2"
                                            :class="opt.key === q.correct_answer ? 'bg-tertiary/10 border-tertiary/30 text-on-tertiary-container font-semibold' : 'bg-surface-container-lowest border-surface-container-highest text-on-surface-variant'"
                                        >
                                            <span 
                                                class="w-5 h-5 rounded-md flex items-center justify-center font-bold text-[10px]"
                                                :class="opt.key === q.correct_answer ? 'bg-tertiary text-white' : 'bg-surface-container text-on-surface-variant'"
                                                x-text="opt.key"
                                            ></span>
                                            <span class="truncate" x-text="opt.text"></span>
                                            <template x-if="opt.key === q.correct_answer">
                                                <span class="material-symbols-outlined text-[14px] text-tertiary ml-auto">check_circle</span>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Fill in the blank preview --}}
                            <template x-if="q.type === 'fill_blank'">
                                <div class="p-2 rounded-xl bg-warning-container border border-warning/30 text-xs text-on-warning-container flex items-center gap-2">
                                    <span class="material-symbols-outlined text-warning text-sm">spellcheck</span>
                                    <span>Đáp án chính xác: <strong class="font-mono" x-text="q.correct_answer"></strong></span>
                                </div>
                            </template>

                            {{-- Essay preview --}}
                            <template x-if="q.type === 'essay'">
                                <div class="p-2.5 rounded-xl bg-purple-50 border border-purple-200 text-[11px] text-purple-900 space-y-1">
                                    <div><strong>Yêu cầu số từ:</strong> <span x-text="q.min_words || 100"></span> từ trở lên</div>
                                    <template x-if="q.rubric_note">
                                        <div class="italic text-purple-700" x-text="'Barem chấm: ' + q.rubric_note"></div>
                                    </template>
                                </div>
                            </template>

                            {{-- Speaking Cue Points preview --}}
                            <template x-if="q.type === 'speaking_prompt'">
                                <div class="p-2.5 rounded-xl bg-error/10 border border-error/30 text-[11px] text-on-error-container whitespace-pre-line" x-text="q.cue_points || 'Gợi ý trả lời vấn đáp...'"></div>
                            </template>
                        </div>
                    </template>

                    {{-- Empty state --}}
                    <template x-if="filteredQuestions.length === 0">
                        <x-ui.empty-state icon="quiz" title="Chưa có câu hỏi nào trong danh mục này" description='Bấm nút "Thêm câu hỏi mới" bên trên để bắt đầu soạn đề thi.' class="rounded-2xl border border-dashed border-outline-variant bg-surface-container-low" />
                    </template>
                </div>
            </div>

            {{-- Form Actions --}}
            <div class="flex items-center justify-between pt-4 border-t border-surface-container-highest bg-surface-container-lowest rounded-2xl p-4 border shadow-xs">
                <x-ui.button variant="secondary" :href="route('placement-tests.index')">Hủy bỏ</x-ui.button>
                <x-ui.button type="submit" icon="save">Cập nhật đề thi &amp; Câu hỏi vào CSDL</x-ui.button>
            </div>
        </form>

        {{-- 3. Modal Soạn Thảo / Chỉnh Sửa Câu Hỏi (Question Modal) — mở/đóng qua open-modal / close-modal 'placement-question' --}}
        <x-ui.modal name="placement-question" max-width="2xl" bare>
            <div class="flex shrink-0 items-center justify-between gap-md border-b border-surface-container px-lg py-md">
                <div>
                    <h3 id="modal-placement-question-title" class="text-sm font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-tertiary text-base" x-text="editIndex !== null ? 'edit_note' : 'add_circle'"></span>
                        <span x-text="editIndex !== null ? 'Chỉnh sửa câu hỏi #' + (editIndex + 1) : 'Thêm câu hỏi mới vào đề thi'"></span>
                    </h3>
                    <p class="text-[11px] text-on-surface-variant">Cấu hình kỹ năng, kiểu bài thi và đáp án chấm điểm</p>
                </div>
                <x-ui.button variant="ghost" icon="close" x-on:click="closeModal()" aria-label="Đóng" />
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-lg py-md space-y-4 text-xs">
                {{-- Skill & Type row --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.field label="Kỹ năng (Skill)" required>
                        <x-ui.select x-model="modalForm.skill" class="font-semibold">
                            <option value="listening">🎧 Listening (Nghe hiểu)</option>
                            <option value="reading">📖 Reading (Đọc hiểu)</option>
                            <option value="grammar">✍️ Grammar &amp; Vocabulary (Ngữ pháp)</option>
                            <option value="writing">📝 Writing (Viết luận)</option>
                            <option value="speaking">🎙️ Speaking (Vấn đáp / Nói)</option>
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field label="Định dạng kiểu bài (Type)" required>
                        <x-ui.select x-model="modalForm.type" class="font-semibold">
                            <option value="multiple_choice">Trắc nghiệm 4 lựa chọn (A, B, C, D)</option>
                            <option value="fill_blank">Điền từ vào chỗ trống</option>
                            <option value="essay">Tự luận / Viết đoạn văn (Essay)</option>
                            <option value="speaking_prompt">Chủ đề phỏng vấn Speaking</option>
                        </x-ui.select>
                    </x-ui.field>
                </div>

                {{-- Audio URL (If listening) --}}
                <template x-if="modalForm.skill === 'listening'">
                    <div class="p-3 bg-secondary/5 rounded-xl border border-secondary/20">
                        <x-ui.input label="Đường dẫn file Audio MP3 (Audio URL)" x-model="modalForm.audio_url" placeholder="Đường dẫn file nghe (.mp3)" hint="Hỗ trợ tệp MP3 lưu tại Media Manager hoặc link CDN trực tiếp." class="font-mono" />
                    </div>
                </template>

                {{-- Reading Passage (If reading) --}}
                <template x-if="modalForm.skill === 'reading'">
                    <div class="p-3 bg-secondary/5 rounded-xl border border-secondary/20">
                        <x-ui.textarea label="Đoạn văn bài đọc (Reading Passage - tùy chọn)" x-model="modalForm.passage" rows="3" placeholder="Nhập văn bản bài đọc nếu câu hỏi dựa vào đoạn văn..." />
                    </div>
                </template>

                {{-- Question Title / Prompt --}}
                <x-ui.textarea label="Nội dung câu hỏi / Đề bài" x-model="modalForm.title" rows="2" placeholder="Ví dụ: What is the main idea of the passage?" required class="font-bold" />

                {{-- Multiple Choice Options Form --}}
                <template x-if="modalForm.type === 'multiple_choice'">
                    <div class="space-y-3 pt-2 border-t border-surface-container-highest">
                        <label class="block font-semibold text-on-surface-variant">4 Lựa chọn trả lời &amp; Tích chọn đáp án đúng:</label>
                        <div class="space-y-2">
                            <template x-for="(opt, idx) in modalForm.options" :key="opt.key">
                                <div class="flex items-center gap-2 p-2 rounded-xl border" :class="modalForm.correct_answer === opt.key ? 'bg-tertiary/5 border-tertiary/30' : 'bg-surface-container-low border-surface-container-highest'">
                                    <input 
                                        type="radio" 
                                        name="correct_opt_radio" 
                                        :value="opt.key" 
                                        x-model="modalForm.correct_answer" 
                                        class="text-tertiary focus:ring-tertiary cursor-pointer"
                                    />
                                    <span class="w-6 font-bold text-center text-xs font-mono" x-text="opt.key"></span>
                                    <input 
                                        type="text" 
                                        x-model="opt.text" 
                                        placeholder="Nhập nội dung phương án..." 
                                        class="w-full text-xs rounded-lg border border-outline-variant p-2 bg-surface-container-lowest focus:border-tertiary focus:ring-tertiary" 
                                    />
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Fill in blank Form --}}
                <template x-if="modalForm.type === 'fill_blank'">
                    <div class="pt-2 border-t border-surface-container-highest">
                        <x-ui.input label="Từ / Cụm từ đáp án chính xác:" x-model="modalForm.correct_answer" placeholder="Ví dụ: had studied" class="font-mono font-bold" />
                    </div>
                </template>

                {{-- Essay Form --}}
                <template x-if="modalForm.type === 'essay'">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-surface-container-highest">
                        <x-ui.input type="number" label="Số từ tối thiểu:" x-model="modalForm.min_words" placeholder="100" class="font-mono" />
                        <x-ui.input label="Ghi chú barem chấm điểm:" x-model="modalForm.rubric_note" placeholder="Tiêu chí chấm điểm..." />
                    </div>
                </template>

                {{-- Speaking Prompt Form --}}
                <template x-if="modalForm.type === 'speaking_prompt'">
                    <div class="pt-2 border-t border-surface-container-highest">
                        <x-ui.textarea label="Gợi ý trả lời / Cue card points:" x-model="modalForm.cue_points" rows="3" x-bind:placeholder="'• Where you went...\n• Who you went with...'" />
                    </div>
                </template>

                {{-- Explanation & Points --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2 border-t border-surface-container-highest">
                    <div class="sm:col-span-2">
                        <x-ui.input label="Giải thích đáp án / Hướng dẫn (Tùy chọn):" x-model="modalForm.explanation" placeholder="Lý do chọn đáp án này..." />
                    </div>
                    <x-ui.input type="number" label="Điểm câu hỏi:" x-model="modalForm.points" min="1" max="10" class="font-mono font-bold" />
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap justify-end gap-sm border-t border-surface-container bg-surface-container-low px-lg py-md">
                <x-ui.button variant="secondary" x-on:click="closeModal()">Đóng</x-ui.button>
                <x-ui.button variant="success" x-on:click="saveModalQuestion()">Lưu câu hỏi</x-ui.button>
            </div>
        </x-ui.modal>
    </div>

    <script>
        function testBuilder(initialData) {
            return {
                questions: initialData.questions || [],
                filterSkill: 'all',
                showModal: false,
                editIndex: null,
                modalForm: {
                    id: null,
                    skill: 'reading',
                    type: 'multiple_choice',
                    title: '',
                    audio_url: '',
                    passage: '',
                    options: [
                        { key: 'A', text: '' },
                        { key: 'B', text: '' },
                        { key: 'C', text: '' },
                        { key: 'D', text: '' }
                    ],
                    correct_answer: 'A',
                    points: 1,
                    min_words: 100,
                    rubric_note: '',
                    cue_points: '',
                    explanation: ''
                },

                get filteredQuestions() {
                    if (this.filterSkill === 'all') return this.questions;
                    return this.questions.filter(q => q.skill === this.filterSkill);
                },

                getSkillLabel(skill) {
                    const map = {
                        listening: 'Nghe hiểu (Listening)',
                        reading: 'Đọc hiểu (Reading)',
                        grammar: 'Ngữ pháp (Grammar)',
                        writing: 'Viết (Writing)',
                        speaking: 'Nói (Speaking)'
                    };
                    return map[skill] || skill;
                },

                getTypeLabel(type) {
                    const map = {
                        multiple_choice: 'Trắc nghiệm A/B/C/D',
                        fill_blank: 'Điền từ vào chỗ trống',
                        essay: 'Viết đoạn văn / Tự luận',
                        speaking_prompt: 'Phỏng vấn / Vấn đáp'
                    };
                    return map[type] || type;
                },

                openAddModal() {
                    this.editIndex = null;
                    this.modalForm = {
                        id: Date.now(),
                        skill: 'reading',
                        type: 'multiple_choice',
                        title: '',
                        audio_url: '',
                        passage: '',
                        options: [
                            { key: 'A', text: '' },
                            { key: 'B', text: '' },
                            { key: 'C', text: '' },
                            { key: 'D', text: '' }
                        ],
                        correct_answer: 'A',
                        points: 1,
                        min_words: 100,
                        rubric_note: '',
                        cue_points: '',
                        explanation: ''
                    };
                    this.showModal = true;
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'placement-question' }));
                },

                editQuestion(index) {
                    this.editIndex = index;
                    const q = this.filteredQuestions[index];
                    this.modalForm = JSON.parse(JSON.stringify(q));
                    if (!this.modalForm.options || this.modalForm.options.length === 0) {
                        this.modalForm.options = [
                            { key: 'A', text: '' },
                            { key: 'B', text: '' },
                            { key: 'C', text: '' },
                            { key: 'D', text: '' }
                        ];
                    }
                    this.showModal = true;
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'placement-question' }));
                },

                saveModalQuestion() {
                    if (!this.modalForm.title.trim()) {
                        window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Vui lòng nhập nội dung câu hỏi!', type: 'error' } }));
                        return;
                    }

                    const questionData = JSON.parse(JSON.stringify(this.modalForm));

                    if (this.editIndex !== null) {
                        const target = this.filteredQuestions[this.editIndex];
                        const realIndex = this.questions.findIndex(q => q === target);
                        if (realIndex !== -1) {
                            this.questions[realIndex] = questionData;
                        }
                    } else {
                        this.questions.push(questionData);
                    }

                    this.closeModal();
                },

                closeModal() {
                    this.showModal = false;
                    this.editIndex = null;
                    window.dispatchEvent(new CustomEvent('close-modal', { detail: 'placement-question' }));
                },

                deleteQuestion(index) {
                    if (!confirm('Bạn có chắc chắn muốn xóa câu hỏi này?')) return;
                    const target = this.filteredQuestions[index];
                    this.questions = this.questions.filter(q => q !== target);
                },

                duplicateQuestion(index) {
                    const target = this.filteredQuestions[index];
                    const copy = JSON.parse(JSON.stringify(target));
                    copy.id = Date.now();
                    copy.title = copy.title + ' (Bản sao)';
                    this.questions.push(copy);
                },

                moveUp(index) {
                    if (index <= 0) return;
                    const target = this.filteredQuestions[index];
                    const prevTarget = this.filteredQuestions[index - 1];
                    const realIdx = this.questions.findIndex(q => q === target);
                    const prevRealIdx = this.questions.findIndex(q => q === prevTarget);
                    
                    if (realIdx !== -1 && prevRealIdx !== -1) {
                        const temp = this.questions[realIdx];
                        this.questions[realIdx] = this.questions[prevRealIdx];
                        this.questions[prevRealIdx] = temp;
                    }
                },

                moveDown(index) {
                    if (index >= this.filteredQuestions.length - 1) return;
                    const target = this.filteredQuestions[index];
                    const nextTarget = this.filteredQuestions[index + 1];
                    const realIdx = this.questions.findIndex(q => q === target);
                    const nextRealIdx = this.questions.findIndex(q => q === nextTarget);
                    
                    if (realIdx !== -1 && nextRealIdx !== -1) {
                        const temp = this.questions[realIdx];
                        this.questions[realIdx] = this.questions[nextRealIdx];
                        this.questions[nextRealIdx] = temp;
                    }
                },

                syncBeforeSubmit(e) {
                    const jsonStr = JSON.stringify(this.questions);
                    if (this.$refs.questionsInput) {
                        this.$refs.questionsInput.value = jsonStr;
                    }
                    if (this.$refs.questionsCountInput) {
                        this.$refs.questionsCountInput.value = this.questions.length;
                    }
                }
            };
        }
    </script>
</x-app-layout>
