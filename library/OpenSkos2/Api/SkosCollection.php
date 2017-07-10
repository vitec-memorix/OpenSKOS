<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace OpenSkos2\Api;

class SkosCollection extends AbstractTripleStoreResource
{
    public function __construct(
        \OpenSkos2\SkosCollectionManager $manager,
        \OpenSkos2\PersonManager $personManager
    ) {
    
        $this->manager = $manager;
        $this->init = $this->manager->getInitArray();
        $this->deletion_integrity_check = new \OpenSkos2\IntegrityCheck($manager);
        $this->personManager = $personManager;
    }
}
