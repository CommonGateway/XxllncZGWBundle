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
use Exception;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;

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
     * Executes the prepare file request to xxllnc.
     *
     * The prepare file request is needed to send the inhoud of a enkelvoudiginformatieobject and retrieve a reference id to connect it to a xxllnc case.
     *
     * @param array  $infoObject
     * @param Source $xxllncApi
     *
     * @return string|null $reference File reference.
     */
    private function prepareFile(array $infoObject, Source $xxllncApi)
    {
        if (isset($infoObject['informatieobject']['_self']['id']) === false) {
            return null;
        }

        $infoObjectEntity = $this->entityManager->find('App:ObjectEntity', $infoObject['informatieobject']['_self']['id']);
        if ($infoObjectEntity instanceof ObjectEntity === false) {
            return null;
        }

        $base64 = $infoObjectEntity->getValueObject('inhoud')->getFiles()->first()->getBase64();
        // $base64 = \Safe\base64_decode($infoObjectEntity->getValueObject('inhoud')->getFiles()->first()->getBase64());
        $file_stream = $base64;
        // $file_stream = Utils::streamFor($base64);
        $multipart = [
            [
                'name'     => 'upload',
                'contents' => $file_stream,
                'filename' => $infoObject['informatieobject']['bestandsnaam'],
            ],
        ];

        // Send the POST request to xxllnc.
        var_dump('Request prepare file:');
        $config = [
            'headers'   => ['Content-Type' => 'multipart/form-data'],
            'debug'     => true,
            'multipart' => $multipart,
        ];
        // try {
            $response = $this->callService->call($xxllncApi, '/case/prepare_file', 'POST', $config);
            $result   = $this->callService->decodeResponse($xxllncApi, $response);
            var_dump('Rerefence id: ', array_key_first($result['result']['instance']['references']));
            $reference = array_key_first($result['result']['instance']['references']) ?? null;
        // } catch (Exception $e) {
        // var_dump($e->getMessage());
        // return null;
        // }//end try
        return $reference ?? null;

    }//end prepareFile()


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
            var_dump('Document number: ', $result['result']['instance']['serial']);
            $documentNumber = $result['result']['instance']['serial'] ?? null;
        } catch (Exception $e) {
            var_dump($e->getMessage());
            return false;
        }//end try

        return $documentNumber ?? false;

    }//end reserveDocumentNumber()


    /**
     * Gets first sync object for given object or creates new one.
     *
     * @param ObjectEntity $object
     * @param Source       $xxllncApi
     *
     * @return Synchronization
     */
    private function getSynchronization(ObjectEntity $object, Source $xxllncApi): Synchronization
    {
        $synchronizations = $this->entityManager->getRepository('App:Synchronization')->findBy(['object' => $object]);
        if (empty($synchronizations) === true) {
            return new Synchronization(
                $xxllncApi,
                $object->getEntity()
            );
        } else {
            return $synchronizations->first();
        }

    }//end getSynchronization()


    /**
     * Gets sync for zaakInformatieObject and gets documentNumber or file reference from xxllnc if needed.
     *
     * @param array  $objectArray
     * @param Source $xxllncApi
     *
     * @return string|null Nothing.
     */
    public function checkCustomNumber(array $zaakInfoObject, Source $xxllncApi, ?string $type = 'zaakInfoObject'): ?string
    {
        switch ($type) {
        case 'zaakInfoObject':
            $object = $this->entityManager->find('App:ObjectEntity', $zaakInfoObject['_self']['id']);
            break;
        case 'enkelvoudigInfoObject':
            $object = $this->entityManager->find('App:ObjectEntity', $zaakInfoObject['informatieobject']['_self']['id']);
            break;
        default:
            return null;
        }

        $synchronization = $this->getSynchronization($object, $xxllncApi);

        if ($synchronization->getSourceId() === null) {
            switch ($type) {
            case 'zaakInfoObject':
                $customNumber = $this->reserveDocumentNumber($xxllncApi);
                break;
            case 'enkelvoudigInfoObject':
                $customNumber = $this->prepareFile($zaakInfoObject, $xxllncApi);
                break;
            default:
                return null;
            }

            $synchronization->setSourceId($customNumber);

            $this->entityManager->persist($synchronization);
            $this->entityManager->flush();

            return $customNumber;
        }

        return null;

    }//end checkCustomNumber()


}//end class
