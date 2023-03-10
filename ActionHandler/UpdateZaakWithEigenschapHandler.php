<?php

namespace CommonGateway\XxllncZGWBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\XxllncZGWBundle\Service\ZGWToXxllncZaakService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;

class UpdateZaakWithEigenschapHandler implements ActionHandlerInterface
{
    private ZGWToXxllncZaakService $zgwToXxllncZaakService;

    public function __construct(ZGWToXxllncZaakService $zgwToXxllncZaakService)
    {
        $this->zgwToXxllncZaakService = $zgwToXxllncZaakService;
    }

    /**
     *  This function returns the requered configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://docs.commongateway.nl/schemas/UpdateZaakWithEigenschap.ActionHandler.schema.json',
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => 'UpdateZaakWithEigenschap',
            'description' => 'This handler updates zgw zaak with eigenschap and saves to xxllnc',
            'required'    => [],
            'properties'  => [],
        ];
    }

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
        return $this->zgwToXxllncZaakService->updateZaakWithEigenschapHandler($data, $configuration);
    }
}
