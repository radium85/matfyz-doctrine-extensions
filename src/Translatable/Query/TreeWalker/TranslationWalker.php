<?php

namespace Gedmo\Translatable\Query\TreeWalker;

use Gedmo\Translatable\Mapping\Event\Adapter\ORM as TranslatableEventAdapter;
use Gedmo\Translatable\TranslatableListener;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\Exec\SingleSelectExecutor;
use Doctrine\ORM\Query\AST\RangeVariableDeclaration;
use Doctrine\ORM\Query\AST\Join;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;

/**
 * The translation sql output walker makes it possible
 * to translate all query components during single query.
 * It works with any select query, any hydration method.
 *
 * Behind the scenes, during the object hydration it forces
 * custom hydrator in order to interact with TranslatableListener
 * and skip postLoad event which would cause automatic retranslation
 * of the fields.
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class TranslationWalker extends SqlWalker
{
    /**
     * Name for translation fallback hint
     *
     * @internal
     */
    const HINT_TRANSLATION_FALLBACKS = '__gedmo.translatable.stored.fallbacks';

    /**
     * Customized object hydrator name
     *
     * @internal
     */
    const HYDRATE_OBJECT_TRANSLATION = '__gedmo.translatable.object.hydrator';

    /**
     * Customized object hydrator name
     *
     * @internal
     */
    const HYDRATE_SIMPLE_OBJECT_TRANSLATION = '__gedmo.translatable.simple_object.hydrator';

    /**
     * Stores all component references from select clause
     *
     * @var array
     */
    private $translatedComponents = array();

    /**
     * DBAL database platform
     *
     * @var \Doctrine\DBAL\Platforms\AbstractPlatform
     */
    private $platform;

    /**
     * DBAL database connection
     *
     * @var \Doctrine\DBAL\Connection
     */
    private $conn;

    /**
     * List of aliases to replace with translation
     * content reference
     *
     * @var array
     */
    private $replacements = array();

    /**
     * List of joins for translated components in query
     *
     * @var array
     */
    private $components = array();

    /**
     * {@inheritDoc}
     */
    public function __construct($query, $parserResult, array $queryComponents)
    {
        parent::__construct($query, $parserResult, $queryComponents);
        $this->conn = $this->getConnection();
        $this->platform = $this->getConnection()->getDatabasePlatform();
        $this->listener = $this->getTranslatableListener();
        $this->extractTranslatedComponents($queryComponents);
    }

    /**
     * {@inheritDoc}
     */
    public function getExecutor($AST)
    {
        if (!$AST instanceof SelectStatement) {
            throw new \Gedmo\Exception\UnexpectedValueException('Translation walker should be used only on select statement');
        }
        $this->prepareTranslatedComponents();

        return new SingleSelectExecutor($AST, $this);
    }

    /**
     * {@inheritDoc}
     */
    public function walkSelectStatement(SelectStatement $AST)
    {
        $result = parent::walkSelectStatement($AST);
        if (!count($this->translatedComponents)) {
            return $result;
        }

        $hydrationMode = $this->getQuery()->getHydrationMode();
        if ($hydrationMode === Query::HYDRATE_OBJECT) {
            $this->getQuery()->setHydrationMode(self::HYDRATE_OBJECT_TRANSLATION);
            $this->getEntityManager()->getConfiguration()->addCustomHydrationMode(
                self::HYDRATE_OBJECT_TRANSLATION,
                'Gedmo\\Translatable\\Hydrator\\ORM\\ObjectHydrator'
            );
            $this->getQuery()->setHint(Query::HINT_REFRESH, true);
        } elseif ($hydrationMode === Query::HYDRATE_SIMPLEOBJECT) {
            $this->getQuery()->setHydrationMode(self::HYDRATE_SIMPLE_OBJECT_TRANSLATION);
            $this->getEntityManager()->getConfiguration()->addCustomHydrationMode(
                self::HYDRATE_SIMPLE_OBJECT_TRANSLATION,
                'Gedmo\\Translatable\\Hydrator\\ORM\\SimpleObjectHydrator'
            );
            $this->getQuery()->setHint(Query::HINT_REFRESH, true);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function walkSelectClause($selectClause)
    {
        $result = parent::walkSelectClause($selectClause);
        $result = $this->replace($this->replacements, $result);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function walkFromClause($fromClause)
    {
        $result = parent::walkFromClause($fromClause);
        $result .= $this->joinTranslations($fromClause);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function walkWhereClause($whereClause)
    {
        $result = parent::walkWhereClause($whereClause);

        return $this->replace($this->replacements, $result);
    }

    /**
     * {@inheritDoc}
     */
    public function walkHavingClause($havingClause)
    {
        $result = parent::walkHavingClause($havingClause);

        return $this->replace($this->replacements, $result);
    }

    /**
     * {@inheritDoc}
     */
    public function walkOrderByClause($orderByClause)
    {
        $result = parent::walkOrderByClause($orderByClause);

        return $this->replace($this->replacements, $result);
    }

    /**
     * {@inheritDoc}
     */
    public function walkSubselect($subselect)
    {
        $result = parent::walkSubselect($subselect);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function walkSubselectFromClause($subselectFromClause)
    {
        $result = parent::walkSubselectFromClause($subselectFromClause);
        $result .= $this->joinTranslations($subselectFromClause);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function walkSimpleSelectClause($simpleSelectClause)
    {
        $result = parent::walkSimpleSelectClause($simpleSelectClause);

        return $this->replace($this->replacements, $result);
    }

    /**
     * {@inheritDoc}
     */
    public function walkGroupByClause($groupByClause)
    {
        $result = parent::walkGroupByClause($groupByClause);

        return $this->replace($this->replacements, $result);
    }

    /**
     * Walks from clause, and creates translation joins
     * for the translated components
     *
     * @param  \Doctrine\ORM\Query\AST\FromClause $from
     * @return string
     */
    private function joinTranslations($from)
    {
        $result = '';
        foreach ($from->identificationVariableDeclarations as $decl) {
            if ($decl->rangeVariableDeclaration instanceof RangeVariableDeclaration) {
                if (isset($this->components[$decl->rangeVariableDeclaration->aliasIdentificationVariable])) {
                    $result .= $this->components[$decl->rangeVariableDeclaration->aliasIdentificationVariable];
                }
            }
            if (isset($decl->joinVariableDeclarations)) {
                foreach ($decl->joinVariableDeclarations as $joinDecl) {
                    if ($joinDecl->join instanceof Join) {
                        if (isset($this->components[$joinDecl->join->aliasIdentificationVariable])) {
                            $result .= $this->components[$joinDecl->join->aliasIdentificationVariable];
                        }
                    }
                }
            } else {
                // based on new changes
                foreach ($decl->joins as $join) {
                    if ($join instanceof Join) {
                        if (isset($this->components[$join->joinAssociationDeclaration->aliasIdentificationVariable])) {
                            $result .= $this->components[$join->joinAssociationDeclaration->aliasIdentificationVariable];
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Creates a left join list for translations
     * on used query components
     *
     * @todo: make it cleaner
     * @return string
     */
    private function prepareTranslatedComponents()
    {
        $q = $this->getQuery();
        $locale = $q->getHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE);
        if (!$locale) {
            // use from listener
            $locale = $this->listener->getListenerLocale();
        }
        $defaultLocale = $this->listener->getDefaultLocale();
        if ($locale === $defaultLocale  && !$this->listener->getPersistDefaultLocaleTranslation()) {
            // Skip preparation as there's no need to translate anything
            return;
        }
        $em = $this->getEntityManager();
        $ea = new TranslatableEventAdapter();
        $ea->setEntityManager($em);
        $joinStrategy = $q->getHint(TranslatableListener::HINT_INNER_JOIN) ? 'INNER' : 'LEFT';

        foreach ($this->translatedComponents as $dqlAlias => $comp) {
            $meta = $comp['metadata'];
            $config = $this->listener->getConfiguration($em, $meta->name);
            $transClass = $this->listener->getTranslationClass($ea, $meta->name);
            $transMeta = $em->getClassMetadata($transClass);
            $transTable = $em->getConfiguration()->getQuoteStrategy()->getTableName($transMeta, $this->platform);
            foreach ($config['fields'] as $field) {
                $compTblAlias = $this->walkIdentificationVariable($dqlAlias, $field);
                $tblAlias = $this->getSQLTableAlias('trans'.$compTblAlias.$field);
                $sql = " {$joinStrategy} JOIN ".$transTable.' '.$tblAlias;
                $sql .= ' ON '.$tblAlias.'.'.$transMeta->getQuotedColumnName('locale', $this->platform)
                    .' = '.$this->conn->quote($locale);
                $sql .= ' AND '.$tblAlias.'.'.$transMeta->getQuotedColumnName('field', $this->platform)
                    .' = '.$this->conn->quote($field);
                $identifier = $meta->getSingleIdentifierFieldName();
                $idColName = $meta->getQuotedColumnName($identifier, $this->platform);
                if ($ea->usesPersonalTranslation($transClass)) {
                    $sql .= ' AND '.$tblAlias.'.'.$transMeta->getSingleAssociationJoinColumnName('object')
                        .' = '.$compTblAlias.'.'.$idColName;
                } else {
                    $sql .= ' AND '.$tblAlias.'.'.$transMeta->getQuotedColumnName('objectClass', $this->platform)
                        .' = '.$this->conn->quote($config['useObjectClass']);

                    $mappingFK = $transMeta->getFieldMapping('foreignKey');
                    $mappingPK = $meta->getFieldMapping($identifier);
                    $fkColName = $this->getCastedForeignKey($compTblAlias.'.'.$idColName, $mappingFK['type'], $mappingPK['type']);
                    $sql .= ' AND '.$tblAlias.'.'.$transMeta->getQuotedColumnName('foreignKey', $this->platform)
                        .' = '.$fkColName;
                }
                isset($this->components[$dqlAlias]) ? $this->components[$dqlAlias] .= $sql : $this->components[$dqlAlias] = $sql;

                $originalField = $compTblAlias.'.'.$meta->getQuotedColumnName($field, $this->platform);
                $substituteField = $tblAlias.'.'.$transMeta->getQuotedColumnName('content', $this->platform);

                // Treat translation as original field type
                $fieldMapping = $meta->getFieldMapping($field);
                if ((($this->platform instanceof MySqlPlatform) &&
                    in_array($fieldMapping["type"], array("decimal"))) ||
                    (!($this->platform instanceof MySqlPlatform) &&
                    !in_array($fieldMapping["type"], array("datetime", "datetimetz", "date", "time", "text")))) {
                    $type = Type::getType($fieldMapping["type"]);
                    $substituteField = 'CAST('.$substituteField.' AS '.$type->getSQLDeclaration($fieldMapping, $this->platform).')';
                }

                // Fallback to original if was asked for
                if (($this->needsFallback() && (!isset($config['fallback'][$field]) || $config['fallback'][$field]))
                    ||  (!$this->needsFallback() && isset($config['fallback'][$field]) && $config['fallback'][$field])
                ) {
                    $substituteField = 'COALESCE('.$substituteField.', '.$originalField.')';
                }

                $this->replacements[$originalField] = $substituteField;
            }
        }
    }

    /**
     * Checks if translation fallbacks are needed
     *
     * @return boolean
     */
    private function needsFallback()
    {
        $q = $this->getQuery();
        $fallback = $q->getHint(TranslatableListener::HINT_FALLBACK);
        if (false === $fallback) {
            // non overrided
            $fallback = $this->listener->getTranslationFallback();
        }

        // applies fallbacks to scalar hydration as well
        return (bool) $fallback;
    }

    /**
     * Search for translated components in the select clause
     *
     * @param array $queryComponents
     */
    private function extractTranslatedComponents(array $queryComponents)
    {
        $em = $this->getEntityManager();
        foreach ($queryComponents as $alias => $comp) {
            if (!isset($comp['metadata'])) {
                continue;
            }
            $meta = $comp['metadata'];
            $config = $this->listener->getConfiguration($em, $meta->name);
            if ($config && isset($config['fields'])) {
                $this->translatedComponents[$alias] = $comp;
            }
        }
    }

    /**
     * Get the currently used TranslatableListener
     *
     * @throws \Gedmo\Exception\RuntimeException - if listener is not found
     *
     * @return TranslatableListener
     */
    private function getTranslatableListener()
    {
        $em = $this->getEntityManager();
        foreach ($em->getEventManager()->getListeners() as $event => $listeners) {
            foreach ($listeners as $hash => $listener) {
                if ($listener instanceof TranslatableListener) {
                    return $listener;
                }
            }
        }

        throw new \Gedmo\Exception\RuntimeException('The translation listener could not be found');
    }

    /**
     * Replaces given sql $str with required
     * results
     *
     * @param array  $repl
     * @param string $str
     *
     * @return string
     */
    private function replace(array $repl, $str)
    {
        foreach ($repl as $target => $result) {
            $str = preg_replace_callback('/(\s|\()('.$target.')(,?)(\s|\))/smi', function ($m) use ($result) {
                return $m[1].$result.$m[3].$m[4];
            }, $str);
        }

        return $str;
    }

    /**
     * Casts a foreign key if needed
     * @NOTE: personal translations manages that for themselves.
     *
     * @param $component - a column with an alias to cast
     * @param $typeFK - translation table foreign key type
     * @param $typePK - primary key type which references translation table
     * @return string - modified $component if needed
     */
    private function getCastedForeignKey($component, $typeFK, $typePK)
    {
        // the keys are of same type
        if ($typeFK === $typePK) {
            return $component;
        }

        // try to look at postgres casting
        if ($this->platform instanceof PostgreSqlPlatform) {
            switch ($typeFK) {
            case 'string':
            case 'guid':
                // need to cast to VARCHAR
                $component = $component . '::VARCHAR';
                break;
            }
        }

        // @TODO may add the same thing for MySQL for performance to match index

        return $component;
    }
}
