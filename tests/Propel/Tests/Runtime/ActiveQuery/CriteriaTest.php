<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveQuery;

use PDO;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Lock;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Adapter\Pdo\MysqlAdapter;
use Propel\Runtime\Adapter\Pdo\PgsqlAdapter;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Propel;
use Propel\Tests\Bookstore\BookQuery;
use Propel\Tests\Bookstore\Map\BookTableMap;
use Propel\Tests\Helpers\Bookstore\BookstoreTestBase;

/**
 * Test class for Criteria.
 *
 * @author Christopher Elkins <celkins@scardini.com>
 * @author Sam Joseph <sam@neurogrid.com>
 *
 * @group database
 */
class CriteriaTest extends BookstoreTestBase
{
    /**
     * The criteria to use in the test.
     *
     * @var \Propel\Runtime\ActiveQuery\Criteria
     */
    private $c;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->c = new ModelCriteria();
    }

    /**
     * Test basic adding of strings.
     *
     * @return void
     */
    public function testAddString()
    {
        $table = 'myTable';
        $column = 'myColumn';
        $value = 'myValue';
        $columnIdentifier = "$table.$column";
        $this->c->setUpdateValue($columnIdentifier, $value, 1);

        $this->assertTrue($this->c->hasUpdateValue($columnIdentifier));
        $this->assertEquals($value, $this->c->getUpdateValue($columnIdentifier));
    }

    /**
     * Test basic adding of strings for table with explicit schema.
     *
     * @return void
     */
    public function testAddUpdateValueWithSchemas()
    {
        $table = 'mySchema.myTable';
        $column = 'myColumn';
        $value = 'myValue';
        $columnIdentifier = "$table.$column";
        $this->c->setUpdateValue($columnIdentifier, $value, 1);

        $this->assertTrue($this->c->hasUpdateValue($columnIdentifier));
        $this->assertEquals($value, $this->c->getUpdateValue($columnIdentifier));
    }

    /**
     * Test basic adding of strings for table with explicit schema.
     *
     * @return void
     */
    public function testAddFilterWithSchemas()
    {
        $table = 'mySchema.myTable';
        $column = 'myColumn';
        $value = 'myValue';
        $columnIdentifier = "$table.$column";
        $this->c->addFilter($columnIdentifier, $value);
        $columnFilters = $this->c->getColumnFilters();

        $this->assertCount(1, $columnFilters);
        $this->assertEquals($value, reset($columnFilters)->getValue());
        $this->assertEquals($columnIdentifier, reset($columnFilters)->getLocalColumnName(false));
    }

    /**
     * @return void
     */
    public function testAddAndSameColumns()
    {
        $table = 'myTable1';
        $column = 'myColumn1';
        $value1 = 'myValue1';
        $key = "$table.$column";

        $value2 = 'myValue2';

        $this->c->add($key, $value1, Criteria::EQUAL);
        $this->c->addAnd($key, $value2, Criteria::EQUAL);

        $expect = $this->getSql('SELECT  FROM myTable1 WHERE (myTable1.myColumn1=:p1 AND myTable1.myColumn1=:p2)');

        $params = [];
        $result = $this->c->createSelectSql($params);

        $expect_params = [
            ['table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'],
            ['table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue2'],
        ];

        $this->assertEquals($expect, $result, 'addAnd() called on an existing column creates a combined criterion');
        $this->assertEquals($expect_params, $params, 'addAnd() called on an existing column creates a combined criterion');
    }

    /**
     * @return void
     */
    public function testAddAndSameColumnsPropel14Compatibility()
    {
        $table1 = 'myTable1';
        $column1 = 'myColumn1';
        $value1 = 'myValue1';
        $key1 = "$table1.$column1";

        $table2 = 'myTable1';
        $column2 = 'myColumn1';
        $value2 = 'myValue2';
        $key2 = "$table2.$column2";

        $table3 = 'myTable3';
        $column3 = 'myColumn3';
        $value3 = 'myValue3';
        $key3 = "$table3.$column3";

        $this->c->add($key1, $value1, Criteria::EQUAL);
        $this->c->add($key3, $value3, Criteria::EQUAL);
        $this->c->addAnd($key2, $value2, Criteria::EQUAL);

        $expect = $this->getSql('SELECT  FROM myTable1, myTable3 WHERE (myTable1.myColumn1=:p1 AND myTable1.myColumn1=:p2) AND myTable3.myColumn3=:p3');

        $params = [];
        $result = $this->c->createSelectSql($params);

        $expect_params = [
            ['table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'],
            ['table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue2'],
            ['table' => 'myTable3', 'column' => 'myColumn3', 'value' => 'myValue3'],
        ];

        $this->assertEquals($expect, $result, 'addAnd() called on an existing column creates a combined criterion');
        $this->assertEquals($expect_params, $params, 'addAnd() called on an existing column creates a combined criterion');
    }

    /**
     * @return void
     */
    public function testAddAndDistinctColumns()
    {
        $table1 = 'myTable1';
        $column1 = 'myColumn1';
        $value1 = 'myValue1';
        $key1 = "$table1.$column1";

        $table2 = 'myTable2';
        $column2 = 'myColumn2';
        $value2 = 'myValue2';
        $key2 = "$table2.$column2";

        $this->c->add($key1, $value1, Criteria::EQUAL);
        $this->c->addAnd($key2, $value2, Criteria::EQUAL);

        $expect = $this->getSql('SELECT  FROM myTable1, myTable2 WHERE myTable1.myColumn1=:p1 AND myTable2.myColumn2=:p2');

        $params = [];
        $result = $this->c->createSelectSql($params);

        $expect_params = [
            ['table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'],
            ['table' => 'myTable2', 'column' => 'myColumn2', 'value' => 'myValue2'],
        ];

        $this->assertEquals($expect, $result, 'addAnd() called on a distinct column adds a criterion to the criteria');
        $this->assertEquals($expect_params, $params, 'addAnd() called on a distinct column adds a criterion to the criteria');
    }

    /**
     * @return void
     */
    public function testAddOrSameColumns()
    {
        $table1 = 'myTable1';
        $column1 = 'myColumn1';
        $value1 = 'myValue1';
        $key1 = "$table1.$column1";

        $table2 = 'myTable1';
        $column2 = 'myColumn1';
        $value2 = 'myValue2';
        $key2 = "$table2.$column2";

        $this->c->add($key1, $value1, Criteria::EQUAL);
        $this->c->addOr($key2, $value2, Criteria::EQUAL);

        $expect = $this->getSql('SELECT  FROM myTable1 WHERE (myTable1.myColumn1=:p1 OR myTable1.myColumn1=:p2)');

        $params = [];
        $result = $this->c->createSelectSql($params);

        $expect_params = [
            ['table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'],
            ['table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue2'],
        ];

        $this->assertEquals($expect, $result, 'addOr() called on an existing column creates a combined criterion');
        $this->assertEquals($expect_params, $params, 'addOr() called on an existing column creates a combined criterion');
    }

    /**
     * @return void
     */
    public function testPrimaryTableNameQuoting()
    {
        $tableName = 'myTable1';
        $this->c->setPrimaryTableName($tableName);
        $countSelect = 'COUNT(*)';
        $this->c->addSelectColumn($countSelect);
        $adapter = Propel::getServiceContainer()->getAdapter('bookstore');
        $escapedTableName = $adapter->quoteIdentifierTable($tableName);

        $this->c->setIdentifierQuoting(true);
        $params = [];
        $this->assertEquals(
            "SELECT {$countSelect} FROM {$escapedTableName}",
            $this->c->createSelectSql($params)
        );
    }

    /**
     * @return void
     */
    public function testAddOrDistinctColumns()
    {
        $table1 = 'myTable1';
        $column1 = 'myColumn1';
        $value1 = 'myValue1';
        $key1 = "$table1.$column1";

        $table2 = 'myTable2';
        $column2 = 'myColumn2';
        $value2 = 'myValue2';
        $key2 = "$table2.$column2";

        $this->c->add($key1, $value1, Criteria::EQUAL);
        $this->c->addOr($key2, $value2, Criteria::EQUAL);

        $expect = $this->getSql('SELECT  FROM myTable1, myTable2 WHERE (myTable1.myColumn1=:p1 OR myTable2.myColumn2=:p2)');

        $params = [];
        $result = $this->c->createSelectSql($params);

        $expect_params = [
            ['table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'],
            ['table' => 'myTable2', 'column' => 'myColumn2', 'value' => 'myValue2'],
        ];

        $this->assertEquals($expect, $result, 'addOr() called on a distinct column adds a criterion to the latest criterion');
        $this->assertEquals($expect_params, $params, 'addOr() called on a distinct column adds a criterion to the latest criterion');
    }

    /**
     * @return void
     */
    public function testAddOrEmptyCriteria()
    {
        $table1 = 'myTable1';
        $column1 = 'myColumn1';
        $value1 = 'myValue1';
        $key1 = "$table1.$column1";

        $this->c->addOr($key1, $value1, Criteria::EQUAL);

        $expect = $this->getSql('SELECT  FROM myTable1 WHERE myTable1.myColumn1=:p1');

        $params = [];
        $result = $this->c->createSelectSql($params);

        $expect_params = [
            ['table' => 'myTable1', 'column' => 'myColumn1', 'value' => 'myValue1'],
        ];

        $this->assertEquals($expect, $result, 'addOr() called on an empty Criteria adds a criterion to the criteria');
        $this->assertEquals($expect_params, $params, 'addOr() called on an empty Criteria adds a criterion to the criteria');
    }

    /**
     * Test Criterion.setIgnoreCase().
     * As the output is db specific the test just prints the result to
     * System.out
     *
     * @return void
     */
    public function testCriterionIgnoreCase()
    {
        $originalDB = Propel::getServiceContainer()->getAdapter();
        $adapters = [new MysqlAdapter(), new PgsqlAdapter()];
        $expectedIgnore = ['UPPER(TABLE.COLUMN) LIKE UPPER(:p1)', 'TABLE.COLUMN ILIKE :p1'];

        $i = 0;
        foreach ($adapters as $adapter) {
            Propel::getServiceContainer()->setAdapter(Propel::getServiceContainer()->getDefaultDatasource(), $adapter);
            $myCriteria = new Criteria();

            $myCriterion = $myCriteria->getNewCriterion(
                'TABLE.COLUMN',
                'FoObAr',
                Criteria::LIKE
            );
            $params = [];
            $sb = $myCriterion->buildStatement($params);
            $expected = 'TABLE.COLUMN LIKE :p1';

            $this->assertEquals($expected, $sb);

            $ignoreCriterion = $myCriterion->setIgnoreCase(true);

            $params = [];
            $sb = $ignoreCriterion->buildStatement($params);
            // $expected = "UPPER(TABLE.COLUMN) LIKE UPPER(?)";
            $this->assertEquals($expectedIgnore[$i], $sb);
            $i++;
        }
        Propel::getServiceContainer()->setAdapter(Propel::getServiceContainer()->getDefaultDatasource(), $originalDB);
    }

    /**
     * @return void
     */
    public function testOrderByIgnoreCase()
    {
        $originalDB = Propel::getServiceContainer()->getAdapter();
        Propel::getServiceContainer()->setAdapter(Propel::getServiceContainer()->getDefaultDatasource(), new MysqlAdapter());
        Propel::getServiceContainer()->setDefaultDatasource('bookstore');

        $criteria = new Criteria();
        $criteria->setIgnoreCase(true);
        $criteria->addAscendingOrderByColumn(BookTableMap::COL_TITLE);
        BookTableMap::addSelectColumns($criteria);
        $params = [];
        $sql = $criteria->createSelectSql($params);
        $expectedSQL = 'SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id, UPPER(book.title) FROM book ORDER BY UPPER(book.title) ASC';
        $this->assertEquals($expectedSQL, $sql);

        Propel::getServiceContainer()->setAdapter(Propel::getServiceContainer()->getDefaultDatasource(), $originalDB);
    }

    /**
     * Test that true is evaluated correctly.
     *
     * @return void
     */
    public function testBoolean()
    {
        $this->c = new Criteria();
        $this->c->add('TABLE.COLUMN', true);

        $expect = $this->getSql('SELECT  FROM TABLE WHERE TABLE.COLUMN=:p1');
        $expect_params = [ [
        'table' => 'TABLE',
        'column' => 'COLUMN',
        'value' => true],
        ];
        try {
            $params = [];
            $result = $this->c->createSelectSql($params);
        } catch (PropelException $e) {
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }

        $this->assertEquals($expect, $result, 'Boolean test failed.');
        $this->assertEquals($expect_params, $params);
    }

    /**
     * @return void
     */
    public function testCurrentDate()
    {
        $this->c = new Criteria();
        $this->c->add('TABLE.TIME_COLUMN', Criteria::CURRENT_TIME);
        $this->c->add('TABLE.DATE_COLUMN', Criteria::CURRENT_DATE);

        $expect = $this->getSql('SELECT  FROM TABLE WHERE TABLE.TIME_COLUMN=CURRENT_TIME AND TABLE.DATE_COLUMN=CURRENT_DATE');

        $result = null;
        try {
            $params = [];
            $result = $this->c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }

        $this->assertEquals($expect, $result, 'Current date test failed!');
    }

    /**
     * @return void
     */
    public function testCountAster()
    {
        $this->c = new Criteria();
        $this->c->addSelectColumn('COUNT(*)');
        $this->c->add('TABLE.TIME_COLUMN', Criteria::CURRENT_TIME);
        $this->c->add('TABLE.DATE_COLUMN', Criteria::CURRENT_DATE);

        $expect = $this->getSql('SELECT COUNT(*) FROM TABLE WHERE TABLE.TIME_COLUMN=CURRENT_TIME AND TABLE.DATE_COLUMN=CURRENT_DATE');

        $result = null;
        try {
            $params = [];
            $result = $this->c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }

        $this->assertEquals($expect, $result);
    }

    /**
     * @return void
     */
    public function testInOperator()
    {
        $c = new Criteria();
        $c->addSelectColumn('*');
        $c->add('TABLE.SOME_COLUMN', [], Criteria::IN);
        $c->add('TABLE.OTHER_COLUMN', [1, 2, 3], Criteria::IN);

        $expect = $this->getSql('SELECT * FROM TABLE WHERE 1<>1 AND TABLE.OTHER_COLUMN IN (:p1,:p2,:p3)');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * @return void
     */
    public function testInOperatorEmptyAfterFull()
    {
        $c = new Criteria();
        $c->addSelectColumn('*');
        $c->add('TABLE.OTHER_COLUMN', [1, 2, 3], Criteria::IN);
        $c->add('TABLE.SOME_COLUMN', [], Criteria::IN);

        $expect = $this->getSql('SELECT * FROM TABLE WHERE TABLE.OTHER_COLUMN IN (:p1,:p2,:p3) AND 1<>1');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * @return void
     */
    public function testInOperatorNested()
    {
        // now do a nested logic test, just for sanity (not that this should be any surprise)

        $c = new Criteria();
        $c->addSelectColumn('*');
        $myCriterion = $c->getNewCriterion('TABLE.COLUMN', [], Criteria::IN);
        $myCriterion->addOr($c->getNewCriterion('TABLE.COLUMN2', [1, 2], Criteria::IN));
        $c->add($myCriterion);

        $expect = $this->getSql('SELECT * FROM TABLE WHERE (1<>1 OR TABLE.COLUMN2 IN (:p1,:p2))');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * Test the Criteria::RAW behavior.
     *
     * @return void
     */
    public function testRaw()
    {
        $c = new Criteria();
        $c->addSelectColumn('A.COL');
        $c->addAsColumn('foo', 'B.COL');
        $c->add('foo = ?', 123, PDO::PARAM_STR);

        $params = [];
        $result = $c->createSelectSql($params);
        $expected = $this->getSql('SELECT A.COL, B.COL AS foo FROM A WHERE foo = :p1');
        $this->assertEquals($expected, $result);
        $expected = [
            ['table' => null, 'type' => PDO::PARAM_STR, 'value' => 123],
        ];
        $this->assertEquals($expected, $params);
    }

    /**
     * @return void
     */
    public function testAddStraightJoin()
    {
        $c = new Criteria();
        $c->addSelectColumn('*');
        $c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1'); // straight join

        $expect = $this->getSql('SELECT * FROM TABLE_A INNER JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1)');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * @return void
     */
    public function testAddSeveralJoins()
    {
        $c = new Criteria();
        $c->addSelectColumn('*');
        $c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1');
        $c->addJoin('TABLE_B.COL_X', 'TABLE_D.COL_X');

        $expect = $this->getSql('SELECT * FROM TABLE_A INNER JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1)'
            . ' INNER JOIN TABLE_D ON (TABLE_B.COL_X=TABLE_D.COL_X)');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * @return void
     */
    public function testAddLeftJoin()
    {
        $c = new Criteria();
        $c->addSelectColumn('TABLE_A.*');
        $c->addSelectColumn('TABLE_B.*');
        $c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_2', Criteria::LEFT_JOIN);

        $expect = $this->getSql('SELECT TABLE_A.*, TABLE_B.* FROM TABLE_A LEFT JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_2)');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * @return void
     */
    public function testAddSeveralLeftJoins()
    {
        // Fails.. Suspect answer in the chunk starting at BaseTableMap:605
        $c = new Criteria();
        $c->addSelectColumn('*');
        $c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::LEFT_JOIN);
        $c->addJoin('TABLE_A.COL_2', 'TABLE_C.COL_2', Criteria::LEFT_JOIN);

        $expect = $this->getSql('SELECT * FROM TABLE_A '
            . 'LEFT JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1) '
            . 'LEFT JOIN TABLE_C ON (TABLE_A.COL_2=TABLE_C.COL_2)');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * @return void
     */
    public function testAddRightJoin()
    {
        $c = new Criteria();
        $c->addSelectColumn('*');
        $c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_2', Criteria::RIGHT_JOIN);

        $expect = $this->getSql('SELECT * FROM TABLE_A RIGHT JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_2)');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * @return void
     */
    public function testAddSeveralRightJoins()
    {
        // Fails.. Suspect answer in the chunk starting at BaseTableMap:605
        $c = new Criteria();
        $c->addSelectColumn('*');
        $c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::RIGHT_JOIN);
        $c->addJoin('TABLE_A.COL_2', 'TABLE_C.COL_2', Criteria::RIGHT_JOIN);

        $expect = $this->getSql('SELECT * FROM TABLE_A '
            . 'RIGHT JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1) '
            . 'RIGHT JOIN TABLE_C ON (TABLE_A.COL_2=TABLE_C.COL_2)');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * @return void
     */
    public function testAddInnerJoin()
    {
        $c = new Criteria();
        $c->addSelectColumn('*');
        $c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::INNER_JOIN);

        $expect = $this->getSql('SELECT * FROM TABLE_A INNER JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1)');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * @return void
     */
    public function testAddSeveralInnerJoin()
    {
        $c = new Criteria();
        $c->addSelectColumn('*');
        $c->addJoin('TABLE_A.COL_1', 'TABLE_B.COL_1', Criteria::INNER_JOIN);
        $c->addJoin('TABLE_B.COL_1', 'TABLE_C.COL_1', Criteria::INNER_JOIN);

        $expect = $this->getSql('SELECT * FROM TABLE_A '
            . 'INNER JOIN TABLE_B ON (TABLE_A.COL_1=TABLE_B.COL_1) '
            . 'INNER JOIN TABLE_C ON (TABLE_B.COL_1=TABLE_C.COL_1)');
        try {
            $params = [];
            $result = $c->createSelectSql($params);
        } catch (PropelException $e) {
            print $e->getTraceAsString();
            $this->fail('PropelException thrown in Criteria->createSelectSql(): ' . $e->getMessage());
        }
        $this->assertEquals($expect, $result);
    }

    /**
     * @link http://www.propelorm.org/ticket/451
     * @link http://www.propelorm.org/ticket/283#comment:8
     *
     * @return void
     */
    public function testSeveralMixedJoinOrders()
    {
        $c = new Criteria();
        $c->clearSelectColumns()->
            addJoin('TABLE_A.FOO_ID', 'TABLE_B.id', Criteria::LEFT_JOIN)->
            addJoin('TABLE_A.BAR_ID', 'TABLE_C.id')->
            addSelectColumn('TABLE_A.id');

        $expect = $this->getSql('SELECT TABLE_A.id FROM TABLE_A LEFT JOIN TABLE_B ON (TABLE_A.FOO_ID=TABLE_B.id) INNER JOIN TABLE_C ON (TABLE_A.BAR_ID=TABLE_C.id)');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expect, $result);
    }

    /**
     * @link http://propel.phpdb.org/trac/ticket/606
     *
     * @return void
     */
    public function testAddJoinArray()
    {
        $c = new Criteria();
        $c->clearSelectColumns()->
            addJoin(['TABLE_A.FOO_ID'], ['TABLE_B.id'], Criteria::LEFT_JOIN)->
            addSelectColumn('TABLE_A.id');

        $expect = $this->getSql('SELECT TABLE_A.id FROM TABLE_A LEFT JOIN TABLE_B ON (TABLE_A.FOO_ID=TABLE_B.id)');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expect, $result);
    }

    /**
     * @link http://propel.phpdb.org/trac/ticket/606
     *
     * @return void
     */
    public function testAddJoinArrayMultiple()
    {
        $c = new Criteria();
        $c->clearSelectColumns()->
            addJoin(
                ['TABLE_A.FOO_ID', 'TABLE_A.BAR'],
                ['TABLE_B.id', 'TABLE_B.BAZ'],
                Criteria::LEFT_JOIN
            )->
                addSelectColumn('TABLE_A.id');

        $expect = $this->getSql('SELECT TABLE_A.id FROM TABLE_A LEFT JOIN TABLE_B ON (TABLE_A.FOO_ID=TABLE_B.id AND TABLE_A.BAR=TABLE_B.BAZ)');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expect, $result);
    }

    /**
     * Test the Criteria::addJoinMultiple() method with an implicit join
     *
     * @link http://propel.phpdb.org/trac/ticket/606
     *
     * @return void
     */
    public function testAddJoinMultiple()
    {
        $c = new Criteria();
        $c->
            clearSelectColumns()->
            addMultipleJoin([
                ['TABLE_A.FOO_ID', 'TABLE_B.id'],
                ['TABLE_A.BAR', 'TABLE_B.BAZ']])->
                addSelectColumn('TABLE_A.id');

        $expect = $this->getSql('SELECT TABLE_A.id FROM TABLE_A INNER JOIN TABLE_B '
            . 'ON (TABLE_A.FOO_ID=TABLE_B.id AND TABLE_A.BAR=TABLE_B.BAZ)');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expect, $result);
    }

    /**
     * Test the Criteria::addJoinMultiple() method with a value as second argument
     *
     * @link http://propel.phpdb.org/trac/ticket/606
     *
     * @return void
     */
    public function testAddJoinMultipleValue()
    {
        $c = new Criteria();
        $c->
            clearSelectColumns()->
            addMultipleJoin([
                ['TABLE_A.FOO_ID', 'TABLE_B.id'],
                ['TABLE_A.BAR', 3]])->
                addSelectColumn('TABLE_A.id');

        $expect = $this->getSql('SELECT TABLE_A.id FROM TABLE_A INNER JOIN TABLE_B '
            . 'ON (TABLE_A.FOO_ID=TABLE_B.id AND TABLE_A.BAR=3)');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expect, $result);
    }

    /**
     * Test the Criteria::addJoinMultiple() method with a joinType
     *
     * @link http://propel.phpdb.org/trac/ticket/606
     *
     * @return void
     */
    public function testAddJoinMultipleWithJoinType()
    {
        $c = new Criteria();
        $c->
            clearSelectColumns()->
            addMultipleJoin(
                [
                ['TABLE_A.FOO_ID', 'TABLE_B.id'],
                ['TABLE_A.BAR', 'TABLE_B.BAZ']],
                Criteria::LEFT_JOIN
            )->
            addSelectColumn('TABLE_A.id');

        $expect = $this->getSql('SELECT TABLE_A.id FROM TABLE_A '
            . 'LEFT JOIN TABLE_B ON (TABLE_A.FOO_ID=TABLE_B.id AND TABLE_A.BAR=TABLE_B.BAZ)');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expect, $result);
    }

    /**
     * Test the Criteria::addJoinMultiple() method with operator
     *
     * @link http://propel.phpdb.org/trac/ticket/606
     *
     * @return void
     */
    public function testAddJoinMultipleWithOperator()
    {
        $c = new Criteria();
        $c->
            clearSelectColumns()->
            addMultipleJoin([
                ['TABLE_A.FOO_ID', 'TABLE_B.id', Criteria::GREATER_EQUAL],
                ['TABLE_A.BAR', 'TABLE_B.BAZ', Criteria::LESS_THAN]])->
                addSelectColumn('TABLE_A.id');

        $expect = $this->getSql('SELECT TABLE_A.id FROM TABLE_A INNER JOIN TABLE_B '
            . 'ON (TABLE_A.FOO_ID>=TABLE_B.id AND TABLE_A.BAR<TABLE_B.BAZ)');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expect, $result);
    }

    /**
     * Test the Criteria::addJoinMultiple() method with join type and operator
     *
     * @link http://propel.phpdb.org/trac/ticket/606
     *
     * @return void
     */
    public function testAddJoinMultipleWithJoinTypeAndOperator()
    {
        $c = new Criteria();
        $c->
            clearSelectColumns()->
            addMultipleJoin(
                [
                ['TABLE_A.FOO_ID', 'TABLE_B.id', Criteria::GREATER_EQUAL],
                ['TABLE_A.BAR', 'TABLE_B.BAZ', Criteria::LESS_THAN]],
                Criteria::LEFT_JOIN
            )->
            addSelectColumn('TABLE_A.id');

        $expect = $this->getSql('SELECT TABLE_A.id FROM TABLE_A '
            . 'LEFT JOIN TABLE_B ON (TABLE_A.FOO_ID>=TABLE_B.id AND TABLE_A.BAR<TABLE_B.BAZ)');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expect, $result);
    }

    /**
     * Test the Criteria::CUSTOM behavior.
     *
     * @return void
     */
    public function testCustomOperator()
    {
        $c = new Criteria();
        $c->addSelectColumn('A.COL');
        $c->add('A.COL', 'date_part(\'YYYY\', A.COL) = \'2007\'', Criteria::CUSTOM);

        $expected = $this->getSql("SELECT A.COL FROM A WHERE date_part('YYYY', A.COL) = '2007'");
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expected, $result);
    }

    /**
     * Tests adding duplicate joins.
     *
     * @link http://propel.phpdb.org/trac/ticket/613
     *
     * @return void
     */
    public function testAddJoin_Duplicate()
    {
        $c = new Criteria();

        $c->addJoin('tbl.COL1', 'tbl.COL2', Criteria::LEFT_JOIN);
        $c->addJoin('tbl.COL1', 'tbl.COL2', Criteria::LEFT_JOIN);
        $this->assertEquals(1, count($c->getJoins()), 'Expected not to have duplicate LJOIN added.');

        $c->addJoin('tbl.COL1', 'tbl.COL2', Criteria::RIGHT_JOIN);
        $c->addJoin('tbl.COL1', 'tbl.COL2', Criteria::RIGHT_JOIN);
        $this->assertEquals(2, count($c->getJoins()), 'Expected 1 new right join to be added.');

        $c->addJoin('tbl.COL1', 'tbl.COL2');
        $c->addJoin('tbl.COL1', 'tbl.COL2');
        $this->assertEquals(3, count($c->getJoins()), 'Expected 1 new implicit join to be added.');

        $c->addJoin('tbl.COL3', 'tbl.COL4');
        $this->assertEquals(4, count($c->getJoins()), 'Expected new col join to be added.');
    }

    /**
     * @link http://propel.phpdb.org/trac/ticket/634
     *
     * @return void
     */
    public function testHasSelectClause()
    {
        $c = new Criteria();
        $c->addSelectColumn('foo');

        $this->assertTrue($c->hasSelectClause());

        $c = new Criteria();
        $c->addAsColumn('foo', 'bar');

        $this->assertTrue($c->hasSelectClause());
    }

    /**
     * Tests including aliases in criterion objects.
     *
     * @link http://propel.phpdb.org/trac/ticket/636
     *
     * @return void
     */
    public function testAliasInCriterion()
    {
        $c = new Criteria();
        $c->addAsColumn('column_alias', 'tbl.COL1');
        $crit = $c->getNewCriterion('column_alias', 'FOO');
        $this->assertNull($crit->getTableAlias());
        $this->assertEquals('column_alias', $crit->getLocalColumnName(false));
    }

    /**
     * @group mysql
     *
     * @return void
     */
    public function testHavingAlias()
    {
        $c = new Criteria();
        $c->addSelectColumn(BookTableMap::COL_TITLE);
        $c->addAsColumn('isb_n', BookTableMap::COL_ISBN);
        $crit = $c->getNewCriterion('isb_n', '1234567890123');
        $c->addHaving($crit);
        $expected = $this->getSql('SELECT book.title, book.isbn AS isb_n FROM book HAVING isb_n=:p1');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expected, $result);
        $c->doSelect($this->con);
        $expected = $this->getSql('SELECT book.title, book.isbn AS isb_n FROM book HAVING isb_n=\'1234567890123\'');
        $this->assertEquals($expected, $this->con->getLastExecutedQuery());
    }

    /**
     * @return void
     */
    public function testHaving()
    {
        $c = new Criteria();
        $c->addSelectColumn(BookTableMap::COL_TITLE);
        $c->addSelectColumn(BookTableMap::COL_ISBN);
        $crit = $c->getNewCriterion('ISBN', '1234567890123');
        $c->addHaving($crit);
        $c->addGroupByColumn(BookTableMap::COL_TITLE);
        $c->addGroupByColumn(BookTableMap::COL_ISBN);
        $expected = $this->getSql('SELECT book.title, book.isbn FROM book GROUP BY book.title,book.isbn HAVING ISBN=:p1');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expected, $result);
        $c->doSelect($this->con);
        $expected = $this->getSql('SELECT book.title, book.isbn FROM book GROUP BY book.title,book.isbn HAVING ISBN=\'1234567890123\'');
        $this->assertEquals($expected, $this->con->getLastExecutedQuery());
    }

    /**
     * @group mysql
     *
     * @return void
     */
    public function testHavingAliasRaw()
    {
        $c = new Criteria();
        $c->addSelectColumn(BookTableMap::COL_TITLE);
        $c->addAsColumn('isb_n', BookTableMap::COL_ISBN);
        $c->addHaving('isb_n = ?', '1234567890123', PDO::PARAM_STR);
        $expected = $this->getSql('SELECT book.title, book.isbn AS isb_n FROM book HAVING isb_n = :p1');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expected, $result);
        $c->doSelect($this->con);
        $expected = $this->getSql('SELECT book.title, book.isbn AS isb_n FROM book HAVING isb_n = \'1234567890123\'');
        $this->assertEquals($expected, $this->con->getLastExecutedQuery());
    }

    /**
     * @group mysql
     *
     * @return void
     */
    public function testHavingOnOverridingAsColumn()
    {
        $c = (new BookQuery())
            ->select(['title'])
            ->addAsColumn('price', 'id')
            ->addHaving('price >= 12');
        $expected = $this->getSql('SELECT id AS price, book.title AS "title" FROM book HAVING price >= 12');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expected, $result);
        $c->doSelect($this->con);
        $this->assertEquals($expected, $this->con->getLastExecutedQuery());
    }

    /**
     * @group mysql
     *
     * @return void
     */
    public function testHavingLocalColumn()
    {
        $c = (new BookQuery())
            ->addHaving('price', 12, Criteria::GREATER_EQUAL);
        $expected = $this->getSql('SELECT  FROM book HAVING book.price>=:p1');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals($expected, $result);
        $c->doSelect($this->con);
        $expected = $this->getSql('SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id FROM book HAVING book.price>=12');
        $this->assertEquals($expected, $this->con->getLastExecutedQuery());
    }

    /**
     * Test whether GROUP BY is being respected in equals() check.
     *
     * @link http://propel.phpdb.org/trac/ticket/674
     *
     * @return void
     */
    public function testEqualsGroupBy()
    {
        $c1 = new Criteria();
        $c1->addGroupByColumn('GBY1');

        $c2 = new Criteria();
        $c2->addGroupByColumn('GBY2');

        $this->assertFalse($c2->equals($c1), 'Expected Criteria NOT to be the same with different GROUP BY columns');

        $c3 = new Criteria();
        $c3->addGroupByColumn('GBY1');
        $c4 = new Criteria();
        $c4->addGroupByColumn('GBY1');
        $this->assertTrue($c4->equals($c3), 'Expected Criteria objects to match.');
    }

    /**
     * Test whether calling setDistinct twice puts in two distinct keywords or not.
     *
     * @link http://propel.phpdb.org/trac/ticket/716
     *
     * @return void
     */
    public function testDoubleSelectModifiers()
    {
        $c = new Criteria();
        $c->setDistinct();
        $this->assertEquals([Criteria::DISTINCT], $c->getSelectModifiers(), 'Initial setDistinct works');
        $c->setDistinct();
        $this->assertEquals([Criteria::DISTINCT], $c->getSelectModifiers(), 'Calling setDistinct again leaves a single distinct');
        $c->setAll();
        $this->assertEquals([Criteria::ALL], $c->getSelectModifiers(), 'All keyword is swaps distinct out');
        $c->setAll();
        $this->assertEquals([Criteria::ALL], $c->getSelectModifiers(), 'Calling setAll leaves a single all');
        $c->setDistinct();
        $this->assertEquals([Criteria::DISTINCT], $c->getSelectModifiers(), 'All back to distinct works');

        $c2 = new Criteria();
        $c2->setAll();
        $this->assertEquals([Criteria::ALL], $c2->getSelectModifiers(), 'Initial setAll works');
    }

    /**
     * @return void
     */
    public function testAddSelectModifier()
    {
        $c = new Criteria();
        $c->setDistinct();
        $c->addSelectModifier('SQL_CALC_FOUND_ROWS');
        $this->assertEquals([Criteria::DISTINCT, 'SQL_CALC_FOUND_ROWS'], $c->getSelectModifiers(), 'addSelectModifier() adds a select modifier to the Criteria');
        $c->addSelectModifier('SQL_CALC_FOUND_ROWS');
        $this->assertEquals([Criteria::DISTINCT, 'SQL_CALC_FOUND_ROWS'], $c->getSelectModifiers(), 'addSelectModifier() adds a select modifier only once');
        $params = [];
        $result = $c->createSelectSql($params);
        $this->assertEquals('SELECT DISTINCT SQL_CALC_FOUND_ROWS  FROM ', $result, 'addSelectModifier() adds a modifier to the final query');
    }

    /**
     * @return void
     */
    public function testWithSimpleLock()
    {
        $c = new Criteria();
        $c->lockForShare();
        $this->assertInstanceOf(Lock::class, $c->getLock(), 'lockForShare() adds a shared read lock to the Criteria');
        $this->assertSame(Lock::SHARED, $c->getLock()->getType());
        $this->assertEmpty($c->getLock()->getTableNames());
        $this->assertFalse($c->getLock()->isNoWait());
    }

    /**
     * @return void
     */
    public function testWithComplexLock()
    {
        $c = new Criteria();
        $c->lockForUpdate(['tableA', 'tableB'], true);
        $this->assertInstanceOf(Lock::class, $c->getLock(), 'lockForUpdate() adds an exclusive read lock to the Criteria');
        $this->assertSame(Lock::EXCLUSIVE, $c->getLock()->getType());
        $this->assertSame(['tableA', 'tableB'], $c->getLock()->getTableNames());
        $this->assertTrue($c->getLock()->isNoWait());
    }

    /**
     * @return void
     */
    public function testWithoutLock()
    {
        $c = new Criteria();
        $c->lockForShare();
        $this->assertInstanceOf(Lock::class, $c->getLock(), 'lockForShare() adds a shared read lock to the Criteria');
        $c->withoutLock();
        $this->assertNull($c->getLock(), 'withoutLock() removes read lock from the Criteria');
    }

    /**
     * @return void
     */
    public function testClone()
    {
        $c1 = new Criteria();
        $c1->addFilter('tbl.COL1', 'foo', Criteria::EQUAL);
        $c2 = clone $c1;
        $c2->addAnd('tbl.COL1', 'bar', Criteria::EQUAL);
        $nbCrit = 0;
        foreach ($c1->getColumnFilters() as $filter) {
            $nbCrit += $filter->count();
        }
        $this->assertEquals(1, $nbCrit, 'cloning a Criteria clones its Criterions');
    }

    /**
     * @return void
     */
    public function testComment()
    {
        $c = new Criteria();
        $this->assertNull($c->getComment(), 'Comment is null by default');
        $c2 = $c->setComment('foo');
        $this->assertSame('foo', $c->getComment(), 'Comment is set by setComment()');
        $this->assertEquals($c, $c2, 'setComment() returns the current Criteria');
        $c->setComment(null);
        $this->assertNull($c->getComment(), 'Comment is reset by setComment(null)');
    }

    /**
     * @return void
     */
    public function testClear()
    {
        $c = new Criteria();
        $c->clear();

        $this->assertTrue(is_array($c->getNamedCriterions()), 'namedCriterions is an array');
        $this->assertSame(0, count($c->getNamedCriterions()), 'namedCriterions is empty by default');

        $this->assertFalse($this->getObjectPropertyValue($c, 'ignoreCase') , 'ignoreCase is false by default');

        $this->assertFalse($c->isSingleRecord(), 'singleRecord is false by default');

        $this->assertTrue(is_array($c->getSelectModifiers()), 'selectModifiers is an array');
        $this->assertEquals(0, count($c->getSelectModifiers()), 'selectModifiers is empty by default');

        $this->assertTrue(is_array($c->getSelectColumns()), 'selectColumns is an array');
        $this->assertEquals(0, count($c->getSelectColumns()), 'selectColumns is empty by default');

        $this->assertTrue(is_array($c->getOrderByColumns()), 'orderByColumns is an array');
        $this->assertEquals(0, count($c->getOrderByColumns()), 'orderByColumns is empty by default');

        $this->assertTrue(is_array($c->getGroupByColumns()), 'groupByColumns is an array');
        $this->assertEquals(0, count($c->getGroupByColumns()), 'groupByColumns is empty by default');

        $this->assertNull($c->getHaving(), 'having is null by default');

        $this->assertTrue(is_array($c->getAsColumns()), 'asColumns is an array');
        $this->assertEquals(0, count($c->getAsColumns()), 'asColumns is empty by default');

        $this->assertTrue(is_array($c->getJoins()), 'joins is an array');
        $this->assertEquals(0, count($c->getJoins()), 'joins is empty by default');

        $this->assertTrue(is_array($c->getSelectQueries()), 'selectQueries is an array');
        $this->assertEquals(0, count($c->getSelectQueries()), 'selectQueries is empty by default');

        $this->assertEquals(0, $c->getOffset(), 'offset is 0 by default');

        $this->assertEquals(-1, $c->getLimit(), 'limit is -1 by default');

        $this->assertTrue(is_array($c->getAliases()), 'aliases is an array');
        $this->assertEquals(0, count($c->getAliases()), 'aliases is empty by default');

        $this->assertFalse($c->isUseTransaction(), 'useTransaction is false by default');

        $this->assertNull($c->getLock(), 'lock is null by default');
    }

    /**
     * @return void
     */
    public function testDefaultLimit()
    {
        $c = new Criteria();
        $this->assertEquals(-1, $c->getLimit(), 'Limit is -1 by default');
    }

    /**
     * @dataProvider dataLimit
     *
     * @return void
     */
    public function testLimit($limit, $expected)
    {
        $c = new Criteria();
        $c2 = $c->setLimit($limit);

        $this->assertSame($expected, $c->getLimit(), 'Correct limit is set by setLimit()');
        $this->assertSame($c, $c2, 'setLimit() returns the current Criteria');
    }

    public function dataLimit()
    {
        return [
            'Negative value' => [
                'limit' => -1,
                'expected' => -1,
            ],
            'Zero' => [
                'limit' => 0,
                'expected' => 0,
            ],

            'Small integer' => [
                'limit' => 38427,
                'expected' => 38427,
            ],
            'Small integer as a string' => [
                'limit' => '38427',
                'expected' => 38427,
            ],

            'Largest 32-bit integer' => [
                'limit' => 2147483647,
                'expected' => 2147483647,
            ],
            'Largest 32-bit integer as a string' => [
                'limit' => '2147483647',
                'expected' => 2147483647,
            ],

            'Largest 64-bit integer' => [
                'limit' => 9223372036854775807,
                'expected' => 9223372036854775807,
            ],
            'Largest 64-bit integer as a string' => [
                'limit' => '9223372036854775807',
                'expected' => 9223372036854775807,
            ],
        ];
    }

    /**
     * @return void
     */
    public function testDefaultOffset()
    {
        $c = new Criteria();
        $this->assertEquals(0, $c->getOffset(), 'Offset is 0 by default');
    }

    /**
     * @dataProvider dataOffset
     *
     * @return void
     */
    public function testOffset($offset, $expected)
    {
        $c = new Criteria();
        $c2 = $c->setOffset($offset);

        $this->assertSame($expected, $c->getOffset(), 'Correct offset is set by setOffset()');
        $this->assertSame($c, $c2, 'setOffset() returns the current Criteria');
    }

    public function dataOffset()
    {
        return [
            'Negative value' => [
                'offset' => -1,
                'expected' => -1,
            ],
            'Zero' => [
                'offset' => 0,
                'expected' => 0,
            ],

            'Small integer' => [
                'offset' => 38427,
                'expected' => 38427,
            ],
            'Small integer as a string' => [
                'offset' => '38427',
                'expected' => 38427,
            ],

            'Largest 32-bit integer' => [
                'offset' => 2147483647,
                'expected' => 2147483647,
            ],
            'Largest 32-bit integer as a string' => [
                'offset' => '2147483647',
                'expected' => 2147483647,
            ],

            'Largest 64-bit integer' => [
                'offset' => 9223372036854775807,
                'expected' => 9223372036854775807,
            ],
            'Largest 64-bit integer as a string' => [
                'offset' => '9223372036854775807',
                'expected' => 9223372036854775807,
            ],
        ];
    }

    /**
     * @return void
     */
    public function testCombineAndFilterBy()
    {
        $params = [];
        $sql = $this->getSql('SELECT  FROM book WHERE ((book.title LIKE :p1 OR book.isbn LIKE :p2) AND book.title LIKE :p3)');
        $c = BookQuery::create()
            ->condition('u1', 'book.title LIKE ?', '%test1%')
            ->condition('u2', 'book.isbn LIKE ?', '%test2%')
            ->combine(['u1', 'u2'], 'or')
            ->filterByTitle('%test3%', Criteria::LIKE);
        $result = $c->createSelectSql($params);
        $this->assertEquals($sql, $result);

        $params = [];
        $sql = $this->getSql('SELECT  FROM book WHERE (book.title LIKE :p1 AND (book.title LIKE :p2 OR book.isbn LIKE :p3))');
        $c = BookQuery::create()
            ->filterByTitle('%test3%', Criteria::LIKE)
            ->condition('u1', 'book.title LIKE ?', '%test1%')
            ->condition('u2', 'book.isbn LIKE ?', '%test2%')
            ->combine(['u1', 'u2'], 'or');
        $result = $c->createSelectSql($params);
        $this->assertEquals($sql, $result);
    }

    /**
     * @return void
     */
    public function testGroupBy()
    {
        $params = [];
        $c = BookQuery::create()
            ->joinReview()
            ->withColumn('COUNT(Review.id)', 'Count')
            ->groupById();

        $result = $c->createSelectSql($params);

        if ($this->runningOnPostgreSQL()) {
            $sql = 'SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id, COUNT(review.id) AS Count FROM book LEFT JOIN review ON (book.id=review.book_id) GROUP BY book.id,book.title,book.isbn,book.price,book.publisher_id,book.author_id';
        } else {
            $sql = $this->getSql('SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id, COUNT(review.id) AS Count FROM book LEFT JOIN review ON (book.id=review.book_id) GROUP BY book.id');
        }

        $this->assertEquals($sql, $result);
    }
}
