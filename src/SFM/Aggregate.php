<?php

abstract class SFM_Aggregate extends SFM_Business implements Iterator, Countable, SFM_Transaction_Restorable
{
    /**
     * __wakeup load all object by id 
     */
    const LOAD_ENTITY_OBJECTS = 'LOAD_ENTITY_OBJECTS';
    /**
     * __wakeup don't load objects
     */
    const LOAD_ONLY_IDS = 'LOAD_ONLY_IDS';

    /**
     * default value for paginator
     */
    const ITEMS_PER_PAGE = 10;

    /**
     * Array of Business objects (e.g., User, Article, Resume, etc.) 
     * key - entity id
     * val - entity object
     * @var array
     */
    protected $entities = array();
    /**
     * Contain all loaded entity id of current aggregate. Simplify exists check  
     * @var array
     */
    protected $loadedListEntityId = array();
    /**
     * Contain all entity id of current aggregate 
     * @var array
     */
    protected $listEntityId = array();
    /**
     * Key for Cache to store Aggregate.
     * Must be generated by Mapper and passed to Aggregate's contructor method
     * 
     * @var string
     */
    protected $cacheKey;
    
    /**
     *
     * @var SFM_Mapper
     */
    protected $mapper;

    protected $objectState = array();
    
    /**
     * Constructor
     *
     * @param array         $aggregateProto  Entity ids or prototypes array
     * @param SFM_Mapper    $mapper          Mapper Object
     * @param null|string   $cacheKey        Cache key for aggregate
     * @param bool          $loadEntities    Should load aggregate entities
     *
     * @throws SFM_Exception_Aggregate
     */
    public function __construct(array $aggregateProto, SFM_Mapper $mapper, $cacheKey = null, $loadEntities = false)
    {
        $this->mapper = $mapper;
        $this->cacheKey = $cacheKey;
        $this->entities = array();

        $idField = $this->mapper->getIdField();
        
        foreach ($aggregateProto as $entityProto) {

            if (false === is_array($entityProto)) {
                $entityProto = array($idField => $entityProto);
            } else if (false === array_key_exists($idField, $entityProto)) {
                throw new SFM_Exception_Aggregate('Entity proto does not contain id');
            }

            $this->listEntityId[] = $entityProto[$idField];

            if (count($entityProto) > 1) {
                $entity = $mapper->createEntity($entityProto);
                $this->entities[$entity->getId()] = $entity;
                $this->loadedListEntityId[$entity->getId()] = $entity->getId();
            }
        }
        
        if ($loadEntities && false === $this->isAllEntitiesLoaded()) {
            $this->loadEntities();
        }
    }

    /**
     * Function of Iterator interface
     */
    public function rewind()
    {
        return empty($this->loadedListEntityId) ? false : reset($this->loadedListEntityId);
    }

    /**
     * Function of Iterator interface
     */
    public function next()
    {
        /* changed by A-25. It is more correct (no notices) */
        if (empty($this->entities))
            return false;

        $next = next($this->loadedListEntityId);
        if ($next === false)
            return false;

        return $this->entities[$next];
        /* return empty($this->entities) ? false : $this->entities[ next($this->loadedListEntityId) ]; */
    }

    /**
     * Function of Iterator interface
     */
    public function key()
    {
        return empty($this->loadedListEntityId) ? false : key($this->loadedListEntityId);
    }

    /**
     * Function of Iterator interface
     */
    public function current()
    {
        /* changed by A-25. It is more correct (no notices) */
        if (empty($this->entities))
            return false;

        $current = current($this->loadedListEntityId);
        if ($current === false)
            return false;

        return $this->entities[$current];
        //return empty($this->loadedListEntityId) ? false : $this->entities[ current($this->loadedListEntityId) ];   
    }

    /**
     * Function of Iterator interface
     */
    public function valid()
    {
        return empty($this->loadedListEntityId) ? false : current($this->loadedListEntityId) !== false;
    }

    /**
     * Function of Countable interface
     */
    public function count()
    {
        return empty($this->loadedListEntityId) ? 0 : count($this->loadedListEntityId);
    }

