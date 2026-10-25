<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\RenderInterface;
use BEAR\Resource\ResourceObject;
use Override;

use function trigger_error;

use const E_USER_WARNING;

/**
 * A renderer that emits an UNRELATED warning (not shaped like a swallowed-render warning,
 * and not from a Throwable at all) and then renders successfully. Proves the guard never
 * inspects warnings: there is nothing here for a false-positive match to trigger on.
 */
final class FakeWarningEmittingRenderer implements RenderInterface
{
    #[Override]
    public function render(ResourceObject $ro): string
    {
        trigger_error('an unrelated notice during render', E_USER_WARNING);

        return 'rendered-ok';
    }
}
