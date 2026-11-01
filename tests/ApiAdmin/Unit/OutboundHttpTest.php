<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Http\HostResolver;
use App\ApiAdmin\Http\OutboundHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class OutboundHttpTest extends TestCase
{
    public function test_credentials_cannot_be_sent_to_a_different_origin(): void
    {
        Http::fake();

        try {
            OutboundHttp::client('https://api.example.test/v1')->withToken('synthetic')->get('https://collector.invalid/');
            $this->fail('Origin substitution must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame('Upstream requests must stay on the configured origin.', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_redirect_response_is_not_followed_with_credentials(): void
    {
        Http::fake(['api.example.test/*' => Http::response('', 302, ['Location' => 'https://collector.invalid/'])]);
        $response = OutboundHttp::client('https://api.example.test')->withToken('synthetic')->get('/records');
        $this->assertSame(302, $response->status());
        Http::assertSentCount(1);
    }

    public function test_hosted_rejects_any_private_dns_answer_before_sending(): void
    {
        config(['api-admin.hosted.mode' => 'hosted', 'api-admin.http.allow_private_addresses' => false]);
        app()->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host, int $depth = 0): array
            {
                return ['93.184.216.34', '169.254.169.254'];
            }
        });
        Http::fake();

        try {
            OutboundHttp::client('https://api.example.test')->get('/');
            $this->fail('A mixed public/private DNS answer must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('public IP', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_hosted_pins_validated_dns_and_disables_proxy_and_tls_overrides(): void
    {
        config(['api-admin.hosted.mode' => 'hosted', 'api-admin.http.allow_private_addresses' => false]);
        app()->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host, int $depth = 0): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::fake(function (Request $request, array $options) {
            $this->assertTrue($options['verify']);
            $this->assertSame('', $options['proxy']);
            $this->assertIsArray($options['curl']);
            $this->assertSame(['api.example.test:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);

            return Http::response([]);
        });
        $this->assertTrue(OutboundHttp::client('https://api.example.test')->withoutVerifying()->get('/')->successful());
    }

    public function test_hosted_rejects_nat64_translation_to_private_ipv4(): void
    {
        config(['api-admin.hosted.mode' => 'hosted', 'api-admin.http.allow_private_addresses' => false]);
        Http::fake();

        try {
            OutboundHttp::client('https://[64:ff9b::a9fe:a9fe]')->get('/');
            $this->fail('NAT64 metadata destination must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('public IP', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_trusted_local_mode_can_reach_private_microservices(): void
    {
        config(['api-admin.hosted.mode' => 'local']);
        Http::fake(['127.0.0.1/*' => Http::response([])]);
        $this->assertTrue(OutboundHttp::client('http://127.0.0.1')->get('/')->successful());
    }

    public function test_urls_with_embedded_credentials_are_rejected(): void
    {
        Http::fake();
        $this->expectException(RuntimeException::class);
        OutboundHttp::client('https://user:secret@api.example.test')->get('/');
    }
}
