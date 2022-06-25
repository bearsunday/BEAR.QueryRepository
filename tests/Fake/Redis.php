<?php

if (! class_exists(\Redis::class)) {
    class Redis
    {
        public const OPT_SERIALIZER = '';
        public const SERIALIZER_PHP = '';

        public function connect($host, $port)
        {
        }

        public function setOption()
        {
        }
    }
}