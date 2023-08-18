<?php

declare(strict_types=1);

namespace Okvpn\Bundle\CronBundle\DependencyInjection;

use Okvpn\Bundle\CronBundle\Attribute\AsCron;
use Okvpn\Bundle\CronBundle\Attribute\AsPeriodicTask;
use Okvpn\Bundle\CronBundle\CronServiceInterface;
use Okvpn\Bundle\CronBundle\CronSubscriberInterface;
use Okvpn\Bundle\CronBundle\Loader\ScheduleLoaderInterface;
use Okvpn\Bundle\CronBundle\Middleware\MiddlewareEngineInterface;
use Okvpn\Bundle\CronBundle\Model;
use Okvpn\Bundle\CronBundle\Runner\ScheduleLoopInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * This is the class that loads and manages your bundle configuration.
 */
final class OkvpnCronExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        if (true === ($config['messenger']['enable'] ?? false)) {
            if (!\interface_exists(MessageBusInterface::class)) {
                throw new LogicException('Messenger cron handle cannot be enabled as the Messenger component is not installed. Try running "composer require symfony/messenger".');
            }

            $loader->load('messenger.yml');
            if (isset($config['messenger']['default_bus'])) {
                $container->getDefinition('okvpn_cron.middleware.messenger')
                    ->replaceArgument(0, new Reference($config['messenger']['default_bus']));
            }
        }

        $container->setParameter('okvpn.config.default_policy', $config['default_policy'] ?? []);
        if (isset($config['lock_factory'])) {
            $container->getDefinition('okvpn_okvpn_cron.middleware.lock')
                ->replaceArgument(0, new Reference($config['lock_factory']));
        }

        $container->setAlias(ScheduleLoopInterface::class, $config['loop_engine'] ?? 'okvpn_cron.standalone_loop');

        $tasks = [];
        foreach (($config['tasks'] ?? []) as $task) {
            $task['shell'] = $task['shell'] ?? true;
            $tasks[] = $task;
        }

        $defaultStamps = [
            'shell' => Model\ShellStamp::class,
            'lock' => Model\LockStamp::class,
            'messenger' => Model\MessengerStamp::class,
            'cron' => Model\ScheduleStamp::class,
            'async' => Model\AsyncStamp::class,
            'arguments' => Model\ArgumentsStamp::class,
            'interval' => Model\PeriodicalScheduleStamp::class
        ];

        $container->getDefinition('okvpn_cron.array_loader')
            ->replaceArgument(0, $tasks);
        $container->getDefinition('okvpn_cron.schedule_factory')
            ->replaceArgument(0, $config['with_stamps'] ?? [])
            ->replaceArgument(1, $defaultStamps);

        $container->getDefinition('okvpn_cron.middleware.cron_expression')
            ->replaceArgument(1, $config['timezone']);
        $container->setParameter('okvpn.config.cron_timezone', $config['timezone']);

        $container->registerForAutoconfiguration(MiddlewareEngineInterface::class)
            ->addTag('okvpn_cron.middleware');
        $container->registerForAutoconfiguration(ScheduleLoaderInterface::class)
            ->addTag('okvpn_cron.loader');
        $container->registerForAutoconfiguration(CronSubscriberInterface::class)
            ->addTag('okvpn.cron');
        $container->registerForAutoconfiguration(CronServiceInterface::class)
            ->addTag('okvpn.cron_service');

        if (method_exists($container, 'registerAttributeForAutoconfiguration')) {
            $container->registerAttributeForAutoconfiguration(AsCron::class, static function (ChildDefinition $definition, AsCron $cron) {
                $definition->addTag('okvpn.cron', $cron->getAttributes());
            });

            $container->registerAttributeForAutoconfiguration(AsPeriodicTask::class, static function (ChildDefinition $definition, AsPeriodicTask $cron) {
                $definition->addTag('okvpn.cron', $cron->getAttributes());
            });
        }
    }
}
