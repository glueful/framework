<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Exceptions;

final class LazyLoadingViolationException extends \LogicException
{
    public function __construct(
        public readonly string $modelClass,
        public readonly string $relation,
    ) {
        parent::__construct(sprintf(
            'Attempted to lazy-load [%s] on model [%s], but lazy loading is disabled. '
            . "Add ->with('%s') to the query, or set "
            . '$instanceLazyLoadingMode = \'off\' on the model.',
            $relation,
            $modelClass,
            $relation,
        ));
    }
}
