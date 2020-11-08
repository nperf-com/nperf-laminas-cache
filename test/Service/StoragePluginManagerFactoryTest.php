<?php

/**
 * @see       https://github.com/laminas/laminas-cache for the canonical source repository
 * @copyright https://github.com/laminas/laminas-cache/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-cache/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\Cache\Service;

use Interop\Container\ContainerInterface;
use Laminas\Cache\Service\StoragePluginManagerFactory;
use Laminas\Cache\Storage\Plugin\PluginInterface;
use Laminas\Cache\Storage\PluginManager;
use Laminas\ServiceManager\ServiceLocatorInterface;
use PHPUnit\Framework\TestCase;

class StoragePluginManagerFactoryTest extends TestCase
{
    public function testFactoryReturnsPluginManager(): void
    {
        $container = $this->prophesize(ContainerInterface::class)->reveal();
        $factory = new StoragePluginManagerFactory();

        $plugins = $factory($container, PluginManager::class);
        $this->assertInstanceOf(PluginManager::class, $plugins);
    }

    public function testFactoryConfiguresPluginManagerUnderContainerInterop(): void
    {
        $container = $this->prophesize(ContainerInterface::class)->reveal();
        $plugin = $this->prophesize(pluginInterface::class)->reveal();

        $factory = new StoragePluginManagerFactory();
        $plugins = $factory($container, PluginManager::class, [
            'services' => [
                'test' => $plugin,
            ],
        ]);
        $this->assertSame($plugin, $plugins->get('test'));
    }

    public function testFactoryConfiguresPluginManagerUnderServiceManagerV2(): void
    {
        $container = $this->prophesize(ServiceLocatorInterface::class);
        $container->willImplement(ContainerInterface::class);

        $plugin = $this->prophesize(PluginInterface::class)->reveal();

        $factory = new StoragePluginManagerFactory();
        $factory->setCreationOptions([
            'services' => [
                'test' => $plugin,
            ],
        ]);

        $plugins = $factory->createService($container->reveal());
        $this->assertSame($plugin, $plugins->get('test'));
    }
}
