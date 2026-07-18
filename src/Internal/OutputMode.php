<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Internal;

/** @internal */
enum OutputMode
{
    case Normal;
    case AudioOnly;
    case VideoOnly;
    case SubtitleOnly;
}
