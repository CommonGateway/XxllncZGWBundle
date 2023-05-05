<?php
/**
 * This class handles the update of a zrc zaak with zrc eigenschap.
 *
 * This ActionHandler executes the
 * ZGWToXxllncService->updateZaakWithEigenschapHandler.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category ActionHandler
 */

namespace CommonGateway\XxllncZGWBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\XxllncZGWBundle\Service\DocumentService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;



class FileToXxllncHandler implements ActionHandlerInterface
{

    /**
     * The ZGW to Xxllnc Service.
     *
     * @var DocumentService
     */
    private DocumentService $documentService;


    /**
     * Class constructor.
     *
     * @param DocumentService $documentService The ZGW to Xxllnc Service
     */
    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;

    }//end __construct()


    /**
     * This function returns the required configuration as
     * a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this action should comply to
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

    }//end getConfiguration()


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
        return $this->documentService->fileToXxllncHandler($data, $configuration);

    }//end run()


}//end class
