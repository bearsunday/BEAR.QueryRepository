<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;

trait VaryUriTrait
{
    private function getVaryUri(AbstractUri $uri) : string
    {
        if (! isset($_SERVER['X_VARY'])) {
            return (string) $uri;
        }
        $varys = \explode(',', $_SERVER['X_VARY']);
        $varyId = '';
        foreach ($varys as $vary) {
            $phpVaryKey = \sprintf('X_%s', \strtoupper($vary));
            if (isset($_SERVER[$phpVaryKey])) {
                $varyId .= $_SERVER[$phpVaryKey];
            }
        }

        return (string) $uri . $varyId;
    }
}
