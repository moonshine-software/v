<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class RootRedirectTest extends TestCase
{
    public function test_the_root_redirects_to_admin_hub(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}
