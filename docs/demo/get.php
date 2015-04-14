<?php

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

require __DIR__ . '/bootstrap.php';

/** @var $user ResourceObject */
/** @var $resource ResourceInterface */
$user = $resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();

echo $user->code. PHP_EOL;
echo $user . PHP_EOL;
