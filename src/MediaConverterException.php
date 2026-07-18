<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

/**
 * Marker for every reachable failure this package throws at a consumer:
 * {@see ConversionFailed} (a subprocess/validation failure) and
 * {@see ConversionCancelled} (a cooperative cancellation). Catch this to
 * handle both in one `catch` without listing each class:
 *
 * ```php
 * try {
 *     $converter->run($pipeline, $output);
 * } catch (MediaConverterException $e) {
 *     // ConversionFailed or ConversionCancelled
 * }
 * ```
 *
 * Internal `\LogicException`s guard impossible states (a programming bug, not
 * a runtime condition) and deliberately do NOT implement this marker — they
 * are meant to surface, not be swallowed by a domain `catch`.
 *
 * @api
 */
interface MediaConverterException extends \Throwable {}
