<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Reverse;

use PDO;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ColumnDefaultValue;
use Propel\Generator\Model\Database;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\Index;
use Propel\Generator\Model\PropelTypes;
use Propel\Generator\Model\Table;
use Propel\Generator\Model\Unique;
use RuntimeException;
use stdClass;

/**
 * Postgresql database schema parser.
 *
 * @author Hans Lellelid <hans@xmpl.org>
 */
class PgsqlSchemaParser extends AbstractSchemaParser
{
    /**
     * Map PostgreSQL native types to Propel types.
     *
     * @var array<string>
     */
    private static $pgsqlTypeMap = [
        'bool' => PropelTypes::BOOLEAN,
        'boolean' => PropelTypes::BOOLEAN,
        'tinyint' => PropelTypes::TINYINT,
        'smallint' => PropelTypes::SMALLINT,
        'mediumint' => PropelTypes::SMALLINT,
        'int2' => PropelTypes::SMALLINT,
        'int' => PropelTypes::INTEGER,
        'int4' => PropelTypes::INTEGER,
        'serial4' => PropelTypes::INTEGER,
        'integer' => PropelTypes::INTEGER,
        'int8' => PropelTypes::BIGINT,
        'bigint' => PropelTypes::BIGINT,
        'bigserial' => PropelTypes::BIGINT,
        'serial8' => PropelTypes::BIGINT,
        'int24' => PropelTypes::BIGINT,
        'real' => PropelTypes::REAL,
        'float' => PropelTypes::FLOAT,
        'float4' => PropelTypes::REAL,
        'decimal' => PropelTypes::DECIMAL,
        'numeric' => PropelTypes::DECIMAL,
        'double' => PropelTypes::DOUBLE,
        'float8' => PropelTypes::DOUBLE,
        'char' => PropelTypes::CHAR,
        'character' => PropelTypes::CHAR,
        'character varying' => PropelTypes::VARCHAR,
        'varchar' => PropelTypes::VARCHAR,
        'date' => PropelTypes::DATE,
        'time' => PropelTypes::TIME,
        'timetz' => PropelTypes::TIME,
        //'year' => PropelTypes::YEAR,  PropelTypes::YEAR does not exist... does this need to be mapped to a different propel type?
        'datetime' => PropelTypes::TIMESTAMP,
        'timestamp' => PropelTypes::TIMESTAMP,
        'timestamptz' => PropelTypes::TIMESTAMP,
        'bytea' => PropelTypes::BLOB,
        'text' => PropelTypes::LONGVARCHAR,
        'time without time zone' => PropelTypes::TIME,
        'time with time zone' => PropelTypes::TIME,
        'timestamp without time zone' => PropelTypes::TIMESTAMP,
        'timestamp with time zone' => PropelTypes::TIMESTAMP,
        'double precision' => PropelTypes::DOUBLE,
        'json' => PropelTypes::JSON,
        'uuid' => PropelTypes::UUID,
    ];

    /**
     * @var array<int>
     */
    protected static $defaultTypeSizes = [
        'char' => 1,
        'character' => 1,
        'integer' => 32,
        'bigint' => 64,
        'smallint' => 16,
        'double precision' => 54,
    ];

    /**
     * Gets a type mapping from native types to Propel types
     *
     * @return array<string>
     */
    #[\Override]
    protected function getTypeMapping(): array
    {
        return self::$pgsqlTypeMap;
    }

    /**
     * Parses a database schema.
     *
     * @param \Propel\Generator\Model\Database $database
     * @param array<\Propel\Generator\Model\Table> $additionalTables
     *
     * @return int
     */
    #[\Override]
    public function parse(Database $database, array $additionalTables = []): int
    {
        $tableWraps = [];

        $this->parseTables($tableWraps, $database);
        foreach ($additionalTables as $table) {
            $this->parseTables($tableWraps, $database, $table);
        }

        // Now populate only columns.
        foreach ($tableWraps as $wrap) {
            $this->addColumns($wrap->table, $wrap->oid);
        }

        // Now add indexes and constraints.
        foreach ($tableWraps as $wrap) {
            $this->addForeignKeys($wrap->table, $wrap->oid);
            $this->addIndexes($wrap->table, $wrap->oid);
            $this->addPrimaryKey($wrap->table, $wrap->oid);
        }

        $this->addSequences($database);

        return count($tableWraps);
    }

