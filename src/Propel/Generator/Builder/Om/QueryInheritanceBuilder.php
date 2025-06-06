<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om;

use Propel\Generator\Builder\Util\EntityObjectClassNames;
use Propel\Generator\Exception\BuildException;
use Propel\Generator\Model\Inheritance;
use Propel\Generator\Model\Table;

/**
 * Generates the empty stub query class for use with single table
 * inheritance.
 *
 * This class produces the empty stub class that can be customized with
 * application business logic, custom behavior, etc.
 *
 * @author François Zaninotto
 */
class QueryInheritanceBuilder extends AbstractOMBuilder
{
    /**
     * @var \Propel\Generator\Builder\Util\EntityObjectClassNames
     */
    protected EntityObjectClassNames $tableNames;

    /**
     * @param \Propel\Generator\Model\Table $table
     */
    public function __construct(Table $table)
    {
        parent::__construct($table);
        $this->tableNames = $this->referencedClasses->useEntityObjectClassNames($table);
    }

    /**
     * The current child "object" we are operating on.
     *
     * @var \Propel\Generator\Model\Inheritance|null
     */
    protected $child;

    /**
     * Returns the name of the current class being built.
     *
     * @return string
     */
    #[\Override]
    public function getUnprefixedClassName(): string
    {
        return $this->getNewStubQueryInheritanceBuilder($this->getChild())->getUnprefixedClassName();
    }

    /**
     * Gets the package for the [base] object classes.
     *
     * @return string
     */
    #[\Override]
    public function getPackage(): string
    {
        return ($this->getChild()->getPackage() ?: parent::getPackage()) . '.Base';
    }

    /**
     * Gets the namespace for the [base] object classes.
     *
     * @return string|null
     */
    #[\Override]
    public function getNamespace(): ?string
    {
        $namespace = parent::getNamespace();

        return $namespace ? "$namespace\\Base" : 'Base';
    }

    /**
     * Sets the child object that we're operating on currently.
     *
     * @param \Propel\Generator\Model\Inheritance $child
     *
     * @return void
     */
    public function setChild(Inheritance $child): void
    {
        $this->child = $child;
    }

    /**
     * Returns the child object we're operating on currently.
     *
     * @throws \Propel\Generator\Exception\BuildException
     *
     * @return \Propel\Generator\Model\Inheritance
     */
    public function getChild(): Inheritance
    {
        if (!$this->child) {
            throw new BuildException('The MultiExtendObjectBuilder needs to be told which child class to build (via setChild() method) before it can build the stub class.');
        }

        return $this->child;
    }

    /**
     * Returns classpath to parent class.
     *
     * @return string|null
     */
    protected function getParentClassName(): ?string
    {
        if ($this->getChild()->getAncestor() === null) {
            return $this->getNewStubQueryBuilder($this->getTable())->getUnqualifiedClassName();
        }

        $ancestorClassName = ClassTools::classname($this->getChild()->getAncestor());
        if ($this->getDatabase()->hasTableByPhpName($ancestorClassName)) {
            return $this->getNewStubQueryBuilder($this->getDatabase()->getTableByPhpName($ancestorClassName))->getUnqualifiedClassName();
        }

        // find the inheritance for the parent class
        foreach ($this->getTable()->getChildrenColumn()->getChildren() as $child) {
            if ($child->getClassName() == $ancestorClassName) {
                return $this->getNewStubQueryInheritanceBuilder($child)->getUnqualifiedClassName();
            }
        }

        return null;
    }

    /**
     * Adds class phpdoc comment and opening of class.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    #[\Override]
    protected function addClassOpen(string &$script): void
    {
        $table = $this->getTable();
        $tableName = $table->getName();
        $tableDesc = $table->getDescription();

        $baseBuilder = $this->getStubQueryBuilder();
        $this->declareClassFromBuilder($baseBuilder);
        $baseClassName = $this->getParentClassName();

        $script .= "
/**
 * Skeleton subclass for representing a query for one of the subclasses of the
 * '$tableName' table.
 *";
        if ($tableDesc) {
            $script .= "
 *
 * $tableDesc";
        }
        if ($this->getBuildProperty('generator.objectModel.addTimeStamp')) {
            $now = strftime('%c');
            $script .= "
 *
 * This class was autogenerated by Propel " . $this->getBuildProperty('general.version') . " on:
 *
 * $now
 *";
        }
        $script .= "
 * You should add additional methods to this class to meet the application
 * requirements.
 * 
 * This class will only be generated as long as it does not already exist in
 * the output directory.
 */
class " . $this->getUnqualifiedClassName() . ' extends ' . $baseClassName . "
{";
    }

