<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property bool $active
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['slug', 'name', 'active', 'metadata'])]
class ApiAdminTenant extends Model
{
    #[Override]
    protected $table = 'api_admin_tenants';

    public function keyAsString(): string
    {
        return (string) $this->id;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
