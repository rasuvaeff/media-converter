<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            // tests/Integration needs real ffmpeg/ffprobe binaries (skipped
            // per-test when unavailable); keep it out of the default Unit
            // suite so `composer build` stays green with neither installed.
            location: new FinderConfig(include: ['tests'], exclude: ['tests/Integration']),
        ),
        new SuiteConfig(
            name: 'Benchmarks',
            location: ['benchmarks'],
        ),
        new SuiteConfig(
            name: 'Integration',
            location: ['tests/Integration'],
        ),
    ],
);
