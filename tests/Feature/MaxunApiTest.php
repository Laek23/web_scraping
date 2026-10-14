<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MaxunApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_api_routes_return_json_unauthorized_responses(): void
    {
        $this->get('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_users_can_register_login_and_revoke_their_session(): void
    {
        $registration = $this->postJson('/api/auth/register', [
            'email' => 'scraper@example.test',
            'password' => 'secure-pass-123',
        ]);

        $registration->assertCreated()
            ->assertJsonPath('user.email', 'scraper@example.test')
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

        $token = $registration->json('token');
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'scraper@example.test');

        $this->withToken($token)->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => explode('|', $token)[0],
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'scraper@example.test',
            'password' => 'secure-pass-123',
        ])->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_robot_crud_is_scoped_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $created = $this->postJson('/api/robots', [
            'name' => 'Product pages',
            'websiteUrl' => 'https://example.com/products',
            'mode' => 'Extract',
            'outputFormats' => ['Markdown', 'JSON'],
            'smartQueries' => 'name price availability',
            'monitoring' => true,
        ]);
        $created->assertCreated()
            ->assertJsonPath('name', 'Product pages')
            ->assertJsonPath('websiteUrl', 'https://example.com/products')
            ->assertJsonPath('monitoring', true);

        $robotId = $created->json('id');
        $this->getJson('/api/robots')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $robotId);

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser)
            ->getJson("/api/robots/{$robotId}")
            ->assertNotFound();
    }

    public function test_robot_run_records_private_network_rejections(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $robot = $user->robots()->create([
            'name' => 'Local host test',
            'website_url' => 'http://127.0.0.1',
            'mode' => 'Scrape',
            'output_formats' => ['Markdown'],
        ]);

        $response = $this->postJson("/api/robots/{$robot->id}/run");

        $response->assertOk()
            ->assertJsonPath('execution.status', 'failed')
            ->assertJsonPath('execution.pagesScraped', 0);
        $this->assertDatabaseHas('executions', [
            'robot_id' => $robot->id,
            'status' => 'failed',
        ]);
    }

    public function test_scrape_run_extracts_page_content_and_selected_formats(): void
    {
        Http::fake([
            '*8.8.8.8*' => Http::response(
                '<html><head><title>Catalog</title><meta name="description" content="Product catalog"></head><body><h1>Products</h1><p>Wireless keyboard</p><script type="application/ld+json">{"@type":"Product","name":"Keyboard"}</script><a href="/about">About</a></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);
        $robot = $user->robots()->create([
            'name' => 'Public catalog',
            'website_url' => 'https://8.8.8.8/products',
            'mode' => 'Scrape',
            'output_formats' => ['Markdown', 'JSON'],
            'smart_queries' => 'keyboard',
        ]);

        $response = $this->postJson("/api/robots/{$robot->id}/run");

        $response->assertOk()
            ->assertJsonPath('execution.status', 'completed')
            ->assertJsonPath('execution.pagesScraped', 1)
            ->assertJsonPath('execution.result.pages.0.title', 'Catalog')
            ->assertJsonPath('execution.result.pages.0.structuredData.0.name', 'Keyboard')
            ->assertJsonPath('execution.result.pages.0.queryMatches.0.term', 'keyboard');
        $this->assertArrayHasKey('Markdown', $response->json('execution.result.outputs'));
        $this->assertArrayHasKey('JSON', $response->json('execution.result.outputs'));
    }

    public function test_search_walks_same_host_pages_and_returns_query_matches(): void
    {
        Http::fake([
            '*8.8.8.8*' => Http::sequence()
                ->push('<html><head><title>Home</title></head><body><p>Welcome</p><a href="/products">Products</a></body></html>', 200, ['Content-Type' => 'text/html'])
                ->push('<html><head><title>Products</title></head><body><h1>Blue widget</h1><p>Item details</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);
        $robot = $user->robots()->create([
            'name' => 'Widget search',
            'website_url' => 'https://8.8.8.8/',
            'mode' => 'Search',
            'output_formats' => ['JSON'],
            'smart_queries' => 'widget',
        ]);

        $response = $this->postJson("/api/robots/{$robot->id}/run");

        $response->assertOk()
            ->assertJsonPath('execution.status', 'completed')
            ->assertJsonPath('execution.pagesScraped', 1)
            ->assertJsonPath('execution.result.pages.0.title', 'Products')
            ->assertJsonPath('execution.result.pages.0.queryMatches.0.term', 'widget');
    }

    public function test_execution_history_is_returned_newest_first_with_large_results(): void
    {
        $user = User::factory()->create();
        $robot = $user->robots()->create([
            'name' => 'History robot',
            'website_url' => 'https://8.8.8.8/',
            'mode' => 'Scrape',
            'output_formats' => ['JSON'],
        ]);
        $oldest = $robot->executions()->create([
            'status' => 'completed',
            'result' => ['payload' => str_repeat('a', 100_000)],
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);
        $newest = $robot->executions()->create([
            'status' => 'completed',
            'result' => ['payload' => str_repeat('b', 100_000)],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/executions')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $newest->id)
            ->assertJsonPath('data.1.id', (string) $oldest->id)
            ->assertJsonPath('data.0.result.payload', str_repeat('b', 100_000))
            ->assertJsonPath('data.1.result.payload', str_repeat('a', 100_000));
    }

    public function test_robot_follows_safe_same_host_redirects(): void
    {
        Http::fake([
            '*8.8.8.8*' => Http::sequence()
                ->push('', 301, ['Location' => '/products/final'])
                ->push(
                    '<html><head><title>Final product page</title></head><body><h1>Keyboard</h1><p>Available today</p></body></html>',
                    200,
                    ['Content-Type' => 'text/html'],
                ),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);
        $robot = $user->robots()->create([
            'name' => 'Redirected product',
            'website_url' => 'https://8.8.8.8/products',
            'mode' => 'Scrape',
            'output_formats' => ['JSON'],
        ]);

        $this->postJson("/api/robots/{$robot->id}/run")
            ->assertOk()
            ->assertJsonPath('execution.status', 'completed')
            ->assertJsonPath('execution.result.pages.0.url', 'https://8.8.8.8/products/final')
            ->assertJsonPath('execution.result.pages.0.title', 'Final product page');
    }

    public function test_robot_rejects_redirects_to_a_different_host(): void
    {
        Http::fake([
            '*8.8.8.8*' => Http::response('', 302, ['Location' => 'https://example.com/final']),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);
        $robot = $user->robots()->create([
            'name' => 'External redirect',
            'website_url' => 'https://8.8.8.8/products',
            'mode' => 'Scrape',
            'output_formats' => ['JSON'],
        ]);

        $this->postJson("/api/robots/{$robot->id}/run")
            ->assertOk()
            ->assertJsonPath('execution.status', 'failed')
            ->assertJsonPath('execution.errorMessage', 'El sitio redirige a otro dominio. Actualiza el robot con la URL final del mismo sitio.');
    }

    public function test_users_can_create_and_revoke_api_keys(): void
    {
        $this->actingAs(User::factory()->create());

        $created = $this->postJson('/api/tokens', ['name' => 'CI pipeline']);
        $created->assertCreated()
            ->assertJsonPath('token.name', 'CI pipeline')
            ->assertJsonStructure(['token' => ['id', 'name', 'createdAt'], 'plainTextToken']);

        $tokenId = $created->json('token.id');
        $this->getJson('/api/tokens')->assertOk()->assertJsonCount(1);
        $this->deleteJson("/api/tokens/{$tokenId}")->assertOk();
        $this->getJson('/api/tokens')->assertOk()->assertJsonCount(0);
    }
}
