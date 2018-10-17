<?php

if (! class_exists('Redis')) {
    class Redis
    {
        const OPT_SERIALIZER = '';
        const SERIALIZER_PHP = '';

        public function connect($host, $port)
        {
        }

        public function setOption()
        {
        }
    }
}