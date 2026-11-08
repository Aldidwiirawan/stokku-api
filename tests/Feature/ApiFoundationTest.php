<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Route khusus test untuk memicu setiap jenis error.
        // Hanya ada selama test berjalan, tidak ada di routes/api.php.
        Route::prefix('api/v1/_test')->group(function () {
            Route::get('auth', fn () => 'ok')->middleware('auth:sanctum');
            Route::get('forbidden', fn () => abort(403));
            Route::get('forbidden-custom', fn () => abort(403, 'Akun Anda nonaktif.'));
            Route::get('gate', fn () => throw new AuthorizationException);
            Route::get('missing-item', fn () => Item::findOrFail(999999));
            Route::get('throttled', fn () => 'ok')->middleware('throttle:1,1');
            Route::post('validation', fn (Request $request) => $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'price' => ['required', 'numeric', 'min:0'],
            ]));
        });
    }

    public function test_ping_returns_status_and_iso8601_server_time(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.version', 'v1');

        // Contoh: 2026-11-08T03:15:00+00:00 (ada tanggal, jam, dan zona waktu)
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $response->json('data.server_time'),
        );
    }

    public function test_unknown_api_route_returns_json_404_even_without_accept_header(): void
    {
        // get() biasa (bukan getJson) = tanpa header Accept: application/json
        $response = $this->get('/api/v1/tidak-ada');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Endpoint tidak ditemukan.']);
    }

    public function test_missing_model_returns_data_not_found(): void
    {
        $this->get('/api/v1/_test/missing-item')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Data tidak ditemukan.']);
    }

    public function test_request_without_token_returns_json_401(): void
    {
        $this->get('/api/v1/_test/auth')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Belum login atau sesi sudah berakhir. Silakan login lagi.']);
    }

    public function test_forbidden_returns_general_message(): void
    {
        $this->get('/api/v1/_test/forbidden')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Akses ditolak.']);

        $this->get('/api/v1/_test/gate')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Akses ditolak.']);
    }

    public function test_forbidden_keeps_our_own_message(): void
    {
        $this->get('/api/v1/_test/forbidden-custom')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Akun Anda nonaktif.']);
    }

    public function test_validation_error_uses_prd_format_in_indonesian(): void
    {
        $this->postJson('/api/v1/_test/validation', ['price' => -5])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Data tidak valid.',
                'errors' => [
                    'name' => ['Nama wajib diisi.'],
                    'price' => ['Harga minimal 0.'],
                ],
            ]);
    }

    public function test_too_many_requests_returns_json_429_with_retry_after(): void
    {
        $this->get('/api/v1/_test/throttled')->assertOk();

        $this->get('/api/v1/_test/throttled')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Terlalu banyak percobaan. Coba lagi sebentar.']);
    }

    public function test_web_routes_are_not_forced_to_json(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
