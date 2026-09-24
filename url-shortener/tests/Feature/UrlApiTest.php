<?php

namespace Tests\Feature;

use App\Models\Click;
use App\Models\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_short_url(): void
    {
        $response = $this->postJson('/api/urls', [
            'long_url' => 'https://example.com/articles/laravel',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.long_url', 'https://example.com/articles/laravel')
            ->assertJsonStructure(['message', 'data' => ['long_url', 'code', 'expires_at']]);

        $code = $response->json('data.code');

        $this->assertIsString($code);
        $this->assertSame(6, strlen($code));
        $this->assertDatabaseHas('urls', [
            'code' => $code,
            'long_url' => 'https://example.com/articles/laravel',
            'expires_at' => null,
        ]);
    }

    public function test_it_rejects_invalid_update_data(): void
    {
        Url::create([
            'long_url' => 'https://example.com/original',
            'code' => 'invalid',
        ]);

        $this->putJson('/api/urls/invalid', [
            'long_url' => 'not-a-url',
            'expires_at' => now()->subMinute()->toDateTimeString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['long_url', 'expires_at']);
    }

    public function test_it_validates_url_creation_input(): void
    {
        $response = $this->postJson('/api/urls', [
            'long_url' => 'not-a-url',
            'expires_at' => now()->subMinute()->toDateTimeString(),
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['long_url', 'expires_at']);

        $this->assertDatabaseCount('urls', 0);
    }

    public function test_it_saves_a_future_expiration(): void
    {
        $expiresAt = now()->addDay()->startOfSecond();

        $response = $this->postJson('/api/urls', [
            'long_url' => 'https://example.com',
            'expires_at' => $expiresAt->toDateTimeString(),
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('urls', [
            'code' => $response->json('data.code'),
            'expires_at' => $expiresAt->toDateTimeString(),
        ]);
    }

    public function test_it_redirects_and_records_a_click(): void
    {
        $url = Url::create([
            'long_url' => 'https://example.com/target',
            'code' => 'abc123',
        ]);

        $response = $this
            ->withHeaders([
                'User-Agent' => 'UrlShortenerTest/1.0',
            ])
            ->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.10',
            ])
            ->get('/abc123');

        $response
            ->assertRedirect('https://example.com/target');

        $this->assertDatabaseHas('urls', [
            'id' => $url->id,
            'click_count' => 1,
        ]);
        $this->assertDatabaseHas('clicks', [
            'url_id' => $url->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'UrlShortenerTest/1.0',
        ]);
    }

    public function test_it_does_not_redirect_or_record_clicks_for_an_expired_url(): void
    {
        $url = Url::create([
            'long_url' => 'https://example.com/expired',
            'code' => 'expired',
            'expires_at' => now()->subMinute(),
        ]);

        $this->get('/expired')->assertNotFound();

        $this->assertDatabaseHas('urls', [
            'id' => $url->id,
            'click_count' => 0,
        ]);
        $this->assertDatabaseCount('clicks', 0);
    }

    public function test_it_returns_not_found_for_an_unknown_redirect_code(): void
    {
        $this->get('/missing')->assertNotFound();
        $this->assertDatabaseCount('clicks', 0);
    }

    public function test_it_lists_active_urls_with_public_fields(): void
    {
        Url::create([
            'long_url' => 'https://example.com/active',
            'code' => 'active',
        ]);
        Url::create([
            'long_url' => 'https://example.com/expired',
            'code' => 'oldone',
            'expires_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/urls')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.code', 'active')
            ->assertJsonPath('0.long_url', 'https://example.com/active')
            ->assertJsonMissingPath('0.id');
    }

    public function test_it_shows_a_url_by_code(): void
    {
        Url::create([
            'long_url' => 'https://example.com/details',
            'code' => 'details',
        ]);

        $this->getJson('/api/urls/details')
            ->assertOk()
            ->assertJsonPath('code', 'details')
            ->assertJsonPath('long_url', 'https://example.com/details');
    }

    public function test_it_updates_a_url(): void
    {
        $url = Url::create([
            'long_url' => 'https://example.com/old',
            'code' => 'update',
            'click_count' => 4,
        ]);

        $expiresAt = now()->addWeek()->toDateTimeString();

        $this->putJson('/api/urls/update', [
            'long_url' => 'https://example.com/new',
            'expires_at' => $expiresAt,
        ])
            ->assertOk()
            ->assertJson(['message' => 'URL updated successfully']);

        $this->assertDatabaseHas('urls', [
            'id' => $url->id,
            'code' => 'update',
            'long_url' => 'https://example.com/new',
            'click_count' => 4,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_it_returns_not_found_for_unknown_management_codes(): void
    {
        $this->getJson('/api/urls/missing')->assertNotFound();
        $this->putJson('/api/urls/missing', [
            'long_url' => 'https://example.com',
        ])->assertNotFound();
        $this->deleteJson('/api/urls/missing')->assertNotFound();
        $this->getJson('/api/urls/missing/stats')->assertNotFound();
    }

    public function test_it_returns_basic_and_detailed_statistics(): void
    {
        $url = Url::create([
            'long_url' => 'https://example.com/stats',
            'code' => 'stats1',
            'click_count' => 2,
        ]);
        Click::create([
            'url_id' => $url->id,
            'ip_address' => '203.0.113.20',
            'user_agent' => 'StatsTest/1.0',
        ]);

        $this->getJson('/api/urls/stats1/stats')
            ->assertOk()
            ->assertJsonPath('click_count', 2)
            ->assertJsonCount(1, 'clicks')
            ->assertJsonPath('clicks.0.ip_address', '203.0.113.20')
            ->assertJsonPath('clicks.0.user_agent', 'StatsTest/1.0')
            ->assertJsonPath('expires_at', null);
    }

    public function test_it_deletes_a_url_and_its_clicks(): void
    {
        $url = Url::create([
            'long_url' => 'https://example.com/delete',
            'code' => 'delete',
        ]);
        Click::create(['url_id' => $url->id]);

        $this->deleteJson('/api/urls/delete')
            ->assertNoContent();

        $this->assertDatabaseMissing('urls', ['id' => $url->id]);
        $this->assertDatabaseCount('clicks', 0);
    }
}
