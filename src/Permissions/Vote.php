<?php

declare(strict_types=1);

namespace Glueful\Permissions;

final class Vote
{
    public const GRANT   = 'grant';
    public const DENY    = 'deny';
    public const ABSTAIN = 'abstain';

    public function __construct(public string $result)
    {
    }
}
