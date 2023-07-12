<?php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

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
     * __construct.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService
    ) {
        $this->entityManager = $entityManager;
        $this->callService   = $callService;

    }//end __construct()


    /**
     * Reserves a document number at the xxllnc api.
     *
     * A document number is needed to save the actual file/document/informatieobject.
     *
     * @param Source $xxllncApi
     *
     * @return string|false $documentNumber Document number else false.
     */
    private function reserveDocumentNumber(Source $xxllncApi)
    {
        // Send the POST request to xxllnc.
        try {
            $response = $this->callService->call($xxllncApi, '/document/reserve_number', 'POST');
            $result   = $this->callService->decodeResponse($xxllncApi, $response);
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
     * @param ObjectEntity $infoObject
     * @param Source       $xxllncApi
     *
     * @return Synchronization
     */
    private function getSynchronization(ObjectEntity $zaakInfoObject, Source $xxllncApi): Synchronization
    {
        $synchronizations = $this->entityManager->getRepository('App:Synchronization')->findBy(['object' => $zaakInfoObject]);
        if (empty($synchronizations) === true) {
            return new Synchronization(
                $xxllncApi,
                $zaakInfoObject->getEntity()
            );
        } else {
            return $synchronizations->first();
        }

    }//end getSynchronization()


    /**
     * Gets sync for zaakInformatieObject and gets documentNumber from xxllnc if needed.
     *
     * @param ObjectEntity $zaakInformatieObject
     * @param Source       $xxllncApi
     *
     * @return void Nothing.
     */
    public function checkDocumentNumber(string $zaakInfoId, Source $xxllncApi): void
    {
        $zaakInfoObject  = $this->entityManager->find('App:ObjectEntity', $zaakInfoId);
        $synchronization = $this->getSynchronization($zaakInfoObject, $xxllncApi);

        if ($synchronization->getSourceId() === null) {
            $documentNumber = $this->reserveDocumentNumber($xxllncApi);
            var_dump($documentNumber);
            $synchronization->setSourceId($documentNumber);

            $this->entityManager->persist($synchronization);
            $this->entityManager->flush();
        }

    }//end checkDocumentNumber()


}//end class
