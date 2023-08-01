<?php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\XxllncZGWBundle\Service\ZGWToXxllncService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Style\SymfonyStyle;


use function Safe\json_encode;

/**
 * The BesluitToXxllncService handles the sending of ZGW Besluit to the xxllnc v1 api.
 *
 * By mapping, posting and creating a synchronization. Only works if the ztc besluittype also exists in the xxllnc api.
 *
 * @author Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class BesluitToXxllncService
{

    /**
     * @var EntityManagerInterface $entityManager.
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CallService $callService.
     */
    private CallService $callService;

    /**
     * @var MappingService $mappingService.
     */
    private MappingService $mappingService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var ZGWToXxllncService
     */
    private ZGWToXxllncService $zgwToXxllncService;

    /**
     * @var SymfonyStyle $style.
     */
    private SymfonyStyle $style;

    /**
     * @var array $configuration.
     */
    private array $configuration;

    /**
     * @var array $data.
     */
    public array $data;

    /**
     * @var LoggerInterface $logger.
     */
    private LoggerInterface $logger;


    /**
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        ZGWToXxllncService $zgwToXxllncService
    ) {
        $this->entityManager      = $entityManager;
        $this->callService        = $callService;
        $this->mappingService     = $mappingService;
        $this->logger             = $pluginLogger;
        $this->resourceService    = $resourceService;
        $this->zgwToXxllncService = $zgwToXxllncService;

    }//end __construct()


    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $style): self
    // @TODO change to monolog
    {
        $this->style = $style;

        return $this;

    }//end setStyle()


    /**
     * Saves a case relation from a case (Besluit) to another case (Zaak) to xxllnc by POST.
     *
     * @param string $caseSourceId        Case id from xxllnc.
     * @param string $besluitCaseSourceId Case (Besluit) id from xxllnc.
     *
     * @return bool True if succesfully saved to xxllnc.
     */
    public function sendCaseRelationForBesluit(string $caseSourceId, string $besluitCaseSourceId): bool
    {
        $xxllncAPI = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');

        $logMessage = "Posting relation for case (besluit): $besluitCaseSourceId to normal case: $caseSourceId";
        $endpoint   = "/case/$besluitCaseSourceId/relation/add";
        $bodyString = json_encode(['related_id' => $caseSourceId]);

        // Send the POST/PUT request to xxllnc.
        try {
            isset($this->style) === true && $this->style->info($logMessage);
            $response = $this->callService->call($xxllncAPI, $endpoint, 'POST', ['body' => $bodyString]);
            $result   = $this->callService->decodeResponse($xxllncAPI, $response);

            $caseId = $result['result']['reference'] ?? null;
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to set relation for besluit case to normal case, message:  {$e->getMessage()}");

            return false;
        }//end try

        return true;

    }//end sendCaseRelationForBesluit()


    /**
     * Maps zgw besluit to xxllnc case.
     *
     * @param string       $besluittypeSourceId The besluittype source id.
     * @param ObjectEntity $besluitObject       ZGW Besluit object.
     * @param ObjectEntity $zaakObject          Zgw Zaak object.
     *
     * @throws Exception
     *
     * @return string|null $this->data Data which we entered the function with.
     */
    public function mapBesluitToXxllnc(string $besluittypeSourceId, ObjectEntity $besluitObject, ObjectEntity $zaakObject): ?string
    {
        $this->zgwToXxllncService->xxllncZaakSchema = $this->resourceService->getSchema('https://development.zaaksysteem.nl/schema/xxllnc.zaakPost.schema.json',                  'common-gateway/xxllnc-zgw-bundle');
        $this->zgwToXxllncService->xxllncAPI        = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json',               'common-gateway/xxllnc-zgw-bundle');
        $xxllncZaakMapping                          = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.ZgwBesluitToXxllncCase.mapping.json', 'common-gateway/xxllnc-zgw-bundle');

        $zaakArrayObject = $zaakObject->toArray();
        $bsn             = ($zaakArrayObject['rollen'][0]['betrokkeneIdentificatie']['inpBsn'] ?? $zaakArrayObject['verantwoordelijkeOrganisatie'] ) ?? null;
        if ($bsn === null) {
            $this->logger->error('No bsn found in a rol->betrokkeneIdentificatie->inpBsn or verantwoordelijkeOrganisatie.');

            return null;
        }

        $besluitObjectArray  = array_merge($besluitObject->toArray(), ['bsn' => $bsn, 'caseTypeId' => $besluittypeSourceId]);
        $besluitMappingArray = $this->mappingService->mapping($xxllncZaakMapping, $besluitObjectArray);
        unset($besluitMappingArray['zgwZaak']);

        $caseObject = $this->zgwToXxllncService->getCaseObject($besluitObject->toArray(), 'besluit');
        $caseObject->hydrate($besluitMappingArray);
        $this->entityManager->persist($caseObject);
        $this->entityManager->flush();

        $caseArray = $caseObject->toArray();

        $synchronization = null;
        // Only get synchronization that has a sourceId.
        if ($caseObject->getSynchronizations()->first() !== false
            && $caseObject->getSynchronizations()->first()->getSourceId() !== null
        ) {
            $synchronization = $caseObject->getSynchronizations()->first();
        }

        $sourceId = $this->zgwToXxllncService->sendCaseToXxllnc($caseArray, $besluitObject, $synchronization, 'besluit');
        if ($sourceId === false) {
            return null;
        }

        return $sourceId;

    }//end mapBesluitToXxllnc()


    /**
     * Gets the zaak object from the path.
     *
     * @param array        $pathParameters The path from the request.
     * @param ObjectEntity $besluitObject  The besluit object from the request.
     *
     * @return ObjectEnttiy|null $zaakObject.
     */
    private function getZaakObject(array $pathParameters, ObjectEntity $besluitObject): ?ObjectEntity
    {
        $zaakId = null;
        foreach ($pathParameters as $path) {
            if (Uuid::isValid($path)) {
                $zaakId = $path;
            }
        }

        if ($zaakId === null) {

            return null;
        }

        $zaakObject = $this->resourceService->getObject($zaakId);
        if ($zaakObject instanceof ObjectEntity === false) {

            return null;
        }

        $besluitObject->setValue('zaak', $zaakObject);
        $this->entityManager->persist($besluitObject);
        $this->entityManager->flush();

        return $zaakObject;

    }//end getZaakObject()


    /**
     * Gets the xxllnc case object which we need the id from.
     *
     * @param string $zaakId.
     *
     * @return ObjectEnttiy|null $zaakObject.
     */
    private function getCaseObject(string $zaakId): ?ObjectEntity
    {
        $zgwZaakAttribute = $this->entityManager->getRepository('App:Attribute')->findOneBy(['name' => 'zgwZaak']);
        if ($zgwZaakAttribute === null) {
            $this->logger->error('zgwZaak Attribute could not be found.');

            return null;
        }

        $value = $this->entityManager->getRepository('App:Value')->findOneBy(['stringValue' => $zaakId, 'attribute' => $zgwZaakAttribute]);
        if ($zgwZaakAttribute === null) {
            $this->logger->error("zgwZaak value could not be found with zaak id: $zaakId");

            return null;
        }

        return $value->getObjectEntity();

    }//end getCaseObject()

    /**
     * Gets the xxllnc case id from the case object.
     *
     * @param ObjectEntity $caseObject.
     *
     * @return string|null external xxllnc case id.
     */
    private function getExternalCaseId(ObjectEntity $caseObject): ?string
    {
        if ($caseObject->getSynchronizations()->first() !== false && Uuid::isValid($caseObject->getSynchronizations()->first()->getSourceId()) === true) {
            return $caseObject->getSynchronizations()->first()->getSourceId();
        }

        return null;

    }//end getExternalCaseId()


    /**
     * Maps the Besluit to a xxllnc case, post it, and creates a realtion to the actual case.
     * We create a sub case for the Besluit because the xxllnc api does not have a Besluit variant.
     */
    private function syncBesluitToXxllnc()
    {
        $zaakBesluitObject = $this->resourceService->getObject($this->data['response']['_self']['id']);
        if ($zaakBesluitObject === null) {
            $this->logger->error('syncBesluitToXxllnc returned, no besluit object could be found with id:.');

            return [];
        }

        // Get ZaakObject so we can get its associated xxllnc case object.
        $zaakObject = $this->getZaakObject($this->data['parameters']['path'], $zaakBesluitObject);
        if ($zaakObject === null) {
            $this->logger->error('syncBesluitToXxllnc returned, no zaak object could be found.');

            return [];
        }

        // Get xxllnc case object so we can get its sourceId.
        $caseObject = $this->getCaseObject($zaakObject->getId()->toString());
        if ($caseObject === null) {
            $this->logger->error('syncBesluitToXxllnc returned, no case object could be found.');

            return [];
        }

        // Check and get the synchronization its source id from the xxllnc case object.
        $caseSourceId = $this->getExternalCaseId($caseObject);
        if ($caseSourceId === null) {
            $this->logger->error('syncBesluitToXxllnc returned, case not synced to xxllnc');

            return [];
        }



        // Get the besluit object from request body.
        $besluitId = substr($this->data['response']['besluit'], strrpos($this->data['response']['besluit'], '/') + 1);
        $besluitObject = $this->resourceService->getObject($besluitId);
        if ($besluitObject instanceof ObjectEntity === false) {
            $this->logger->error('syncBesluitToXxllnc returned, no besluit set on zaakBesluit');

            return [];
        }

        // Get the besluittype object so we can check its synchronizaitons.
        $besluitTypeId = substr($besluitObject->getValue('besluittype'), strrpos($besluitObject->getValue('besluittype'), '/') + 1);
        $besluitTypeObject = $this->resourceService->getObject($besluitTypeId);
        if ($besluitTypeObject instanceof ObjectEntity === false) {
            $this->logger->error('syncBesluitToXxllnc returned, no besluitType set on besluit');

            return [];
        }
        
        // Check the besluittype synchronization and source id so we know it came fromt the xxllnc api.
        if ($besluitTypeObject->getSynchronizations()->first() === false || $besluitTypeObject->getSynchronizations()->first()->getSourceId() === null) {
            $this->logger->error('syncBesluitToXxllnc returned, the associated besluittype is not synced from the xxllnc api because there is no synchronization or sourceId on that synchronization found.');

            return [];
        }

        // Get the besluittype source id.
        $besluitTypeSourceId = $besluitTypeObject->getSynchronizations()->first()->getSourceId();

        // Map besluit case to xxllnc.
        $besluitCaseSourceId = $this->mapBesluitToXxllnc($besluitTypeSourceId, $besluitObject, $zaakObject);
        if (isset($besluitCaseSourceId) === false) {
            $this->logger->error("Failed to map/send besluit to xxllnc, quitting syncBesluitToXxllnc.");

            return [];
        }

        // Link normal case and besluit case at xxllnc api.
        if ($this->sendCaseRelationForBesluit($caseSourceId, $besluitCaseSourceId) === false) {
            $this->logger->error("setting case relation for case $caseSourceId and case (besluit) $besluitCaseSourceId failed.");

            return [];
        }

        return [];

    }//end syncBesluitToXxllnc()


    /**
     * Creates or updates a ZGW Besluit as a xxllnc related case to the main case.
     *
     * @param ?array $data          Data from the handler where the xxllnc casetype is in.
     * @param ?array $configuration Configuration from the Action where the Zaak entity id is stored in.
     *
     * @throws Exception
     *
     * @return array $this->data Data which we entered the function with.
     *
     * @todo Make function smaller and more readable.
     */
    public function besluitToXxllncHandler(?array $data = [], ?array $configuration = []): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        if (isset($this->data['response']['_self']['id']) === false || isset($this->data['response']['besluit']) === false) {
            $this->logger->error('syncBesluitToXxllnc returned, no id or besluit found in given zaakbesluit.');
            var_dump('died');die;

            return [];
        }

        return ['response' => $this->syncBesluitToXxllnc()];

    }//end besluitToXxllncHandler()


}//end class
