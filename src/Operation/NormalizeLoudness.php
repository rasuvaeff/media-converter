<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * EBU R128 loudness normalisation (`loudnorm`): integrated loudness target
 * `I` (LUFS), true peak ceiling `TP` (dBTP), loudness range `LRA` (LU).
 *
 * @api
 */
final readonly class NormalizeLoudness implements OperationInterface
{
    public function __construct(
        private float $integratedLufs = -16.0,
        private float $truePeakDbtp = -1.5,
        private float $loudnessRangeLu = 11.0,
    ) {
        if ($integratedLufs < -70.0 || $integratedLufs > -5.0) {
            throw new \InvalidArgumentException('Integrated loudness target must be between -70 and -5 LUFS');
        }

        if ($truePeakDbtp < -9.0 || $truePeakDbtp > 0.0) {
            throw new \InvalidArgumentException('True peak ceiling must be between -9 and 0 dBTP');
        }

        if ($loudnessRangeLu < 1.0 || $loudnessRangeLu > 50.0) {
            throw new \InvalidArgumentException('Loudness range must be between 1 and 50 LU');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->addAudioFilter(sprintf(
            'loudnorm=I=%s:TP=%s:LRA=%s',
            $this->integratedLufs,
            $this->truePeakDbtp,
            $this->loudnessRangeLu,
        ));
    }
}
