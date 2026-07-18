<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Progress;

/** @api */
enum ConversionPhase: string
{
    case Probing = 'probing';
    case Running = 'running';
    case Committing = 'committing';
    case Completed = 'completed';
}
