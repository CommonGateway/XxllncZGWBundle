<?php

// src/Service/PetStoreService.php

namespace CommonGateway\PetStoreBundle\Service;

class PetStoreService
{

    /*
     * Returns a welcoming string
     * 
     * @return array 
     */
    public function petStoreHandler(array $data, array $configuration): array
    {
        return ['response' => 'Hello. Your PetStoreBundle works'];
    }
}
