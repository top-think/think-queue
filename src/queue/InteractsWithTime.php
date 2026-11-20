<?php

namespace think\queue;

use Carbon\Carbon;
use DateInterval;
use DateTimeInterface;

trait InteractsWithTime
{
    /**
     * Get the number of seconds until the given DateTime.
     *
     * @param DateTimeInterface|DateInterval|int $delay
     * @return int
     */
    protected function secondsUntil($delay)
    {
        $delay = $this->parseDateInterval($delay);

        return $delay instanceof DateTimeInterface
            ? max(0, $delay->getTimestamp() - $this->currentTime())
            : (int) $delay;
    }

    /**
     * Get the "available at" UNIX timestamp.
     *
     * @param DateTimeInterface|DateInterval|int $delay
     * @return int
     */
    protected function availableAt($delay = 0)
    {
        $delay = $this->parseDateInterval($delay);

        return $delay instanceof DateTimeInterface
            ? $delay->getTimestamp()
            : Carbon::now()->addRealSeconds($delay)->getTimestamp();
    }

    /**
     * If the given value is an interval, convert it to a DateTime instance.
     *
     * @param DateTimeInterface|DateInterval|int $delay
     * @return DateTimeInterface|int
     */
    protected function parseDateInterval($delay)
    {
        if ($delay instanceof DateInterval) {
            $delay = Carbon::now()->add($delay);
        }

        return $delay;
    }

    /**
     * Get the current system time as a UNIX timestamp.
     *
     * @return int
     */
    protected function currentTime()
    {
        return Carbon::now()->getTimestamp();
    }

    /**
     * Get the "available at" UNIX millisecond timestamp.
     *
     * 延迟任务调度统一使用毫秒精度：
     * - 传入 DateTimeInterface 时视为绝对时间（秒级精度，毫秒位补零）
     * - 传入数值时视为延迟秒数
     *
     * @param DateTimeInterface|DateInterval|int $delay
     * @return int
     */
    protected function availableAtMs($delay = 0)
    {
        $delay = $this->parseDateInterval($delay);

        return $delay instanceof DateTimeInterface
            ? $delay->getTimestamp() * 1000
            : $this->currentTimeMs() + (int) ($delay * 1000);
    }

    /**
     * Get the current system time as a UNIX millisecond timestamp.
     *
     * @return int
     */
    public function currentTimeMs()
    {
        return (int) floor(microtime(true) * 1000);
    }
}
