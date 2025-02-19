<?php

namespace Gedmo\Tree;

use Doctrine\Common\EventArgs;
use Gedmo\Mapping\MappedEventSubscriber;
use Doctrine\Common\Persistence\ObjectManager;

/**
 * The tree listener handles the synchronization of
 * tree nodes. Can implement different
 * strategies on handling the tree.
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class TreeListener extends MappedEventSubscriber
{
    /**
     * {@inheritDoc}
     */
    protected $ignoredFilters = array(
        'Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter'
    );

    /**
     * Tree processing strategies for object classes
     *
     * @var array
     */
    private $strategies = array();

    /**
     * List of strategy instances
     *
     * @var array
     */
    private $strategyInstances = array();

    /**
     * List of used classes on flush
     *
     * @var array
     */
    private $usedClassesOnFlush = array();

    /**
     * Specifies the list of events to listen
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            'prePersist',
            'preRemove',
            'preUpdate',
            'onFlush',
            'loadClassMetadata',
            'postPersist',
            'postUpdate',
            'postRemove',
        );
    }

    /**
     * Get the used strategy for tree processing
     *
     * @param ObjectManager $om
     * @param string        $class
     *
     * @return Strategy
     */
    public function getStrategy(ObjectManager $om, $class)
    {
        if (!isset($this->strategies[$class])) {
            $config = $this->getConfiguration($om, $class);
            if (!$config) {
                throw new \Gedmo\Exception\UnexpectedValueException("Tree object class: {$class} must have tree metadata at this point");
            }
            $managerName = 'UnsupportedManager';
            if ($om instanceof \Doctrine\ORM\EntityManager) {
                $managerName = 'ORM';
            } elseif ($om instanceof \Doctrine\ODM\MongoDB\DocumentManager) {
                $managerName = 'ODM\\MongoDB';
            }
            if (!isset($this->strategyInstances[$config['strategy']])) {
                $strategyClass = $this->getNamespace().'\\Strategy\\'.$managerName.'\\'.ucfirst($config['strategy']);

                if (!class_exists($strategyClass)) {
                    throw new \Gedmo\Exception\InvalidArgumentException($managerName." TreeListener does not support tree type: {$config['strategy']}");
                }
                $this->strategyInstances[$config['strategy']] = new $strategyClass($this);
            }
            $this->strategies[$class] = $config['strategy'];
        }

        return $this->strategyInstances[$this->strategies[$class]];
    }

    /**
     * Looks for Tree objects being updated
     * for further processing
     *
     * @param EventArgs $args
     */
    public function onFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        $this->disableFilters($om);
        // check all scheduled updates for TreeNodes
        foreach ($ea->getScheduledObjectInsertions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if ($this->getConfiguration($om, $meta->name)) {
                $this->usedClassesOnFlush[$meta->name] = null;
                $this->getStrategy($om, $meta->name)->processScheduledInsertion($om, $object, $ea);
                $ea->recomputeSingleObjectChangeSet($uow, $meta, $object);
            }
        }

        foreach ($ea->getScheduledObjectUpdates($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if ($this->getConfiguration($om, $meta->name)) {
                $this->usedClassesOnFlush[$meta->name] = null;
                $this->getStrategy($om, $meta->name)->processScheduledUpdate($om, $object, $ea);
            }
        }

        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if ($this->getConfiguration($om, $meta->name)) {
                $this->usedClassesOnFlush[$meta->name] = null;
                $this->getStrategy($om, $meta->name)->processScheduledDelete($om, $object);
            }
        }

        foreach ($this->getStrategiesUsedForObjects($this->usedClassesOnFlush) as $strategy) {
            $strategy->onFlushEnd($om, $ea);
        }
        $this->enableFilters($om);
    }

    /**
     * Updates tree on Node removal
     *
     * @param EventArgs $args
     */
    public function preRemove(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));

        if ($this->getConfiguration($om, $meta->name)) {
            $this->disableFilters($om);
            $this->getStrategy($om, $meta->name)->processPreRemove($om, $object);
            $this->enableFilters($om);
        }
    }

    /**
     * Checks for persisted Nodes
     *
     * @param EventArgs $args
     */
    public function prePersist(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));

        if ($this->getConfiguration($om, $meta->name)) {
            $this->disableFilters($om);
            $this->getStrategy($om, $meta->name)->processPrePersist($om, $object);
            $this->enableFilters($om);
        }
    }

    /**
     * Checks for updated Nodes
     *
     * @param EventArgs $args
     */
    public function preUpdate(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));

        if ($this->getConfiguration($om, $meta->name)) {
            $this->disableFilters($om);
            $this->getStrategy($om, $meta->name)->processPreUpdate($om, $object);
            $this->enableFilters($om);
        }
    }

    /**
     * Checks for pending Nodes to fully synchronize
     * the tree
     *
     * @param EventArgs $args
     */
    public function postPersist(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));

        if ($this->getConfiguration($om, $meta->name)) {
            $this->disableFilters($om);
            $this->getStrategy($om, $meta->name)->processPostPersist($om, $object, $ea);
            $this->enableFilters($om);
        }
    }

    /**
     * Checks for pending Nodes to fully synchronize
     * the tree
     *
     * @param EventArgs $args
     */
    public function postUpdate(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));

        if ($this->getConfiguration($om, $meta->name)) {
            $this->disableFilters($om);
            $this->getStrategy($om, $meta->name)->processPostUpdate($om, $object, $ea);
            $this->enableFilters($om);
        }
    }

    /**
     * Checks for pending Nodes to fully synchronize
     * the tree
     *
     * @param EventArgs $args
     */
    public function postRemove(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));

        if ($this->getConfiguration($om, $meta->name)) {
            $this->disableFilters($om);
            $this->getStrategy($om, $meta->name)->processPostRemove($om, $object, $ea);
            $this->enableFilters($om);
        }
    }

    /**
     * Mapps additional metadata
     *
     * @param EventArgs $eventArgs
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $om = $ea->getObjectManager();
        $meta = $eventArgs->getClassMetadata();
        $this->loadMetadataForObjectClass($om, $meta);
        if (isset(self::$configurations[$this->name][$meta->name]) && self::$configurations[$this->name][$meta->name]) {
            $this->getStrategy($om, $meta->name)->processMetadataLoad($om, $meta);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }

    /**
     * Get the list of strategy instances used for
     * given object classes
     *
     * @param array $classes
     *
     * @return Strategy[]
     */
    protected function getStrategiesUsedForObjects(array $classes)
    {
        $strategies = array();
        foreach ($classes as $name => $opt) {
            if (isset($this->strategies[$name]) && !isset($strategies[$this->strategies[$name]])) {
                $strategies[$this->strategies[$name]] = $this->strategyInstances[$this->strategies[$name]];
            }
        }

        return $strategies;
    }
}
