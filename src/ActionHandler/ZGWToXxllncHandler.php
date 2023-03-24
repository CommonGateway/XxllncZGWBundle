<?php

namespace CommonGateway\XxllncZGWBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\XxllncZGWBundle\Service\ZGWToXxllncService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;

/**
 * This class handles the synchronization of a zgw zrc zaak to a xxllnc case.
 *
 * This ActionHandler executes the zgwToXxllncService->zgwToXxllncHandler.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category ActionHandler
 */
class ZGWToXxllncHandler implements ActionHandlerInterface
{

    /**
     * ZGWToXxllncService
     *
     * @var ZGWToXxllncService $zgwToXxllncService
     */
    private ZGWToXxllncService $zgwToXxllncService;


    /**
     * Class constructor
     *
     * @param ZGWToXxllncService $zgwToXxllncService The ZGW to Xxllnc Service
     */
    public function __construct(ZGWToXxllncService $zgwToXxllncService)
    {
        $this->zgwToXxllncService = $zgwToXxllncService;

    }//end __construct()


    /**
     * This function returns the requered configuration as
     * a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://development.zaaksysteem.nl/schemas/ZGWToXxllnc.ActionHandler.schema.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'ZGWToXxllnc',
            'description' => 'This handler posts zgw zaak to xxllnc',
            'required'    => [],
            'properties'  => [],
        ];

    }//end getConfiguration()


    /**
     * This function runs the zgw zaaktype plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @throws GatewayException
     * @throws CacheException
     * @throws InvalidArgumentException
     * @throws ComponentException
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->zgwToXxllncService->zgwToXxllncHandler($data, $configuration);

    }//end run()


}//end class
