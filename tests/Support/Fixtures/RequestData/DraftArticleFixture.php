<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * End-to-end fixture exercising every v2 hydrator feature:
 *  - #[FromRoute] path binding (uuid, locale)
 *  - #[FromQuery] query binding with a field #[Rule] (preview)
 *  - #[ArrayOf(DtoClass)] nested-DTO body array with recursive 422s (schema)
 *  - ValidatesSelf cross-field validation (schema must be non-empty unless previewing)
 */
final class DraftArticleFixture implements RequestData, ValidatesSelf
{
    /** @param array<int,FieldDefFixture> $schema */
    public function __construct(
        #[FromRoute] public string $uuid = '',
        #[FromRoute] public string $locale = '',
        #[FromQuery] #[Rule('in:true,false')] public string $preview = 'false',
        #[ArrayOf(FieldDefFixture::class)] #[Rule('required|array')] public array $schema = [],
    ) {
    }

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        // When not previewing, the schema must contain at least one field definition.
        if ($this->preview === 'false' && $this->schema === []) {
            return ['schema' => ['At least one field is required unless preview is enabled.']];
        }
        return [];
    }
}
