<?php

namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Attribute;
use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use Doctrine\ORM\EntityManagerInterface;

class HydrationService
{

    /**
     * @var SynchronizationService The synchronization service.
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var EntityManagerInterface The entity manager.
     */
    private EntityManagerInterface $entityManager;


    /**
     * The constructor of the service.
     *
     * @param SynchronizationService $synchronizationService
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(SynchronizationService $synchronizationService, EntityManagerInterface $entityManager)
    {
        $this->synchronizationService = $synchronizationService;
        $this->entityManager          = $entityManager;

    }//end __construct()


    /**
     * Recursively loop through an object, check if a synchronisation exists or create one (if necessary).
     *
     * @param array  $object The object array ready for hydration.
     * @param Source $source The source the objects need to be connected to.
     * @param Entity $entity The entity of the (sub)object.
     *
     * @return array|ObjectEntity The resulting object or array.
     */
    public function searchAndReplaceSynchronizations(array $object, Source $source, Entity $entity)
    {
        foreach ($object as $key => $value) {
            if (is_array($value) == true) {
                $subEntity = $entity;
                if($entity->getAttributeByName($key) !== false && $entity->getAttributeByName($key) !== null && $entity->getAttributeByName($key)->getObject() !== null) {
                    $subEntity = $entity->getAttributeByName($key)->getObject();
                }
                $object[$key] = $this->searchAndReplaceSynchronizations($value, $source, $subEntity);
            } elseif ($key === '_sourceId') {
                $synchronization = $this->synchronizationService->findSyncBySource($source, $entity, $value);
            }
        }

        if(isset($synchronization) === true) {
            if($synchronization->getObject() instanceof ObjectEntity === false) {
                $synchronization->setObject(new ObjectEntity($entity));
            }

            $synchronization->getObject()->hydrate($object);
            $this->entityManager->persist($synchronization->getObject());
            $this->entityManager->persist($synchronization);

            $this->entityManager->flush();
            $this->entityManager->flush();

            return $synchronization->getObject();
        }

        return $object;
    }//end searchAndReplaceSynchronizations()


}//end class