    /**
     * @param array $tableWraps
     * @param \Propel\Generator\Model\Database $database
     * @param \Propel\Generator\Model\Table|null $filterTable
     *
     * @return void
     */
    protected function parseTables(array &$tableWraps, Database $database, ?Table $filterTable = null): void
    {
        $params = [];

        $sql = "
          SELECT c.oid, c.relname, n.nspname
          FROM pg_class c join pg_namespace n on (c.relnamespace=n.oid)
          WHERE c.relkind = 'r'
            AND n.nspname NOT IN ('information_schema','pg_catalog')
            AND n.nspname NOT LIKE 'pg_temp%'
            AND n.nspname NOT LIKE 'pg_toast%'";

        if ($filterTable) {
            $schema = $filterTable->getSchema();
            if ($schema) {
                $sql .= ' AND n.nspname = ?';
                $params[] = $schema;
            }

            $sql .= ' AND c.relname = ?';
            $params[] = $filterTable->getCommonName();
        } elseif (!$database->getSchema()) {
            /** @var \PDOStatement $stmt */
            $stmt = $this->dbh->query('SELECT schema_name FROM information_schema.schemata');
            $searchPath = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $searchPath[] = $row['schema_name'];
            }

            foreach ($searchPath as &$path) {
                $params[] = $path;
                $path = '?';
            }
            $searchPath = implode(', ', $searchPath);
            $sql .= "
            AND n.nspname IN ($searchPath)";
        } else {
            $sql .= "
            AND n.nspname = ?";
            $params[] = $database->getSchema();
        }

        $sql .= "
          ORDER BY relname";

        /** @var \PDOStatement $stmt */
        $stmt = $this->dbh->prepare($sql);

        $stmt->execute($params);

