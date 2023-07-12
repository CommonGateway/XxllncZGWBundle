<?php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Entity\Value;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\SynchronizationService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Safe\json_encode;

/**
 * The ZGWToXxllncService handles the sending of ZGW objects to the xxllnc v1 api.
 *
 * By mapping, posting and creating a synchronization. Only works if the ztc zaaktype also exists in the xxllnc api.
 *
 * @author Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class ZGWToXxllncService
{

    /**
     * @var EntityManagerInterface $entityManager.
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SynchronizationService $syncService.
     */
    private SynchronizationService $syncService;

    /**
     * @var DocumentService
     */
    private DocumentService $documentService;

    /**
     * @var MappingService $mappingService.
     */
    private MappingService $mappingService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var SymfonyStyle $style.
     */
    public array $data;

    /**
     * @var array $configuration.
     */
    private array $configuration;

    /**
     * @var array $data.
     */
    public array $data;

    /**
     * @var Source|null $xxllncAPI.
     */
    public ?Source $xxllncAPI;

    /**
     * @var Schema|null $xxllncZaakSchema.
     */
    public ?Schema $xxllncZaakSchema;

    /**
     * @var LoggerInterface $logger.
     */
    private LoggerInterface $logger;


    /**
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        SynchronizationService $syncService,
        DocumentService $documentService,
        GatewayResourceService $resourceService
    ) {
        $this->entityManager   = $entityManager;
        $this->mappingService  = $mappingService;
        $this->logger          = $pluginLogger;
        $this->syncService     = $syncService;
        $this->resourceService = $resourceService;
        $this->documentService = $documentService;

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
     * Gets the eigenschappen from a zaaktype and creates a simpler array.
     *
     * @param ObjectEntity $zaakTypeObject These is the ZGW ZaakType.
     *
     * @return array $zaakTypeEigenschappen This is the Xxllnc Zaak array with the added eigenschappen.
     */
    private function getEigenschappen(ObjectEntity $zaakTypeObject): array
    {
        $zaakTypeEigenschappen = $zaakTypeObject->getValue('eigenschappen');
        if ($zaakTypeEigenschappen instanceof PersistentCollection) {
            $zaakTypeEigenschappen = $zaakTypeEigenschappen->toArray();
        }

        $eigenschappen = [];
        foreach ($zaakTypeEigenschappen as $eigenschap) {
            $eigenschappen[$eigenschap->getId()->toString()] = $eigenschap->getValue('naam');
        }

        return $eigenschappen;

    }//end getEigenschappen()


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
        // Create a array for the eigenschappen so its easier to check if zaakeigenschapen are valid for the zaaktype.
        $eigenschapIds = $this->getEigenschappen($zaakTypeObject);

        // eigenschappen to values
        if (isset($zaakArrayObject['eigenschappen']) === true) {
            foreach ($zaakArrayObject['eigenschappen'] as $zaakEigenschap) {
                if (isset($zaakEigenschap['eigenschap']['naam']) === true && isset($eigenschapIds[$zaakEigenschap['eigenschap']['_self']['id']]) === true
                ) {
                    // refetch eigenschap otherwise it doesnt load the specificate sub object.
                    $eigenschap = $this->entityManager->find('App:ObjectEntity', $zaakEigenschap['eigenschap']['_self']['id'])->toArray();

                    // If formaat is checkbox set the waarde in a array that is in a array :/.
                    if (isset($eigenschap['specificatie']['formaat']) === true && $eigenschap['specificatie']['formaat'] === 'checkbox') {
                        $xxllncZaakArray['values'][$eigenschap['naam']] = [[$zaakEigenschap['waarde']]];

                        continue;
                    }

                    // Else set the waarde in a array.
                    $xxllncZaakArray['values'][$eigenschap['naam']] = [$zaakEigenschap['waarde']];
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


    private function getFileObjects(ObjectEntity $zaakObject)
    {
        // Get attribute for the value we need to fetch.
        $attribute = $this->entityManager->getRepository('App:Attribute')->findOneBy(
            [
                'name'   => 'zaak',
                'entity' => $this->schemaRepo->findOneBy(['reference' => 'https://vng.opencatalogi.nl/schemas/zrc.zaakInformatieObject.schema.json']),
            ]
        );

        // Get values so we can get the zaakinformatieobjecten from this zaak.
        $values = $this->entityManager->getRepository('App:Value')->findBy(
            [
                'attribute'   => $attribute,
                'stringValue' => $zaakObject,
            ]
        );

        // Loop through valeus and get object of each value.
        $fileObjects = [];

        var_dump(count($values));
        $this->documentService->xxllncAPI = $this->xxllncAPI;
        foreach ($values as $value) {
            // Make sure this zaakInformatieObject has a xxllnc documentNumber so we can send it to xxllnc.
            $this->documentService->checkDocumentNumber($value->getObjectEntity());

            // Get object again because new Synchronization might be added that we need.
            $fileObjects[] = $this->entityManager->find('App:ObjectEntity', $value->getObjectEntity()->getId()->toString());
            return $fileObjects;
        }

        return $fileObjects;

    }//end getFileObjects()


    /**
     * Maps a file from zgw to xxllnc.
     *
     * @param array $xxllncZaakArray This is the Xxllnc Zaak array.
     *
     * @return array $xxllncZaakArray This is the Xxllnc Zaak array with the added eigenschappen.
     */
    private function mapPostFileObjects(array $xxllncZaakArray, array $zaakArrayObject): array
    {
        $zaakObject = $this->entityManager->find('App:ObjectEntity', $zaakArrayObject['_self']['id']);
        var_dump('getfielobjects');
        $fileObjects = $this->getFileObjects($zaakObject);

        if (empty($fileObjects) === false) {
            $xxllncZaakArray['files'] = [];
        }

        foreach ($fileObjects as $fileObject) {
            var_dump(get_class($fileObject));
            if ($fileObject->getSynchronizations()->first() && $fileObject->getSynchronizations()->first()->getSourceId()) {
                $xxllncZaakArray['files'][] = [
                    'reference' => $fileObject->getSynchronizations()->first()->getSourceId(),
                    'name'      => $fileObject->getValue('titel'),
                    'metadata'  => [
                        'reference' => null,
                        'type'      => 'metadata',
                        'instance'  => [
                            'appearance'    => $fileObject->getValue('beschrijving'),
                            'category'      => 'Zaak document',
                            'description'   => $fileObject->getValue('beschrijving'),
                            'origin'        => 'Inkomend',
                            'origin_date'   => $fileObject->getValue('registratiedatum'),
                            'pronom_format' => $fileObject->getValue('informatieobject')->getValue('formaat'),
                            'structure'     => 'text',
                            'trust_level'   => $fileObject->getValue('informatieobject')->getValue('integriteit')->getValue('waarde'),
                            'status'        => $fileObject->getValue('status'),
                            'creation_date' => $fileObject->getValue('registratiedatum'),
                        ],
                    ],
                ];
            }//end if
        }//end foreach

        return $xxllncZaakArray;

    }//end mapPostFileObjects()


    /**
     * Saves case to xxllnc by POST or PUT request.
     *
     * @param array                $caseArray       Case object as array.
     * @param ObjectEntity         $caseObject      Case object as ObjectEntity.
     * @param Synchronization|null $synchronization Earlier created synchronization object.
     * @param string|null          $type            zaak or besluit.
     *
     * @return bool True if succesfully saved to xxllnc
     *
     * @todo Make function smaller and more readable
     */
    public function sendCaseToXxllnc(array $caseArray, ObjectEntity $caseObject, ?Synchronization $synchronization = null, ?string $type = 'zaak')
    {
        switch ($type) {
        case 'zaak':
            $resourceId = $caseArray['zgwZaak'];
            break;
        case 'besluit':
            $resourceId = $caseArray['zgwBesluit'];
            break;
        }

        $objectId = $resourceId;

        // If we have a sync with a sourceId we can do a PUT.
        if ($synchronization && $synchronization->getSourceId()) {
            $endpoint     = "/case/{$synchronization->getSourceId()}/update";
            $unsetMapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseUnsetPUT.mapping.json', 'xxllnc-zgw-bundle');
        }

        // If we have dont have a sync or sourceId we can do a POST.
        if ($synchronization === null
            || ($synchronization !== null && $synchronization->getSourceId() === null)
        ) {
            $synchronization = new Synchronization($this->xxllncAPI, $this->xxllncZaakSchema);
            $endpoint        = '/case/create';
            $unsetMapping    = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncCaseUnsetPOST.mapping.json', 'xxllnc-zgw-bundle');
        }

        if ($unsetMapping instanceof Mapping == false) {
            return false;
        }

        // Unset unwanted properties.
        $caseArray = $this->mappingService->mapping($unsetMapping, $caseArray);
        var_dump(json_encode($caseArray));
        die;
        $method = 'POST';
        $this->logger->info("$method a case to xxllnc ($type ID: $objectId) ".json_encode($caseArray));

        // New
        // Method is always POST in the xxllnc api for creating and updating (not needed to pass here).
        $responseBody = $this->syncService->synchronizeTemp($synchronization, $caseArray, $caseObject, $this->xxllncZaakSchema, $endpoint, 'result.reference');
        $this->entityManager->persist($synchronization);
        $this->entityManager->flush();
        $caseId = $responseBody['result']['reference'] ?? null;

        // Old @todo remove when new works.
        // // Send the POST/PUT request to xxllnc.
        // try {
        // isset($this->style) === true && $this->style->info($logMessage);
        // $response = $this->callService->call($this->xxllncAPI, $endpoint, $method, ['body' => json_encode($caseArray), 'headers' => ['Content-Type' => 'application/json']]);
        // $result   = $this->callService->decodeResponse($this->xxllncAPI, $response);
        // $caseId   = $result['result']['reference'] ?? null;
        // $this->logger->info("$method succesfull for case with externalId: $caseId and response: ".json_encode($result));
        // } catch (Exception $e) {
        // $this->logger->error("Failed to $method case, message:  {$e->getMessage()}");
        // return false;
        // }//end try
        return $caseId ?? false;

    }//end sendCaseToXxllnc()


    /**
     * Searches for an already created case object for when this case has already been synced and we need to update it or creates a new one.
     *
     * @param array  $zaakArrayObject
     * @param string $type
     *
     * @return ObjectEntity|array $caseObject
     */
    public function getCaseObject(array $zaakArrayObject, string $type = 'case')
    {
        switch ($type) {
        case 'case':
            $name = 'zgwZaak';
            break;
        case 'besluit':
            $name = 'zgwBesluit';
            break;
        }

        // Get needed attribute so we can find the already existing case object
        $zgwZaakAttribute = $this->entityManager->getRepository('App:Attribute')->findOneBy(['entity' => $this->xxllncZaakSchema, 'name' => $name]);
        if ($zgwZaakAttribute === null) {
            return [];
        }

        // Find or create case object.
        $caseValue = $this->entityManager->getRepository('App:Value')->findOneBy(['stringValue' => $zaakArrayObject['_self']['id'], 'attribute' => $zgwZaakAttribute]);
        if ($caseValue instanceof Value) {
            $caseObject = $caseValue->getObjectEntity();
        } else {
            $caseObject = new ObjectEntity($this->xxllncZaakSchema);
        }

        return $caseObject;

    }//end getCaseObject()


    /**
     * Maps zgw zaak to xxllnc case.
     *
     * @param  string       $casetypeId      The caseType id.
     * @param  ObjectEntity $zaakTypeObject  ZGW ZaakType object.
     * @param  array        $zaakArrayObject The data array of a zaak Object.
     * @return array        $this->data Data which we entered the function with.
     *
     * @throws Exception
     *
     * @return string|null
     */
    public function mapZGWToXxllnc(string $casetypeId, ObjectEntity $zaakTypeObject, array $zaakArrayObject): array
    {
        if (isset($zaakArrayObject['verantwoordelijkeOrganisatie']) === false) {
            $this->logger->error('verantwoordelijkeOrganisatie is not set');

            return [];
        }

        $bsn = ($zaakArrayObject['rollen'][0]['betrokkeneIdentificatie']['inpBsn'] ?? $zaakArrayObject['verantwoordelijkeOrganisatie'] ?? ) null;
        if ($bsn === null) {
            $this->logger->error('No bsn found in a rol->betrokkeneIdentificatie->inpBsn or verantwoordelijke organisatie.');

            return [];
        }

        $mapping = $this->resourceService->getMapping('https://development.zaaksysteem.nl/mapping/xxllnc.XxllncZaakToCase.mapping.json', 'xxllnc-zgw-bundle');
        if ($mapping instanceof Mapping === false) {
            return [];
        }

        // Base values.
        // @todo remove if mapping works.
        // $caseArray = $this->setCaseDefaultValues($zaakArrayObject, $casetypeId, $bsn);
        // Map ZGW Zaak to xxllnc case.
        $zaakArrayObject = array_merge($zaakArrayObject, ['bsn' => $bsn, 'caseTypeId' => $casetypeId]);
        $caseArray       = $this->mappingService->mapping($mapping, $zaakArrayObject);

        // @todo Remove if mapping works.
        // $caseArray = $this->mapPostInfoObjecten($caseArray, $zaakArrayObject);
        // $caseArray = $this->mapPostEigenschappen($caseArray, $zaakArrayObject, $zaakTypeObject);
        // @todo Might be needed in the future.
        // $caseArray = $this->mapPostRollen($caseArray, $zaakArrayObject); // disabled for now.
        $caseObject = $this->getCaseObject($zaakArrayObject);
        $caseObject->hydrate($caseArray);
        $this->entityManager->persist($caseObject);

        // @todo Remove if confirmed its not needed.
        // $caseArray = $caseObject->toArray();
        $synchronization = null;
        // Only get synchronization that has a sourceId.
        if ($caseObject->getSynchronizations() && isset($caseObject->getSynchronizations()[0]) === true && $caseObject->getSynchronizations()[0]->getSourceId()) {
            $synchronization = $caseObject->getSynchronizations()[0];
        }

        // Unset empty keys.
        $caseArray = array_filter($caseArray);

        $sourceId = $this->sendCaseToXxllnc($caseArray, $caseObject, $synchronization);
        if ($sourceId === false) {
            var_dump("POST to xxllnc failed.");
            return [];
        }

        // Not needed anymore.
        // $this->saveSynchronization($synchronization, $sourceId, $caseObject);
        return $caseArray;

    }//end mapZGWToXxllnc()


    /**
     * Gets zaaktype id on multple ways.
     *
     * @return string|bool $zaakTypeId or bool if not found.
     */
    private function getZaakTypeId()
    {
        if (isset($this->data['zaaktype']) === true && Uuid::isValid($this->data['zaaktype']) === true) {
            return $this->data['zaaktype'];
        }

        if (isset($this->data['zaaktype']['_self']['id']) === true) {
            return $this->data['zaaktype']['_self']['id'];
        }

        if (isset($this->data['embedded']['zaaktype']['_self']['id']) === true) {
            return $this->data['embedded']['zaaktype']['_self']['id'];
        }

        if (filter_var($this->data['zaaktype'], FILTER_VALIDATE_URL) !== false) {
            $id = substr($this->data['zaaktype'], (strrpos($this->data['zaaktype'], '/') + 1));
            if (Uuid::isValid($id) === true) {
                return $id;
            }
        }

        return false;

    }//end getZaakTypeId()


    /**
     * Handles all code to send a zgw zaak as a xxllnc case to the xxllnc v1 api.
     *
     * @param string|null $zaakTypeId The id of the zaaktype
     *
     * @return array empty
     * @throws Exception
     */
    public function syncZaakToXxllnc(): array
    {
        isset($this->style) === true && $this->style->success('function syncZaakToXxllnc triggered');

        $this->xxllncZaakSchema = $this->resourceService->getSchema('https://development.zaaksysteem.nl/schema/xxllnc.zaakPost.schema.json', 'xxllnc-zgw-bundle');
        $this->xxllncAPI        = $this->resourceService->getSource('https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json', 'xxllnc-zgw-bundle');

        $zaakTypeId = $this->getZaakTypeId();
        if ($zaakTypeId === false) {
            var_dump('test1');
            return [];
        }

        $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
        $casetypeId     = $zaakTypeObject->getSynchronizations()[0]->getSourceId() ?? null;

        if (isset($this->data['_self']['id']) === false) {
            var_dump('test2');
            return [];
        }

        $zaakObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id']);
        if ($zaakObject instanceof ObjectEntity === false) {
            return [];
        }

        return $this->mapZGWToXxllnc($casetypeId, $zaakTypeObject, $zaakObject->toArray());

    }//end syncZaakToXxllnc()


    /**
     * Triggers case update to xxllnc.
     *
     * @param ?array $data          Data from the handler where the xxllnc casetype is in.
     * @param ?array $configuration Configuration from the Action where the Zaak entity id is stored in.
     *
     * @return array $this->data Data which we entered the function with.
     */
    public function fileToXxllncHandler(?array $data = [], ?array $configuration = []): array
    {
        $this->data          = $data['response'];
        $this->configuration = $configuration;

        $zaakInformatieObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id']);
        $zaakObject           = $zaakInformatieObject->getValue('zaak');

        $this->data = $zaakObject->toArray();

        return ['response' => $this->syncZaakToXxllnc()];

    }//end fileToXxllncHandler()


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
