<?php

namespace App\Domain\Revenue;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;

final class RecognitionScheduleBuilder
{
    /**
     * @return list<ScheduledDay>
     */
    public function build(int $instructorPoolMinor, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($instructorPoolMinor < 0) {
            throw new InvalidArgumentException('Instructor pool cannot be negative.');
        }

        if ($start->getOffset() !== 0 || $end->getOffset() !== 0) {
            throw new InvalidArgumentException('Schedule boundaries must use UTC.');
        }

        if (! $start->isStartOfDay() || ! $end->isStartOfDay() || $start->greaterThanOrEqualTo($end)) {
            throw new InvalidArgumentException('Schedule requires an increasing half-open range of UTC dates.');
        }

        $dayCount = (int) $start->diffInDays($end);
        $baseDaily = intdiv($instructorPoolMinor, $dayCount);
        $remainder = $instructorPoolMinor % $dayCount;
        $days = [];

        for ($index = 0; $index < $dayCount; $index++) {
            $days[] = new ScheduledDay(
                $start->addDays($index),
                $baseDaily + ($index < $remainder ? 1 : 0),
            );
        }

        if (array_sum(array_map(fn (ScheduledDay $day) => $day->instructorPoolMinor, $days)) !== $instructorPoolMinor) {
            throw new LogicException('Recognition schedule did not conserve the instructor pool.');
        }

        return $days;
    }
}
