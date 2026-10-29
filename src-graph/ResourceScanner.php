<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use BEAR\Resource\ResourceObject;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function class_exists;
use function file_get_contents;
use function ksort;
use function preg_match;
use function realpath;
use function str_ends_with;
use function str_replace;
use function usort;

/**
 * Finds the resource classes under a directory
 *
 * Classes are loaded through the application's autoloader only; a file is never included
 * directly, so a file the autoloader does not resolve is reported as skipped, not executed.
 */
final class ResourceScanner
{
    /** @var list<array{file: string, reason: string}> */
    public array $skipped = [];

    /** @return array<class-string<ResourceObject>, ReflectionClass<ResourceObject>> keyed and sorted by class */
    public function scan(string $dir): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();
            $reported = str_replace('\\', '/', $path);
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            $class = $this->className($path);
            if ($class === null) {
                continue;
            }

            if (! class_exists($class)) {
                $this->skipped[] = ['file' => $reported, 'reason' => "{$class} is not autoloadable"];
                continue;
            }

            $ref = new ReflectionClass($class);
            if ($ref->getFileName() !== realpath($path)) {
                // The autoloader resolves this class to another file: this one is never loaded.
                $loaded = str_replace('\\', '/', (string) $ref->getFileName());
                $this->skipped[] = ['file' => $reported, 'reason' => "{$class} is loaded from {$loaded}"];
                continue;
            }

            if ($ref->isAbstract() || ! $ref->isSubclassOf(ResourceObject::class)) {
                continue;
            }

            /** @var ReflectionClass<ResourceObject> $resource */
            $resource = $ref;
            $classes[$resource->getName()] = $resource;
        }

        ksort($classes);
        usort($this->skipped, static fn (array $a, array $b): int => $a['file'] <=> $b['file']);

        return $classes;
    }

    private function className(string $path): string|null
    {
        $code = (string) file_get_contents($path);
        if (! preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $code, $class)) {
            return null;
        }

        return preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $code, $namespace) ? "{$namespace[1]}\\{$class[1]}" : $class[1];
    }
}
