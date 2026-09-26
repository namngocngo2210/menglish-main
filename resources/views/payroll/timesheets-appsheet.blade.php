<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-primary text-2xl">schedule</span>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight">Chấm Công AppSheet</h1>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a 
                    href="https://www.appsheet.com/start/00cb153e-2abe-4a50-bc50-36f9be53a422" 
                    target="_blank" 
                    rel="noopener noreferrer" 
                    class="px-3.5 py-1.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-xs font-bold text-gray-700 shadow-sm transition inline-flex items-center gap-1.5"
                >
                    <span class="material-symbols-outlined text-sm">open_in_new</span>
                    <span>Mở tab mới</span>
                </a>
                <button 
                    onclick="toggleAppsheetFullscreen()" 
                    class="px-3.5 py-1.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition inline-flex items-center gap-1.5"
                >
                    <span class="material-symbols-outlined text-sm">fullscreen</span>
                    <span>Toàn màn hình</span>
                </button>
            </div>
        </div>
    </x-slot>

    {{-- Appsheet Embedded Container --}}
    <div class="bg-white rounded-3xl border border-gray-200 shadow-sm overflow-hidden flex flex-col h-[calc(100vh-175px)] min-h-[650px] relative">
        <iframe 
            id="appsheet-frame"
            name="preview-frame"
            allowfullscreen="true" 
            frameborder="0" 
            src="https://www.appsheet.com/start/00cb153e-2abe-4a50-bc50-36f9be53a422" 
            class="w-full h-full border-0 flex-1 rounded-2xl"
            style="width: 100%; height: 100%; min-height: 600px; border: none;"
        ></iframe>
    </div>

    <script>
        function toggleAppsheetFullscreen() {
            const iframe = document.getElementById('appsheet-frame');
            if (!document.fullscreenElement) {
                if (iframe.requestFullscreen) {
                    iframe.requestFullscreen();
                } else if (iframe.webkitRequestFullscreen) {
                    iframe.webkitRequestFullscreen();
                } else if (iframe.msRequestFullscreen) {
                    iframe.msRequestFullscreen();
                }
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        }
    </script>
</x-app-layout>
