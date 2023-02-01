<?php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Entity;
use App\Entity\ObjectEntity;
use App\Service\ObjectEntityService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\Console\Style\SymfonyStyle;

class ZGWToXxllncZaakService
{
    private EntityManagerInterface $entityManager;
    private ObjectEntityService $objectEntityService;
    private SymfonyStyle $io;
    private array $configuration;
    private array $data;

    private Entity $entityRepo;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        ObjectEntityService $objectEntityService
    ) {
        $this->entityManager = $entityManager;
        $this->objectEntityService = $objectEntityService;

        $this->entityRepo = $this->entityManager->getRepository(Entity::class);
    }

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
        // var_dump(1);
        // var_dump(isset($zaakArrayObject['rollen']) && isset($zaakArrayObject['zaaktype']['roltypen']));
        if (isset($zaakArrayObject['rollen']) && isset($zaakArrayObject['zaaktype']['roltypen'])) {
            foreach ($zaakArrayObject['rollen'] as $rol) {
                foreach ($zaakArrayObject['zaaktype']['roltypen'] as $rolType) {
                    // var_dump(2);
                    // var_dump($rolType['omschrijving'] === $rol['roltoelichting']);
                    // var_dump($rolType['omschrijving']);
                    // var_dump($rol['roltoelichting']);
                    if ($rolType['omschrijving'] === $rol['roltoelichting']) {
                        $rolTypeObject = $this->entityManager->find('App:ObjectEntity', $rolType['_self']['id']);
                        // var_dump(3);
                        // var_dump($rolTypeObject->getId()->toString());
                        // var_dump($rolTypeObject instanceof ObjectEntity && $rolTypeObject->getExternalId() !== null);
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
     * Maps zgw zaak to xxllnc case.
     *
     * @param string       $caseTypeId           CaseTypeID as in xxllnc.
     * @param ObjectEntity $zaakTypeObject       ZGW ZaakType object 
     * @param array        $zaakArrayObject      ZGW Zaak object as array
     * @param Entity       $xxllncZaakEntity The xxllnc create/update case object
     *
     * @return array $this->data Data which we entered the function with
     */
    public function mapZGWToXxllncZaak(string $casetypeId, ObjectEntity $zaakTypeObject, array $zaakArrayObject, Entity $xxllncZaakEntity, ?bool $throwEvent = true)
    {
        if (isset($zaakArrayObject['verantwoordelijkeOrganisatie'])) {
            $xxllncZaakArray['requestor'] = ['id' => '922904418', 'type' => 'person'];
        } else {
            throw new \Exception('verantwoordelijkeOrganisatie is not set');
        }

        $xxllncZaakArray['zgwZaak'] = $zaakArrayObject['_self']['id'];
        $xxllncZaakArray['casetype_id'] = $casetypeId;
        $xxllncZaakArray['source'] = 'behandelaar';
        $xxllncZaakArray['confidentiality'] = 'public';

        $eigenschappenCollection = $zaakTypeObject->getValue('eigenschappen');
        if ($eigenschappenCollection instanceof PersistentCollection) {
            $eigenschappenCollection = $eigenschappenCollection->toArray();
        }
        $xxllncZaakArray = $this->mapPostEigenschappen($xxllncZaakArray, $zaakArrayObject, $eigenschappenCollection);
        $xxllncZaakArray = $this->mapPostInfoObjecten($xxllncZaakArray, $zaakArrayObject);
        $xxllncZaakArray = $this->mapPostRollen($xxllncZaakArray, $zaakArrayObject);

        $xxllncZaakObjectEntity = new ObjectEntity();
        $xxllncZaakObjectEntity->setEntity($xxllncZaakEntity);

        $xxllncZaakObjectEntity->hydrate($xxllncZaakArray);

        $this->entityManager->persist($xxllncZaakObjectEntity);
        $this->entityManager->flush();
        $this->entityManager->clear('App:ObjectEntity');

        $xxllncZaakArrayObject = $xxllncZaakObjectEntity->toArray();

        var_dump('xxllnc zaak created');

        $throwEvent && $this->objectEntityService->dispatchEvent('commongateway.object.create', ['entity' => $xxllncZaakEntity->getId()->toString(), 'response' => $xxllncZaakArrayObject]);

        return $xxllncZaakArrayObject;
    }

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
        // var_dump('mapZgwToZaakHandler triggered');
        $this->data = $data['response'];
        $this->configuration = $configuration;
        $xxllncZaakArray = [];

        isset($this->configuration['entities']['XxllncZaakPost']) && $xxllncZaakPostEntity = $this->entityRepo->find($this->configuration['entities']['XxllncZaakPost']);

        if (!isset($xxllncZaakPostEntity)) {
            throw new \Exception('Xxllnc zaak entity not found, check ZgwToXxllncHandler config');
        }

        if (isset($this->data['zaaktype'])) {
            isset($this->data['zaaktype']['_self']['id']) && $zaakTypeId = $this->data['zaaktype']['_self']['id'];
            $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
            $casetypeId = $zaakTypeObject->getExternalId();
            // Return here cause if the zaaktype is created through this gateway, we cant sync it to xxllnc because it doesn't exist there
            if (!isset($casetypeId)) {
                return $this->data;
            }
        } else {
            throw new \Exception('No zaaktype set on zaak');
        }

        if (isset($this->data['_self']['id'])) {
            $zaakArrayObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id']);
        } else {
            throw new \Exception('No id on zaak');
        }

        if (isset($zaakArrayObject)) {
            $zaakArrayObject = $zaakArrayObject->toArray();
        } else {
            throw new \Exception('ZGW Zaak not found with id: ' . $this->data['_self']['id']);
        }

        $xxllncZaakArrayObject = $this->mapZGWToXxllncZaak($casetypeId, $zaakTypeObject, $zaakArrayObject, $xxllncZaakPostEntity);

        return ['response' => $xxllncZaakArrayObject];
    }
}
