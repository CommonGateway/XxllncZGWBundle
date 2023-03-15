<?php

namespace CommonGateway\XxllncZGWBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\XxllncZGWBundle\Service\ZaakService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;

/**
 * This class handles the synchronization of one or more of xxllnc cases to zgw zrc zaken.
 *
 * This ActionHandler executes the zaakService->zaakHandler.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category ActionHandler
 */
class ZaakHandler implements ActionHandlerInterface
{
    private ZaakService $zaakService;

    /**
     * __construct
     */
    public function __construct(ZaakService $zaakService)
    {
        $this->zaakService = $zaakService;
    } //end __construct()

    /**
     * This function returns the requered configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://development.zaaksysteem.nl/schemas/Zaak.ActionHandler.schema.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'ZaakAction',
            'description' => 'This handler customly maps xxllnc case to zgw zaak',
            'required'    => ['zaakEntityId'],
            'properties'  => [
                'zaakTypeEntityId' => [
                    'type'         => 'string',
                    'description'  => 'The UUID of the Zaak Entity on the gateway',
                    'example'      => ''
                ]
            ]
        ];
    } //end getConfiguration()

    /**
     * This function runs the service for validating cases.
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
        return $this->zaakService->zaakHandler($data, $configuration);
    } //end run()
}
