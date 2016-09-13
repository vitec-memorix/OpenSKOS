<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace OpenSkos2\Api;

use OpenSkos2\Api\Exception\ApiException;
use OpenSkos2\Api\Exception\InvalidArgumentException;
use OpenSkos2\Api\Exception\InvalidPredicateException;
use OpenSkos2\Api\Exception\NotFoundException;
use OpenSkos2\Api\Response\ResultSet\JsonpResponse;
use OpenSkos2\Api\Response\ResultSet\JsonResponse;
use OpenSkos2\Api\Response\ResultSet\RdfResponse;
use OpenSkos2\Api\Transform\DataRdf;
use OpenSkos2\ConceptManager;
use OpenSkos2\Concept as ConceptResource;
use OpenSkos2\RelationManager;
use OpenSkos2\FieldsMaps;
use OpenSkos2\Namespaces;
use OpenSkos2\Namespaces\Skos;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Namespaces\NamespaceAdmin;
use OpenSkos2\Search\Autocomplete;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ServerRequestInterface as PsrServerRequestInterface;
use OpenSkos2\MyInstitutionModules\Authorisation;
use OpenSkos2\MyInstitutionModules\Deletion;
use OpenSkos2\MyInstitutionModules\Relations;
use Zend\Diactoros\Stream;
use Zend\Diactoros\Response;

require_once dirname(__FILE__) . '/../config.inc.php';
//require_once dirname(__FILE__) . '/../../../tools/Logging.php';


class Concept extends AbstractTripleStoreResource {

    /**
     * Search autocomplete
     *
     * @var Autocomplete
     */
    private $searchAutocomplete;

    public function __construct(ConceptManager $manager, Autocomplete $searchAutocomplete) {
        $this->manager = $manager;
        $this->searchAutocomplete = $searchAutocomplete;
        $this->authorisationManager = new Authorisation($manager);
        $this->deletionManager = new Deletion($manager);
    }

