<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

final class HttpCacheResponder
{
    public function __invoke(array $headers, $body)
    {
        // header
        foreach ($headers as $label => $value) {
            header("{$label}: {$value}", false);
        }

        echo $body;
    }
}
