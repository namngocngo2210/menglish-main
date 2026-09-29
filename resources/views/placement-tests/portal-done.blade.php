<!DOCTYPE html>
<html lang="vi" class="h-full bg-surface-container-low">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hoàn thành bài test — MEnglish</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=be-vietnam-pro:400,500,600,700,800&display=swap" rel="stylesheet" />
    @include('layouts.partials.assets')
</head>
<body class="font-sans antialiased text-on-surface bg-surface-container-low min-h-screen flex items-center justify-center p-4">
    {{-- Thí sinh không xem điểm sau khi nộp: Học vụ duyệt kết quả rồi trung tâm gửi cho phụ huynh. --}}
    <main class="max-w-md w-full bg-surface-container-lowest rounded-3xl border border-surface-container-highest shadow-xl p-8 space-y-4 text-center" data-testid="placement-test-done">
        <div class="w-20 h-20 mx-auto rounded-full bg-primary-container/15 text-primary-container flex items-center justify-center">
            <span class="material-symbols-outlined text-5xl">celebration</span>
        </div>
        <h1 class="text-2xl font-extrabold tracking-tight text-on-surface">Chúc mừng con đã hoàn thiện bài test!</h1>
        @if (filled($candidateName))
            <p class="text-sm font-semibold text-on-surface">{{ $candidateName }}</p>
        @endif
        <p class="text-sm text-on-surface-variant leading-relaxed">
            Bài làm đã được gửi tới MEnglish. Thầy cô sẽ chấm và liên hệ gửi kết quả cùng lộ trình học phù hợp cho phụ huynh trong thời gian sớm nhất.
        </p>
        <p class="text-xs text-on-surface-variant">{{ $test->title }}</p>
    </main>
</body>
</html>
