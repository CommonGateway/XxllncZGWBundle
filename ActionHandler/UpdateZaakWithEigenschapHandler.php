<?php

namespace CommonGateway\XxllncZGWBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\XxllncZGWBundle\Service\ZGWToXxllncService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;


/**
 * This class handles the update of a zrc zaak with zrc eigenschap.
 *
 * This ActionHandler executes the ZGWToXxllncService->updateZaakWithEigenschapHandler.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category ActionHandler
 */
class UpdateZaakWithEigenschapHandler implements ActionHandlerInterface
{

    /**
     * @var ZGWToXxllncService
     */
    private ZGWToXxllncService $zgwToXxllncService;

    /**
     * __construct
     */
    public function __construct(ZGWToXxllncService $zgwToXxllncService)
    {
        $this->zgwToXxllncService = $zgwToXxllncService;
    } //end __construct()

    /**
     * This function returns the requered configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://development.zaaksysteem.nl/schemas/UpdateZaakWithEigenschap.ActionHandler.schema.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'UpdateZaakWithEigenschap',
            'description' => 'This handler updates zgw zaak with eigenschap and saves to xxllnc',
            'required'    => [],
            'properties'  => [],
        ];
    } //end getConfiguration()

    /**
     * This function runs the zgw zaak eigenschap update.
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
        return $this->zgwToXxllncService->updateZaakWithEigenschapHandler($data, $configuration);
    } //end run()
}
