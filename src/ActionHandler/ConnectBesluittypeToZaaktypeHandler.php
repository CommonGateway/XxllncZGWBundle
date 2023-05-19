<?php
/**
 * This class handles the synchronization of one or more of xxllnc casetypes to zgw ztc zaaktypen.
 *
 * This ActionHandler executes the zaakTypeService->zaakTypeHandler.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category ActionHandler
 */

namespace CommonGateway\XxllncZGWBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\XxllncZGWBundle\Service\ZaakTypeService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;

class ConnectBesluittypeToZaaktypeHandler implements ActionHandlerInterface
{

    /**
     * The case type service.
     *
     * @var ZaakTypeService
     */
    private ZaakTypeService $zaakTypeService;


    /**
     * Class constructor.
     *
     * @param ZaakTypeService $zaakTypeService The case type service
     */
    public function __construct(ZaakTypeService $zaakTypeService)
    {
        $this->zaakTypeService = $zaakTypeService;

    }//end __construct()


    /**
     * This function returns the requered configuration as
     * a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://development.zaaksysteem.nl/schemas/ZaakType.ActionHandler.schema.json',
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

    }//end getConfiguration()


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
        return $this->zaakTypeService->connectBesluittypeToZaaktypeHandler($data, $configuration);

    }//end run()


}//end class
