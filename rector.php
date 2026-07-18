<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSkip([
        // The `@var mixed $cached` on the PSR-16 get() assignment is required
        // by psalm (MixedAssignment) — the documented rector<->psalm conflict.
        RemoveUselessVarTagRector::class => [__DIR__ . '/src/CachedProbesMedia.php'],
    ]);
