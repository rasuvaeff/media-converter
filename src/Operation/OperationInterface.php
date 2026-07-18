<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * One composable step of a {@see \Rasuvaeff\MediaConverter\Pipeline}.
 *
 * An operation contributes to the command being assembled: it may add an
 * input, a video/audio filter node, an output option, or a stream map. The
 * composition of operations is what becomes the final ffmpeg invocation —
 * there are no fixed "profiles", only operations you combine.
 *
 * Implementations must be pure with respect to {@see CommandSpec}: given the
 * same spec they contribute the same fragments, so a pipeline renders
 * deterministically.
 *
 * @api
 */
interface OperationInterface
{
    public function applyTo(CommandSpec $spec): void;
}
