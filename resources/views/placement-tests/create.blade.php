<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <a href="{{ route('placement-tests.index') }}" class="w-10 h-10 flex items-center justify-center rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary-container">post_add</span>
                        Tạo Đề Thi Mới &amp; Soạn Thảo Câu Hỏi
                    </h1>
                    <p class="text-xs text-gray-500">Soạn thảo bộ đề kiểm tra năng lực đầu vào theo quy chuẩn MEnglish Admin</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('placement-tests.index') }}" class="px-4 py-2 rounded-xl border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 text-xs font-semibold shadow-2xs transition">Hủy</a>
                <button type="submit" form="createPlacementTestForm" class="px-5 py-2 rounded-xl bg-primary-container hover:bg-primary text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[18px]">save</span>
                    <span>Lưu đề thi</span>
                </button>
            </div>
        </div>
    </x-slot>

    @if ($errors->any())
        <div class="max-w-7xl mx-auto mb-4 p-4 bg-rose-50 border border-rose-200 rounded-2xl text-xs text-rose-800 space-y-1">
            <div class="font-bold flex items-center gap-1.5 text-rose-900">
                <span class="material-symbols-outlined text-base">error</span>
                <span>Vui lòng kiểm tra lại các thông tin:</span>
            </div>
            <ul class="list-disc list-inside space-y-0.5 pl-2">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $defaultQuestions = [
            [
                'id' => 1,
                'skill' => 'listening',
                'type' => 'multiple_choice',
                'section' => 'A. LISTENING - Exercise 1',
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
                'teacher_note' => 'Hướng dẫn nghe từ khóa "final destination".'
            ],
            [
                'id' => 2,
                'skill' => 'reading',
                'type' => 'multiple_choice',
                'section' => 'B. READING - Exercise 1',
                'title' => 'According to the passage, what is the primary benefit of renewable energy?',
                'audio_url' => '',
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
                'teacher_note' => 'Kiểm tra kỹ năng Scanning thông tin trong đoạn.'
            ],
            [
                'id' => 3,
                'skill' => 'grammar',
                'type' => 'fill_blank',
                'section' => 'C. GRAMMAR & VOCABULARY',
                'title' => 'Complete the sentence: If she _____ (study) harder last month, she would have passed the IELTS exam.',
                'audio_url' => '',
                'passage' => '',
                'options' => [],
                'correct_answer' => 'had studied',
                'points' => 1,
                'explanation' => 'Third conditional structure: If + S + had + V3/ed, S + would have + V3/ed.',
                'teacher_note' => 'Câu điều kiện loại 3.'
            ],
            [
                'id' => 4,
                'skill' => 'writing',
                'type' => 'essay',
                'section' => 'D. WRITING TASK',
                'title' => 'Writing Task: Some people believe that studying online is more effective than traditional classroom learning. Discuss both views and give your opinion.',
                'audio_url' => '',
                'passage' => '',
                'options' => [],
                'correct_answer' => '',
                'min_words' => 120,
                'points' => 9,
                'explanation' => 'Chấm theo 4 tiêu chí IELTS: Task Response, Coherence & Cohesion, Lexical Resource, Grammatical Range & Accuracy.',
                'teacher_note' => 'Yêu cầu tối thiểu 120 từ.'
            ],
            [
                'id' => 5,
                'skill' => 'speaking',
                'type' => 'speaking_prompt',
                'section' => 'E. SPEAKING TEST',
                'title' => 'Speaking Part 2: Describe a memorable journey or trip you took.',
                'audio_url' => '',
                'passage' => "• Where you went and who you went with\n• How you travelled there\n• What you did during the trip\n• And explain why this trip was so memorable for you",
                'options' => [],
                'correct_answer' => '',
                'points' => 9,
                'explanation' => 'Đánh giá độ trôi chảy, phản xạ phát âm, từ vựng và cấu trúc câu.',
                'teacher_note' => 'Chấm theo thang Rubric Speaking Cambridge / CEFR.'
            ]
        ];

        $initialQuestions = old('questions');
        if (is_string($initialQuestions)) {
            $initialQuestions = json_decode($initialQuestions, true);
        }
        if (!is_array($initialQuestions) || empty($initialQuestions)) {
            $initialQuestions = $defaultQuestions;
        }
    @endphp

    <div class="space-y-6" x-data="testCreatorApp({
        questions: {{ Js::from($initialQuestions) }}
    })">
        <form id="createPlacementTestForm" action="{{ route('placement-tests.store') }}" method="POST" @submit="syncBeforeSubmit($event)">
            @csrf
            <!-- Hidden Synchronized Questions JSON -->
            <input type="hidden" name="questions" x-ref="questionsInput" :value="JSON.stringify(questions)" />
            <input type="hidden" name="questions_count" x-ref="questionsCountInput" :value="questions.length" />

            <div class="flex flex-col lg:flex-row gap-6 min-h-[calc(100vh-180px)]">
                
                <!-- ────────────────────────────────────────────── -->
                <!-- LEFT SIDEBAR: GENERAL INFO & QUESTION LIST -->
                <!-- ────────────────────────────────────────────── -->
                <aside class="w-full lg:w-[360px] xl:w-[400px] shrink-0 space-y-4">
                    
                    <!-- General Info Card -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 space-y-3">
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                            <h3 class="text-xs font-bold text-gray-800 uppercase tracking-wider flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-primary-container text-[18px]">info</span>
                                <span>Thông tin chung</span>
                            </h3>
                            <span class="text-[10px] text-rose-500 font-medium">* Bắt buộc</span>
                        </div>

                        <div class="space-y-2.5 text-xs">
                            <div>
                                <label class="block font-bold text-gray-700 mb-1 text-[11px]">Tên đề thi <span class="text-rose-500">*</span></label>
                                <input type="text" name="title" value="{{ old('title', 'Đề Kiểm Tra Trình Độ 4 Kỹ Năng - Standard ' . date('Y')) }}" required placeholder="VD: Đề Test Đầu Vào IELTS 6.5" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-bold focus:border-primary-container focus:ring-primary-container bg-white shadow-2xs" />
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1 text-[11px]">Mã đề (Code) <span class="text-rose-500">*</span></label>
                                    <input type="text" name="code" value="{{ old('code', 'TEST-IE-' . date('ymd-His')) }}" required placeholder="TEST-01" class="w-full text-xs font-mono font-bold rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container bg-white shadow-2xs" />
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1 text-[11px]">Thời gian (phút) <span class="text-rose-500">*</span></label>
                                    <input type="number" name="duration_minutes" value="{{ old('duration_minutes', 45) }}" min="5" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container bg-white shadow-2xs" />
                                </div>
                            </div>

                            <div>
                                <label class="block font-bold text-gray-700 mb-1 text-[11px]">Cấp độ / Trình độ mục tiêu</label>
                                <select name="target_level" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-semibold focus:border-primary-container focus:ring-primary-container bg-white shadow-2xs">
                                    <option value="Lớp 1-2 (Starters)">Lớp 1-2 (Starters)</option>
                                    <option value="Lớp 3-4 (Movers)" selected>Lớp 3-4 (Movers)</option>
                                    <option value="Lớp 5-6 (Flyers)">Lớp 5-6 (Flyers)</option>
                                    <option value="IELTS Foundation (3.0 - 4.5)">IELTS Foundation (3.0 - 4.5)</option>
                                    <option value="IELTS Intensive (5.0 - 6.5)">IELTS Intensive (5.0 - 6.5)</option>
                                    <option value="IELTS Master (6.5 - 7.5+)">IELTS Master (6.5 - 7.5+)</option>
                                </select>
                            </div>

                            <div>
                                <label class="block font-bold text-gray-700 mb-1 text-[11px]">Mô tả / Hướng dẫn làm bài</label>
                                <textarea name="description" rows="2" placeholder="Ghi chú hướng dẫn..." class="w-full text-xs rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container bg-white shadow-2xs">{{ old('description', 'Bài kiểm tra đánh giá năng lực ngôn ngữ toàn diện gồm 4 kỹ năng: Nghe, Đọc, Viết và Nói.') }}</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Question List Navigation Card -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 space-y-3">
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                            <h3 class="text-xs font-bold text-gray-800 uppercase tracking-wider flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-indigo-600 text-[18px]">format_list_numbered</span>
                                <span>Danh sách câu hỏi</span>
                            </h3>
                            <span class="text-xs font-bold bg-orange-100 text-primary-container px-2.5 py-0.5 rounded-full font-mono" x-text="questions.length + ' Câu'"></span>
                        </div>

                        <!-- Action Toolbar: Quick Add Types -->
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <button type="button" @click="addNewQuestion('multiple_choice', 'listening')" class="px-2.5 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg font-bold transition flex items-center justify-center gap-1 text-[11px] cursor-pointer">
                                <span class="material-symbols-outlined text-[14px]">headphones</span>
                                <span>+ Trắc nghiệm</span>
                            </button>
                            <button type="button" @click="addNewQuestion('fill_blank', 'grammar')" class="px-2.5 py-1.5 bg-purple-50 hover:bg-purple-100 text-purple-700 rounded-lg font-bold transition flex items-center justify-center gap-1 text-[11px] cursor-pointer">
                                <span class="material-symbols-outlined text-[14px]">edit_square</span>
                                <span>+ Điền từ</span>
                            </button>
                            <button type="button" @click="addNewQuestion('essay', 'writing')" class="px-2.5 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded-lg font-bold transition flex items-center justify-center gap-1 text-[11px] cursor-pointer">
                                <span class="material-symbols-outlined text-[14px]">edit_document</span>
                                <span>+ Tự luận Writing</span>
                            </button>
                            <button type="button" @click="addNewQuestion('speaking_prompt', 'speaking')" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 rounded-lg font-bold transition flex items-center justify-center gap-1 text-[11px] cursor-pointer">
                                <span class="material-symbols-outlined text-[14px]">mic</span>
                                <span>+ Speaking</span>
                            </button>
                        </div>

                        <!-- Question Cards List -->
                        <div class="space-y-2 max-h-[460px] overflow-y-auto pr-1">
                            <template x-for="(q, idx) in questions" :key="q.id">
                                <div 
                                    @click="selectQuestion(idx)" 
                                    :class="currentIndex === idx ? 'bg-primary-container text-white shadow-md border-primary ring-2 ring-orange-200' : 'bg-white text-gray-800 border-gray-200 hover:border-orange-300 hover:bg-slate-50'"
                                    class="p-3 rounded-xl border transition-all cursor-pointer space-y-1"
                                >
                                    <div class="flex justify-between items-center">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-black text-xs font-mono" :class="currentIndex === idx ? 'text-white' : 'text-gray-900'" x-text="'Câu ' + String(idx + 1).padStart(2, '0')"></span>
                                            <span class="text-[9px] uppercase font-extrabold px-1.5 py-0.5 rounded" :class="currentIndex === idx ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-700'" x-text="getTypeBadge(q.type)"></span>
                                        </div>
                                        <div class="flex items-center gap-1" @click.stop>
                                            <button type="button" @click="moveQuestion(idx, -1)" :disabled="idx === 0" class="p-0.5 rounded hover:bg-black/10 disabled:opacity-30" title="Lên">
                                                <span class="material-symbols-outlined text-[14px]">arrow_upward</span>
                                            </button>
                                            <button type="button" @click="moveQuestion(idx, 1)" :disabled="idx === questions.length - 1" class="p-0.5 rounded hover:bg-black/10 disabled:opacity-30" title="Xuống">
                                                <span class="material-symbols-outlined text-[14px]">arrow_downward</span>
                                            </button>
                                            <button type="button" @click="deleteQuestion(idx)" :disabled="questions.length <= 1" class="p-0.5 rounded hover:bg-black/10 text-rose-300 hover:text-rose-100 disabled:opacity-30" title="Xóa">
                                                <span class="material-symbols-outlined text-[14px]">close</span>
                                            </button>
                                        </div>
                                    </div>
                                    <p class="text-[11px] line-clamp-1 leading-snug" :class="currentIndex === idx ? 'text-white/90' : 'text-gray-600'" x-text="q.title || '(Chưa nhập nội dung đề bài...)'"></p>
                                    <div class="flex justify-between items-center pt-1 border-t text-[10px]" :class="currentIndex === idx ? 'border-white/20 text-white/90' : 'border-gray-100 text-gray-500'">
                                        <span class="font-bold uppercase tracking-wider" x-text="q.skill"></span>
                                        <span class="font-mono font-bold" :class="currentIndex === idx ? 'text-white' : 'text-orange-600'" x-text="(q.points || 1) + 'đ'"></span>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Big Add Button -->
                        <button 
                            type="button" 
                            @click="addNewQuestion('multiple_choice', 'reading')" 
                            class="w-full py-2.5 border-2 border-dashed border-orange-300 bg-orange-50/50 rounded-xl text-primary-container font-bold text-xs hover:bg-orange-100/50 hover:border-orange-400 transition-all flex items-center justify-center gap-1.5 cursor-pointer shadow-2xs"
                        >
                            <span class="material-symbols-outlined text-[18px]">add_circle</span>
                            <span>Thêm câu hỏi mới vào đề</span>
                        </button>
                    </div>

                </aside>

                <!-- ────────────────────────────────────────────── -->
                <!-- RIGHT MAIN PANEL: QUESTION DETAIL EDITOR -->
                <!-- ────────────────────────────────────────────── -->
                <main class="flex-1 bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
                    <template x-if="currentQ">
                        <div class="space-y-5">
                            
                            <!-- Editor Header -->
                            <div class="flex items-center justify-between pb-3 border-b border-gray-200 flex-wrap gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-xl bg-primary-container text-white font-black text-sm flex items-center justify-center font-mono shadow-xs">
                                        #<span x-text="String(currentIndex + 1).padStart(2, '0')"></span>
                                    </span>
                                    <div>
                                        <h2 class="text-sm font-black text-gray-900">
                                            Soạn Thảo Chi Tiết Câu Hỏi Số <span class="text-primary-container font-mono" x-text="currentIndex + 1"></span>
                                        </h2>
                                        <span class="text-[11px] text-gray-500">Thiết lập nội dung đề, phương án, đoạn văn, file nghe &amp; đáp án chấm tự động</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button 
                                        type="button" 
                                        @click="duplicateQuestion(currentIndex)" 
                                        class="px-3 py-1.5 text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold transition flex items-center gap-1 cursor-pointer"
                                    >
                                        <span class="material-symbols-outlined text-[15px]">content_copy</span>
                                        <span>Nhân bản câu</span>
                                    </button>
                                    <button 
                                        type="button" 
                                        @click="deleteQuestion(currentIndex)" 
                                        :disabled="questions.length <= 1"
                                        class="px-3 py-1.5 text-rose-600 bg-rose-50 hover:bg-rose-100 rounded-lg text-xs font-bold transition flex items-center gap-1 cursor-pointer disabled:opacity-50"
                                    >
                                        <span class="material-symbols-outlined text-[15px]">delete</span>
                                        <span>Xóa câu</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Section & Points & Skill -->
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 text-xs">
                                <div class="md:col-span-6">
                                    <label class="block font-bold text-gray-800 mb-1 text-[11px]">Phần thi (Section / Tiêu đề nhóm câu)</label>
                                    <input type="text" x-model="currentQ.section" placeholder="VD: A. LISTENING - Exercise 1" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-semibold focus:ring-primary-container focus:border-primary-container bg-white shadow-2xs" />
                                </div>
                                <div class="md:col-span-3">
                                    <label class="block font-bold text-gray-800 mb-1 text-[11px]">Kỹ năng (Skill) <span class="text-rose-500">*</span></label>
                                    <select x-model="currentQ.skill" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-bold focus:ring-primary-container focus:border-primary-container bg-white shadow-2xs">
                                        <option value="listening">🎧 Listening (Nghe)</option>
                                        <option value="reading">📖 Reading (Đọc hiểu)</option>
                                        <option value="grammar">🔤 Grammar / Vocab</option>
                                        <option value="writing">✍️ Writing (Viết)</option>
                                        <option value="speaking">🗣️ Speaking (Nói)</option>
                                    </select>
                                </div>
                                <div class="md:col-span-3">
                                    <label class="block font-bold text-gray-800 mb-1 text-[11px]">Điểm số (Points)</label>
                                    <input type="number" x-model.number="currentQ.points" step="0.25" min="0.25" class="w-full text-xs font-mono font-black text-orange-600 rounded-xl border border-gray-300 p-2.5 focus:ring-primary-container focus:border-primary-container bg-white shadow-2xs" />
                                </div>
                            </div>

                            <!-- Question Type Selector Buttons -->
                            <div class="space-y-2 text-xs">
                                <label class="block font-bold text-gray-800 uppercase tracking-wider text-[10px]">Định dạng loại câu hỏi</label>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <button 
                                        type="button" 
                                        @click="setType('multiple_choice')" 
                                        :class="currentQ.type === 'multiple_choice' ? 'border-2 border-primary-container bg-orange-50 text-primary-container font-black shadow-xs' : 'border border-gray-200 bg-white text-gray-700 hover:bg-slate-50'"
                                        class="p-2.5 rounded-xl transition flex items-center justify-center gap-1.5 cursor-pointer text-xs"
                                    >
                                        <span class="material-symbols-outlined text-[18px]">list_alt</span>
                                        <span>Trắc nghiệm A/B/C/D</span>
                                    </button>

                                    <button 
                                        type="button" 
                                        @click="setType('fill_blank')" 
                                        :class="currentQ.type === 'fill_blank' ? 'border-2 border-primary-container bg-orange-50 text-primary-container font-black shadow-xs' : 'border border-gray-200 bg-white text-gray-700 hover:bg-slate-50'"
                                        class="p-2.5 rounded-xl transition flex items-center justify-center gap-1.5 cursor-pointer text-xs"
                                    >
                                        <span class="material-symbols-outlined text-[18px]">edit_square</span>
                                        <span>Điền vào chỗ trống</span>
                                    </button>

                                    <button 
                                        type="button" 
                                        @click="setType('essay')" 
                                        :class="currentQ.type === 'essay' ? 'border-2 border-primary-container bg-orange-50 text-primary-container font-black shadow-xs' : 'border border-gray-200 bg-white text-gray-700 hover:bg-slate-50'"
                                        class="p-2.5 rounded-xl transition flex items-center justify-center gap-1.5 cursor-pointer text-xs"
                                    >
                                        <span class="material-symbols-outlined text-[18px]">edit_note</span>
                                        <span>Tự luận Writing</span>
                                    </button>

                                    <button 
                                        type="button" 
                                        @click="setType('speaking_prompt')" 
                                        :class="currentQ.type === 'speaking_prompt' ? 'border-2 border-primary-container bg-orange-50 text-primary-container font-black shadow-xs' : 'border border-gray-200 bg-white text-gray-700 hover:bg-slate-50'"
                                        class="p-2.5 rounded-xl transition flex items-center justify-center gap-1.5 cursor-pointer text-xs"
                                    >
                                        <span class="material-symbols-outlined text-[18px]">record_voice_over</span>
                                        <span>Phỏng vấn Speaking</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Audio Track Upload & Player (for Listening) -->
                            <div class="p-4 bg-indigo-50/60 border border-indigo-200 rounded-2xl space-y-2 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-indigo-950 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-indigo-600 text-base">headphones</span>
                                        <span>Đường dẫn tệp Audio Nghe (.mp3)</span>
                                    </span>
                                    <span class="text-[10px] text-indigo-700 font-mono italic">Phát trực tiếp trên giao diện thi</span>
                                </div>
                                <input type="text" x-model="currentQ.audio_url" placeholder="/uploads/2026/dethitest/audio-track-01.mp3" class="w-full text-xs rounded-xl border border-indigo-300 p-2.5 bg-white font-mono shadow-2xs" />
                                <template x-if="currentQ.audio_url">
                                    <div class="pt-1">
                                        <audio controls class="w-full h-8" :src="currentQ.audio_url"></audio>
                                    </div>
                                </template>
                            </div>

                            <!-- Reading / Context Passage Content -->
                            <div class="space-y-1 text-xs">
                                <label class="block font-bold text-gray-800 text-[11px]">Đoạn văn đọc hiểu / Bối cảnh câu hỏi (Passage / Reading Text)</label>
                                <textarea x-model="currentQ.passage" rows="3" placeholder="Nhập đoạn văn đọc hiểu hoặc ngữ cảnh của câu hỏi (nếu có)..." class="w-full text-xs rounded-xl border border-gray-300 p-3 focus:ring-primary-container focus:border-primary-container bg-white font-serif shadow-2xs leading-relaxed"></textarea>
                            </div>

                            <!-- Question Text Content -->
                            <div class="space-y-1 text-xs">
                                <label class="block font-bold text-gray-800 text-[11px]">Nội dung câu hỏi / Yêu cầu đề bài <span class="text-rose-500">*</span></label>
                                <textarea x-model="currentQ.title" rows="2" placeholder="VD: According to the passage, what is the primary benefit of renewable energy?" class="w-full text-xs font-bold rounded-xl border border-gray-300 p-3 focus:ring-primary-container focus:border-primary-container bg-white shadow-2xs"></textarea>
                            </div>

                            <!-- MULTIPLE CHOICE OPTIONS EDITOR -->
                            <template x-if="currentQ.type === 'multiple_choice'">
                                <div class="space-y-3 text-xs bg-slate-50/70 p-4 rounded-2xl border border-gray-200">
                                    <div class="flex items-center justify-between pb-2 border-b border-gray-200">
                                        <h4 class="font-bold text-gray-900 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                                            <span class="material-symbols-outlined text-primary-container text-base">tune</span>
                                            <span>Các phương án trả lời (Tích chọn radio vào đáp án đúng)</span>
                                        </h4>
                                        <button type="button" @click="addOption()" class="px-2.5 py-1 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-lg text-xs font-bold transition flex items-center gap-1 shadow-2xs cursor-pointer">
                                            <span class="material-symbols-outlined text-[14px]">add</span>
                                            <span>Thêm phương án</span>
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <template x-for="(opt, oIdx) in currentQ.options" :key="opt.key">
                                            <div class="p-3 bg-white rounded-xl border border-gray-200 space-y-2 shadow-2xs">
                                                <div class="flex items-center gap-2">
                                                    <input 
                                                        type="radio" 
                                                        :name="'correct_ans_' + currentQ.id" 
                                                        :checked="currentQ.correct_answer === opt.key" 
                                                        @change="currentQ.correct_answer = opt.key" 
                                                        class="w-4 h-4 text-orange-600 focus:ring-orange-500 cursor-pointer"
                                                    />
                                                    <span class="font-black text-sm font-mono w-5" :class="currentQ.correct_answer === opt.key ? 'text-primary-container' : 'text-gray-700'" x-text="opt.key + '.'"></span>
                                                    <input type="text" x-model="opt.text" placeholder="Nhập nội dung phương án..." class="flex-1 text-xs rounded-lg border border-gray-300 p-2 bg-white font-medium focus:ring-primary-container focus:border-primary-container" />
                                                    <button type="button" @click="removeOption(oIdx)" :disabled="currentQ.options.length <= 2" class="p-1 text-gray-400 hover:text-rose-600 disabled:opacity-20 cursor-pointer" title="Xóa phương án">
                                                        <span class="material-symbols-outlined text-[16px]">close</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="p-2.5 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-900 text-[11px] flex items-center gap-2">
                                        <span class="material-symbols-outlined text-emerald-600 text-base">check_circle</span>
                                        <span>Đáp án đúng hiện tại: <strong class="font-mono text-sm" x-text="currentQ.correct_answer || 'Chưa chọn'"></strong></span>
                                    </div>
                                </div>
                            </template>

                            <!-- FILL BLANK SETUP -->
                            <template x-if="currentQ.type === 'fill_blank'">
                                <div class="p-4 bg-slate-50 border border-gray-200 rounded-xl space-y-2 text-xs">
                                    <label class="block font-bold text-gray-900 uppercase text-[11px]">Từ / Cụm từ đáp án chính xác <span class="text-rose-500">*</span></label>
                                    <input type="text" x-model="currentQ.correct_answer" placeholder="VD: had studied, will go, beautiful..." class="w-full text-xs rounded-lg border border-gray-300 p-2.5 bg-white font-mono font-bold text-emerald-700 shadow-2xs" />
                                    <p class="text-[11px] text-gray-500 italic">* Hệ thống sẽ tự động đối soát đáp án này không phân biệt hoa thường khi học viên nộp bài.</p>
                                </div>
                            </template>

                            <!-- ESSAY WRITING SETUP -->
                            <template x-if="currentQ.type === 'essay'">
                                <div class="p-4 bg-amber-50/60 border border-amber-200 rounded-xl space-y-3 text-xs">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label class="block font-bold text-amber-950 uppercase text-[10px] mb-1">Số từ tối thiểu (Minimum Words)</label>
                                            <input type="number" x-model.number="currentQ.min_words" placeholder="120" min="10" class="w-full text-xs rounded-lg border border-amber-300 p-2.5 bg-white font-bold" />
                                        </div>
                                        <div>
                                            <label class="block font-bold text-amber-950 uppercase text-[10px] mb-1">Tiêu chuẩn Rubric áp dụng</label>
                                            <input type="text" x-model="currentQ.rubric_note" placeholder="IELTS Writing Task 1 / Task 2 Rubric" class="w-full text-xs rounded-lg border border-amber-300 p-2.5 bg-white font-medium" />
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-amber-800 italic">* Bài làm tự luận sẽ được giáo viên chấm trực tiếp tại cổng kết quả.</p>
                                </div>
                            </template>

                            <!-- SPEAKING SETUP -->
                            <template x-if="currentQ.type === 'speaking_prompt'">
                                <div class="p-4 bg-rose-50/60 border border-rose-200 rounded-xl space-y-2 text-xs">
                                    <label class="block font-bold text-rose-950 uppercase text-[11px]">Gợi ý dàn ý / Cue points cho học viên</label>
                                    <textarea x-model="currentQ.passage" rows="3" placeholder="• Where you went and who you went with\n• How you travelled there\n• What you did..." class="w-full text-xs rounded-lg border border-rose-300 p-2.5 bg-white leading-relaxed"></textarea>
                                </div>
                            </template>

                            <!-- Explanation Box -->
                            <div class="p-4 bg-amber-50/50 border border-amber-200 rounded-2xl space-y-1.5 text-xs">
                                <label class="block font-bold text-amber-950 uppercase text-[11px] flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-primary-container text-base">lightbulb</span>
                                    <span>Lời giải thích chi tiết &amp; Dẫn chứng bài làm (Explanation)</span>
                                </label>
                                <textarea x-model="currentQ.explanation" rows="2" placeholder="Giải thích vì sao đáp án này đúng, trích dẫn transcript bài nghe hoặc đoạn văn bài đọc..." class="w-full text-xs rounded-xl border border-amber-300 p-2.5 bg-white shadow-2xs leading-relaxed"></textarea>
                            </div>

                            <!-- Teacher Note Box -->
                            <div class="p-4 bg-slate-50 border border-gray-200 rounded-2xl space-y-1.5 text-xs">
                                <label class="block font-bold text-gray-800 uppercase text-[11px] flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-orange-600 text-base">settings_suggest</span>
                                    <span>Ghi chú chuyên môn cho Giáo viên khi chấm (Teacher's Note)</span>
                                </label>
                                <textarea x-model="currentQ.teacher_note" rows="2" placeholder="Ghi chú thêm về tiêu chí, bẫy từ vựng..." class="w-full text-xs rounded-xl border border-gray-300 p-2.5 bg-white shadow-2xs"></textarea>
                            </div>

                        </div>
                    </template>
                </main>

            </div>
        </form>
    </div>

    <script>
        function testCreatorApp(cfg) {
            return {
                questions: cfg.questions || [],
                currentIndex: 0,

                init() {
                    if (this.questions.length === 0) {
                        this.addNewQuestion('multiple_choice', 'listening');
                    }
                },

                get currentQ() {
                    return this.questions[this.currentIndex] || null;
                },

                selectQuestion(idx) {
                    this.currentIndex = idx;
                },

                getTypeBadge(type) {
                    return {
                        'multiple_choice': 'Trắc nghiệm',
                        'fill_blank': 'Điền từ',
                        'speaking_prompt': 'Nói',
                        'essay': 'Tự luận'
                    }[type] || 'Câu hỏi';
                },

                setType(type) {
                    if (!this.currentQ) return;
                    this.currentQ.type = type;
                    if (type === 'multiple_choice' && (!this.currentQ.options || this.currentQ.options.length === 0)) {
                        this.currentQ.options = [
                            { key: 'A', text: '' },
                            { key: 'B', text: '' },
                            { key: 'C', text: '' },
                            { key: 'D', text: '' }
                        ];
                        this.currentQ.correct_answer = 'A';
                    }
                },

                addOption() {
                    if (!this.currentQ || !this.currentQ.options) return;
                    const letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
                    const nextKey = letters[this.currentQ.options.length] || String.fromCharCode(65 + this.currentQ.options.length);
                    this.currentQ.options.push({ key: nextKey, text: '' });
                },

                removeOption(idx) {
                    if (!this.currentQ || !this.currentQ.options || this.currentQ.options.length <= 2) return;
                    const removedKey = this.currentQ.options[idx].key;
                    this.currentQ.options.splice(idx, 1);
                    // Re-index keys
                    const letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
                    this.currentQ.options.forEach((opt, i) => {
                        opt.key = letters[i] || String.fromCharCode(65 + i);
                    });
                    if (this.currentQ.correct_answer === removedKey) {
                        this.currentQ.correct_answer = this.currentQ.options[0].key;
                    }
                },

                addNewQuestion(type = 'multiple_choice', skill = 'reading') {
                    const newId = Date.now();
                    const newQ = {
                        id: newId,
                        skill: skill,
                        type: type,
                        section: skill.toUpperCase() + ' SECTION',
                        title: '',
                        audio_url: '',
                        passage: '',
                        options: type === 'multiple_choice' ? [
                            { key: 'A', text: '' },
                            { key: 'B', text: '' },
                            { key: 'C', text: '' },
                            { key: 'D', text: '' }
                        ] : [],
                        correct_answer: type === 'multiple_choice' ? 'A' : '',
                        points: 1,
                        explanation: '',
                        teacher_note: ''
                    };
                    this.questions.push(newQ);
                    this.currentIndex = this.questions.length - 1;
                },

                moveQuestion(idx, direction) {
                    const targetIdx = idx + direction;
                    if (targetIdx < 0 || targetIdx >= this.questions.length) return;
                    const temp = this.questions[idx];
                    this.questions[idx] = this.questions[targetIdx];
                    this.questions[targetIdx] = temp;
                    this.currentIndex = targetIdx;
                },

                duplicateQuestion(idx) {
                    const clone = JSON.parse(JSON.stringify(this.questions[idx]));
                    clone.id = Date.now();
                    clone.title = (clone.title ? clone.title : 'Câu hỏi') + ' (Bản sao)';
                    this.questions.splice(idx + 1, 0, clone);
                    this.currentIndex = idx + 1;
                },

                deleteQuestion(idx) {
                    if (this.questions.length <= 1) {
                        alert('Đề thi phải có ít nhất 1 câu hỏi!');
                        return;
                    }
                    if (confirm('Bạn có chắc chắn muốn xóa câu hỏi này?')) {
                        this.questions.splice(idx, 1);
                        if (this.currentIndex >= this.questions.length) {
                            this.currentIndex = this.questions.length - 1;
                        }
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
