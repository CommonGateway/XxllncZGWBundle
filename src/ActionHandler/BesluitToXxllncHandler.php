<?php
/**
 * This class handles the synchronization of a zgw brc besluit to a xxllnc case.
 *
 * This ActionHandler executes the besluitToXxllncService->besluitToXxllncHandler.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category ActionHandler
 */

namespace CommonGateway\XxllncZGWBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\XxllncZGWBundle\Service\BesluitToXxllncService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;

class BesluitToXxllncHandler implements ActionHandlerInterface
{

    /**
     * BesluitToXxllncService.
     *
     * @var BesluitToXxllncService
     */
    private BesluitToXxllncService $besluitToXxllncService;


    /**
     * Class constructor.
     *
     * @param BesluitToXxllncService $besluitToXxllncService The ZGW to Xxllnc Service
     */
    public function __construct(BesluitToXxllncService $besluitToXxllncService)
    {
        $this->besluitToXxllncService = $besluitToXxllncService;

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
            'description' => 'This handler posts zgw besluit to xxllnc',
            'required'    => [],
            'properties'  => [],
        ];

    }//end getConfiguration()


    /**
     * This function runs the besluitToXxllncHandler.
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
        return $this->besluitToXxllncService->besluitToXxllncHandler($data, $configuration);

    }//end run()


}//end class
