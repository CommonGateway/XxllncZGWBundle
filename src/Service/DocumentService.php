<?php
/**
 * This class handles the synchronization of a zgw drc objectinformatieobject to a xxllnc document.
 *
 * By mapping, posting and creating a synchronization. Only works if the ztc zaaktype also exists in the xxllnc api.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Service
 */

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;
use CommonGateway\XxllncZGWBundle\Service\ZGWToXxllncService;


class DocumentService
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
     * @var ZGWToXxllncService
     */
    private ZGWToXxllncService $zgwToXxllncService;

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
        CallService $callService,
        ZGWToXxllncService $zgwToXxllncService
    ) {
        $this->entityManager      = $entityManager;
        $this->callService        = $callService;
        $this->zgwToXxllncService = $zgwToXxllncService;

        $this->schemaRepo = $this->entityManager->getRepository('App:Entity');
        $this->sourceRepo = $this->entityManager->getRepository('App:Gateway');

    }//end __construct()


    /**
     *
     * @return string|false $documentNumber Document number else false.
     */
    private function getZaakObject()
    {

        return $zaakObject;

    }//end getZaakObject()


    /**
     *
     * @return string|false $documentNumber Document number else false.
     */
    private function reserveDocumentNumber()
    {
        // Send the POST request to xxllnc.
        try {
            $response       = $this->callService->call($this->xxllncAPI, '/document/reserve_number', 'POST');
            $result         = $this->callService->decodeResponse($this->xxllncAPI, $response);
            $documentNumber = $result['result']['reference'] ?? null;
        } catch (Exception $e) {
            return false;
        }//end try

        return $documentNumber ?? false;

    }//end reserveDocumentNumber()


    /**
     * Gets first sync object for objectinformatieobject or creates new one.
     *
     * @return Synchronization
     */
    private function getSynchronization(ObjectEntity $infoObject): Synchronization
    {
        $synchronizations = $this->entityManager->getRepository('App:Synchronization')->findBy(['object' => $infoObject]);
        if (empty($synchronizations) === true) {
            return new Synchronization(
                $this->sourceRepo->findOneBy(['reference' => 'https://development.zaaksysteem.nl/source/xxllnc.zaaksysteem.source.json']),
                $infoObject->getEntity()
            );
        } else {
            return $synchronizations->first();
        }

    }//end getSynchronization()


    /**
     * Reserves a document number at xxllnc api, creates a synchronization object with
     * that number, so we can later add this objectinformatieobject to the case we send to xxllnc api.
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

        $objectInformatieObject = $this->entityManager->find('App:ObjectEntity', $this->data['_self']['id']);
        $zaakObject             = $objectInformatieObject->getValue('object');

        $synchronization = $this->getSynchronization($objectInformatieObject);

        $documentNumber = $this->reserveDocumentNumber();
        $synchronization->setSourceId($documentNumber);

        $this->entityManager->persist($synchronization);
        $this->entityManager->flush();

        $this->zgwToXxllncService->data = $zaakObject->toArray();

        return ['response' => $this->zgwToXxllncService->syncZaakToXxllnc()];

    }//end fileToXxllncHandler()


}//end class
