<?php declare(strict_types=1);

namespace Swoft\TestLib;

use Swoft\Helper\ComposerJSON;
use Swoft\SwoftComponent;
use function dirname;

/**
 * Class AutoLoader
 */
class AutoLoader extends SwoftComponent
{
    /**
     * Get namespace and dir
     *
     * @return array
     * [
     *     namespace => dir path
     * ]
     */
    public function getPrefixDirs(): array
    {
        return [
            __NAMESPACE__ => __DIR__,
        ];
    }

    /**
     * Metadata information for the component.
     *
     * @return array
     * @see ComponentInterface::getMetadata()
     */
    protected function metadata(): array
    {
        $jsonFile = dirname(__DIR__) . '/composer.json';

        return ComposerJSON::open($jsonFile)->getMetadata();
    }
}