    /**
     * Specifies the methods that are added as part of the stub object class.
     *
     * By default there are no methods for the empty stub classes; override this
     * method if you want to change that behavior.
     *
     * @see ObjectBuilder::addClassBody()
     *
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    protected function addClassBody(string &$script): void
    {
        $this->declareClassFromBuilder($this->getTableMapBuilder());
        $this->declareClasses(
            '\Propel\Runtime\Connection\ConnectionInterface',
            '\Propel\Runtime\ActiveQuery\Criteria',
        );
        $this->addFactory($script);
        $this->addPreSelect($script);
        $this->addPreUpdate($script);
        $this->addPreDelete($script);
        $this->addDoDeleteAll($script);
    }

    /**
     * Adds the factory for this object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addFactory(string &$script): void
    {
        $builder = $this->getNewStubQueryInheritanceBuilder($this->getChild());
        $queryClassName = $this->declareClassFromBuilder($builder, 'Child');
        $queryClassNameFq = $builder->getFullyQualifiedClassName();

        $script .= "
    /**
     * Returns a new $queryClassName object.
     *
     * @param string|null \$modelAlias The alias of a model in the query
     * @param \Propel\Runtime\ActiveQuery\Criteria|null \$criteria Optional Criteria to build the query from
     *
     * @return $queryClassNameFq
     */
    public static function create(?string \$modelAlias = null, ?Criteria \$criteria = null): Criteria
    {
        if (\$criteria instanceof $queryClassName) {
            return \$criteria;
        }
        \$query = new $queryClassName();
        if (\$modelAlias !== null) {
            \$query->setModelAlias(\$modelAlias);
        }
        if (\$criteria !== null) {
            \$query->mergeWith(\$criteria);
        }

        return \$query;
    }\n";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addPreSelect(string &$script): void
    {
        $childClassName = $this->child->getClassName();

        $script .= "
    /**
     * Filters the query to target only $childClassName objects.
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con a connection object
     *
     * @return void
     */
    public function preSelect(ConnectionInterface \$con): void
    {
        " . $this->getClassKeyCondition() . "
    }\n";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addPreUpdate(string &$script): void
    {
        $childClassName = $this->child->getClassName();

        $script .= "
    /**
     * Filters the query to target only $childClassName objec
     *
     * @param array \$values The associative array of columns and values for the update
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con The connection object used by the query
     * @param bool \$forceIndividualSaves If false (default), the resulting call is a Criteria::doUpdate(), otherwise it is a series of save() calls on all the found objects
     *
     * @return int|null
     */
    public function preUpdate(&\$values, ConnectionInterface \$con, \$forceIndividualSaves = false): ?int
    {
        " . $this->getClassKeyCondition() . "

        return null;
    }\n";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addPreDelete(string &$script): void
    {
        $childClassName = $this->child->getClassName();

        $script .= "
    /**
     * Filters the query to target only $childClassName objects.
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con a connection object
     *
     * @return int|null
     */
    public function preDelete(ConnectionInterface \$con): ?int
    {
        " . $this->getClassKeyCondition() . "

        return null;
    }\n";
    }

    /**
     * @return string
     */
    protected function getClassKeyCondition(): string
    {
        $tableMapClassName = $this->getTableMapClassName();
        $columnConstant = $this->child->getConstantSuffix();
        $columnName = $this->child->getColumn()->getName();

        return "\$this->addUsingOperator(\$this->resolveLocalColumnByName('{$columnName}'), {$tableMapClassName}::CLASSKEY_{$columnConstant});";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addDoDeleteAll(string &$script): void
    {
        $childClassName = $this->child->getClassName();

        $script .= "
    /**
     * Issue a DELETE query based on the current ModelCriteria deleting all rows in the table
     * Having the $childClassName class.
     * This method is called by ModelCriteria::deleteAll() inside a transaction
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null \$con a connection object
     *
     * @return int The number of deleted rows
     */
    public function doDeleteAll(?ConnectionInterface \$con = null): int
    {
        // condition on class key is already added in preDelete()
        return parent::delete(\$con);
    }\n";
    }

    /**
     * Closes class.
     *
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    protected function addClassClose(string &$script): void
    {
        $script .= "}\n";
    }
}
