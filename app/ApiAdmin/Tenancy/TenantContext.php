<?php

declare(strict_types=1);

namespace App\ApiAdmin\Tenancy;

final readonly class TenantContext
{
    public const string LOCAL_ID = 'local';

    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $mode,
        public bool $isLocal,
    ) {}

    public static function local(): self
    {
        return new self(
            id: self::LOCAL_ID,
            slug: self::LOCAL_ID,
            name: 'Local',
            mode: 'local',
            isLocal: true,
        );
    }

    /**
     * @return array{id: string, slug: string, name: string, mode: string, is_local: bool}
     */
    public function toLogContext(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'mode' => $this->mode,
            'is_local' => $this->isLocal,
        ];
    }
}
