<?php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Action;
use App\Entity\Entity;
use App\Entity\Gateway;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Service\ObjectEntityService;
use App\Service\SynchronizationService;
use App\Service\TranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

class MapZaakService
{
    private EntityManagerInterface $entityManager;
    private TranslationService $translationService;
    private ObjectEntityService $objectEntityService;
    private SynchronizationService $synchronizationService;
    private array $configuration;
    private array $data;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        TranslationService $translationService,
        ObjectEntityService $objectEntityService,
        SynchronizationService $synchronizationService
    ) {
        $this->entityManager = $entityManager;
        $this->translationService = $translationService;
        $this->objectEntityService = $objectEntityService;
        $this->synchronizationService = $synchronizationService;

        $this->objectEntityRepo = $this->entityManager->getRepository(ObjectEntity::class);
        $this->entityRepo = $this->entityManager->getRepository(Entity::class);
        $this->actionRepo = $this->entityManager->getRepository(Action::class);

        $this->mappingIn = [
            'identificatie'              => 'reference',
            'omschrijving'               => 'instance.subject',
            'toelichting'                => 'instance.subject_external',
            'registratiedatum'           => 'instance.date_of_registration',
            'startdatum'                 => 'instance.date_of_registration',
            'einddatum'                  => 'instance.date_of_completion',
            'einddatumGepland'           => 'instance.date_target',
            'publicatiedatum'            => 'instance.date_target',
            'communicatiekanaal'         => 'instance.channel_of_contact',
            'vertrouwelijkheidaanduidng' => 'instance.confidentiality.mapped',

        ];

        $this->skeletonIn = [
            'verantwoordelijkeOrganisatie' => '070124036',
            'betalingsindicatie'           => 'geheel',
            'betalingsindicatieWeergave'   => 'Bedrag is volledig betaald',
            'laatsteBetaalDatum'           => '15-07-2022',
            'archiefnominatie'             => 'blijvend_bewaren',
            'archiefstatus'                => 'nog_te_archiveren',
        ];
    }

    /**
     * Maps the eigenschappen from xxllnc to zgw.
     *
     * @param array      $zaakArray     This is the ZGW Zaak array.
     * @param array      $zaakTypeArray This is the ZGW ZaakType array.
     * @param attributes $ar            This is the xxllnc attributes array that will be mapped to eigenschappen.
     *
     * @return array $zaakArray This is the ZGW Zaak array with the added eigenschappen.
     */
    private function mapEigenschappen(array $zaakArray, array $zaakTypeArray, ObjectEntity $zaakTypeObjectEntity, array $attributes): array
    {
        // Manually map properties to eigenschappen
        if (!isset($zaakTypeArray['eigenschappen'])) {
            $eigenschappen = [];
            foreach ($attributes as $attributeName => $attributeValue) {
                $eigenschappen[] = [
                    'naam'      => $attributeName,
                    'definitie' => $attributeName,
                ];
            }
            $zaakTypeObjectEntity->setValue('eigenschappen', $eigenschappen);
            $this->entityManager->persist($zaakTypeObjectEntity);
            $this->entityManager->flush();
        }

        $zaakTypeArray = $zaakTypeObjectEntity->toArray();

        !isset($zaakArray['eigenschappen']) && $zaakArray['eigenschappen'] = [];
        foreach ($attributes as $attributeName => $attributeValue) {
            foreach ($zaakTypeArray['eigenschappen'] as $eigenschap) {
                if ($eigenschap['naam'] == $attributeName) {
                    $zaakArray['eigenschappen'][] = [
                        'naam'   => $attributeName,
                        'waarde' => is_array($attributeValue) ?
                            json_encode($attributeValue) :
                            strval($attributeValue),
                        'eigenschap' => $this->objectEntityRepo->find($eigenschap['_self']['id']),
                    ];
                }
            }
        }

        return $zaakArray;
    }

    /**
     * Maps the rollen from xxllnc to zgw.
     *
     * @param array $zaakArray     This is the ZGW Zaak array.
     * @param array $zaakTypeArray This is the ZGW ZaakType array.
     * @param array $rol           This is the xxllnc Rol array.
     *
     * @return array $zaakArray This is the ZGW Zaak array with the added rollen.
     */
    private function mapRollen(array $zaakArray, array $zaakTypeArray, array $rol): array
    {
        $zaakArray['rollen'] = [];
        foreach ($zaakTypeArray['roltypen'] as $rolType) {
            if (strtolower($rol['preview']) == strtolower($rolType['omschrijving'])) {
                $zaakArray['rollen'][] = [
                    'roltype'              => $this->objectEntityRepo->find($rolType['_self']['id']),
                    'omschrijving'         => $rol['preview'],
                    'omschrijvingGeneriek' => strtolower($rol['preview']),
                    'roltoelichting'       => $rol['instance']['description'],
                    'betrokkeneType'       => 'natuurlijk_persoon',
                ];
            }
        }

        return $zaakArray;
    }

    /**
     * Maps the status from xxllnc to zgw.
     *
     * @param array $zaakArray     This is the ZGW Zaak array.
     * @param array $zaakTypeArray This is the ZGW ZaakType array.
     * @param array $status        This is the xxllnc Status array.
     *
     * @return array $zaakArray This is the ZGW Zaak array with the added status.
     */
    private function mapStatus(array $zaakArray, array $zaakTypeArray, array $status): array
    {
        foreach ($zaakTypeArray['statustypen'] as $statusType) {
            if ($status['preview'] == $statusType['omschrijving']) {
                $zaakArray['status'] = [
                    'statustype'        => $this->objectEntityRepo->find($statusType['_self']['id']),
                    'datumStatusGezet'  => isset($status['instance']['date_modified']) ? $status['instance']['date_modified'] : '2020-04-15',
                    'statustoelichting' => isset($status['instance']['milestone_label']) && strval($status['instance']['milestone_label']),
                ];

                return $zaakArray;
            }
        }

        return $zaakArray;
    }

    /**
     * Finds or creates a ObjectEntity from the Zaak Entity.
     *
     * @param Entity $zaakEntity This is the Zaak Entity in the gateway.
     *
     * @return ObjectEntity $zaakObjectEntity This is the ZGW Zaak ObjectEntity.
     */
    private function getZaakObjectEntity(Entity $zaakEntity): ObjectEntity
    {
        // Find already existing zgwZaak by $this->data['reference']
        $zaakObjectEntity = $this->objectEntityRepo->findOneBy(['externalId' => $this->data['reference'], 'entity' => $zaakEntity]);

        // Create new empty ObjectEntity if no ObjectEntity has been found
        if (!$zaakObjectEntity instanceof ObjectEntity) {
            $zaakObjectEntity = new ObjectEntity();
            $zaakObjectEntity->setEntity($zaakEntity);
        }

        return $zaakObjectEntity;
    }

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
            $eigenschapIds[] = $eigenschap->getValue('id');
        }

        // eigenschappen to values
        if (isset($zaakArrayObject['eigenschappen'])) {
            foreach ($zaakArrayObject['eigenschappen'] as $zaakEigenschap) {
                if (isset($zaakEigenschap['eigenschap'])) {
                    in_array($zaakEigenschap['eigenschap']['_self']['id'], $eigenschapIds) && $xxllncZaakArray['values'][] = [
                        $zaakEigenschap['eigenschap']['definitie'] => $zaakEigenschap['waarde'],
                    ];
                }
            }
        }

        return $xxllncZaakArray;
    }

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
    }

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
        // rollen to subjects
        if (isset($zaakArrayObject['rollen']) && isset($zaakArrayObject['zaaktype']['roltypen'])) {
            foreach ($zaakArrayObject['rollen'] as $rol) {
                foreach ($zaakArrayObject['zaaktype']['roltypen'] as $rolType) {
                    if ($rolType['omschrijving'] === $rol['roltoelichting']) {
                        $rolTypeObject = $this->entityManager->find('App:ObjectEntity', $rolType['_self']['id']);
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
    }

    /**
     * Creates or updates a ZGW Zaak from a xxllnc casetype with the use of mapping.
     *
     * @param array $data          Data from the handler where the xxllnc casetype is in.
     * @param array $configuration Configuration from the Action where the Zaak entity id is stored in.
     *
     * @return array $this->data Data which we entered the function with
     */
    public function zgwToXxllncHandler(array $data, array $configuration): array
    {
        // var_dump('mapZgwToZaakHandler triggered');
        $this->data = $data['response'];
        $this->configuration = $configuration;

        isset($this->configuration['entities']['XxllncZaakPost']) && $xxllncZaakPostEntity = $this->entityRepo->find($this->configuration['entities']['XxllncZaakPost']);

        if (!isset($xxllncZaakPostEntity)) {
            throw new \Exception('Xxllnc zaak entity not found, check ZgwToXxllncHandler config');
        }

        if (isset($this->data['zaaktype'])) {
            isset($this->data['zaaktype']['_self']['id']) && $zaakTypeId = $this->data['zaaktype']['_self']['id'];
            $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
            $casetypeId = $zaakTypeObject->getExternalId();
        } else {
            throw new \Exception('No zaaktype set on zaak');
        }

        if (isset($this->data['_self']['id'])) {
            $zaakArrayObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id'])->toArray();
        } else {
            throw new \Exception('No id on zaak');
        }

        $xxllncZaakArray = ['casetype_id' => $casetypeId];
        $xxllncZaakArray['source'] = 'behandelaar';
        $xxllncZaakArray['confidentiality'] = 'public';

        $eigenschappenCollection = $zaakTypeObject->getValue('eigenschappen');
        if ($eigenschappenCollection instanceof PersistentCollection) {
            $eigenschappenCollection = $eigenschappenCollection->toArray();
        }
        $xxllncZaakArray = $this->mapPostEigenschappen($xxllncZaakArray, $zaakArrayObject, $eigenschappenCollection);
        $xxllncZaakArray = $this->mapPostInfoObjecten($xxllncZaakArray, $zaakArrayObject);
        $xxllncZaakArray = $this->mapPostRollen($xxllncZaakArray, $zaakArrayObject);

        // DONT COMMIT @TODO remove
        $objectEntities = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['entity' => $xxllncZaakPostEntity]);
        foreach ($objectEntities as $object) {
            $this->entityManager->remove($object);
        }

        $xxllncZaakObjectEntity = new ObjectEntity();
        $xxllncZaakObjectEntity->setEntity($xxllncZaakPostEntity);

        $xxllncZaakObjectEntity->hydrate($xxllncZaakArray);

        $this->entityManager->persist($xxllncZaakObjectEntity);
        $this->entityManager->flush();
        $this->entityManager->clear('App:ObjectEntity');

        $xxllncZaakArrayObject = $xxllncZaakObjectEntity->toArray();

        $this->objectEntityService->dispatchEvent('commongateway.object.create', ['entity' => $xxllncZaakPostEntity->getId()->toString(), 'response' => $xxllncZaakArrayObject]);

        return $this->data;
    }

    /**
     * Gets a existing ZaakType or syncs one from the xxllnc api.
     *
     * @param array $data          Data from the handler where the xxllnc casetype is in.
     * @param array $configuration Configuration from the Action where the Zaak entity id is stored in.
     *
     * @return array $this->data Data which we entered the function with
     */
    public function getZaakTypeByExtId(Gateway $xxllncGateway, Entity $xxllncZaakTypeEntity, Entity $zaakTypeEntity, string $zaakTypeId, Action $action)
    {
        $zaakTypeObjectEntity = $this->objectEntityRepo->findOneBy(['externalId' => $zaakTypeId, 'entity' => $zaakTypeEntity]);

        if (
            !isset($zaakTypeObjectEntity) ||
            (isset($zaakTypeObjectEntity) &&
                !$synchronization = $this->entityManager->getRepository('App:Synchronization')->findOneBy(['object' => $zaakTypeObjectEntity->getId(), 'gateway' => $xxllncGateway])
            )
        ) {
            $synchronization = new Synchronization($xxllncGateway);
            isset($zaakTypeObjectEntity) && $synchronization->setObject($zaakTypeObjectEntity);
            $synchronization->setSourceId($zaakTypeId);
            $synchronization->setEntity($xxllncZaakTypeEntity);
            $synchronization->setAction($action);
            $synchronization->setEndpoint('/casetype/' . $zaakTypeId);
            $this->entityManager->persist($synchronization);
            $synchronization = $this->synchronizationService->handleSync($synchronization, [], $action->getConfiguration());

            $this->entityManager->persist($synchronization);
            $this->entityManager->flush();

            return $synchronization->getObject();
        } else {
            return $zaakTypeObjectEntity;
        }
    }

    /**
     * Creates or updates a ZGW Zaak from a xxllnc casetype with the use of mapping.
     *
     * @param array $data          Data from the handler where the xxllnc casetype is in.
     * @param array $configuration Configuration from the Action where the Zaak entity id is stored in.
     *
     * @return array $this->data Data which we entered the function with
     */
    public function mapZaakHandler(array $data, array $configuration): array
    {
        var_dump('MapZaakService triggered');
        $this->data = $data['response'];
        $this->configuration = $configuration;

        // ik heb in config nog nodig: domein url (kan via gekoppelde source), xxllncZaakTypeEntityId

        // Find ZGW Type entities by id from config
        $zaakEntity = $this->entityRepo->find($configuration['entities']['Zaak']);
        $zaakTypeEntity = $this->entityRepo->find($configuration['entities']['ZaakType']);
        $xxllncZaakTypeEntity = $this->entityRepo->find($configuration['entities']['XxllncZaakType']);
        $syncOneZaakTypeAction = $this->actionRepo->find($configuration['actions']['SyncOneZaakType']);

        // Get xxllnc Gateway
        $xxllncGateway = $this->entityManager->getRepository(Gateway::class)->find($configuration['source']);

        if (!$zaakEntity instanceof Entity) {
            throw new \Exception('Zaak entity could not be found, plugin configuration could be wrong');
        }
        if (!$zaakTypeEntity instanceof Entity) {
            throw new \Exception('ZaakType entity could not be found, plugin configuration could be wrong');
        }
        if (!$xxllncZaakTypeEntity instanceof Entity) {
            throw new \Exception('Xxllnc zaaktype entity could not be found, plugin configuration could be wrong');
        }
        if (!$xxllncGateway instanceof Gateway) {
            throw new \Exception('Xxllnc gateway could not be found, plugin configuration could be wrong');
        }
        if (!$syncOneZaakTypeAction instanceof Action) {
            throw new \Exception('Xxllnc gateway could not be found, plugin configuration could be wrong');
        }

        // if no casetype id return
        if (!isset($this->data['instance']['casetype'])) {
            return ['response' => $this->data];
        }

        $zaakTypeId = $this->data['instance']['casetype']['reference'];

        isset($zaakTypeId) && $zaakTypeObjectEntity = $this->getZaakTypeByExtId($xxllncGateway, $xxllncZaakTypeEntity, $zaakTypeEntity, $zaakTypeId, $syncOneZaakTypeAction);

        if (!$zaakTypeObjectEntity instanceof ObjectEntity) {
            return $this->data;
        }

        // $zaakTypeArray = $zaakTypeObjectEntity->toArray();

        // Get xxllncZaakObjectArray from this->data['_self']['id']
        $xxllncZaakObjectEntity = $this->objectEntityRepo->find($this->data['_self']['id']);
        $xxllncZaakObjectArray = $xxllncZaakObjectEntity->toArray();

        // Map and set default values from xxllnc casetype to zgw zaaktype
        $zgwZaakArray = $this->translationService->dotHydrator(isset($this->skeletonIn) ? array_merge($this->data, $this->skeletonIn) : $this->data, $this->data, $this->mappingIn);

        // Get array version of the ZaakType
        $zaakTypeArray = $zaakTypeObjectEntity->toArray();

        // Set zaakType
        $zgwZaakArray['zaaktype'] = $zaakTypeObjectEntity;

        if (isset($zaakTypeArray['statustypen']) && isset($xxllncZaakObjectArray['instance']['milestone'])) {
            $zgwZaakArray = $this->mapStatus($zgwZaakArray, $zaakTypeArray, $xxllncZaakObjectArray['instance']['milestone']);
        }
        if (isset($zaakTypeArray['roltypen']) && isset($xxllncZaakObjectArray['instance']['route']['instance']['role'])) {
            $zgwZaakArray = $this->mapRollen($zgwZaakArray, $zaakTypeArray, $xxllncZaakObjectArray['instance']['route']['instance']['role']);
        }
        if (isset($zaakTypeArray['eigenschappen']) && isset($xxllncZaakObjectArray['instance']['attributes'])) {
            $zgwZaakArray = $this->mapEigenschappen($zgwZaakArray, $zaakTypeArray, $zaakTypeObjectEntity, $xxllncZaakObjectArray['instance']['attributes']);
        }
        $zaakObjectEntity = $this->getZaakObjectEntity($zaakEntity);

        // set organization, application and owner on zaakObjectEntity from this->data
        $zaakObjectEntity->setOrganization($xxllncZaakObjectEntity->getOrganization());
        $zaakObjectEntity->setOwner($xxllncZaakObjectEntity->getOwner());
        $zaakObjectEntity->setApplication($xxllncZaakObjectEntity->getApplication());

        $zaakObjectEntity->hydrate($zgwZaakArray);

        $zaakObjectEntity->setExternalId($this->data['reference']);
        $zaakObjectEntity = $this->synchronizationService->setApplicationAndOrganization($zaakObjectEntity);

        $this->entityManager->persist($zaakObjectEntity);
        $this->entityManager->flush();
        var_dump('ZGW Zaak created');

        return ['response' => $zaakObjectEntity->toArray()];
    }
}
