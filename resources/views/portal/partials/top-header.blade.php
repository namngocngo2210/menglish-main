@props([
    'student' => null,
    'students' => collect(),
    'title' => 'MENGLISH',
    'showBack' => false,
    'backUrl' => null,
])

<header class="w-full sticky top-0 bg-surface-container-lowest/95 dark:bg-inverse-surface/95 backdrop-blur-md border-b border-surface-container-highest dark:border-inverse-surface flex items-center justify-between px-4 h-16 z-40">
    <div class="flex items-center gap-2">
        @if($showBack)
            <a href="{{ $backUrl ?? route('portal.student.home', ['studentId' => $student?->id]) }}" class="p-2 rounded-full hover:bg-surface-container dark:hover:bg-inverse-surface text-on-surface-variant dark:text-inverse-on-surface transition">
                <span class="material-symbols-outlined text-[20px]">arrow_back</span>
            </a>
        @endif
        <div>
            <h1 class="font-black text-xl tracking-tight text-primary">{{ $title }}</h1>
            @if($student)
                <p class="text-[10px] text-on-surface-variant font-medium line-clamp-1">{{ $student->name }} • {{ $student->currentClass?->name ?? 'Chưa xếp lớp' }}</p>
            @endif
        </div>
    </div>

    <div class="flex items-center gap-2">
        @if($students && $students->count() > 1)
            {{-- Quick Student Switcher Dropdown --}}
            <x-ui.select class="!min-w-0 py-1 text-[11px] font-semibold" aria-label="Chọn học viên"
                    onchange="window.location.href = window.location.pathname.replace(/\/home(\/\d+)?$/, '/home/' + this.value).replace(/\/student-homework(\/\d+)?$/, '/student-homework/' + this.value).replace(/\/pronunciation(\/\d+)?$/, '/pronunciation/' + this.value).replace(/\/notifications(\/\d+)?$/, '/notifications/' + this.value).replace(/\/survey(\/\d+)?$/, '/survey/' + this.value).replace(/\/feedback(\/\d+)?$/, '/feedback/' + this.value)"
                    :options="$students->pluck('name', 'id')" :value="$student?->id" />
        @endif

        <a href="{{ route('portal.app-shell', ['student_id' => $student?->id]) }}" title="Xem App Shell & Menu" class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center overflow-hidden hover:opacity-80 transition-opacity active:scale-95">
            <span class="material-symbols-outlined text-[22px]">account_circle</span>
        </a>
    </div>
</header>
