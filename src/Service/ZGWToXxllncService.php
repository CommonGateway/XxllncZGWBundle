<?php
/**
 * This class handles the synchronization of a zgw zrc zaak to a xxllnc case.
 *
 * By mapping, posting and creating a synchronization.
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
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService
    ) {
        $this->entityManager = $entityManager;
        $this->callService   = $callService;

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
     * Updates zgw zrc zaak with zrc eigenschap.
     *
     * @param array|null $data
     * @param array|null $configuration
     *
     * @return array
     *
     * @throws Exception
     * @todo   Make function smaller
     */
    public function updateZaakWithEigenschapHandler(?array $data = [], ?array $configuration = []): array
    {
        isset($this->style) === true && $this->style->success('updateZaakWithEigenschapHandler triggered');
        $this->configuration = $configuration;


        var_dump('test');die;
        $zaakObject = $this->entityManager->find('App:ObjectEntity', $data['response']['_id']);
        $zaakArray  = $zaakObject->toArray();

        $this->hasRequiredGatewayObjects();

        if (isset($zaakArray['zaaktype']) === false) {
            // throw new \Exception('No zaaktype set on zaak');.
            return ['response' => $data];
        }

        if (isset($zaakArray['zaaktype']['_self']['id']) === false) {
            // throw new Exception('ZaakType id not found on Zaak object');.
            return ['response' => $data];
        }

        $zaakTypeId     = $zaakArray['zaaktype']['_self']['id'];
        $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
        $casetypeId     = $zaakTypeObject->getSynchronizations()[0]->getSourceId() ?? null;
        // Return here cause if the zaaktype is created through this gateway, we cant sync it to xxllnc because it doesn't exist there
        if (isset($casetypeId) === false) {
            return ['response' => $data];
        }

        if (isset($zaakArray['_self']['id']) === false) {
            // throw new \Exception('No id on zaak'); // meaning it didnt properly save in the gateway
            return ['response' => $data];
        }

        $zaakObject = $this->entityManager->find('App:ObjectEntity', $zaakArray['_self']['id']);

        if (isset($zaakObject) === false) {
            return ['response' => $data];
        }

        $zaakArray = $zaakObject->toArray();

        $this->mapZGWToXxllnc($casetypeId, $zaakTypeObject, $zaakArray);

        return ['response' => $data];

    }//end updateZaakWithEigenschapHandler()


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


    // @todo Remove once updating zaak completely works (@Barry Brands)
    // /**
    // * Creates or updates a ZGW Zaak from a xxllnc casetype with the use of mapping.
    // *
    // * @param array $data          Data from the handler where the xxllnc casetype is in.
    // * @param array $configuration Configuration from the Action where the Zaak entity id is stored in.
    // *
    // * @return array $this->data Data which we entered the function with
    // */
    // public function mapUpdateZaak(array $data, array $configuration): array
    // {
    // if (!isset($data['response']['_self']['id'])) {
    // return $data;
    // }
    // validate object type
    // $objectEntity = $this->entityManager->find('App:ObjectEntity', $data['response']['_self']['id']);
    // if (!in_array($objectEntity->getEntity()->getName(), ['ZaakEigenschap'])) {
    // return $data;
    // }
    // $this->data = $data['response'];
    // $this->configuration = $configuration;
    // $this->data = $objectEntity->toArray();
    // isset($this->configuration['entities']['XxllncZaakPost']) && $xxllncZaakPostEntity = $this->entityRepo->find($this->configuration['entities']['XxllncZaakPost']);
    // if (!isset($xxllncZaakPostEntity)) {
    // throw new \Exception('XxllncZaakPost entity not found, check MapUpdateZaak config');
    // }
    // if (!isset($this->data['zaak'])) {
    // throw new \Exception('Zaak not set on given object');
    // }
    // if (isset($this->data['zaak']['zaaktype'])) {
    // isset($this->data['zaak']['zaaktype']['_self']['id']) && $zaakTypeId = $this->data['zaak']['zaaktype']['_self']['id'];
    // $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
    // $casetypeId = $zaakTypeObject->getExternalId();
    // Return here cause if the zaaktype is created through this gateway, we cant sync it to xxllnc because it doesn't exist there
    // if (!isset($casetypeId)) {
    // return $this->data;
    // }
    // } else {
    // throw new \Exception('ZaakType not set on Zaak');
    // }
    // @TODO update SyncUpdateZaak config with Zaak externalId?
    // $values = $this->entityManager->getRepository('App:Value')->findBy(['stringValue' => $this->data['zaak']['_self']['id']]);
    // foreach ($values as $value) {
    // if ($value->getObjectEntity()->getEntity()->getId()->toString() == $xxllncZaakPostEntity->getId()->toString() && $value->getAttribute()->getName() == 'zgwZaak') {
    // $externalId = $value->getObjectEntity()->getExternalId();
    // $zaakArray = $value->getObjectEntity()->toArray();
    // break;
    // }
    // }
    // if (!isset($externalId)) {
    // throw new \Exception('Earlier external id from xxllnc not found');
    // }
    // $zaakObject = $this->entityManager->find('App:ObjectEntity', $this->data['zaak']['_self']['id']);
    // if (!$zaakObject instanceof ObjectEntity) {
    // throw new \Exception('Zaak object not found with id:' . $this->data['zaak']['_self']['id']);
    // }
    // $zaakArray = $zaakObject->toArray();
    // $xxllncZaakArray = $this->mapZGWToXxllnc($casetypeId, $zaakTypeObject, $zaakArray, $xxllncZaakPostEntity, false);
    // return ['response' => $xxllncZaakArray, 'entity' => $xxllncZaakPostEntity->getId()->toString()];
    // }


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
            $method          = 'PUT';
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
                'zgwZaak',
            ];
        }//end if

        // If we have dont have a sync or sourceId we can do a post.
        if ($synchronization === null || ($synchronization && $synchronization->getSourceId() !== null)) {
            $method          = 'POST';
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

        // Send the POST/PUT request to xxllnc.
        var_dump(json_encode($caseArray));die;
        try {
            isset($this->style) === true && $this->style->info($logMessage);
            $response = $this->callService->call($this->xxllncAPI, $endpoint, $method, ['form_params' => $caseArray]);
            $result   = $this->callService->decodeResponse($this->xxllncAPI, $response);
            $caseId   = $result['result']['reference'] ?? null;
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to $method case, message:  {$e->getMessage()}");
            var_dump($e->getMessage());

            return false;
        }//end try
            var_dump($caseId);die;

        return $caseId ?? false;

    }//end sendCaseToXxllnc()


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
        $caseArray['zgwZaak']         = $zaakArrayObject['_self']['id'];
        $caseArray['casetype_id']     = $casetypeId;
        $caseArray['source']          = 'behandelaar';
        $caseArray['confidentiality'] = 'public';

        $eigenschappenCollection = $zaakTypeObject->getValue('eigenschappen');
        if ($eigenschappenCollection instanceof PersistentCollection) {
            $eigenschappenCollection = $eigenschappenCollection->toArray();
        }

        // Manually map subobjects
        $caseArray = $this->mapPostEigenschappen($caseArray, $zaakArrayObject, $eigenschappenCollection);
        $caseArray = $this->mapPostInfoObjecten($caseArray, $zaakArrayObject);
        // $caseArray = $this->mapPostRollen($caseArray, $zaakArrayObject); // disabled for now.
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

        if ($caseObject->getSynchronizations()) {
            $synchronization = $caseObject->getSynchronizations()[0];
        }

        $caseObject->hydrate($caseArray);
        $this->entityManager->persist($caseObject);
        $caseArray = $caseObject->toArray();

        $caseArray['requestor'] = [
            'id'   => '999991723',
            'type' => 'person',
        ];

        $sourceId               = $this->sendCaseToXxllnc($caseArray, $synchronization ?? null);

        if (($sourceId && isset($synchronization) === false) || (isset($synchronization) === true && $synchronization->getSourceId() === null)) {
            $synchronization = new Synchronization();
            $synchronization->setEntity($this->xxllncZaakSchema);
        }

        $synchronization->setSourceId($sourceId);
        $synchronization->setSource($this->xxllncAPI);
        $synchronization->setObject($caseObject);

        $this->entityManager->persist($synchronization);
        $this->entityManager->flush();

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
     * Creates or updates a ZGW Zaak from a xxllnc casetype with the use of mapping.
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

        var_dump('test2');

        isset($this->style) === true && $this->style->success('zgwToXxllnc triggered');

        $this->hasRequiredGatewayObjects();

        if (isset($this->data['zaaktype']) === false) {
            var_dump("isset($this->data['zaaktype']) === false");die;
            return ['response' => []];
        }

        var_dump($this->data['zaaktype']);
        if (isset($this->data['embedded']['zaaktype']['_self']['id']) === false &&
            isset($this->data['zaaktype']) === false) {
            var_dump("isset($this->data['embedded']['zaaktype']['_self']['id']) === false");die;
            return ['response' => []];
        }

        $zaakTypeId     = substr($this->data['zaaktype'], strrpos($this->data['zaaktype'], '/') + 1) ?? $this->data['embedded']['zaaktype']['_self']['id'];
        $zaakTypeObject = $this->entityManager->find('App:ObjectEntity', $zaakTypeId);
        $casetypeId     = $zaakTypeObject->getSynchronizations()[0]->getSourceId() ?? null;
        // Return here cause if the zaaktype is created through this gateway, we cant sync it to xxllnc because it doesn't exist there
        if (isset($casetypeId) === false) {
            var_dump("isset($casetypeId) === false");die;
            return $this->data;
        }

        if (isset($this->data['_self']['id']) === false) {
            var_dump("isset($this->data['_self']['id']) === false");die;
            return ['response' => []];
        }

        $zaakArrayObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id']);

        if (isset($zaakArrayObject) === false) {
            var_dump("isset($zaakArrayObject) === false");die;
            return ['response' => []];
        }

        $zaakArrayObject = $zaakArrayObject->toArray();

        $xxllncZaakArrayObject = $this->mapZGWToXxllnc($casetypeId, $zaakTypeObject, $zaakArrayObject);

        return ['response' => $zaakArrayObject];

    }//end zgwToXxllncHandler()


}//end class
