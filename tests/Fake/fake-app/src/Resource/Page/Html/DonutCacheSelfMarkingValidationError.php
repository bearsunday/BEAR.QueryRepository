<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

/**
 * The marked-and-error-code case: a top-level
 * #[DonutCache] form page that marks ITSELF (a CSRF token issued alongside the form) and
 * answers 400 for a validation error. handleProceedResult()'s own error-code branch - not
 * the ETag-shortcut one - must never report this to the dev gate: it never did before that
 * shortcut's own cleanup/refusal was added (an error-code response was never gate-checked
 * at all), and marking a response that also happens to answer non-200 is not a developer
 * mistake to report either way.
 */
#[DonutCache]
class DonutCacheSelfMarkingValidationError extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(): static
    {
        $this->mark->mark('csrf-token-issued');
        $this->code = Code::BAD_REQUEST;
        $this->body = ['error' => 'validation failed'];

        return $this;
    }
}
