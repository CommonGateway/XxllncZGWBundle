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

use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Exception;


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
    private ObjectRepository $sourceRepo;

    /**
     * @var Source|null
     */
    public ?Source $xxllncAPI;


    /**
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService
    ) {
        $this->entityManager = $entityManager;
        $this->callService   = $callService;

        $this->sourceRepo = $this->entityManager->getRepository('App:Gateway');

    }//end __construct()


    /**
     *
     * @return string|false $documentNumber Document number else false.
     */
    private function reserveDocumentNumber()
    {
        // Send the POST request to xxllnc.
        try {
            $response = $this->callService->call($this->xxllncAPI, '/document/reserve_number', 'POST');
            $result   = $this->callService->decodeResponse($this->xxllncAPI, $response);
            var_dump($result);
            $documentNumber = $result['result']['reference'] ?? null;
        } catch (Exception $e) {
            var_dump($e->getMessage());
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
     * Gets sync for zaakInformatieObject and gets documentNumber from xxllnc if needed.
     *
     * @param ObjectEntity $zaakInformatieObject
     */
    public function checkDocumentNumber(ObjectEntity $zaakInformatieObject)
    {
        $synchronization = $this->getSynchronization($zaakInformatieObject);

        if ($synchronization->getSourceId() === null) {
            $documentNumber = $this->reserveDocumentNumber();
            var_dump($documentNumber);
            $synchronization->setSourceId($documentNumber);

            $this->entityManager->persist($synchronization);
            $this->entityManager->flush();
        }

    }//end checkDocumentNumber()


}//end class
