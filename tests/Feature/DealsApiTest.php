<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DealsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_preview_extracts_open_graph_and_json_ld_metadata(): void
    {
        $html = '<html><head><meta property="og:title" content="Wireless headphones"><meta property="og:image" content="https://8.8.8.8/headphones.jpg"><meta property="product:price:currency" content="USD"><script type="application/ld+json">{"@context":"https://schema.org","@type":"https://schema.org/Product","name":"Headphones","description":"Bluetooth headphones","brand":{"@type":"Brand","name":"Acme"},"category":"Audio","sku":"AC-100","offers":{"@type":"AggregateOffer","lowPrice":"79.99","highPrice":"99.99","priceCurrency":"USD","availability":"https://schema.org/InStock"},"aggregateRating":{"ratingValue":"4.7","reviewCount":"82"}}</script></head><body><span itemprop="price" content="79.99">$79.99</span><span class="old-price">$99.99</span><img alt="Headphones" src="https://8.8.8.8/headphones-gallery-1.jpg"><img alt="Headphones" src="https://8.8.8.8/headphones-gallery-2.jpg"><picture><source srcset="https://8.8.8.8/headphones-large.webp 1x"><img alt="Headphones" srcset="https://8.8.8.8/headphones-small.jpg 1x, https://8.8.8.8/headphones-large.jpg 2x"></picture></body></html>';
        Http::fake([
            '*8.8.8.8*' => Http::response(
                $html,
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->postJson('/api/v1/extract-preview', ['url' => 'https://8.8.8.8/product'])
            ->assertOk()
            ->assertJsonPath('title', 'Headphones')
            ->assertJsonPath('currentPrice', 79.99)
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('imageUrl', 'https://8.8.8.8/headphones.jpg')
            ->assertJsonPath('imageCandidates.0', 'https://8.8.8.8/headphones.jpg')
            ->assertJsonPath('imageCandidates.1', 'https://8.8.8.8/headphones-gallery-1.jpg')
            ->assertJsonPath('imageCandidates.2', 'https://8.8.8.8/headphones-gallery-2.jpg')
            ->assertJsonPath('imageCandidates.3', 'https://8.8.8.8/headphones-large.jpg')
            ->assertJsonPath('imageCandidates.4', 'https://8.8.8.8/headphones-large.webp')
            ->assertJsonPath('regularPrice', 99.99)
            ->assertJsonPath('productDetails.brand', 'Acme')
            ->assertJsonPath('productDetails.category', 'Audio')
            ->assertJsonPath('productDetails.sku', 'AC-100')
            ->assertJsonPath('productDetails.availability', 'InStock')
            ->assertJsonPath('productDetails.rating', '4.7')
            ->assertJsonPath('productDetails.reviewCount', '82')
            ->assertJsonPath('html', $html)
            ->assertJsonPath('source', 'metadata')
            ->assertJsonPath('complete', true);
    }

    public function test_preview_recognizes_common_store_names_and_saves_product_details(): void
    {
        Http::fake([
            '*liverpool.com.mx*' => Http::response(
                '<html><head><meta property="og:title" content="iPhone 17 Pro Max"><meta property="og:image" content="https://8.8.8.8/iphone.jpg"><meta property="product:price:amount" content="28999"><meta property="product:price:currency" content="MXN"></head></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $preview = $this->postJson('/api/v1/extract-preview', [
            'url' => 'https://www.liverpool.com.mx/tienda/pdp/iphone-17-pro-max',
        ])->assertOk()
            ->assertJsonPath('store', 'Liverpool')
            ->assertJsonPath('title', 'iPhone 17 Pro Max')
            ->assertJsonPath('currentPrice', 28999)
            ->assertJsonPath('currency', 'MXN');

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/deals', [
            'title' => $preview->json('title'),
            'productUrl' => $preview->json('url'),
            'imageUrl' => $preview->json('imageUrl'),
            'galleryImages' => [
                'https://8.8.8.8/iphone.jpg',
                'https://8.8.8.8/iphone-side.jpg',
            ],
            'store' => $preview->json('store'),
            'productDetails' => ['brand' => 'Apple', 'model' => 'iPhone 17 Pro Max'],
            'currentPrice' => $preview->json('currentPrice'),
            'currency' => $preview->json('currency'),
        ])->assertCreated()
            ->assertJsonPath('store', 'Liverpool')
            ->assertJsonPath('productDetails.brand', 'Apple')
            ->assertJsonPath('productDetails.model', 'iPhone 17 Pro Max')
            ->assertJsonPath('galleryImages.0', 'https://8.8.8.8/iphone.jpg')
            ->assertJsonPath('galleryImages.1', 'https://8.8.8.8/iphone-side.jpg');
    }

    public function test_product_preview_uses_mercado_libre_api_and_falls_back_for_blocked_pages(): void
    {
        Http::fake([
            'api.mercadolibre.com/items/MLM123456789' => Http::response([
                'title' => 'Cámara digital',
                'price' => 1800,
                'original_price' => 2400,
                'currency_id' => 'MXN',
                'thumbnail' => 'https://http2.mlstatic.com/D_123.jpg',
                'pictures' => [
                    ['secure_url' => 'https://http2.mlstatic.com/D_123.jpg'],
                    ['secure_url' => 'https://http2.mlstatic.com/D_456.jpg'],
                ],
                'category_id' => 'MLM1747',
                'available_quantity' => 7,
                'attributes' => [
                    ['id' => 'BRAND', 'name' => 'Marca', 'value_name' => 'Acer'],
                    ['id' => 'MODEL', 'name' => 'Modelo', 'value_name' => 'Nitro V'],
                ],
            ]),
            'api.mercadolibre.com/items/MLM123456789/description' => Http::response([
                'plain_text' => 'Laptop para juegos con memoria de 16 GB.',
            ]),
            '*articulo.mercadolibre.com.mx*' => Http::response('Blocked', 403),
            '*8.8.8.8*' => Http::response('Blocked', 403),
        ]);

        $this->postJson('/api/v1/extract-preview', [
            'url' => 'https://articulo.mercadolibre.com.mx/MLM-123456789-camara',
        ])->assertOk()
            ->assertJsonPath('title', 'Cámara digital')
            ->assertJsonPath('currentPrice', 1800)
            ->assertJsonPath('discountPercent', 25)
            ->assertJsonPath('description', 'Laptop para juegos con memoria de 16 GB.')
            ->assertJsonPath('productDetails.Marca', 'Acer')
            ->assertJsonPath('productDetails.Modelo', 'Nitro V')
            ->assertJsonPath('productDetails.stock', '7')
            ->assertJsonPath('imageCandidates.0', 'https://http2.mlstatic.com/D_123.jpg')
            ->assertJsonPath('imageCandidates.1', 'https://http2.mlstatic.com/D_456.jpg')
            ->assertJsonPath('html', '')
            ->assertJsonPath('source', 'mercadolibre-api');

        $this->postJson('/api/v1/extract-preview', ['url' => 'https://8.8.8.8/blocked'])
            ->assertOk()
            ->assertJsonPath('title', '')
            ->assertJsonPath('currentPrice', null)
            ->assertJsonPath('source', 'fallback')
            ->assertJsonPath('complete', false);
    }

    public function test_product_preview_prefers_mercado_libre_listing_id_from_pdp_filters(): void
    {
        Http::fake([
            'api.mercadolibre.com/items/MLM3883508200' => Http::response([
                'title' => 'Laptop gamer Ryzen 7',
                'price' => 12499,
                'original_price' => 15999,
                'currency_id' => 'MXN',
                'thumbnail' => 'https://http2.mlstatic.com/D_123.jpg',
            ]),
            '*mercadolibre.com.mx*' => Http::response('Blocked', 403),
        ]);

        $url = 'https://www.mercadolibre.com.mx/laptop-games-amd-ryzen-7-7730u-16gb-ram-512gb-ssd-156-fhd/p/MLM53294459?pdp_filters=item_id:MLM3883508200#is_advertising=true&searchVariation=MLM53294459';
        $this->postJson('/api/v1/extract-preview', ['url' => $url])
            ->assertOk()
            ->assertJsonPath('title', 'Laptop gamer Ryzen 7')
            ->assertJsonPath('currentPrice', 12499)
            ->assertJsonPath('discountPercent', 22)
            ->assertJsonPath('source', 'mercadolibre-api')
            ->assertJsonPath('complete', true);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadolibre.com/items/MLM3883508200');
    }

    public function test_product_preview_infers_title_from_url_slug_when_api_and_html_are_blocked(): void
    {
        Http::fake([
            'api.mercadolibre.com/*' => Http::response(['blocked_by' => 'PolicyAgent'], 403),
            '*articulo.mercadolibre.com.mx*' => Http::response('Blocked', 403),
            '*amazon.com.mx*' => Http::response('Robot Check', 503),
        ]);

        $this->postJson('/api/v1/extract-preview', [
            'url' => 'https://articulo.mercadolibre.com.mx/MLM-3075940501-nike-legend-essential-3-tenis-de-entrenamiento-para-hombre-_JM?searchVariation=204779988369',
        ])->assertOk()
            ->assertJsonPath('title', 'Nike Legend Essential 3 Tenis De Entrenamiento Para Hombre')
            ->assertJsonPath('store', 'Mercado Libre')
            ->assertJsonPath('currency', 'MXN')
            ->assertJsonPath('source', 'url-slug')
            ->assertJsonPath('complete', false);

        $this->postJson('/api/v1/extract-preview', [
            'url' => 'https://www.amazon.com.mx/Echo-Dot-Bocina-Inteligente-Alexa/dp/B09B8V1LZ3',
        ])->assertOk()
            ->assertJsonPath('title', 'Echo Dot Bocina Inteligente Alexa')
            ->assertJsonPath('store', 'Amazon')
            ->assertJsonPath('source', 'url-slug')
            ->assertJsonPath('complete', false);
    }


    public function test_users_can_publish_bookmark_vote_comment_and_view_their_deals(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $deal = $this->postJson('/api/v1/deals', [
            'title' => 'Teclado mecánico',
            'description' => 'Switches táctiles',
            'productUrl' => 'https://8.8.8.8/keyboard',
            'imageUrl' => 'https://8.8.8.8/keyboard.jpg',
            'store' => 'Tienda de prueba',
            'currentPrice' => 75,
            'regularPrice' => 100,
            'currency' => 'USD',
        ])->assertCreated()
            ->assertJsonPath('title', 'Teclado mecánico')
            ->assertJsonPath('discountPercent', 25);

        $dealId = $deal->json('id');
        $this->getJson('/api/v1/deals')->assertOk()
            ->assertJsonPath('data.0.id', (string) $dealId);

        $this->postJson("/api/v1/deals/{$dealId}/bookmark")
            ->assertOk()->assertJsonPath('bookmarked', true);
        $this->getJson('/api/v1/user/bookmarks')
            ->assertOk()->assertJsonPath('data.0.id', (string) $dealId);
        $this->postJson("/api/v1/deals/{$dealId}/vote")
            ->assertOk()->assertJsonPath('votesCount', 1);
        $this->postJson("/api/v1/deals/{$dealId}/comments", ['body' => 'Buen precio'])
            ->assertCreated()->assertJsonPath('body', 'Buen precio');
        $this->getJson("/api/v1/deals/{$dealId}")
            ->assertOk()->assertJsonPath('comments.0.body', 'Buen precio')
            ->assertJsonPath('priceHistory.0.price', 75);
        $this->getJson('/api/v1/user/deals')
            ->assertOk()->assertJsonPath('data.0.id', (string) $dealId);
    }

    public function test_user_can_update_profile_and_password_and_old_tokens_are_revoked(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $token = $user->createToken('profile-test')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/user/profile', [
            'name' => 'Updated User',
            'preferences' => ['currency' => 'MXN', 'notifications' => false],
        ])->assertOk()
            ->assertJsonPath('user.name', 'Updated User')
            ->assertJsonPath('user.preferences.currency', 'MXN')
            ->assertJsonPath('user.preferences.notifications', false);

        $this->withToken($token)->putJson('/api/v1/user/password', [
            'currentPassword' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertTrue(password_verify('new-password-456', $user->fresh()->password));
    }

    public function test_product_preview_extracts_amazon_mexico_product_page(): void
    {
        $amazonHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Amazon.com.mx: Echo Dot 5ta Generación : Dispositivos Amazon</title>
</head>
<body>
    <div id="titleSection">
        <h1 id="title">
            <span id="productTitle">Echo Dot (5.ª generación) Bocina inteligente con Alexa</span>
        </h1>
    </div>
    <div id="corePriceDisplay_desktop_feature_div">
        <span class="priceToPay"><span class="a-offscreen">$799.00</span></span>
        <span class="basisPrice"><span class="a-offscreen">$1,299.00</span></span>
    </div>
    <div id="imgTagWrapperId">
        <img id="landingImage" data-a-dynamic-image='{"https://m.media-amazon.com/images/I/echo-dot-hi.jpg":[1200,1200],"https://m.media-amazon.com/images/I/echo-dot-sm.jpg":[400,400]}' src="https://m.media-amazon.com/images/I/echo-dot-sm.jpg" />
    </div>
    <div id="feature-bullets">
        <ul>
            <li><span class="a-list-item">Sonido potente con voces más claras</span></li>
            <li><span class="a-list-item">Controla tu casa inteligente con la voz</span></li>
        </ul>
    </div>
</body>
</html>
HTML;

        Http::fake([
            'https://www.amazon.com.mx/dp/B09B8V1LZ3' => Http::response($amazonHtml, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $this->postJson('/api/v1/extract-preview', ['url' => 'https://www.amazon.com.mx/dp/B09B8V1LZ3'])
            ->assertOk()
            ->assertJsonPath('title', 'Echo Dot (5.ª generación) Bocina inteligente con Alexa')
            ->assertJsonPath('currentPrice', 799)
            ->assertJsonPath('regularPrice', 1299)
            ->assertJsonPath('discountPercent', 38)
            ->assertJsonPath('imageUrl', 'https://m.media-amazon.com/images/I/echo-dot-hi.jpg')
            ->assertJsonPath('store', 'Amazon')
            ->assertJsonPath('currency', 'MXN')
            ->assertJsonPath('complete', true);
    }

    public function test_product_preview_handles_eneba_links_without_utf8_errors(): void
    {
        $url = 'https://www.eneba.com/latam/xbox-fortnite-minty-legends-pack-1000-v-bucks-xbox-live-key-global';
        $res = $this->postJson('/api/v1/extract-preview', ['url' => $url])
            ->assertOk()
            ->assertJsonPath('store', 'Eneba')
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('complete', true);

        $this->assertNotNull($res->json('currentPrice'));
        $this->assertTrue((float) $res->json('currentPrice') > 0);
    }
}
