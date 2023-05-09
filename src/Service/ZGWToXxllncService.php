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
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\ObjectRepository;
use Exception;
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
     * @var DocumentService
     */
    private DocumentService $documentService;

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
    public array $data;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $schemaRepo;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $sourceRepo;

    /**
     * @var Source|null
     */
    private ?Source $xxllncAPI;

    /**
     * @var Schema|null
     */
    private ?Schema $xxllncZaakSchema;


    /**
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        DocumentService $documentService
    ) {
        $this->entityManager     = $entityManager;
        $this->callService       = $callService;
        $this->documentService   = $documentService;

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
                if (isset($zaakEigenschap['eigenschap']) === true && in_array($zaakEigenschap['eigenschap']['_self']['id'], $eigenschapIds) === true) {
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
                'attribute'       => $attribute,
                'stringValue'    => $zaakObject,
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
        $zaakObject  = $this->entityManager->find('App:ObjectEntity', $zaakArrayObject['_self']['id']);
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
                            'creation_date' => $fileObject->getValue('registratiedatum')
                        ]
                    ]
                ];
            }
        }//end foreach

        return $xxllncZaakArray;

    }//end mapPostFileObjects()


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
    public function sendCaseToXxllnc(array $caseArray, ?Synchronization $synchronization = null)
    {
        // If we have a sync with a sourceId we can do a put else post.
        if ($synchronization && $synchronization->getSourceId()) {
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
            ];
        }//end if

        // If we have dont have a sync or sourceId we can do a post.
        if ($synchronization === null || ($synchronization && !$synchronization->getSourceId())) {
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
        }//end foreach

        if (isset($caseArray['requestor']['_self']) === true) {
            unset($caseArray['requestor']['_self']);
        }//end if

        // Method is always POST in the xxllnc api for creating and updating.
        $method = 'POST';

        var_dump(json_encode($caseArray));die;

        // Send the POST/PUT request to xxllnc.
        try {
            isset($this->style) === true && $this->style->info($logMessage);
            $response = $this->callService->call($this->xxllncAPI, $endpoint, $method, ['form_params' => $caseArray]);
            $result   = $this->callService->decodeResponse($this->xxllncAPI, $response);
            $caseId   = $result['result']['reference'] ?? null;
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to $method case, message:  {$e->getMessage()}");

            return false;
        }//end try

        return $caseId ?? false;

    }//end sendCaseToXxllnc()


    /**
     * Sets some default values for the case object.
     *
     * @param array  $zaakArrayObject
     * @param string $caseTypeId
     *
     * @return array $caseArray
     */
    private function setCaseDefaultValues(array $zaakArrayObject, string $caseTypeId)
    {
        return [
            'zgwZaak'         => $zaakArrayObject['_self']['id'],
            'casetype_id'     => $caseTypeId,
            'source'          => 'behandelaar',
            'confidentiality' => 'public',
            'requestor'       => [
                'id'   => '999991723',
                'type' => 'person',
            ],
        ];

    }//end setCaseDefaultValues()


    /**
     * Searches for an already created case object for when this case has already been synced and we need to update it or creates a new one.
     *
     * @param array $zaakArrayObject
     *
     * @return ObjectEntity|array $caseObject
     */
    private function getCaseObject(array $zaakArrayObject)
    {
        // Get needed attribute so we can find the already existing case object
        $zgwZaakAttribute = $this->entityManager->getRepository('App:Attribute')->findOneBy(['entity' => $this->xxllncZaakSchema, 'name' => 'zgwZaak']);
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
     * Creates or updates synchronization object.
     *
     * @param Synchronization $synchronization
     * @param string          $sourceId        ID of the case just created at xxllnc api.
     * @param ObjectEntity    $caseObject
     *
     * @return void
     */
    private function saveSynchronization(?Synchronization $synchronization = null, string $sourceId, ObjectEntity $caseObject): void
    {
        if (isset($synchronization) === false || (isset($synchronization) === true && $synchronization->getSourceId() === null)) {
            $synchronization = new Synchronization();
            $synchronization->setEntity($this->xxllncZaakSchema);
        }

        $synchronization->setSourceId($sourceId);
        $synchronization->setSource($this->xxllncAPI);
        $synchronization->setObject($caseObject);

        $this->entityManager->persist($synchronization);
        $this->entityManager->flush();

    }//end saveSynchronization()


    /**
     * Maps zgw zaak to xxllnc case.
     *
     * @param  string       $casetypeId      The caseType id.
     * @param  ObjectEntity $zaakTypeObject  ZGW ZaakType object.
     * @param  array        $zaakArrayObject The data array of a zaak Object.
     * @return array $this->data Data which we entered the function with.
     *
     * @throws Exception
     * @todo   Make function smaller and more readable.
     */
    public function mapZGWToXxllnc(string $casetypeId, ObjectEntity $zaakTypeObject, array $zaakArrayObject): array
    {
        if (isset($zaakArrayObject['verantwoordelijkeOrganisatie']) === false) {
            throw new \Exception('verantwoordelijkeOrganisatie is not set');
        }

        // Base values
        $caseArray = $this->setCaseDefaultValues($zaakArrayObject, $casetypeId);

        // Manually map subobjects
        $caseArray = $this->mapPostEigenschappen($caseArray, $zaakArrayObject, $zaakTypeObject);
        $caseArray = $this->mapPostInfoObjecten($caseArray, $zaakArrayObject);
        var_dump($zaakArrayObject['_self']['id']);
        $caseArray = $this->mapPostFileObjects($caseArray, $zaakArrayObject);

        // $caseArray = $this->mapPostRollen($caseArray, $zaakArrayObject); // disabled for now.
        $caseObject = $this->getCaseObject($zaakArrayObject);

        $caseObject->hydrate($caseArray);
        $this->entityManager->persist($caseObject);
        $caseArray = $caseObject->toArray();

        $synchronization = null;
        // Only get synchronization that has a sourceId.
        if ($caseObject->getSynchronizations() && isset($caseObject->getSynchronizations()[0]) === true && $caseObject->getSynchronizations()[0]->getSourceId()) {
            $synchronization = $caseObject->getSynchronizations()[0];
        }

        $sourceId = $this->sendCaseToXxllnc($caseArray, $synchronization);
        if (!$sourceId) {
            return [];
        }

        $this->saveSynchronization($synchronization, $sourceId, $caseObject);

        return $caseArray;

    }//end mapZGWToXxllnc()


    /**
     * Makes sure this action has all the gateway objects it needs.
     *
     * @return bool false if some object couldn't be fetched
     */
    private function hasRequiredGatewayObjects(): bool
    {
        // Get XxllncZaak schema.
        if (isset($this->xxllncZaakSchema) === false && ($this->xxllncZaakSchema = $this->schemaRepo->findOneBy(['reference' => 'https://development.zaaksysteem.nl/schema/xxllnc.zaakPost.schema.json'])) === null) {
            isset($this->style) && $this->style->error('Could not find Schema: https://development.zaaksysteem.nl/schema/xxllnc.zaakPost.schema.json');

            return false;
        }

        // Get xxllnc source.
        if (isset($this->xxllncAPI) === false && ($this->xxllncAPI = $this->sourceRepo->findOneBy(['reference' => 'https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json'])) === null) {
            isset($this->style) && $this->style->error('Could not find Source: https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json');

            return false;
        }

        return true;

    }//end hasRequiredGatewayObjects()


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
     * @return array empty
     */
    public function syncZaakToXxllnc(): array
    {
        isset($this->style) === true && $this->style->success('function syncZaakToXxllnc triggered');

        $this->hasRequiredGatewayObjects();

        $zaakTypeId = $this->getZaakTypeId();
        if ($zaakTypeId === false) {
            return [];
        }

        $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
        $casetypeId     = $zaakTypeObject->getSynchronizations()[0]->getSourceId() ?? null;
        // Return here cause if the zaaktype is created through this gateway, we cant sync it to xxllnc because it doesn't exist there
        if (isset($casetypeId) === false) {
            return [];
        }

        if (isset($this->data['_self']['id']) === false) {
            return [];
        }

        $zaakArrayObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id']);

        if (isset($zaakArrayObject) === false) {
            return [];
        }

        $zaakArrayObject = $zaakArrayObject->toArray();

        $xxllncZaakArrayObject = $this->mapZGWToXxllnc($casetypeId, $zaakTypeObject, $zaakArrayObject);

        return [];

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

        $zaakInformatieObject   = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id']);
        $zaakObject             = $zaakInformatieObject->getValue('zaak');

        $this->data = $zaakObject->toArray();

        return ['response' => $this->syncZaakToXxllnc()];

    }//end fileToXxllncHandler()


    /**
     * Updates xxllnc case synchronization when zaak sub objects are created through their own endpoints.
     *
     * @param array|null $data
     * @param array|null $configuration
     *
     * @return array
     *
     * @throws Exception
     * @todo   Make function smaller
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

        return ['response' => $this->syncZaakToXxllnc()];

    }//end updateZaakHandler()


    /**
     * Creates or updates a ZGW Zaak that is created through the normal /zaken endpoint.
     *
     * @param ?array $data          Data from the handler where the xxllnc casetype is in.
     * @param ?array $configuration Configuration from the Action where the Zaak entity id is stored in.
     *
     * @return array $this->data Data which we entered the function with.
     *
     * @throws Exception
     * @todo   Make function smaller and more readable.
     */
    public function zgwToXxllncHandler(?array $data = [], ?array $configuration = []): array
    {
        $this->data          = $data['response'];
        $this->configuration = $configuration;

        return ['response' => $this->syncZaakToXxllnc()];

    }//end zgwToXxllncHandler()


}//end class