    /**
     * Map the following requests
     *
     * /api/find-concepts?q=Kim%20Holland
     * /api/find-concepts?&fl=prefLabel,scopeNote&format=json&q=inScheme:"http://uri"
     * /api/find-concepts?format=json&fl=uuid,uri,prefLabel,class,dc_title&id=http://data.beeldengeluid.nl/gtaa/27140
     * /api/concept/82c2614c-3859-ed11-4e55-e993c06fd9fe.rdf
     *
     * @param ServerRequestInterface $request
     * @param string $context
     * @return ResponseInterface
     */
    public function findConcepts(PsrServerRequestInterface $request, $context) {
        try {
            set_time_limit(120);
            $params = $request->getQueryParams();
            //\Tools\Logging::var_error_log(" params \n", $params , '/app/data/debug.txt');
        
            // offset
            $start = 0;
            if (!empty($params['start'])) {
                $start = (int) $params['start'];
            }

            // limit
            $limit = MAXIMAL_ROWS;
            if (isset($params['rows']) && $params['rows'] < MAXIMAL_ROWS) {
                $limit = (int) $params['rows'];
            }

            $options = [
                'start' => $start,
                'rows' => $limit,
                 'status' => [\OpenSkos2\Concept::STATUS_CANDIDATE, \OpenSkos2\Concept::STATUS_APPROVED],
            ];

            // search query
            if (isset($params['q'])) {
                $options['searchText'] = $params['q'];
            }

            
            if (isset($params['sorts'])) {
                $sortmap = $this->prepareSortsForSolr($params['sorts']);
                $options['sorts'] = $sortmap;
            }

            if (isset($params['skosCollection'])) {
                $options['skosCollection'] = explode(' ', trim($params['skosCollection']));
            }

           
            if (isset($params['sets'])) {
                $options['sets'] = explode(' ', trim($params['sets']));
            }

            $tenantCodes=[];     
            if (isset($params['tenant'])) {
                $tenantCodes = explode(' ', trim($params['tenant']));
            } else { // synomym parameter
                if (isset($params['tenants'])) {
                    $tenantCodes  = explode(' ', trim($params['tenants']));
                } else {
                    if (isset($params['tenantUri'])) {
                        $options['tenants'] = explode(' ', trim($params['tenantUri']));
                    }
                }
            }
            
            if (count($tenantCodes)>0) {
                foreach ($tenantCodes as $tenantcode) {
                    $tenantUri = $this->manager->fetchInstitutionUriByCode($tenantcode);
                    if ($tenantUri === null) {
                        throw new ApiException('The tenant referred by code ' . $tenantcode . ' does not exist in the triple store. ', 400);
                    };
                    $options['tenants'][]=$tenantUri;
                }
            }


            if (isset($params['conceptScheme'])) {
                $options['conceptScheme'] = explode(' ', trim($params['conceptScheme']));
            }

            if (isset($params['status'])) {
                $options['status'] = explode(' ', trim($params['status']));
            }

            //\Tools\Logging::var_error_log(" params \n", $params , '/app/data/debug.txt');
        
            $concepts = $this->searchAutocomplete->search($options, $total);

            foreach ($concepts as $concept) {
                $spec = $this->manager->fetchTenantSpec($concept);
                foreach ($spec as $tenant_and_set) {
                    $concept->addProperty(OpenSkos::SET, new \OpenSkos2\Rdf\Uri($tenant_and_set['seturi']));
                    $concept->addProperty(OpenSkos::TENANT, new \OpenSkos2\Rdf\Uri($tenant_and_set['tenanturi']));
                }
            }


            $result = new ResourceResultSet($concepts, $total, $start, $limit);

            if (isset($params['fl'])) {
                $propertiesList = $this->fieldsListToProperties($params['fl']);
            } else {
                $propertiesList = [];
            }
            switch ($context) {
                case 'json':
                    $response = (new JsonResponse($result, $propertiesList))->getResponse();
                    break;
                case 'jsonp':
                    $response = (new JsonpResponse($result, $params['callback'], $propertiesList))->getResponse();
                    break;
                case 'rdf':
                    $response = (new RdfResponse($result, $propertiesList))->getResponse();
                    break;
                default:
                    throw new InvalidArgumentException('Invalid context: ' . $context);
            }
            set_time_limit(30);
            return $response;
        } catch (Exception $ex) {
            return $this->getErrorResponseFromException($ex);

        }
    }

    /**
     * Gets a list (array or string) of fields and try to recognise the properties from it.
     * @param array $fieldsList
     * @return array
     */
    protected function fieldsListToProperties($fieldsList) {
        if (!is_array($fieldsList)) {
            $fieldsList = array_map('trim', explode(',', $fieldsList));
        }

        $propertiesList = [];
        //olha was here, it used to be getOldToProperties();
        $fieldsMap = FieldsMaps::getNamesToProperties();

        // Tries to search for the field in fields map.
        // If not found there tries to expand it from short property.
        // If not that - just pass it as it is.
        foreach ($fieldsList as $field) {
            if (!empty($field)) {
                if (isset($fieldsMap[$field])) {
                    $propertiesList[] = $fieldsMap[$field];
                } else {
                    $propertiesList[] = Namespaces::expandProperty($field);
                }
            }
        }

        // Check if we have a nice properties list at the end.
        foreach ($propertiesList as $propertyUri) {
            if ($propertyUri == 'uri') {
                continue;
            }
            if (filter_var($propertyUri, FILTER_VALIDATE_URL) == false) {
                throw new InvalidPredicateException(
                'The field "' . $propertyUri . '" from fields list is not recognised.'
                );
            }
        }

        return $propertiesList;
    }

