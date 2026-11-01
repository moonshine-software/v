<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DockerEnvironmentTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function urls(): array
    {
        return [
            'production HTTPS' => ['production', 'https://admin.example.com', true],
            'production HTTP rejected' => ['production', 'http://admin.example.com', false],
            'production loopback HTTP rejected' => ['production', 'http://127.0.0.1:8080', false],
            'local loopback HTTP' => ['local', 'http://127.0.0.1:8080', true],
            'local public HTTP rejected' => ['local', 'http://admin.example.com', false],
            'local wildcard HTTP rejected' => ['local', 'http://0.0.0.0:8080', false],
            'local deceptive hostname rejected' => ['local', 'http://127.0.0.1.example.com', false],
            'malformed URL rejected' => ['local', 'not-a-url', false],
        ];
    }

    #[DataProvider('urls')]
    public function test_environment_validation_preserves_https_requirement_outside_local_loopback(string $environment, string $url, bool $valid): void
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../../docker/validate-environment.php'], env: [
            'APP_ENV' => $environment,
            'APP_URL' => $url,
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'DB_PASSWORD' => str_repeat('d', 32),
            'REDIS_PASSWORD' => str_repeat('r', 32),
        ]);
        $process->run();

        self::assertSame($valid, $process->isSuccessful(), $process->getErrorOutput());
    }
}
