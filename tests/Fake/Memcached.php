<?php
if (! class_exists(\Memcached::class)) {
    class Memcached
    {
        public function addServers(array $servers)
        {
        }

        public function getVersion()
        {
            // Return a version that satisfies Symfony Cache requirement (> 3.1.5)
            return ['localhost' => '3.2.0'];
        }
    }
}