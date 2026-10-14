<?php

namespace App\Services;

use App\Models\Robot;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WebScraper
{
    private const MAX_PAGES = 5;

    private const MAX_RESPONSE_BYTES = 2_000_000;

    public function scrape(Robot $robot): array
    {
        $pages = [];
        $queue = [$robot->website_url];
        $visited = [];
        $host = strtolower((string) parse_url($robot->website_url, PHP_URL_HOST));
        $multiPage = in_array($robot->mode, ['Crawl', 'Search'], true);

        while ($queue !== [] && count($pages) < ($multiPage ? self::MAX_PAGES : 1)) {
            $url = array_shift($queue);
            $normalized = $this->normalizeUrl($url);

            if (isset($visited[$normalized])) {
                continue;
            }

            $visited[$normalized] = true;
            $page = $this->scrapePage($normalized, $robot->smart_queries);
            $pages[] = $page;

            if ($multiPage) {
                foreach ($page['links'] as $link) {
                    if (strtolower((string) parse_url($link['url'], PHP_URL_HOST)) === $host) {
                        $queue[] = $link['url'];
                    }
                }
            }
        }

        if ($robot->mode === 'Search') {
            $pages = array_values(array_filter($pages, fn (array $page) => $page['queryMatches'] !== []));
        }

        $result = [
            'robot' => [
                'id' => (string) $robot->id,
                'name' => $robot->name,
                'mode' => $robot->mode,
                'websiteUrl' => $robot->website_url,
            ],
            'pages' => $pages,
            'pageCount' => count($pages),
            'scrapedAt' => now()->toISOString(),
        ];
        $result['outputs'] = $this->formatOutputs($pages, $robot);

        return $result;
    }

    private function scrapePage(string $url, ?string $query): array
    {
        $initialHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $redirects = 0;
        do {
            $this->assertPublicUrl($url);

            $response = Http::withHeaders([
                'User-Agent' => 'MaxunBot/1.0 (+https://maxun.dev/bot)',
                'Accept' => 'text/html, application/xhtml+xml, text/plain, application/json;q=0.9',
            ])
                ->connectTimeout(5)
                ->timeout(15)
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->get($url);

            if (! $response->redirect()) {
                break;
            }

            if ($redirects >= 5) {
                throw new RuntimeException('El sitio excedió el límite de 5 redireccionamientos.');
            }

            $location = trim($response->header('Location', ''));
            $redirectUrl = $location !== '' ? $this->absoluteUrl($url, $location) : null;
            $redirectHost = strtolower((string) parse_url((string) $redirectUrl, PHP_URL_HOST));
            if (! $redirectUrl || $redirectHost !== $initialHost) {
                throw new RuntimeException('El sitio redirige a otro dominio. Actualiza el robot con la URL final del mismo sitio.');
            }

            $redirectParts = parse_url($redirectUrl);
            $redirectScheme = strtolower($redirectParts['scheme'] ?? '');
            $redirectPort = $redirectParts['port'] ?? ($redirectScheme === 'https' ? 443 : 80);
            if (! in_array($redirectPort, [80, 443], true)
                || (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && $redirectScheme !== 'https')) {
                throw new RuntimeException('El sitio redirige a un puerto no permitido.');
            }

            $url = $redirectUrl;
            $redirects++;
        } while (true);

        if ($response->failed()) {
            throw new RuntimeException("El sitio respondió con HTTP {$response->status()}.");
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (! $stream->eof()) {
            $body .= $stream->read(8192);
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new RuntimeException('La respuesta del sitio supera el límite de 2 MB por página.');
            }
        }

        $contentType = strtolower($response->header('Content-Type', ''));
        if (str_contains($contentType, 'application/pdf')) {
            throw new RuntimeException('La extracción de PDF todavía no está habilitada. Usa una página HTML o texto.');
        }

        if (! str_contains($contentType, 'text/html')
            && ! str_contains($contentType, 'application/xhtml+xml')
            && ! str_contains($contentType, 'text/plain')
            && ! str_contains($contentType, 'application/json')) {
            throw new RuntimeException('El sitio no devolvió HTML, texto ni JSON compatible.');
        }

        if (str_contains($contentType, 'text/plain') || str_contains($contentType, 'application/json')) {
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');

            return [
                'url' => $url,
                'title' => basename((string) parse_url($url, PHP_URL_PATH)) ?: $url,
                'description' => '',
                'headings' => [],
                'text' => Str::limit($plain, 50_000, ''),
                'links' => [],
                'metadata' => [],
                'structuredData' => [],
                'queryMatches' => $this->findQueryMatches($plain, $query),
                'html' => '',
            ];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('No se pudo interpretar el HTML de la página.');
        }

        $xpath = new DOMXPath($document);
        $structuredData = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $decoded = json_decode($script->textContent, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $structuredData[] = $decoded;
            }
        }
        foreach ($xpath->query('//script|//style|//noscript|//svg|//iframe|//nav|//footer|//header') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = trim($document->getElementsByTagName('title')->item(0)?->textContent ?? '');
        $description = $this->metaContent($xpath, 'description');
        $headings = [];
        foreach ($xpath->query('//h1|//h2|//h3') ?: [] as $heading) {
            $text = trim(preg_replace('/\s+/u', ' ', $heading->textContent) ?? '');
            if ($text !== '') {
                $headings[] = Str::limit($text, 300, '');
            }
        }

        $links = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|javascript|tel):/i', $href)) {
                continue;
            }
            $absolute = $this->absoluteUrl($url, $href);
            if (! $absolute || strtolower((string) parse_url($absolute, PHP_URL_HOST)) !== strtolower((string) parse_url($url, PHP_URL_HOST))) {
                continue;
            }
            $linkText = trim(preg_replace('/\s+/u', ' ', $anchor->textContent) ?? '');
            $links[$absolute] = [
                'text' => Str::limit($linkText, 200, ''),
                'url' => $absolute,
            ];
            if (count($links) >= 30) {
                break;
            }
        }

        $bodyNode = $document->getElementsByTagName('body')->item(0);
        $text = trim(preg_replace('/\s+/u', ' ', $bodyNode?->textContent ?? '') ?? '');
        $metadata = [];
        foreach ($xpath->query('//meta[@property or @name]') ?: [] as $meta) {
            if (! $meta instanceof DOMElement) {
                continue;
            }
            $key = $meta->getAttribute('property') ?: $meta->getAttribute('name');
            $value = trim($meta->getAttribute('content'));
            if ($key !== '' && $value !== '') {
                $metadata[$key] = Str::limit($value, 1000, '');
            }
        }

        $html = $bodyNode ? $document->saveHTML($bodyNode) : '';

        return [
            'url' => $url,
            'title' => Str::limit($title, 500, ''),
            'description' => Str::limit($description, 2000, ''),
            'headings' => array_slice($headings, 0, 100),
            'text' => Str::limit($text, 50_000, ''),
            'links' => array_values($links),
            'metadata' => $metadata,
            'structuredData' => $structuredData,
            'queryMatches' => $this->findQueryMatches($text, $query),
            'html' => Str::limit($html, 300_000, ''),
        ];
    }

    private function assertPublicUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            throw new RuntimeException('La URL debe usar HTTP o HTTPS y apuntar a un dominio público.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_merge(
                array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
                array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            );

        if ($addresses === []) {
            throw new RuntimeException('No se pudo resolver el dominio de la URL.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Por seguridad, Maxun solo puede acceder a direcciones IP públicas.');
            }
        }
    }

    private function metaContent(DOMXPath $xpath, string $name): string
    {
        $nodes = $xpath->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="'.$name.'"]/@content');

        return trim($nodes?->item(0)?->nodeValue ?? '');
    }

    private function absoluteUrl(string $base, string $href): ?string
    {
        if (filter_var($href, FILTER_VALIDATE_URL)) {
            return str_starts_with(strtolower($href), 'http://') || str_starts_with(strtolower($href), 'https://')
                ? $this->normalizeUrl($href)
                : null;
        }

        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($href, '//')) {
            return $this->normalizeUrl($parts['scheme'].':'.$href);
        }
        if (str_starts_with($href, '/')) {
            return $this->normalizeUrl($origin.$href);
        }

        return $this->normalizeUrl($origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$href);
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('El robot contiene una URL inválida.');
        }

        return $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function findQueryMatches(string $text, ?string $query): array
    {
        $terms = preg_split('/\s+/u', trim((string) $query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matches = [];

        foreach (array_slice(array_unique($terms), 0, 12) as $term) {
            if (mb_strlen($term) < 3) {
                continue;
            }
            $position = mb_stripos($text, $term);
            if ($position !== false) {
                $matches[] = [
                    'term' => $term,
                    'context' => mb_substr($text, max(0, $position - 100), 240),
                ];
            }
        }

        return $matches;
    }

    private function formatOutputs(array $pages, Robot $robot): array
    {
        $outputs = [];
        foreach ($robot->output_formats as $format) {
            $outputs[$format] = match ($format) {
                'Markdown' => collect($pages)->map(fn (array $page) => $this->toMarkdown($page))->implode("\n\n---\n\n"),
                'HTML' => collect($pages)->pluck('html')->implode("\n"),
                'JSON' => $pages,
            };
        }

        return $outputs;
    }

    private function toMarkdown(array $page): string
    {
        $parts = ['# '.($page['title'] ?: $page['url']), '', 'Source: '.$page['url']];
        if ($page['description'] !== '') {
            $parts[] = '';
            $parts[] = $page['description'];
        }
        foreach ($page['headings'] as $heading) {
            $parts[] = '';
            $parts[] = '## '.$heading;
        }
        if ($page['text'] !== '') {
            $parts[] = '';
            $parts[] = $page['text'];
        }

        return implode("\n", $parts);
    }
}
