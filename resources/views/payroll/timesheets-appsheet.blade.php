<x-app-layout>
    <x-ui.page-header title="Chấm Công AppSheet" icon="schedule">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="open_in_new" href="https://www.appsheet.com/start/00cb153e-2abe-4a50-bc50-36f9be53a422" target="_blank" rel="noopener noreferrer">Mở tab mới</x-ui.button>
            <x-ui.button icon="fullscreen" onclick="toggleAppsheetFullscreen()">Toàn màn hình</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Appsheet Embedded Container --}}
    <div class="bg-surface-container-lowest rounded-3xl border border-surface-container-highest shadow-sm overflow-hidden flex flex-col h-[calc(100vh-175px)] min-h-[650px] relative">
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
