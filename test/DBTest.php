<?php
namespace Pineapple\Test;

use Pineapple\DB;
use Pineapple\Test\DB\Driver\TestDriver;
use Pineapple\Error;
use Pineapple\DB\Error as DBError;
use PHPUnit\Framework\TestCase;

class DBTest extends TestCase
{
    public function testFactoryCreatesCorrectDriver()
    {
        $driver = DB::factory(TestDriver::class);

        $this->assertInstanceOf(TestDriver::class, $driver);
    }

    public function testDefaultPersistentOptionIsFalse()
    {
        $driver = DB::factory(TestDriver::class);

        $this->assertFalse($driver->getOption('persistent'));
    }

    public function testFactoryCreatesCorrectDriverWithArrayOptions()
    {
        $driver = DB::factory(TestDriver::class, ['debug' => 'q who?']);

        $this->assertInstanceOf(TestDriver::class, $driver);
    }

    public function testFactoryWithArrayOptions()
    {
        $driver = DB::factory(TestDriver::class, ['debug' => 'q who?']);

        $expected = 'q who?';
        $actual = $driver->getOption('debug');

        $this->assertEquals($expected, $actual);
    }

    public function testFactoryCreatesCorrectDriverWithLegacyOption()
    {
        $driver = DB::factory(TestDriver::class, true);

        $this->assertInstanceOf(TestDriver::class, $driver);
    }

    public function testDefaultPersistentWithLegacyOptionIsTrue()
    {
        $driver = DB::factory(TestDriver::class, true);

        $this->assertTrue($driver->getOption('persistent'));
    }

    public function testFactoryWithBadDriverReturnsError()
    {
        $badDriver = DB::factory('murp');

        $this->assertInstanceOf(Error::class, $badDriver);
    }

    public function testIsConnection()
    {
        $driver = DB::factory(TestDriver::class);

        $this->assertTrue(DB::isConnection($driver));
    }

    public function testIsNotConnection()
    {
        $badDriver = new \stdClass;

        $this->assertFalse(DB::isConnection($badDriver));
    }

    /**
     * @dataProvider manipulationStatementsDataProvider
     */
    public function testManipulationStatementsAreCorrectlyIdentified($statement)
    {
        $this->assertTrue(DB::isManip($statement));
    }

    public function testNonManipulationStatementsAreCorrectlyIdentified()
    {
        $statement = '
            SELECT lyrics
              FROM track
             WHERE title = "useless"
               AND artist = "depeche mode"
        ';

        $this->assertFalse(DB::isManip($statement));
    }

    public function testErrorMessageForDbOkStatusIsNoError()
    {
        $message = DB::errorMessage(DB::DB_OK);

        $this->assertEquals('no error', $message);
    }

    public function testErrorMessageFromErrorClass()
    {
        $message = new DBError(DB::DB_ERROR_NOT_FOUND);

        $this->assertEquals('not found', DB::errorMessage($message));
    }

    protected function manipulationStatements()
    {
        return [
            'INSERT INTO things (foo, bar) VALUES (1, 2)',

            'UPDATE things
               SET bar = 2
             WHERE foo = 1',

            'DELETE FROM things
                  WHERE foo = 1',

            'REPLACE INTO things (foo, bar) VALUES (1, 2)',

            'CREATE TABLE things (id INT PRIMARY KEY NOT NULL AUTO_INCREMENT)',

            'DROP TABLE things',

            'LOAD DATA INFILE "foo.dat"
                        INTO things',

            'SELECT foo, bar, baz
              INTO stuff
              FROM things',

            'ALTER TABLE whatnot
            DROP COLUMN id',

            'GRANT SELECT ON things TO nobody@"%"',

            'REVOKE SELECT ON things FROM nobody@"%"',

            'LOCK TABLE things',

            'UNLOCK TABLE things',
        ];
    }

    public function manipulationStatementsDataProvider()
    {
        return array_map(function ($x) {
          return (array) $x;
        }, $this->manipulationStatements());
    }
}
