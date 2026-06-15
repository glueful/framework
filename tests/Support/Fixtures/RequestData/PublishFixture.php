<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

final class PublishFixture implements RequestData, ValidatesSelf
{
    public function __construct(
        #[Rule('required|in:draft,published')] public string $status = 'draft',
        #[Rule('string')] public ?string $publishedAt = null,
    ) {
    }

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        if ($this->status === 'published' && $this->publishedAt === null) {
            return ['publishedAt' => ['Required when status is published.']];
        }
        return [];
    }
}
