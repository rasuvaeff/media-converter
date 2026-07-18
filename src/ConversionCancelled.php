<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

/** @api */
final class ConversionCancelled extends \RuntimeException implements MediaConverterException
{
    public function __construct()
    {
        parent::__construct('Media conversion was cancelled');
    }
}
