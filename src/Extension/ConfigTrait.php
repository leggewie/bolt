<?php

namespace Bolt\Extension;

use Bolt\Filesystem\Exception\RuntimeException;
use Bolt\Filesystem\Handler\DirectoryInterface;
use Bolt\Filesystem\Handler\FileInterface;
use Bolt\Filesystem\Handler\ParsableInterface;
use Bolt\Helpers\Arr;
use Bolt\Storage\Field\FieldInterface;
use Pimple as Container;

/**
 * Config file handling for extensions.
 *
 * @author Carson Full <carsonfull@gmail.com>
 */
trait ConfigTrait
{
    /** @var array */
    private $config;

    /**
     * Register a list of Bolt fields.
     *
     * Example:
     * <pre>
     *  return [
     *      new ColourPickField(),
     *  ];
     * </pre>
     *
     * @return FieldInterface[]
     */
    protected function registerFields()
    {
        return [];
    }

    /**
     * Call this in register method.
     */
    protected function extendConfigService()
    {
        $app = $this->getContainer();
        foreach ((array) $this->registerFields() as $fieldClass) {
            if ($fieldClass instanceof FieldInterface) {
                $app['storage.typemap'] = array_merge(
                    $app['storage.typemap'],
                    [$fieldClass->getName() => get_class($fieldClass)]
                );
                $app['storage.field_manager']->addFieldType($fieldClass->getName(), $fieldClass);
            }
        }
    }

    /**
     * Override this to provide a default configuration,
     * which will be used in the absence of a config file.
     *
     * @return array
     */
    protected function getDefaultConfig()
    {
        return [];
    }

    /**
     * Returns the config for the extension.
     *
     * @return array
     */
    protected function getConfig()
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $this->config = $this->getDefaultConfig();

        $app = $this->getContainer();

        $file = $app['filesystem']->getFile(strtolower("extensions_config://{$this->getName()}.{$this->getVendor()}.yml"));

        if (!$file->exists()) {
            try {
                $this->copyDistFile($file);
            } catch (RuntimeException $e) {
                return $this->config;
            }
        }

        $this->addConfig($file);

        $localFile = $file->getParent()->getFile($file->getFilename('.yml') . '_local.yml');
        if ($localFile->exists()) {
            $this->addConfig($localFile);
        }

        return $this->config;
    }

    /**
     * Merge in a yaml file to the config.
     *
     * @param ParsableInterface $file
     */
    private function addConfig(ParsableInterface $file)
    {
        $app = $this->getContainer();

        try {
            $newConfig = $file->parse();
        } catch (RuntimeException $e) {
            $app['logger.flash']->danger($e->getMessage());
            $app['logger.system']->error($e->getMessage(), ['event' => 'exception', 'exception' => $e]);
            throw $e;
        }

        if (is_array($newConfig)) {
            $this->config = Arr::replaceRecursive($this->config, $newConfig);
        }
    }

    /**
     * Copy config.yml.dist to extension config dir.
     *
     * @param FileInterface $file
     */
    private function copyDistFile(FileInterface $file)
    {
        $distFile = $this->getBaseDirectory()->getFile('config/config.yml.dist');
        $file->write($distFile->read());

        $app = $this->getContainer();
        $app['logger.system']->info(
            sprintf('Copied %s to %s', $distFile->getFullPath(), $file->getFullPath()),
            ['event' => 'extensions']
        );
    }

    /** @return string */
    abstract public function getName();

    /** @return string */
    abstract public function getVendor();

    /** @return DirectoryInterface */
    abstract protected function getBaseDirectory();

    /** @return Container */
    abstract protected function getContainer();
}