        // First load the tables (important that this happens before filling out details of tables)
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['relname'];
            $namespaceName = $row['nspname'];
            if ($name == $this->getMigrationTable()) {
                continue;
            }
            $oid = $row['oid'];
            $table = new Table($name);
            if ($namespaceName !== 'public') {
                $table->setSchema($namespaceName);
            }
            $table->setIdMethod($database->getDefaultIdMethod());
            $table->setDatabase($database);
            if (!$database->hasTable($table->getName())) {
                $database->addTable($table);

                // Create a wrapper to hold these tables and their associated OID
                $wrap = new stdClass();
                $wrap->table = $table;
                $wrap->oid = $oid;
                $tableWraps[] = $wrap;
            }
        }
    }

    /**
     * Adds Columns to the specified table.
     *
     * @param \Propel\Generator\Model\Table $table The Table model class to add columns to.
     * @param int $oid The table OID
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    protected function addColumns(Table $table, int $oid): void
    {
        // Get the columns, types, etc.
        // Based on code from pgAdmin3 (http://www.pgadmin.org/)

        $searchPath = '?';
        $params = [$table->getDatabase()->getSchema()];
        $schema = $table->getSchema();

        if ($schema) {
            $params = [$schema];
        } elseif (!$table->getDatabase()->getSchema()) {
            $stmt = $this->dbh->query('SHOW search_path');
            if ($stmt === false) {
                throw new RuntimeException('Could not retrieve search_path from database.');
            }
            $searchPathString = $stmt->fetchColumn();

            $params = [];
            $searchPath = explode(',', $searchPathString);

            foreach ($searchPath as &$path) {
                $params[] = trim($path);
                $path = '?';
            }
            $searchPath = implode(', ', $searchPath);
        }

        $stmt = $this->dbh->prepare("
        SELECT
            column_name,
            data_type,
            column_default,
            is_nullable,
            numeric_precision,
            numeric_scale,
            character_maximum_length
        FROM information_schema.columns
        WHERE
            table_schema IN ($searchPath) AND table_name = ?
        ");
        if ($stmt === false) {
            throw new RuntimeException('PdoConnection::prepare() failed and did not return statement object for execution.');
        }

        $params[] = $table->getCommonName();
        $stmt->execute($params);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $size = $row['character_maximum_length'];
            if (!$size) {
                $size = $row['numeric_precision'];
            }
            $scale = $row['numeric_scale'];

            $name = $row['column_name'];
            $type = $row['data_type'];
            $default = $row['column_default'];
            $isNullable = ($row['is_nullable'] === true || strtoupper($row['is_nullable']) === 'YES');

            // Check to ensure that this column isn't an array data type
            if ($type === 'ARRAY') {
                $this->warn(sprintf('Array datatypes are not currently supported [%s.%s]', $table->getName(), $name));

                continue;
            }

            $autoincrement = null;

            // if column has a default

            if (is_string($default) && (strlen(trim($default)) > 0)) {
                if (!preg_match('/^nextval\(/', $default)) {
                    $strDefault = preg_replace('/::[\W\D]*/', '', $default);
                } else {
                    $autoincrement = true;
                    $default = null;
                }
            } else {
                $default = null;
            }

            $propelType = $this->getMappedPropelType($type);
            if (!$propelType) {
                $propelType = Column::DEFAULT_TYPE;
                $this->warn('Column [' . $table->getName() . '.' . $name . '] has a column type (' . $type . ') that Propel does not support.');
            }

            if (isset(static::$defaultTypeSizes[$type]) && $size == static::$defaultTypeSizes[$type]) {
                $size = null;
            }

            if (substr(strtoupper($type), 0, 6) === 'SERIAL') {
                $autoincrement = true;
                $default = null;
            }

            $column = new Column($name);
            $column->setTable($table);
            $column->setDomainForType($propelType);
            $column->getDomain()->replaceSize($size);
            if ($scale) {
                $column->getDomain()->replaceScale($scale);
            }

            if ($default !== null) {
                if ($this->isColumnDefaultExpression($default)) {
                    $defaultType = ColumnDefaultValue::TYPE_EXPR;
                } else {
                    $defaultType = ColumnDefaultValue::TYPE_VALUE;
                    $default = str_replace("'", '', $strDefault);
                }
                $column->getDomain()->setDefaultValue(new ColumnDefaultValue($default, $defaultType));
            }

            $column->setAutoIncrement((bool)$autoincrement);
            $column->setNotNull(!$isNullable);

            $table->addColumn($column);
        }
    }

    /**
     * @param string $default
     *
     * @return bool
     */
    protected function isColumnDefaultExpression(string $default): bool
    {
        $containsFunctionCall = substr($default, 0, 1) !== "'" && strpos($default, '(');

        if ($containsFunctionCall) {
            return true;
        }

        $defaultColumnValueExpressions = [
            'CURRENT_TIMESTAMP' => 'CURRENT_TIMESTAMP',
            'LOCALTIMESTAMP' => 'LOCALTIMESTAMP',
        ];

        if (isset($defaultColumnValueExpressions[strtoupper($default)])) {
            return true;
        }

        return false;
    }

    /**
     * Load foreign keys for this table.
     *
     * @param \Propel\Generator\Model\Table $table
     * @param int $oid
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    protected function addForeignKeys(Table $table, int $oid): void
    {
        $database = $table->getDatabase();
        $stmt = $this->dbh->prepare("SELECT
            conname,
            confupdtype,
            confdeltype,
            CASE nl.nspname WHEN 'public' THEN cl.relname ELSE nl.nspname||'.'||cl.relname END as fktab,
                array_agg(DISTINCT a2.attname) AS fkcols,
                CASE nr.nspname WHEN 'public' THEN cr.relname ELSE nr.nspname||'.'||cr.relname END as reftab,
                    array_agg(DISTINCT a1.attname) AS refcols
                    FROM pg_constraint ct
                    JOIN pg_class cl ON cl.oid=conrelid
                    JOIN pg_class cr ON cr.oid=confrelid
                    JOIN pg_namespace nl ON nl.oid = cl.relnamespace
                    JOIN pg_namespace nr ON nr.oid = cr.relnamespace
                    LEFT JOIN pg_catalog.pg_attribute a1 ON a1.attrelid = ct.confrelid
                    LEFT JOIN pg_catalog.pg_attribute a2 ON a2.attrelid = ct.conrelid
                    WHERE
                    contype='f'
                    AND conrelid = ?
                    AND a2.attnum = ANY (ct.conkey)
                    AND a1.attnum = ANY (ct.confkey)
                    GROUP BY conname, confupdtype, confdeltype, fktab, reftab
                    ORDER BY conname");
        if ($stmt === false) {
            throw new RuntimeException('PdoConnection::prepare() failed and did not return statement object for execution.');
        }
        $stmt->bindValue(1, $oid);
        $stmt->execute();

        $foreignKeys = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['conname'];
            $localTable = $row['fktab'];
            $localColumns = explode(',', trim($row['fkcols'], '{}'));
            $foreignTableName = $row['reftab'];
            $foreignColumns = explode(',', trim($row['refcols'], '{}'));

            // On Update
            switch ($row['confupdtype']) {
                case 'c':
                    $onupdate = ForeignKey::CASCADE;

                    break;
                case 'd':
                    $onupdate = ForeignKey::SETDEFAULT;

                    break;
                case 'n':
                    $onupdate = ForeignKey::SETNULL;

                    break;
                case 'r':
                    $onupdate = ForeignKey::RESTRICT;

                    break;
                default:
                case 'a':
                    // NOACTION is the postgresql default
                    $onupdate = ForeignKey::NONE;

                    break;
            }
            // On Delete
            switch ($row['confdeltype']) {
                case 'c':
                    $ondelete = ForeignKey::CASCADE;

                    break;
                case 'd':
                    $ondelete = ForeignKey::SETDEFAULT;

                    break;
                case 'n':
                    $ondelete = ForeignKey::SETNULL;

                    break;
                case 'r':
                    $ondelete = ForeignKey::RESTRICT;

                    break;
                default:
                case 'a':
                    // NOACTION is the postgresql default
                    $ondelete = ForeignKey::NONE;

                    break;
            }

            $foreignTable = $database->getTable($foreignTableName);
            $localTable = $database->getTable($localTable);

            if (!$foreignTable) {
                continue;
            }

            if (!isset($foreignKeys[$name])) {
                $fk = new ForeignKey($name);
                $fk->setForeignTableCommonName($foreignTable->getCommonName());
                if ($table->guessSchemaName() != $foreignTable->guessSchemaName()) {
                    $fk->setForeignSchemaName($foreignTable->getSchema());
                }
                $fk->setOnDelete($ondelete);
                $fk->setOnUpdate($onupdate);
                $table->addForeignKey($fk);
                $foreignKeys[$name] = $fk;
            }

            $max = count($localColumns);
            for ($i = 0; $i < $max; $i++) {
                $foreignKeys[$name]->addReference(
                    $localTable->getColumn($localColumns[$i]),
                    $foreignTable->getColumn($foreignColumns[$i]),
                );
            }
        }
    }

    /**
     * Load indexes for this table
     *
     * @param \Propel\Generator\Model\Table $table
     * @param int $oid
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    protected function addIndexes(Table $table, int $oid): void
    {
        $stmt = $this->dbh->prepare("SELECT
            DISTINCT ON(cls.relname)
            cls.relname as idxname,
            indkey,
            indisunique
            FROM pg_index idx
            JOIN pg_class cls ON cls.oid=indexrelid
            WHERE indrelid = ? AND NOT indisprimary
            ORDER BY cls.relname");
        if ($stmt === false) {
            throw new RuntimeException('PdoConnection::prepare() failed and did not return statement object for execution.');
        }

        $stmt->bindValue(1, $oid);
        $stmt->execute();

        $stmt2 = $this->dbh->prepare("SELECT a.attname
            FROM pg_catalog.pg_class c JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
            WHERE c.oid = ? AND a.attnum = ? AND NOT a.attisdropped
            ORDER BY a.attnum");
        if ($stmt2 === false) {
            throw new RuntimeException('PdoConnection::prepare() failed and did not return statement object for execution.');
        }

        $indexes = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['idxname'];
            $unique = (in_array($row['indisunique'], ['t', true, 1, '1']) ? true : false);

            if (!isset($indexes[$name])) {
                if ($unique) {
                    $indexes[$name] = new Unique($name);
                } else {
                    $indexes[$name] = new Index($name);
                }
            }

            $arrColumns = explode(' ', $row['indkey']);
            foreach ($arrColumns as $intColNum) {
                $stmt2->bindValue(1, $oid);
                $stmt2->bindValue(2, $intColNum);
                $stmt2->execute();

                $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);

                $indexes[$name]->setTable($table);
                $indexes[$name]->addColumn([
                    'name' => $row2['attname'],
                ]);
            }
        }

        foreach ($indexes as $index) {
            if ($index instanceof Unique) {
                $table->addUnique($index);
            } else {
                $table->addIndex($index);
            }
        }
    }

    /**
     * Loads the primary key for this table.
     *
     * @param \Propel\Generator\Model\Table $table
     * @param int $oid
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    protected function addPrimaryKey(Table $table, int $oid): void
    {
        $stmt = $this->dbh->prepare("SELECT
            DISTINCT ON(cls.relname)
            cls.relname as idxname,
            indkey,
            indisunique
            FROM pg_index idx
            JOIN pg_class cls ON cls.oid=indexrelid
            WHERE indrelid = ? AND indisprimary
            ORDER BY cls.relname");
        if ($stmt === false) {
            throw new RuntimeException('PdoConnection::prepare() failed and did not return statement object for execution.');
        }
        $stmt->bindValue(1, $oid);
        $stmt->execute();

        // Loop through the returned results, grouping the same key_name together
        // adding each column for that key.
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arrColumns = explode(' ', $row['indkey']);
            foreach ($arrColumns as $intColNum) {
                $stmt2 = $this->dbh->prepare("SELECT a.attname
                    FROM pg_catalog.pg_class c JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
                    WHERE c.oid = ? AND a.attnum = ? AND NOT a.attisdropped
                    ORDER BY a.attnum");
                if ($stmt2 === false) {
                    throw new RuntimeException('PdoConnection::prepare() failed and did not return statement object for execution.');
                }
                $stmt2->bindValue(1, $oid);
                $stmt2->bindValue(2, $intColNum);
                $stmt2->execute();

                $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                if (!$table->getColumn($row2['attname'])) {
                    continue;
                }
                $table->getColumn($row2['attname'])->setPrimaryKey(true);
            }
        }
    }

    /**
     * Adds the sequences for this database.
     *
     * @param \Propel\Generator\Model\Database $database
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    protected function addSequences(Database $database): void
    {
        $searchPath = '?';
        $params = [$database->getSchema()];
        if (!$database->getSchema()) {
            $stmt = $this->dbh->query('SHOW search_path');
            if ($stmt === false) {
                throw new RuntimeException('Query returned no statement.');
            }
            $searchPathString = $stmt->fetchColumn();

            $params = [];
            $searchPath = explode(',', $searchPathString);

            foreach ($searchPath as &$path) {
                $params[] = $path;
                $path = '?';
            }
            $searchPath = implode(', ', $searchPath);
        }

        $stmt = $this->dbh->prepare("
            SELECT c.relname, n.nspname
            FROM pg_class c, pg_namespace n
            WHERE
                n.oid = c.relnamespace
            AND c.relkind = 'S'
            AND n.nspname IN ($searchPath);
        ");
        if ($stmt === false) {
            throw new RuntimeException('PdoConnection::prepare() failed and did not return statement object for execution.');
        }
        $stmt->execute($params);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['nspname'] . '.' . $row['relname'];
            $database->addSequence($name);
        }
    }
}
