<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Support\Renderable;
use MoonShine\UI\Fields\Field;
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

    private function previewOf(Field $field): string
    {
        $preview = $field->fill(self::RAW)->preview();

        return $preview instanceof Renderable ? $preview->render() : $preview;
    }
}
