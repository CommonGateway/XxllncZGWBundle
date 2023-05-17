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
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\ObjectRepository;
use Exception;
use Psr\Log\LoggerInterface;
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
     * @var Source|null
     */
    private ?Source $xxllncAPI;

    /**
     * @var Schema|null
     */
    private ?Schema $xxllncZaakSchema;

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
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->callService   = $callService;
        $this->logger        = $pluginLogger;

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
    public function sendCaseToXxllnc(array $caseArray, ?Synchronization $synchronization = null)
    {
        $zaakOrBesluitId = $caseArray['zgwZaak'] ?? $caseArray['zgwBesluit'];
        // If we have a sync with a sourceId we can do a PUT.
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

        $this->logger->info("$method a case to xxllnc (Zaak ID: $zaakOrBesluitId)", ['mapped case' => $caseArray]);
        $this->logger->info(\Safe\json_encode($caseArray));

        // Send the POST/PUT request to xxllnc.
        try {
            isset($this->style) === true && $this->style->info($logMessage);
            $response = $this->callService->call($this->xxllncAPI, $endpoint, $method, ['body' => \Safe\json_encode($caseArray), 'headers' => ['Content-Type' => 'application/json']]);
            $result   = $this->callService->decodeResponse($this->xxllncAPI, $response);
            $caseId   = $result['result']['reference'] ?? null;
            $this->logger->info("$method succesfull for case with externalId: $caseId");
        } catch (Exception $e) {
            $this->logger->error("Failed to $method case, message:  {$e->getMessage()}");

            return false;
        }//end try

        return $caseId ?? false;

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
        $logMessage = "Posting relation for case (besluit): $besluitCaseSourceId to normal case: $caseSourceId";
        $endpoint   = "/case/$caseSourceId/relation/add";
        $body       = ['related_id' => $besluitCaseSourceId];

        // Send the POST/PUT request to xxllnc.
        try {
            isset($this->style) === true && $this->style->info($logMessage);
            $response = $this->callService->call($this->xxllncAPI, $endpoint, 'POST', ['form_params' => $body]);
            $result   = $this->callService->decodeResponse($this->xxllncAPI, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to set relation for besluit case to normal case, message:  {$e->getMessage()}");

            return false;
        }//end try

        return true;

    }//end sendCaseRelationForBesluit()


    /**
     * Sets some default values for the case object.
     *
     * @param array  $zaakArrayObject
     * @param string $caseTypeId
     * @param string $bsn
     * @param string $type besluit or case
     *
     * @return array $caseArray
     */
    private function setCaseDefaultValues(array $zaakArrayObject, string $caseTypeId, string $bsn, string $type = 'case')
    {
        $dateTimeNow = new DateTime('now');
        $dateTimeNow = ($dateTimeNow->format('Y-m-d').'T'.$dateTimeNow->format('H:i:s').'Z');

        // @TODO could be a mapping object
        return [
            'zgwZaak'              => $zaakArrayObject['_self']['id'],
            'casetype_id'          => $caseTypeId,
            'source'               => 'behandelaar',
            'date_of_registration' => $dateTimeNow,
            'confidentiality'      => 'public',
            'requestor'            => [
                'id'   => $bsn,
                'type' => 'person',
            ],
        ];

        switch ($type) {
            case 'zaak':
                $array['zgwZaak'] = $zaakArrayObject['_self']['id'];
                break;
            case 'besluit':
                $array['zgwBesluit'] = $zaakArrayObject['_self']['id'];
                break;
        }//end switch

        return $array;

    }//end setCaseDefaultValues()


    /**
     * Searches for an already created case object for when this case has already been synced and we need to update it or creates a new one.
     *
     * @param array $zaakArrayObject
     *
     * @return ObjectEntity|array $caseObject
     */
    private function getCaseObject(array $zaakArrayObject, string $type = 'case')
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
     * Maps zgw zaak to xxllnc case.
     *
     * @param string       $casetypeId      The caseType id.
     * @param ObjectEntity $zaakTypeObject  ZGW ZaakType object.
     * @param array        $zaakArrayObject The data array of a zaak Object.
     *
     * @throws Exception
     *
     * @return array $this->data Data which we entered the function with.
     *
     * @todo Make function smaller and more readable.
     */
    public function mapZGWToXxllnc(string $casetypeId, ObjectEntity $zaakTypeObject, array $zaakArrayObject): array
    {
        if (isset($zaakArrayObject['verantwoordelijkeOrganisatie']) === false) {
            throw new \Exception('verantwoordelijkeOrganisatie is not set');
        }

        $bsn = $this->getZaakBsn($zaakArrayObject);
        if ($bsn === null) {
            throw new \Exception('No bsn found in a rol->betrokkeneIdentificatie->inpBsn');
        }

        // Base values
        $caseArray = $this->setCaseDefaultValues($zaakArrayObject, $casetypeId, $bsn);

        // Manually map subobjects
        $caseArray = $this->mapPostEigenschappen($caseArray, $zaakArrayObject, $zaakTypeObject);
        $caseArray = $this->mapPostInfoObjecten($caseArray, $zaakArrayObject);
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

        // Unset empty keys.
        $caseArray = array_filter($caseArray);

        $sourceId = $this->sendCaseToXxllnc($caseArray, $synchronization);
        if (!$sourceId) {
            var_dump("POST to xxllnc failed.");
            return [];
        }

        var_dump("POST to xxllnc succesfull with external id: $sourceId");
        $this->saveSynchronization($synchronization, $sourceId, $caseObject);

        return $caseArray;

    }//end mapZGWToXxllnc()


    /**
     * Maps zgw besluit to xxllnc case.
     *
     * @param string $besluitCaseTypeId  The besluitTypeId.
     * @param array  $besluitArrayObject ZGW Besluit array.
     *
     * @throws Exception
     *
     * @return string|null $this->data Data which we entered the function with.
     */
    public function mapBesluitToXxllnc(string $besluitCaseTypeId, array $besluitArrayObject)
    {
        if (isset($zaakArrayObject['verantwoordelijkeOrganisatie']) === false) {
            throw new \Exception('verantwoordelijkeOrganisatie is not set');
        }

        // Base values.
        $caseArray = $this->setCaseDefaultValues($besluitArrayObject, $besluitCaseTypeId, 'besluit');

        // Manually map subobjects.
        $caseObject = $this->getCaseObject($besluitArrayObject, 'besluit');

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
            return null;
        }

        $this->saveSynchronization($synchronization, $sourceId, $caseObject);

        return $sourceId;

    }//end mapBesluitToXxllnc()


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
    private function syncZaakToXxllnc(): array
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
     * Maps the Besluit to a xxllnc case, post it, and creates a realtion to the actual case.
     * We create a sub case for the Besluit because the xxllnc api does not have a Besluit variant.
     */
    private function syncBesluitToXxllnc()
    {
        if (isset($this->data['zaak']) === false) {
            var_dump('syncBesluitToXxllnc returned, no zaak set');
            $this->logger->error('syncBesluitToXxllnc returned, no zaak set');

            return [];
        }

        if (isset($this->data['besluit']['besluittype']) === false) {
            var_dump('syncBesluitToXxllnc returned, no besluittype set');
            $this->logger->error('syncBesluitToXxllnc returned, no besluittype set');

            return [];
        }

        $zaakObject      = $this->entityManager->find('App:ObjectEntity', $this->data['zaak']['_self']['id']);
        $zaakArrayObject = $zaakObject->toArray();

        $besluitTypeObject = $this->entityManager->find('App:ObjectEntity', $this->data['besluit']['besluittype']['_self']['id']);
        if ($besluitTypeObject === null) {
            var_dump('syncBesluitToXxllnc returned, no besluitType set on besluit');
            $this->logger->error('syncBesluitToXxllnc returned, no besluitType set on besluit');

            return [];
        }

        // If set we already synced the Zaak to xxllnc as a case.
        if ($besluitTypeObject->getSynchronizations() && $besluitTypeObject->getSynchronizations()->first() && $besluitTypeObject->getSynchronizations()->first()->getSourceId()) {
            $besluitCaseTypeId = $besluitTypeObject->getSynchronizations()->first()->getSourceId();
        } else {
            var_dump('syncBesluitToXxllnc returned, the current besluittype had no source id so it did not came from the xxllnc api.');
            $this->logger->error('syncBesluitToXxllnc returned, the current besluittype had no source id so it did not came from the xxllnc api.');

            return [];
        }

        // If set we already synced the Zaak to xxllnc as a case.
        if ($zaakObject->getSynchronizations() && $zaakObject->getSynchronizations()->first() && $zaakObject->getSynchronizations()->first()->getSourceId()) {
            $caseSourceId = $zaakObject->getSynchronizations()->first()->getSourceId();
        }

        // If the Zaak hasn't been send to xxllnc yet, do it now.
        if (isset($caseSourceId) === false) {
            // @TODO Lets expect the case has been synced already for now...
            // @TODO Make it possible to sync zaak from here LOW PRIO
            // $this->syncZaakToXxllnc();
        }

        $besluitCaseSourceId = $this->mapBesluitToXxllnc($besluitCaseTypeId, $this->data['besluit']);
        if (isset($besluitCaseSourceId) === false) {
            $this->logger->error('no besluitCaseSourceId returned from mapBesluitToXxllnc, returning.');
            var_dump('no besluitCaseSourceId returned from mapBesluitToXxllnc, returning.');

            return [];
        }

        // Link normal case and besluit case at xxllnc api.
        if ($this->sendCaseRelationForBesluit($caseSourceId, $besluitCaseSourceId) === false) {
            $this->logger->error("setting case relation for case $caseSourceId and case (besluit) $besluitCaseSourceId failed.");
            var_dump("setting case relation for case $caseSourceId and case (besluit) $besluitCaseSourceId failed.");

            return [];
        }
        var_dump('test syncBesluitToXxllnc');
        exit;
        return [];

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

        return ['response' => $this->syncZaakToXxllnc()];

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
        $this->data          = $data['response'];
        $this->configuration = $configuration;

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

        return ['response' => $this->syncZaakToXxllnc()];

    }//end zgwToXxllncHandler()


}//end class
