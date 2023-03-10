<?php

namespace CommonGateway\XxllncZGWBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\XxllncZGWBundle\Service\XxllncToZGWZaakTypeService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;

class XxllncToZGWZaakTypeHandler implements ActionHandlerInterface
{
    private XxllncToZGWZaakTypeService $xxllncToZGWZaakTypeService;

    public function __construct(XxllncToZGWZaakTypeService $xxllncToZGWZaakTypeService)
    {
        $this->xxllncToZGWZaakTypeService = $xxllncToZGWZaakTypeService;
    }

    /**
     *  This function returns the requered configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://development.zaaksysteem.nl/schemas/XxllncToZGWZaakType.ActionHandler.schema.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'ZGW ZaakType Action',
            'description' => 'This handler customly maps xxllnc casetype to zgw zaaktype ',
            'required'    => ['zaakTypeEntityId'],
            'properties'  => [
                'zaakTypeEntityId' => [
                    'type'        => 'string',
                    'description' => 'The UUID of the case entitEntity on the gateway',
                    'example'     => '',
                ],
            ],
        ];
    }

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
        return $this->xxllncToZGWZaakTypeService->xxllncToZGWZaakTypeHandler($data, $configuration);
    }
}
