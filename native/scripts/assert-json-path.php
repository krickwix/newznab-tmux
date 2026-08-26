<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "usage: php native/scripts/assert-json-path.php <json-file> <dot.path> <expected-json>\n");
    exit(2);
}

[$script, $file, $path, $expectedJson] = $argv;

try {
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $expected = json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, "decode json assertion input: {$exception->getMessage()}\n");
    exit(1);
}

$actual = $data;
foreach (explode('.', $path) as $segment) {
    if (! is_array($actual) || ! array_key_exists($segment, $actual)) {
        fwrite(STDERR, "json path {$path} is missing at {$segment}\n");
        exit(1);
    }

    $actual = $actual[$segment];
}

if ($actual !== $expected) {
    $encodedActual = json_encode($actual, JSON_UNESCAPED_SLASHES);
    $encodedExpected = json_encode($expected, JSON_UNESCAPED_SLASHES);
    fwrite(STDERR, "json path {$path} = {$encodedActual}, expected {$encodedExpected}\n");
    exit(1);
}
