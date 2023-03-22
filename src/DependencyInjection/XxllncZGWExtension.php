<?php

namespace CommonGateway\XxllncZGWBundle\src\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This class loads all files in this bundle.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category DependencyInjection
 */
class XxllncZGWExtension extends Extension
{


    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../Resources/config'));
        $loader->load('services.yaml');

    }//end load()


}//end class
