<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Core\TypeCasts\MixedDataWrapper;
use MoonShine\Crud\Collections\Fields;
use MoonShine\UI\Fields\Field;
use MoonShine\UI\Fields\Password;
use MoonShine\UI\Fields\Text;
use MoonShine\UI\Fields\Textarea;
use Tests\TestCase;

/**
 * Upstream APIs own their data: form values go out exactly as entered and are
 * escaped only when displayed. Escaping on apply corrupted upstream records
 * ("Tom &amp; Jerry") and double-encoded them on every re-save.
 */
class RestPayloadEscapingTest extends TestCase
{
    private const string RAW = 'Tom & Jerry <b>"x"</b>';

    public function test_text_fields_apply_the_entered_value_unchanged(): void
    {
        request()->merge(['title' => self::RAW, 'body' => self::RAW]);

        $this->assertSame(self::RAW, Text::make('Title', 'title')->getRequestValue());
        $this->assertSame(self::RAW, Textarea::make('Body', 'body')->getRequestValue());
    }

    public function test_text_fields_still_escape_the_displayed_value(): void
    {
        $escaped = 'Tom &amp; Jerry &lt;b&gt;&quot;x&quot;&lt;/b&gt;';

        $this->assertStringContainsString($escaped, $this->previewOf(Text::make('Title', 'title')));
        $this->assertStringContainsString($escaped, $this->previewOf(Textarea::make('Body', 'body')));
    }

    public function test_save_sends_raw_secrets_to_the_api_without_logging_their_values(): void
    {
        $payload = ['password' => 'raw-password<&>', 'profile' => ['token' => 'nested-secret<&>']];
        request()->merge($payload);
        Http::fake(['https://api.example.test/users' => Http::response(['data' => ['id' => 1]], 201)]);
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });
        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return ServiceConfig::fromArray([
                    'name' => 'log-test', 'base_url' => 'https://api.example.test', 'auth_type' => 'none',
                ]);
            }

            public function endpoint(): string
            {
                return '/users';
            }
        };

        $resource->save(new MixedDataWrapper($payload), Fields::make([
            Password::make('Password', 'password')->raw(),
        ]));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === $payload);
        $this->assertNotEmpty($logs);
        $recorded = json_encode($logs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('raw-password', $recorded);
        $this->assertStringNotContainsString('nested-secret', $recorded);
    }

    private function previewOf(Field $field): string
    {
        $preview = $field->fill(self::RAW)->preview();

        return $preview instanceof Renderable ? $preview->render() : $preview;
    }
}
