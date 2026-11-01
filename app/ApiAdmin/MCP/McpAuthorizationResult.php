<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

final readonly class McpAuthorizationResult
{
    private function __construct(
        public bool $allowed,
        public ?string $code = null,
        public ?string $error = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $code, string $error): self
    {
        return new self(false, $code, $error);
    }

    /**
     * @return array<string, mixed>
     */
    public function toToolResult(): array
    {
        return [
            'success' => false,
            'code' => $this->code ?? 'MCP_AUTHORIZATION_DENIED',
            'error' => $this->error ?? 'MCP authorization denied.',
        ];
    }
}
