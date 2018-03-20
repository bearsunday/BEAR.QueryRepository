<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

require __DIR__ . '/bootstrap.php';

/* @global $resource ResourceInterface */
/* @var $user ResourceObject */
$user = $resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
echo $user->code . PHP_EOL;
echo $user . PHP_EOL;