    public function delete(PsrServerRequestInterface $request) {
        try {
            $params = $this->getAndAdaptQueryParams($request);
            if (!isset($params['id'])) {
                throw new InvalidArgumentException('Missing id parameter', 412);
            }

            $id = $params['id'];
            /* @var $concept Concept */
            $concept = $this->manager->fetchByUri($id);
            if (!$concept) {
                throw new NotFoundException('Concept not found by id :' . $id, 404);
            }
            if ($concept->isDeleted()) {
                 throw new NotFoundException('Concept already deleted :' . $id, 410);
             }

            $user = $this->getUserFromParams($params);

            $this->authorisationManager->resourceDeleteAllowed($user, $params['tenantcode'], $concept);

            $this->manager->deleteSoft($concept);
        } catch (Exception $e) {
            return $this->getErrorResponseFromException($e);
        }

        $xml = (new DataRdf($concept))->transform();
        return $this->getSuccessResponse($xml, 202);
    }

    
    protected function validate($resourceObject, $isForUpdate, $tenanturi) {
        parent::validate($resourceObject, $isForUpdate, $tenanturi);
        $this->checkRelationsInConcept($resourceObject);
    }

   


    // also, throws an exception when a poperty is not from a standar namespace and not a custom (user-defined) relation
    private function checkRelationsInConcept(ConceptResource $concept) {
        $userDefinedRelUris = array_values(Relations::$myrelations);
        $registeredRelationUris = array_values($this->manager->getUserRelationQNameUris());
        $allRelationUris = array_values(RelationManager::fetchConceptConceptRelationsNameUri());
        $conceptUri = $concept->getUri();
        $properties = array_keys($concept->getProperties());
        foreach ($properties as $property) {
            if (in_array($property, $allRelationUris)) { // is a relation 
                // if it is a user-defined, it must be registered
                if (in_array($property, $userDefinedRelUris)) { // is a user-defined relation
                    if (!in_array($property, $registeredRelationUris)) {
                        throw new ApiException('The relation  ' . $property . '  is not registered in the triple store. ', 400);
                    }
                }
                // cycle-check and duplication check
                $relatedConcepts = $concept->getProperty($property);
                foreach ($relatedConcepts as $relConcept) {
                    // check if related concept exists
                    $exists = $this->manager->resourceExists($relConcept, Skos::CONCEPT);
                    if (!$exists) {
                       throw new ApiException('The related concept  ' . $relConcept . '  does not exist. ', 400);
                    }
                    $this->manager->relationTripleIsDuplicated($conceptUri, $relConcept, $property);
                    $this->manager->relationTripleCreatesCycle($conceptUri, $relConcept, $property);
                }
            } else { // not a concept-concept relation, must be from a standard namespace
                if (!NamespaceAdmin::isPropertyFromStandardNamespace($property)) {
                    throw new ApiException('The property  ' . $property . '  does not belong to standart properties of a concepts and is not a user-defined relation. ', 400);
                }
            }
        }
        return true;
    }

    private function prepareSortsForSolr($sortstring) {
        $sortlist = explode(" ", $sortstring);
        $l = count($sortlist);
        $sortmap = [];
        $i = 0;
        while ($i < $l - 1) { // the last element will be worked-on after the loop is finished
            $j = $i;
            $i++;
            $sortfield = $this->prepareSortFieldForSolr($sortlist[$j]);
            $sortorder = 'asc';
            if ($sortlist[$i] === "asc" || $sortlist[$i] === 'desc') {
                $sortorder = $sortlist[$i];
                $i++;
            }
            $sortmap[$sortfield] = $sortorder;
        };
        if ($sortlist[$l - 1] !== 'asc' && $sortlist[$l - 1] !== 'desc') { // field name is the last and no order after it
            $sortfield = $this->prepareSortFieldForSolr($sortlist[$l - 1]); // Fix "@nl" to "_nl"
            $sortmap[$sortfield] = 'asc';
        };
        return $sortmap;
    }

    private function prepareSortFieldForSolr($term) { // translate field name  to am internal sort-field name
        if (substr($term, 0, 5) === "sort_" || substr($term, strlen($term) - 3, 1) === "_") { // is already an internal presentation ready for solr, starts with sort_* or *_langcode
            return $term;
        }
        if ($this->isDateField($term)) {
            return "sort_d_" . $term;
        } else {
            if (strpos($term, "@") !== false) {
                return str_replace("@", "_", $term);
            } else {
                return "sort_s_" . $term;
            }
        }
    }

