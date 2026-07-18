<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

/**
 * A 3x3 anchor point within the video frame, used by {@see TextOverlay} to
 * place drawn text relative to a margin from the frame edges.
 *
 * @api
 */
enum Position
{
    case TopLeft;
    case TopCenter;
    case TopRight;
    case MiddleLeft;
    case Center;
    case MiddleRight;
    case BottomLeft;
    case BottomCenter;
    case BottomRight;
}
