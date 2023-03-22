<?php

// src/XxllncZGWBundle.php

namespace CommonGateway\XxllncZGWBundle\src;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * This main class makes the bundle findable and useable.
 *
 * @author Barry Brands <barry@conduction.nl>
 */
class XxllncZGWBundle extends Bundle
{

    /**
     * Returns the path the bundle is in
     *
     * @return string
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);

    }//end getPath()

}//end class
