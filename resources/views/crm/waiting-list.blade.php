<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-4">
        @if (session('status'))
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        @include('crm.partials.waiting-class-table')
    </div>
</x-app-layout>
