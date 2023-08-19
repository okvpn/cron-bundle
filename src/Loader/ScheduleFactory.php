<?php

declare(strict_types=1);

namespace Okvpn\Bundle\CronBundle\Loader;

use Okvpn\Bundle\CronBundle\Model\JitterStamp;
use Okvpn\Bundle\CronBundle\Model\PeriodicalStampInterface;
use Okvpn\Bundle\CronBundle\Model\ScheduleEnvelope;

final class ScheduleFactory implements ScheduleFactoryInterface
{
    private $withStampsFqcn;
    private $defaultStampsMap;

    /**
     * @param array $withStampsFqcn
     * @param array $defaultStampsMap
     */
    public function __construct(array $withStampsFqcn = [], array $defaultStampsMap = [])
    {
        $this->withStampsFqcn = $withStampsFqcn;
        $this->defaultStampsMap = $defaultStampsMap;
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $config): ScheduleEnvelope
    {
        if (!isset($config['command'])) {
            throw new \InvalidArgumentException('Command name is a required parameter');
        }

        $stamps = [];
        $withStampsFqcn = array_merge($config['options']['with'] ?? [], $this->withStampsFqcn);
        foreach ($withStampsFqcn as $stampFqcn) {
            $stamps[] = new $stampFqcn($config);
        }

        foreach ($this->defaultStampsMap as $key => $stampFqcn) {
            if (isset($config[$key]) && $config[$key]) {
                $stamps[] = new $stampFqcn($config[$key]);
            }
        }

        $envelope = new ScheduleEnvelope($config['command'], ...$stamps);
        if (is_numeric($config['jitter'] ?? null) && ($stamp = $envelope->get(PeriodicalStampInterface::class))) {
            $envelope = $envelope->with(new JitterStamp((int)$config['jitter'], $stamp));
        }

        return $envelope;
    }
}
