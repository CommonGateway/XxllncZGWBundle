<?php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\Persistence\ObjectRepository;
use CommonGateway\CoreBundle\Service\CallService;
use App\Entity\Gateway as Source;
use Exception;
use App\Entity\Attribute;
use App\Entity\Value;

class ZGWToXxllncZaakService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private SymfonyStyle $io;
    private array $configuration;
    private array $data;

    private ObjectRepository $schemaRepo;
    private ObjectRepository $sourceRepo;

    private ?Source $xxllncAPI;
    private ?Schema $xxllncZaakSchema;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;

        $this->schemaRepo = $this->entityManager->getRepository(Schema::class);
        $this->sourceRepo = $this->entityManager->getRepository(Source::class);
    }// end __construct

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $io
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;

        return $this;
    }// end setStyle

    /**
     * Maps the eigenschappen from zgw to xxllnc.
     *
     * @param array $xxllncZaakArray       This is the Xxllnc Zaak array.
     * @param array $zaakArrayObject       This is the ZGW Zaak array.
     * @param array $zaakTypeEigenschappen These are the ZGW ZaakType eigenschappen.
     *
     * @return array $xxllncZaakArray This is the Xxllnc Zaak array with the added eigenschappen.
     */
    private function mapPostEigenschappen(array $xxllncZaakArray, array $zaakArrayObject, array $zaakTypeEigenschappen): array
    {
        $eigenschapIds = [];
        foreach ($zaakTypeEigenschappen as $eigenschap) {
            $eigenschapIds[] = $eigenschap->getId()->toString();
        }

        // eigenschappen to values
        if (isset($zaakArrayObject['eigenschappen'])) {
            foreach ($zaakArrayObject['eigenschappen'] as $zaakEigenschap) {
                if (isset($zaakEigenschap['eigenschap']) && in_array($zaakEigenschap['eigenschap']['_self']['id'], $eigenschapIds)) {
                    $xxllncZaakArray['values'][$zaakEigenschap['eigenschap']['definitie']] = [$zaakEigenschap['waarde']];
                }
            }
        }

        return $xxllncZaakArray;
    }// end mapPostEigenschappen

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
        if (isset($zaakArrayObject['zaakinformatieobjecten'])) {
            foreach ($zaakArrayObject['zaakinformatieobjecten'] as $infoObject) {
                isset($infoObject['informatieobject']) && $xxllncZaakArray['files'][] = [
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
                            'trust_level'   => $infoObject['integriteit']['waarde'] ?? 'Openbaar',
                            'status'        => 'original',
                            'creation_date' => $infoObject['informatieobject']['creatiedatum'],
                        ],
                    ],
                ];
            }
        }

        return $xxllncZaakArray;
    }// end mapPostInfoObjecten

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
        if (isset($zaakArrayObject['rollen']) && isset($zaakArrayObject['zaaktype']['roltypen'])) {
            foreach ($zaakArrayObject['rollen'] as $rol) {
                foreach ($zaakArrayObject['zaaktype']['roltypen'] as $rolType) {
                    if ($rolType['omschrijvingGeneriek'] === $rol['roltoelichting']) {
                        $rolTypeObject = $this->entityManager->find(ObjectEntity::class, $rolType['_self']['id']);
                        if ($rolTypeObject instanceof ObjectEntity && $rolTypeObject->getExternalId() !== null) {
                            $xxllncZaakArray['subjects'][] = [
                                'subject' => [
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
        }

        return $xxllncZaakArray;
    }// end mapPostRollen

    

    // /**
    //  * Creates or updates a ZGW Zaak from a xxllnc casetype with the use of mapping.
    //  *
    //  * @param array $data          Data from the handler where the xxllnc casetype is in.
    //  * @param array $configuration Configuration from the Action where the Zaak entity id is stored in.
    //  *
    //  * @return array $this->data Data which we entered the function with
    //  */
    // public function mapUpdateZaak(array $data, array $configuration): array
    // {
    //     if (!isset($data['response']['_self']['id'])) {
    //         return $data;
    //     }

    //     // validate object type
    //     $objectEntity = $this->entityManager->find(ObjectEntity::class, $data['response']['_self']['id']);
    //     if (!in_array($objectEntity->getEntity()->getName(), ['ZaakEigenschap'])) {
    //         return $data;
    //     }
    //     // var_dump('mapUpdate triggered');
    //     $this->data = $data['response'];
    //     $this->configuration = $configuration;

    //     $this->data = $objectEntity->toArray();

    //     isset($this->configuration['entities']['XxllncZaakPost']) && $xxllncZaakPostEntity = $this->entityRepo->find($this->configuration['entities']['XxllncZaakPost']);

    //     if (!isset($xxllncZaakPostEntity)) {
    //         throw new \Exception('XxllncZaakPost entity not found, check MapUpdateZaak config');
    //     }

    //     if (!isset($this->data['zaak'])) {
    //         throw new \Exception('Zaak not set on given object');
    //     }

    //     if (isset($this->data['zaak']['zaaktype'])) {
    //         isset($this->data['zaak']['zaaktype']['_self']['id']) && $zaakTypeId = $this->data['zaak']['zaaktype']['_self']['id'];
    //         $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
    //         $casetypeId = $zaakTypeObject->getExternalId();
    //         // Return here cause if the zaaktype is created through this gateway, we cant sync it to xxllnc because it doesn't exist there
    //         if (!isset($casetypeId)) {
    //             return $this->data;
    //         }
    //     } else {
    //         throw new \Exception('ZaakType not set on Zaak');
    //     }

    //     // @TODO update SyncUpdateZaak config with Zaak externalId?
    //     $values = $this->entityManager->getRepository('App:Value')->findBy(['stringValue' => $this->data['zaak']['_self']['id']]);
    //     foreach ($values as $value) {
    //         if ($value->getObjectEntity()->getEntity()->getId()->toString() == $xxllncZaakPostEntity->getId()->toString() && $value->getAttribute()->getName() == 'zgwZaak') {
    //             $externalId = $value->getObjectEntity()->getExternalId();
    //             $zaakArray = $value->getObjectEntity()->toArray();
    //             break;
    //         }
    //     }

    //     if (!isset($externalId)) {
    //         throw new \Exception('Earlier external id from xxllnc not found');
    //     }

    //     $zaakObject = $this->entityManager->find('App:ObjectEntity', $this->data['zaak']['_self']['id']);
    //     if (!$zaakObject instanceof ObjectEntity) {
    //         throw new \Exception('Zaak object not found with id:' . $this->data['zaak']['_self']['id']);
    //     }

    //     $zaakArray = $zaakObject->toArray();

    //     $xxllncZaakArray = $this->mapZGWToXxllncZaak($casetypeId, $zaakTypeObject, $zaakArray, $xxllncZaakPostEntity, false);

    //     return ['response' => $xxllncZaakArray, 'entity' => $xxllncZaakPostEntity->getId()->toString()];
    // }

    /**
     * Saves case to xxllnc by POST or PUT request.
     *
     * @param string           $caseArray       Case object.
     * @param ?Synchronization $synchronization Earlier created synchronization object. 
     *
     * @return bool True if succesfully saved to xxllnc
     */
    public function sendCaseToXxllnc(array $caseArray, ?Synchronization $synchronization = null)
    {
        // If we have a id we can do a put else post
        if ($synchronization && $synchronization->getSourceId()) {
            $method = 'PUT';
            $endpoint = "/case/{$synchronization->getSourceId()}/update";
            $logMessage = "Updating case: {$synchronization->getSourceId()} to xxllnc";
            $unsetProperties = ['_self', 'requestor', 'casetype_id', 'source', 'open', 'route', 'contact_details', 'confidentiality', 'number', 'zgwZaak'];
        } else {
            $method = 'POST';
            $endpoint = '/case/create';
            $logMessage = 'Posting new case to xxllnc';
            $unsetProperties = ['_self', 'requestor._self', 'zgwZaak'];
        }// end if

        // unset unwanted properties
        foreach ($unsetProperties as $property) {
            unset($caseArray[$property]);
        }
        if (isset($caseArray['requestor']['_self'])) {
            unset($caseArray['requestor']['_self']);
        }
        
        // Send the POST/PUT request to xxllnc
            var_dump(json_encode($caseArray));die;
        try {
            isset($this->io) && $this->io->info($logMessage);
            $response = $this->callService->call($this->xxllncAPI, $endpoint, $method, ['body' => $caseArray]);
            $result = $this->callService->decodeResponse($this->xxllncAPI, $response);
            var_dump($result);die;
        } catch (Exception $e) {
            isset($this->io) && $this->io->error("Failed to $method case, message:  {$e->getMessage()}");
            var_dump($e->getMessage());die;

            return false;
        }// end try catch

        // @TODO return saved id

        return 'id';
    }// end sendCaseToXxllnc

    /**
     * Maps zgw zaak to xxllnc case.
     *
     * @param string       $caseTypeId           CaseTypeID as in xxllnc.
     * @param ObjectEntity $zaakTypeObject       ZGW ZaakType object 
     *
     * @return array $this->data Data which we entered the function with
     */
    public function mapZGWToXxllncZaak(string $casetypeId, ObjectEntity $zaakTypeObject, array $zaakArrayObject)
    {
        if (!isset($zaakArrayObject['verantwoordelijkeOrganisatie'])) {
            throw new \Exception('verantwoordelijkeOrganisatie is not set');
        }
        
        // Base values
        $caseArray['zgwZaak'] = $zaakArrayObject['_self']['id'];
        $caseArray['casetype_id'] = $casetypeId;
        $caseArray['source'] = 'behandelaar';
        $caseArray['confidentiality'] = 'public';

        $eigenschappenCollection = $zaakTypeObject->getValue('eigenschappen');
        if ($eigenschappenCollection instanceof PersistentCollection) {
            $eigenschappenCollection = $eigenschappenCollection->toArray();
        }

        // Manually map subobjects
        $caseArray = $this->mapPostEigenschappen($caseArray, $zaakArrayObject, $eigenschappenCollection);
        $caseArray = $this->mapPostInfoObjecten($caseArray, $zaakArrayObject);
        // $caseArray = $this->mapPostRollen($caseArray, $zaakArrayObject); // disabled for now

        // Get needed attribute so we can find the already existing case object // @TODO do
        $zgwZaakAttribute = $this->entityManager->getRepository(Attribute::class)->findOneBy(['entity' => $this->xxllncZaakSchema, 'name' => 'zgwZaak']);
        if (!$zgwZaakAttribute) {
            throw new Exception('No zgwZaak attribute found');
        }

        // Find or create case object
        $caseObject = $this->entityManager->getRepository(Value::class)->findOneBy(['stringValue' => $zaakArrayObject['_self']['id'], 'attribute' => $zgwZaakAttribute]) ?? new ObjectEntity($this->xxllncZaakSchema);
        if ($caseObject->getSynchronizations()) {
            $synchronization = $caseObject->getSynchronizations()[0];
        }

        $caseObject->hydrate($caseArray);
        $this->entityManager->persist($caseObject);
        $caseArray = $caseObject->toArray();

        $caseArray['requestor'] = ['id' => '922904418', 'type' => 'person'];
        $sourceId = $this->sendCaseToXxllnc($caseArray, $synchronization ?? null);

        if (($sourceId && !isset($synchronization)) || (isset($synchronization) && !$synchronization->getSourceId())) {
            $synchronization = new Synchronization();
            $synchronization->setEntity($this->xxllncZaakSchema);
        }

        $synchronization->setSourceId($sourceId);
        $synchronization->setObject($caseObject);

        $this->entityManager->persist($synchronization);
        $this->entityManager->flush();
        // old
        // $throwEvent && $this->objectEntityService->dispatchEvent('commongateway.object.create', ['entity' => $xxllncZaakEntity->getId()->toString(), 'response' => $caseObject]);

        return $caseArray;
    }// end mapZGWToXxllncZaak

    

    /**
     * Makes sure this action has all the gateway objects it needs.
     * 
     * @return bool false if some object couldn't be fetched
     */
    private function getRequiredGatewayObjects(): bool
    {
        // Get ZaakType schema
        if (!isset($this->xxllncZaakSchema) && !$this->xxllncZaakSchema = $this->schemaRepo->findOneBy(['reference' => 'https://common-gateway.nl/xxllnc-zaak-post.schema.json'])) {
           isset($this->io) && $this->io->error('Could not find Schema: https://common-gateway.nl/xxllnc-zaak-post.schema.json');

            return false;
        }

        // Get xxllnc source
        if (!isset($this->xxllncAPI) && !$this->xxllncAPI = $this->sourceRepo->findOneBy(['location' => 'https://development.zaaksysteem.nl/api/v1'])) {
           isset($this->io) && $this->io->error('Could not find Source: Xxllnc API');

            return false;
        }

        return true;
    }// end getRequiredGatewayObjects

    /**
     * Creates or updates a ZGW Zaak from a xxllnc casetype with the use of mapping.
     *
     * @param ?array $data          Data from the handler where the xxllnc casetype is in.
     * @param ?array $configuration Configuration from the Action where the Zaak entity id is stored in.
     *
     * @return array $this->data Data which we entered the function with
     */
    public function zgwToXxllncZaakHandler(?array $data = [], ?array $configuration = []): array
    {
        $this->data = $data['response'];
        $this->configuration = $configuration;

        // @TODO change all Exceptions to GatewayExceptions ?

        isset($this->io) && $this->io->success('zgwToXxllncZaak triggered');

        $this->getRequiredGatewayObjects();


        if (!isset($this->data['zaaktype'])) {
            throw new \Exception('No zaaktype set on zaak');
        }
        
        if (!isset($this->data['embedded']['zaaktype']['_self']['id'])) {
            throw new Exception('ZaakType id not found on Zaak object');
        }
        $zaakTypeId = $this->data['embedded']['zaaktype']['_self']['id'];
        $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
        $casetypeId = $zaakTypeObject->getSynchronizations()[0]->getSourceId() ?? null;
        // Return here cause if the zaaktype is created through this gateway, we cant sync it to xxllnc because it doesn't exist there
        if (!isset($casetypeId)) {
            return $this->data;
        }

        if (!isset($this->data['_self']['id'])) {
            throw new \Exception('No id on zaak'); // meaning it didnt properly save in the gateway
        }

        $zaakArrayObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id']);

        if (!isset($zaakArrayObject)) {
            throw new \Exception('ZGW Zaak not found with id: ' . $this->data['_self']['id']);
        }
        $zaakArrayObject = $zaakArrayObject->toArray();

        $xxllncZaakArrayObject = $this->mapZGWToXxllncZaak($casetypeId, $zaakTypeObject, $zaakArrayObject);

        return ['response' => $xxllncZaakArrayObject];
    }
}