    /**
     * Total number of elements in Aggregate (include loaded and not loaded objects)
     */
    public function totalCount()
    {
        return count($this->listEntityId);
    }

    public function isEmpty()
    {
        $c = $this->totalCount();
        if (0 == $c) {
            return true;
        } else {
            return false;
        }
    }

    protected function saveObjectState()
    {
        $this->objectState = array(
            'loadedListEntityId' => $this->loadedListEntityId,
            'listEntityId'       => $this->listEntityId
        );
    }

    public function push(SFM_Entity $entity)
    {
        $this->saveObjectState();

        $this->entities[$entity->getId()] = $entity;
        if (!in_array($entity->getId(), $this->listEntityId)) {
            array_push($this->listEntityId, $entity->getId());
        }
        if (!in_array($entity->getId(), $this->loadedListEntityId)) {
            array_push($this->loadedListEntityId, $entity->getId());
        }
        $this->mapper->updateAggregate($this);
    }

    public function unshift(SFM_Entity $entity)
    {
        $this->saveObjectState();

        $this->entities[$entity->getId()] = $entity;
        if (!in_array($entity->getId(), $this->listEntityId)) {
            array_unshift($this->listEntityId, $entity->getId());
        }
        if (!in_array($entity->getId(), $this->loadedListEntityId)) {
            array_unshift($this->loadedListEntityId, $entity->getId());
        }
        $this->mapper->updateAggregate($this);
    }

    public function remove(SFM_Entity $entity)
    {
        $this->saveObjectState();

        $entityKey = array_search($entity, $this->entities);
        if ($entityKey !== false) {
            $this->splice($this->entities, $entityKey, 1);
        }
        $entityIdKey = array_search($entity->getId(), $this->listEntityId);
        if($entityIdKey !== false) {
            $this->splice($this->listEntityId, $entityIdKey, 1);
        }
        $entityLoadedKey = array_search($entity->getId(), $this->loadedListEntityId);
        if ($entityLoadedKey !== false) {
            $this->splice($this->loadedListEntityId, $entityLoadedKey, 1);
        }

        $this->mapper->updateAggregate($this);
    }

    protected function splice(&$array, $offset, $length)
    {
        $return = array_slice($array, $offset, $length, true);
        foreach ($return as $key => $value) {
            unset($array[$key]);
        }
        return $return;
    }

    /**
     * Return array of entities
     */
    public function getContent()
    {
        return $this->entities;
    }

    /**
     * Returns key in Cache for this Aggregate
     * Unlike to Entities, Aggregates don't have id field. 
     * That's why Mapper has to generate key for Aggregate immediately after fetching Aggregate content from DB.
     * Then key is stored in Aggregate.
     * 
     * @return string
     */
    public function getCacheKey()
    {
        return $this->cacheKey;
    }

    /**
     * Returns array of tags, which values influence on Aggreagate cached value
     * @see http://www.smira.ru/2008/10/29/web-caching-memcached-5/
     * 
     * @return array
     */
    public function getCacheTags()
    {
        $tags = array();

        if (!$this->isAllEntitiesLoaded()) {
            //prevent acess to empty $this->entities
            $tags = $this->mapper->getCacheKeysByEntitiesId($this->listEntityId);
        } else {
            foreach ($this->entities as $entity) {
                $tags = array_merge($tags, $entity->getCacheTags());
            }
        }
        return $tags;
    }

    /**
     * @return SFM_Entity
     */
    public function getRandomEntity()
    {
        $entity = null;
        if (false === empty($this->loadedListEntityId)) {
            $pointer = array_rand($this->loadedListEntityId, 1);
            $entity = $this->getEntityById($this->loadedListEntityId[$pointer]);
        }

        return $entity;
    }

    public function __sleep()
    {
        return array('listEntityId', 'cacheKey');
    }

    public function __wakeup()
    {
        $mapperClassName = str_replace('Aggregate', 'Mapper', get_class($this));
        $this->mapper = call_user_func(array($mapperClassName, 'getInstance'));

        $this->entities = array();
    }

