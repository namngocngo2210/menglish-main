<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * Smoke test cho thư viện component resources/views/components/ui: render được, đúng các trạng thái chính.
 */
class UiComponentsRenderTest extends TestCase
{
    public function test_buttons_badges_and_money_render(): void
    {
        $html = (string) $this->blade(<<<'BLADE'
            <x-ui.button icon="add" href="/x">Tạo mới</x-ui.button>
            <x-ui.button variant="secondary" type="submit">Lưu</x-ui.button>
            <x-ui.button variant="ghost" icon="more_vert" aria-label="Thao tác" />
            <x-ui.badge color="stage-won">Đã chốt</x-ui.badge>
            <x-ui.badge color="unknown" :dot="false">Khác</x-ui.badge>
            <x-ui.money :value="-1500000" />
            <x-ui.money :value="null" />
            <x-ui.avatar name="Nguyễn Anh Tuấn" />
        BLADE);

        $this->assertStringContainsString('href="/x"', $html);
        $this->assertStringContainsString('bg-primary-container', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('bg-stage-won/10', $html);
        $this->assertStringContainsString('-1.500.000 ₫', $html);
        $this->assertStringContainsString('text-error', $html);
        $this->assertStringContainsString('—', $html);
        $this->assertStringContainsString('>NA</span>', $html);
    }

    public function test_layout_components_render(): void
    {
        $html = (string) $this->blade(<<<'BLADE'
            <x-ui.page-header title="Quản lý lớp" description="Mô tả">
                <x-slot:actions><x-ui.button>Tạo</x-ui.button></x-slot:actions>
            </x-ui.page-header>
            <x-ui.stat-card label="Tổng" value="124" tone="error" icon="person_off" />
            <x-ui.tabs><x-ui.tab href="/a" :active="true" :count="3">Tab A</x-ui.tab><x-ui.tab href="/b">Tab B</x-ui.tab></x-ui.tabs>
            <x-ui.alert type="warning" title="Chú ý" dismissible>Nội dung</x-ui.alert>
            <x-ui.empty-state title="Trống" description="Không có dữ liệu" />
            <x-ui.modal name="demo" title="Hộp thoại">Nội dung modal</x-ui.modal>
            <x-ui.data-table min-width="600px"><table><tr><td>1</td></tr></table></x-ui.data-table>
            <x-ui.filter-bar><x-ui.select name="source" :options="['a' => 'A']" placeholder="Tất cả" inline-label="Nguồn:" /></x-ui.filter-bar>
            <x-ui.toast />
        BLADE);

        $this->assertStringContainsString('Quản lý lớp', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('border-l-4', $html);
        $this->assertStringContainsString('open-modal', $html);
        $this->assertStringContainsString('name="search"', $html);
        $this->assertStringContainsString('Nguồn:', $html);
    }

    public function test_form_fields_show_required_star_and_validation_error(): void
    {
        $this->withViewErrors(['phone' => ['Số điện thoại không hợp lệ.']]);

        $html = (string) $this->blade(<<<'BLADE'
            <x-ui.input name="phone" label="Số điện thoại" required hint="10 số" />
            <x-ui.select name="branch" label="Chi nhánh" :options="[1 => 'CS1']" value="1" />
            <x-ui.textarea name="note" label="Ghi chú" />
            <x-ui.date name="from" label="Từ ngày" value="2026-01-02" />
        BLADE);

        $this->assertStringContainsString('text-error" aria-hidden="true">*', $html);
        $this->assertStringContainsString('Số điện thoại không hợp lệ.', $html);
        $this->assertStringContainsString('border-error', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('value="1" selected', $html);
        $this->assertStringContainsString('type="date"', $html);
        $this->assertStringContainsString('value="2026-01-02"', $html);
    }

    public function test_money_tone_and_input_suffix(): void
    {
        $html = (string) $this->blade(<<<'BLADE'
            <x-ui.money :value="2500000" tone="success" sign />
            <x-ui.money :value="-100" />
            <x-ui.input name="capacity" label="Sĩ số" suffix="học viên" />
            <x-ui.input name="plain" />
        BLADE);

        $this->assertMatchesRegularExpression('/text-tertiary[^>]*>\+2\.500\.000 ₫</u', $html);
        $this->assertMatchesRegularExpression('/text-error[^>]*>-100 ₫</u', $html);
        $this->assertStringContainsString('pr-16', $html);
        $this->assertStringContainsString('>học viên</span>', $html);
        // Không label / icon / suffix: chỉ thẻ input, không bọc <label>.
        $this->assertMatchesRegularExpression('/<input type="text"\s+name="plain"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<label[^>]*>\s*<input type="text"\s+name="plain"/', $html);
    }

    public function test_pagination_renders_with_record_options(): void
    {
        $paginator = new LengthAwarePaginator(range(1, 10), 45, 10, 2, ['path' => '/items']);

        $html = (string) $this->blade('<x-ui.pagination :paginator="$p" unit="khách" />', ['p' => $paginator]);

        $this->assertStringContainsString('Hiển thị:', $html);
        $this->assertStringContainsString('trong tổng số', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('bg-primary-container', $html);
    }
}
