@if ($errors->any())
    <div class="mb-4 p-4 rounded-xl bg-rose-50 text-rose-800 border border-rose-200 flex items-start gap-3" role="alert">
        <span class="material-symbols-outlined text-rose-600">error</span>
        <ul class="text-sm font-medium space-y-0.5">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