    public function getListEntitiesId()
    {
        return $this->listEntityId;
    }

    /**
     * Check, is all entity objects loaded
     *
     * @return boolean
     */
    public function isAllEntitiesLoaded()
    {
        if (sizeof($this->loadedListEntityId) == sizeof($this->listEntityId)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Load All entity objects to aggregate
     * @returns $this
     */
    public function loadEntities()
    {
        $this->loadEntitiesByIds($this->listEntityId);

        return $this;
    }

    /**
     * @param int $pageNum
     * @param int|null $perPage
     * @returns $this
     */
    public function loadEntitiesForCurrentPage($pageNum, $perPage = null)
    {
        if ($perPage === null)
            $perPage = $this->getItemsPerPage();
            
        $itpp = $perPage;
        --$pageNum;
        $ids = array_slice($this->getListEntitiesId(), $pageNum * $itpp, $itpp);
        $this->loadEntitiesByIds($ids);

	return $this;
    }

    public function getItemsPerPage()
    {
        /**
         * @FIXME hack, rewrite it if PHP version > 5.3.0 
         * @see http://ru.php.net/manual/en/language.oop5.constants.php 
         */
        $class = get_class($this);
        eval("\$a = $class::ITEMS_PER_PAGE;");
        return $a;
    }

    /**
     * Load entity objects to aggregate
     * Note: new objects add to the end of array, it's may be completly different from $this->listEntityId order
     *
     * @param array $entityId
     * @return void
     */
    protected function loadEntitiesByIds(array $entityId)
    {
        $notLoaded = array_diff($entityId, $this->loadedListEntityId);
        $newEntities = $this->mapper->getMultiEntitiesByIds($notLoaded);

        $tmp = array();
        $num = sizeof($newEntities);
        for ($i = 0; $i < $num; $i++) {
            $tmp[$newEntities[$i]->getId()] = $i;
        }

        foreach ($notLoaded as $id) {
            $entity = isset($tmp[$id]) && isset($newEntities[$tmp[$id]]) ? $newEntities[$tmp[$id]] : null;
            if (isset($entity)) {
                $this->loadedListEntityId[] = $id;
                $this->entities[$id] = $entity;
            }
        }
    }

    /**
     * Updates all Entities in Aggreagte
     */
    public function update(array $params)
    {
        foreach ($this->entities as $id => $entity) {
            $entity->update($params);
        }
    }

    /**
     * Provides sorting aggregate by value of $fieldName of each entity
     *
     * @param string $fieldName
     * @param bool $asc direction of sorting
     */
    public function sort($fieldName, $asc=true)
    {
        $sortedArray = array();
        foreach ($this->entities as $id => $entity) {
            $sortedArray [$entity->$fieldName] = $entity->getId();
        }
        $asc ? ksort($sortedArray) : krsort($sortedArray);
        $this->loadedListEntityId = array_values($sortedArray);
    }

    /**
     * Performing preloading of computed values for all entity objects.
     * Can be used before assigning to Template to avoid errors in view code 
     *
     * @param array $fieldNames Array of fields to be loaded
     */
    public function preloadComputedForEntities(array $fieldNames)
    {
        foreach ($this->entities as $entity) {
            $entity->preloadComputed($fieldNames);
        }
    }

    /**
     * Just call function provided by argument.  
     * For example to load all lazy load objects.
     *
     * @param string $getterName function name without ()
     * @return void
     */
    public function preloadDependencyByCallback($getterName)
    {
        foreach ($this->entities as $entity) {
            $entity->{$getterName}();
        }
    }

    /**
     * Calls delete() method of every Entity in Aggregate
     * @return bool true on success, false otherwise
     */
    public function delete()
    {
        $result = $this->callEntities('delete');
        return $result;
    }

    /**
     * Filters Entities by prototype values
     * @param array $filters Array of key => value
     * @return SFM_Aggregate
     */
    public function filter(array $matches=array(), array $disagrees=array())
    {
        if (count($matches) == 0 && count($disagrees) == 0) {
            return $this;
        }

        $filteredProto = array();
        foreach ($this->entities as $entity) {
            $isValid = true;

            foreach ($matches as $key => $value) {
                if ($entity->$key != $value) {
                    //TODO exit after first false (continue|break)
                    $isValid = false;
                }
            }

            foreach ($disagrees as $key => $value) {
                if ($entity->$key == $value) {
                    //TODO exit after first false (continue|break)
                    $isValid = false;
                }
            }

            if ($isValid) {
                $filteredProto [] = $entity->getProto();
            }
        }

        $className = get_class($this);

        return new $className($filteredProto, $this->mapper);
    }

    /**
     * 
     * @param int $id
     * @return SFM_Entity
     */
    public function getEntityById($id)
    {
        if (!in_array($id, $this->listEntityId)) {
            return null;
        }

        foreach ($this->entities as $entity) {
            if ($entity->getId() == $id) {
                return $entity;
            }
        }
    }

    public function __toString()
    {
        return get_class($this) . ' of ' . $this->count() . ' element(s)';
    }

    /**
     * Calls all Entities. Returns array of responces
     * @param string $func
     * @param array $params
     * @return array
     */
    protected function callEntities($func, $params=array())
    {
        if (!$this->isAllEntitiesLoaded()) {
            $this->loadEntities();
        }

        $result = array();

        foreach ($this->entities as $entity) {
            $result[] = call_user_func_array(array($entity, $func), $params);
        }

        return $result;
    }
    
    /**
     * Combines several aggregates to one.
     * @param SFM_Mapper $mapper
     * @param array of SFM_Aggregate $aggregates
     * @param string|null $cacheKey 
     */
    public static function combine(SFM_Mapper $mapper,array $aggregates,$cacheKey = null)
    {
        $entityIds = array();
        foreach($aggregates as $aggregate){
            $entityIds = array_merge($entityIds,$aggregate->getListEntitiesId());
        }
        $aggregate = $mapper->createAggregate($entityIds,$cacheKey);
        return $aggregate; 
    }
    
    /**
     * Returns if the entity exists in aggregate
     * @param SFM_Entity $entity
     * @param boolean $onlyLoaded If true, entity is searched only in loaded entities
     */
    public function entityExists(SFM_Entity $entity, $onlyLoaded = false)
    {
        $index = $this->getEntityIndex($entity,$onlyLoaded);
        return $index !== null;
    }
    
    /**
     * Returns entity index
     * @param SFM_Entity $entity
     * @param boolean $onlyLoaded If true, entity is searched only in loaded entities
     * 
     * @return integer
     */
    public function getEntityIndex(SFM_Entity $entity, $onlyLoaded = false)
    {
        $exists = in_array($entity->getId(), $this->getListEntitiesId());
        if(!$exists){
            return null;
        }
        if($onlyLoaded && !isset($this->entities[$entity->getId()])){
            return null;
        }
            
        return array_search($entity->getId(), $this->getListEntitiesId());
    }
    
    public function clearLoadedEntities()
    {
        $this->entities = array();
        $this->loadedListEntityId = array();
    }
    
    /**
     * Закольцевать агрегат, начиная с определенного места.
     * Например, есть 5 элементов, надо закольцевать, начиная с 3.
     * В результате получается 3,4,5,1,2.
     * @param integer $offset
     * @return SFM_Aggregate     
     */
    public function recircle($offset)
    {
        $entityIds = $this->getListEntitiesId();
        $sortedEntityIds = array_slice($entityIds,$offset - 1,count($entityIds) - ($offset - 1));
        $sortedEntityIds = array_merge($sortedEntityIds,array_slice($entityIds,0,$offset - 1));
        return $this->mapper->createAggregate($sortedEntityIds,null,true);
    }

    public function getObjectIdentifier()
    {
        $identifier = $this->getCacheKey() ? $this->getCacheKey() : spl_object_hash($this);

        return $identifier;
    }

    public function getObjectState()
    {
        return $this->objectState;
    }

    public function restoreObjectState($state)
    {
        $this->listEntityId = $state['listEntityId'];
        $this->loadedListEntityId = $state['loadedListEntityId'];
    }
}
