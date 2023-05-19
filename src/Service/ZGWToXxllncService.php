<?php
/**
 * This class handles the synchronization of a zgw zrc zaak to a xxllnc case.
 *
 * By mapping, posting and creating a synchronization. Only works if the ztc zaaktype also exists in the xxllnc api.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Service
 */

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Attribute;
use App\Entity\Entity as Schema;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Entity\Value;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\ObjectRepository;
use Exception;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Style\SymfonyStyle;

class ZGWToXxllncService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $synService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $style;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $schemaRepo;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $sourceRepo;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        LoggerInterface $pluginLogger,
        SynchronizationService $synService,
        GatewayResourceService $resourceService,
        MappingService $mappingService
    ) {
        $this->entityManager   = $entityManager;
        $this->callService     = $callService;
        $this->logger          = $pluginLogger;
        $this->synService      = $synService;
        $this->resourceService = $resourceService;
        $this->mappingService  = $mappingService;

        $this->schemaRepo = $this->entityManager->getRepository('App:Entity');
        $this->sourceRepo = $this->entityManager->getRepository('App:Gateway');

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
     * Maps the eigenschappen from zgw to xxllnc.
     *
     * @param array        $xxllncZaakArray This is the Xxllnc Zaak array.
     * @param array        $zaakArrayObject This is the ZGW Zaak array.
     * @param ObjectEntity $zaakTypeObject  These is the ZGW ZaakType.
     *
     * @return array $xxllncZaakArray This is the Xxllnc Zaak array with the added eigenschappen.
     */
    private function mapPostEigenschappen(array $xxllncZaakArray, array $zaakArrayObject, ObjectEntity $zaakTypeObject): array
    {
        $zaakTypeEigenschappen = $zaakTypeObject->getValue('eigenschappen');
        if ($zaakTypeEigenschappen instanceof PersistentCollection) {
            $zaakTypeEigenschappen = $zaakTypeEigenschappen->toArray();
        }

        $eigenschapIds = [];
        foreach ($zaakTypeEigenschappen as $eigenschap) {
            $eigenschapIds[] = $eigenschap->getId()->toString();
        }

        // eigenschappen to values
        if (isset($zaakArrayObject['eigenschappen']) === true) {
            foreach ($zaakArrayObject['eigenschappen'] as $zaakEigenschap) {
                if (isset($zaakEigenschap['eigenschap']) === true && in_array($zaakEigenschap['eigenschap']['_self']['id'], $eigenschapIds)) {
                    $xxllncZaakArray['values'][$zaakEigenschap['eigenschap']['definitie']] = [$zaakEigenschap['waarde']];
                }
            }
        }

        return $xxllncZaakArray;

    }//end mapPostEigenschappen()


    /**
     * Maps the informatieobjecten from zgw to xxllnc.
     *
     * @param array $xxllncZaakArray This is the Xxllnc Zaak array.
     * @param array $zaakTypeArray   This is the ZGW Zaak array.
     *
     * @return array $xxllncZaakArray This is the Xxllnc Zaak array with the added eigenschappen.
     */
    private function mapPostInfoObjecten(array $xxllncZaakArray, array $zaakArrayObject): array
    {
        if (isset($zaakArrayObject['zaakinformatieobjecten']) === true) {
            foreach ($zaakArrayObject['zaakinformatieobjecten'] as $infoObject) {
                isset($infoObject['informatieobject']) === true && $xxllncZaakArray['files'][] = [
                    // 'reference' => $infoObject['_self']['id'],
                    'type'     => 'metadata',
                    'naam'     => $infoObject['titel'],
                    'metadata' => [
                        // 'reference' =>  null,
                        'type'     => 'metadata',
                        'instance' => [
                            'appearance'    => $infoObject['informatieobject']['bestandsnaam'],
                            'category'      => null,
                            'description'   => $infoObject['informatieobject']['beschrijving'],
                            'origin'        => 'Inkomend',
                            'origin_date'   => $infoObject['informatieobject']['creatiedatum'],
                            'pronom_format' => $infoObject['informatieobject']['formaat'],
                            'structure'     => 'text',
                            'trust_level'   => ($infoObject['integriteit']['waarde'] ?? 'Openbaar'),
                            'status'        => 'original',
                            'creation_date' => $infoObject['informatieobject']['creatiedatum'],
                        ],
                    ],
                ];
            }//end foreach
        }//end if

        return $xxllncZaakArray;

    }//end mapPostInfoObjecten()


    /**
     * Maps the rollen from zgw to xxllnc.
     *
     * @param array $xxllncZaakArray This is the Xxllnc Zaak array.
     * @param array $zaakTypeArray   This is the ZGW Zaak array.
     *
     * @return array $xxllncZaakArray This is the Xxllnc Zaak array with the added eigenschappen.
     */
    private function mapPostRollen(array $xxllncZaakArray, array $zaakArrayObject): array
    {
        if (isset($zaakArrayObject['rollen']) === true && isset($zaakArrayObject['zaaktype']['roltypen']) === true) {
            foreach ($zaakArrayObject['rollen'] as $rol) {
                foreach ($zaakArrayObject['zaaktype']['roltypen'] as $rolType) {
                    if ($rolType['omschrijvingGeneriek'] === $rol['roltoelichting']) {
                        $rolTypeObject = $this->entityManager->find('App:ObjectEntity', $rolType['_self']['id']);
                        if ($rolTypeObject instanceof ObjectEntity && $rolTypeObject->getExternalId() !== null) {
                            $xxllncZaakArray['subjects'][] = [
                                'subject'                => [
                                    'type'      => 'subject',
                                    'reference' => $rolTypeObject->getExternalId(),
                                ],
                                'role'                   => $rol['roltoelichting'],
                                'magic_string_prefix'    => $rol['roltoelichting'],
                                'pip_authorized'         => true,
                                'send_auth_notification' => false,
                            ];
                        }
                    }
                }
            }
        }//end if

        return $xxllncZaakArray;

    }//end mapPostRollen()


    /**
     * Saves case to xxllnc by POST or PUT request.
     *
     * @param array                $caseArray       Case object.
     * @param Synchronization|null $synchronization Earlier created synchronization object.
     *
     * @return bool True if succesfully saved to xxllnc
     *
     * @todo Make function smaller and more readable
     */
    public function sendCaseToXxllnc(array $caseArray, ?Synchronization $synchronization = null, string $type = 'zaak'): ?string
    {
        switch ($type) {
        case 'zaak':
            $resourceId = $caseArray['zgwZaak'];
            break;
        case 'besluit':
            $resourceId = $caseArray['zgwBesluit'];
            break;
        }

        // If we have a sync with a sourceId we can do a PUT.
        if ($synchronization !== null
            && $synchronization->getSourceId()
        ) {
            $endpoint        = "/case/{$synchronization->getSourceId()}/update";
            $logMessage      = "Updating case: {$synchronization->getSourceId()} to xxllnc";
            $unsetProperties = [
                '_self',
                'requestor',
                'casetype_id',
                'source',
                'open',
                'route',
                'contact_details',
                'confidentiality',
                'number',
                'subjects',
                'zgwZaak',
                'date_of_registration',
            ];
        }//end if

        // If we have dont have a sync or sourceId we can do a POST.
        if ($synchronization === null
            || ($synchronization !== null && $synchronization->getSourceId() === null)
        ) {
            $endpoint        = '/case/create';
            $logMessage      = 'Posting new case to xxllnc';
            $unsetProperties = [
                '_self',
                'requestor._self',
                'zgwZaak',
            ];
        }//end if

        // unset unwanted properties.
        foreach ($unsetProperties as $property) {
            unset($caseArray[$property]);
        }

        if (isset($caseArray['requestor']['_self']) === true) {
            unset($caseArray['requestor']['_self']);
        }

        // Method is always POST in the xxllnc api for creating and updating.
        $method = 'POST';

        $this->logger->info("$method a case to xxllnc (Zaak ID: $resourceId)", ['mapped case' => $caseArray]);
        $this->logger->info(\Safe\json_encode($caseArray));

        $xxllncAPI = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');
        // Send the POST/PUT request to xxllnc.
        try {
            isset($this->style) === true && $this->style->info($logMessage);
            $response = $this->callService->call($xxllncAPI, $endpoint, $method, ['body' => \Safe\json_encode($caseArray), 'headers' => ['Content-Type' => 'application/json']]);
            $result   = $this->callService->decodeResponse($xxllncAPI, $response);

            var_dump("LALALALALLA");
            // var_dump($result);
            $caseId = $result['result']['reference'] ?? null;
            var_dump("$method succesfull for case with externalId: $caseId");
            $this->logger->info("$method succesfull for case with externalId: $caseId");

            return $caseId;
        } catch (Exception $e) {
            $this->logger->error("Failed to $method case, message:  {$e->getMessage()}");
            var_dump("Failed to $method case, message:  {$e->getMessage()}");

            return null;
        }//end try

    }//end sendCaseToXxllnc()


    /**
     * Saves a case relation from a case (Besluit) to another case (Zaak) to xxllnc by POST.
     *
     * @param string $caseSourceId        Case id from xxllnc.
     * @param string $besluitCaseSourceId Case (Besluit) id from xxllnc.
     *
     * @return bool True if succesfully saved to xxllnc.
     */
    public function sendCaseRelationForBesluit(string $caseSourceId, string $besluitCaseSourceId)
    {
        $xxllncAPI = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');

        $logMessage = "Posting relation for case (besluit): $besluitCaseSourceId to normal case: $caseSourceId";
        $endpoint   = "/case/$caseSourceId/relation/add";
        $body       = ['related_id' => $besluitCaseSourceId];

        // Send the POST/PUT request to xxllnc.
        try {
            isset($this->style) === true && $this->style->info($logMessage);
            $response = $this->callService->call($xxllncAPI, $endpoint, 'POST', ['form_params' => $body]);
            $result   = $this->callService->decodeResponse($xxllncAPI, $response);

            var_dump("LALALALALLA");
            // var_dump($result);
            $caseId = $result['result']['reference'] ?? null;
            var_dump("succesfull for case relation with externalId: $caseId");
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to set relation for besluit case to normal case, message:  {$e->getMessage()}");

            var_dump("Failed to set relation for besluit case to normal case, message:  {$e->getMessage()}");
            return false;
        }//end try

        return true;

    }//end sendCaseRelationForBesluit()


    /**
     * Sets some default values for the case object.
     *
     * @param ObjectEntity $resourceObject       The resource object (zaak or besluit).
     * @param string       $resourceTypeSourceId The resource type source id (zaaktype or besluittype).
     * @param string       $bsn                  The bsn.
     * @param string       $type                 besluit or case
     *
     * @return array $caseArray
     */
    private function setCaseDefaultValues(ObjectEntity $resourceObject, string $resourceTypeSourceId, string $bsn, string $type = 'zaak'): array
    {
        $dateTimeNow                      = new DateTime('now');
        $dateTimeNow                      = ($dateTimeNow->format('Y-m-d').'T'.$dateTimeNow->format('H:i:s').'Z');
        $resourceMappingArray['resource'] = [
            'date_of_registration' => $dateTimeNow,
            'casetype_id'          => $resourceTypeSourceId,
            'bsn'                  => $bsn,
        // Get bsn from case
        ];

        switch ($type) {
        case 'zaak':
            $resourceMappingArray['resource']['zgwZaak'] = $resourceObject->getId()->toString();
            break;
        case 'besluit':
            $resourceMappingArray['resource']['zgwBesluit'] = $resourceObject->getId()->toString();
            break;
        }//end switch

        return $resourceMappingArray;

    }//end setCaseDefaultValues()


    /**
     * Searches for an already created case object for when this case has already been synced and we need to update it or creates a new one.
     *
     * @param array  $zaakArrayObject
     * @param string $type
     *
     * @return ObjectEntity|array $caseObject
     */
    private function getCaseObject(array $zaakArrayObject, string $type = 'case')
    {
        $xxllncZaakSchema = $this->resourceService->getSchema('https://development.zaaksysteem.nl/schema/xxllnc.zaakPost.schema.json', 'common-gateway/xxllnc-zgw-bundle');

        switch ($type) {
        case 'case':
            $name = 'zgwZaak';
            break;
        case 'besluit':
            $name = 'zgwBesluit';
            break;
        }

        // Get needed attribute so we can find the already existing case object
        $zgwZaakAttribute = $this->entityManager->getRepository('App:Attribute')->findOneBy(['entity' => $xxllncZaakSchema, 'name' => $name]);
        if ($zgwZaakAttribute === null) {
            return [];
        }

        // Find or create case object.
        $caseValue = $this->entityManager->getRepository('App:Value')->findOneBy(['stringValue' => $zaakArrayObject['_self']['id'], 'attribute' => $zgwZaakAttribute]);
        if ($caseValue instanceof Value) {
            $caseObject = $caseValue->getObjectEntity();
        } else {
            $caseObject = new ObjectEntity($xxllncZaakSchema);
        }

        return $caseObject;

    }//end getCaseObject()


    /**
     * Finds the requestor of a ZGW Zaak.
     *
     * @param array $zaakArrayObject ZGW Zaak.
     *
     * @return string|null $bsn if found.
     */
    private function getZaakBsn(array $zaakArrayObject): ?string
    {
        // Option 1
        if (isset($zaakArrayObject['rollen'][0]['betrokkeneIdentificatie']['inpBsn']) === true) {
            return $zaakArrayObject['rollen'][0]['betrokkeneIdentificatie']['inpBsn'];
        }

        // Option 2
        if (isset($zaakArrayObject['verantwoordelijkeOrganisatie']) === true) {
            return $zaakArrayObject['verantwoordelijkeOrganisatie'];
        }

        return null;

    }//end getZaakBsn()


    /**
     * Get bsn from case
     *
     * @param array $zaakArray ZGW Zaak object as array.
     *
     * @throws Exception
     *
     * @return string|null
     */
    public function getBsnFromCase(array $zaakArray): ?string
    {
        $bsn = $this->getZaakBsn($zaakArray);
        if ($bsn === null) {
            throw new \Exception('No bsn found in a rol->betrokkeneIdentificatie->inpBsn or verantwoordelijkeOrganisatie is not set');
        }

        return $bsn;

    }//end getBsnFromCase()


    /**
     * Maps zgw zaak to xxllnc case.
     *
     * @param string       $zaaktypeSourceId The caseType source id.
     * @param ObjectEntity $zaakTypeObject   ZGW ZaakType object.
     * @param ObjectEntity $zaakObject       The ZGW zaak Object.
     *
     * @throws Exception
     *
     * @return array $this->data Data which we entered the function with.
     *
     * @todo Make function smaller and more readable.
     */
    public function mapZGWToXxllnc(string $zaaktypeSourceId, ObjectEntity $zaakTypeObject, ObjectEntity $zaakObject): array
    {
        $xxllncZaakSchema  = $this->resourceService->getSchema('https://development.zaaksysteem.nl/schema/xxllnc.zaakPost.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $xxllncAPI         = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');
        $xxllncZaakMapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.ZgwBesluitToXxllncCase.mapping.json', 'common-gateway/xxllnc-zgw-bundle');

        $zaakArrayObject = $zaakObject->toArray();
        $bsn             = $this->getBsnFromCase($zaakArrayObject);
        // Base values
        $caseMappingArray = $this->setCaseDefaultValues($zaakObject, $zaaktypeSourceId, $bsn, 'zaak');
        $caseMappingArray = $this->mappingService->mapping($xxllncZaakMapping, $caseMappingArray);
        unset($caseMappingArray['zgwBesluit']);

        // Manually map subobjects
        $caseMappingArray = $this->mapPostEigenschappen($caseMappingArray, $zaakArrayObject, $zaakTypeObject);
        $caseMappingArray = $this->mapPostInfoObjecten($caseMappingArray, $zaakArrayObject);
        // $caseArray = $this->mapPostRollen($caseArray, $zaakArrayObject); // disabled for now.
        $caseObject = $this->getCaseObject($zaakArrayObject);

        $caseObject->hydrate($caseMappingArray);
        $this->entityManager->persist($caseObject);
        $this->entityManager->flush();

        $synchronization = null;
        // Only get synchronization that has a sourceId.
        if ($caseObject->getSynchronizations()->first() !== false
            && $caseObject->getSynchronizations()->first()->getSourceId() !== null
        ) {
            $synchronization = $caseObject->getSynchronizations()->first();
        }

        $sourceId = $this->sendCaseToXxllnc($caseObject->toArray(), $synchronization);
        if ($sourceId === null) {
            var_dump("POST to xxllnc failed.");
            return [];
        }

        var_dump("POST to xxllnc succesfull with external id: $sourceId");
        $synchronization = $this->synService->findSyncBySource($xxllncAPI, $xxllncZaakSchema, $sourceId);
        $synchronization = $this->synService->synchronize($synchronization, $caseObject->toArray());

        $zaakObject->addSynchronization($synchronization);
        $this->entityManager->persist($zaakObject);
        $this->entityManager->flush();

        return $zaakObject->toArray();

    }//end mapZGWToXxllnc()


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
        var_dump("halloooo");
        $xxllncZaakSchema  = $this->resourceService->getSchema('https://development.zaaksysteem.nl/schema/xxllnc.zaakPost.schema.json', 'common-gateway/xxllnc-zgw-bundle');
        $xxllncAPI         = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'common-gateway/xxllnc-zgw-bundle');
        $xxllncZaakMapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.ZgwBesluitToXxllncCase.mapping.json', 'common-gateway/xxllnc-zgw-bundle');

        $bsn                 = $this->getBsnFromCase($zaakObject->toArray());
        $besluitMappingArray = $this->setCaseDefaultValues($besluitObject, $besluittypeSourceId, $bsn, 'besluit');
        $besluitMappingArray = $this->mappingService->mapping($xxllncZaakMapping, $besluitMappingArray);
        unset($besluitMappingArray['zgwZaak']);

        $caseObject = $this->getCaseObject($besluitObject->toArray(), 'besluit');
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

        $sourceId = $this->sendCaseToXxllnc($caseArray, $synchronization);
        if ($sourceId === null) {
            return [];
        }

        $synchronization = $this->synService->findSyncBySource($xxllncAPI, $xxllncZaakSchema, $sourceId);
        $synchronization = $this->synService->synchronize($synchronization, $besluitObject->toArray());
        var_dump($synchronization->getId()->toString());

        $besluitObject->addSynchronization($synchronization);
        $this->entityManager->persist($besluitObject);
        $this->entityManager->flush();

        return $synchronization->getSourceId();

    }//end mapBesluitToXxllnc()


    /**
     * Gets zaaktype id on multple ways.
     *
     * @return string|bool $zaakTypeId or bool if not found.
     */
    private function getZaakTypeId()
    {
        if (isset($this->data['zaaktype']) === false) {
            return false;
        }

        if (isset($this->data['embedded']['zaaktype']['_self']['id']) === false
            && isset($this->data['zaaktype']) === false
        ) {
            return false;
        }

        if (is_array($this->data['zaaktype']) === true) {
            return $this->data['zaaktype']['_self']['id'];
        } else if (filter_var($this->data['zaaktype'], FILTER_VALIDATE_URL) !== false) {
            return substr($this->data['zaaktype'], (strrpos($this->data['zaaktype'], '/') + 1));
        } else {
            return $this->data['embedded']['zaaktype']['_self']['id'];
        }

    }//end getZaakTypeId()


    /**
     * Handles all code to make a zgw zaak to a xxllnc case.
     *
     * @param string|null $zaakTypeId The id of the zaaktype
     *
     * @return array empty
     * @throws Exception
     */
    private function syncZaakToXxllnc(?string $zaakTypeId): array
    {
        isset($this->style) === true && $this->style->success('function syncZaakToXxllnc triggered');

        $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
        // Only get synchronization that has a sourceId.
        if ($zaakTypeObject->getSynchronizations()->first() !== false
            && $zaakTypeObject->getSynchronizations()->first()->getSourceId() !== null
        ) {
            $zaaktypeSourceId = $zaakTypeObject->getSynchronizations()->first()->getSourceId();
        }

        // Return here cause if the zaaktype is created through this gateway, we cant sync it to xxllnc because it doesn't exist there
        if (isset($zaaktypeSourceId) === false) {
            return [];
        }

        if (isset($this->data['_self']['id']) === false) {
            return [];
        }

        $zaakObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id']);
        if ($zaakObject instanceof ObjectEntity === false) {
            return [];
        }

        return $this->mapZGWToXxllnc($zaaktypeSourceId, $zaakTypeObject, $zaakObject);

    }//end syncZaakToXxllnc()


    /**
     * Gets the case source id from the case and adds the case to the besluit.
     *
     * @param array        $pathParameters The path from the request.
     * @param ObjectEntity $besluitObject  The besluit object from the request.
     */
    private function getCaseObjectAndConnectWithBesluit(array $pathParameters, ObjectEntity $besluitObject): ?ObjectEntity
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

        $zaakObject = $this->entityManager->find('App:ObjectEntity', $zaakId);
        if ($zaakObject instanceof ObjectEntity === false) {
            $this->logger->error('syncBesluitToXxllnc returned, there is no zaak with the given id');

            return null;
        }

        $besluitObject->setValue('zaak', $zaakObject);
        $this->entityManager->persist($besluitObject);
        $this->entityManager->flush();

        return $zaakObject;

    }//end getCaseObjectAndConnectWithBesluit()


    /**
     * Gets the case source id from the case.
     *
     * @param ObjectEntity $zaakObject The besluit object from the request.
     */
    private function getCaseSourceId(ObjectEntity $zaakObject): ?string
    {
        if ($zaakObject->getSynchronizations()->first() !== false) {
            return $zaakObject->getSynchronizations()->first()->getSourceId();
        }

        return null;

    }//end getCaseSourceId()


    /**
     * Gets the case source id from the case.
     *
     * @param ObjectEntity $besluitTypeObject The besluittype object
     */
    private function getBesluittypeSourceId(ObjectEntity $besluitTypeObject): ?string
    {
        // If set we already synced the Zaak to xxllnc as a case.
        if ($besluitTypeObject->getSynchronizations()->first() !== false
            && $besluitTypeObject->getSynchronizations()->first()->getSourceId() !== null
        ) {
            return $besluitTypeObject->getSynchronizations()->first()->getSourceId();
        }

        $this->logger->error('syncBesluitToXxllnc returned, the current besluittype had no source id so it did not came from the xxllnc api.');

        return null;

    }//end getBesluittypeSourceId()


    /**
     * Maps the Besluit to a xxllnc case, post it, and creates a realtion to the actual case.
     * We create a sub case for the Besluit because the xxllnc api does not have a Besluit variant.
     */
    private function syncBesluitToXxllnc()
    {
        $besluitObject = $this->entityManager->find('App:ObjectEntity', $this->data['response']['_self']['id']);
        // Get caseObject and add the case to the besluit.
        $caseObject = $this->getCaseObjectAndConnectWithBesluit($this->data['parameters']['path'], $besluitObject);
        // Get the case soure id.
        $caseSourceId = $this->getCaseSourceId($caseObject);

        // If the Zaak hasn't been send to xxllnc yet, do it now.
        if (isset($caseSourceId) === false) {
            // @TODO Lets expect the case has been synced already for now...
            // @TODO Make it possible to sync zaak from here LOW PRIO
            // $this->syncZaakToXxllnc();
        }

        $besluitTypeObject = $this->entityManager->find('App:ObjectEntity', $this->data['response']['besluit']);
        if ($besluitTypeObject instanceof ObjectEntity === false) {
            $this->logger->error('syncBesluitToXxllnc returned, no besluitType set on besluit');

            return [];
        }

        // Get the besluittype source id.
        $besluittypeSourceId = $this->getBesluittypeSourceId($besluitTypeObject);
        $besluitCaseSourceId = $this->mapBesluitToXxllnc($besluittypeSourceId, $besluitObject, $caseObject);

        // Link normal case and besluit case at xxllnc api.
        if ($this->sendCaseRelationForBesluit($caseSourceId, $besluitCaseSourceId) === false) {
            $this->logger->error("setting case relation for case $caseSourceId and case (besluit) $besluitCaseSourceId failed.");
            var_dump("setting case relation for case $caseSourceId and case (besluit) $besluitCaseSourceId failed.");

            return [];
        }

        return ['response' => $besluitObject->toArray()];

    }//end syncBesluitToXxllnc()


    /**
     * Updates xxllnc case synchronization when zaak sub objects are created through their own endpoints.
     *
     * @param array|null $data
     * @param array|null $configuration
     *
     * @throws Exception
     *
     * @return array
     *
     * @todo Make function smaller
     */
    public function updateZaakHandler(?array $data = [], ?array $configuration = []): array
    {
        $this->configuration = $configuration;

        if (is_array($data['response']['zaak'])) {
            $zaakId = $data['response']['zaak']['_self']['id'];
        } else {
            $zaakId = substr($data['response']['zaak'], (strrpos($data['response']['zaak'], '/') + 1));
        }

        if ($zaakId === false || $zaakId === null) {
            return ['response' => []];
        }

        $zaakObject = $this->entityManager->find('App:ObjectEntity', $zaakId);
        if ($zaakObject === null) {
            return ['response' => []];
        }

        $this->data = $zaakObject->toArray();

        $zaakTypeId = $this->getZaakTypeId();
        if ($zaakTypeId === false) {
            return [];
        }

        return ['response' => $this->syncZaakToXxllnc($zaakTypeId)];

    }//end updateZaakHandler()


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
        var_dump("halloooo");

        if (isset($this->data['response']['_self']['id']) === false) {
            $this->logger->error('syncBesluitToXxllnc returned, no besluittype set');

            return [];
        }

        if (isset($this->data['response']['besluit']) === false) {
            $this->logger->error('syncBesluitToXxllnc returned, no besluittype set');

            return [];
        }

        return ['response' => $this->syncBesluitToXxllnc()];

    }//end besluitToXxllncHandler()


    /**
     * Creates or updates a ZGW Zaak that is created through the normal /zaken endpoint.
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
    public function zgwToXxllncHandler(?array $data = [], ?array $configuration = []): array
    {
        $this->data          = $data['response'];
        $this->configuration = $configuration;

        $zaakTypeId = $this->getZaakTypeId();
        if ($zaakTypeId === false) {
            return [];
        }

        return ['response' => $this->syncZaakToXxllnc($zaakTypeId)];

    }//end zgwToXxllncHandler()


}//end class
