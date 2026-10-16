<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AddressSearch;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bản đồ cài đặt chấm công cơ sở: tìm toạ độ theo địa chỉ qua máy chủ (Nominatim → Photon), tự rút gọn địa chỉ đủ số nhà / ngõ.
 */
class BranchAddressSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function nominatimRow(string $name, float $lat, float $lng): array
    {
        return ['place_id' => crc32($name), 'display_name' => $name, 'lat' => (string) $lat, 'lon' => (string) $lng];
    }

    public function test_nominatim_result_is_returned_and_cached(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([$this->nominatimRow('Đội Cấn, Ba Đình, Hà Nội', 21.0353, 105.8237)])]);

        $this->actingAs($this->admin)->getJson(route('branches.address-search', ['q' => 'Đội Cấn, Ba Đình']))
            ->assertOk()
            ->assertJsonPath('matched', null)
            ->assertJsonPath('results.0.label', 'Đội Cấn, Ba Đình, Hà Nội')
            ->assertJsonPath('results.0.lat', 21.0353);

        // Chính sách Nominatim: User-Agent định danh ứng dụng (không dùng UA mặc định của thư viện HTTP).
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'countrycodes=vn')
            && str_starts_with($request->header('User-Agent')[0] ?? '', 'menglish'));

        $this->actingAs($this->admin)->getJson(route('branches.address-search', ['q' => '  đội cấn,  ba đình ']))->assertOk();
        Http::assertSentCount(1);
    }

    public function test_full_vietnamese_address_falls_back_to_street_via_photon(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([]),
            'photon.komoot.io/*' => function (Request $request) {
                if (str_starts_with($request['q'], '23')) {
                    return Http::response(['type' => 'FeatureCollection', 'features' => []]);
                }

                return Http::response(['type' => 'FeatureCollection', 'features' => [[
                    'geometry' => ['type' => 'Point', 'coordinates' => [105.8237, 21.0353]],
                    'properties' => ['osm_type' => 'W', 'osm_id' => 42, 'name' => 'Phố Đội Cấn', 'district' => 'Ba Đình', 'city' => 'Hà Nội', 'country' => 'Việt Nam', 'countrycode' => 'VN'],
                ]]]);
            },
        ]);

        $this->actingAs($this->admin)->getJson(route('branches.address-search', ['q' => '23 ngõ 209 Đội Cấn']))
            ->assertOk()
            ->assertJsonPath('matched', 'Đội Cấn')
            ->assertJsonPath('results.0.label', 'Phố Đội Cấn, Ba Đình, Hà Nội, Việt Nam')
            ->assertJsonPath('results.0.lng', 105.8237);
    }

    public function test_photon_is_used_when_nominatim_blocks_the_server(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response('Access blocked', 403),
            'photon.komoot.io/*' => Http::response(['features' => [[
                'geometry' => ['coordinates' => [105.8, 21.01]],
                'properties' => ['name' => 'Trần Duy Hưng', 'city' => 'Hà Nội', 'countrycode' => 'VN'],
            ]]]),
        ]);

        $this->actingAs($this->admin)->getJson(route('branches.address-search', ['q' => 'Trần Duy Hưng']))
            ->assertOk()
            ->assertJsonPath('results.0.label', 'Trần Duy Hưng, Hà Nội');
    }

    public function test_all_services_down_returns_502_and_is_not_cached(): void
    {
        $online = false;
        Http::fake(function (Request $request) use (&$online) {
            if (! $online) {
                throw new ConnectionException('timeout');
            }

            return Http::response([$this->nominatimRow('Đội Cấn', 21.03, 105.82)]);
        });

        $this->actingAs($this->admin)->getJson(route('branches.address-search', ['q' => 'Đội Cấn']))
            ->assertStatus(502)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Không kết nối được'));

        $online = true;
        $this->actingAs($this->admin)->getJson(route('branches.address-search', ['q' => 'Đội Cấn']))->assertOk()->assertJsonCount(1, 'results');
    }

    public function test_requires_branch_update_permission_and_query(): void
    {
        Http::fake();
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('sales_consultant');

        $this->actingAs($staff)->getJson(route('branches.address-search', ['q' => 'Đội Cấn']))->assertForbidden();
        $this->actingAs($this->admin)->getJson(route('branches.address-search'))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_variants_strip_house_number_and_alley(): void
    {
        $this->assertSame(['23 ngõ 209 Đội Cấn', 'Đội Cấn'], AddressSearch::variants('23 ngõ 209 Đội Cấn'));
        $this->assertSame(
            ['Số 12, ngách 34/5 Trần Duy Hưng, Cầu Giấy, Hà Nội', 'Trần Duy Hưng, Cầu Giấy, Hà Nội', 'Cầu Giấy, Hà Nội'],
            AddressSearch::variants('Số 12, ngách 34/5 Trần Duy Hưng, Cầu Giấy, Hà Nội'),
        );
        $this->assertSame(['Tô Hiến Thành, Hai Bà Trưng'], AddressSearch::variants('Tô Hiến Thành, Hai Bà Trưng'));
    }
}
