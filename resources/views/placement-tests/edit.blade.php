<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('placement-tests.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-teal-600">edit_document</span>
                    Chỉnh Sửa Bộ Đề &amp; Quản Lý Câu Hỏi: {{ $test->title }}
                </h1>
                <p class="text-xs text-gray-500">Mã đề: <strong class="font-mono text-gray-800">{{ $test->code }}</strong> · Cập nhật cấu hình, audio, bài đọc, câu hỏi và đáp án chấm</p>
            </div>
        </div>
    </x-slot>

    @php
        $defaultQuestions = [
            [
                'id' => 1,
                'skill' => 'listening',
                'type' => 'multiple_choice',
                'title' => "What is the passenger's final destination in the conversation?",
                'audio_url' => '/uploads/2026/dethitest/de-test-lop-6-len-7/track-1-4-20260819105025-7k9aa.mp3',
                'passage' => 'Listen to the audio clip at Customer Service Desk.',
                'options' => [
                    ['key' => 'A', 'text' => 'London Heathrow'],
                    ['key' => 'B', 'text' => 'Melbourne International Airport'],
                    ['key' => 'C', 'text' => 'Tokyo Narita'],
                    ['key' => 'D', 'text' => 'Singapore Changi'],
                ],
                'correct_answer' => 'B',
                'points' => 1,
                'explanation' => 'The passenger confirms connecting flight to Melbourne.',
            ],
            [
                'id' => 2,
                'skill' => 'reading',
                'type' => 'multiple_choice',
                'title' => 'According to the passage, what is the primary benefit of renewable energy?',
                'passage' => 'Renewable energy sources, such as solar and wind power, emit little to no greenhouse gases during operation. In addition, they decrease reliance on finite fossil fuel reserves and stimulate local job growth in clean tech sectors.',
                'options' => [
                    ['key' => 'A', 'text' => 'It eliminates the need for power grids'],
                    ['key' => 'B', 'text' => 'It significantly reduces greenhouse gas emissions'],
                    ['key' => 'C', 'text' => 'It requires no initial capital investment'],
                    ['key' => 'D', 'text' => 'It operates without any maintenance'],
                ],
                'correct_answer' => 'B',
                'points' => 1,
                'explanation' => 'The passage explicitly states that renewable energy emits little to no greenhouse gases.',
            ],
            [
                'id' => 3,
                'skill' => 'grammar',
                'type' => 'fill_blank',
                'title' => 'Complete the sentence: If she _____ (study) harder last month, she would have passed the IELTS exam.',
                'correct_answer' => 'had studied',
                'points' => 1,
                'explanation' => 'Third conditional structure: If + S + had + V3/ed, S + would have + V3/ed.',
            ],
            [
                'id' => 4,
                'skill' => 'writing',
                'type' => 'essay',
                'title' => 'Writing Task: Some people believe that studying online is more effective than traditional classroom learning. Discuss both views and give your opinion.',
                'min_words' => 120,
                'rubric_note' => 'Chấm theo tiêu chí: Task Response, Coherence & Cohesion, Lexical Resource, Grammar Accuracy.',
                'points' => 9,
            ],
            [
                'id' => 5,
                'skill' => 'speaking',
                'type' => 'speaking_prompt',
                'title' => 'Speaking Part 2: Describe a memorable journey or trip you took.',
                'cue_points' => "• Where you went and who you went with\n• How you travelled there\n• What you did during the trip\n• And explain why this trip was so memorable for you",
                'points' => 9,
            ],
        ];

        $currentQuestions = old('questions', $test->questions ?? $defaultQuestions);
        if (empty($currentQuestions)) {
            $currentQuestions = $defaultQuestions;
        }
    @endphp

    <div class="max-w-5xl mx-auto space-y-6" x-data="testBuilder({
        questions: @json($currentQuestions)
    })">
        <form action="{{ route('placement-tests.update', $test->id) }}" method="POST" @submit="syncBeforeSubmit($event)" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Hidden synchronized questions payload -->
            <input type="hidden" name="questions" x-ref="questionsInput" :value="JSON.stringify(questions)" />
            <input type="hidden" name="questions_count" x-ref="questionsCountInput" :value="questions.length" />

            <!-- 1. General Test Info -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-4">
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider pb-2 border-b border-gray-100 flex items-center justify-between">
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-base">info</span>
                        1. Thông tin cấu hình bộ đề
                    </span>
                    <span class="text-xs font-normal text-gray-400">Các trường đánh dấu <span class="text-rose-500">*</span> là bắt buộc</span>
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Mã đề thi (Code)</label>
                        <input type="text" value="{{ $test->code }}" disabled class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 bg-gray-50 p-2.5 text-gray-500 cursor-not-allowed" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Thời gian làm bài (Phút) <span class="text-rose-500">*</span></label>
                        <input type="number" name="duration_minutes" value="{{ old('duration_minutes', $test->duration_minutes) }}" min="5" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5 focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Trình độ mục tiêu <span class="text-rose-500">*</span></label>
                        <select name="target_level" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-primary focus:ring-primary">
                            <option value="Tổng hợp A1 - B2" {{ $test->target_level === 'Tổng hợp A1 - B2' ? 'selected' : '' }}>Tổng hợp A1 - B2</option>
                            <option value="IELTS Foundation (3.0 - 4.5)" {{ $test->target_level === 'IELTS Foundation (3.0 - 4.5)' ? 'selected' : '' }}>IELTS Foundation (3.0 - 4.5)</option>
                            <option value="IELTS Intensive (5.0 - 6.5)" {{ $test->target_level === 'IELTS Intensive (5.0 - 6.5)' ? 'selected' : '' }}>IELTS Intensive (5.0 - 6.5)</option>
                            <option value="IELTS Master (6.5 - 7.5+)" {{ $test->target_level === 'IELTS Master (6.5 - 7.5+)' ? 'selected' : '' }}>IELTS Master (6.5 - 7.5+)</option>
                            <option value="Giao tiếp Quốc tế B1 - B2" {{ $test->target_level === 'Giao tiếp Quốc tế B1 - B2' ? 'selected' : '' }}>Giao tiếp Quốc tế B1 - B2</option>
                        </select>
                    </div>
                    <div class="md:col-span-3">
                        <label class="block font-semibold text-gray-700 mb-1">Tiêu đề đề thi <span class="text-rose-500">*</span></label>
                        <input type="text" name="title" value="{{ old('title', $test->title) }}" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold focus:border-primary focus:ring-primary" />
                    </div>
                    <div class="md:col-span-3">
                        <label class="block font-semibold text-gray-700 mb-1">Mô tả / Hướng dẫn thí sinh khi bắt đầu</label>
                        <textarea name="description" rows="2" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary focus:ring-primary">{{ old('description', $test->description) }}</textarea>
                    </div>
                    <div class="md:col-span-3 flex items-center gap-2 pt-1">
                        <input type="checkbox" name="is_active" id="is_active" value="1" {{ $test->is_active ? 'checked' : '' }} class="rounded border-gray-300 text-primary focus:ring-primary">
                        <label for="is_active" class="text-xs font-semibold text-gray-800">Đang kích hoạt đề thi (Hiển thị cho Lead / Thí sinh truy cập làm bài)</label>
                    </div>
                </div>
            </div>

            <!-- 2. Question Builder (Trình soạn thảo câu hỏi đa định dạng) -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-gray-100">
                    <div>
                        <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-teal-600 text-base">quiz</span>
                            2. Danh sách câu hỏi &amp; Kiểu bài thi
                            <span class="px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 border border-teal-200 font-mono text-xs font-bold" x-text="questions.length + ' câu hỏi'"></span>
                        </h2>
                        <p class="text-xs text-gray-500 mt-0.5">Tạo và cấu hình các dạng bài: Trắc nghiệm A/B/C/D, Audio Nghe, Đọc hiểu, Điền từ, Viết luận, Vấn đáp</p>
                    </div>

                    <button 
                        type="button" 
                        @click="openAddModal()" 
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-xs font-bold shadow-sm transition cursor-pointer shrink-0"
                    >
                        <span class="material-symbols-outlined text-base">add_circle</span>
                        <span>Thêm câu hỏi mới</span>
                    </button>
                </div>

                <!-- Skill Filter Tabs -->
                <div class="flex items-center gap-2 overflow-x-auto pb-1 text-xs">
                    <button 
                        type="button" 
                        @click="filterSkill = 'all'" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap cursor-pointer"
                        :class="filterSkill === 'all' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    >
                        Tất cả (<span x-text="questions.length"></span>)
                    </button>
                    <button 
                        type="button" 
                        @click="filterSkill = 'listening'" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap cursor-pointer flex items-center gap-1.5"
                        :class="filterSkill === 'listening' ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'"
                    >
                        <span class="material-symbols-outlined text-sm">headphones</span>
                        <span>Listening (<span x-text="questions.filter(q => q.skill === 'listening').length"></span>)</span>
                    </button>
                    <button 
                        type="button" 
                        @click="filterSkill = 'reading'" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap cursor-pointer flex items-center gap-1.5"
                        :class="filterSkill === 'reading' ? 'bg-blue-600 text-white' : 'bg-blue-50 text-blue-700 hover:bg-blue-100'"
                    >
                        <span class="material-symbols-outlined text-sm">menu_book</span>
                        <span>Reading (<span x-text="questions.filter(q => q.skill === 'reading').length"></span>)</span>
                    </button>
                    <button 
                        type="button" 
                        @click="filterSkill = 'grammar'" 
                        class="px-3 py-1.5 rounded-xl font-bold transition whitespace-nowrap cursor-pointer flex items-center gap-1.5"
                        :class="filterSkill === 'grammar' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-700 hover:bg-amber-100'"
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
                        :class="filterSkill === 'speaking' ? 'bg-rose-600 text-white' : 'bg-rose-50 text-rose-700 hover:bg-rose-100'"
                    >
                        <span class="material-symbols-outlined text-sm">mic</span>
                        <span>Speaking (<span x-text="questions.filter(q => q.skill === 'speaking').length"></span>)</span>
                    </button>
                </div>

                <!-- Questions List Container -->
                <div class="space-y-3.5">
                    <template x-for="(q, idx) in filteredQuestions" :key="q.id || idx">
                        <div class="bg-gray-50/90 rounded-2xl p-4 border border-gray-200/90 hover:border-teal-500/50 hover:bg-white transition shadow-xs group">
                            <div class="flex items-start justify-between gap-3 mb-2.5">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="w-6 h-6 rounded-lg bg-gray-900 text-white flex items-center justify-center font-mono font-bold text-xs" x-text="'#' + (idx + 1)"></span>
                                    
                                    <!-- Skill Badge -->
                                    <span 
                                        class="px-2 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-wider"
                                        :class="{
                                            'bg-indigo-100 text-indigo-800 border border-indigo-200': q.skill === 'listening',
                                            'bg-blue-100 text-blue-800 border border-blue-200': q.skill === 'reading',
                                            'bg-amber-100 text-amber-800 border border-amber-200': q.skill === 'grammar',
                                            'bg-purple-100 text-purple-800 border border-purple-200': q.skill === 'writing',
                                            'bg-rose-100 text-rose-800 border border-rose-200': q.skill === 'speaking'
                                        }"
                                        x-text="getSkillLabel(q.skill)"
                                    ></span>

                                    <!-- Type Badge -->
                                    <span class="px-2 py-0.5 rounded-md bg-gray-200/70 text-gray-700 text-[10px] font-semibold" x-text="getTypeLabel(q.type)"></span>

                                    <span class="text-[11px] text-gray-400 font-medium" x-text="'(' + (q.points || 1) + ' điểm)'"></span>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex items-center gap-1 shrink-0">
                                    <button 
                                        type="button" 
                                        @click="moveUp(idx)" 
                                        :disabled="idx === 0"
                                        class="p-1 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-200/60 disabled:opacity-30 disabled:cursor-not-allowed transition" 
                                        title="Chuyển lên trên"
                                    >
                                        <span class="material-symbols-outlined text-[16px]">arrow_upward</span>
                                    </button>
                                    <button 
                                        type="button" 
                                        @click="moveDown(idx)" 
                                        :disabled="idx === filteredQuestions.length - 1"
                                        class="p-1 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-200/60 disabled:opacity-30 disabled:cursor-not-allowed transition" 
                                        title="Chuyển xuống dưới"
                                    >
                                        <span class="material-symbols-outlined text-[16px]">arrow_downward</span>
                                    </button>
                                    <button 
                                        type="button" 
                                        @click="duplicateQuestion(idx)" 
                                        class="p-1 rounded-lg text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition" 
                                        title="Nhân bản câu hỏi"
                                    >
                                        <span class="material-symbols-outlined text-[16px]">content_copy</span>
                                    </button>
                                    <button 
                                        type="button" 
                                        @click="editQuestion(idx)" 
                                        class="p-1 rounded-lg text-gray-400 hover:text-teal-600 hover:bg-teal-50 transition" 
                                        title="Chỉnh sửa câu hỏi"
                                    >
                                        <span class="material-symbols-outlined text-[16px]">edit</span>
                                    </button>
                                    <button 
                                        type="button" 
                                        @click="deleteQuestion(idx)" 
                                        class="p-1 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition" 
                                        title="Xóa câu hỏi"
                                    >
                                        <span class="material-symbols-outlined text-[16px]">delete</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Question Title -->
                            <div class="text-xs font-bold text-gray-900 mb-2 leading-relaxed" x-text="q.title"></div>

                            <!-- Audio attachment preview if any -->
                            <template x-if="q.audio_url">
                                <div class="mb-2.5 p-2 rounded-xl bg-indigo-50/70 border border-indigo-100 flex items-center gap-2 text-xs">
                                    <span class="material-symbols-outlined text-indigo-600 text-base">volume_up</span>
                                    <span class="text-[11px] font-mono text-indigo-900 truncate" x-text="'Audio MP3: ' + q.audio_url"></span>
                                </div>
                            </template>

                            <!-- Reading Passage preview if any -->
                            <template x-if="q.passage">
                                <div class="mb-2.5 p-2.5 rounded-xl bg-blue-50/50 border border-blue-100 text-[11px] text-gray-700 italic line-clamp-2" x-text="'Đoạn văn: ' + q.passage"></div>
                            </template>

                            <!-- Multiple Choice Options preview -->
                            <template x-if="q.type === 'multiple_choice' && q.options">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                    <template x-for="opt in q.options" :key="opt.key">
                                        <div 
                                            class="p-2 rounded-xl border flex items-center gap-2"
                                            :class="opt.key === q.correct_answer ? 'bg-emerald-50 border-emerald-300 text-emerald-900 font-semibold' : 'bg-white border-gray-200 text-gray-700'"
                                        >
                                            <span 
                                                class="w-5 h-5 rounded-md flex items-center justify-center font-bold text-[10px]"
                                                :class="opt.key === q.correct_answer ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600'"
                                                x-text="opt.key"
                                            ></span>
                                            <span class="truncate" x-text="opt.text"></span>
                                            <template x-if="opt.key === q.correct_answer">
                                                <span class="material-symbols-outlined text-[14px] text-emerald-600 ml-auto">check_circle</span>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <!-- Fill in the blank preview -->
                            <template x-if="q.type === 'fill_blank'">
                                <div class="p-2 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-amber-600 text-sm">spellcheck</span>
                                    <span>Đáp án chính xác: <strong class="font-mono" x-text="q.correct_answer"></strong></span>
                                </div>
                            </template>

                            <!-- Essay preview -->
                            <template x-if="q.type === 'essay'">
                                <div class="p-2.5 rounded-xl bg-purple-50 border border-purple-200 text-[11px] text-purple-900 space-y-1">
                                    <div><strong>Yêu cầu số từ:</strong> <span x-text="q.min_words || 100"></span> từ trở lên</div>
                                    <template x-if="q.rubric_note">
                                        <div class="italic text-purple-700" x-text="'Barem chấm: ' + q.rubric_note"></div>
                                    </template>
                                </div>
                            </template>

                            <!-- Speaking Cue Points preview -->
                            <template x-if="q.type === 'speaking_prompt'">
                                <div class="p-2.5 rounded-xl bg-rose-50 border border-rose-200 text-[11px] text-rose-900 whitespace-pre-line" x-text="q.cue_points || 'Gợi ý trả lời vấn đáp...'"></div>
                            </template>
                        </div>
                    </template>

                    <!-- Empty state -->
                    <template x-if="filteredQuestions.length === 0">
                        <div class="p-8 text-center bg-gray-50 rounded-2xl border border-dashed border-gray-300">
                            <span class="material-symbols-outlined text-4xl text-gray-300 mb-2">quiz</span>
                            <div class="text-xs font-bold text-gray-600">Chưa có câu hỏi nào trong danh mục này</div>
                            <p class="text-[11px] text-gray-400 mt-1">Bấm nút "Thêm câu hỏi mới" bên trên để bắt đầu soạn đề thi.</p>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between pt-4 border-t border-gray-100 bg-white rounded-2xl p-4 border shadow-xs">
                <a href="{{ route('placement-tests.index') }}" class="px-4 py-2 border border-gray-200 text-xs font-semibold text-gray-700 rounded-xl hover:bg-gray-50 transition">
                    Hủy bỏ
                </a>
                <button type="submit" class="px-6 py-2.5 bg-primary hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>Cập nhật đề thi &amp; Câu hỏi vào CSDL</span>
                </button>
            </div>
        </form>

        <!-- 3. Modal Soạn Thảo / Chỉnh Sửa Câu Hỏi (Question Modal) -->
        <div 
            x-show="showModal" 
            x-cloak 
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-gray-900/60 backdrop-blur-xs"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
        >
            <div 
                @click.away="closeModal()" 
                class="bg-white rounded-3xl border border-gray-200 shadow-2xl w-full max-w-2xl overflow-hidden my-8"
            >
                <div class="p-5 bg-gray-50 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-teal-600 text-base" x-text="editIndex !== null ? 'edit_note' : 'add_circle'"></span>
                            <span x-text="editIndex !== null ? 'Chỉnh sửa câu hỏi #' + (editIndex + 1) : 'Thêm câu hỏi mới vào đề thi'"></span>
                        </h3>
                        <p class="text-[11px] text-gray-500">Cấu hình kỹ năng, kiểu bài thi và đáp án chấm điểm</p>
                    </div>
                    <button type="button" @click="closeModal()" class="p-1 text-gray-400 hover:text-gray-700 rounded-lg hover:bg-gray-200/50 transition">
                        <span class="material-symbols-outlined text-lg">close</span>
                    </button>
                </div>

                <div class="p-6 space-y-4 max-h-[calc(85vh-130px)] overflow-y-auto text-xs">
                    <!-- Skill & Type row -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-semibold text-gray-700 mb-1">Kỹ năng (Skill) <span class="text-rose-500">*</span></label>
                            <select x-model="modalForm.skill" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-teal-500 focus:ring-teal-500">
                                <option value="listening">🎧 Listening (Nghe hiểu)</option>
                                <option value="reading">📖 Reading (Đọc hiểu)</option>
                                <option value="grammar">✍️ Grammar &amp; Vocabulary (Ngữ pháp)</option>
                                <option value="writing">📝 Writing (Viết luận)</option>
                                <option value="speaking">🎙️ Speaking (Vấn đáp / Nói)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-semibold text-gray-700 mb-1">Định dạng kiểu bài (Type) <span class="text-rose-500">*</span></label>
                            <select x-model="modalForm.type" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-teal-500 focus:ring-teal-500">
                                <option value="multiple_choice">Trắc nghiệm 4 lựa chọn (A, B, C, D)</option>
                                <option value="fill_blank">Điền từ vào chỗ trống</option>
                                <option value="essay">Tự luận / Viết đoạn văn (Essay)</option>
                                <option value="speaking_prompt">Chủ đề phỏng vấn Speaking</option>
                            </select>
                        </div>
                    </div>

                    <!-- Audio URL (If listening) -->
                    <template x-if="modalForm.skill === 'listening'">
                        <div class="p-3 bg-indigo-50/60 rounded-xl border border-indigo-100 space-y-1.5">
                            <label class="block font-semibold text-indigo-950">Đường dẫn file Audio MP3 (Audio URL)</label>
                            <input type="text" x-model="modalForm.audio_url" placeholder="/uploads/2026/dethitest/de-test-lop-6-len-7/track-1-4-20260819105025-7k9aa.mp3" class="w-full text-xs rounded-lg border border-indigo-200 p-2 bg-white font-mono" />
                            <span class="text-[10px] text-indigo-600">Hỗ trợ tệp MP3 lưu tại Media Manager hoặc link CDN trực tiếp.</span>
                        </div>
                    </template>

                    <!-- Reading Passage (If reading) -->
                    <template x-if="modalForm.skill === 'reading'">
                        <div class="p-3 bg-blue-50/60 rounded-xl border border-blue-100 space-y-1.5">
                            <label class="block font-semibold text-blue-950">Đoạn văn bài đọc (Reading Passage - tùy chọn)</label>
                            <textarea x-model="modalForm.passage" rows="3" placeholder="Nhập văn bản bài đọc nếu câu hỏi dựa vào đoạn văn..." class="w-full text-xs rounded-lg border border-blue-200 p-2 bg-white"></textarea>
                        </div>
                    </template>

                    <!-- Question Title / Prompt -->
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Nội dung câu hỏi / Đề bài <span class="text-rose-500">*</span></label>
                        <textarea x-model="modalForm.title" rows="2" placeholder="Ví dụ: What is the main idea of the passage?" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold focus:border-teal-500 focus:ring-teal-500"></textarea>
                    </div>

                    <!-- Multiple Choice Options Form -->
                    <template x-if="modalForm.type === 'multiple_choice'">
                        <div class="space-y-3 pt-2 border-t border-gray-100">
                            <label class="block font-semibold text-gray-700">4 Lựa chọn trả lời &amp; Tích chọn đáp án đúng:</label>
                            <div class="space-y-2">
                                <template x-for="(opt, idx) in modalForm.options" :key="opt.key">
                                    <div class="flex items-center gap-2 p-2 rounded-xl border" :class="modalForm.correct_answer === opt.key ? 'bg-emerald-50/70 border-emerald-300' : 'bg-gray-50 border-gray-200'">
                                        <input 
                                            type="radio" 
                                            name="correct_opt_radio" 
                                            :value="opt.key" 
                                            x-model="modalForm.correct_answer" 
                                            class="text-emerald-600 focus:ring-emerald-500 cursor-pointer"
                                        />
                                        <span class="w-6 font-bold text-center text-xs font-mono" x-text="opt.key"></span>
                                        <input 
                                            type="text" 
                                            x-model="opt.text" 
                                            placeholder="Nhập nội dung phương án..." 
                                            class="w-full text-xs rounded-lg border border-gray-200 p-2 bg-white focus:border-teal-500 focus:ring-teal-500" 
                                        />
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <!-- Fill in blank Form -->
                    <template x-if="modalForm.type === 'fill_blank'">
                        <div class="space-y-2 pt-2 border-t border-gray-100">
                            <label class="block font-semibold text-gray-700">Từ / Cụm từ đáp án chính xác:</label>
                            <input type="text" x-model="modalForm.correct_answer" placeholder="Ví dụ: had studied" class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5 bg-white" />
                        </div>
                    </template>

                    <!-- Essay Form -->
                    <template x-if="modalForm.type === 'essay'">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-gray-100">
                            <div>
                                <label class="block font-semibold text-gray-700 mb-1">Số từ tối thiểu:</label>
                                <input type="number" x-model="modalForm.min_words" placeholder="100" class="w-full text-xs font-mono rounded-xl border border-gray-200 p-2.5" />
                            </div>
                            <div>
                                <label class="block font-semibold text-gray-700 mb-1">Ghi chú barem chấm điểm:</label>
                                <input type="text" x-model="modalForm.rubric_note" placeholder="Tiêu chí chấm điểm..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5" />
                            </div>
                        </div>
                    </template>

                    <!-- Speaking Prompt Form -->
                    <template x-if="modalForm.type === 'speaking_prompt'">
                        <div class="space-y-2 pt-2 border-t border-gray-100">
                            <label class="block font-semibold text-gray-700">Gợi ý trả lời / Cue card points:</label>
                            <textarea x-model="modalForm.cue_points" rows="3" placeholder="• Where you went...&#10;• Who you went with..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5"></textarea>
                        </div>
                    </template>

                    <!-- Explanation & Points -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2 border-t border-gray-100">
                        <div class="sm:col-span-2">
                            <label class="block font-semibold text-gray-700 mb-1">Giải thích đáp án / Hướng dẫn (Tùy chọn):</label>
                            <input type="text" x-model="modalForm.explanation" placeholder="Lý do chọn đáp án này..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5" />
                        </div>
                        <div>
                            <label class="block font-semibold text-gray-700 mb-1">Điểm câu hỏi:</label>
                            <input type="number" x-model="modalForm.points" min="1" max="10" class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5" />
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-gray-50 border-t border-gray-100 flex items-center justify-end gap-2">
                    <button type="button" @click="closeModal()" class="px-4 py-2 border border-gray-200 text-xs font-semibold text-gray-700 rounded-xl hover:bg-gray-100 transition">
                        Đóng
                    </button>
                    <button type="button" @click="saveModalQuestion()" class="px-5 py-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-sm transition">
                        Lưu câu hỏi
                    </button>
                </div>
            </div>
        </div>
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
                },

                saveModalQuestion() {
                    if (!this.modalForm.title.trim()) {
                        alert('Vui lòng nhập nội dung câu hỏi!');
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
