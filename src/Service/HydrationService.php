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
    private SynchronizationService $synchronizationService;
    private EntityManagerInterface $entityManager;

    public function __construct(SynchronizationService $synchronizationService, EntityManagerInterface $entityManager)
    {
        $this->synchronizationService = $synchronizationService;
        $this->entityManager          = $entityManager;
    }

    /**
     * Checks if an array is associative.
     *
     * @param array $array The array to check
     *
     * @return bool Whether or not the array is associative
     */
    private function isAssociative(array $array): bool
    {
        if ([] === $array) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    public function searchAndReplaceSynchronizations(array $object, Source $source, Entity $entity)
    {
        foreach ($object as $key => $value) {
            if (is_array($value) == true) {
                $subEntity = $entity;
                if($this->isAssociative($object) === true) {
                    $attribute = $entity->getAttributeByName($key);
                    $attribute instanceof Attribute && $attribute->getObject() ?? $subEntity = $entity;
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

            return $synchronization->getObject()->getId()->toString();
        }

        return $object;
    }
}