    private function isDateField($term) {
        return ($term === "dateAccepted" || $term === "dateSubmitted" || $term === "modified");
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return Response
     */
    public function addRelationTriple(PsrServerRequestInterface $request) {
        $params = $this->getAndAdaptQueryParams($request);
        try {
            $body = $this->preEditChecksRels($request, $params, false);
            $this->manager->addRelationTriple($body['concept'], $body['type'], $body['related']);
        } catch (ApiException $exc) {
            return $this->getErrorResponse($exc->getCode(), $exc->getMessage());
        }

        $stream = new Stream('php://memory', 'wb+');
        $stream->write('Relations added');
        $response = (new Response())
                ->withBody($stream);
        return $response;
    }

    /**
     * @param PsrServerRequestInterface $request
     * @return Response
     */
    public function deleteRelationTriple(PsrServerRequestInterface $request) {
        try {
            $params = $this->getAndAdaptQueryParams($request); // sets tenant info
            $body = $this->preEditChecksRels($request, $params, true);
            $this->manager->deleteRelationTriple($body['concept'], $body['type'], $body['related']);
        } catch (Exception $e) {
            return $this->getErrorResponseFromException($e);
        }

        $stream = new Stream('php://memory', 'wb+');
        $stream->write('Relation deleted');
        $response = (new Response())
                ->withBody($stream);
        return $response;
    }

    private function preEditChecksRels(PsrServerRequestInterface $request, $params, $toBeDeleted) {

        $body = $request->getParsedBody();
        if (!isset($body['key'])) {
            throw new ApiException('Missing key', 400);
        }
        if (!isset($body['concept'])) {
            throw new ApiException('Missing concept', 400);
        }
        if (!isset($body['related'])) {
            throw new ApiException('Missing related', 400);
        }
        if (!isset($body['type'])) {
            throw new ApiException('Missing type', 400);
        }

        $exists1 = $this->manager->resourceExists($body['concept'], Skos::CONCEPT);
        if (!$exists1) {
            throw new ApiException('The concept referred by the uri ' . $body['concept'] . ' does not exist.', 400);
        }
        
        $exists2 = $this->manager->resourceExists($body['related'], Skos::CONCEPT);
        if (!$exists2) {
            throw new ApiException('The concept referred by the uri ' . $body['related'] . ' does not exist.', 400);
        }

        $userDefinedRelUris = array_values(Relations::$myrelations);
        $registeredRelationUris = array_values($this->manager->getUserRelationQNameUris());
        $allRelationUris = array_values(RelationManager::fetchConceptConceptRelationsNameUri());
        if (in_array($body['type'], $allRelationUris)) { // is a concept-concept relation 
            // if it is a user-defined, it must be registered
            if (in_array($body['type'], $userDefinedRelUris)) { // is a user-defined relation
                if (!in_array($body['type'], $registeredRelationUris)) {
                    throw new ApiException('The relation  ' . $body['type'] . '  is not registered in the triple store. ', 400);
                }
            }
        } else {
            throw new ApiException('The relation  ' . $body['type'] . '  is neither a skos concept-concept relation nor a user-defined relation. ', 400);
        }

        if (!$toBeDeleted) {
            $this->manager->relationTripleIsDuplicated($body['concept'], $body['related'], $body['type']);
            $this->manager->relationTripleCreatesCycle($body['concept'], $body['related'], $body['type']);
        }

        $user = $this->getUserByKey($body['key']);

        $concept = $this->manager->fetchByUri($body['concept'], $this->manager->getResourceType());
        $this->authorisationManager->resourceEditAllowed($user, $params['tenantcode'], $concept); // throws an exception if not allowed
        $relatedConcept = $this->manager->fetchByUri($body['related'], $this->manager->getResourceType());
        $this->authorisationManager->resourceEditAllowed($user, $params['tenantcode'], $relatedConcept); // throws an exception if not allowed

        return $body;
    }

}
