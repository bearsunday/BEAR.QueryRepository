<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

use function assert;
use function dirname;
use function file_get_contents;
use function is_object;
use function json_decode;
use function json_encode;
use function sprintf;

/**
 * Validates RepositoryLogger output against docs/schemas/repository-log.json
 *
 * This is the drift-detection mechanism: it guarantees that the operations
 * actually emitted by the implementation stay consistent with the published
 * JSON Schema contract. If an op or field diverges from the schema, the
 * assertion fails and the drift is caught immediately.
 */
trait SchemaValidationTrait
{
    private function assertLogValidatesSchema(StructuredRepositoryLoggerInterface $logger): void
    {
        $schemaFile = dirname(__DIR__) . '/docs/schemas/repository-log.json';
        $schema = json_decode((string) file_get_contents($schemaFile));
        assert(is_object($schema));
        $validator = new Validator();
        $formatter = new ErrorFormatter();

        foreach ($logger->getLogs() as $entry) {
            // opis/json-schema validates JSON objects (stdClass), not associative arrays
            $data = json_decode((string) json_encode($entry));
            $result = $validator->validate($data, $schema);
            $error = $result->error();
            $errors = $error === null ? [] : $formatter->format($error);
            $this->assertTrue(
                $result->isValid(),
                sprintf(
                    "Log entry does not match repository-log.json:\n  entry: %s\n  errors: %s",
                    (string) json_encode($entry),
                    (string) json_encode($errors),
                ),
            );
        }
    }
}
