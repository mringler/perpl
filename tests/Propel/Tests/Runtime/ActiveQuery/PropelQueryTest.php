<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveQuery;

use Propel\Runtime\ActiveQuery\PropelQuery;
use Propel\Runtime\Exception\ClassNotFoundException;
use Propel\Tests\Bookstore\Behavior\Map\Table6TableMap;
use Propel\Tests\Bookstore\Behavior\Table6;
use Propel\Tests\Bookstore\Behavior\Table6Query;
use Propel\Tests\Bookstore\Book;
use Propel\Tests\Bookstore\BookQuery;
use Propel\Tests\Helpers\Bookstore\BookstoreDataPopulator;
use Propel\Tests\Helpers\Bookstore\BookstoreTestBase;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Collection\ArrayCollection;

/**
 * Test class for PropelQuery
 *
 * @author Francois Zaninotto
 *
 * @group database
 */
class PropelQueryTest extends BookstoreTestBase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        include_once(__DIR__ . '/PropelQueryTestClasses.php');
    }

    /**
     * @return void
     */
    public function testFrom()
    {
        $q = PropelQuery::from('\Propel\Tests\Bookstore\Book');
        $expected = new BookQuery();
        $this->assertEquals($expected, $q, 'from() returns a Model query instance based on the model name');

        $q = PropelQuery::from('\Propel\Tests\Bookstore\Book b');
        $expected = new BookQuery();
        $expected->setModelAlias('b');
        $this->assertEquals($expected, $q, 'from() sets the model alias if found after the blank');

        $q = PropelQuery::from('\Propel\Tests\Runtime\ActiveQuery\myBook');
        $expected = new MyBookQuery();
        $this->assertEquals($expected, $q, 'from() can find custom query classes');

        try {
            $q = PropelQuery::from('Foo');
            $this->fail('PropelQuery::from() throws an exception when called on a non-existing query class');
        } catch (ClassNotFoundException $e) {
            $this->assertTrue(true, 'PropelQuery::from() throws an exception when called on a non-existing query class');
        }
    }

    /**
     * @return void
     */
    public function testQuery()
    {
        BookstoreDataPopulator::depopulate();
        BookstoreDataPopulator::populate();

        $book = PropelQuery::from('\Propel\Tests\Bookstore\Book b')
            ->where('b.Title like ?', 'Don%')
            ->orderBy('b.ISBN', 'desc')
            ->findOne();
        $this->assertTrue($book instanceof Book);
        $this->assertEquals('Don Juan', $book->getTitle());
    }

    /**
     * testFilterById
     *
     * Various test for filterById functions
     * Id's are autoincrement so we have to use a Select to get current ID's
     *
     * @return void
     */
    public function testFilterById()
    {
        // find by single id
        $book = PropelQuery::from('\Propel\Tests\Bookstore\Book b')
            ->where('b.Title like ?', 'Don%')
            ->orderBy('b.ISBN', 'desc')
            ->findOne();

        $c = BookQuery::create()->filterById($book->getId());

        $book2 = $c->findOne();

        $this->assertTrue($book2 instanceof Book);
        $this->assertEquals('Don Juan', $book2->getTitle());

        //find range
        $booksAll = PropelQuery::from('\Propel\Tests\Bookstore\Book b')
            ->orderBy('b.ID', 'asc')
            ->find();

        $booksIn = BookQuery::create()
            ->filterById([$booksAll[1]->getId(), $booksAll[2]->getId()])
            ->find();

        $this->assertTrue($booksIn[0] == $booksAll[1]);
        $this->assertTrue($booksIn[1] == $booksAll[2]);

        // filter by min value with greater equal
        $booksIn = null;

        $booksIn = BookQuery::create()
            ->filterById(
                ['min' => $booksAll[2]->getId()]
            )
            ->find();

        $this->assertTrue($booksIn[1] == $booksAll[3]);

        // filter by max value with less equal
        $booksIn = null;

        $booksIn = BookQuery::create()
            ->filterById(
                ['max' => $booksAll[1]->getId()]
            )
            ->find();

        $this->assertTrue($booksIn[1] == $booksAll[1]);

        // check backwards compatibility:
        // SELECT  FROM `book` WHERE book.id IN (:p1,:p2)
        // must be the same as
        // SELECT  FROM `book` WHERE (book.id>=:p1 AND book.id<=:p2)

        $minMax = BookQuery::create()
            ->filterById([
                'min' => $booksAll[1]->getId(),
                'max' => $booksAll[2]->getId()
            ])->find();

        $In = BookQuery::create()
            ->filterById([
                $booksAll[1]->getId(),
                $booksAll[2]->getId()
            ])->find();

        $this->assertEquals($minMax->getData(), $In->getData());
    }

    /**
     * @return void
     */
    public function testInstancePool()
    {
        \Propel\Runtime\Propel::enableInstancePooling();
        
        $object = (new Table6())
            ->setTitle('test')
        ;
        $object->save();
        $key = $object->getId();

        $this->assertSame($object, Table6TableMap::getInstanceFromPool($key));
        Table6TableMap::removeInstanceFromPool($object);
        $this->assertNull(Table6TableMap::getInstanceFromPool($key), 'should have cleared instance pool');

        $object = Table6Query::create()->findPk($key);
        $this->assertSame($object, Table6TableMap::getInstanceFromPool($key));
    }

    /**
     * @return void
     */
    public function testFindShouldNotThrowExceptionWhenSelectAndClearMethodsWereExecuted()
    {
        $bookQuery = BookQuery::create();

        $bookQuery->select(['Title'])->find();

        $bookQuery->clear();
        $result = $bookQuery->find();

        $this->assertNotNull($result);
    }

    /**
     * @dataProvider findMethodsProvider
     */
    public function testReturnTypeOfFind(string $findMethodName, $findMethodArg)
    {
        $queryTypes = [
            ['query' => BookQuery::create(), 'returnType' => ObjectCollection::class],
            ['query' => BookQuery::create()->select(['id']), 'returnType' => ArrayCollection::class],
        ];
        foreach ($queryTypes as ['query' => $query, 'returnType' => $returnType]) {
            $result = call_user_func([$query, $findMethodName], $findMethodArg);
            $this->assertInstanceOf($returnType, $result);
        }
    }

    public function findMethodsProvider()
    {
        return [
            ['find', null],
            ['findByTitle', 'le title'],
            ['findPks', [42]]
        ];
    }
}
