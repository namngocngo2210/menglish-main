@props([
    'student' => null,
    'students' => collect(),
    'title' => 'MENGLISH',
    'showBack' => false,
    'backUrl' => null,
])

<header class="w-full sticky top-0 bg-white/95 dark:bg-gray-900/95 backdrop-blur-md border-b border-gray-200 dark:border-gray-800 flex items-center justify-between px-4 h-16 z-40">
    <div class="flex items-center gap-2">
        @if($showBack)
            <a href="{{ $backUrl ?? route('portal.student.home', ['studentId' => $student?->id]) }}" class="p-2 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-300 transition">
                <span class="material-symbols-outlined text-[20px]">arrow_back</span>
            </a>
        @endif
        <div>
            <h1 class="font-black text-xl tracking-tight text-primary">{{ $title }}</h1>
            @if($student)
                <p class="text-[10px] text-gray-500 font-medium line-clamp-1">{{ $student->name }} • {{ $student->currentClass?->name ?? 'IELTS Starter' }}</p>
            @endif
        </div>
    </div>

    <div class="flex items-center gap-2">
        @if($students && $students->count() > 1)
            <!-- Quick Student Switcher Dropdown -->
            <select class="text-[11px] font-semibold py-1 px-2.5 bg-gray-100 hover:bg-gray-200 border-none rounded-full text-gray-700 cursor-pointer focus:ring-1 focus:ring-primary"
                    onchange="window.location.href = window.location.pathname.replace(/\/home(\/\d+)?$/, '/home/' + this.value).replace(/\/student-homework(\/\d+)?$/, '/student-homework/' + this.value).replace(/\/pronunciation(\/\d+)?$/, '/pronunciation/' + this.value).replace(/\/notifications(\/\d+)?$/, '/notifications/' + this.value).replace(/\/survey(\/\d+)?$/, '/survey/' + this.value).replace(/\/feedback(\/\d+)?$/, '/feedback/' + this.value)">
                @foreach($students as $st)
                    <option value="{{ $st->id }}" {{ ($student && $student->id === $st->id) ? 'selected' : '' }}>
                        {{ $st->name }}
                    </option>
                @endforeach
            </select>
        @endif

        <a href="{{ route('portal.app-shell', ['student_id' => $student?->id]) }}" title="Xem App Shell & Menu" class="w-9 h-9 rounded-full bg-orange-100 text-primary flex items-center justify-center overflow-hidden hover:opacity-80 transition-opacity active:scale-95">
            <span class="material-symbols-outlined text-[22px]">account_circle</span>
        </a>
    </div>
</header>
