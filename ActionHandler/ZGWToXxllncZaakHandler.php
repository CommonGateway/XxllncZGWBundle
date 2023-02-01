<?php

namespace CommonGateway\XxllncZGWBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\XxllncZGWBundle\Service\ZGWToXxllncZaakService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;

class ZGWToXxllncZaakHandler implements ActionHandlerInterface
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
            '$id'         => 'https://example.com/person.schema.json',
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => 'Zaakeigenschappen Action',
            'description' => 'This handler posts zaak eigenschappen from ZDS to ZGW',
            'required'    => ['identifierPath'],
            'properties'  => [
                'identifierPath' => [
                    'type'        => 'string',
                    'description' => 'The DNS of the mail provider, see https://symfony.com/doc/6.2/mailer.html for details',
                    'example'     => 'native://default',
                ],
                'eigenschappen' => [
                    'type'        => 'array',
                    'description' => '',
                ],
            ],
        ];
    }

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
    }
}
