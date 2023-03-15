<?php

namespace CommonGateway\XxllncZGWBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\XxllncZGWBundle\Service\ZGWToXxllncZaakService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;

/**
 * This class handles the synchronization of a zgw zrc zaak to a xxllnc case.
 *
 * This ActionHandler executes the zgwToXxllncZaakService->zgwToXxllncZaakHandler.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category ActionHandler
 */
class ZGWToXxllncZaakHandler implements ActionHandlerInterface
{

    /**
     * ZGWToXxllncZaakService
     */
    private ZGWToXxllncZaakService $zgwToXxllncZaakService;

    /**
     * __construct
     */
    public function __construct(ZGWToXxllncZaakService $zgwToXxllncZaakService)
    {
        $this->zgwToXxllncZaakService = $zgwToXxllncZaakService;
    }//end __construct()

    /**
     *  This function returns the requered configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://development.zaaksysteem.nl/schemas/ZGWToXxllncZaak.ActionHandler.schema.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'ZGWZaakToXxllnc',
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
        return $this->zgwToXxllncZaakService->zgwToXxllncZaakHandler($data, $configuration);
    }//end run()
}
