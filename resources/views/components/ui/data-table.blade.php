{{--
    <x-ui.data-table> — khung bảng dữ liệu: card viền + cuộn ngang bên trong card + style chuẩn cho <table>.
    Chỉ cần đặt <table> thô vào slot; thead/th/td/tr được style tự động (header nền nhạt, chữ label in hoa,
    dòng có viền dưới, hover đổi nền). Cột số tiền nên dùng <x-ui.money>.
    Props: minWidth (vd. "720px") — chiều rộng tối thiểu của bảng trước khi cuộn ngang
    Slots: header (tiêu đề/toolbar phía trên bảng), footer (phân trang: <x-ui.pagination :paginator="$rows" />)
    Ví dụ:
      <x-ui.data-table min-width="720px">
          <x-slot:header><h3 class="font-h3 text-h3">Danh sách lớp</h3></x-slot:header>
          <table>
              <thead><tr><th>Tên lớp</th><th class="text-right">Học phí</th></tr></thead>
              <tbody>
                  @forelse ($classes as $class)
                      <tr><td>{{ $class->name }}</td><td><x-ui.money :value="$class->fee" /></td></tr>
                  @empty
                      <tr><td colspan="2"><x-ui.empty-state title="Chưa có lớp nào" /></td></tr>
                  @endforelse
              </tbody>
          </table>
          <x-slot:footer><x-ui.pagination :paginator="$classes" /></x-slot:footer>
      </x-ui.data-table>
--}}
@props(['minWidth' => null])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest']) }}>
    @isset($header)
        <div class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-container p-md">{{ $header }}</div>
    @endisset
    <div class="custom-scrollbar overflow-x-auto
                [&_table]:w-full [&_table]:border-collapse [&_table]:text-left
                [&_thead]:border-b [&_thead]:border-outline-variant [&_thead]:bg-surface-container-low
                [&_th]:whitespace-nowrap [&_th]:px-md [&_th]:py-3 [&_th]:font-label [&_th]:text-label [&_th]:uppercase [&_th]:tracking-wider [&_th]:text-on-surface-variant
                [&_td]:px-md [&_td]:py-sm [&_td]:font-body-base [&_td]:text-body-base [&_td]:text-on-surface
                [&_tbody_tr]:border-b [&_tbody_tr]:border-surface-container [&_tbody_tr:last-child]:border-0
                [&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-surface-container-low"
         @if ($minWidth) style="--tw-table-min: {{ $minWidth }}" @endif>
        <div @if ($minWidth) class="min-w-[var(--tw-table-min)]" @endif>
            {{ $slot }}
        </div>
    </div>
    @isset($footer)
        <div class="border-t border-outline-variant bg-surface-container-low">{{ $footer }}</div>
    @endisset
</div>
