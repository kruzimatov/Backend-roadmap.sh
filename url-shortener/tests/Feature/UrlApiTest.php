<?php

namespace Tests\Feature;

use App\Models\Click;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlApiTest extends TestCase
{
    use RefreshDatabase;

    private function createUrl(User $user, array $attributes = []): Url
    {
        return Url::create(array_merge([
            'user_id' => $user->id,
            'long_url' => 'https://example.com',
            'code' => 'code'.fake()->unique()->numerify('###'),
        ], $attributes));
    }

    public function test_it_requires_authentication_for_url_management(): void
    {
        $this->getJson('/api/urls')->assertUnauthorized();
        $this->postJson('/api/urls', ['long_url' => 'https://example.com'])->assertUnauthorized();
    }

    public function test_it_creates_a_short_url_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/urls', [
                'long_url' => 'https://example.com/articles/laravel',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.long_url', 'https://example.com/articles/laravel')
            ->assertJsonStructure(['message', 'data' => ['long_url', 'code', 'expires_at']]);

        $this->assertDatabaseHas('urls', [
            'user_id' => $user->id,
            'code' => $response->json('data.code'),
            'long_url' => 'https://example.com/articles/laravel',
            'expires_at' => null,
        ]);
    }

    public function test_it_rejects_invalid_url_creation_input(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/urls', [
                'long_url' => 'javascript:alert(1)',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('long_url');
    }

    public function test_it_saves_a_future_expiration(): void
    {
        $user = User::factory()->create();
        $expiresAt = now()->addDay()->startOfSecond();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/urls', [
                'long_url' => 'https://example.com',
                'expires_at' => $expiresAt->toDateTimeString(),
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('urls', [
            'user_id' => $user->id,
            'code' => $response->json('data.code'),
            'expires_at' => $expiresAt->toDateTimeString(),
        ]);
    }

    public function test_it_redirects_and_records_a_click_without_authentication(): void
    {
        $url = Url::create([
            'user_id' => User::factory()->create()->id,
            'long_url' => 'https://example.com/target',
            'code' => 'abc123',
        ]);

        $this->withHeaders(['User-Agent' => 'UrlShortenerTest/1.0'])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/abc123')
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
            'user_id' => User::factory()->create()->id,
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

    public function test_it_lists_only_the_authenticated_users_active_urls_with_pagination(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->createUrl($user);
        $this->createUrl($user);
        $this->createUrl($otherUser, ['code' => 'other1']);
        $this->createUrl($user, [
            'code' => 'oldone',
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/urls?per_page=1')
            ->assertOk()
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['code' => 'other1'])
            ->assertJsonMissing(['code' => 'oldone']);
    }

    public function test_it_shows_only_the_owners_url(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $url = $this->createUrl($owner, ['code' => 'details']);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/urls/details')
            ->assertNotFound();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/urls/details')
            ->assertOk()
            ->assertJsonPath('code', $url->code)
            ->assertJsonPath('long_url', $url->long_url);
    }

    public function test_it_rejects_invalid_update_data(): void
    {
        $user = User::factory()->create();
        $this->createUrl($user, ['code' => 'invalid']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/urls/invalid', [
                'long_url' => 'javascript:alert(1)',
                'expires_at' => now()->subMinute()->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['long_url', 'expires_at']);
    }

    public function test_it_updates_the_owners_url(): void
    {
        $user = User::factory()->create();
        $url = $this->createUrl($user, [
            'code' => 'update',
            'click_count' => 4,
        ]);
        $expiresAt = now()->addWeek()->toDateTimeString();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/urls/update', [
                'long_url' => 'https://example.com/new',
                'expires_at' => $expiresAt,
            ])
            ->assertOk()
            ->assertJson(['message' => 'URL updated successfully']);

        $this->assertDatabaseHas('urls', [
            'id' => $url->id,
            'long_url' => 'https://example.com/new',
            'click_count' => 4,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_it_returns_not_found_for_unknown_or_foreign_management_codes(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->createUrl($otherUser, ['code' => 'private1']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/urls/missing')->assertNotFound();
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/urls/private1', ['long_url' => 'https://example.com'])->assertNotFound();
        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/urls/private1')->assertNotFound();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/urls/private1/stats')->assertNotFound();
    }

    public function test_it_returns_statistics_for_the_owner(): void
    {
        $user = User::factory()->create();
        $url = $this->createUrl($user, [
            'code' => 'stats1',
            'click_count' => 2,
        ]);
        Click::create([
            'url_id' => $url->id,
            'ip_address' => '203.0.113.20',
            'user_agent' => 'StatsTest/1.0',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/urls/stats1/stats')
            ->assertOk()
            ->assertJsonPath('click_count', 2)
            ->assertJsonCount(1, 'clicks')
            ->assertJsonPath('clicks.0.ip_address', '203.0.113.20');
    }

    public function test_it_deletes_the_owners_url_and_its_clicks(): void
    {
        $user = User::factory()->create();
        $url = $this->createUrl($user, ['code' => 'delete']);
        Click::create(['url_id' => $url->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/urls/delete')
            ->assertNoContent();

        $this->assertDatabaseMissing('urls', ['id' => $url->id]);
        $this->assertDatabaseCount('clicks', 0);
    }
}
